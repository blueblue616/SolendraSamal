<?php
require __DIR__ . '/config.php';
$r = $conn->query("SHOW COLUMNS FROM bookings LIKE 'status'");
echo json_encode($r->fetch_assoc()) . PHP_EOL;
$r2 = $conn->query("SELECT booking_id, status, LENGTH(status) AS len FROM bookings ORDER BY created_at DESC LIMIT 15");
while ($row = $r2->fetch_assoc()) {
    echo json_encode($row) . PHP_EOL;
}
