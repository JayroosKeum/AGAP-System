-- =============================================================================
-- Migration: 20261021_005_comprehensive_kp_categories.sql
-- Purpose:
--   Expand complaint categories to comprehensively cover all disputes under
--   the Katarungang Pambarangay (KP) Law (RA 7160 Sec. 408-412, Revised Penal Code,
--   Civil Code, and Special Laws: BP 22, PD 1612, LOI 1314) as displayed on the
--   official Katarungang Pambarangay master poster.
--
--   Each category includes:
--   - Full English name with legal reference / RPC Article citations where applicable
--   - Official Tagalog counterpart
--   - Alphabetical arrangement from A to Z, with 'Others (Please Specify)' at the end.
-- =============================================================================

SET NAMES utf8mb4;

-- Ensure category_name column is large enough
ALTER TABLE complaint_categories MODIFY COLUMN category_name VARCHAR(255) NOT NULL;

-- 1. Standardize existing in-use categories
UPDATE complaint_categories 
SET category_name = 'Non-Payment of Debt / Hindi Pagbabayad ng Utang' 
WHERE category_id = 1;

UPDATE complaint_categories 
SET category_name = 'Breach of Contract or Agreement / Paglabag sa Kontrata o Kasunduan' 
WHERE category_id = 2;

UPDATE complaint_categories 
SET category_name = 'Threats (Grave, Light, or Other Threats) (Arts. 282, 283, 285) / Pagbabanta (Threats)' 
WHERE category_id = 5;

UPDATE complaint_categories 
SET category_name = 'Lease and Rental Disputes / Pagpapaupa at Alitan sa Upa' 
WHERE category_id = 6;

UPDATE complaint_categories 
SET category_name = 'Others (Please Specify) / Iba pa (Pakitukoy)' 
WHERE category_id = 25;

-- 2. Upsert comprehensive list of all 51 Katarungang Pambarangay complaint categories
INSERT INTO complaint_categories (category_name, description) VALUES
('Abandonment of a Minor (Arts. 276, 277) / Pagpapabaya sa Bata o Menor de Edad', 'Abandonment of a child or minor under 7 years old by custodian or person legally charged with care under Arts. 276-277 of the Revised Penal Code.'),
('Abandonment of Persons in Danger or One\'s Own Victim (Art. 275) / Pagpapabaya sa Taong nasa Panganib o Sariling Biktima', 'Failure to render assistance to a wounded or endangered person or one\'s own accident victim under Art. 275 of the Revised Penal Code.'),
('Acts of Lasciviousness with Consent (Art. 339) / Malalaswang Gawa na may Pahintulot', 'Offenses involving lascivious conduct committed with consent under Art. 339 of the Revised Penal Code.'),
('Agrarian Disputes (LOI 1314) / Alitan sa Lupang Pang-agrikultura (Agraryo)', 'Disputes between agricultural landowners, tenants, and farmworkers covered under LOI 1314 within Katarungang Pambarangay jurisdiction.'),
('Alarms and Scandals (Art. 155) / Panggugulo at Alarma', 'Public disturbance, disorderly behavior, discharge of firearms/firecrackers, or disruptive nighttime noise under Art. 155 of the Revised Penal Code.'),
('Alteration of Boundary Marks or Landmarks (Art. 313) / Pagbabago ng mga Palatandaan ng Hangganan', 'Unauthorized removal, destruction, or alteration of monuments, fences, or property boundary markers under Art. 313 of the Revised Penal Code.'),
('Animal and Pet-Related Disputes / Alitan Dulot ng Alagang Hayop (Kagat, Ingay, Dumi)', 'Disputes regarding domestic pet bites, barking, pet feces, roaming livestock, or damage caused by domestic animals under Art. 2183 of the Civil Code.'),
('Anti-Fencing Law (PD 1612) / Pagbili o Pagtanggap ng Nakaw na Ari-arian (Anti-Fencing)', 'Buying, keeping, concealing, or selling stolen items where the penalty is within Katarungang Pambarangay jurisdiction under PD 1612.'),
('Assistance to Suicide (Art. 253) / Pagtulong sa Pagpapatiwakal', 'Lending assistance to suicide within the statutory penalty threshold under Art. 253 of the Revised Penal Code.'),
('Bouncing Checks (BP 22) / Pag-isyu ng Talbog na Tseke (Bouncing Checks)', 'Issuing unfunded or bouncing checks covered by Batas Pambansa Blg. 22 within Katarungang Pambarangay conciliation thresholds.'),
('Boundary Dispute and Encroachment / Pagtatalo sa Hangganan ng Lupa at Bakod', 'Disputes concerning boundary lines, fence overlapping, structure encroachment, and property limits under KP civil jurisdiction.'),
('Breach of Contract or Agreement / Paglabag sa Kontrata o Kasunduan', 'Violations or failure to fulfill contractual terms, promissory obligations, or covenants under the Civil Code.'),
('Coercions (Grave, Light, or Similar) (Arts. 286-289) / Pamimilit o Pagpilit (Coercion)', 'Compelling another by violence or intimidation to perform an act against their will, or preventing lawful acts under Arts. 286-289 of the Revised Penal Code.'),
('Collection of Debts or Rentals / Paglikom ng mga Utang o Upa', 'Civil claims for collection of overdue rental payments, loans, or financial liabilities under civil obligations.'),
('Damages and Reimbursement / Bayad-Pinsala at Pagbabalik ng Halaga', 'Civil claims for indemnity, property reimbursement, or financial compensation for harm or damages sustained under quasi-delicts.'),
('Dispensing of Abortives by Pharmacist (Art. 259) / Pagbebenta o Pagbibigay ng Pampalaglag ng Parmasyutiko', 'Dispensing abortives without proper medical prescription under Art. 259 of the Revised Penal Code.'),
('Easement and Right-of-Way / Karapatan sa Daanan at Daluyan (Easement)', 'Disputes concerning rights of way, access roads, drainage passages, and lateral support under Civil Code Arts. 613-687.'),
('Estafa and Swindling (Arts. 315, 316, 317) / Panlilinlang o Estafa', 'Minor swindling, fraudulent transactions, or deceitful inducement of minors under Arts. 315-317 of the Revised Penal Code.'),
('False Certificates (Arts. 174, 175) / Paggamit o Pagbibigay ng Pekeng Sertipiko', 'Issuance or fraudulent use of false medical or official certificates under Arts. 174-175 of the Revised Penal Code.'),
('False Testimony Against or In Favor of Defendant (Arts. 180, 181) / Pagsisinungaling o Maling Testimonya', 'Giving false testimony in civil or criminal proceedings within statutory limits under Arts. 180-181 of the Revised Penal Code.'),
('Family and Domestic Relations / Mga Relasyong Pampamilya at Tahanan', 'Civil disputes between family members, domestic support arrangements, and living arrangements under the Family Code.'),
('Fictitious Name and Concealment of True Name (Art. 178) / Paggamit ng Huwad na Pangalan at Pagkukubli ng Tunay na Pangalan', 'Using an assumed fictitious name or concealing true identity in public transactions under Art. 178 of the Revised Penal Code.'),
('Grave Scandal (Art. 200) / Malubhang Eskandalo (Grave Scandal)', 'Public acts of indecency or scandalous behavior not expressly penalized by other articles under Art. 200 of the Revised Penal Code.'),
('Illegal Use of Uniforms or Insignia (Art. 179) / Ilegal na Paggamit ng Uniporme o Sagisag', 'Publicly wearing unauthorized uniforms, badges, or official insignia under Art. 179 of the Revised Penal Code.'),
('Imprudence and Negligence (Art. 365) / Pinsala dulot ng Kapabayaan o Imprudensya', 'Quasi-offenses causing physical injury or damage to property through reckless or simple imprudence under Art. 365 of the Revised Penal Code.'),
('Incriminating Innocent Persons & Intriguing Against Honor (Arts. 363, 364) / Pagsasangkot ng Walang-Salang Tao at Paninirang-Puri', 'Falsely planting incriminating evidence or engaging in intrigues to blemish reputation under Arts. 363-364 of the Revised Penal Code.'),
('Inducing Minor to Abandon Home (Art. 271) / Pag-uudyok sa Menor de Edad na Lumayas sa Tahanan', 'Inducing or convincing an unmarried minor to abandon parental custody under Art. 271 of the Revised Penal Code.'),
('Lease and Rental Disputes / Pagpapaupa at Alitan sa Upa', 'Disputes between landlords and tenants regarding rental agreements, lease terms, and property maintenance under the Civil Code.'),
('Less Serious Physical Injuries (Art. 265) / Hindi Lubhang Malubhang Pananakit sa Katawan', 'Physical harm requiring medical attendance or incapacitating the victim from 10 to 30 days under Art. 265 of the Revised Penal Code.'),
('Malicious Mischief and Damage to Property (Arts. 327, 329) / Paninira ng Ari-arian (Malicious Mischief)', 'Willful or malicious destruction of or damage to another\'s property not amounting to arson under Arts. 327 and 329 of the Revised Penal Code.'),
('Neighborhood Nuisance (Noise, Odor, Waste) / Pambubulabog sa Kapaligiran (Ingay, Amoy, Basura)', 'Disputes regarding excessive videoke/noise disturbances, foul odors, smoke, or improper waste disposal under Civil Code Art. 694.'),
('Non-Payment of Debt / Hindi Pagbabayad ng Utang', 'Civil claims regarding unpaid debts, promissory notes, or overdue financial liabilities under contracts and obligations.'),
('Occupation of Real Property or Usurpation of Real Rights (Art. 312) / Pang-aagaw o Ilegal na Pagsakop sa Ari-arian', 'Unlawful occupation of real property or usurpation of real rights under Art. 312 of the Revised Penal Code.'),
('Physical Injuries in a Tumultuous Affray (Art. 252) / Pananakit sa Katawan sa Gitna ng Kaguluhan', 'Physical injuries inflicted during a tumultuous brawl where the specific author cannot be identified under Art. 252 of the Revised Penal Code.'),
('Premature Marriages (Art. 351) / Maagang Pagpapakasal (Premature Marriage)', 'Marriages contracted before the expiration of statutory waiting periods under Art. 351 of the Revised Penal Code.'),
('Price Control Violations / Paglabag sa Pagkontrol ng Presyo ng Bilihin', 'Overpricing or violation of local and national price control regulations on basic commodities.'),
('Removal, Sale or Pledge of Mortgaged Property (Art. 319) / Pag-aalis, Pagbenta, o Pagsangla ng Nakasanglang Ari-arian', 'Unauthorized removal, sale, or pledging of personal property pledged under chattel mortgage under Art. 319 of the Revised Penal Code.'),
('Responsibility of Participants in a Duel (Art. 260) / Pananagutan ng mga Kalahok sa Duelo', 'Participation in a duel causing slight harm or challenge under Art. 260 of the Revised Penal Code.'),
('Seizure of Correspondence or Secrets with Abuse of Office (Arts. 290, 291) / Pagharang ng Liham o Pagsisiwalat ng Lihim', 'Opening private correspondence or unlawfully revealing confidential secrets under Arts. 290-291 of the Revised Penal Code.'),
('Simple Seduction (Art. 338) / Simpleng Panunukso o Pang-aakit (Simple Seduction)', 'Seduction of an unmarried minor woman through deceit under Art. 338 of the Revised Penal Code.'),
('Slander and Defamation (Arts. 358, 359) / Paninirang-Puri (Oral Defamation / Slander)', 'Oral defamation, slander by deed, or publicly insulting another under Arts. 358-359 of the Revised Penal Code.'),
('Slight Physical Injuries and Maltreatment (Art. 266) / Bahagyang Pananakit at Pagmamalupit (Slight Physical Injuries)', 'Physical injuries requiring medical attendance for 1 to 9 days, or ill-treatment without physical injury under Art. 266 of the Revised Penal Code.'),
('Specific Performance of Obligation / Tiyak na Pagtupad ng Obligasyon', 'Civil claims compelling fulfillment of a promised prestation or obligation under contractual agreements.'),
('Theft and Other Thefts (Arts. 309, 310) / Pagnanakaw (Theft)', 'Theft where the value of stolen property falls within the statutory conciliation threshold of Katarungang Pambarangay under Arts. 309-310.'),
('Threatening to Publish for Compensation or Prohibited Publications (Arts. 154, 356, 357) / Pagbabanta ng Paglalathala o Ipinagbabawal na Paglalathala', 'Blackmail through publication, or publishing unauthorized or prohibited literature under Arts. 154, 356, and 357 of the Revised Penal Code.'),
('Threats (Grave, Light, or Other Threats) (Arts. 282, 283, 285) / Pagbabanta (Threats)', 'Threatening another with a crime or harm under Arts. 282, 283, and 285 of the Revised Penal Code.'),
('Trespass to Dwelling and Other Forms (Arts. 280, 281) / Ilegal na Pagpasok sa Bahay o Bakuran (Trespass)', 'Unlawful entry into an inhabited dwelling or private premises without permission under Arts. 280-281 of the Revised Penal Code.'),
('Unjust Vexation (Art. 287) / Hindi Makatarungang Pang-iinis o Panggigipit (Unjust Vexation)', 'Harassment, emotional disturbance, or vexatious behavior without physical injury under Art. 287 of the Revised Penal Code.'),
('Unlawful Arrest (Art. 269) / Ilegal na Pag-aresto o Pagpigil', 'Arresting or detaining another person without legal authority or valid warrant under Art. 269 of the Revised Penal Code.'),
('Violation of Local Ordinances / Paglabag sa Lokal na Ordinansa', 'Infractions of barangay and city ordinances punishable within Katarungang Pambarangay jurisdiction.'),
('Others (Please Specify) / Iba pa (Pakitukoy)', 'Other civil disputes or light criminal offenses covered by Katarungang Pambarangay jurisdiction not specifically listed above.')
ON DUPLICATE KEY UPDATE description = VALUES(description);
