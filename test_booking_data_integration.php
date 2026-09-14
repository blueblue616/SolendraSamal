<?php
/**
 * Test Script: Verify Booking Data Integration
 * Tests that booking data is properly fetched for Dashboard, Bookings, and Customer sections
 */

require_once __DIR__ . '/config.php';

echo "========================================\n";
echo "BOOKING DATA INTEGRATION TEST\n";
echo "========================================\n\n";

// Test 1: Dashboard Stats
echo "1. DASHBOARD STATS TEST\n";
echo "------------------------\n";
try {
    $result = $conn->query("SELECT COUNT(*) as count FROM bookings");
    $booking_count = $result ? $result->fetch_assoc()['count'] : 0;

    $result = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending_booking_confirmation'");
    $pending_bookings = $result ? $result->fetch_assoc()['count'] : 0;

    $result = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'booking_confirmed'");
    $confirmed_bookings = $result ? $result->fetch_assoc()['count'] : 0;

    // Count unique customers (by email)
    $result = $conn->query("SELECT COUNT(DISTINCT email) as count FROM bookings");
    $customer_count = $result ? $result->fetch_assoc()['count'] : 0;

    echo "  Total Customers: $customer_count\n";
    echo "  Total Bookings: $booking_count\n";
    echo "  Pending Bookings: $pending_bookings\n";
    echo "  Confirmed Bookings: $confirmed_bookings\n";
    echo "  ✓ Dashboard stats fetched successfully\n\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 2: Bookings List
echo "2. BOOKINGS LIST TEST\n";
echo "----------------------\n";
try {
    $sql = "SELECT b.* FROM bookings b ORDER BY b.created_at DESC LIMIT 5";
    $result = $conn->query($sql);

    if (!$result) {
        echo "  ✗ Query failed: " . $conn->error . "\n\n";
    } elseif ($result->num_rows > 0) {
        echo "  Total Bookings Found: " . $result->num_rows . "\n\n";
        while ($row = $result->fetch_assoc()) {
            echo "  Booking ID: " . $row['booking_id'] . "\n";
            echo "    Name: " . $row['name'] . "\n";
            echo "    Email: " . $row['email'] . "\n";
            echo "    Status: " . $row['status'] . "\n";
            echo "    Check-in: " . $row['checkin'] . "\n";
            echo "    Check-out: " . $row['checkout'] . "\n";
            echo "    Total Amount: ₱" . number_format($row['total_amount'] ?? 0, 2) . "\n";
            echo "\n";
        }
        echo "  ✓ Bookings list fetched successfully\n\n";
    } else {
        echo "  No bookings found\n\n";
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Customer Bookings
echo "3. CUSTOMER BOOKINGS TEST\n";
echo "-------------------------\n";
try {
    $sql = "SELECT DISTINCT email,
            (SELECT name FROM bookings WHERE email = b.email ORDER BY created_at DESC LIMIT 1) as name,
            (SELECT phone FROM bookings WHERE email = b.email ORDER BY created_at DESC LIMIT 1) as phone,
            (SELECT COUNT(*) FROM bookings WHERE email = b.email) as total_bookings,
            (SELECT MAX(created_at) FROM bookings WHERE email = b.email) as last_booking_date,
            (SELECT MIN(created_at) FROM bookings WHERE email = b.email) as created_at
            FROM bookings b
            ORDER BY last_booking_date DESC LIMIT 3";
    $result = $conn->query($sql);

    if (!$result) {
        echo "  ✗ Query failed: " . $conn->error . "\n\n";
    } elseif ($result->num_rows > 0) {
        echo "  Customers with bookings: " . $result->num_rows . "\n\n";
        while ($row = $result->fetch_assoc()) {
            $customer_id = 'C-' . strtoupper(substr(md5($row['email']), 0, 6));
            echo "  Customer ID: " . $customer_id . "\n";
            echo "    Name: " . $row['name'] . "\n";
            echo "    Email: " . $row['email'] . "\n";
            echo "    Phone: " . $row['phone'] . "\n";
            echo "    Total Bookings: " . $row['total_bookings'] . "\n";
            echo "    Last Booking: " . ($row['last_booking_date'] ?? 'N/A') . "\n";

            // Get bookings for this customer
            $booking_sql = "SELECT * FROM bookings WHERE email = '{$row['email']}' ORDER BY created_at DESC";
            $booking_result = $conn->query($booking_sql);
            if ($booking_result && $booking_result->num_rows > 0) {
                echo "    Bookings:\n";
                while ($booking = $booking_result->fetch_assoc()) {
                    echo "      - " . $booking['booking_id'] . " (" . $booking['status'] . ")\n";
                }
            }
            echo "\n";
        }
        echo "  ✓ Customer bookings fetched successfully\n\n";
    } else {
        echo "  No customers with bookings found\n\n";
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 4: Dashboard Analytics
echo "4. DASHBOARD ANALYTICS TEST\n";
echo "----------------------------\n";
try {
    // Booking trends
    $booking_trends = [];
    for ($i = 5; $i >= 0; $i--) {
        $month = date('Y-m', strtotime("-$i months"));
        $month_start = $month . '-01';
        $month_end = date('Y-m-t', strtotime($month_start));

        $result = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE created_at BETWEEN '$month_start' AND '$month_end 23:59:59'");
        $count = $result ? $result->fetch_assoc()['count'] : 0;

        $booking_trends[] = [
            'month' => date('M Y', strtotime($month_start)),
            'count' => (int)$count
        ];
    }

    echo "  Booking Trends (last 6 months):\n";
    foreach ($booking_trends as $trend) {
        echo "    " . $trend['month'] . ": " . $trend['count'] . " bookings\n";
    }

    // Recent activities
    $recent_bookings = $conn->query("SELECT * FROM bookings ORDER BY created_at DESC LIMIT 3");
    echo "\n  Recent Activities:\n";
    if ($recent_bookings && $recent_bookings->num_rows > 0) {
        while ($row = $recent_bookings->fetch_assoc()) {
            $status = str_replace('_', ' ', $row['status']);
            $status = ucfirst($status);
            echo "    - Booking #{$row['booking_id']} by {$row['name']} - $status\n";
        }
    } else {
        echo "    No recent activities found\n";
    }

    echo "\n  ✓ Dashboard analytics fetched successfully\n\n";
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

echo "========================================\n";
echo "INTEGRATION TEST COMPLETED\n";
echo "========================================\n";
