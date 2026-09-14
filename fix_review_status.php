<?php
require_once 'config.php';

// Fix the booking that has sent_at but status is still Scheduled
$sql = "UPDATE bookings 
        SET review_email_status = 'Sent' 
        WHERE review_email_sent_at IS NOT NULL 
        AND review_email_status = 'Scheduled'";

if ($conn->query($sql)) {
    echo "Fixed review email status for bookings that were sent but not marked as Sent.\n";
    echo "Affected rows: " . $conn->affected_rows . "\n";
} else {
    echo "ERROR: " . $conn->error . "\n";
}

// Check the status again
$sql = "SELECT booking_id, name, email, status, checkin, checkout, 
        review_email_scheduled_at, review_email_sent_at, review_email_status 
        FROM bookings 
        WHERE review_email_status = 'Scheduled' 
        OR review_email_status = 'Sent'
        ORDER BY booking_id DESC";

$result = $conn->query($sql);

echo "\nCurrent review email statuses:\n";
echo str_repeat("-", 150) . "\n";
printf("%-15s %-25s %-30s %-25s %-25s %-25s %-25s\n", 
    "Booking ID", "Name", "Email", "Status", "Scheduled At", "Sent At", "Review Status");
echo str_repeat("-", 150) . "\n";

while ($row = $result->fetch_assoc()) {
    printf("%-15s %-25s %-30s %-25s %-25s %-25s %-25s\n", 
        $row['booking_id'],
        substr($row['name'], 0, 23),
        substr($row['email'], 0, 28),
        $row['status'],
        $row['review_email_scheduled_at'] ?? 'NULL',
        $row['review_email_sent_at'] ?? 'NULL',
        $row['review_email_status'] ?? 'NULL'
    );
}
