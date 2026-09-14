-- Add check-in reminder email tracking columns to bookings table
-- Run this migration to enable the check-in reminder email feature

ALTER TABLE bookings
ADD COLUMN IF NOT EXISTS checkin_reminder_scheduled_at DATETIME NULL COMMENT 'Scheduled time for check-in reminder email',
ADD COLUMN IF NOT EXISTS checkin_reminder_sent_at DATETIME NULL COMMENT 'Actual time when check-in reminder email was sent',
ADD COLUMN IF NOT EXISTS checkin_reminder_status VARCHAR(50) DEFAULT 'Not Scheduled' COMMENT 'Status of check-in reminder email (Not Scheduled, Scheduled, Sent, Failed, Skipped)';

-- Add index for faster querying of due reminders
CREATE INDEX IF NOT EXISTS idx_checkin_reminder_scheduled ON bookings(checkin_reminder_scheduled_at, checkin_reminder_status);
