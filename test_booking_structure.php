<?php
/**
 * Test Script: Check Booking Table Structure and Data
 */

require_once __DIR__ . '/config.php';

echo "========================================\n";
echo "BOOKING TABLE STRUCTURE TEST\n";
echo "========================================\n\n";

// Check bookings table structure
echo "Bookings Table Structure:\n";
$columnsResult = $conn->query("DESCRIBE bookings");
while ($column = $columnsResult->fetch_assoc()) {
    echo "  - " . $column['Field'] . " (" . $column['Type'] . ", " . $column['Collation'] . ")\n";
}

echo "\n";

// Sample booking data
echo "Sample Booking Data (first 3):\n";
$bookingsResult = $conn->query("SELECT booking_id, user_id, name, email, phone, status FROM bookings LIMIT 3");
while ($booking = $bookingsResult->fetch_assoc()) {
    echo "  Booking ID: " . $booking['booking_id'] . "\n";
    echo "    User ID: " . ($booking['user_id'] ?? 'NULL') . "\n";
    echo "    Name: " . $booking['name'] . "\n";
    echo "    Email: " . $booking['email'] . "\n";
    echo "    Phone: " . $booking['phone'] . "\n";
    echo "    Status: " . $booking['status'] . "\n";
    echo "\n";
}

// Check users table structure
echo "Users Table Structure:\n";
$usersColumnsResult = $conn->query("DESCRIBE users");
while ($column = $usersColumnsResult->fetch_assoc()) {
    echo "  - " . $column['Field'] . " (" . $column['Type'] . ", " . $column['Collation'] . ")\n";
}

echo "\n";

// Sample user data
echo "Sample User Data (first 3):\n";
$usersResult = $conn->query("SELECT user_id, name, email, phone FROM users LIMIT 3");
while ($user = $usersResult->fetch_assoc()) {
    echo "  User ID: " . $user['user_id'] . "\n";
    echo "    Name: " . $user['name'] . "\n";
    echo "    Email: " . $user['email'] . "\n";
    echo "    Phone: " . $user['phone'] . "\n";
    echo "\n";
}

echo "========================================\n";
echo "TEST COMPLETED\n";
echo "========================================\n";
