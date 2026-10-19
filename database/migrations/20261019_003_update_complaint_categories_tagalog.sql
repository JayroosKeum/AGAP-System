-- Migration: Add Tagalog alternatives to complaint categories
-- Format: "English / Tagalog" (e.g. "Threats / Pagbabanta")

UPDATE complaint_categories SET category_name = 'Non-Payment of Debt / Hindi Pagbabayad ng Utang' WHERE category_id = 1;
UPDATE complaint_categories SET category_name = 'Breach of Agreement / Paglabag sa Kasunduan' WHERE category_id = 2;
UPDATE complaint_categories SET category_name = 'Physical Injuries / Pananakit sa Katawan' WHERE category_id = 3;
UPDATE complaint_categories SET category_name = 'Defamation / Paninirang-puri' WHERE category_id = 4;
UPDATE complaint_categories SET category_name = 'Threats / Pagbabanta' WHERE category_id = 5;
UPDATE complaint_categories SET category_name = 'Property and Rental Disputes / Alitan sa Ari-arian at Paupahan' WHERE category_id = 6;
UPDATE complaint_categories SET category_name = 'Disturbance and Public Disorder / Panggugulo sa Kaayusan' WHERE category_id = 7;
UPDATE complaint_categories SET category_name = 'Malicious Mischief / Paninira ng Ari-arian' WHERE category_id = 8;
UPDATE complaint_categories SET category_name = 'Trespassing / Ilegal na Pagpasok' WHERE category_id = 9;
UPDATE complaint_categories SET category_name = 'Family and Domestic Disputes / Alitan sa Pamilya at Tahanan' WHERE category_id = 10;
