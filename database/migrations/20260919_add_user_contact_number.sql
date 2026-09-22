-- Apply once to an existing populated agap_db before using User Management contact numbers.
-- Fresh databases receive this column from database/schema.sql.
ALTER TABLE users
    ADD COLUMN contact_no VARCHAR(20) NULL AFTER email;
