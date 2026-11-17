ALTER TABLE gates
    ADD COLUMN description VARCHAR(255) NULL AFTER name,
    ADD COLUMN location VARCHAR(160) NULL AFTER description,
    ADD COLUMN supports_entry TINYINT(1) NOT NULL DEFAULT 1 AFTER location,
    ADD COLUMN supports_exit TINYINT(1) NOT NULL DEFAULT 1 AFTER supports_entry;

CREATE TABLE IF NOT EXISTS companies (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    name VARCHAR(160) NOT NULL,
    registration_number VARCHAR(80) NULL,
    contact_person VARCHAR(120) NULL,
    phone VARCHAR(32) NULL,
    email VARCHAR(254) NULL,
    address VARCHAR(255) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_companies_registration (registration_number),
    KEY idx_companies_name (name),
    KEY idx_companies_status_name (status, name),
    CONSTRAINT fk_companies_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS drivers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    full_name VARCHAR(120) NOT NULL,
    identification_number VARCHAR(80) NULL,
    license_number VARCHAR(80) NULL,
    phone VARCHAR(32) NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_drivers_identification (identification_number),
    UNIQUE KEY uq_drivers_license (license_number),
    KEY idx_drivers_company_status (company_id, status),
    KEY idx_drivers_name (full_name),
    CONSTRAINT fk_drivers_company
        FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_drivers_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS vehicles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    company_id BIGINT UNSIGNED NULL,
    assigned_driver_id BIGINT UNSIGNED NULL,
    plate_number VARCHAR(40) NOT NULL,
    plate_normalized VARCHAR(40) NOT NULL,
    vehicle_type ENUM('car', 'truck', 'bus', 'motorcycle', 'other') NOT NULL,
    make VARCHAR(80) NULL,
    model VARCHAR(100) NULL,
    registration_number VARCHAR(80) NULL,
    registration_expiry DATE NULL,
    status ENUM('active', 'inactive', 'blocked') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_vehicles_plate_normalized (plate_normalized),
    UNIQUE KEY uq_vehicles_registration_number (registration_number),
    KEY idx_vehicles_company_status (company_id, status),
    KEY idx_vehicles_driver (assigned_driver_id),
    KEY idx_vehicles_type (vehicle_type),
    CONSTRAINT fk_vehicles_company
        FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_vehicles_assigned_driver
        FOREIGN KEY (assigned_driver_id) REFERENCES drivers (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_vehicles_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS lanes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    gate_id BIGINT UNSIGNED NOT NULL,
    code VARCHAR(64) NOT NULL,
    name VARCHAR(120) NOT NULL,
    direction ENUM('entry', 'exit', 'both') NOT NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_lanes_gate_code (gate_id, code),
    UNIQUE KEY uq_lanes_gate_name (gate_id, name),
    KEY idx_lanes_gate_status (gate_id, status),
    KEY idx_lanes_direction_status (direction, status),
    CONSTRAINT fk_lanes_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_lanes_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schedules (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(32) NOT NULL,
    company_id BIGINT UNSIGNED NULL,
    vehicle_id BIGINT UNSIGNED NOT NULL,
    driver_id BIGINT UNSIGNED NOT NULL,
    gate_id BIGINT UNSIGNED NULL,
    lane_id BIGINT UNSIGNED NULL,
    direction ENUM('entry', 'exit') NOT NULL,
    scheduled_at DATETIME NOT NULL,
    purpose VARCHAR(120) NOT NULL,
    cargo_summary VARCHAR(500) NULL,
    status ENUM('scheduled', 'cancelled') NOT NULL DEFAULT 'scheduled',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_schedules_reference (reference),
    KEY idx_schedules_datetime_status (scheduled_at, status),
    KEY idx_schedules_company_datetime (company_id, scheduled_at),
    KEY idx_schedules_vehicle_datetime (vehicle_id, scheduled_at),
    KEY idx_schedules_gate_direction_datetime (gate_id, direction, scheduled_at),
    KEY idx_schedules_driver (driver_id),
    CONSTRAINT fk_schedules_company
        FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_driver
        FOREIGN KEY (driver_id) REFERENCES drivers (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_lane
        FOREIGN KEY (lane_id) REFERENCES lanes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_schedules_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_requests (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(40) NOT NULL,
    request_type ENUM('access', 'exit_clearance', 'vehicle_registration', 'schedule_change') NOT NULL,
    title VARCHAR(200) NOT NULL,
    note TEXT NULL,
    details JSON NULL,
    company_id BIGINT UNSIGNED NULL,
    driver_id BIGINT UNSIGNED NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    schedule_id BIGINT UNSIGNED NULL,
    gate_id BIGINT UNSIGNED NULL,
    lane_id BIGINT UNSIGNED NULL,
    direction ENUM('entry', 'exit') NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    assigned_reviewer_id BIGINT UNSIGNED NULL,
    status ENUM('pending', 'approved', 'denied', 'cancelled') NOT NULL DEFAULT 'pending',
    decision_reason TEXT NULL,
    decided_by BIGINT UNSIGNED NULL,
    decided_at DATETIME NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_approval_requests_reference (reference),
    KEY idx_approval_requests_status_created (status, created_at),
    KEY idx_approval_requests_submitter_status (submitted_by, status),
    KEY idx_approval_requests_reviewer_status (assigned_reviewer_id, status),
    KEY idx_approval_requests_company (company_id),
    KEY idx_approval_requests_driver (driver_id),
    KEY idx_approval_requests_vehicle (vehicle_id),
    KEY idx_approval_requests_schedule (schedule_id),
    KEY idx_approval_requests_gate_direction (gate_id, direction),
    CONSTRAINT fk_approval_requests_company
        FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_requests_driver
        FOREIGN KEY (driver_id) REFERENCES drivers (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_requests_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_requests_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_requests_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_requests_lane
        FOREIGN KEY (lane_id) REFERENCES lanes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_requests_submitter
        FOREIGN KEY (submitted_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_requests_reviewer
        FOREIGN KEY (assigned_reviewer_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_approval_requests_decided_by
        FOREIGN KEY (decided_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS approval_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    approval_request_id BIGINT UNSIGNED NOT NULL,
    actor_id BIGINT UNSIGNED NOT NULL,
    previous_status ENUM('pending', 'approved', 'denied', 'cancelled') NOT NULL,
    new_status ENUM('approved', 'denied', 'cancelled') NOT NULL,
    reason TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_approval_decisions_request_created (approval_request_id, created_at),
    KEY idx_approval_decisions_actor_created (actor_id, created_at),
    CONSTRAINT fk_approval_decisions_request
        FOREIGN KEY (approval_request_id) REFERENCES approval_requests (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_approval_decisions_actor
        FOREIGN KEY (actor_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    actor_id BIGINT UNSIGNED NULL,
    action VARCHAR(80) NOT NULL,
    entity_type VARCHAR(48) NOT NULL,
    entity_id BIGINT UNSIGNED NOT NULL,
    metadata JSON NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_audit_events_entity_created (entity_type, entity_id, created_at),
    KEY idx_audit_events_actor_created (actor_id, created_at),
    CONSTRAINT fk_audit_events_actor
        FOREIGN KEY (actor_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, description)
VALUES
    ('companies.view', 'View company records.'),
    ('companies.create', 'Create company records.'),
    ('companies.update', 'Update company records.'),
    ('companies.manage_status', 'Activate or deactivate companies.'),
    ('drivers.view', 'View driver records.'),
    ('drivers.create', 'Create driver records.'),
    ('drivers.update', 'Update driver records.'),
    ('drivers.manage_status', 'Activate or deactivate drivers.'),
    ('vehicles.view', 'View vehicle records.'),
    ('vehicles.create', 'Create vehicle records.'),
    ('vehicles.update', 'Update vehicle records.'),
    ('vehicles.manage_status', 'Activate, deactivate, or block vehicles.'),
    ('gates.view', 'View gates and lanes.'),
    ('gates.create', 'Create gates and lanes.'),
    ('gates.update', 'Update gates and lanes.'),
    ('gates.manage_status', 'Activate or deactivate gates and lanes.'),
    ('schedules.view', 'View schedules.'),
    ('schedules.create', 'Create schedules.'),
    ('schedules.update', 'Update schedules.'),
    ('schedules.cancel', 'Cancel schedules.'),
    ('approvals.view', 'View approval requests within the assigned scope.'),
    ('approvals.submit', 'Submit approval requests.'),
    ('approvals.assign', 'Assign pending approval requests to reviewers.'),
    ('approvals.decide', 'Approve or deny approval requests.'),
    ('approvals.cancel_own', 'Cancel own pending approval requests.')
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
        'companies.view',
        'drivers.view',
        'vehicles.view',
        'gates.view',
        'schedules.view',
        'schedules.create',
        'schedules.cancel',
        'approvals.view',
        'approvals.submit',
        'approvals.cancel_own'
    )
WHERE r.code = 'officer';
