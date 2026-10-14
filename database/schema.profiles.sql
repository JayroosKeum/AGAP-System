USE remastered_db;

START TRANSACTION;

CREATE TABLE IF NOT EXISTS profiles (
    profile_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_no VARCHAR(30) NOT NULL,
    first_name VARCHAR(100) NOT NULL,
    middle_name VARCHAR(100) NULL,
    last_name VARCHAR(100) NOT NULL,
    suffix VARCHAR(20) NULL,
    birth_date DATE NULL,
    sex ENUM('Male','Female','Not Specified') NOT NULL DEFAULT 'Not Specified',
    civil_status ENUM('Single','Married','Widowed','Separated','Not Specified') NOT NULL DEFAULT 'Not Specified',
    contact_no VARCHAR(20) NULL,
    email VARCHAR(150) NULL,
    address_line VARCHAR(255) NOT NULL,
    purok_sitio VARCHAR(100) NULL,
    barangay VARCHAR(100) NOT NULL,
    city_municipality VARCHAR(100) NOT NULL,
    province VARCHAR(100) NOT NULL,
    residency_status ENUM('Permanent Resident','Tenant','Non-Resident') NOT NULL DEFAULT 'Permanent Resident',
    latitude DECIMAL(10,7) NULL,
    longitude DECIMAL(10,7) NULL,
    voter_status ENUM('Registered','Not Registered','Not Specified') NOT NULL DEFAULT 'Not Specified',
    occupation VARCHAR(150) NULL,
    notes TEXT NULL,
    status ENUM('Active','Archived') NOT NULL DEFAULT 'Active',
    created_by INT UNSIGNED NOT NULL,
    updated_by INT UNSIGNED NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_profiles_profile_no (profile_no),
    KEY idx_profiles_name (last_name, first_name, middle_name),
    KEY idx_profiles_contact_no (contact_no),
    KEY idx_profiles_status (status),
    CONSTRAINT fk_profiles_created_by FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE RESTRICT,
    CONSTRAINT fk_profiles_updated_by FOREIGN KEY (updated_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS profile_history (
    profile_history_id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    profile_id BIGINT UNSIGNED NOT NULL,
    action ENUM('Created','Updated','Archived','Reactivated') NOT NULL,
    old_values LONGTEXT NULL,
    new_values LONGTEXT NULL,
    acted_by INT UNSIGNED NOT NULL,
    acted_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_profile_history_profile (profile_id, acted_at),
    CONSTRAINT fk_profile_history_profile FOREIGN KEY (profile_id) REFERENCES profiles(profile_id) ON DELETE RESTRICT,
    CONSTRAINT fk_profile_history_actor FOREIGN KEY (acted_by) REFERENCES users(user_id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

COMMIT;
