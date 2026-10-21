-- Migration: Expand Complaint Categories Covered by Katarungang Pambarangay (RA 7160)
-- and add "Others please specify" support.
-- Format: "English / Tagalog"

SET NAMES utf8mb4;

-- 1. Ensure category_name column can hold longer titles
ALTER TABLE complaint_categories MODIFY COLUMN category_name VARCHAR(255) NOT NULL;

-- 2. Add other_category_specification column to complaints
ALTER TABLE complaints 
    ADD COLUMN other_category_specification VARCHAR(255) NULL AFTER category_id;

-- 3. Insert expanded KP categories (both English and Tagalog)
INSERT INTO complaint_categories (category_name, description) VALUES
('Boundary Dispute and Encroachment / Alitan sa Hangganan ng Lupa at Bakod', 'Disputes concerning property boundaries, fence or wall encroachment, and land line overlapping under KP civil jurisdiction.'),
('Easement and Right-of-Way / Karapatan sa Daanan at Daluyan (Easement)', 'Disputes regarding right of way, water drainage, pathways, and property easements under the Civil Code.'),
('Unjust Vexation / Hindi Makatarungang Pang-iinis o Panggigipit', 'Light offenses involving vexatious behavior, harassment, or emotional irritation under Art. 287 of the Revised Penal Code.'),
('Coercion / Pamimilit o Pagpilit', 'Disputes involving compelling another to do something against their will or preventing lawful actions under Arts. 286-287 of the Revised Penal Code.'),
('Neighborhood Nuisance (Noise, Odor, Waste) / Pambubulabog sa Kapaligiran (Ingay, Amoy, Basura)', 'Disputes regarding excessive noise, videoke disturbances, foul odors, smoke, and improper waste disposal affecting neighbors.'),
('Animal and Pet-Related Disputes / Alitan Dulot ng Alagang Hayop (Kagat, Ingay, Dumi)', 'Disputes involving pet bites, barking, pet feces, roaming livestock, or damage caused by domestic animals under Art. 2183 of the Civil Code.'),
('Damage to Property through Reckless Imprudence / Pinsala sa Ari-arian dulot ng Kapabayaan', 'Unintentional damage to property resulting from negligence or vehicular incidents within the barangay.'),
('Simple Theft / Pagnanakaw ng Maliit na Halaga', 'Minor theft cases within statutory penalty thresholds covered by Katarungang Pambarangay conciliation.'),
('Swindling or Estafa / Panlilinlang o Estafa', 'Minor swindling or deceitful transactions within the statutory threshold punishable by light penalties.'),
('Usurpation of Real Property or Rights / Pang-aagaw ng Karapatan sa Ari-arian', 'Unlawful occupation or usurpation of real property rights under Arts. 312-313 of the Revised Penal Code.'),
('Alteration of Boundary Marks / Pagbabago ng mga Palatandaan ng Hangganan', 'Unauthorized removal or alteration of boundary monuments or property markers under Art. 313 of the Revised Penal Code.'),
('Acts of Lasciviousness and Seduction / Pambabastos at Malalaswang Gawa', 'Disputes involving minor sexual harassment, acts of lasciviousness, or seduction within KP statutory penalty limits.'),
('Illegal Use of Uniforms or False Pretenses / Maling Paggamit ng Pagkakakilanlan', 'Disputes involving misrepresentation or improper use of insignia under Arts. 177-179 of the Revised Penal Code.'),
('Damages and Reimbursement / Bayad-Pinsala at Pagbabalik ng Halaga', 'Civil claims for reimbursement of expenses, compensation for damages, or return of personal property.'),
('Others (Please Specify) / Iba pa (Pakitukoy)', 'Other civil disputes or light criminal offenses covered by Katarungang Pambarangay jurisdiction not specifically listed above.')
ON DUPLICATE KEY UPDATE category_name = VALUES(category_name), description = VALUES(description);
