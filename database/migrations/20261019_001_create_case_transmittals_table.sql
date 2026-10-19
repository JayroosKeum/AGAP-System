-- Migration: Create case_transmittals table for MTC & DILG Court Transmittal Module
-- Date: 2026-10-19

CREATE TABLE IF NOT EXISTS case_transmittals (
    transmittal_id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    case_id INT UNSIGNED NOT NULL,
    transmittal_number VARCHAR(50) NOT NULL UNIQUE,
    settlement_date DATE NOT NULL,
    ten_day_expiry_date DATE NOT NULL,
    target_agency ENUM('MTC', 'DILG', 'Both') NOT NULL DEFAULT 'Both',
    transmittal_date DATE NULL,
    transmitting_officer_id INT UNSIGNED NULL,
    attesting_officer_id INT UNSIGNED NULL,
    status ENUM('Pending', 'Generated', 'Transmitted', 'Acknowledged') NOT NULL DEFAULT 'Pending',
    mtc_received_date DATE NULL,
    mtc_receiver_name VARCHAR(150) NULL,
    dilg_received_date DATE NULL,
    dilg_receiver_name VARCHAR(150) NULL,
    receiving_copy_path VARCHAR(255) NULL,
    remarks TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (case_id) REFERENCES cases(case_id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
