-- Migration: Add Secondary Category support to complaints table
-- Date: 2026-11-04
-- Target: MySQL 5.7+ / MySQL 8.0+ / Azure Database for MySQL

ALTER TABLE `complaints`
  ADD COLUMN `secondary_category_id` INT UNSIGNED NULL AFTER `category_id`,
  ADD COLUMN `secondary_other_category_specification` VARCHAR(255) NULL AFTER `other_category_specification`;
