<?php
/**
 * Test Script: Verify Booking Date Accuracy
 * Tests that check-in/out dates and email information are accurate in booking data
 */

require_once __DIR__ . '/config.php';

echo "========================================\n";
echo "BOOKING DATE ACCURACY TEST\n";
echo "========================================\n\n";

// Test 1: Fetch booking data with dates
echo "1. BOOKING DATE DATA TEST\n";
echo "---------------------------\n";
try {
    $sql = "SELECT booking_id, name, email, phone, checkin, checkout, guests, status, created_at FROM bookings ORDER BY created_at DESC LIMIT 5";
    $result = $conn->query($sql);

    if ($result && $result->num_rows > 0) {
        echo "  Total Bookings: " . $result->num_rows . "\n\n";
        while ($row = $result->fetch_assoc()) {
            echo "  Booking ID: " . $row['booking_id'] . "\n";
            echo "    Name: " . $row['name'] . "\n";
            echo "    Email: " . $row['email'] . "\n";
            echo "    Phone: " . $row['phone'] . "\n";
            echo "    Check-in (DB): " . $row['checkin'] . "\n";
            echo "    Check-out (DB): " . $row['checkout'] . "\n";
            echo "    Guests: " . $row['guests'] . "\n";
            echo "    Status: " . $row['status'] . "\n";
            echo "    Created At: " . $row['created_at'] . "\n";

            // Validate date format
            $checkinValid = DateTime::createFromFormat('Y-m-d', $row['checkin']);
            $checkoutValid = DateTime::createFromFormat('Y-m-d', $row['checkout']);

            echo "    Check-in Valid: " . ($checkinValid ? 'Yes' : 'No') . "\n";
            echo "    Check-out Valid: " . ($checkoutValid ? 'Yes' : 'No') . "\n";

            // Check if dates are logical (check-out after check-in)
            if ($checkinValid && $checkoutValid) {
                $checkinTimestamp = $checkinValid->getTimestamp();
                $checkoutTimestamp = $checkoutValid->getTimestamp();
                $isLogical = $checkoutTimestamp > $checkinTimestamp;
                echo "    Date Logic: " . ($isLogical ? 'Valid (check-out after check-in)' : 'Invalid (check-out before check-in)') . "\n";
            }

            echo "\n";
        }
        echo "  ✓ Booking date data fetched successfully\n\n";
    } else {
        echo "  No bookings found\n\n";
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 2: Check for NULL or invalid dates
echo "2. DATE VALIDITY TEST\n";
echo "----------------------\n";
try {
    $sql = "SELECT booking_id, checkin, checkout FROM bookings";
    $result = $conn->query($sql);

    $invalidDates = 0;
    $nullDates = 0;
    $totalBookings = 0;

    if ($result) {
        $totalBookings = $result->num_rows;
        while ($row = $result->fetch_assoc()) {
            if (is_null($row['checkin']) || is_null($row['checkout'])) {
                $nullDates++;
            }

            $checkinValid = DateTime::createFromFormat('Y-m-d', $row['checkin']);
            $checkoutValid = DateTime::createFromFormat('Y-m-d', $row['checkout']);

            if (!$checkinValid || !$checkoutValid) {
                $invalidDates++;
            }
        }
    }

    echo "  Total Bookings: $totalBookings\n";
    echo "  NULL Dates: $nullDates\n";
    echo "  Invalid Date Format: $invalidDates\n";
    echo "  Valid Dates: " . ($totalBookings - $nullDates - $invalidDates) . "\n";

    if ($nullDates === 0 && $invalidDates === 0) {
        echo "  ✓ All dates are valid\n\n";
    } else {
        echo "  ⚠ Some dates need attention\n\n";
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

// Test 3: Email validation
echo "3. EMAIL VALIDITY TEST\n";
echo "----------------------\n";
try {
    $sql = "SELECT booking_id, email FROM bookings";
    $result = $conn->query($sql);

    $invalidEmails = 0;
    $nullEmails = 0;
    $totalBookings = 0;

    if ($result) {
        $totalBookings = $result->num_rows;
        while ($row = $result->fetch_assoc()) {
            if (is_null($row['email']) || $row['email'] === '') {
                $nullEmails++;
            } elseif (!filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                $invalidEmails++;
            }
        }
    }

    echo "  Total Bookings: $totalBookings\n";
    echo "  NULL Emails: $nullEmails\n";
    echo "  Invalid Email Format: $invalidEmails\n";
    echo "  Valid Emails: " . ($totalBookings - $nullEmails - $invalidEmails) . "\n";

    if ($nullEmails === 0 && $invalidEmails === 0) {
        echo "  ✓ All emails are valid\n\n";
    } else {
        echo "  ⚠ Some emails need attention\n\n";
    }
} catch (Exception $e) {
    echo "  ✗ Error: " . $e->getMessage() . "\n\n";
}

echo "========================================\n";
echo "DATE ACCURACY TEST COMPLETED\n";
echo "========================================\n";
