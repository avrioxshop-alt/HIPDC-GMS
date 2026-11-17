CREATE TABLE IF NOT EXISTS gate_devices (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    public_id CHAR(32) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    name VARCHAR(120) NOT NULL,
    device_type ENUM('qr_scanner', 'anpr', 'camera', 'gate_controller') NOT NULL,
    gate_id BIGINT UNSIGNED NOT NULL,
    lane_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('entry', 'exit') NOT NULL,
    secret_hash BINARY(32) NOT NULL,
    last_sequence BIGINT UNSIGNED NOT NULL DEFAULT 0,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_gate_devices_public_id (public_id),
    KEY idx_gate_devices_gate_lane_status (gate_id, lane_id, status),
    CONSTRAINT fk_gate_devices_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_gate_devices_lane
        FOREIGN KEY (lane_id) REFERENCES lanes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_gate_devices_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(40) NOT NULL,
    subject_type ENUM('vehicle', 'visitor', 'goods') NOT NULL,
    event_type ENUM('entry', 'exit') NOT NULL,
    gate_id BIGINT UNSIGNED NOT NULL,
    lane_id BIGINT UNSIGNED NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    driver_id BIGINT UNSIGNED NULL,
    visitor_id BIGINT UNSIGNED NULL,
    visitor_pass_id BIGINT UNSIGNED NULL,
    schedule_id BIGINT UNSIGNED NULL,
    goods_declaration_id BIGINT UNSIGNED NULL,
    approval_request_id BIGINT UNSIGNED NULL,
    company_id BIGINT UNSIGNED NULL,
    actor_id BIGINT UNSIGNED NULL,
    device_id BIGINT UNSIGNED NULL,
    device_sequence BIGINT UNSIGNED NULL,
    idempotency_key VARCHAR(128) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    request_hash BINARY(32) NOT NULL,
    access_result ENUM('granted', 'denied') NOT NULL,
    denial_reason VARCHAR(255) NULL,
    access_method ENUM('manual', 'qr', 'anpr') NOT NULL,
    vehicle_plate_observed VARCHAR(40) NULL,
    override_reason VARCHAR(1000) NULL,
    metadata JSON NULL,
    granted_goods_declaration_id BIGINT UNSIGNED
        GENERATED ALWAYS AS (
            IF(access_result = 'granted', goods_declaration_id, NULL)
        ) VIRTUAL,
    event_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_access_events_reference (reference),
    UNIQUE KEY uq_access_events_idempotency (idempotency_key),
    UNIQUE KEY uq_access_events_device_sequence (device_id, device_sequence),
    UNIQUE KEY uq_access_events_goods_movement (granted_goods_declaration_id),
    KEY idx_access_events_gate_time (gate_id, event_at, id),
    KEY idx_access_events_lane_time (lane_id, event_at, id),
    KEY idx_access_events_vehicle_time (vehicle_id, event_at, id),
    KEY idx_access_events_visitor_time (visitor_id, event_at, id),
    KEY idx_access_events_driver_time (driver_id, event_at, id),
    KEY idx_access_events_company_time (company_id, event_at, id),
    KEY idx_access_events_plate_time (vehicle_plate_observed, event_at),
    KEY idx_access_events_result_time (access_result, event_at),
    KEY idx_access_events_method_time (access_method, event_at),
    KEY idx_access_events_goods (goods_declaration_id, event_at),
    KEY idx_access_events_approval (approval_request_id),
    CONSTRAINT fk_access_events_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_lane
        FOREIGN KEY (lane_id) REFERENCES lanes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_driver
        FOREIGN KEY (driver_id) REFERENCES drivers (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_visitor
        FOREIGN KEY (visitor_id) REFERENCES visitors (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_visitor_pass
        FOREIGN KEY (visitor_pass_id) REFERENCES visitor_passes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_goods
        FOREIGN KEY (goods_declaration_id) REFERENCES goods_declarations (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_approval
        FOREIGN KEY (approval_request_id) REFERENCES approval_requests (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_company
        FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_access_events_actor
        FOREIGN KEY (actor_id) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_access_events_device
        FOREIGN KEY (device_id) REFERENCES gate_devices (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_access_events_result_reason
        CHECK (
            (access_result = 'granted' AND denial_reason IS NULL)
            OR (access_result = 'denied' AND denial_reason IS NOT NULL)
        )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO permissions (code, description)
VALUES
    ('gate_operations.view', 'View access events and gate operations within assigned scope.'),
    ('gate_operations.process', 'Submit manual, QR, and ANPR access attempts at assigned gates.'),
    ('gate_operations.override', 'Record an audited override for a vehicle exit with no matching entry.'),
    ('gate_devices.manage', 'Register, view, and deactivate gate devices.')
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
    ON p.code IN ('gate_operations.view', 'gate_operations.process')
WHERE r.code = 'officer';
