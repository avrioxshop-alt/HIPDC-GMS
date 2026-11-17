CREATE TABLE IF NOT EXISTS roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(32) NOT NULL,
    name VARCHAR(64) NOT NULL,
    description VARCHAR(255) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_code (code),
    UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    description VARCHAR(255) NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (role_id, permission_id),
    CONSTRAINT fk_role_permissions_role
        FOREIGN KEY (role_id) REFERENCES roles (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_role_permissions_permission
        FOREIGN KEY (permission_id) REFERENCES permissions (id)
        ON UPDATE CASCADE ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    role_id BIGINT UNSIGNED NOT NULL,
    username VARCHAR(64) NOT NULL,
    full_name VARCHAR(120) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    auth_version INT UNSIGNED NOT NULL DEFAULT 0,
    last_login_at TIMESTAMP NULL DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_username (username),
    UNIQUE KEY uq_users_email (email),
    KEY idx_users_role_status (role_id, status),
    KEY idx_users_status_created (status, created_at),
    CONSTRAINT fk_users_role
        FOREIGN KEY (role_id) REFERENCES roles (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS gates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(120) NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gates_code (code),
    UNIQUE KEY uq_gates_name (name),
    KEY idx_gates_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_gate_assignments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    gate_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('entry', 'exit', 'both') NOT NULL,
    assigned_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_user_gate_assignment (user_id, gate_id),
    KEY idx_gate_assignments_gate (gate_id),
    KEY idx_gate_assignments_assigned_by (assigned_by),
    CONSTRAINT fk_gate_assignments_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_gate_assignments_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_gate_assignments_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO roles (code, name, description)
VALUES
    ('admin', 'Admin', 'Manages system users and configuration.'),
    ('officer', 'Officer', 'Uses the system according to assigned permissions and gates.')
ON DUPLICATE KEY UPDATE
    name = VALUES(name),
    description = VALUES(description);

INSERT INTO permissions (code, description)
VALUES
    ('users.view', 'View user accounts.'),
    ('users.create', 'Create user accounts.'),
    ('users.update', 'Update user profile information.'),
    ('users.manage_status', 'Activate or deactivate user accounts.'),
    ('users.assign_role', 'Assign account roles.'),
    ('users.assign_gates', 'Assign users to gates and directions.'),
    ('users.change_password', 'Change user passwords.'),
    ('gate.entry', 'Perform entry operations at assigned gates.'),
    ('gate.exit', 'Perform exit operations at assigned gates.')
ON DUPLICATE KEY UPDATE
    description = VALUES(description);

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles AS r
CROSS JOIN permissions AS p
WHERE r.code = 'admin';

INSERT IGNORE INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id
FROM roles AS r
INNER JOIN permissions AS p ON p.code IN ('gate.entry', 'gate.exit')
WHERE r.code = 'officer';
