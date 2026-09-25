-- Add review email tracking fields to bookings table
-- Run this SQL in your MySQL database to add the required fields

ALTER TABLE bookings 
ADD COLUMN review_email_scheduled_at DATETIME NULL AFTER payment_proof,
ADD COLUMN review_email_sent_at DATETIME NULL AFTER review_email_scheduled_at,
ADD COLUMN review_email_status ENUM('Not Scheduled', 'Scheduled', 'Sent', 'Failed', 'Skipped - Status Changed') DEFAULT 'Not Scheduled' AFTER review_email_sent_at;

-- Normalize columns created by older versions of this migration.
ALTER TABLE bookings
MODIFY COLUMN review_email_scheduled_at DATETIME NULL DEFAULT NULL,
MODIFY COLUMN review_email_sent_at DATETIME NULL DEFAULT NULL;
