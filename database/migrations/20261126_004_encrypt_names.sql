-- Widen name columns for authenticated AES-256-GCM ciphertext. Existing values
-- are preserved; run pii_backfill_and_rotate.php with the protected application
-- key after this migration and before completing production cutover.
ALTER TABLE residents DROP INDEX idx_residents_name, MODIFY first_name VARCHAR(512) NOT NULL, MODIFY middle_name VARCHAR(512) NULL, MODIFY last_name VARCHAR(512) NOT NULL, MODIFY suffix VARCHAR(512) NULL, ADD INDEX idx_residents_name (last_name(128), first_name(128));
ALTER TABLE users MODIFY first_name VARCHAR(512) NOT NULL, MODIFY middle_name VARCHAR(512) NULL, MODIFY last_name VARCHAR(512) NOT NULL;
