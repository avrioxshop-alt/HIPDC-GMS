CREATE TABLE IF NOT EXISTS visitors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(32) NOT NULL,
    full_name VARCHAR(160) NOT NULL,
    document_reference VARCHAR(120) NULL,
    phone VARCHAR(32) NULL,
    email VARCHAR(254) NULL,
    organization VARCHAR(160) NULL,
    company_id BIGINT UNSIGNED NULL,
    host_person VARCHAR(120) NULL,
    purpose VARCHAR(255) NOT NULL,
    expected_arrival_at DATETIME NOT NULL,
    expected_departure_at DATETIME NULL,
    schedule_id BIGINT UNSIGNED NULL,
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    created_by BIGINT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_visitors_reference (reference),
    KEY idx_visitors_name_arrival (full_name, expected_arrival_at),
    KEY idx_visitors_company_arrival (company_id, expected_arrival_at),
    KEY idx_visitors_creator_arrival (created_by, expected_arrival_at),
    KEY idx_visitors_schedule (schedule_id),
    CONSTRAINT fk_visitors_company
        FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_visitors_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_visitors_created_by
        FOREIGN KEY (created_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS visitor_passes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(40) NOT NULL,
    visitor_id BIGINT UNSIGNED NOT NULL,
    gate_id BIGINT UNSIGNED NOT NULL,
    direction ENUM('entry', 'exit') NOT NULL,
    valid_from DATETIME NOT NULL,
    expires_at DATETIME NOT NULL,
    qr_token_hash BINARY(32) NULL,
    status ENUM('pending_approval', 'active', 'denied', 'cancelled', 'revoked')
        NOT NULL DEFAULT 'pending_approval',
    submitted_by BIGINT UNSIGNED NOT NULL,
    issued_by BIGINT UNSIGNED NULL,
    issued_at DATETIME NULL,
    revoked_by BIGINT UNSIGNED NULL,
    revoked_at DATETIME NULL,
    revocation_reason VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_visitor_passes_reference (reference),
    UNIQUE KEY uq_visitor_passes_token_hash (qr_token_hash),
    KEY idx_visitor_passes_visitor_status (visitor_id, status, expires_at),
    KEY idx_visitor_passes_gate_validity (gate_id, direction, status, valid_from, expires_at),
    KEY idx_visitor_passes_submitter (submitted_by, created_at),
    CONSTRAINT fk_visitor_passes_visitor
        FOREIGN KEY (visitor_id) REFERENCES visitors (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_visitor_passes_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_visitor_passes_submitter
        FOREIGN KEY (submitted_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_visitor_passes_issuer
        FOREIGN KEY (issued_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL,
    CONSTRAINT fk_visitor_passes_revoker
        FOREIGN KEY (revoked_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_declarations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(40) NOT NULL,
    direction ENUM('entry', 'exit') NOT NULL,
    company_id BIGINT UNSIGNED NOT NULL,
    vehicle_id BIGINT UNSIGNED NULL,
    driver_id BIGINT UNSIGNED NULL,
    schedule_id BIGINT UNSIGNED NULL,
    gate_id BIGINT UNSIGNED NULL,
    lane_id BIGINT UNSIGNED NULL,
    purpose VARCHAR(255) NOT NULL,
    submitted_by BIGINT UNSIGNED NOT NULL,
    submitted_at DATETIME NULL,
    status ENUM('draft', 'pending', 'approved', 'denied', 'cancelled')
        NOT NULL DEFAULT 'draft',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_goods_declarations_reference (reference),
    KEY idx_goods_declarations_status_created (status, created_at),
    KEY idx_goods_declarations_company_created (company_id, created_at),
    KEY idx_goods_declarations_creator_status (submitted_by, status),
    KEY idx_goods_declarations_vehicle (vehicle_id),
    KEY idx_goods_declarations_driver (driver_id),
    KEY idx_goods_declarations_schedule (schedule_id),
    KEY idx_goods_declarations_gate_direction (gate_id, direction),
    KEY idx_goods_declarations_lane (lane_id),
    CONSTRAINT fk_goods_declarations_company
        FOREIGN KEY (company_id) REFERENCES companies (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_goods_declarations_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES vehicles (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_goods_declarations_driver
        FOREIGN KEY (driver_id) REFERENCES drivers (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_goods_declarations_schedule
        FOREIGN KEY (schedule_id) REFERENCES schedules (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_goods_declarations_gate
        FOREIGN KEY (gate_id) REFERENCES gates (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_goods_declarations_lane
        FOREIGN KEY (lane_id) REFERENCES lanes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_goods_declarations_submitter
        FOREIGN KEY (submitted_by) REFERENCES users (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS goods_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    declaration_id BIGINT UNSIGNED NOT NULL,
    description VARCHAR(255) NOT NULL,
    item_code VARCHAR(80) NULL,
    quantity DECIMAL(18,3) NOT NULL,
    unit VARCHAR(32) NOT NULL,
    serial_number VARCHAR(120) NULL,
    remarks VARCHAR(1000) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_goods_items_declaration (declaration_id, id),
    KEY idx_goods_items_code (item_code),
    CONSTRAINT fk_goods_items_declaration
        FOREIGN KEY (declaration_id) REFERENCES goods_declarations (id)
        ON UPDATE CASCADE ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE approval_requests
    MODIFY request_type ENUM(
        'access',
        'exit_clearance',
        'vehicle_registration',
        'schedule_change',
        'visitor_pass',
        'goods_declaration'
    ) NOT NULL,
    ADD COLUMN visitor_pass_id BIGINT UNSIGNED NULL AFTER schedule_id,
    ADD COLUMN goods_declaration_id BIGINT UNSIGNED NULL AFTER visitor_pass_id,
    ADD UNIQUE KEY uq_approval_requests_visitor_pass (visitor_pass_id),
    ADD UNIQUE KEY uq_approval_requests_goods_declaration (goods_declaration_id),
    ADD CONSTRAINT fk_approval_requests_visitor_pass
        FOREIGN KEY (visitor_pass_id) REFERENCES visitor_passes (id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    ADD CONSTRAINT fk_approval_requests_goods_declaration
        FOREIGN KEY (goods_declaration_id) REFERENCES goods_declarations (id)
        ON UPDATE CASCADE ON DELETE RESTRICT;

INSERT INTO permissions (code, description)
VALUES
    ('visitors.view', 'View visitor records within the assigned scope.'),
    ('visitors.create', 'Create visitor registrations.'),
    ('visitors.update', 'Update visitor registrations.'),
    ('visitors.manage_status', 'Activate or deactivate visitor records.'),
    ('visitors.passes.view', 'View visitor passes within the assigned scope.'),
    ('visitors.passes.issue', 'Submit visitor pass requests for approval.'),
    ('visitors.passes.validate', 'Validate visitor QR tokens at assigned gates.'),
    ('visitors.passes.revoke', 'Revoke an issued visitor pass.'),
    ('goods.view', 'View goods declarations within the assigned scope.'),
    ('goods.create', 'Create goods declaration drafts.'),
    ('goods.update', 'Update owned or administered goods declaration drafts.'),
    ('goods.submit', 'Submit goods declarations for approval.'),
    ('goods.cancel_own', 'Cancel own goods declarations or requests.')
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
        'visitors.view',
        'visitors.create',
        'visitors.passes.view',
        'visitors.passes.issue',
        'visitors.passes.validate',
        'goods.view',
        'goods.create',
        'goods.update',
        'goods.submit',
        'goods.cancel_own'
    )
WHERE r.code = 'officer';
