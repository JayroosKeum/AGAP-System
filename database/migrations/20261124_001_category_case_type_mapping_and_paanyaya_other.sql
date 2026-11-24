-- Add default_case_type to complaint_categories and preliminary_other_category_specification to paanyayas.
-- Safe to rerun (idempotent).

SET NAMES utf8mb4;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'complaint_categories' AND COLUMN_NAME = 'default_case_type'
), 'SELECT 1', 'ALTER TABLE complaint_categories ADD COLUMN default_case_type ENUM(\'Civil\', \'Criminal\') NOT NULL DEFAULT \'Civil\' AFTER ltia_code');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

SET @upgrade = IF(EXISTS (
    SELECT 1 FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'paanyayas' AND COLUMN_NAME = 'preliminary_other_category_specification'
), 'SELECT 1', 'ALTER TABLE paanyayas ADD COLUMN preliminary_other_category_specification VARCHAR(255) NULL AFTER preliminary_category_id');
PREPARE migration FROM @upgrade;
EXECUTE migration;
DEALLOCATE PREPARE migration;

-- Map standardized Katarungang Pambarangay categories to Criminal vs. Civil.
UPDATE complaint_categories
SET default_case_type = 'Criminal'
WHERE category_name LIKE 'Abandonment of a Minor%'
   OR category_name LIKE 'Abandonment of Persons in Danger%'
   OR category_name LIKE 'Acts of Lasciviousness%'
   OR category_name LIKE 'Alarms and Scandals%'
   OR category_name LIKE 'Alteration of Boundary Marks%'
   OR category_name LIKE 'Anti-Fencing Law%'
   OR category_name LIKE 'Assistance to Suicide%'
   OR category_name LIKE 'Bouncing Checks%'
   OR category_name LIKE 'Coercions%'
   OR category_name LIKE 'Dispensing of Abortives%'
   OR category_name LIKE 'Estafa and Swindling%'
   OR category_name LIKE 'False Certificates%'
   OR category_name LIKE 'False Testimony%'
   OR category_name LIKE 'Fictitious Name%'
   OR category_name LIKE 'Grave Scandal%'
   OR category_name LIKE 'Illegal Use of Uniforms%'
   OR category_name LIKE 'Imprudence and Negligence%'
   OR category_name LIKE 'Incriminating Innocent Persons%'
   OR category_name LIKE 'Inducing Minor to Abandon Home%'
   OR category_name LIKE 'Less Serious Physical Injuries%'
   OR category_name LIKE 'Malicious Mischief%'
   OR category_name LIKE 'Occupation of Real Property%'
   OR category_name LIKE 'Physical Injuries in a Tumultuous Affray%'
   OR category_name LIKE 'Premature Marriages%'
   OR category_name LIKE 'Price Control Violations%'
   OR category_name LIKE 'Removal, Sale or Pledge of Mortgaged Property%'
   OR category_name LIKE 'Responsibility of Participants in a Duel%'
   OR category_name LIKE 'Safe Spaces Act%'
   OR category_name LIKE 'Seizure of Correspondence%'
   OR category_name LIKE 'Simple Seduction%'
   OR category_name LIKE 'Slander and Defamation%'
   OR category_name LIKE 'Slight Physical Injuries%'
   OR category_name LIKE 'Theft and Other Thefts%'
   OR category_name LIKE 'Threatening to Publish%'
   OR category_name LIKE 'Threats%'
   OR category_name LIKE 'Trespass to Dwelling%'
   OR category_name LIKE 'Unjust Vexation%'
   OR category_name LIKE 'Unlawful Arrest%'
   OR category_name LIKE 'Violation of Local Ordinances%'
   OR ltia_code LIKE 'LTIA-CRIM%';

UPDATE complaint_categories
SET default_case_type = 'Civil'
WHERE category_name LIKE 'Agrarian Disputes%'
   OR category_name LIKE 'Boundary Dispute%'
   OR category_name LIKE 'Breach of Contract%'
   OR category_name LIKE 'Collection of Debts%'
   OR category_name LIKE 'Damages and Reimbursement%'
   OR category_name LIKE 'Easement and Right-of-Way%'
   OR category_name LIKE 'Family and Domestic Relations%'
   OR category_name LIKE 'Lease and Rental Disputes%'
   OR category_name LIKE 'Neighborhood Nuisance%'
   OR category_name LIKE 'Non-Payment of Debt%'
   OR category_name LIKE 'Specific Performance of Obligation%'
   OR category_name LIKE 'Others%'
   OR category_name LIKE 'Iba pa%';
