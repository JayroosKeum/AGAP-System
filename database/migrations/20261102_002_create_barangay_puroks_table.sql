-- Migration: Create barangay_puroks table for dynamic management of Barangay Tumana areas
USE agap_db;

CREATE TABLE IF NOT EXISTS `barangay_puroks` (
  `id` INT AUTO_INCREMENT PRIMARY KEY,
  `purok_name` VARCHAR(100) NOT NULL UNIQUE,
  `description` VARCHAR(255) NULL,
  `is_active` TINYINT(1) NOT NULL DEFAULT 1,
  `sort_order` INT NOT NULL DEFAULT 0,
  `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX `idx_purok_active` (`is_active`, `sort_order`, `purok_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed default known Tumana puroks / areas if table is empty
INSERT IGNORE INTO `barangay_puroks` (`purok_name`, `description`, `sort_order`, `is_active`) VALUES
('Purok 1', 'Barangay Tumana Purok 1', 1, 1),
('Purok 2', 'Barangay Tumana Purok 2', 2, 1),
('Purok 3', 'Barangay Tumana Purok 3', 3, 1),
('Purok 4', 'Barangay Tumana Purok 4', 4, 1),
('Purok 5', 'Barangay Tumana Purok 5', 5, 1),
('Purok 6', 'Barangay Tumana Purok 6', 6, 1),
('Palmera Homes', 'Palmera Homes area', 7, 1),
('Farmers Compound', 'Farmers Compound area', 8, 1),
('Twin River Subdivision', 'Twin River Subd. Phase 1 & 2', 9, 1),
('Riverside Area', 'Near Marikina River Dike', 10, 1),
('Concepcion Boundary', 'Tumana - Concepcion boundary', 11, 1);
