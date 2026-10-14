USE remastered_db;

START TRANSACTION;

ALTER TABLE profiles
    MODIFY sex ENUM('Male','Female','Other') NOT NULL DEFAULT 'Male',
    MODIFY civil_status ENUM('Single','Married','Widowed','Separated') NOT NULL DEFAULT 'Single',
    MODIFY residency_status ENUM('Permanent Resident','Temporary Resident','Tenant') NOT NULL DEFAULT 'Permanent Resident';

COMMIT;
