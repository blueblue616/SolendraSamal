<?php
/**
 * Cron Worker: Send Google Review Emails
 *
 * Run every 5-15 minutes via cron job.
 * For XAMPP/local development, run manually:
 * php cron/send_review_emails.php
 */

date_default_timezone_set('Asia/Manila');

error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../email_helper.php';

echo "[" . date('Y-m-d H:i:s') . "] Starting Google Review Email Worker\n";

try {
    if ($conn->connect_error) {
        throw new Exception("Database connection failed: " . $conn->connect_error);
    }

    backfillUnscheduledReviewEmails($conn);
    $summary = processDueReviewEmails($conn);

    echo "Processed: {$summary['processed']}\n";
    echo "Sent: {$summary['sent']}\n";
    echo "Failed: {$summary['failed']}\n";
    echo "Skipped: {$summary['skipped']}\n";
    echo "[" . date('Y-m-d H:i:s') . "] Worker completed\n";

    exit(0);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    echo "[" . date('Y-m-d H:i:s') . "] Worker failed\n";
    exit(1);
}
