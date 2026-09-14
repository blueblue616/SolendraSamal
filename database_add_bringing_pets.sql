-- Add bringing_pets column to bookings table
-- Run this SQL to add the new field to the database

ALTER TABLE bookings ADD COLUMN bringing_pets VARCHAR(10) DEFAULT 'no' AFTER requests;
