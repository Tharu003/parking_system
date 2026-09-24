-- ParkSmart security authentication + vehicle category upgrade
-- Compatible with PHP 8.2 / MariaDB 10.4

USE parking_db;

CREATE TABLE IF NOT EXISTS security_users (
    id INT(11) NOT NULL AUTO_INCREMENT,
    full_name VARCHAR(100) NOT NULL,
    username VARCHAR(50) NOT NULL,
    email VARCHAR(120) NOT NULL,
    phone VARCHAR(25) DEFAULT NULL,
    password VARCHAR(255) NOT NULL,
    security_question VARCHAR(255) NOT NULL,
    security_answer VARCHAR(255) NOT NULL,
    status ENUM('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_security_username (username),
    UNIQUE KEY uq_security_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- ============================================================
-- SECURITY LOGIN TEST ACCOUNT
-- Username: security
-- Password: Security@123
-- The password is stored as a PHP password_hash() bcrypt hash.
-- ============================================================
INSERT INTO security_users
(full_name, username, email, phone, password, security_question, security_answer, status)
SELECT
'ParkSmart Security', 'security', 'security@parksmart.local', '0770000000',
'$2y$12$HnJe6k43MpDWWbugSvoYZu9hafkEONYcJsMPwEFFMp/S8yLKI6Yc2',
'First School',
'$2y$12$Jueudywz9RmAGuI1K2Q0pOTQp/K/V1EX8KkgQzVdJpGwFNvd9i0T2',
'ACTIVE'
WHERE NOT EXISTS (
    SELECT 1 FROM security_users
    WHERE username='security' OR email='security@parksmart.local'
);

-- Keep existing historical ticket IDs intact.
-- Existing ID 1 becomes Bike; ID 2 remains Car.
UPDATE vehicle_types SET type_name='Bike', hourly_rate=50.00 WHERE id=1;
UPDATE vehicle_types SET type_name='Car', hourly_rate=100.00 WHERE id=2;

-- ID 3 is kept as Bus because existing tickets may reference it.
INSERT INTO vehicle_types (id,type_name,hourly_rate)
SELECT 4,'Three-Wheel',50.00
WHERE NOT EXISTS (SELECT 1 FROM vehicle_types WHERE id=4);

INSERT INTO vehicle_types (id,type_name,hourly_rate)
SELECT 5,'Van',100.00
WHERE NOT EXISTS (SELECT 1 FROM vehicle_types WHERE id=5);

-- After these inserts, vehicle_types should contain:
-- 1 Bike       LKR 50/hr
-- 2 Car        LKR 100/hr
-- 3 Bus        LKR 200/hr (existing)
-- 4 Three-Wheel LKR 50/hr
-- 5 Van        LKR 100/hr

ALTER TABLE tickets
    MODIFY vehicle_type_id INT(11) NOT NULL;

-- Optional indexes for faster VIP validation / active parking checks.
CREATE INDEX idx_vip_vehicle_status ON vip_requests(vehicle_number,status,ticket_code);
CREATE INDEX idx_ticket_vehicle_status ON tickets(vehicle_number,status);


-- ============================================================
-- VIP PARKING SESSION UPGRADE
-- VIP vehicles do not receive a normal parking ticket.
-- ============================================================
ALTER TABLE vip_requests
  MODIFY status ENUM('PENDING','APPROVED','REJECTED','USED') DEFAULT 'PENDING';

CREATE TABLE IF NOT EXISTS vip_parking_sessions (
    id INT(11) NOT NULL AUTO_INCREMENT,
    vip_request_id INT(11) DEFAULT NULL,
    vehicle_number VARCHAR(20) NOT NULL,
    driver_name VARCHAR(100) DEFAULT NULL,
    contact_no VARCHAR(20) DEFAULT NULL,
    reason TEXT DEFAULT NULL,
    entry_time DATETIME NOT NULL,
    exit_time DATETIME DEFAULT NULL,
    duration_minutes INT(11) NOT NULL DEFAULT 0,
    status ENUM('PARKED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PARKED',
    slot_label VARCHAR(30) DEFAULT NULL,
    authorized_by VARCHAR(100) DEFAULT NULL,
    exited_by VARCHAR(100) DEFAULT NULL,
    gate_notes TEXT DEFAULT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_vip_session_vehicle_status (vehicle_number,status),
    KEY idx_vip_session_entry (entry_time),
    KEY idx_vip_session_status (status),
    KEY idx_vip_session_request (vip_request_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
