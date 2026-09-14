-- Add review email tracking fields to bookings table
-- Run this SQL in your MySQL database to add the required fields

ALTER TABLE bookings 
ADD COLUMN review_email_scheduled_at DATETIME NULL AFTER payment_proof,
ADD COLUMN review_email_sent_at DATETIME NULL AFTER review_email_scheduled_at,
ADD COLUMN review_email_status ENUM('Not Scheduled', 'Scheduled', 'Sent', 'Failed', 'Skipped - Status Changed') DEFAULT 'Not Scheduled' AFTER review_email_sent_at;
