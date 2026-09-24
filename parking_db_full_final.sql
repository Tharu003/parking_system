-- ============================================================
-- PARKSMART PARKING SYSTEM - COMPLETE DATABASE SQL
-- PHP 8.2 / MariaDB 10.4
-- Database: parking_db
-- Safe for an existing database: creates missing tables first.
-- ============================================================

CREATE DATABASE IF NOT EXISTS `parking_db`
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_general_ci;

USE `parking_db`;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ============================================================
-- 1. ADMINS
-- ============================================================
CREATE TABLE IF NOT EXISTS `admins` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(100) NOT NULL,
  `password` varchar(255) NOT NULL,
  `security_question` varchar(255) NOT NULL,
  `security_answer` varchar(255) NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `username` (`username`),
  UNIQUE KEY `uq_admin_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Existing admin from the supplied database.
INSERT INTO `admins`
(`id`,`name`,`username`,`email`,`password`,`security_question`,`security_answer`)
VALUES
(1,'Nishara De Silva','admin','nishu@gmail.com',
'$2y$10$mLRsfEXKLjYMKN3whXNTyOCoD8F/ipVgg7EVFSHVF5/3L0a4t4wUa',
'First School',
'$2y$10$K3abXW04jOWfeWNfSK1pxulb3Pjp2m6uKqMe.OFv5EXDwxGlo8ygm')
ON DUPLICATE KEY UPDATE
  `name`=VALUES(`name`),
  `email`=VALUES(`email`),
  `password`=VALUES(`password`),
  `security_question`=VALUES(`security_question`),
  `security_answer`=VALUES(`security_answer`);

-- ============================================================
-- 2. SECURITY STAFF
-- ============================================================
CREATE TABLE IF NOT EXISTS `security_users` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `full_name` varchar(100) NOT NULL,
  `username` varchar(50) NOT NULL,
  `email` varchar(120) NOT NULL,
  `phone` varchar(25) DEFAULT NULL,
  `password` varchar(255) NOT NULL,
  `security_question` varchar(255) NOT NULL,
  `security_answer` varchar(255) NOT NULL,
  `status` enum('ACTIVE','INACTIVE') NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_security_username` (`username`),
  UNIQUE KEY `uq_security_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 3. VEHICLE TYPES
-- Create BEFORE tickets/vip_requests because they reference it.
-- IDs 1,2,3 are retained for historical ticket compatibility.
-- ============================================================
CREATE TABLE IF NOT EXISTS `vehicle_types` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `type_name` varchar(50) NOT NULL,
  `hourly_rate` decimal(10,2) NOT NULL DEFAULT 0.00,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Existing historical IDs:
-- 1 = Bike
-- 2 = Car
-- 3 = Bus
INSERT INTO `vehicle_types` (`id`,`type_name`,`hourly_rate`) VALUES
(1,'Bike',50.00),
(2,'Car',100.00),
(3,'Bus',200.00)
ON DUPLICATE KEY UPDATE
  `type_name`=VALUES(`type_name`),
  `hourly_rate`=VALUES(`hourly_rate`);

-- New separate categories.
INSERT INTO `vehicle_types` (`id`,`type_name`,`hourly_rate`)
VALUES
(4,'Three-Wheel',50.00),
(5,'Van',100.00)
ON DUPLICATE KEY UPDATE
  `type_name`=VALUES(`type_name`),
  `hourly_rate`=VALUES(`hourly_rate`);

-- ============================================================
-- 4. VIP REQUESTS
-- ============================================================
CREATE TABLE IF NOT EXISTS `vip_requests` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vehicle_number` varchar(20) NOT NULL,
  `vehicle_type_id` int(11) DEFAULT NULL,
  `driver_name` varchar(100) NOT NULL,
  `contact_no` varchar(20) NOT NULL,
  `reason` text NOT NULL,
  `requested_date` datetime DEFAULT current_timestamp(),
  `status` enum('PENDING','APPROVED','REJECTED','USED') DEFAULT 'PENDING',
  `ticket_code` varchar(50) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vip_vehicle_status` (`vehicle_number`,`status`,`ticket_code`),
  KEY `idx_vip_vehicle_type` (`vehicle_type_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Ensure an older existing database also receives the one-time USED state.
ALTER TABLE `vip_requests`
  MODIFY `status` enum('PENDING','APPROVED','REJECTED','USED') DEFAULT 'PENDING';

-- Existing VIP request from the supplied database.
INSERT INTO `vip_requests`
(`id`,`vehicle_number`,`vehicle_type_id`,`driver_name`,`contact_no`,`reason`,
 `requested_date`,`status`,`ticket_code`)
VALUES
(1,'CAB-5120',NULL,'Mr.Kumara','0766801989','Vendor Dilivery',
 '2026-09-09 17:22:43','APPROVED',NULL)
ON DUPLICATE KEY UPDATE
  `vehicle_number`=VALUES(`vehicle_number`),
  `driver_name`=VALUES(`driver_name`),
  `contact_no`=VALUES(`contact_no`),
  `reason`=VALUES(`reason`),
  `status`=VALUES(`status`);

-- ============================================================
-- 5. TICKETS
-- ============================================================
CREATE TABLE IF NOT EXISTS `tickets` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `ticket_code` varchar(50) NOT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `vehicle_type_id` int(11) NOT NULL,
  `entry_time` datetime NOT NULL,
  `exit_time` datetime DEFAULT NULL,
  `duration_hours` int(11) NOT NULL DEFAULT 0,
  `total_fee` decimal(10,2) NOT NULL DEFAULT 0.00,
  `status` enum(
    'PARKED',
    'COMPLETED',
    'VOID_REQUESTED',
    'VOIDED',
    'CANCEL_REQUESTED',
    'CANCELLED',
    'REJECTED'
  ) NOT NULL DEFAULT 'PARKED',
  `is_vip` tinyint(1) NOT NULL DEFAULT 0,
  `action_reason` text DEFAULT NULL,
  `requested_by` varchar(100) DEFAULT NULL,
  `requested_at` datetime DEFAULT NULL,
  `reviewed_by` varchar(100) DEFAULT NULL,
  `reviewed_at` datetime DEFAULT NULL,
  `admin_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_ticket_code` (`ticket_code`),
  KEY `fk_tickets_vehicle_types` (`vehicle_type_id`),
  KEY `idx_ticket_vehicle_status` (`vehicle_number`,`status`),
  KEY `idx_ticket_entry_time` (`entry_time`),
  KEY `idx_ticket_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Existing historical tickets from the supplied database.
INSERT INTO `tickets`
(`id`,`ticket_code`,`vehicle_number`,`vehicle_type_id`,`entry_time`,`exit_time`,
 `duration_hours`,`total_fee`,`status`,`is_vip`,`action_reason`,`requested_by`,
 `requested_at`,`reviewed_by`,`reviewed_at`,`admin_notes`,`created_at`)
VALUES
(1,'PK-1788946110-712','CAM-3298',2,'2026-09-09 14:58:30','2026-09-09 17:17:26',
 3,800.00,'COMPLETED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-09 09:28:30'),
(7,'PK-1788953912-629','BAV-5120',1,'2026-09-09 17:08:32','2026-09-09 18:29:06',
 2,100.00,'COMPLETED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-09 11:38:32'),
(8,'PK-1788960073-643','BAS-2222',2,'2026-09-09 18:51:13','2026-09-09 18:54:04',
 1,0.00,'COMPLETED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-09 13:21:13'),
(9,'PK-1788964703-274','CAM-3298',1,'2026-09-09 20:08:23',NULL,
 0,0.00,'PARKED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-09 14:38:23'),
(10,'PK-1788965009-769','BAV-8799',1,'2026-09-09 20:13:29',NULL,
 0,0.00,'PARKED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-09 14:43:29'),
(11,'PK-1788965070-457','VCF-7779',2,'2026-09-09 20:14:30','2026-09-13 15:33:38',
 92,9200.00,'COMPLETED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-09 14:44:30'),
(12,'PK-1789288589-516','VDT-9999',1,'2026-09-13 14:06:29',NULL,
 0,0.00,'PARKED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-13 08:36:29'),
(13,'PK-1789293677-338','CAM-4444',1,'2026-09-13 15:31:17',NULL,
 0,0.00,'PARKED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-13 10:01:17'),
(14,'PK-1789300394-885','CAD-4446',2,'2026-09-13 17:23:14',NULL,
 0,0.00,'PARKED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-13 11:53:14'),
(15,'PK-1789649170-896','CXS-0008',1,'2026-09-17 18:16:10',NULL,
 0,0.00,'VOIDED',0,'Double','Nimal','2026-09-17 18:54:47',
 'admin','2026-09-17 19:05:48','','2026-09-17 12:46:10'),
(16,'PK-1789657525-606','DFG-9987',2,'2026-09-17 20:35:25',NULL,
 0,0.00,'VOID_REQUESTED',0,'asd','Sunil','2026-09-17 20:39:45',
 NULL,NULL,NULL,'2026-09-17 15:05:25'),
(17,'PK-1789658399-653','BVF-5564',1,'2026-09-17 20:49:59',NULL,
 0,0.00,'PARKED',0,NULL,NULL,NULL,NULL,NULL,NULL,'2026-09-17 15:19:59'),
(18,'PK-1789658460-845','DSV-0007',1,'2026-09-17 20:51:00',NULL,
 0,0.00,'VOID_REQUESTED',0,'afsd','Nimal','2026-09-17 20:54:41',
 NULL,NULL,NULL,'2026-09-17 15:21:00'),
(19,'PK-1789658767-848','DGT-5555',2,'2026-09-17 20:56:07',NULL,
 0,0.00,'VOID_REQUESTED',0,'sdfef','nimal','2026-09-17 20:56:28',
 NULL,NULL,NULL,'2026-09-17 15:26:07')
ON DUPLICATE KEY UPDATE
  `vehicle_number`=VALUES(`vehicle_number`),
  `vehicle_type_id`=VALUES(`vehicle_type_id`),
  `entry_time`=VALUES(`entry_time`),
  `exit_time`=VALUES(`exit_time`),
  `duration_hours`=VALUES(`duration_hours`),
  `total_fee`=VALUES(`total_fee`),
  `status`=VALUES(`status`),
  `is_vip`=VALUES(`is_vip`),
  `action_reason`=VALUES(`action_reason`),
  `requested_by`=VALUES(`requested_by`),
  `requested_at`=VALUES(`requested_at`),
  `reviewed_by`=VALUES(`reviewed_by`),
  `reviewed_at`=VALUES(`reviewed_at`),
  `admin_notes`=VALUES(`admin_notes`);


-- ============================================================
-- 6. VIP PARKING SESSIONS
-- VIP vehicles do NOT receive a normal parking ticket.
-- This table is the gate/parking record for an approved VIP visit.
-- ============================================================
CREATE TABLE IF NOT EXISTS `vip_parking_sessions` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `vip_request_id` int(11) DEFAULT NULL,
  `vehicle_number` varchar(20) NOT NULL,
  `driver_name` varchar(100) DEFAULT NULL,
  `contact_no` varchar(20) DEFAULT NULL,
  `reason` text DEFAULT NULL,
  `entry_time` datetime NOT NULL,
  `exit_time` datetime DEFAULT NULL,
  `duration_minutes` int(11) NOT NULL DEFAULT 0,
  `status` enum('PARKED','COMPLETED','CANCELLED') NOT NULL DEFAULT 'PARKED',
  `slot_label` varchar(30) DEFAULT NULL,
  `authorized_by` varchar(100) DEFAULT NULL,
  `exited_by` varchar(100) DEFAULT NULL,
  `gate_notes` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_vip_session_vehicle_status` (`vehicle_number`,`status`),
  KEY `idx_vip_session_entry` (`entry_time`),
  KEY `idx_vip_session_status` (`status`),
  KEY `idx_vip_session_request` (`vip_request_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ============================================================
-- 7. AUDIT LOGS
-- ============================================================
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `user_name` varchar(100) DEFAULT NULL,
  `action` text DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_audit_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

INSERT INTO `audit_logs` (`id`,`user_name`,`action`,`created_at`) VALUES
(1,'Nimal','Void (Mistake/No-Show) requested for Ticket ID: 15. Reason: Double','2026-09-17 13:24:47'),
(2,'Entry Terminal','Entry ticket PK-1789657525-606 issued for DFG-9987 (Car / Van / SUV)','2026-09-17 15:05:26'),
(3,'Sunil','Void (Mistake/No-Show) requested for Ticket ID: 16. Reason: asd','2026-09-17 15:09:45'),
(4,'Entry Terminal','Entry ticket PK-1789658399-653 issued for BVF-5564 (Bike / Three-Wheel)','2026-09-17 15:19:59'),
(5,'Entry Terminal','Entry ticket PK-1789658460-845 issued for DSV-0007 (Bike / Three-Wheel)','2026-09-17 15:21:00'),
(6,'Nimal','Void (Mistake/No-Show) requested for Ticket ID: 18. Reason: afsd','2026-09-17 15:24:41'),
(7,'Entry Terminal','Entry ticket PK-1789658767-848 issued for DGT-5555 (Car / Van / SUV)','2026-09-17 15:26:07'),
(8,'nimal','Void (Mistake/No-Show) requested for Ticket ID: 19. Reason: sdfef','2026-09-17 15:26:28')
ON DUPLICATE KEY UPDATE
  `user_name`=VALUES(`user_name`),
  `action`=VALUES(`action`),
  `created_at`=VALUES(`created_at`);

-- ============================================================
-- 8. FOREIGN KEYS
-- Add only if they do not already exist.
-- ============================================================
SET @fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tickets'
    AND CONSTRAINT_NAME = 'fk_tickets_vehicle_types'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql = IF(
  @fk_exists = 0,
  'ALTER TABLE tickets ADD CONSTRAINT fk_tickets_vehicle_types FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id) ON DELETE CASCADE',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @fk_exists = (
  SELECT COUNT(*)
  FROM information_schema.TABLE_CONSTRAINTS
  WHERE CONSTRAINT_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vip_requests'
    AND CONSTRAINT_NAME = 'fk_vip_vehicle_type'
    AND CONSTRAINT_TYPE = 'FOREIGN KEY'
);

SET @sql = IF(
  @fk_exists = 0,
  'ALTER TABLE vip_requests ADD CONSTRAINT fk_vip_vehicle_type FOREIGN KEY (vehicle_type_id) REFERENCES vehicle_types(id) ON DELETE SET NULL',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

-- ============================================================
-- 9. INDEXES (safe checks)
-- ============================================================
SET @idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'vip_requests'
    AND INDEX_NAME = 'idx_vip_vehicle_status'
);

SET @sql = IF(
  @idx_exists = 0,
  'CREATE INDEX idx_vip_vehicle_status ON vip_requests(vehicle_number,status,ticket_code)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @idx_exists = (
  SELECT COUNT(*)
  FROM information_schema.STATISTICS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'tickets'
    AND INDEX_NAME = 'idx_ticket_vehicle_status'
);

SET @sql = IF(
  @idx_exists = 0,
  'CREATE INDEX idx_ticket_vehicle_status ON tickets(vehicle_number,status)',
  'SELECT 1'
);
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================
-- 10. FINAL CHECKS
-- ============================================================
SELECT 'DATABASE READY' AS result;

SELECT id, type_name, hourly_rate
FROM vehicle_types
ORDER BY id;

SELECT id, username, email
FROM admins
ORDER BY id;

SELECT id, full_name, username, email, status
FROM security_users
ORDER BY id;

SELECT id, vehicle_number, status, ticket_code
FROM vip_requests
ORDER BY id DESC;

-- ============================================================
-- END
-- ============================================================
