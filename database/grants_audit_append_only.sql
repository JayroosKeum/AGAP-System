-- Least-privilege database grant template for production AGAP deployments (A7).
-- Apply this using a privileged DBA account so the application runtime account
-- can INSERT and SELECT from audit_trails, but cannot UPDATE or DELETE rows.
-- Note: MySQL triggers trg_audit_trails_no_update and trg_audit_trails_no_delete
-- also enforce append-only behavior at the storage-engine trigger layer.

-- Replace 'agap_app'@'%' with your actual runtime DB user/host.
-- REVOKE UPDATE, DELETE ON agap_db.audit_trails FROM 'agap_app'@'%';
-- GRANT SELECT, INSERT ON agap_db.audit_trails TO 'agap_app'@'%';
-- FLUSH PRIVILEGES;
