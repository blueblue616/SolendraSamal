<?php
/**
 * Email Queue Processor
 * Processes queued emails and sends them via PHPMailer
 * Run this via cron every 1-2 minutes
 */

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../email_helper.php';

// Set timezone
date_default_timezone_set('Asia/Manila');

echo "[" . date('Y-m-d H:i:s') . "] Starting email queue processor...\n";

try {
    // Fetch pending emails (limit to 20 per run to avoid long execution)
    $sql = "SELECT id, recipient_email, recipient_name, subject, html_body, email_type, booking_id 
            FROM email_queue 
            WHERE status = 'pending' 
            ORDER BY created_at ASC 
            LIMIT 20";
    
    $result = $conn->query($sql);
    
    if (!$result) {
        throw new Exception("Query failed: " . $conn->error);
    }
    
    $pendingCount = $result->num_rows;
    echo "Found $pendingCount pending emails to process\n";
    
    if ($pendingCount === 0) {
        echo "No pending emails. Exiting.\n";
        exit;
    }
    
    $processed = 0;
    $failed = 0;
    
    while ($row = $result->fetch_assoc()) {
        $emailId = $row['id'];
        $recipientEmail = $row['recipient_email'];
        $recipientName = $row['recipient_name'];
        $subject = $row['subject'];
        $htmlBody = $row['html_body'];
        $emailType = $row['email_type'];
        $bookingId = $row['booking_id'];
        
        echo "Processing email ID $emailId to $recipientEmail...\n";
        
        // Send email using existing function
        $sent = sendBookingEmail($recipientEmail, $recipientName, $subject, $htmlBody, $emailType, $bookingId);
        
        if ($sent) {
            // Update status to sent
            $updateSql = "UPDATE email_queue 
                         SET status = 'sent', sent_at = NOW() 
                         WHERE id = $emailId";
            $conn->query($updateSql);
            echo "  ✓ Email sent successfully\n";
            $processed++;
        } else {
            // Increment attempts
            $updateSql = "UPDATE email_queue 
                         SET attempts = attempts + 1, 
                             error_message = 'Failed to send email',
                             status = CASE WHEN attempts >= 3 THEN 'failed' ELSE 'pending' END
                         WHERE id = $emailId";
            $conn->query($updateSql);
            echo "  ✗ Failed to send email (attempt " . ($row['attempts'] + 1) . ")\n";
            $failed++;
        }
    }
    
    echo "\nSummary:\n";
    echo "  Processed: $processed\n";
    echo "  Failed: $failed\n";
    echo "  Total: $pendingCount\n";
    echo "[" . date('Y-m-d H:i:s') . "] Email queue processor completed.\n";
    
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
    error_log("Email queue processor error: " . $e->getMessage());
    exit(1);
}
?>
