-- Migration: Add Dismissed outcome to paanyaya_minutes and paanyaya_sessions
-- Description: Prevent truncation error when dismissing complaint due to complainant absence

ALTER TABLE paanyaya_minutes 
    MODIFY COLUMN outcome ENUM('Settled', 'No_Agreement', 'Party_Absent', 'Dismissed') NOT NULL,
    MODIFY COLUMN complainant_choice ENUM('Request_Paanyaya_2', 'Escalate_Mediation', 'Dismissed', 'None') DEFAULT 'None';

ALTER TABLE paanyaya_sessions 
    MODIFY COLUMN session_outcome ENUM('Settled', 'Absent_Retry', 'Continued_Dialogue', 'Impasse_Escalate', 'Max_Failed_Escalate', 'Dismissed') NULL;
