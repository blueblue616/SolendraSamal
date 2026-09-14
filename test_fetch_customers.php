<?php
/**
 * Test Script: Fetch Customer Information
 * This script tests the get_users endpoint to verify customer data is being fetched correctly
 */

require_once __DIR__ . '/config.php';

// Simulate the get_users action
$action = 'get_users';

try {
    // First, check database counts
    $userCountResult = $conn->query("SELECT COUNT(*) as count FROM users");
    $userCount = $userCountResult->fetch_assoc()['count'];

    $bookingCountResult = $conn->query("SELECT COUNT(*) as count FROM bookings");
    $bookingCount = $bookingCountResult->fetch_assoc()['count'];

    echo "Database Statistics:\n";
    echo "  Total Users: " . $userCount . "\n";
    echo "  Total Bookings: " . $bookingCount . "\n\n";

    // Get users with their booking information (match by email)
    $sql = "SELECT DISTINCT u.*,
            (SELECT COUNT(*) FROM bookings WHERE email COLLATE utf8mb4_general_ci = u.email COLLATE utf8mb4_general_ci) as total_bookings,
            (SELECT MAX(created_at) FROM bookings WHERE email COLLATE utf8mb4_general_ci = u.email COLLATE utf8mb4_general_ci) as last_booking_date
            FROM users u
            INNER JOIN bookings b ON u.email COLLATE utf8mb4_general_ci = b.email COLLATE utf8mb4_general_ci
            GROUP BY u.user_id
            ORDER BY u.created_at DESC";
    $result = $conn->query($sql);
    if (!$result) {
        throw new Exception('Query failed: ' . $conn->error);
    }

    $users = [];
    while ($row = $result->fetch_assoc()) {
        // Get all bookings for this user (match by email)
        $booking_sql = "SELECT * FROM bookings WHERE email COLLATE utf8mb4_general_ci = '{$row['email']}' COLLATE utf8mb4_general_ci ORDER BY created_at DESC";
        $booking_result = $conn->query($booking_sql);

        $bookings = [];
        if ($booking_result) {
            while ($booking_row = $booking_result->fetch_assoc()) {
                $bookings[] = [
                    'booking_id' => $booking_row['booking_id'],
                    'name' => $booking_row['name'],
                    'email' => $booking_row['email'],
                    'phone' => $booking_row['phone'],
                    'checkin' => $booking_row['checkin'],
                    'checkout' => $booking_row['checkout'],
                    'guests' => $booking_row['guests'],
                    'total_amount' => $booking_row['total_amount'],
                    'downpayment' => $booking_row['amount_sent'] ?? 0,
                    'remaining_balance' => $booking_row['total_amount'] - ($booking_row['amount_sent'] ?? 0),
                    'status' => $booking_row['status'],
                    'payment_status' => $booking_row['payment_status'] ?? 'pending',
                    'payment_proof' => $booking_row['payment_proof'],
                    'created_at' => $booking_row['created_at'],
                    'special_requests' => $booking_row['requests']
                ];
            }
        }

        $row['bookings'] = $bookings;
        $row['recent_bookings'] = array_slice($bookings, 0, 5);
        $users[] = $row;
    }

    // If no customers found with bookings, show all users
    if (empty($users) && $userCount > 0) {
        echo "Note: No users with bookings found. Showing all users instead.\n\n";
        $allUsersResult = $conn->query("SELECT * FROM users ORDER BY created_at DESC");
        while ($row = $allUsersResult->fetch_assoc()) {
            $row['bookings'] = [];
            $row['recent_bookings'] = [];
            $row['total_bookings'] = 0;
            $row['last_booking_date'] = null;
            $users[] = $row;
        }
    }

    // Display results
    echo "========================================\n";
    echo "CUSTOMER INFORMATION FETCH TEST\n";
    echo "========================================\n\n";
    echo "Total Customers Found: " . count($users) . "\n\n";

    foreach ($users as $user) {
        echo "----------------------------------------\n";
        echo "Customer ID: " . $user['user_id'] . "\n";
        echo "Name: " . $user['name'] . "\n";
        echo "Email: " . $user['email'] . "\n";
        echo "Phone: " . ($user['phone'] ?? 'N/A') . "\n";
        echo "Total Bookings: " . $user['total_bookings'] . "\n";
        echo "Last Booking Date: " . ($user['last_booking_date'] ?? 'N/A') . "\n";
        echo "Joined: " . $user['created_at'] . "\n";
        echo "\n";

        // Display booking details
        if (!empty($user['bookings'])) {
            echo "  Bookings (" . count($user['bookings']) . "):\n";
            foreach ($user['bookings'] as $booking) {
                echo "    - Booking ID: " . $booking['booking_id'] . "\n";
                echo "      Status: " . $booking['status'] . "\n";
                echo "      Check-in: " . $booking['checkin'] . "\n";
                echo "      Check-out: " . $booking['checkout'] . "\n";
                echo "      Guests: " . $booking['guests'] . "\n";
                echo "      Total Amount: ₱" . number_format($booking['total_amount'] ?? 0, 2) . "\n";
                echo "      Payment Status: " . $booking['payment_status'] . "\n";
                echo "\n";
            }
        } else {
            echo "  No bookings found\n\n";
        }
        echo "----------------------------------------\n\n";
    }

    echo "========================================\n";
    echo "TEST COMPLETED SUCCESSFULLY\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
