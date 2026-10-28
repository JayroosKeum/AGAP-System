-- Migration: Allow 'Late' in paanyaya_sessions attendance columns
-- Standardizes attendance states across Paanyaya, Mediation, and Conciliation

ALTER TABLE paanyaya_sessions 
    MODIFY COLUMN complainant_attended ENUM('Present', 'Late', 'Absent') DEFAULT 'Absent',
    MODIFY COLUMN respondent_attended ENUM('Present', 'Late', 'Absent') DEFAULT 'Absent';
