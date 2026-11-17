CREATE TABLE IF NOT EXISTS officer_shifts (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(40) NOT NULL,
    officer_id BIGINT UNSIGNED NOT NULL,
    gate_id BIGINT UNSIGNED NOT NULL,
    lane_id BIGINT UNSIGNED NULL,
    direction ENUM('entry', 'exit') NOT NULL,
    starts_at DATETIME NOT NULL,
    ends_at DATETIME NOT NULL,
    status ENUM('scheduled', 'cancelled') NOT NULL DEFAULT 'scheduled',
    notes VARCHAR(1000) NULL,
    assigned_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_officer_shifts_reference (reference),
    KEY idx_officer_shifts_officer_window (officer_id, status, starts_at, ends_at),
    KEY idx_officer_shifts_gate_window (gate_id, lane_id, direction, status, starts_at, ends_at),
    KEY idx_officer_shifts_status_start (status, starts_at),
    CONSTRAINT fk_officer_shifts_officer
        FOREIGN KEY (officer_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_officer_shifts_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_officer_shifts_lane
        FOREIGN KEY (lane_id) REFERENCES lanes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_officer_shifts_assigned_by
        FOREIGN KEY (assigned_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_officer_shifts_time_window CHECK (ends_at > starts_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS cameras (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(40) NOT NULL,
    name VARCHAR(120) NOT NULL,
    model VARCHAR(120) NULL,
    serial_reference VARCHAR(120) NULL,
    location VARCHAR(160) NULL,
    gate_id BIGINT UNSIGNED NOT NULL,
    lane_id BIGINT UNSIGNED NULL,
    status ENUM('active', 'inactive', 'maintenance') NOT NULL DEFAULT 'active',
    monitoring_status ENUM('unknown', 'healthy', 'degraded', 'offline') NOT NULL DEFAULT 'unknown',
    monitoring_notes VARCHAR(1000) NULL,
    last_monitored_at DATETIME NULL,
    configuration JSON NULL,
    created_by BIGINT UNSIGNED NOT NULL,
    updated_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_cameras_reference (reference),
    UNIQUE KEY uq_cameras_serial_reference (serial_reference),
    KEY idx_cameras_gate_status (gate_id, status),
    KEY idx_cameras_monitoring (monitoring_status, last_monitored_at),
    CONSTRAINT fk_cameras_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cameras_lane
        FOREIGN KEY (lane_id) REFERENCES lanes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cameras_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_cameras_updated_by
        FOREIGN KEY (updated_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id BIGINT UNSIGNED NOT NULL,
    notification_type ENUM('approval', 'shift', 'camera', 'administrative', 'system') NOT NULL,
    title VARCHAR(160) NOT NULL,
    message VARCHAR(1000) NOT NULL,
    entity_type VARCHAR(48) NULL,
    entity_id BIGINT UNSIGNED NULL,
    created_by BIGINT UNSIGNED NULL,
    read_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notifications_user_unread_created (user_id, read_at, created_at, id),
    KEY idx_notifications_entity (entity_type, entity_id),
    KEY idx_notifications_creator_created (created_by, created_at),
    CONSTRAINT fk_notifications_user
        FOREIGN KEY (user_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE CASCADE,
    CONSTRAINT fk_notifications_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, description)
VALUES
    ('dashboard.view', 'View role-scoped operational dashboard summaries.'),
    ('shifts.view', 'View own or assigned officer shifts.'),
    ('shifts.manage', 'Create, update, and cancel officer shifts.'),
    ('cameras.view', 'View camera inventory and monitoring metadata within assigned gates.'),
    ('cameras.manage', 'Manage camera inventory and monitoring metadata.'),
    ('reports.view', 'View role-scoped operational reports and exports.'),
    ('notifications.view', 'View the authenticated user''s notifications.'),
    ('notifications.read', 'Mark the authenticated user''s notifications as read.'),
    ('notifications.manage', 'Create administrative notifications for active users.'),
    ('audit.view', 'View immutable administrative audit events.')
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
INNER JOIN permissions AS p
    ON p.code IN (
        'dashboard.view',
        'shifts.view',
        'cameras.view',
        'reports.view',
        'notifications.view',
        'notifications.read'
    )
WHERE r.code = 'officer';
