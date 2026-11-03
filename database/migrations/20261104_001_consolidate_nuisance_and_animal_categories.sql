-- =============================================================================
-- Migration: 20261104_001_consolidate_nuisance_and_animal_categories.sql
-- Purpose:
--   Consolidate stray animals, barking dogs, loud videoke, and garbage
--   into a single unified complaint category.
-- =============================================================================

SET NAMES utf8mb4;

-- 1. Find target unified category (Neighborhood Nuisance) and update its title, Tagalog name, and description
UPDATE complaint_categories
SET category_name = 'Neighborhood Nuisance & Animal Disturbances (Stray Animals, Barking Dogs, Loud Videoke, Garbage) / Pambubulabog sa Kapaligiran at Alagang Hayop (Gala na Hayop, Tahol ng Aso, Maingay na Videoke, Basura)',
    tagalog_name = 'Pambubulabog sa Kapaligiran at Alagang Hayop (Gala na Hayop, Tahol ng Aso, Maingay na Videoke, Basura)',
    description = 'Consolidated neighborhood nuisance disputes involving stray or roaming animals, barking dogs, pet bites/feces, excessive noise or loud videoke disturbances, foul odors, and improper garbage or waste disposal under Civil Code Arts. 694 & 2183 and local ordinances.',
    is_active = 1
WHERE category_name LIKE 'Neighborhood Nuisance%' OR category_id = 31;

-- 2. Reassign any existing complaints from the separate Animal/Pet category to the unified category
SET @unified_cat_id = (SELECT category_id FROM complaint_categories WHERE category_name LIKE 'Neighborhood Nuisance%' LIMIT 1);
SET @animal_cat_id = (SELECT category_id FROM complaint_categories WHERE category_name LIKE 'Animal and Pet-Related Disputes%' LIMIT 1);

UPDATE complaints
SET category_id = @unified_cat_id
WHERE @unified_cat_id IS NOT NULL AND @animal_cat_id IS NOT NULL AND category_id = @animal_cat_id;

-- 3. Deactivate the separate Animal/Pet category so only the unified category appears
UPDATE complaint_categories
SET is_active = 0
WHERE @animal_cat_id IS NOT NULL AND category_id = @animal_cat_id;
