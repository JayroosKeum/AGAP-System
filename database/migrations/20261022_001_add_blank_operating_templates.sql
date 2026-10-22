-- Migration: 20261022_001_add_blank_operating_templates.sql
-- Description: Add PATAWAG, CASE DOCUMENTATION, and MINUTES FORM to official blank templates

INSERT INTO document_templates (template_name, description) VALUES
('PATAWAG (Pre-docketing Paanyaya Form)', 'Official Blank Paanyaya / Pre-docketing Invitation Form before formal case docketing.'),
('Case Profile & Intake Documentation Jacket', 'Official Case Folder Jacket and Profile Record containing parties, timeline, and document inventory.'),
('Minutes of Hearing (Katitikan ng Pagdinig)', 'Official Blank Minutes of Hearing Form for Mediation, Pangkat Conciliation, and Pre-docketing dialogues.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
