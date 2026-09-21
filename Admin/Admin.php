<?php
// Disable error display for API responses
ini_set('display_errors', 0);
error_reporting(E_ALL);

session_start();

// Check if admin is logged in
if (!isset($_SESSION['admin_logged_in']) || $_SESSION['admin_logged_in'] !== true) {
    // Return JSON error for AJAX requests
    if ($_SERVER['REQUEST_METHOD'] === 'POST' || (isset($_GET['action']) && $_GET['action'])) {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session expired. Please log in again.']);
        exit;
    }
    header('Location: AdminLogin.php');
    exit;
}

require_once '../config.php';

function ensureAdminNotificationsTable($conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS admin_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        type VARCHAR(50) NOT NULL,
        subject VARCHAR(255) NOT NULL,
        message TEXT NOT NULL,
        booking_id VARCHAR(50) NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_booking_type (booking_id, type),
        INDEX idx_type_read (type, is_read)
    )");
}

function getUnreadNewBookingNotificationCount($conn) {
    ensureAdminNotificationsTable($conn);

    $count = 0;
    $new_booking_result = $conn->query("SELECT COUNT(*) as count FROM admin_notifications WHERE type = 'new_booking' AND is_read = 0");
    if ($new_booking_result) {
        $count = intval($new_booking_result->fetch_assoc()['count']);
    }

    $legacy_pending_result = $conn->query("SELECT COUNT(*) as count FROM bookings
        WHERE status = 'pending_booking_confirmation'
        AND booking_id NOT IN (SELECT booking_id FROM admin_notifications WHERE type = 'new_booking')");
    if ($legacy_pending_result) {
        $count += intval($legacy_pending_result->fetch_assoc()['count']);
    }

    return $count;
}

// Handle POST requests
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $action = $_POST['action'] ?? '';
    
    error_log("DEBUG: POST request received, action: " . $action);
    
    // Check database connection
    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    
    if ($action === 'delete_user') {
        try {
            $user_id = $conn->real_escape_string($_POST['user_id']);

            // Get the email associated with this customer ID
            // Customer IDs are generated as C-<hash> from email
            // We need to find the email from the bookings table
            $email_result = $conn->query("SELECT DISTINCT email FROM bookings LIMIT 1");

            // Since we can't easily map customer ID back to email without additional data,
            // we'll delete by email if it's passed, otherwise delete all bookings with a warning
            if (isset($_POST['email'])) {
                $email = $conn->real_escape_string($_POST['email']);
                $sql = "DELETE FROM bookings WHERE email = '$email'";
                if ($conn->query($sql)) {
                    echo json_encode(['success' => true, 'message' => 'Customer deleted successfully']);
                } else {
                    echo json_encode(['success' => false, 'message' => 'Error deleting customer: ' . $conn->error]);
                }
            } else {
                echo json_encode(['success' => false, 'message' => 'Email required to delete customer']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'update_booking_status') {
        try {
            $booking_id = $conn->real_escape_string($_POST['booking_id']);
            $status = $conn->real_escape_string($_POST['status']);

            $valid_statuses = ['pending_booking_confirmation', 'booking_confirmed', 'payment_pending', 'payment_under_review', 'payment_confirmed', 'arrival_notice_sent', 'completed', 'cancelled'];
            if (!in_array($status, $valid_statuses)) {
                throw new Exception('Invalid status');
            }

            // Get current booking details before updating
            $check_sql = "SELECT * FROM bookings WHERE booking_id = '$booking_id'";
            $check_result = $conn->query($check_sql);
            if (!$check_result) {
                throw new Exception('Query failed: ' . $conn->error);
            }
            $booking_data = $check_result->fetch_assoc();

            if (!$booking_data) {
                throw new Exception('Booking not found');
            }

            $old_status = $booking_data['status'];

            if ($status === 'booking_confirmed' && $old_status !== 'pending_booking_confirmation') {
                throw new Exception('This booking has already been confirmed or cannot be confirmed');
            }

            if ($status === $old_status) {
                echo json_encode(['success' => true, 'message' => 'Booking status is already up to date']);
                exit;
            }

            $sql = "UPDATE bookings SET status = '$status' WHERE booking_id = '$booking_id' AND status = '$old_status'";
            if ($conn->query($sql)) {
                if ($conn->affected_rows === 0) {
                    throw new Exception('Booking status changed before update could complete. Please refresh and try again.');
                }

                ensureAdminNotificationsTable($conn);
                if ($status !== 'pending_booking_confirmation') {
                    $conn->query("UPDATE admin_notifications SET is_read = 1
                                  WHERE booking_id = '$booking_id' AND type = 'new_booking'");
                }

                // Send emails if PHPMailer is available and status changed
                if (file_exists(__DIR__ . '/../email_helper.php') && $old_status !== $status) {
                    require_once __DIR__ . '/../email_helper.php';

                    // Send approval email when status changes to booking_confirmed
                    if ($status === 'booking_confirmed' && $old_status === 'pending_booking_confirmation') {
                        $emailSent = sendBookingApprovalEmail(
                            $booking_data['name'],
                            $booking_data['email'],
                            $booking_id,
                            $booking_data['checkin'],
                            $booking_data['checkout'],
                            $booking_data['guests']
                        );
                        if (!$emailSent) {
                            error_log("Failed to send booking approval email to customer: " . $booking_data['email']);
                        }

                        if (!scheduleReviewEmailForBooking($conn, $booking_id, $booking_data['checkout'])) {
                            error_log("Failed to schedule review email for booking $booking_id: " . $conn->error);
                        }

                        if (!scheduleCheckinReminderForBooking($conn, $booking_id, $booking_data['checkin'])) {
                            error_log("Failed to schedule check-in reminder email for booking $booking_id: " . $conn->error);
                        }
                    }

                    // Send rejection email when status changes to cancelled
                    if ($status === 'cancelled' && $old_status === 'pending_booking_confirmation') {
                        $emailSent = sendBookingRejectionEmail(
                            $booking_data['name'],
                            $booking_data['email'],
                            $booking_id,
                            $booking_data['checkin'],
                            $booking_data['checkout']
                        );
                        if (!$emailSent) {
                            error_log("Failed to send booking rejection email to customer: " . $booking_data['email']);
                        }
                    }
                }

                echo json_encode(['success' => true, 'message' => 'Booking status updated successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error updating booking status: ' . $conn->error]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete_booking') {
        try {
            $booking_id = $conn->real_escape_string($_POST['booking_id']);
            
            $sql = "DELETE FROM bookings WHERE booking_id = '$booking_id'";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Booking deleted successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error deleting booking: ' . $conn->error]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    

    
    if ($action === 'save_payment_settings') {
        try {
            $settings = [
                'bank_name' => $conn->real_escape_string($_POST['bank_name'] ?? ''),
                'bank_account_name' => $conn->real_escape_string($_POST['bank_account_name'] ?? ''),
                'bank_account_number' => $conn->real_escape_string($_POST['bank_account_number'] ?? ''),
                'gcash_name' => $conn->real_escape_string($_POST['gcash_name'] ?? ''),
                'gcash_number' => $conn->real_escape_string($_POST['gcash_number'] ?? ''),
                'payment_instructions' => $conn->real_escape_string($_POST['payment_instructions'] ?? ''),

            ];

            foreach ($settings as $key => $value) {
                $sql = "INSERT INTO payment_settings (setting_key, setting_value) VALUES ('$key', '$value')
                        ON DUPLICATE KEY UPDATE setting_value = '$value'";
                if (!$conn->query($sql)) {
                    throw new Exception('Error saving setting ' . $key . ': ' . $conn->error);
                }
            }

            echo json_encode(['success' => true, 'message' => 'Payment settings saved successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'upload_qr_code') {
        try {
            // Check if qr_code column exists in payment_settings
            $check_column = $conn->query("SHOW COLUMNS FROM payment_settings LIKE 'qr_code'");
            if ($check_column->num_rows == 0) {
                $conn->query("ALTER TABLE payment_settings ADD COLUMN qr_code TEXT AFTER setting_value");
            }
            
            if (!isset($_FILES['qr_code']) || $_FILES['qr_code']['error'] !== UPLOAD_ERR_OK) {
                throw new Exception('Please upload a valid QR code image');
            }
            
            $file = $_FILES['qr_code'];
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            if (!in_array($file['type'], $allowed_types)) {
                throw new Exception('Only JPG, PNG, and GIF images are allowed');
            }
            
            $upload_dir = '../uploads/qr_codes/';
            if (!is_dir($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file_extension = pathinfo($file['name'], PATHINFO_EXTENSION);
            $file_name = 'payment_qr_' . time() . '.' . $file_extension;
            $file_path = $upload_dir . $file_name;

            // For Page.php to access, we need the relative path from Pages folder
            $relative_path_for_page = '../uploads/qr_codes/' . $file_name;

            if (!move_uploaded_file($file['tmp_name'], $file_path)) {
                throw new Exception('Failed to upload QR code');
            }
            
            // Save QR code path to payment_settings (use relative path for Page.php access)
            $sql = "INSERT INTO payment_settings (setting_key, setting_value) VALUES ('qr_code', '$relative_path_for_page')
                    ON DUPLICATE KEY UPDATE setting_value = '$relative_path_for_page'";

            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'QR code uploaded successfully', 'qr_code_path' => $relative_path_for_page]);
            } else {
                throw new Exception('Error saving QR code: ' . $conn->error);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_qr_code') {
        try {
            $sql = "SELECT setting_value FROM payment_settings WHERE setting_key = 'qr_code'";
            $result = $conn->query($sql);

            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                // The path is stored with ../ prefix, Admin.php needs ../ removed
                $qr_path = $row['setting_value'];
                if (strpos($qr_path, '../') === 0) {
                    $qr_path = substr($qr_path, 3); // Remove ../
                }
                echo json_encode(['success' => true, 'qr_code_path' => $qr_path]);
            } else {
                echo json_encode(['success' => true, 'qr_code_path' => null]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'delete_qr_code') {
        try {
            // Get current QR code path
            $sql = "SELECT setting_value FROM payment_settings WHERE setting_key = 'qr_code'";
            $result = $conn->query($sql);
            
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                $qr_path = $row['setting_value'];
                
                // Delete file if exists
                if ($qr_path && file_exists($qr_path)) {
                    unlink($qr_path);
                }
                
                // Remove from database
                $delete_sql = "DELETE FROM payment_settings WHERE setting_key = 'qr_code'";
                $conn->query($delete_sql);
            }
            
            echo json_encode(['success' => true, 'message' => 'QR code deleted successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'rebook_booking') {
        try {
            $booking_id = $conn->real_escape_string($_POST['booking_id']);
            $new_checkin = $conn->real_escape_string($_POST['new_checkin']);
            $new_checkout = $conn->real_escape_string($_POST['new_checkout']);

            // Validate dates
            if (empty($new_checkin) || empty($new_checkout)) {
                throw new Exception('Check-in and check-out dates are required');
            }

            if (strtotime($new_checkin) >= strtotime($new_checkout)) {
                throw new Exception('Check-out date must be after check-in date');
            }

            // Check if booking exists
            $check_sql = "SELECT * FROM bookings WHERE booking_id = '$booking_id'";
            $check_result = $conn->query($check_sql);

            if (!$check_result) {
                throw new Exception('Query failed: ' . $conn->error);
            }

            $booking_data = $check_result->fetch_assoc();

            if (!$booking_data) {
                throw new Exception('Booking not found');
            }

            // Check if new dates are available
            $availability_sql = "SELECT COUNT(*) as count FROM bookings 
                                WHERE booking_id != '$booking_id' 
                                AND status NOT IN ('cancelled', 'rejected')
                                AND (
                                    (checkin <= '$new_checkin' AND checkout > '$new_checkin') OR
                                    (checkin < '$new_checkout' AND checkout >= '$new_checkout') OR
                                    (checkin >= '$new_checkin' AND checkout <= '$new_checkout')
                                )";
            $availability_result = $conn->query($availability_sql);
            $availability_data = $availability_result->fetch_assoc();

            if ($availability_data['count'] > 0) {
                throw new Exception('Selected dates are not available. Please choose different dates.');
            }

            // Update booking dates
            $update_sql = "UPDATE bookings 
                          SET checkin = '$new_checkin', 
                              checkout = '$new_checkout',
                              updated_at = NOW()
                          WHERE booking_id = '$booking_id'";

            if (!$conn->query($update_sql)) {
                throw new Exception('Failed to update booking: ' . $conn->error);
            }

            // Send rebook email notification
            require_once __DIR__ . '/../email_helper.php';
            $success = sendBookingRebookedEmail(
                $booking_data,
                $booking_data['checkin'],
                $booking_data['checkout'],
                $new_checkin,
                $new_checkout
            );

            if ($success) {
                echo json_encode(['success' => true, 'message' => 'Booking rebooked successfully. Email notification sent.']);
            } else {
                echo json_encode(['success' => true, 'message' => 'Booking rebooked successfully. Email notification failed.']);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'resend_review_email') {
        try {
            $booking_id = $conn->real_escape_string($_POST['booking_id']);
            
            // Get booking details
            $check_sql = "SELECT * FROM bookings WHERE booking_id = '$booking_id'";
            $check_result = $conn->query($check_sql);
            
            if (!$check_result) {
                throw new Exception('Query failed: ' . $conn->error);
            }
            
            $booking_data = $check_result->fetch_assoc();
            
            if (!$booking_data) {
                throw new Exception('Booking not found');
            }
            
            require_once __DIR__ . '/../email_helper.php';

            if (!isBookingEligibleForReviewEmail($booking_data['status'])) {
                throw new Exception('Review email can only be sent for confirmed or completed bookings');
            }
            
            $bookingData = [
                'booking_id' => $booking_data['booking_id'],
                'customer_name' => $booking_data['name'],
                'customer_email' => $booking_data['email'],
                'status' => $booking_data['status'],
                'checkin' => $booking_data['checkin'],
                'checkout' => $booking_data['checkout']
            ];
            
            $emailSent = sendGoogleReviewEmail($bookingData);
            
            if ($emailSent) {
                // Update booking as sent
                $updateSql = "UPDATE bookings SET 
                    review_email_sent_at = NOW(),
                    review_email_status = 'Sent'
                    WHERE booking_id = '$booking_id'";
                
                if ($conn->query($updateSql)) {
                    echo json_encode(['success' => true, 'message' => 'Review email sent successfully']);
                } else {
                    throw new Exception('Failed to update sent status: ' . $conn->error);
                }
            } else {
                throw new Exception('Failed to send review email');
            }
            
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_calendar_data') {
        try {
            $start_date = $conn->real_escape_string($_GET['start_date']);
            $end_date = $conn->real_escape_string($_GET['end_date']);
            
            // Check if blocked_dates table exists
            $check_table = $conn->query("SHOW TABLES LIKE 'blocked_dates'");
            if ($check_table->num_rows == 0) {
                $conn->query("CREATE TABLE blocked_dates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    date DATE NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
            }
            
            // Check if maintenance_dates table exists
            $check_table = $conn->query("SHOW TABLES LIKE 'maintenance_dates'");
            if ($check_table->num_rows == 0) {
                $conn->query("CREATE TABLE maintenance_dates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    date DATE NOT NULL UNIQUE,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
            }
            
            // Get bookings in date range
            $sql = "SELECT * FROM bookings WHERE 
                    (checkin <= '$end_date' AND checkout >= '$start_date')
                    AND status NOT IN ('cancelled')
                    ORDER BY checkin ASC";
            $result = $conn->query($sql);
            
            $bookings = [];
            while ($row = $result->fetch_assoc()) {
                $bookings[] = $row;
            }
            
            // Get blocked dates
            $sql = "SELECT date FROM blocked_dates WHERE date BETWEEN '$start_date' AND '$end_date' ORDER BY date ASC";
            $result = $conn->query($sql);
            
            $blocked_dates = [];
            while ($row = $result->fetch_assoc()) {
                $blocked_dates[] = $row['date'];
            }
            
            // Get maintenance dates
            $sql = "SELECT date FROM maintenance_dates WHERE date BETWEEN '$start_date' AND '$end_date' ORDER BY date ASC";
            $result = $conn->query($sql);
            
            $maintenance_dates = [];
            while ($row = $result->fetch_assoc()) {
                $maintenance_dates[] = $row['date'];
            }
            
            echo json_encode([
                'success' => true,
                'bookings' => $bookings,
                'blocked_dates' => $blocked_dates,
                'maintenance_dates' => $maintenance_dates
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'block_dates') {
        try {
            if (!isset($_POST['dates']) || !is_array($_POST['dates'])) {
                throw new Exception('No dates provided');
            }
            
            foreach ($_POST['dates'] as $date) {
                $date = $conn->real_escape_string($date);
                $sql = "INSERT INTO blocked_dates (date) VALUES ('$date') 
                        ON DUPLICATE KEY UPDATE date = date";
                $conn->query($sql);
            }
            
            echo json_encode(['success' => true, 'message' => 'Dates blocked successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'unblock_dates') {
        try {
            if (!isset($_POST['dates']) || !is_array($_POST['dates'])) {
                throw new Exception('No dates provided');
            }
            
            $dates = array_map(function($date) use ($conn) {
                return "'" . $conn->real_escape_string($date) . "'";
            }, $_POST['dates']);
            
            $sql = "DELETE FROM blocked_dates WHERE date IN (" . implode(',', $dates) . ")";
            $conn->query($sql);
            
            echo json_encode(['success' => true, 'message' => 'Dates unblocked successfully']);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    



    
    if ($action === 'verify_payment') {
        try {
            $booking_id = $conn->real_escape_string($_POST['booking_id']);
            $verified = isset($_POST['verified']) ? $_POST['verified'] === 'true' : false;
            
            if ($verified) {
                $status = 'payment_confirmed';
                $message = 'Payment verified successfully';
            } else {
                $status = 'payment_under_review';
                $message = 'Payment rejected. Please upload a new proof of payment.';
            }
            
            $sql = "UPDATE bookings SET status = '$status', payment_status = '$status' WHERE booking_id = '$booking_id'";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => $message]);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error verifying payment: ' . $conn->error]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'send_arrival_notice') {
        try {
            $booking_id = $conn->real_escape_string($_POST['booking_id']);
            
            // Check if arrival_notice_date column exists
            $check_column = $conn->query("SHOW COLUMNS FROM bookings LIKE 'arrival_notice_date'");
            if ($check_column->num_rows == 0) {
                $conn->query("ALTER TABLE bookings ADD COLUMN arrival_notice_date TIMESTAMP NULL");
            }
            
            $sql = "UPDATE bookings SET status = 'arrival_notice_sent', arrival_notice_sent = TRUE, arrival_notice_date = NOW() WHERE booking_id = '$booking_id'";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Arrival notice sent successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error sending arrival notice: ' . $conn->error]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'reject_booking') {
        try {
            $booking_id = $conn->real_escape_string($_POST['booking_id']);
            $rejection_reason = $conn->real_escape_string($_POST['rejection_reason'] ?? '');
            
            // Check if rejection_reason column exists
            $check_column = $conn->query("SHOW COLUMNS FROM bookings LIKE 'rejection_reason'");
            if ($check_column->num_rows == 0) {
                $conn->query("ALTER TABLE bookings ADD COLUMN rejection_reason TEXT NULL");
            }
            
            $sql = "UPDATE bookings SET status = 'cancelled', rejection_reason = '$rejection_reason' WHERE booking_id = '$booking_id'";
            if ($conn->query($sql)) {
                ensureAdminNotificationsTable($conn);
                $conn->query("UPDATE admin_notifications SET is_read = 1
                              WHERE booking_id = '$booking_id' AND type = 'new_booking'");
                echo json_encode(['success' => true, 'message' => 'Booking rejected successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error rejecting booking: ' . $conn->error]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'add_maintenance_date') {
        try {
            $date = $conn->real_escape_string($_POST['date']);
            $reason = $conn->real_escape_string($_POST['reason'] ?? '');
            
            // Check if maintenance_dates table exists
            $check_table = $conn->query("SHOW TABLES LIKE 'maintenance_dates'");
            if ($check_table->num_rows == 0) {
                $conn->query("CREATE TABLE maintenance_dates (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    date DATE NOT NULL UNIQUE,
                    reason TEXT,
                    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                )");
            }
            
            // Check if date already exists
            $check_date = $conn->query("SELECT id FROM maintenance_dates WHERE date = '$date'");
            if ($check_date->num_rows > 0) {
                echo json_encode(['success' => false, 'message' => 'Maintenance date already exists']);
                exit;
            }
            
            $sql = "INSERT INTO maintenance_dates (date, reason) VALUES ('$date', '$reason')";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Maintenance date added successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error adding maintenance date: ' . $conn->error]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    

    
    if ($action === 'get_maintenance_details') {
        try {
            $date = $conn->real_escape_string($_GET['date']);
            
            $sql = "SELECT reason FROM maintenance_dates WHERE date = '$date'";
            $result = $conn->query($sql);
            
            if ($result && $result->num_rows > 0) {
                $row = $result->fetch_assoc();
                echo json_encode(['success' => true, 'reason' => $row['reason']]);
            } else {
                echo json_encode(['success' => true, 'reason' => null]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'remove_maintenance_date') {
        try {
            $date = $conn->real_escape_string($_POST['date']);
            
            $sql = "DELETE FROM maintenance_dates WHERE date = '$date'";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Maintenance date removed successfully']);
            } else {
                echo json_encode(['success' => false, 'message' => 'Error removing maintenance date: ' . $conn->error]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'mark_admin_notification_read') {
        try {
            $booking_id = $conn->real_escape_string($_POST['booking_id'] ?? '');
            $notification_type = $conn->real_escape_string($_POST['notification_type'] ?? '');

            if (!$booking_id || !$notification_type) {
                throw new Exception('Missing notification details');
            }

            if (in_array($notification_type, ['pending_booking_confirmation', 'new_booking'], true)) {
                $notification_type = 'new_booking';
            }

            ensureAdminNotificationsTable($conn);

            $sql = "UPDATE admin_notifications SET is_read = 1
                    WHERE booking_id = '$booking_id' AND type = '$notification_type' AND is_read = 0";
            $conn->query($sql);

            echo json_encode([
                'success' => true,
                'message' => 'Notification marked as read',
                'count' => getUnreadNewBookingNotificationCount($conn)
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'approve_review') {
        try {
            $review_id = $conn->real_escape_string($_POST['review_id']);

            $sql = "UPDATE reviews SET status = 'approved' WHERE id = '$review_id'";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Review approved successfully']);
            } else {
                throw new Exception('Error approving review: ' . $conn->error);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'reject_review') {
        try {
            $review_id = $conn->real_escape_string($_POST['review_id']);

            $sql = "UPDATE reviews SET status = 'rejected' WHERE id = '$review_id'";
            if ($conn->query($sql)) {
                echo json_encode(['success' => true, 'message' => 'Review rejected successfully']);
            } else {
                throw new Exception('Error rejecting review: ' . $conn->error);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'mark_all_admin_notifications_read') {
        try {
            ensureAdminNotificationsTable($conn);
            $conn->query("UPDATE admin_notifications SET is_read = 1 WHERE type = 'new_booking' AND is_read = 0");

            echo json_encode([
                'success' => true,
                'message' => 'All notifications marked as read',
                'count' => getUnreadNewBookingNotificationCount($conn)
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Handle GET requests for fetching data
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];
    
    // Check database connection
    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }
    
    if ($action === 'process_due_review_emails') {
        try {
            require_once __DIR__ . '/../email_helper.php';
            backfillUnscheduledReviewEmails($conn);
            $summary = processDueReviewEmails($conn);
            echo json_encode(['success' => true, 'summary' => $summary]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_users') {
        try {
            // Get customers from bookings table (unique emails)
            $sql = "SELECT DISTINCT email,
                    (SELECT name FROM bookings WHERE email = b.email ORDER BY created_at DESC LIMIT 1) as name,
                    (SELECT phone FROM bookings WHERE email = b.email ORDER BY created_at DESC LIMIT 1) as phone,
                    (SELECT COUNT(*) FROM bookings WHERE email = b.email) as total_bookings,
                    (SELECT MAX(created_at) FROM bookings WHERE email = b.email) as last_booking_date,
                    (SELECT MIN(created_at) FROM bookings WHERE email = b.email) as created_at
                    FROM bookings b
                    ORDER BY last_booking_date DESC";
            $result = $conn->query($sql);
            if (!$result) {
                throw new Exception('Query failed: ' . $conn->error);
            }

            $users = [];
            while ($row = $result->fetch_assoc()) {
                // Generate a customer ID from email
                $row['user_id'] = 'C-' . strtoupper(substr(md5($row['email']), 0, 6));

                // Get all bookings for this customer
                $booking_sql = "SELECT * FROM bookings WHERE email = '{$row['email']}' ORDER BY created_at DESC";
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

            echo json_encode(['success' => true, 'users' => $users]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_users_with_bookings') {
        try {
            $sql = "SELECT DISTINCT email,
                    (SELECT name FROM bookings WHERE email = b.email ORDER BY created_at DESC LIMIT 1) as name,
                    (SELECT phone FROM bookings WHERE email = b.email ORDER BY created_at DESC LIMIT 1) as phone,
                    (SELECT MIN(created_at) FROM bookings WHERE email = b.email) as created_at,
                    COUNT(b.booking_id) as booking_count
                    FROM bookings b
                    GROUP BY email
                    ORDER BY created_at DESC";
            $result = $conn->query($sql);
            if (!$result) {
                throw new Exception('Query failed: ' . $conn->error);
            }

            $users = [];
            while ($row = $result->fetch_assoc()) {
                // Generate customer ID
                $row['user_id'] = 'C-' . strtoupper(substr(md5($row['email']), 0, 6));
                $users[] = $row;
            }

            echo json_encode(['success' => true, 'users' => $users]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_reviews') {
        try {
            // Check if status column exists
            $column_check = $conn->query("SHOW COLUMNS FROM reviews LIKE 'status'");
            $has_status = $column_check && $column_check->num_rows > 0;

            // If status column doesn't exist, add it
            if (!$has_status) {
                $conn->query("ALTER TABLE reviews ADD COLUMN status ENUM('pending', 'approved', 'rejected') DEFAULT 'pending'");
            }

            $sql = "SELECT * FROM reviews ORDER BY FIELD(status, 'pending', 'approved', 'rejected'), created_at DESC";
            $result = $conn->query($sql);
            if (!$result) {
                throw new Exception('Query failed: ' . $conn->error);
            }

            $reviews = [];
            while ($row = $result->fetch_assoc()) {
                $reviews[] = $row;
            }

            echo json_encode(['success' => true, 'reviews' => $reviews]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    

    
    if ($action === 'get_admin_notification_count') {
        try {
            $pending_bookings = getUnreadNewBookingNotificationCount($conn);

            echo json_encode([
                'success' => true,
                'count' => $pending_bookings,
                'breakdown' => [
                    'new_bookings' => $pending_bookings
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_admin_notifications') {
        try {
            $notifications = [];
            ensureAdminNotificationsTable($conn);

            $new_bookings = $conn->query("SELECT * FROM admin_notifications WHERE type = 'new_booking' AND is_read = 0 ORDER BY created_at DESC LIMIT 10");
            if ($new_bookings) {
                while ($row = $new_bookings->fetch_assoc()) {
                    $notifications[] = [
                        'type' => 'new_booking',
                        'subject' => $row['subject'],
                        'message' => $row['message'],
                        'created_at' => $row['created_at'],
                        'booking_id' => $row['booking_id'],
                        'is_read' => false
                    ];
                }
            }

            // Legacy pending bookings without a notification record
            $pending = $conn->query("SELECT * FROM bookings
                WHERE status = 'pending_booking_confirmation'
                AND booking_id NOT IN (SELECT booking_id FROM admin_notifications WHERE type = 'new_booking')
                ORDER BY created_at DESC LIMIT 10");
            if ($pending) {
                while ($row = $pending->fetch_assoc()) {
                    $notifications[] = [
                        'type' => 'new_booking',
                        'subject' => 'New Booking',
                        'message' => "Booking #{$row['booking_id']} by {$row['name']} needs confirmation",
                        'created_at' => $row['created_at'],
                        'booking_id' => $row['booking_id'],
                        'is_read' => false
                    ];
                }
            }

            usort($notifications, function($a, $b) {
                return strtotime($b['created_at']) - strtotime($a['created_at']);
            });

            echo json_encode(['success' => true, 'notifications' => array_slice($notifications, 0, 10)]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_booking') {
        try {
            $booking_id = isset($_GET['booking_id']) ? $conn->real_escape_string($_GET['booking_id']) : '';
            if (!$booking_id) {
                throw new Exception('Booking ID is required');
            }

            $sql = "SELECT b.* FROM bookings b WHERE b.booking_id = '$booking_id' LIMIT 1";
            $result = $conn->query($sql);
            if (!$result) {
                throw new Exception('Booking query failed: ' . $conn->error);
            }

            $booking = $result->fetch_assoc();
            if (!$booking) {
                throw new Exception('Booking not found');
            }

            echo json_encode(['success' => true, 'booking' => $booking]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_bookings') {
        try {
            // Check if bookings table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'bookings'");
            if ($table_check->num_rows == 0) {
                throw new Exception('Bookings table does not exist');
            }
            
            $page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
            $limit = isset($_GET['limit']) ? max(5, min(100, intval($_GET['limit']))) : 10;
            $offset = ($page - 1) * $limit;
            $status_filter = isset($_GET['status']) ? $conn->real_escape_string($_GET['status']) : '';
            $search = isset($_GET['search']) ? $conn->real_escape_string($_GET['search']) : '';
            $checkin_from = isset($_GET['checkin_from']) ? $conn->real_escape_string($_GET['checkin_from']) : '';
            $checkin_to = isset($_GET['checkin_to']) ? $conn->real_escape_string($_GET['checkin_to']) : '';
            
            $where_clause = "1=1";
            if ($status_filter && $status_filter !== 'all') {
                $where_clause .= " AND b.status = '$status_filter'";
            }
            if ($search) {
                $where_clause .= " AND (b.name LIKE '%$search%' OR b.email LIKE '%$search%' OR b.booking_id LIKE '%$search%')";
            }
            if ($checkin_from) {
                $where_clause .= " AND b.checkin >= '$checkin_from'";
            }
            if ($checkin_to) {
                $where_clause .= " AND b.checkin <= '$checkin_to'";
            }
            
            $count_sql = "SELECT COUNT(*) as total FROM bookings b WHERE $where_clause";
            $total_result = $conn->query($count_sql);
            if (!$total_result) {
                throw new Exception('Count query failed: ' . $conn->error);
            }
            $total = $total_result->fetch_assoc()['total'];

            $sql = "SELECT b.* FROM bookings b WHERE $where_clause ORDER BY b.created_at DESC LIMIT $limit OFFSET $offset";
            $result = $conn->query($sql);
            if (!$result) {
                throw new Exception('Bookings query failed: ' . $conn->error);
            }
            
            $bookings = [];
            while ($row = $result->fetch_assoc()) {
                $bookings[] = $row;
            }
            
            echo json_encode([
                'success' => true, 
                'bookings' => $bookings,
                'pagination' => [
                    'page' => $page,
                    'limit' => $limit,
                    'total' => $total,
                    'pages' => ceil($total / $limit)
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_calendar_data') {
        try {
            $start_date = isset($_GET['start_date']) ? $conn->real_escape_string($_GET['start_date']) : '';
            $end_date = isset($_GET['end_date']) ? $conn->real_escape_string($_GET['end_date']) : '';
            
            // Fetch bookings that overlap with the date range (for calendar display)
            // We want bookings that: start within range, end within range, or span across the range
            $where_clause = "status != 'cancelled'";
            if ($start_date && $end_date) {
                $where_clause .= " AND (checkin <= '$end_date' AND checkout >= '$start_date')";
            }
            
            $sql = "SELECT * FROM bookings WHERE $where_clause ORDER BY checkin ASC";
            $result = $conn->query($sql);
            if (!$result) {
                throw new Exception('Query failed: ' . $conn->error);
            }
            
            $bookings = [];
            while ($row = $result->fetch_assoc()) {
                $bookings[] = [
                    'booking_id' => $row['booking_id'],
                    'name' => $row['name'],
                    'email' => $row['email'],
                    'checkin' => $row['checkin'],
                    'checkout' => $row['checkout'],
                    'guests' => $row['guests'],
                    'status' => $row['status'],
                    'payment_method' => $row['payment_method']
                ];
            }
            
            // Fetch blocked dates if table exists
            $blocked_dates = [];
            $check_table = $conn->query("SHOW TABLES LIKE 'blocked_dates'");
            if ($check_table && $check_table->num_rows > 0) {
                $blocked_where = "1=1";
                if ($start_date) {
                    $blocked_where .= " AND date >= '$start_date'";
                }
                if ($end_date) {
                    $blocked_where .= " AND date <= '$end_date'";
                }
                $blocked_result = $conn->query("SELECT date FROM blocked_dates WHERE $blocked_where ORDER BY date ASC");
                while ($row = $blocked_result->fetch_assoc()) {
                    $blocked_dates[] = $row['date'];
                }
            }
            
            // Fetch maintenance dates if table exists
            $maintenance_dates = [];
            $check_table = $conn->query("SHOW TABLES LIKE 'maintenance_dates'");
            if ($check_table && $check_table->num_rows > 0) {
                $maintenance_where = "1=1";
                if ($start_date) {
                    $maintenance_where .= " AND date >= '$start_date'";
                }
                if ($end_date) {
                    $maintenance_where .= " AND date <= '$end_date'";
                }
                $maintenance_result = $conn->query("SELECT date FROM maintenance_dates WHERE $maintenance_where ORDER BY date ASC");
                while ($row = $maintenance_result->fetch_assoc()) {
                    $maintenance_dates[] = $row['date'];
                }
            }
            
            echo json_encode([
                'success' => true,
                'bookings' => $bookings,
                'blocked_dates' => $blocked_dates,
                'maintenance_dates' => $maintenance_dates
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    

    
    if ($action === 'get_stats') {
        try {
            $booking_count = $conn->query("SELECT COUNT(*) as count FROM bookings")->fetch_assoc()['count'];
            $pending_bookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'pending_booking_confirmation'")->fetch_assoc()['count'];
            $confirmed_bookings = $conn->query("SELECT COUNT(*) as count FROM bookings WHERE status = 'booking_confirmed'")->fetch_assoc()['count'];

            // Count unique customers (by email)
            $customer_count = $conn->query("SELECT COUNT(DISTINCT email) as count FROM bookings")->fetch_assoc()['count'];

            echo json_encode([
                'success' => true,
                'stats' => [
                    'users' => $customer_count,
                    'bookings' => $booking_count,
                    'pending' => $pending_bookings,
                    'confirmed' => $confirmed_bookings
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'get_dashboard_analytics') {
        try {
            // Check if total_amount column exists in bookings table, add if not
            $column_check = $conn->query("SHOW COLUMNS FROM bookings LIKE 'total_amount'");
            if (!$column_check || $column_check->num_rows === 0) {
                $conn->query("ALTER TABLE bookings ADD COLUMN total_amount DECIMAL(10,2) DEFAULT 0.00");
            }

            // Booking trends over time (last 6 months)
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

            // Monthly revenue (from all confirmed bookings using actual total_amount)
            $monthly_revenue = [];
            for ($i = 5; $i >= 0; $i--) {
                $month = date('Y-m', strtotime("-$i months"));
                $month_start = $month . '-01';
                $month_end = date('Y-m-t', strtotime($month_start));

                // Calculate revenue from all confirmed booking total_amount (booking_confirmed, payment_confirmed, arrival_notice_sent, completed)
                $result = $conn->query("SELECT COALESCE(SUM(total_amount), 0) as total FROM bookings WHERE status IN ('booking_confirmed', 'payment_confirmed', 'arrival_notice_sent', 'completed') AND created_at BETWEEN '$month_start' AND '$month_end 23:59:59'");
                $revenue = $result ? $result->fetch_assoc()['total'] : 0;

                $monthly_revenue[] = [
                    'month' => date('M Y', strtotime($month_start)),
                    'revenue' => (float)$revenue
                ];
            }

            // Occupancy rate (current month)
            $current_month_start = date('Y-m-01');
            $current_month_end = date('Y-m-t');
            $total_days_in_month = date('t');

            // Get total booked days in current month
            $result = $conn->query("SELECT checkin, checkout FROM bookings WHERE status IN ('booking_confirmed', 'payment_confirmed', 'arrival_notice_sent', 'completed') AND checkin <= '$current_month_end' AND checkout >= '$current_month_start'");

            $booked_days = 0;
            while ($row = $result->fetch_assoc()) {
                $checkin = max($row['checkin'], $current_month_start);
                $checkout = min($row['checkout'], $current_month_end);
                $days = (strtotime($checkout) - strtotime($checkin)) / (60 * 60 * 24);
                if ($days > 0) {
                    $booked_days += $days;
                }
            }

            // Assuming 1 room property (this should come from property settings)
            $total_available_days = $total_days_in_month;
            $occupancy_rate = $total_available_days > 0 ? round(($booked_days / $total_available_days) * 100, 1) : 0;

            // Recent activities (last 10 events)
            $activities = [];

            // Recent bookings
            $recent_bookings = $conn->query("SELECT * FROM bookings ORDER BY created_at DESC LIMIT 5");
            while ($row = $recent_bookings->fetch_assoc()) {
                $status = str_replace('_', ' ', $row['status']);
                $status = ucfirst($status);
                $activities[] = [
                    'type' => 'booking',
                    'message' => "Booking #{$row['booking_id']} by {$row['name']} - $status",
                    'time' => $row['created_at'],
                    'booking_id' => $row['booking_id']
                ];
            }

            // Recent reviews
            $recent_reviews = $conn->query("SELECT 'review' as type, name, status, created_at FROM reviews ORDER BY created_at DESC LIMIT 3");
            while ($row = $recent_reviews->fetch_assoc()) {
                $activities[] = [
                    'type' => 'review',
                    'message' => "New review from {$row['name']} ({$row['status']})",
                    'time' => $row['created_at']
                ];
            }

            // Recent cancellations
            $recent_cancellations = $conn->query("SELECT 'cancellation' as type, name, booking_id, created_at FROM bookings WHERE status = 'cancelled' ORDER BY created_at DESC LIMIT 3");
            while ($row = $recent_cancellations->fetch_assoc()) {
                $activities[] = [
                    'type' => 'cancellation',
                    'message' => "Booking #{$row['booking_id']} cancelled by {$row['name']}",
                    'time' => $row['created_at']
                ];
            }

            // Sort activities by time
            usort($activities, function($a, $b) {
                return strtotime($b['time']) - strtotime($a['time']);
            });

            // Take only top 10
            $activities = array_slice($activities, 0, 10);

            // Upcoming check-ins (next 30 days)
            $today = date('Y-m-d');
            $next_month = date('Y-m-d', strtotime('+30 days'));
            $checkins = $conn->query("SELECT name, checkin, booking_id FROM bookings WHERE status IN ('booking_confirmed', 'payment_confirmed', 'arrival_notice_sent') AND checkin BETWEEN '$today' AND '$next_month' ORDER BY checkin ASC LIMIT 5");

            $upcoming_checkins = [];
            while ($row = $checkins->fetch_assoc()) {
                $upcoming_checkins[] = [
                    'name' => $row['name'],
                    'date' => $row['checkin'],
                    'booking_id' => $row['booking_id']
                ];
            }

            // Upcoming check-outs (next 30 days)
            $checkouts = $conn->query("SELECT name, checkout, booking_id FROM bookings WHERE status IN ('arrival_notice_sent', 'completed') AND checkout BETWEEN '$today' AND '$next_month' ORDER BY checkout ASC LIMIT 5");

            $upcoming_checkouts = [];
            while ($row = $checkouts->fetch_assoc()) {
                $upcoming_checkouts[] = [
                    'name' => $row['name'],
                    'date' => $row['checkout'],
                    'booking_id' => $row['booking_id']
                ];
            }

            echo json_encode([
                'success' => true,
                'analytics' => [
                    'booking_trends' => $booking_trends,
                    'monthly_revenue' => $monthly_revenue,
                    'occupancy_rate' => $occupancy_rate,
                    'recent_activities' => $activities,
                    'upcoming_checkins' => $upcoming_checkins,
                    'upcoming_checkouts' => $upcoming_checkouts
                ]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
    
    if ($action === 'get_payment_settings') {
        try {
            $sql = "SELECT setting_key, setting_value FROM payment_settings";
            $result = $conn->query($sql);
            if (!$result) {
                throw new Exception('Query failed: ' . $conn->error);
            }
            
            $settings = [];
            while ($row = $result->fetch_assoc()) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
            echo json_encode(['success' => true, 'settings' => $settings]);
        } catch (Exception $e) {
            echo json_encode(['success' => false, 'message' => $e->getMessage()]);
        }
        exit;
    }
}

// Handle GET requests
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    $action = $_GET['action'];

    // Check database connection
    if ($conn->connect_error) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit;
    }


}

// Handle logout
if (isset($_GET['action']) && $_GET['action'] === 'logout') {
    session_destroy();
    header('Location: AdminLogin.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.5">
  <title>Solendra Samal · Admin</title>
  <!-- Google Font & Icons -->
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600&family=Playfair+Display:wght@400;500;600;700&family=Manrope:wght@300;400;500;600&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0-beta3/css/all.min.css">
  <link rel="stylesheet" href="../Css/Admin.css">
</head>
<body>
  <!-- SIDEBAR -->
  <aside class="sidebar" id="sidebar">
    <div class="brand">
      <img src="../Picture/LOGO/solendrasamal-removebg-preview.png" alt="Solendra Samal" class="brand-logo">
      <span>Solendra</span><span>Samal</span>
    </div>
    <nav class="nav">
      <a class="nav-item active" data-page="dashboard"><i class="fas fa-chart-pie"></i><span>Dashboard</span></a>
      <a class="nav-item" data-page="bookings"><i class="fas fa-calendar-check"></i><span>Bookings</span></a>
      <a class="nav-item" data-page="calendar"><i class="fas fa-calendar-alt"></i><span>Calendar</span></a>
      <a class="nav-item" data-page="payment-qr"><i class="fas fa-qrcode"></i><span>Payment QR</span></a>
      <a class="nav-item" data-page="payment"><i class="fas fa-credit-card"></i><span>Payment</span></a>
      <a class="nav-item" data-page="reviews"><i class="fas fa-star"></i><span>Reviews</span></a>
      <a class="nav-item" data-page="settings"><i class="fas fa-cog"></i><span>Settings</span></a>
      <a class="nav-item" href="../Pages/Page.php"><i class="fas fa-home"></i><span>View Site</span></a>
      <a class="nav-item logout" id="logoutBtn"><i class="fas fa-sign-out-alt"></i><span>Logout</span></a>
    </nav>
  </aside>

  <!-- MAIN -->
  <div class="main">
    <header class="topnav">
      <button class="toggle-sidebar sidebar-toggle-button" id="mobileSidebarToggle" type="button" aria-label="Open navigation menu" title="Open navigation menu"><i class="fas fa-bars"></i></button>
      <div class="search"><i class="fas fa-search"></i><input placeholder="Search bookings, guests..."></div>
      <div class="actions">
        <div class="notification-bell" onclick="toggleAdminNotificationDropdown()">
          <i class="fas fa-bell"></i>
          <span id="adminNoticeBadge" class="notification-badge" style="display:none;">0</span>
        </div>
        <div id="adminNotificationDropdown" class="notification-dropdown" style="display:none;">
          <div class="notification-header">
            <h4>Notifications</h4>
            <button class="btn btn-sm btn-outline" onclick="markAllNotificationsAsRead()" style="font-size:0.75rem;padding:0.3rem 0.8rem;">
              <i class="fas fa-check-double"></i> Mark as Read
            </button>
          </div>
          <div id="adminNotificationList" class="notification-list">
            <div class="no-notifications">No notifications</div>
          </div>
        </div>
        <button id="themeToggle"><i class="fas fa-moon"></i></button>
        <div class="avatar">SS</div>
      </div>
    </header>
    <div class="content" id="pageContent">
      <!-- DASHBOARD -->
      <div id="page-dashboard" class="page">
        <div class="card-grid" id="statsGrid"></div>

        <div class="dashboard-analytics-grid">
          <div class="dashboard-panel dashboard-chart">
            <h4>Booking Analytics</h4>
            <div id="bookingAnalyticsChart" class="dashboard-chart-content">Loading...</div>
          </div>
          <div class="dashboard-panel dashboard-chart">
            <h4>Monthly Revenue</h4>
            <div id="monthlyRevenueChart" class="dashboard-chart-content">Loading...</div>
          </div>
          <div class="dashboard-panel dashboard-chart">
            <h4>Occupancy Rate</h4>
            <div id="occupancyRateChart" class="dashboard-chart-content">Loading...</div>
          </div>
        </div>

        <div class="dashboard-secondary">
          <div class="dashboard-panel dashboard-panel-wide">
            <h4>Recent Activities</h4>
            <ul id="recentActivitiesList" class="dashboard-activity-list"></ul>
          </div>
          <div class="dashboard-panels-row">
            <div class="dashboard-panel">
              <h5>Upcoming Check-ins</h5>
              <div id="upcomingCheckinsList" class="dashboard-mini-list"></div>
            </div>
            <div class="dashboard-panel">
              <h5>Upcoming Check-outs</h5>
              <div id="upcomingCheckoutsList" class="dashboard-mini-list"></div>
            </div>
          </div>
        </div>
      </div>

      <!-- BOOKINGS -->
      <div id="page-bookings" class="page hidden">
        <div class="bookings-header">
          <h2>Recent Bookings</h2>
          <div class="bookings-actions">
            <input type="text" id="searchBookings" placeholder="Search...">
            <select id="filterStatus">
              <option value="all">All Status</option>
              <option value="pending_booking_confirmation">Pending</option>
              <option value="booking_confirmed">Confirmed</option>
              <option value="payment_pending">Payment Pending</option>
              <option value="payment_under_review">Payment Review</option>
              <option value="payment_confirmed">Payment Confirmed</option>
              <option value="arrival_notice_sent">Arrival Notice</option>
              <option value="completed">Completed</option>
              <option value="cancelled">Cancelled</option>
            </select>
            <button class="btn" onclick="refreshBookings()">Refresh</button>
          </div>
        </div>
        
        <!-- Stats Cards -->
        <div class="stats-row">
          <div class="stat-card">
            <span class="stat-number" id="totalBookingsCount">0</span>
            <span class="stat-label">Total</span>
          </div>
          <div class="stat-card stat-pending">
            <span class="stat-number" id="pendingBookingsCount">0</span>
            <span class="stat-label">Pending</span>
          </div>
          <div class="stat-card stat-confirmed">
            <span class="stat-number" id="confirmedBookingsCount">0</span>
            <span class="stat-label">Confirmed</span>
          </div>
          <div class="stat-card stat-completed">
            <span class="stat-number" id="completedBookingsCount">0</span>
            <span class="stat-label">Completed</span>
          </div>
          <div class="stat-card stat-cancelled">
            <span class="stat-number" id="cancelledBookingsCount">0</span>
            <span class="stat-label">Cancelled</span>
          </div>
        </div>
        
        <div id="bookingsLoading" class="loading-state hidden">
          <i class="fas fa-spinner fa-spin"></i>
          <span>Loading...</span>
        </div>
        
        <div id="bookingsError" class="error-state hidden">
          <i class="fas fa-exclamation-circle"></i>
          <span id="bookingsErrorMsg">Failed to load bookings</span>
          <button class="btn btn-sm" onclick="refreshBookings()">Retry</button>
        </div>
        
        <div class="bookings-table-container">
          <table class="bookings-table">
            <thead>
              <tr>
                <th>ID</th>
                <th>Guest</th>
                <th>Status</th>
                <th>Actions</th>
              </tr>
            </thead>
            <tbody id="bookingsTableBody"></tbody>
          </table>
        </div>
        
        <div class="bookings-footer">
          <div id="bookingsPagination"></div>
          <div id="bookingsInfo"></div>
        </div>
      </div>

      <!-- CALENDAR -->
      <div id="page-calendar" class="page hidden">
        <div class="calendar-page-header">
          <h2>📅 Calendar Management</h2>
          <p>Manage bookings and schedule maintenance</p>
        </div>
        
        <div class="calendar-layout">
          <div class="booking-calendar-wrapper">
            <div class="booking-calendar-container">
              <div class="calendar-header">
                <button class="calendar-nav" onclick="prevMonth()"><i class="fas fa-chevron-left"></i></button>
                <h4 id="currentMonth">January 2025</h4>
                <button class="calendar-nav" onclick="nextMonth()"><i class="fas fa-chevron-right"></i></button>
              </div>
              <div class="calendar-weekdays">
                <span>Su</span><span>Mo</span><span>Tu</span><span>We</span><span>Th</span><span>Fr</span><span>Sa</span>
              </div>
              <div id="calendarGrid" class="calendar-days">
                <!-- Calendar days will be generated by JavaScript -->
              </div>
              <div class="calendar-legend">
                <div class="legend-item"><span class="legend-color available"></span> Available</div>
                <div class="legend-item"><span class="legend-color booked"></span> Booked</div>
                <div class="legend-item"><span class="legend-color maintenance"></span> Maintenance</div>
              </div>
            </div>
            
            <div class="calendar-actions">
              <div id="selectionInfo">No dates selected</div>
              <div class="action-buttons">
                <button class="btn btn-sm" id="setMaintenanceBtn" onclick="setMaintenance()" disabled>Set Maintenance</button>
                <button class="btn btn-sm btn-outline" id="removeMaintenanceBtn" onclick="removeMaintenance()">Remove Maintenance</button>
                <button class="btn btn-sm btn-outline" id="viewDetailsBtn" onclick="viewBookingDetails()" disabled>View Details</button>
              </div>
            </div>
            
            <div class="calendar-stats">
              <span>📊 <strong id="bookingsCount">0</strong> bookings this month</span>
              <span>🔧 <strong id="maintenanceCount">0</strong> maintenance days</span>
            </div>
          </div>
        </div>
      </div>

      <!-- PROPERTY -->
      <div id="page-property" class="page hidden">
        <h3>🏠 Property Settings</h3>
        <button class="btn" style="margin-bottom:12px;" onclick="loadPropertyData()">🔄 Load Data</button>
        <div style="background:var(--card);padding:24px;border-radius:var(--radius);">
          <div class="flex" style="flex-wrap:wrap;gap:18px;">
            <input id="propertyName" placeholder="Property Name" style="flex:1;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);">
            <input id="propertyCapacity" placeholder="Guest Capacity" style="width:100px;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);">
          </div>
          <div class="flex" style="flex-wrap:wrap;gap:18px;margin-top:12px;">
            <input id="propertyBedrooms" placeholder="Bedrooms" style="width:100px;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);">
            <input id="propertyBathrooms" placeholder="Bathrooms" style="width:100px;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);">
            <input id="propertyLocation" placeholder="Location" style="flex:1;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);">
          </div>
          <textarea id="propertyDescription" placeholder="Description" rows="3" style="width:100%;padding:12px;border-radius:16px;border:1px solid var(--border);background:var(--bg);margin-top:12px;color:var(--text-primary);"></textarea>
          <div style="margin-top:16px;">
            <label style="font-weight:600;margin-bottom:8px;display:block;">Contact Information</label>
            <input id="propertyPhone" placeholder="Phone" style="width:100%;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);margin-bottom:8px;color:var(--text-primary);">
            <input id="propertyEmail" placeholder="Email" style="width:100%;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);margin-bottom:8px;color:var(--text-primary);">
          </div>
          <div class="flex" style="margin-top:12px;gap:12px;">
            <button class="btn" onclick="savePropertyData()">Save Changes</button>
          </div>
        </div>
      </div>

      <!-- PAYMENT QR -->
      <div id="page-payment-qr" class="page hidden">
        <h3>📱 Online Payment QR Code</h3>
        <p style="color:var(--text-secondary);margin-bottom:20px;">Upload and manage the QR code for online payments. This will be displayed to users during the payment process.</p>
        
        <div style="background:var(--card);padding:24px;border-radius:var(--radius);">
          <div id="qrCodeSection">
            <div id="qrCodeDisplay" style="text-align:center;padding:40px;background:rgba(255,255,255,0.05);border-radius:20px;border:2px dashed var(--border);margin-bottom:20px;">
              <div id="qrCodePlaceholder" style="color:var(--text-secondary);">
                <i class="fas fa-qrcode" style="font-size:48px;margin-bottom:16px;"></i>
                <p>No QR code uploaded yet</p>
              </div>
              <div id="qrCodeImage" class="hidden">
                <img id="qrCodeImg" src="" alt="Payment QR Code" style="max-width:300px;border-radius:12px;border:1px solid rgba(255,255,255,0.2);">
              </div>
            </div>
            
            <div style="margin-bottom:20px;">
              <label style="font-weight:600;margin-bottom:8px;display:block;">Upload QR Code</label>
              <input type="file" id="qrCodeFile" accept="image/*" style="width:100%;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);">
            </div>
            
            <div class="flex" style="margin-top:12px;gap:12px;">
              <button class="btn" onclick="uploadQRCode()">Upload QR Code</button>
              <button class="btn btn-outline hidden" id="deleteQRBtn" onclick="deleteQRCode()">Delete QR Code</button>
            </div>
          </div>
        </div>
      </div>

      <!-- PAYMENT SETTINGS -->
      <div id="page-payment" class="page hidden">
        <h3>💳 Payment Settings</h3>
        <button class="btn" style="margin-bottom:12px;" onclick="loadPaymentSettings()">🔄 Load Settings</button>
        <div style="background:var(--card);padding:24px;border-radius:var(--radius);">
          <h4 style="margin-bottom:16px;font-family:'Playfair Display',serif;">Bank Transfer Details</h4>
          <div style="margin-bottom:20px;">
            <label style="font-weight:600;margin-bottom:8px;display:block;">Bank Name</label>
            <input id="bankName" placeholder="e.g., BDO, BPI, Metrobank" style="width:100%;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);margin-bottom:12px;color:var(--text-primary);">
            <label style="font-weight:600;margin-bottom:8px;display:block;">Account Name</label>
            <input id="bankAccountName" placeholder="Account holder name" style="width:100%;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);margin-bottom:12px;color:var(--text-primary);">
            <label style="font-weight:600;margin-bottom:8px;display:block;">Account Number</label>
            <input id="bankAccountNumber" placeholder="Account number" style="width:100%;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);margin-bottom:12px;color:var(--text-primary);">
          </div>
          
          <h4 style="margin-bottom:16px;font-family:'Playfair Display',serif;">GCash Details</h4>
          <div style="margin-bottom:20px;">
            <label style="font-weight:600;margin-bottom:8px;display:block;">GCash Name</label>
            <input id="gcashName" placeholder="GCash account name" style="width:100%;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);margin-bottom:12px;color:var(--text-primary);">
            <label style="font-weight:600;margin-bottom:8px;display:block;">GCash Number</label>
            <input id="gcashNumber" placeholder="09XXXXXXXXX" style="width:100%;padding:12px;border-radius:40px;border:1px solid var(--border);background:var(--bg);margin-bottom:12px;color:var(--text-primary);">
          </div>
          
          <h4 style="margin-bottom:16px;font-family:'Playfair Display',serif;">Payment Instructions</h4>
          <div style="margin-bottom:20px;">
            <textarea id="paymentInstructions" placeholder="Instructions for customers when uploading proof of payment" rows="4" style="width:100%;padding:12px;border-radius:16px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);"></textarea>
          </div>

          <div class="flex" style="margin-top:12px;gap:12px;">
            <button class="btn" onclick="savePaymentSettings()">Save Payment Settings</button>
          </div>
        </div>
      </div>

      <!-- USERS -->
      <div id="page-users" class="page hidden">
        <h3 style="margin-bottom:12px;">👥 Customer Management</h3>
        <div class="flex" style="margin-bottom:16px;gap:12px;flex-wrap:wrap;">
          <input id="searchUsers" placeholder="Search customers..." style="flex:1;padding:10px 18px;border-radius:40px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);">
          <select id="filterUsers" onchange="refreshUsers()" style="padding:10px 18px;border-radius:40px;border:1px solid var(--border);background:var(--bg);color:var(--text-primary);">
            <option value="all">All Customers</option>
            <option value="pending" selected>Pending Approval</option>
            <option value="approved">Approved Bookings</option>
          </select>
          <button class="btn" onclick="refreshUsers()">🔄 Refresh</button>
        </div>
        <div class="table-wrap">
          <table><thead><tr><th>Customer ID</th><th>Name</th><th>Email</th><th>Phone</th><th>Total Bookings</th><th>Last Booking</th><th>Recent Status</th><th>Joined</th><th>Actions</th></tr></thead>
          <tbody id="usersTableBody"></tbody></table>
        </div>
      </div>

      <!-- GALLERY, AMENITIES, REVIEWS, MESSAGES, REPORTS, SETTINGS (simplified) -->
      <div id="page-gallery" class="page hidden"><h3>🖼️ Gallery</h3><div class="flex"><button class="btn">Upload Image</button><button class="btn btn-outline">Replace</button></div><div style="display:flex;gap:12px;margin-top:16px;flex-wrap:wrap;"><div style="width:100px;height:100px;background:var(--bg);border-radius:12px;display:flex;align-items:center;justify-content:center;">🏡</div><div style="width:100px;height:100px;background:var(--bg);border-radius:12px;display:flex;align-items:center;justify-content:center;">🛋️</div></div></div>
      <div id="page-amenities" class="page hidden"><h3>🧺 Amenities</h3><div class="flex" style="gap:12px;flex-wrap:wrap;"><span class="badge" style="background:var(--accent);color:white;">Wi-Fi</span><span class="badge" style="background:var(--accent);color:white;">AC</span><span class="badge" style="background:var(--accent);color:white;">Kitchen</span><button class="btn btn-sm">+ Add</button></div></div>
      <div id="page-reviews" class="page hidden">
        <h3>⭐ Reviews</h3>
        <button class="btn" style="margin-bottom:12px;" onclick="loadReviews()">🔄 Refresh</button>
        <div id="reviewsList" style="display:flex;flex-direction:column;gap:12px;"></div>
      </div>

      <div id="page-reports" class="page hidden"><h3>📈 Reports</h3><button class="btn">Export PDF</button><button class="btn btn-outline">CSV</button><div style="background:var(--bg);height:120px;border-radius:16px;margin-top:16px;display:flex;align-items:center;justify-content:center;">[Revenue chart]</div></div>
      <div id="page-settings" class="page hidden"><h3>⚙️ Settings</h3><div style="background:var(--card);padding:20px;border-radius:var(--radius);"><div class="flex"><label>Dark mode</label><button id="themeToggle2" class="btn btn-sm">Toggle</button></div><button class="btn" style="margin-top:16px;">Change Password</button></div></div>
    </div>
  </div>

  <!-- MODAL PLACEHOLDER -->
  <div id="modalContainer"></div>
  <script>
    // ---------- GLOBAL FUNCTIONS (accessible to inline onclick handlers) ----------
    
    // Booking state management
    let bookingsState = {
      currentPage: 1,
      itemsPerPage: 10,
      totalItems: 0,
      totalPages: 0,
      isLoading: false
    };
    
    // Update booking metrics cards
    window.updateBookingMetrics = function(bookings) {
      const total = bookings.length;
      const pending = bookings.filter(b => b.status === 'pending_booking_confirmation').length;
      const confirmed = bookings.filter(b => b.status === 'booking_confirmed').length;
      const completed = bookings.filter(b => b.status === 'completed').length;
      const cancelled = bookings.filter(b => b.status === 'cancelled').length;
      
      const totalEl = document.getElementById('totalBookingsCount');
      const pendingEl = document.getElementById('pendingBookingsCount');
      const confirmedEl = document.getElementById('confirmedBookingsCount');
      const completedEl = document.getElementById('completedBookingsCount');
      const cancelledEl = document.getElementById('cancelledBookingsCount');
      
      if (totalEl) totalEl.textContent = total;
      if (pendingEl) pendingEl.textContent = pending;
      if (confirmedEl) confirmedEl.textContent = confirmed;
      if (completedEl) completedEl.textContent = completed;
      if (cancelledEl) cancelledEl.textContent = cancelled;
    };
    
    // Update mini calendar
    window.updateMiniCalendar = function(bookings) {
      const miniCalendarMonth = document.getElementById('miniCalendarMonth');
      const miniCalendarDays = document.getElementById('miniCalendarDays');
      
      // Skip if mini calendar elements don't exist (they were removed in the cleanup)
      if (!miniCalendarMonth || !miniCalendarDays) {
        return;
      }
      
      const now = new Date();
      const year = now.getFullYear();
      const month = now.getMonth();
      
      // Update month header
      const monthNames = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
      miniCalendarMonth.textContent = `${monthNames[month]} ${year}`;
      
      // Get first day of month and total days
      const firstDay = new Date(year, month, 1).getDay();
      const daysInMonth = new Date(year, month + 1, 0).getDate();
      
      // Get booking dates for highlighting
      const bookingDates = new Set();
      bookings.forEach(booking => {
        const checkin = new Date(booking.checkin);
        const checkout = new Date(booking.checkout);
        const current = new Date(checkin);
        while (current < checkout) {
          bookingDates.add(current.toDateString());
          current.setDate(current.getDate() + 1);
        }
      });
      
      // Generate calendar days
      miniCalendarDays.innerHTML = '';
      
      // Add empty cells for days before first day of month
      for (let i = 0; i < firstDay; i++) {
        const emptyCell = document.createElement('div');
        emptyCell.className = 'mini-cal-date';
        emptyCell.style.opacity = '0.3';
        miniCalendarDays.appendChild(emptyCell);
      }
      
      // Add days of month
      const today = new Date().toDateString();
      for (let day = 1; day <= daysInMonth; day++) {
        const dateCell = document.createElement('div');
        dateCell.className = 'mini-cal-date';
        dateCell.textContent = day;
        
        const currentDate = new Date(year, month, day).toDateString();
        
        if (currentDate === today) {
          dateCell.classList.add('today');
        }
        
        if (bookingDates.has(currentDate)) {
          dateCell.classList.add('has-booking');
        }
        
        miniCalendarDays.appendChild(dateCell);
      }
    };

    // Load bookings from database with pagination and filtering
    window.refreshBookings = function(page = 1) {
      console.log('refreshBookings called with page:', page);
      if (bookingsState.isLoading) return;
      
      bookingsState.isLoading = true;
      const tbody = document.getElementById('bookingsTableBody');
      const loadingDiv = document.getElementById('bookingsLoading');
      const errorDiv = document.getElementById('bookingsError');
      const paginationDiv = document.getElementById('bookingsPagination');
      const infoDiv = document.getElementById('bookingsInfo');
      
      console.log('DOM elements found:', { tbody: !!tbody, loadingDiv: !!loadingDiv, errorDiv: !!errorDiv });
      
      // Show loading state
      if (tbody) tbody.innerHTML = '';
      if (loadingDiv) loadingDiv.classList.remove('hidden');
      if (errorDiv) errorDiv.classList.add('hidden');
      if (paginationDiv) paginationDiv.innerHTML = '';
      if (infoDiv) infoDiv.innerHTML = '';
      
      const searchTerm = document.getElementById('searchBookings')?.value || '';
      const statusFilter = document.getElementById('filterStatus')?.value || 'all';
      const itemsPerPage = document.getElementById('itemsPerPage')?.value || '10';
      const checkinFrom = document.getElementById('filterCheckinFrom')?.value || '';
      const checkinTo = document.getElementById('filterCheckinTo')?.value || '';
      
      bookingsState.currentPage = page;
      bookingsState.itemsPerPage = parseInt(itemsPerPage);
      
      let url = `Admin.php?action=get_bookings&page=${page}&limit=${itemsPerPage}&status=${statusFilter}&search=${encodeURIComponent(searchTerm)}`;
      if (checkinFrom) url += `&checkin_from=${checkinFrom}`;
      if (checkinTo) url += `&checkin_to=${checkinTo}`;
      
      console.log('Fetching bookings from:', url);
      
      fetch(url)
        .then(response => {
          console.log('Response status:', response.status);
          if (!response.ok) throw new Error('Network response was not ok');
          return response.json();
        })
        .then(data => {
          console.log('Bookings data received:', data);
          bookingsState.isLoading = false;
          if (loadingDiv) loadingDiv.classList.add('hidden');
          
          if (!data.success) {
            console.error('API returned error:', data.message);
            throw new Error(data.message || 'Failed to load bookings');
          }
          
          const bookings = data.bookings || [];
          const pagination = data.pagination || { total: 0, pages: 0 };
          
          bookingsState.totalItems = pagination.total;
          bookingsState.totalPages = pagination.pages;
          
          // Update metrics cards
          updateBookingMetrics(bookings);
          
          // Update mini calendar
          updateMiniCalendar(bookings);
          
          if (bookings.length === 0) {
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;color:var(--text-secondary);padding:40px;">No bookings found</td></tr>';
            return;
          }

          tbody.innerHTML = bookings.map(booking => {
            const statusClass = booking.status || 'pending_booking_confirmation';

            const guestName = booking.name || booking.user_name || 'N/A';
            
            // Generate context-aware quick actions based on status
            let quickActions = '';
            quickActions = `
              <button class="booking-action-button icon-btn" onclick="viewBooking('${booking.booking_id}')" title="View details" aria-label="View details"><i class="fas fa-eye"></i></button>
              <div class="dropdown" style="display:inline-block;position:relative;">
                <button class="booking-action-button icon-btn" onclick="toggleBookingMenu('${booking.booking_id}')" title="More options" aria-label="More options"><i class="fas fa-ellipsis-v"></i></button>
                <div id="bookingMenu-${booking.booking_id}" class="dropdown-menu" style="display:none;position:absolute;right:0;top:100%;background:var(--card);border:1px solid var(--border);border-radius:8px;min-width:150px;z-index:1000;box-shadow:0 4px 12px rgba(0,0,0,0.2);">
                  <button class="dropdown-item" onclick="deleteBooking('${booking.booking_id}')" style="display:block;width:100%;padding:8px 12px;text-align:left;background:none;border:none;color:var(--text-primary);cursor:pointer;font-size:13px;"><i class="fas fa-trash-alt"></i> Delete booking</button>
                </div>
              </div>
            `;
            
            return `
              <tr>
                <td data-label="ID" class="booking-id"><span class="booking-value">${booking.booking_id}</span></td>
                <td data-label="Guest" class="booking-guest"><span class="booking-value">${guestName}</span></td>
                <td data-label="Status"><span class="status-badge status-${statusClass}"><span class="status-dot"></span>${booking.status.replace(/_/g, ' ').replace(/\b\w/g, character => character.toUpperCase())}</span></td>
                <td data-label="Actions" class="booking-actions-cell">
                  ${quickActions}
                </td>
              </tr>
            `;
          }).join('');
          
          // Render pagination
          renderPagination(pagination.page, pagination.pages, pagination.total);
          
          // Show info
          const startItem = (pagination.page - 1) * bookingsState.itemsPerPage + 1;
          const endItem = Math.min(pagination.page * bookingsState.itemsPerPage, pagination.total);
          if (infoDiv) {
            infoDiv.innerHTML = `Showing ${startItem}-${endItem} of ${pagination.total} bookings`;
          }
        })
        .catch(error => {
          bookingsState.isLoading = false;
          if (loadingDiv) loadingDiv.classList.add('hidden');
          if (errorDiv) {
            errorDiv.classList.remove('hidden');
            const errorMsg = document.getElementById('bookingsErrorMsg');
            if (errorMsg) errorMsg.textContent = error.message || 'Failed to load bookings';
          }
          showToast('Error loading bookings: ' + error.message);
        });
    };

    // Render pagination controls
    function renderPagination(currentPage, totalPages, totalItems) {
      const paginationDiv = document.getElementById('bookingsPagination');
      if (!paginationDiv || totalPages <= 1) {
        if (paginationDiv) paginationDiv.innerHTML = '';
        return;
      }
      
      let html = '';
      
      // Previous button
      html += `<button class="btn btn-sm btn-outline" ${currentPage === 1 ? 'disabled style="opacity:0.5;"' : ''} onclick="refreshBookings(${currentPage - 1})">« Prev</button>`;
      
      // Page numbers (show max 5 pages)
      const startPage = Math.max(1, currentPage - 2);
      const endPage = Math.min(totalPages, startPage + 4);
      
      if (startPage > 1) {
        html += `<button class="btn btn-sm btn-outline" onclick="refreshBookings(1)">1</button>`;
        if (startPage > 2) html += `<span style="color:var(--text-secondary);">...</span>`;
      }
      
      for (let i = startPage; i <= endPage; i++) {
        html += `<button class="btn btn-sm ${i === currentPage ? '' : 'btn-outline'}" onclick="refreshBookings(${i})">${i}</button>`;
      }
      
      if (endPage < totalPages) {
        if (endPage < totalPages - 1) html += `<span style="color:var(--text-secondary);">...</span>`;
        html += `<button class="btn btn-sm btn-outline" onclick="refreshBookings(${totalPages})">${totalPages}</button>`;
      }
      
      // Next button
      html += `<button class="btn btn-sm btn-outline" ${currentPage === totalPages ? 'disabled style="opacity:0.5;"' : ''} onclick="refreshBookings(${currentPage + 1})">Next »</button>`;
      
      paginationDiv.innerHTML = html;
    }

    // Apply date filter
    window.applyDateFilter = function() {
      refreshBookings(1);
    };

    // Clear date filter
    window.clearDateFilter = function() {
      const checkinFrom = document.getElementById('filterCheckinFrom');
      const checkinTo = document.getElementById('filterCheckinTo');
      if (checkinFrom) checkinFrom.value = '';
      if (checkinTo) checkinTo.value = '';
      refreshBookings(1);
    };






    // Update dashboard stats with real data
    window.updateDashboardStats = function() {
      fetch('Admin.php?action=get_stats')
      .then(response => response.json())
      .then(data => {
        if (!data.success) return;

        const stats = data.stats;
        const statsArray = [
          { label: 'Total Bookings', value: stats.bookings.toString(), change: stats.bookings > 0 ? '+12%' : '0%' },
          { label: 'Pending', value: stats.pending.toString(), change: stats.pending > 0 ? '⏳' : '0' },
          { label: 'Confirmed', value: stats.confirmed.toString(), change: stats.confirmed > 0 ? '+8%' : '0' },
          { label: 'Registered Customers', value: stats.users.toString(), change: stats.users > 0 ? '+5%' : '0' },

        ];

        const statsGrid = document.getElementById('statsGrid');
        if (!statsGrid) return;

        statsGrid.innerHTML = statsArray.map(s => `
          <div class="stat-card"><div class="label">${s.label}</div><div class="value">${s.value}</div><div class="change">${s.change}</div></div>
        `).join('');
      })
      .catch(error => console.error('Error loading stats:', error));
    };

    // Load dashboard analytics and summary panels
    window.loadDashboardAnalytics = function() {
      fetch('Admin.php?action=get_dashboard_analytics')
      .then(response => response.json())
      .then(data => {
        if (!data.success) return;

        const analytics = data.analytics;
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const gridColor = isDark ? 'rgba(255, 255, 255, 0.1)' : 'var(--border)';
        const labelColor = isDark ? 'rgba(255, 255, 255, 0.6)' : 'var(--text-tertiary)';
        const subLabelColor = isDark ? 'rgba(255, 255, 255, 0.7)' : 'var(--text-secondary)';

        const bookingTrendsContainer = document.getElementById('bookingAnalyticsChart');
        if (bookingTrendsContainer && analytics.booking_trends) {
          const maxCount = Math.max(...analytics.booking_trends.map(t => t.count), 1);
          bookingTrendsContainer.innerHTML = `
            <div class="dashboard-chart-inner">
              <div class="dashboard-chart-bars">
                ${analytics.booking_trends.map((trend, index) => `
                  <div class="dashboard-chart-bar-group">
                    <div class="bar-wrapper" onmouseenter="showTooltip(this, '${trend.count} bookings')" onmouseleave="hideTooltip(this)">
                      <div class="bar bar-booking" style="height:${(trend.count / maxCount) * 80}px;animation-delay:${index * 0.1}s;"></div>
                    </div>
                    <span class="dashboard-chart-label">${trend.month.split(' ')[0]}</span>
                  </div>
                `).join('')}
              </div>
              <div class="dashboard-chart-caption" style="color:${subLabelColor};">Bookings per month</div>
            </div>
          `;
          bookingTrendsContainer.querySelector('.dashboard-chart-bars').style.borderBottomColor = gridColor;
          bookingTrendsContainer.querySelectorAll('.dashboard-chart-label').forEach(el => el.style.color = labelColor);
        }

        const revenueContainer = document.getElementById('monthlyRevenueChart');
        if (revenueContainer && analytics.monthly_revenue) {
          const maxRevenue = Math.max(...analytics.monthly_revenue.map(r => r.revenue), 1);
          revenueContainer.innerHTML = `
            <div class="dashboard-chart-inner">
              <div class="dashboard-chart-bars">
                ${analytics.monthly_revenue.map((rev, index) => `
                  <div class="dashboard-chart-bar-group">
                    <div class="bar-wrapper" onmouseenter="showTooltip(this, '₱${(rev.revenue / 1000).toFixed(1)}k')" onmouseleave="hideTooltip(this)">
                      <div class="bar bar-revenue" style="height:${(rev.revenue / maxRevenue) * 80}px;animation-delay:${index * 0.1}s;"></div>
                    </div>
                    <span class="dashboard-chart-label">${rev.month.split(' ')[0]}</span>
                  </div>
                `).join('')}
              </div>
              <div class="dashboard-chart-caption" style="color:${subLabelColor};">Revenue (PHP)</div>
            </div>
          `;
          revenueContainer.querySelector('.dashboard-chart-bars').style.borderBottomColor = gridColor;
          revenueContainer.querySelectorAll('.dashboard-chart-label').forEach(el => el.style.color = labelColor);
        }

        const occupancyContainer = document.getElementById('occupancyRateChart');
        if (occupancyContainer) {
          const rate = analytics.occupancy_rate || 0;
          const progressBg = isDark ? 'rgba(255, 255, 255, 0.1)' : 'var(--bg-secondary)';
          occupancyContainer.innerHTML = `
            <div class="dashboard-chart-inner dashboard-occupancy">
              <div class="dashboard-occupancy-value">${rate}%</div>
              <div class="dashboard-chart-caption" style="color:${subLabelColor};margin-bottom:16px;">Occupancy Rate</div>
              <div class="dashboard-occupancy-track" style="background:${progressBg};">
                <div class="dashboard-occupancy-fill" style="width:${rate}%;"></div>
              </div>
            </div>
          `;
        }

        const activitiesContainer = document.getElementById('recentActivitiesList');
        const checkinsContainer = document.getElementById('upcomingCheckinsList');
        const checkoutsContainer = document.getElementById('upcomingCheckoutsList');

        if (activitiesContainer && analytics.recent_activities) {
          if (analytics.recent_activities.length === 0) {
            activitiesContainer.innerHTML = '<li class="dashboard-empty">No recent activities</li>';
          } else {
            activitiesContainer.innerHTML = analytics.recent_activities.map(activity => {
              const time = new Date(activity.time).toLocaleString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
              const icon = activity.type === 'booking' ? 'fa-calendar-check' : activity.type === 'review' ? 'fa-star' : activity.type === 'cancellation' ? 'fa-times-circle' : 'fa-bell';
              return `<li class="dashboard-activity-item"><i class="fas ${icon}"></i><span class="dashboard-activity-text">${activity.message}</span><span class="dashboard-activity-time">${time}</span></li>`;
            }).join('');
          }
        }

        if (checkinsContainer && analytics.upcoming_checkins) {
          if (analytics.upcoming_checkins.length === 0) {
            checkinsContainer.innerHTML = '<div class="dashboard-empty">No upcoming check-ins</div>';
          } else {
            checkinsContainer.innerHTML = analytics.upcoming_checkins.map(checkin => {
              const date = new Date(checkin.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
              return `<div class="dashboard-mini-item"><span>${checkin.name}</span><span>${date}</span></div>`;
            }).join('');
          }
        }

        if (checkoutsContainer && analytics.upcoming_checkouts) {
          if (analytics.upcoming_checkouts.length === 0) {
            checkoutsContainer.innerHTML = '<div class="dashboard-empty">No upcoming check-outs</div>';
          } else {
            checkoutsContainer.innerHTML = analytics.upcoming_checkouts.map(checkout => {
              const date = new Date(checkout.date).toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
              return `<div class="dashboard-mini-item"><span>${checkout.name}</span><span>${date}</span></div>`;
            }).join('');
          }
        }
      })
      .catch(error => console.error('Error loading dashboard analytics:', error));
    };

    window.showTooltip = function(element, text) {
      hideTooltip(element);
      const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
      const tooltip = document.createElement('div');
      tooltip.className = 'chart-tooltip';
      tooltip.textContent = text;
      tooltip.style.cssText = `
        position: absolute;
        bottom: calc(100% + 8px);
        left: 50%;
        transform: translateX(-50%);
        background: ${isDark ? 'rgba(255, 255, 255, 0.95)' : 'var(--text-primary)'};
        color: ${isDark ? 'var(--text-primary)' : 'var(--bg)'};
        padding: 8px 14px;
        border-radius: 8px;
        font-size: 12px;
        font-weight: 500;
        white-space: nowrap;
        z-index: 1000;
        box-shadow: 0 4px 12px rgba(0, 0, 0, 0.15);
        pointer-events: none;
      `;
      element.style.position = 'relative';
      element.appendChild(tooltip);
    };

    window.hideTooltip = function(element) {
      const tooltip = element.querySelector('.chart-tooltip');
      if (tooltip) tooltip.remove();
    };

    // Load property data from localStorage
    window.loadPropertyData = function() {
      const propertyData = JSON.parse(localStorage.getItem('solendra_property') || '{}');
      
      if (propertyData.name) {
        document.getElementById('propertyName').value = propertyData.name || '';
        document.getElementById('propertyCapacity').value = propertyData.capacity || '';
        document.getElementById('propertyBedrooms').value = propertyData.bedrooms || '';
        document.getElementById('propertyBathrooms').value = propertyData.bathrooms || '';
        document.getElementById('propertyLocation').value = propertyData.location || '';
        document.getElementById('propertyDescription').value = propertyData.description || '';
        
        if (propertyData.contact) {
          document.getElementById('propertyPhone').value = propertyData.contact.phone || '';
          document.getElementById('propertyEmail').value = propertyData.contact.email || '';
        }
        
        showToast('Property data loaded successfully');
      } else {
        showToast('No property data found');
      }
    };

    // Load payment settings from database
    window.loadPaymentSettings = function() {
      fetch('Admin.php?action=get_payment_settings')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          const settings = data.settings;
          document.getElementById('bankName').value = settings.bank_name || '';
          document.getElementById('bankAccountName').value = settings.bank_account_name || '';
          document.getElementById('bankAccountNumber').value = settings.bank_account_number || '';
          document.getElementById('gcashName').value = settings.gcash_name || '';
          document.getElementById('gcashNumber').value = settings.gcash_number || '';
          document.getElementById('paymentInstructions').value = settings.payment_instructions || '';
          showToast('Payment settings loaded successfully');
        } else {
          showToast('Failed to load payment settings');
        }
      })
      .catch(error => {
        console.error('Error loading payment settings:', error);
        showToast('Error loading payment settings');
      });
    };

    // Save payment settings to database
    window.savePaymentSettings = function() {
      const formData = new FormData();
      formData.append('action', 'save_payment_settings');
      formData.append('bank_name', document.getElementById('bankName').value);
      formData.append('bank_account_name', document.getElementById('bankAccountName').value);
      formData.append('bank_account_number', document.getElementById('bankAccountNumber').value);
      formData.append('gcash_name', document.getElementById('gcashName').value);
      formData.append('gcash_number', document.getElementById('gcashNumber').value);
      formData.append('payment_instructions', document.getElementById('paymentInstructions').value);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast(data.message);
        } else {
          showToast(data.message || 'Failed to save payment settings');
        }
      })
      .catch(error => {
        console.error('Error saving payment settings:', error);
        showToast('Error saving payment settings');
      });
    };
    
    // Load QR code
    window.loadQRCode = function() {
      fetch('Admin.php?action=get_qr_code')
      .then(response => response.json())
      .then(data => {
        if (!data.success) return;
        
        const placeholder = document.getElementById('qrCodePlaceholder');
        const imageContainer = document.getElementById('qrCodeImage');
        const qrImg = document.getElementById('qrCodeImg');
        const deleteBtn = document.getElementById('deleteQRBtn');
        
        if (data.qr_code_path) {
          placeholder.classList.add('hidden');
          imageContainer.classList.remove('hidden');
          qrImg.src = data.qr_code_path;
          deleteBtn.classList.remove('hidden');
        } else {
          placeholder.classList.remove('hidden');
          imageContainer.classList.add('hidden');
          deleteBtn.classList.add('hidden');
        }
      })
      .catch(error => console.error('Error loading QR code:', error));
    };
    
    // Upload QR code
    window.uploadQRCode = function() {
      const fileInput = document.getElementById('qrCodeFile');
      if (!fileInput.files || fileInput.files.length === 0) {
        alert('Please select a QR code image to upload');
        return;
      }
      
      const formData = new FormData();
      formData.append('action', 'upload_qr_code');
      formData.append('qr_code', fileInput.files[0]);
      
      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert(data.message);
          fileInput.value = '';
          loadQRCode();
        } else {
          alert(data.message || 'Failed to upload QR code');
        }
      })
      .catch(error => {
        console.error('Error uploading QR code:', error);
        alert('Error uploading QR code');
      });
    };
    
    // Delete QR code
    window.deleteQRCode = function() {
      if (!confirm('Are you sure you want to delete the QR code?')) {
        return;
      }

      const formData = new FormData();
      formData.append('action', 'delete_qr_code');

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          alert(data.message);
          loadQRCode();
        } else {
          alert(data.message || 'Failed to delete QR code');
        }
      })
      .catch(error => {
        console.error('Error deleting QR code:', error);
        alert('Error deleting QR code');
      });
    };

    // Load reviews
    window.loadReviews = function() {
      fetch('Admin.php?action=get_reviews')
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          displayReviews(data.reviews);
        } else {
          showToast('Failed to load reviews');
        }
      })
      .catch(error => {
        console.error('Error loading reviews:', error);
        showToast('Error loading reviews');
      });
    };

    // Display reviews
    function displayReviews(reviews) {
      const container = document.getElementById('reviewsList');
      if (!container) return;

      if (reviews.length === 0) {
        container.innerHTML = '<div style="background:var(--card);padding:16px;border-radius:var(--radius-sm);text-align:center;">No reviews yet</div>';
        return;
      }

      container.innerHTML = reviews.map(review => {
        const statusColors = {
          'pending': 'var(--warning)',
          'approved': 'var(--success)',
          'rejected': 'var(--danger)'
        };

        const stars = '★'.repeat(review.rating) + '☆'.repeat(5 - review.rating);
        const statusColor = statusColors[review.status] || 'var(--text-tertiary)';

        return `
          <div style="background:var(--card);padding:16px;border-radius:var(--radius-sm);">
            <div style="display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:8px;">
              <div>
                <strong>${review.name || 'Guest'}</strong>
                <span style="color:var(--warning);margin-left:8px;">${stars}</span>
              </div>
              <span style="background:${statusColor};color:white;padding:4px 12px;border-radius:12px;font-size:12px;font-weight:600;">${review.status.toUpperCase()}</span>
            </div>
            <p style="margin-bottom:12px;color:var(--text-secondary);">${review.review_text}</p>
            <div style="font-size:12px;color:var(--text-muted);margin-bottom:12px;">${new Date(review.created_at).toLocaleString()}</div>
            ${review.status === 'pending' ? `
              <div style="display:flex;gap:8px;">
                <button class="btn btn-sm" onclick="approveReview(${review.id})">✓ Approve</button>
                <button class="btn btn-sm btn-outline" onclick="rejectReview(${review.id})">✗ Reject</button>
              </div>
            ` : ''}
          </div>
        `;
      }).join('');
    }

    // Approve review
    window.approveReview = function(reviewId) {
      if (!confirm('Are you sure you want to approve this review?')) {
        return;
      }

      const formData = new FormData();
      formData.append('action', 'approve_review');
      formData.append('review_id', reviewId);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast(data.message);
          loadReviews();
        } else {
          showToast(data.message || 'Failed to approve review');
        }
      })
      .catch(error => {
        console.error('Error approving review:', error);
        showToast('Error approving review');
      });
    };

    // Reject review
    window.rejectReview = function(reviewId) {
      if (!confirm('Are you sure you want to reject this review?')) {
        return;
      }

      const formData = new FormData();
      formData.append('action', 'reject_review');
      formData.append('review_id', reviewId);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast(data.message);
          loadReviews();
        } else {
          showToast(data.message || 'Failed to reject review');
        }
      })
      .catch(error => {
        console.error('Error rejecting review:', error);
        showToast('Error rejecting review');
      });
    };

    // Load users from database
    window.refreshUsers = function() {
      fetch('Admin.php?action=get_users')
      .then(response => response.json())
      .then(usersData => {
        if (!usersData.success) {
          console.error('Failed to load users:', usersData.message);
          return;
        }

        const users = usersData.users;
        const tbody = document.getElementById('usersTableBody');
        const searchTerm = document.getElementById('searchUsers')?.value.toLowerCase() || '';
        const filterType = document.getElementById('filterUsers')?.value || 'all';

        const filteredCustomers = users.filter(user => {
          // Filter by search term
          const matchesSearch = user.name.toLowerCase().includes(searchTerm) ||
                 user.email.toLowerCase().includes(searchTerm) ||
                 user.user_id.toLowerCase().includes(searchTerm);

          // Filter by status
          let matchesFilter = true;
          if (filterType === 'pending') {
            matchesFilter = user.bookings && user.bookings.some(b => b.status === 'pending_booking_confirmation');
          } else if (filterType === 'approved') {
            matchesFilter = user.bookings && user.bookings.some(b => b.status === 'booking_confirmed');
          }

          return matchesSearch && matchesFilter;
        });

        if (filteredCustomers.length === 0) {
          tbody.innerHTML = '<tr><td colspan="10" style="text-align:center;color:var(--text-secondary);">No customers found</td></tr>';
          return;
        }

        tbody.innerHTML = filteredCustomers.map(user => {
          const joinedDate = new Date(user.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
          const lastBookingDate = user.last_booking_date ? new Date(user.last_booking_date).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }) : 'N/A';

          // Get most recent booking status
          let recentStatus = 'N/A';
          let statusBadge = '';
          if (user.bookings && user.bookings.length > 0) {
            const latestBooking = user.bookings[0];
            recentStatus = latestBooking.status.replace(/_/g, ' ');
            recentStatus = recentStatus.charAt(0).toUpperCase() + recentStatus.slice(1);

            // Status badge colors
            const statusColors = {
              'pending_booking_confirmation': 'var(--warning)',
              'booking_confirmed': 'var(--success)',
              'payment_pending': 'var(--warning)',
              'payment_under_review': 'var(--info)',
              'payment_confirmed': 'var(--success)',
              'arrival_notice_sent': 'var(--accent)',
              'completed': 'var(--success)',
              'cancelled': 'var(--danger)'
            };
            const bgColor = statusColors[latestBooking.status] || 'var(--text-tertiary)';
            statusBadge = `<span class="badge" style="background:${bgColor};color:white;">${recentStatus}</span>`;
          }

          return `
            <tr>
              <td>${user.user_id}</td>
              <td>${user.name}</td>
              <td>${user.email}</td>
              <td>${user.phone || 'N/A'}</td>
              <td>${user.total_bookings}</td>
              <td>${lastBookingDate}</td>
              <td>${statusBadge}</td>
              <td>${joinedDate}</td>
              <td>
                <button class="btn btn-sm btn-outline" onclick="viewUserDetails('${user.user_id}')" title="View Details">👁</button>
                <button class="btn btn-sm btn-outline" onclick="viewUserBookings('${user.user_id}')" title="View Bookings">📋</button>
                <button class="btn btn-sm btn-outline" onclick="deleteUser('${user.user_id}', '${user.email}')" title="Delete Customer">🗑</button>
              </td>
            </tr>
          `;
        }).join('');
      })
      .catch(error => console.error('Error loading users:', error));
    };

    // View user's bookings
    window.viewUserBookings = function(userId) {
      // Switch to bookings page and filter by user
      showPage('bookings');
      // After a short delay, filter the bookings by user_id
      setTimeout(() => {
        const searchInput = document.getElementById('searchBookings');
        if (searchInput) {
          searchInput.value = userId;
          refreshBookings(1);
        }
      }, 100);
    };

    // View customer details with all booking information
    window.viewUserDetails = function(userId) {
      fetch('Admin.php?action=get_users')
      .then(response => response.json())
      .then(data => {
        if (!data.success) {
          showToast('Failed to load customer data');
          return;
        }

        const user = data.users.find(u => u.user_id === userId);
        if (!user) {
          showToast('Customer not found');
          return;
        }

        const joinedDate = new Date(user.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
        
        // Build booking details HTML
        let bookingsHtml = '';
        if (user.bookings && user.bookings.length > 0) {
          bookingsHtml = user.bookings.map(booking => {
            // Validate and format check-in/check-out dates
            const checkinDateObj = booking.checkin ? new Date(booking.checkin) : null;
            const checkoutDateObj = booking.checkout ? new Date(booking.checkout) : null;
            const createdDateObj = booking.created_at ? new Date(booking.created_at) : null;

            const checkinDate = checkinDateObj && !isNaN(checkinDateObj.getTime())
              ? checkinDateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
              : 'N/A';
            const checkoutDate = checkoutDateObj && !isNaN(checkoutDateObj.getTime())
              ? checkoutDateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
              : 'N/A';
            const createdDate = createdDateObj && !isNaN(createdDateObj.getTime())
              ? createdDateObj.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })
              : 'N/A';
            
            const statusColors = {
              'pending_booking_confirmation': 'var(--warning)',
              'booking_confirmed': 'var(--success)',
              'payment_pending': 'var(--warning)',
              'payment_under_review': 'var(--info)',
              'payment_confirmed': 'var(--success)',
              'arrival_notice_sent': 'var(--accent)',
              'completed': 'var(--success)',
              'cancelled': 'var(--danger)'
            };
            const bgColor = statusColors[booking.status] || 'var(--text-tertiary)';
            const statusDisplay = booking.status.replace(/_/g, ' ');
            statusDisplay = statusDisplay.charAt(0).toUpperCase() + statusDisplay.slice(1);

            // Build action buttons based on status
            let actionButtons = '';
            if (booking.status === 'pending_booking_confirmation') {
              actionButtons = `
                <div style="grid-column:1/-1;margin-top:12px;display:flex;gap:8px;">
                  <button class="btn btn-sm" style="flex:1;background:var(--success);color:white;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;" onclick="handleBookingAction('${booking.booking_id}', 'booking_confirmed', this); refreshUsers();">
                    <i class="fas fa-check"></i> Confirm
                  </button>
                  <button class="btn btn-sm" style="flex:1;background:var(--danger);color:white;border:none;padding:8px 16px;border-radius:6px;cursor:pointer;" onclick="handleBookingRejection('${booking.booking_id}', this); refreshUsers();">
                    <i class="fas fa-times"></i> Reject
                  </button>
                </div>
              `;
            }

            return `
              <div style="background:var(--card);border:1px solid var(--border);border-radius:12px;padding:16px;margin-bottom:12px;">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;">
                  <strong style="color:var(--text-primary);">Booking ID: ${booking.booking_id}</strong>
                  <span class="badge" style="background:${bgColor};color:white;">${statusDisplay}</span>
                </div>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;font-size:13px;">
                  <div><span style="color:var(--text-secondary);">Name:</span> <span style="color:var(--text-primary);">${booking.name || 'N/A'}</span></div>
                  <div><span style="color:var(--text-secondary);">Email:</span> <span style="color:var(--text-primary);">${booking.email || 'N/A'}</span></div>
                  <div><span style="color:var(--text-secondary);">Phone:</span> <span style="color:var(--text-primary);">${booking.phone || 'N/A'}</span></div>
                  <div><span style="color:var(--text-secondary);">Check-in:</span> <span style="color:var(--text-primary);">${checkinDate}</span></div>
                  <div><span style="color:var(--text-secondary);">Check-out:</span> <span style="color:var(--text-primary);">${checkoutDate}</span></div>
                  <div><span style="color:var(--text-secondary);">Guests:</span> <span style="color:var(--text-primary);">${booking.guests ? (booking.adults || booking.children || booking.infants ? booking.guests + ' (' + (booking.adults || 0) + ' adults, ' + (booking.children || 0) + ' children, ' + (booking.infants || 0) + ' infants)' : booking.guests) : 'N/A'}</span></div>
                  <div><span style="color:var(--text-secondary);">Pets:</span> <span style="color:var(--text-primary);">${booking.pets || 'No'}</span></div>
                  <div><span style="color:var(--text-secondary);">Total Amount:</span> <span style="color:var(--text-primary);">₱${booking.total_amount || '0'}</span></div>
                  <div><span style="color:var(--text-secondary);">Downpayment:</span> <span style="color:var(--text-primary);">₱${booking.downpayment || '0'}</span></div>
                  <div><span style="color:var(--text-secondary);">Remaining:</span> <span style="color:var(--text-primary);">₱${booking.remaining_balance || '0'}</span></div>
                  <div><span style="color:var(--text-secondary);">Payment Status:</span> <span style="color:var(--text-primary);">${booking.payment_status || 'pending'}</span></div>
                  <div><span style="color:var(--text-secondary);">Created:</span> <span style="color:var(--text-primary);">${createdDate}</span></div>
                  ${booking.special_requests ? `<div style="grid-column:1/-1;margin-top:8px;"><span style="color:var(--text-secondary);">Special Requests:</span> <span style="color:var(--text-primary);">${booking.special_requests}</span></div>` : ''}
                  ${actionButtons}
                </div>
              </div>
            `;
          }).join('');
        } else {
          bookingsHtml = '<div style="text-align:center;color:var(--text-secondary);padding:20px;">No bookings found</div>';
        }

        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = `
          <div class="modal-container">
            <div class="modal-header">
              <h4>Customer Details</h4>
              <span onclick="this.closest('.modal-overlay').remove()" style="cursor:pointer;font-size:24px;color:var(--text-secondary);">&times;</span>
            </div>
            <div class="modal-body">
              <div style="margin-bottom:20px;">
                <h5 style="margin-bottom:12px;color:var(--text-primary);">Customer Information</h5>
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;font-size:14px;">
                  <div><span style="color:var(--text-secondary);">Customer ID:</span> <span style="color:var(--text-primary);">${user.user_id}</span></div>
                  <div><span style="color:var(--text-secondary);">Name:</span> <span style="color:var(--text-primary);">${user.name}</span></div>
                  <div><span style="color:var(--text-secondary);">Email:</span> <span style="color:var(--text-primary);">${user.email}</span></div>
                  <div><span style="color:var(--text-secondary);">Phone:</span> <span style="color:var(--text-primary);">${user.phone || 'N/A'}</span></div>
                  <div><span style="color:var(--text-secondary);">Total Bookings:</span> <span style="color:var(--text-primary);">${user.total_bookings}</span></div>
                  <div><span style="color:var(--text-secondary);">Joined:</span> <span style="color:var(--text-primary);">${joinedDate}</span></div>
                </div>
              </div>
              <div>
                <h5 style="margin-bottom:12px;color:var(--text-primary);">Booking History (${user.bookings.length})</h5>
                ${bookingsHtml}
              </div>
            </div>
          </div>
        `;
        document.body.appendChild(modal);
      })
      .catch(error => {
        console.error('Error loading customer details:', error);
        showToast('Error loading customer details');
      });
    };

    // View booking details
    window.viewBooking = function(id) {
      console.log('Viewing booking:', id);
      fetch(`Admin.php?action=get_booking&booking_id=${encodeURIComponent(id)}&_t=${Date.now()}`)
      .then(response => response.json())
      .then(data => {
        if (!data.success) {
          showToast(data.message || 'Failed to load booking details');
          return;
        }
        
        const booking = data.booking;
        if (!booking) return;
        
        console.log('Booking status:', booking.status);

        const guestName = booking.name || booking.user_name || 'N/A';
        const guestEmail = booking.email || 'N/A';
        const guestPhone = booking.phone || 'N/A';

        // Validate and format check-in/check-out dates
        const checkinDate = booking.checkin ? new Date(booking.checkin) : null;
        const checkoutDate = booking.checkout ? new Date(booking.checkout) : null;

        // Format dates for display
        const formattedCheckin = checkinDate && !isNaN(checkinDate.getTime())
          ? checkinDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
          : 'N/A';
        const formattedCheckout = checkoutDate && !isNaN(checkoutDate.getTime())
          ? checkoutDate.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
          : 'N/A';

        // Format status for display
        let statusDisplay = booking.status.replace(/_/g, ' ');
        statusDisplay = statusDisplay.charAt(0).toUpperCase() + statusDisplay.slice(1);
        
        // Build action buttons based on status
        let actionButtons = '';
        let paymentSection = '';
        
        if (booking.status === 'pending_booking_confirmation') {
          actionButtons = `
            <button class="action-btn action-btn-success" onclick="handleBookingAction('${booking.booking_id}', 'booking_confirmed', this)">
              <i class="fas fa-check"></i>
              <span>Confirm Booking</span>
            </button>
            <button class="action-btn action-btn-danger" onclick="handleBookingRejection('${booking.booking_id}', this)">
              <i class="fas fa-times"></i>
              <span>Reject Booking</span>
            </button>
          `;

        } else if (booking.status === 'payment_under_review') {
          // Show payment details and proof prominently
          paymentSection = `
            <div class="payment-section payment-review">
              <div class="payment-header">
                <i class="fas fa-credit-card"></i>
                <h4>Payment Review Required</h4>
              </div>
              <div class="payment-details">
                <div class="payment-detail-item">
                  <label>Payment Method</label>
                  <span>${booking.payment_method ? booking.payment_method.replace(/_/g, ' ').toUpperCase() : 'N/A'}</span>
                </div>
                <div class="payment-detail-item">
                  <label>Total Amount</label>
                  <span>${booking.total_amount ? '₱' + parseFloat(booking.total_amount).toLocaleString() : 'N/A'}</span>
                </div>
                ${booking.payment_proof ? `
                  <div class="payment-proof-section">
                    <label>Payment Proof</label>
                    <a href="${booking.payment_proof}" target="_blank" class="proof-link">
                      <i class="fas fa-file-alt"></i>
                      View Proof
                    </a>
                    ${booking.payment_proof.match(/\.(jpg|jpeg|png|gif)$/i) ? `
                      <div class="proof-image">
                        <img src="${booking.payment_proof}" alt="Payment Proof">
                      </div>
                    ` : ''}
                  </div>
                ` : '<div class="no-proof"><i class="fas fa-exclamation-triangle"></i> No payment proof uploaded</div>'}
              </div>
            </div>
          `;
          actionButtons = `
            <button class="action-btn action-btn-success" onclick="verifyPayment('${booking.booking_id}', true); this.closest('.modal-overlay').remove();">
              <i class="fas fa-check-circle"></i>
              <span>Approve Payment</span>
            </button>
            <button class="action-btn action-btn-danger" onclick="verifyPayment('${booking.booking_id}', false); this.closest('.modal-overlay').remove();">
              <i class="fas fa-times-circle"></i>
              <span>Reject Payment</span>
            </button>
          `;
        } else if (booking.status === 'payment_pending') {
          paymentSection = `
            <div class="payment-section payment-pending">
              <div class="payment-header">
                <i class="fas fa-clock"></i>
                <h4>Payment Pending</h4>
              </div>
              <div class="payment-details">
                <div class="payment-detail-item">
                  <label>Payment Method</label>
                  <span>${booking.payment_method ? booking.payment_method.replace(/_/g, ' ').toUpperCase() : 'N/A'}</span>
                </div>
                <div class="payment-detail-item">
                  <label>Total Amount</label>
                  <span>${booking.total_amount ? '₱' + parseFloat(booking.total_amount).toLocaleString() : 'N/A'}</span>
                </div>
                ${booking.payment_proof ? `
                  <div class="payment-proof-section">
                    <label>Payment Proof</label>
                    <a href="${booking.payment_proof}" target="_blank" class="proof-link">
                      <i class="fas fa-file-alt"></i>
                      View Proof
                    </a>
                    ${booking.payment_proof.match(/\.(jpg|jpeg|png|gif)$/i) ? `
                      <div class="proof-image">
                        <img src="${booking.payment_proof}" alt="Payment Proof">
                      </div>
                    ` : ''}
                  </div>
                ` : '<div class="no-proof"><i class="fas fa-exclamation-triangle"></i> No payment proof uploaded</div>'}
              </div>
            </div>
          `;
          actionButtons = `
            <button class="action-btn action-btn-success" onclick="verifyPayment('${booking.booking_id}', true); this.closest('.modal-overlay').remove();">
              <i class="fas fa-money-bill-wave"></i>
              <span>Confirm Walk-in Payment</span>
            </button>
          `;
        } else if (booking.status === 'payment_confirmed') {
          paymentSection = `
            <div class="payment-section payment-confirmed">
              <div class="payment-header">
                <i class="fas fa-check-circle"></i>
                <h4>Payment Confirmed</h4>
              </div>
              <div class="payment-details">
                <div class="payment-detail-item">
                  <label>Payment Method</label>
                  <span>${booking.payment_method ? booking.payment_method.replace(/_/g, ' ').toUpperCase() : 'N/A'}</span>
                </div>
                <div class="payment-detail-item">
                  <label>Total Amount</label>
                  <span>${booking.total_amount ? '₱' + parseFloat(booking.total_amount).toLocaleString() : 'N/A'}</span>
                </div>
                ${booking.payment_proof ? `
                  <div class="payment-proof-section">
                    <label>Payment Proof</label>
                    <a href="${booking.payment_proof}" target="_blank" class="proof-link">
                      <i class="fas fa-file-alt"></i>
                      View Proof
                    </a>
                    ${booking.payment_proof.match(/\.(jpg|jpeg|png|gif)$/i) ? `
                      <div class="proof-image">
                        <img src="${booking.payment_proof}" alt="Payment Proof">
                      </div>
                    ` : ''}
                  </div>
                ` : ''}
              </div>
            </div>
          `;
          actionButtons = `
            <button class="action-btn action-btn-secondary" onclick="this.closest('.modal-overlay').remove();">
              <i class="fas fa-times"></i>
              <span>Close</span>
            </button>
          `;
        } else if (booking.status === 'arrival_notice_sent') {
          actionButtons = `
            <button class="action-btn action-btn-success" onclick="updateBookingStatus('${booking.booking_id}', 'completed'); this.closest('.modal-overlay').remove();">
              <i class="fas fa-flag-checkered"></i>
              <span>Mark as Completed</span>
            </button>
          `;
        } else if (booking.status === 'booking_confirmed') {
          console.log('Booking is confirmed, showing close button only');
          actionButtons = `
            <button class="action-btn action-btn-secondary" onclick="this.closest('.modal-overlay').remove();">
              <i class="fas fa-times"></i>
              <span>Close</span>
            </button>
            <button class="action-btn action-btn-danger" onclick="updateBookingStatus('${booking.booking_id}', 'cancelled'); this.closest('.modal-overlay').remove();">
              <i class="fas fa-times"></i>
              <span>Cancel Booking</span>
            </button>
          `;
        } else if (booking.status === 'completed') {
          actionButtons = `
            <button class="action-btn action-btn-secondary" onclick="this.closest('.modal-overlay').remove();">
              <i class="fas fa-check"></i>
              <span>Close</span>
            </button>
          `;
        } else if (booking.status === 'cancelled') {
          actionButtons = `
            <button class="action-btn action-btn-secondary" onclick="this.closest('.modal-overlay').remove();">
              <i class="fas fa-times"></i>
              <span>Close</span>
            </button>
          `;
        } else {
          // Default close button for any other status
          actionButtons = `
            <button class="action-btn action-btn-secondary" onclick="this.closest('.modal-overlay').remove();">
              <i class="fas fa-times"></i>
              <span>Close</span>
            </button>
          `;
        }

        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = `
          <div class="modal-container">
            <div class="modal-header">
              <div class="modal-header-left">
                <i class="fas fa-clipboard-check"></i>
                <h2>Booking Confirmation</h2>
              </div>
              <div class="modal-header-actions">
                <button class="btn-close" onclick="this.closest('.modal-overlay').remove()"><i class="fas fa-times"></i> Close</button>
                ${booking.status === 'pending_booking_confirmation' ? `
                <button class="btn-confirm" onclick="handleBookingAction('${booking.booking_id}', 'booking_confirmed', this)"><i class="fas fa-check"></i> Confirm</button>
                ` : booking.status === 'payment_under_review' ? `
                <button class="btn-confirm" onclick="verifyPayment('${booking.booking_id}', true); this.closest('.modal-overlay').remove();"><i class="fas fa-check"></i> Approve</button>
                ` : ''}
              </div>
            </div>
            
            <div class="modal-body">
              <div class="info-grid">
                <div class="info-card">
                  <div class="card-title"><i class="fas fa-user-circle"></i> Guest Information</div>
                  <div class="detail-row">
                    <span class="label"><i class="far fa-user"></i> Guest Name</span>
                    <span class="value">${guestName}</span>
                  </div>
                  <div class="detail-row">
                    <span class="label"><i class="far fa-envelope"></i> Email</span>
                    <span class="value" style="font-weight:500; font-size:0.9rem;">${guestEmail}</span>
                  </div>
                  <div class="detail-row">
                    <span class="label"><i class="fas fa-phone-alt"></i> Contact</span>
                    <span class="value">${guestPhone}</span>
                  </div>
                  ${booking.user_id ? `
                  <div class="detail-row">
                    <span class="label"><i class="fas fa-id-card"></i> Customer ID</span>
                    <span class="value" style="font-weight:500;">${booking.user_id}</span>
                  </div>
                  ` : ''}
                  <div class="detail-row" style="border-bottom: none; padding-bottom:0;">
                    <span class="label"><i class="fas fa-hashtag"></i> Booking Ref</span>
                    <span class="value" style="font-weight:600; color:var(--accent);">${booking.booking_id}</span>
                  </div>
                </div>

                <div class="info-card">
                  <div class="card-title"><i class="fas fa-calendar-alt"></i> Booking Details</div>
                  <div class="detail-row">
                    <span class="label"><i class="fas fa-calendar-check"></i> Check-in</span>
                    <span class="value">${formattedCheckin}</span>
                  </div>
                  <div class="detail-row">
                    <span class="label"><i class="fas fa-calendar-times"></i> Check-out</span>
                    <span class="value">${formattedCheckout}</span>
                  </div>
                  <div class="detail-row">
                    <span class="label"><i class="fas fa-users"></i> Guests</span>
                    <span class="value">${booking.guests ? (booking.adults || booking.children || booking.infants ? booking.guests + ' (' + (booking.adults || 0) + ' adults, ' + (booking.children || 0) + ' children, ' + (booking.infants || 0) + ' infants)' : booking.guests) : 'N/A'}</span>
                  </div>
                  <div class="detail-row" style="border-bottom: none; padding-bottom:0;">
                    <span class="label"><i class="fas fa-tag"></i> Total Amount</span>
                    <span class="value amount">${booking.total_amount ? '₱' + parseFloat(booking.total_amount).toLocaleString() : 'N/A'}</span>
                  </div>
                </div>
              </div>

              <div class="payment-proof-grid">
                <div class="info-card" style="margin-bottom:0;">
                  <div class="card-title"><i class="fas fa-credit-card"></i> Payment Information</div>
                  ${booking.payment_method ? `
                  <div class="detail-row">
                    <span class="label"><i class="fas fa-qrcode"></i> Payment Method</span>
                    <span class="value">${booking.payment_method.replace(/_/g, ' ').toUpperCase()}</span>
                  </div>
                  ` : ''}
                  ${booking.amount_sent ? `
                  <div class="detail-row" style="border-bottom: none; padding-bottom:0;">
                    <span class="label"><i class="fas fa-money-bill-wave"></i> Amount Sent</span>
                    <span class="value amount" style="color:var(--text-primary);">₱${parseFloat(booking.amount_sent).toLocaleString()}</span>
                  </div>
                  ` : ''}
                </div>

                ${booking.payment_proof ? `
                <div class="proof-preview">
                  <div class="proof-title"><i class="fas fa-image"></i> Payment Proof</div>
                  <div class="proof-image-wrapper" onclick="window.open('../${booking.payment_proof}', '_blank')">
                    ${booking.payment_proof.match(/\.(jpg|jpeg|png|gif)$/i) ? `
                    <img src="../${booking.payment_proof}" alt="Payment proof" />
                    ` : `
                    <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
                      <i class="fas fa-file-alt" style="font-size: 2rem;"></i>
                      <p style="margin-top: 0.5rem;">Click to view</p>
                    </div>
                    `}
                    <span class="expand-hint"><i class="fas fa-expand"></i> click to view full size</span>
                  </div>
                  <div style="font-size:0.7rem; color:var(--text-tertiary); margin-top:0.4rem; text-align:center;">
                    <i class="far fa-file-image"></i> ${booking.payment_proof.split('/').pop()}
                  </div>
                </div>
                ` : ''}
              </div>

              ${booking.review_email_status ? `
              <div class="email-status-card">
                <div class="email-status-item">
                  <span class="label"><i class="far fa-envelope"></i> Status</span>
                  <span class="value badge-status"><i class="fas fa-clock"></i> ${booking.review_email_status}</span>
                </div>
                ${booking.review_email_scheduled_at ? `
                <div class="email-status-item">
                  <span class="label"><i class="far fa-calendar-alt"></i> Scheduled</span>
                  <span class="value">${new Date(booking.review_email_scheduled_at).toLocaleString()}</span>
                </div>
                ` : ''}
                ${booking.review_email_sent_at ? `
                <div class="email-status-item">
                  <span class="label"><i class="fas fa-check-circle" style="color:var(--success);"></i> Sent</span>
                  <span class="value" style="color:var(--accent);">${new Date(booking.review_email_sent_at).toLocaleString()}</span>
                </div>
                ` : ''}
              </div>
              ` : ''}

              ${booking.checkin_reminder_status ? `
              <div class="email-status-card">
                <div class="email-status-item">
                  <span class="label"><i class="far fa-bell"></i> Check-in Reminder</span>
                  <span class="value badge-status"><i class="fas fa-clock"></i> ${booking.checkin_reminder_status}</span>
                </div>
                ${booking.checkin_reminder_scheduled_at ? `
                <div class="email-status-item">
                  <span class="label"><i class="far fa-calendar-alt"></i> Scheduled</span>
                  <span class="value">${new Date(booking.checkin_reminder_scheduled_at).toLocaleString()}</span>
                </div>
                ` : ''}
                ${booking.checkin_reminder_sent_at ? `
                <div class="email-status-item">
                  <span class="label"><i class="fas fa-check-circle" style="color:var(--success);"></i> Sent</span>
                  <span class="value" style="color:var(--accent);">${new Date(booking.checkin_reminder_sent_at).toLocaleString()}</span>
                </div>
                ` : ''}
              </div>
              ` : ''}

              ${booking.requests ? `
              <div class="info-card" style="margin-top: 1.8rem;">
                <div class="card-title"><i class="fas fa-comment-alt"></i> Special Requests</div>
                <p style="color: var(--text-secondary); line-height: 1.6;">${booking.requests}</p>
              </div>
              ` : ''}

              <div style="margin-top:0.8rem; font-size:0.75rem; color:var(--text-tertiary); text-align:right; border-top:1px solid var(--border); padding-top:0.6rem;">
                <i class="far fa-clock"></i> ${statusDisplay} · ${booking.booking_id}
              </div>
            </div>
          </div>
        `;
        document.body.appendChild(modal);
        
        if (booking.status === 'pending_booking_confirmation') {
          markBookingNotificationAsRead(booking.booking_id, 'new_booking');
        }
      })
      .catch(error => console.error('Error loading booking:', error));
    };

    // Open rebook modal
    window.openRebookModal = function(bookingId, currentCheckin, currentCheckout, event) {
      const modal = document.createElement('div');
      modal.className = 'modal-overlay';
      modal.innerHTML = `
        <div class="modal-container">
          <div class="modal-header">
            <div class="modal-header-left">
              <i class="fas fa-calendar-alt"></i>
              <h2>Rebook Booking</h2>
            </div>
            <div class="modal-header-actions">
              <button class="btn-close" onclick="this.closest('.modal-overlay').remove()"><i class="fas fa-times"></i> Close</button>
            </div>
          </div>
          
          <div class="modal-body">
            <div class="info-card">
              <div class="card-title"><i class="fas fa-info-circle"></i> Current Booking Dates</div>
              <div class="detail-row">
                <span class="label">Booking ID</span>
                <span class="value">${bookingId}</span>
              </div>
              <div class="detail-row">
                <span class="label">Current Check-in</span>
                <span class="value">${new Date(currentCheckin).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}</span>
              </div>
              <div class="detail-row" style="border-bottom: none; padding-bottom:0;">
                <span class="label">Current Check-out</span>
                <span class="value">${new Date(currentCheckout).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}</span>
              </div>
            </div>

            <div class="info-card" style="margin-top: 1.5rem;">
              <div class="card-title"><i class="fas fa-calendar-check"></i> New Booking Dates</div>
              <div class="detail-row">
                <span class="label">New Check-in Date</span>
                <input type="date" id="newCheckin" style="flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:0.9rem;background:var(--bg);color:var(--text-primary);" required>
              </div>
              <div class="detail-row" style="border-bottom: none; padding-bottom:0;">
                <span class="label">New Check-out Date</span>
                <input type="date" id="newCheckout" style="flex:1;padding:8px 12px;border:1px solid var(--border);border-radius:6px;font-size:0.9rem;background:var(--bg);color:var(--text-primary);" required>
              </div>
            </div>

            <div style="margin-top: 1.5rem; color: var(--text-secondary); font-size: 0.85rem; line-height: 1.5;">
              <i class="fas fa-info-circle"></i> Note: Rebooking will update the booking dates and the calendar availability will be automatically updated.
            </div>
          </div>

          <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:1rem 1.5rem;border-top:1px solid var(--border);background:var(--bg-secondary);">
            <button class="action-btn action-btn-success" onclick="processRebook('${bookingId}', event)">
              <i class="fas fa-check"></i>
              <span>Confirm Rebook</span>
            </button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);

      // Set minimum dates (fix timezone issue)
      const today = new Date();
      const year = today.getFullYear();
      const month = String(today.getMonth() + 1).padStart(2, '0');
      const day = String(today.getDate()).padStart(2, '0');
      const todayStr = `${year}-${month}-${day}`;
      document.getElementById('newCheckin').min = todayStr;
      document.getElementById('newCheckout').min = todayStr;

      // Set current dates as placeholders
      document.getElementById('newCheckin').value = currentCheckin;
      document.getElementById('newCheckout').value = currentCheckout;
    };

    // Process rebook
    window.processRebook = function(bookingId, event) {
      const newCheckin = document.getElementById('newCheckin').value;
      const newCheckout = document.getElementById('newCheckout').value;

      if (!newCheckin || !newCheckout) {
        showToast('Please select both check-in and check-out dates');
        return;
      }

      if (new Date(newCheckin) >= new Date(newCheckout)) {
        showToast('Check-out date must be after check-in date');
        return;
      }

      // Get the rebook button and add loading state
      const rebookBtn = event.currentTarget;
      const originalText = rebookBtn.innerHTML;
      rebookBtn.disabled = true;
      rebookBtn.innerHTML = '<i class="fas fa-spinner fa-spin" style="margin-right:8px;"></i> Processing...';
      rebookBtn.style.opacity = '0.9';
      rebookBtn.style.transform = 'scale(0.98)';

      const formData = new FormData();
      formData.append('action', 'rebook_booking');
      formData.append('booking_id', bookingId);
      formData.append('new_checkin', newCheckin);
      formData.append('new_checkout', newCheckout);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast(data.message || 'Booking rebooked successfully');
          document.querySelector('.modal-overlay').remove();
          refreshBookings(bookingsState.currentPage);
          // Refresh calendar to show updated booking dates
          renderCalendar();
        } else {
          showToast(data.message || 'Failed to rebook booking');
          // Reset button state on error
          rebookBtn.disabled = false;
          rebookBtn.innerHTML = originalText;
          rebookBtn.style.opacity = '1';
          rebookBtn.style.transform = 'scale(1)';
        }
      })
      .catch(error => {
        console.error('Error rebooking:', error);
        showToast('Error rebooking booking');
        // Reset button state on error
        rebookBtn.disabled = false;
        rebookBtn.innerHTML = originalText;
        rebookBtn.style.opacity = '1';
        rebookBtn.style.transform = 'scale(1)';
      });
    };


    // Confirm action with dialog
    window.confirmAction = function(action, bookingId) {
      let message = '';
      let confirmText = 'Confirm';
      let cancelText = 'Cancel';
      
      switch(action) {
        case 'confirm_booking':
          message = 'Are you sure you want to confirm this booking? This will notify the guest that their reservation is confirmed.';
          break;
        case 'cancel_booking':
          message = 'Are you sure you want to cancel this booking? This action cannot be undone and will notify the guest.';
          confirmText = 'Yes, Cancel Booking';
          break;
        case 'approve_payment':
          message = 'Are you sure you want to approve this payment? The booking will proceed to the next stage.';
          confirmText = 'Yes, Approve';
          break;
        case 'reject_payment':
          message = 'Are you sure you want to reject this payment? The guest will need to submit a new payment.';
          confirmText = 'Yes, Reject';
          break;
        case 'confirm_walkin':
          message = 'Confirm that walk-in payment has been received?';
          break;
        case 'complete_booking':
          message = 'Mark this booking as completed? This will finalize the stay.';
          break;
        case 'request_payment':
          message = 'Send payment request to the guest?';
          break;
        default:
          message = 'Are you sure you want to perform this action?';
      }
      
      const modal = document.createElement('div');
      modal.className = 'modal-overlay';
      modal.innerHTML = `
        <div class="modal confirm-modal">
          <div class="confirm-icon">
            <i class="fas fa-exclamation-circle"></i>
          </div>
          <h3>Confirm Action</h3>
          <p>${message}</p>
          <div class="confirm-actions">
            <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">${cancelText}</button>
            <button class="btn btn-primary" onclick="executeAction('${action}', '${bookingId}', this)">${confirmText}</button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    };
    
    // Execute confirmed action
    window.executeAction = function(action, bookingId, button) {
      button.closest('.modal-overlay').remove();
      
      switch(action) {
        case 'confirm_booking':
          updateBookingStatus(bookingId, 'booking_confirmed');
          break;
        case 'cancel_booking':
          updateBookingStatus(bookingId, 'cancelled');
          break;
        case 'approve_payment':
          verifyPayment(bookingId, true);
          break;
        case 'reject_payment':
          verifyPayment(bookingId, false);
          break;
        case 'confirm_walkin':
          verifyPayment(bookingId, true);
          break;
        case 'complete_booking':
          updateBookingStatus(bookingId, 'completed');
          break;
        case 'request_payment':
          updateBookingStatus(bookingId, 'payment_pending');
          break;
      }
      
      // Close booking modal if open
      const bookingModal = document.querySelector('.booking-modal');
      if (bookingModal) {
        bookingModal.closest('.modal-overlay').remove();
      }
    };
    
    // Handle booking action (confirm/reject)
    window.handleBookingAction = function(bookingId, status, buttonElement) {
      const modal = buttonElement ? buttonElement.closest('.modal-overlay') : null;

      if (buttonElement && buttonElement.dataset.processing === 'true') {
        return;
      }

      if (!confirm('Are you sure you want to ' + (status === 'booking_confirmed' ? 'confirm' : status) + ' this booking?')) {
        return;
      }

      if (modal) {
        modal.querySelectorAll('.btn-confirm, .action-btn-success, .action-btn-danger').forEach(btn => {
          btn.disabled = true;
        });
      }

      if (buttonElement) {
        buttonElement.dataset.processing = 'true';
        buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
      }
      
      const formData = new FormData();
      formData.append('action', 'update_booking_status');
      formData.append('booking_id', bookingId);
      formData.append('status', status);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast(data.message || 'Booking updated successfully');
          refreshBookings(bookingsState.currentPage);
          refreshUsers();
          updateDashboardStats();
          loadAdminNotifications();
          updateAdminNotificationBadge();
          if (modal) {
            modal.remove();
          }
        } else {
          showToast(data.message || 'Failed to update booking status');
          if (modal) {
            modal.querySelectorAll('.btn-confirm, .action-btn-success, .action-btn-danger').forEach(btn => {
              btn.disabled = false;
            });
          }
          if (buttonElement) {
            buttonElement.dataset.processing = 'false';
            buttonElement.innerHTML = status === 'booking_confirmed'
              ? '<i class="fas fa-check"></i> Confirm'
              : buttonElement.innerHTML;
          }
        }
      })
      .catch(error => {
        console.error('Error updating booking:', error);
        showToast('Error updating booking');
        if (modal) {
          modal.querySelectorAll('.btn-confirm, .action-btn-success, .action-btn-danger').forEach(btn => {
            btn.disabled = false;
          });
        }
        if (buttonElement) {
          buttonElement.dataset.processing = 'false';
        }
      });
    };
    
    // Handle booking rejection
    window.handleBookingRejection = function(bookingId, buttonElement) {
      const modal = buttonElement ? buttonElement.closest('.modal-overlay') : null;
      const reason = prompt('Rejection reason (optional):');
      if (reason === null) return;

      if (buttonElement && buttonElement.dataset.processing === 'true') {
        return;
      }
      
      if (!confirm('Are you sure you want to reject this booking?')) {
        return;
      }

      if (modal) {
        modal.querySelectorAll('.btn-confirm, .action-btn-success, .action-btn-danger').forEach(btn => {
          btn.disabled = true;
        });
      }

      if (buttonElement) {
        buttonElement.dataset.processing = 'true';
        buttonElement.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
      }
      
      const formData = new FormData();
      formData.append('action', 'reject_booking');
      formData.append('booking_id', bookingId);
      formData.append('rejection_reason', reason);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast(data.message || 'Booking rejected successfully');
          refreshBookings(bookingsState.currentPage);
          refreshUsers();
          updateDashboardStats();
          loadAdminNotifications();
          updateAdminNotificationBadge();
          if (modal) {
            modal.remove();
          }
        } else {
          showToast(data.message || 'Failed to reject booking');
          if (modal) {
            modal.querySelectorAll('.btn-confirm, .action-btn-success, .action-btn-danger').forEach(btn => {
              btn.disabled = false;
            });
          }
          if (buttonElement) {
            buttonElement.dataset.processing = 'false';
          }
        }
      })
      .catch(error => {
        console.error('Error rejecting booking:', error);
        showToast('Error rejecting booking');
        if (buttonElement) {
          buttonElement.dataset.processing = 'false';
        }
      });
    };
    
    // Update modal to show new status
    window.updateModalStatus = function(buttonElement, newStatus) {
      const modal = buttonElement.closest('.modal-overlay');
      if (!modal) return;
      
      console.log('Updating modal status to:', newStatus);
      
      const statusBadge = modal.querySelector('.status-badge');
      const actionButtonsContainer = modal.querySelector('.modal-actions-buttons');
      
      console.log('Status badge found:', !!statusBadge);
      console.log('Action buttons container found:', !!actionButtonsContainer);
      
      // Update status badge
      if (statusBadge) {
        statusBadge.className = `status-badge status-${newStatus}`;
        let statusDisplay = newStatus.replace(/_/g, ' ');
        statusDisplay = statusDisplay.charAt(0).toUpperCase() + statusDisplay.slice(1);
        statusBadge.textContent = statusDisplay;
        console.log('Status badge updated to:', statusDisplay);
      }
      
      // Replace action buttons with close button
      if (actionButtonsContainer) {
        console.log('Replacing action buttons with close button');
        actionButtonsContainer.innerHTML = `
          <button class="action-btn action-btn-secondary" onclick="this.closest('.modal-overlay').remove();">
            <i class="fas fa-times"></i>
            <span>Close</span>
          </button>
        `;
      }
      
      // Also check for any other action buttons that might exist
      const allActionButtons = modal.querySelectorAll('.modal-actions-buttons');
      console.log('Total action button containers found:', allActionButtons.length);
      allActionButtons.forEach((container, index) => {
        if (index === 0) {
          // First one already updated
          return;
        }
        container.innerHTML = `
          <button class="action-btn action-btn-secondary" onclick="this.closest('.modal-overlay').remove();">
            <i class="fas fa-times"></i>
            <span>Close</span>
          </button>
        `;
      });
    };

    // Update booking status
    window.updateBookingStatus = function(id, status) {
      const formData = new FormData();
      formData.append('action', 'update_booking_status');
      formData.append('booking_id', id);
      formData.append('status', status);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          refreshBookings(bookingsState.currentPage);
          updateDashboardStats();
          updateAdminNotificationBadge();
          showToast(`Booking ${id} ${status}`);
        } else {
          showToast('Failed to update booking status');
        }
      })
      .catch(error => {
        console.error('Error updating booking:', error);
        showToast('Error updating booking');
      });
    };

    // Resend review email
    window.resendReviewEmail = function(bookingId) {
      if (!confirm('Are you sure you want to resend the Google review email for this booking?')) {
        return;
      }
      
      const formData = new FormData();
      formData.append('action', 'resend_review_email');
      formData.append('booking_id', bookingId);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast(data.message || 'Review email sent successfully');
          refreshBookings(bookingsState.currentPage);
          // Close modal to refresh the review email status
          const modal = document.querySelector('.booking-modal');
          if (modal) {
            modal.closest('.modal-overlay').remove();
          }
        } else {
          showToast(data.message || 'Failed to send review email');
        }
      })
      .catch(error => {
        console.error('Error resending review email:', error);
        showToast('Error resending review email');
      });
    };

    // Toggle booking menu dropdown
    window.toggleBookingMenu = function(bookingId) {
      const menu = document.getElementById(`bookingMenu-${bookingId}`);
      if (menu) {
        const isVisible = menu.style.display === 'block';
        // Close all other menus first
        document.querySelectorAll('.dropdown-menu').forEach(m => m.style.display = 'none');
        // Toggle current menu
        menu.style.display = isVisible ? 'none' : 'block';
        
        // Add click-outside-to-close handler
        if (!isVisible) {
          setTimeout(() => {
            document.addEventListener('click', function closeMenu(e) {
              if (!menu.contains(e.target) && !e.target.closest(`[onclick="toggleBookingMenu('${bookingId}')"]`)) {
                menu.style.display = 'none';
                document.removeEventListener('click', closeMenu);
              }
            });
          }, 0);
        }
      }
    };
    
    // Delete booking
    window.deleteBooking = function(id) {
      // Close the menu
      const menu = document.getElementById(`bookingMenu-${id}`);
      if (menu) menu.style.display = 'none';
      
      if (!confirm('Are you sure you want to delete this booking? This action cannot be undone.')) {
        return;
      }

      const formData = new FormData();
      formData.append('action', 'delete_booking');
      formData.append('booking_id', id);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          refreshBookings(bookingsState.currentPage);
          updateDashboardStats();
          showToast(`Booking ${id} deleted`);
        } else {
          showToast(data.message || 'Failed to delete booking');
        }
      })
      .catch(error => {
        console.error('Error deleting booking:', error);
        showToast('Error deleting booking');
      });
    };

    // Verify payment
    window.verifyPayment = function(bookingId, verified) {
      const formData = new FormData();
      formData.append('action', 'verify_payment');
      formData.append('booking_id', bookingId);
      formData.append('verified', verified);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          refreshBookings(bookingsState.currentPage);
          updateDashboardStats();
          updateAdminNotificationBadge();
          showToast(data.message);
          

        } else {
          showToast(data.message || 'Failed to verify payment');
        }
      })
      .catch(error => {
        console.error('Error verifying payment:', error);
        showToast('Error verifying payment');
      });
    };

    // Calendar state
    let calendarDate = new Date();
    let selectedDates = [];
    let calendarBookings = [];
    let blockedDates = [];
    let maintenanceDates = [];

    // Initialize calendar
    window.initCalendar = function() {
      renderCalendar();
    };

    // Render calendar for current month
    window.renderCalendar = function() {
      const year = calendarDate.getFullYear();
      const month = calendarDate.getMonth();
      
      // Update month display
      document.getElementById('currentMonth').textContent = 
        new Date(year, month).toLocaleDateString('en-US', { month: 'long', year: 'numeric' });
      
      // Fetch calendar data
      fetchCalendarData(year, month);
    };

    // Fetch calendar data from backend
    window.fetchCalendarData = function(year, month) {
      const startDate = new Date(year, month, 1).toISOString().split('T')[0];
      const endDate = new Date(year, month + 1, 0).toISOString().split('T')[0];
      
      console.log('Fetching calendar data for:', startDate, 'to', endDate);
      
      fetch(`Admin.php?action=get_calendar_data&start_date=${startDate}&end_date=${endDate}`)
      .then(response => response.json())
      .then(data => {
        console.log('Calendar data response:', data);
        if (data.success) {
          calendarBookings = data.bookings || [];
          blockedDates = data.blocked_dates || [];
          maintenanceDates = data.maintenance_dates || [];
          console.log('Bookings loaded:', calendarBookings.length);
          console.log('Blocked dates:', blockedDates.length);
          console.log('Maintenance dates:', maintenanceDates.length);
          renderCalendarGrid(year, month);
          updateCalendarStats();
        } else {
          console.error('Failed to load calendar data:', data.message);
        }
      })
      .catch(error => {
        console.error('Error loading calendar data:', error);
      });
    };

    // Render calendar grid
    window.renderCalendarGrid = function(year, month) {
      const grid = document.getElementById('calendarGrid');
      const today = new Date();
      today.setHours(0, 0, 0, 0);
      
      console.log('Rendering calendar grid for:', year, month);
      console.log('Calendar bookings:', calendarBookings);
      
      grid.innerHTML = '';
      
      // Get first day of month and total days
      const firstDay = new Date(year, month, 1).getDay();
      const daysInMonth = new Date(year, month + 1, 0).getDate();
      const daysInPrevMonth = new Date(year, month, 0).getDate();
      
      // Previous month days
      for (let i = firstDay - 1; i >= 0; i--) {
        const day = daysInPrevMonth - i;
        const date = new Date(year, month - 1, day);
        const dayEl = createDayElement(day, 'prev-month', date);
        grid.appendChild(dayEl);
      }
      
      // Current month days
      for (let day = 1; day <= daysInMonth; day++) {
        const date = new Date(year, month, day);
        const dateStr = date.toISOString().split('T')[0];
        
        // Check date status
        let className = 'current-month';
        let currentBooking = null;
        
        if (date < today) {
          className += ' disabled';
        }
        
        // Check if maintenance
        if (maintenanceDates.includes(dateStr)) {
          className += ' maintenance';
        }
        // Check if booked
        else {
          const booking = calendarBookings.find(b => {
            const checkin = new Date(b.checkin);
            const checkout = new Date(b.checkout);
            const current = new Date(date);
            current.setHours(0, 0, 0, 0);
            checkin.setHours(0, 0, 0, 0);
            checkout.setHours(0, 0, 0, 0);
            return current >= checkin && current <= checkout;
          });
          if (booking) {
            className += ' booked';
            currentBooking = booking;
            
            // Check if check-in date
            const checkinDate = new Date(booking.checkin).toISOString().split('T')[0];
            if (dateStr === checkinDate) {
              className += ' checkin';
            }
            
            // Check if check-out date
            const checkoutDate = new Date(booking.checkout).toISOString().split('T')[0];
            if (dateStr === checkoutDate) {
              className += ' checkout';
            }
          }
        }
        
        if (selectedDates.includes(dateStr)) {
          className += ' selected';
        }
        
        const dayEl = createDayElement(day, className, date, dateStr, currentBooking);
        grid.appendChild(dayEl);
      }
      
      // Next month days
      const totalCells = firstDay + daysInMonth;
      const remainingCells = 42 - totalCells; // 6 rows x 7 days
      for (let i = 1; i <= remainingCells; i++) {
        const date = new Date(year, month + 1, i);
        const dayEl = createDayElement(i, 'next-month', date);
        grid.appendChild(dayEl);
      }
    };

    // Create day element
    window.createDayElement = function(day, className, date, dateStr = null, booking = null) {
      const el = document.createElement('div');
      el.className = `calendar-day ${className}`;
      el.textContent = day;
      el.dataset.date = date.toISOString();
      
      // Add visual indicator for selected dates
      if (className.includes('selected')) {
        const indicator = document.createElement('span');
        indicator.className = 'day-indicator selected-indicator';
        indicator.innerHTML = '<i class="fas fa-check"></i>';
        indicator.style.cssText = 'position: absolute; top: 2px; right: 2px; font-size: 10px; color: var(--success); background: rgba(16, 185, 129, 0.2); border-radius: 50%; width: 18px; height: 18px; display: flex; align-items: center; justify-content: center;';
        el.appendChild(indicator);
      }
      
      // Force pointer cursor on maintenance dates
      if (className.includes('maintenance')) {
        el.style.cursor = 'pointer';
      }
      
      // Add click handler for available dates only (exclude maintenance dates - they have their own handler)
      if (!className.includes('disabled') && !className.includes('booked') && !className.includes('blocked') && !className.includes('maintenance') && !className.includes('prev-month') && !className.includes('next-month')) {
        el.addEventListener('click', () => {
          if (dateStr) {
            toggleDateSelection(dateStr);
          }
        });
      }
      
      // Add click handler for booked dates to view details
      if (className.includes('booked') && booking) {
        el.addEventListener('click', () => showBookingDetailsModal(booking));

        // Validate and format dates for tooltip
        const checkinDateObj = booking.checkin ? new Date(booking.checkin) : null;
        const checkoutDateObj = booking.checkout ? new Date(booking.checkout) : null;

        const checkinDateStr = checkinDateObj && !isNaN(checkinDateObj.getTime())
          ? checkinDateObj.toLocaleDateString()
          : 'N/A';
        const checkoutDateStr = checkoutDateObj && !isNaN(checkoutDateObj.getTime())
          ? checkoutDateObj.toLocaleDateString()
          : 'N/A';

        el.title = `Guest: ${booking.name}\nCheck-in: ${checkinDateStr}\nCheck-out: ${checkoutDateStr}`;
      }
      
      // Add click handler for maintenance dates to allow selection
      if (className.includes('maintenance') && dateStr) {
        el.addEventListener('click', () => {
          toggleDateSelection(dateStr);
        });
        el.addEventListener('dblclick', () => {
          showMaintenanceDetails(dateStr);
        });
        el.title = 'Maintenance scheduled - Click to select, double-click to view details';
      }
      
      return el;
    };

    // Toggle date selection
    window.toggleDateSelection = function(dateStr) {
      const index = selectedDates.indexOf(dateStr);
      if (index > -1) {
        selectedDates.splice(index, 1);
      } else {
        selectedDates.push(dateStr);
      }
      
      renderCalendarGrid(calendarDate.getFullYear(), calendarDate.getMonth());
      updateSelectionInfo();
      updateActionButtons();
    };

    // Update selection info
    window.updateSelectionInfo = function() {
      const infoEl = document.getElementById('selectionInfo');
      if (selectedDates.length === 0) {
        infoEl.textContent = 'No dates selected';
      } else {
        const sorted = [...selectedDates].sort();
        if (sorted.length === 1) {
          infoEl.textContent = `Selected: ${sorted[0]}`;
        } else {
          infoEl.textContent = `Selected: ${sorted[0]} to ${sorted[sorted.length - 1]}`;
        }
      }
    };

    // Update action buttons state
    window.updateActionButtons = function() {
      const setMaintenanceBtn = document.getElementById('setMaintenanceBtn');
      const removeMaintenanceBtn = document.getElementById('removeMaintenanceBtn');
      const viewDetailsBtn = document.getElementById('viewDetailsBtn');
      
      if (!setMaintenanceBtn || !removeMaintenanceBtn || !viewDetailsBtn) return;
      
      console.log('updateActionButtons called');
      console.log('selectedDates:', selectedDates);
      console.log('maintenanceDates:', maintenanceDates);
      
      if (selectedDates.length === 0) {
        setMaintenanceBtn.disabled = true;
        removeMaintenanceBtn.disabled = true;
        viewDetailsBtn.disabled = true;
        document.getElementById('selectionInfo').textContent = 'No dates selected';
        return;
      }
      
      document.getElementById('selectionInfo').textContent = `${selectedDates.length} date(s) selected`;
      
      const hasBooked = selectedDates.some(d => {
        return calendarBookings.find(b => {
          const checkin = new Date(b.checkin);
          const checkout = new Date(b.checkout);
          const current = new Date(d);
          return current >= checkin && current <= checkout;
        });
      });
      const hasMaintenance = selectedDates.some(d => maintenanceDates.includes(d));
      const hasNonMaintenance = selectedDates.some(d => !maintenanceDates.includes(d));
      
      console.log('hasMaintenance:', hasMaintenance);
      console.log('hasBooked:', hasBooked);
      console.log('hasNonMaintenance:', hasNonMaintenance);
      
      // Set Maintenance: enabled when selected dates include non-maintenance dates
      setMaintenanceBtn.disabled = !hasNonMaintenance;
      
      // Remove Maintenance: enabled when selected dates include maintenance dates
      removeMaintenanceBtn.disabled = !hasMaintenance;
      
      // View Details: enabled when selected dates include booked dates
      viewDetailsBtn.disabled = !hasBooked;
      
      console.log('setMaintenanceBtn.disabled:', setMaintenanceBtn.disabled);
      console.log('removeMaintenanceBtn.disabled:', removeMaintenanceBtn.disabled);
      console.log('viewDetailsBtn.disabled:', viewDetailsBtn.disabled);
    };

    // Update calendar stats
    window.updateCalendarStats = function() {
      document.getElementById('bookingsCount').textContent = calendarBookings.length;
      document.getElementById('blockedCount').textContent = blockedDates.length;
      document.getElementById('maintenanceCount').textContent = maintenanceDates.length;
    };

    // Navigation functions
    window.prevMonth = function() {
      calendarDate.setMonth(calendarDate.getMonth() - 1);
      selectedDates = [];
      renderCalendar();
    };

    window.nextMonth = function() {
      calendarDate.setMonth(calendarDate.getMonth() + 1);
      selectedDates = [];
      renderCalendar();
    };

    window.goToToday = function() {
      calendarDate = new Date();
      selectedDates = [];
      renderCalendar();
    };

    // Block dates
    window.blockDates = function() {
      if (selectedDates.length === 0) return;
      
      const formData = new FormData();
      formData.append('action', 'block_dates');
      selectedDates.forEach(date => formData.append('dates[]', date));
      
      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast('Dates blocked successfully');
          selectedDates = [];
          renderCalendar();
        } else {
          showToast(data.message || 'Failed to block dates');
        }
      })
      .catch(error => {
        console.error('Error blocking dates:', error);
        showToast('Error blocking dates');
      });
    };

    // Unblock dates
    window.unblockDates = function() {
      if (selectedDates.length === 0) return;
      
      const formData = new FormData();
      formData.append('action', 'unblock_dates');
      selectedDates.forEach(date => formData.append('dates[]', date));
      
      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          showToast('Dates unblocked successfully');
          selectedDates = [];
          renderCalendar();
        } else {
          showToast(data.message || 'Failed to unblock dates');
        }
      })
      .catch(error => {
        console.error('Error unblocking dates:', error);
        showToast('Error unblocking dates');
      });
    };
    
    // Set maintenance dates
    window.setMaintenance = function() {
      console.log('setMaintenance called');
      console.log('selectedDates:', selectedDates);
      
      if (selectedDates.length === 0) {
        showToast('Please select dates first');
        return;
      }
      
      // Show maintenance modal
      const modal = document.createElement('div');
      modal.className = 'modal-overlay';
      modal.innerHTML = `
        <div class="modal" style="max-width: 500px;">
          <div class="modal-header">
            <h3>🔧 Set Maintenance</h3>
            <button class="close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
          </div>
          <div class="modal-body">
            <p style="margin-bottom: 16px; color: var(--text-secondary);">
              Setting maintenance for <strong>${selectedDates.length} date(s)</strong>:
            </p>
            <div style="background: var(--bg); padding: 12px; border-radius: 8px; margin-bottom: 16px; max-height: 120px; overflow-y: auto;">
              ${selectedDates.sort().map(d => `<div style="padding: 4px 0; color: var(--text-primary);">${new Date(d).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}</div>`).join('')}
            </div>
            <div class="form-group">
              <label style="display: block; margin-bottom: 8px; color: var(--text-primary); font-weight: 500;">Reason (Optional)</label>
              <textarea id="maintenanceReason" rows="3" placeholder="e.g., Pool maintenance, AC repair, etc." style="width: 100%; padding: 10px; background: var(--bg); border: 1px solid var(--border); border-radius: 8px; color: var(--text-primary); resize: vertical;"></textarea>
            </div>
          </div>
          <div class="modal-actions">
            <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
            <button class="btn btn-primary" onclick="confirmSetMaintenance(this)">Set Maintenance</button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    };
    
    // Confirm set maintenance from modal
    window.confirmSetMaintenance = function(button) {
      const reason = document.getElementById('maintenanceReason').value;
      const modal = button.closest('.modal-overlay');
      
      console.log('Setting maintenance for dates:', selectedDates);
      console.log('Reason:', reason);
      
      showToast('Setting maintenance dates...');
      
      const addPromises = selectedDates.map(date => {
        const formData = new FormData();
        formData.append('action', 'add_maintenance_date');
        formData.append('date', date);
        formData.append('reason', reason || '');
        
        console.log(`Sending request for date: ${date}`);
        
        return fetch('Admin.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          console.log(`Response for ${date}:`, data);
          if (data.success) {
            console.log(`Maintenance set for ${date}`);
            return { success: true, date };
          } else {
            console.error(`Failed to set maintenance for ${date}:`, data.message);
            return { success: false, date, error: data.message };
          }
        })
        .catch(error => {
          console.error('Error setting maintenance:', error);
          return { success: false, date, error: error.message };
        });
      });
      
      Promise.all(addPromises).then(results => {
        console.log('All promises resolved:', results);
        const successful = results.filter(r => r.success).length;
        const failed = results.filter(r => !r.success).length;
        
        modal.remove();
        
        if (failed === 0) {
          showToast(`Successfully set maintenance for ${successful} date(s)`);
        } else {
          showToast(`Set maintenance for ${successful} date(s), ${failed} failed`);
        }
        
        selectedDates = [];
        renderCalendar();
      });
    };
    
    // Remove maintenance dates
    window.removeMaintenance = function() {
      console.log('removeMaintenance called');
      console.log('selectedDates:', selectedDates);
      console.log('maintenanceDates:', maintenanceDates);
      
      if (selectedDates.length === 0) {
        showToast('Please select dates to remove maintenance from');
        return;
      }
      
      // Filter to only maintenance dates
      const maintenanceDatesSelected = selectedDates.filter(d => maintenanceDates.includes(d));
      
      if (maintenanceDatesSelected.length === 0) {
        showToast('No maintenance dates selected. Please select maintenance dates (highlighted in yellow/orange).');
        return;
      }
      
      // Show remove maintenance modal
      const modal = document.createElement('div');
      modal.className = 'modal-overlay';
      modal.innerHTML = `
        <div class="modal" style="max-width: 500px;">
          <div class="modal-header">
            <h3>🔧 Remove Maintenance</h3>
            <button class="close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
          </div>
          <div class="modal-body">
            <p style="margin-bottom: 16px; color: var(--text-secondary);">
              Are you sure you want to remove maintenance from <strong>${maintenanceDatesSelected.length} date(s)</strong>?
            </p>
            <div style="background: var(--bg); padding: 12px; border-radius: 8px; margin-bottom: 16px; max-height: 120px; overflow-y: auto;">
              ${maintenanceDatesSelected.sort().map(d => `<div style="padding: 4px 0; color: var(--text-primary);">${new Date(d).toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })}</div>`).join('')}
            </div>
            <p style="color: var(--text-secondary); font-size: 13px;">
              <i class="fas fa-info-circle"></i> These dates will become available for booking again.
            </p>
          </div>
          <div class="modal-actions">
            <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Cancel</button>
            <button class="btn btn-danger" onclick="confirmRemoveMaintenance(this)">Remove Maintenance</button>
          </div>
        </div>
      `;
      document.body.appendChild(modal);
    };
    
    // Confirm remove maintenance from modal
    window.confirmRemoveMaintenance = function(button) {
      const modal = button.closest('.modal-overlay');
      
      // Filter to only maintenance dates
      const maintenanceDatesSelected = selectedDates.filter(d => maintenanceDates.includes(d));
      
      showToast('Removing maintenance dates...');
      
      // Process each date sequentially
      let processed = 0;
      maintenanceDatesSelected.forEach((date, index) => {
        const formData = new FormData();
        formData.append('action', 'remove_maintenance_date');
        formData.append('date', date);
        
        console.log(`Removing maintenance for date: ${date}`);
        
        fetch('Admin.php', {
          method: 'POST',
          body: formData
        })
        .then(response => response.json())
        .then(data => {
          console.log('Response for', date, ':', data);
          processed++;
          
          if (data.success) {
            console.log(`Maintenance removed from ${date}`);
          } else {
            console.error(`Failed to remove maintenance from ${date}:`, data.message);
          }
          
          // When all dates are processed, refresh calendar
          if (processed === maintenanceDatesSelected.length) {
            modal.remove();
            showToast('Maintenance dates removed successfully');
            selectedDates = [];
            renderCalendar();
          }
        })
        .catch(error => {
          console.error('Error removing maintenance:', error);
          processed++;
          
          if (processed === maintenanceDatesSelected.length) {
            modal.remove();
            showToast('Some maintenance dates could not be removed');
            selectedDates = [];
            renderCalendar();
          }
        });
      });
    };

    // Show maintenance details modal
    window.showMaintenanceDetails = function(dateStr) {
      const date = new Date(dateStr);
      const formattedDate = date.toLocaleDateString('en-US', { weekday: 'long', month: 'long', day: 'numeric', year: 'numeric' });
      
      // Fetch maintenance reason from database
      fetch(`Admin.php?action=get_maintenance_details&date=${dateStr}`)
      .then(response => response.json())
      .then(data => {
        const reason = data.success && data.reason ? data.reason : 'No reason specified';
        
        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = `
          <div class="modal" style="max-width: 450px;">
            <div class="modal-header">
              <h3>🔧 Maintenance Details</h3>
              <button class="close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            </div>
            <div class="modal-body">
              <div style="background: var(--bg); padding: 16px; border-radius: 8px; margin-bottom: 16px;">
                <label style="display: block; color: var(--text-secondary); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 4px;">Date</label>
                <span style="color: var(--text-primary); font-size: 16px; font-weight: 600;">${formattedDate}</span>
              </div>
              <div style="margin-bottom: 20px;">
                <label style="display: block; color: var(--text-secondary); font-size: 11px; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 8px;">Reason</label>
                <p style="color: var(--text-primary); line-height: 1.5;">${reason}</p>
              </div>
              <p style="color: var(--text-secondary); font-size: 13px;">
                <i class="fas fa-info-circle"></i> This date is currently blocked for bookings due to maintenance.
              </p>
            </div>
            <div class="modal-actions">
              <button class="btn btn-secondary" onclick="this.closest('.modal-overlay').remove()">Close</button>
              <button class="btn btn-danger" onclick="removeSingleMaintenance('${dateStr}', this)">Remove Maintenance</button>
            </div>
          </div>
        `;
        document.body.appendChild(modal);
      })
      .catch(error => {
        console.error('Error fetching maintenance details:', error);
        showToast('Error loading maintenance details');
      });
    };
    
    // Remove maintenance from a single date
    window.removeSingleMaintenance = function(dateStr, button) {
      const modal = button.closest('.modal-overlay');
      
      if (!confirm('Are you sure you want to remove maintenance from this date?')) {
        return;
      }
      
      const formData = new FormData();
      formData.append('action', 'remove_maintenance_date');
      formData.append('date', dateStr);
      
      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          modal.remove();
          showToast('Maintenance removed successfully');
          renderCalendar();
        } else {
          showToast(data.message || 'Failed to remove maintenance');
        }
      })
      .catch(error => {
        console.error('Error removing maintenance:', error);
        showToast('Error removing maintenance');
      });
    };
    window.viewBookingDetails = function(bookingId) {
      if (bookingId) {
        viewBooking(bookingId);
      } else {
        // Find booking from selected dates
        const booking = calendarBookings.find(b => {
          return selectedDates.some(d => {
            const checkin = new Date(b.checkin);
            const checkout = new Date(b.checkout);
            const current = new Date(d);
            return current >= checkin && current < checkout;
          });
        });
        if (booking) {
          viewBooking(booking.booking_id);
        }
      }
    };
    
    // Show booking details modal (read-only, no action buttons)
    window.showBookingDetailsModal = function(booking) {
      const guestName = booking.name || booking.user_name || 'N/A';
      const guestEmail = booking.email || 'N/A';
      const guestPhone = booking.phone || 'N/A';

      // Format status for display
      let statusDisplay = booking.status.replace(/_/g, ' ');
      statusDisplay = statusDisplay.charAt(0).toUpperCase() + statusDisplay.slice(1);

      // Validate and format check-in/check-out dates
      const checkinDateObj = booking.checkin ? new Date(booking.checkin) : null;
      const checkoutDateObj = booking.checkout ? new Date(booking.checkout) : null;

      const checkinDate = checkinDateObj && !isNaN(checkinDateObj.getTime())
        ? checkinDateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
        : 'N/A';
      const checkoutDate = checkoutDateObj && !isNaN(checkoutDateObj.getTime())
        ? checkoutDateObj.toLocaleDateString('en-US', { weekday: 'short', month: 'short', day: 'numeric', year: 'numeric' })
        : 'N/A';
      
      const modal = document.createElement('div');
      modal.className = 'modal-overlay';
      modal.innerHTML = `
        <div class="modal-container">
          <div class="modal-header">
            <div class="modal-header-left">
              <i class="fas fa-clipboard-check"></i>
              <h2>Booking Details</h2>
            </div>
            <div class="modal-header-actions">
              <button class="btn-close" onclick="this.closest('.modal-overlay').remove()"><i class="fas fa-times"></i> Close</button>
            </div>
          </div>
          
          <div class="modal-body">
            <div class="info-grid">
              <div class="info-card">
                <div class="card-title"><i class="fas fa-user-circle"></i> Guest Information</div>
                <div class="detail-row">
                  <span class="label"><i class="far fa-user"></i> Guest Name</span>
                  <span class="value">${guestName}</span>
                </div>
                <div class="detail-row">
                  <span class="label"><i class="far fa-envelope"></i> Email</span>
                  <span class="value" style="font-weight:500; font-size:0.9rem;">${guestEmail}</span>
                </div>
                <div class="detail-row">
                  <span class="label"><i class="fas fa-phone-alt"></i> Contact</span>
                  <span class="value">${guestPhone}</span>
                </div>
                ${booking.user_id ? `
                <div class="detail-row">
                  <span class="label"><i class="fas fa-id-card"></i> Customer ID</span>
                  <span class="value" style="font-weight:500;">${booking.user_id}</span>
                </div>
                ` : ''}
                <div class="detail-row" style="border-bottom: none; padding-bottom:0;">
                  <span class="label"><i class="fas fa-hashtag"></i> Booking Ref</span>
                  <span class="value" style="font-weight:600; color:var(--accent);">${booking.booking_id}</span>
                </div>
              </div>

              <div class="info-card">
                <div class="card-title"><i class="fas fa-calendar-alt"></i> Booking Details</div>
                <div class="detail-row">
                  <span class="label"><i class="fas fa-calendar-check"></i> Check-in</span>
                  <span class="value">${checkinDate}</span>
                </div>
                <div class="detail-row">
                  <span class="label"><i class="fas fa-calendar-times"></i> Check-out</span>
                  <span class="value">${checkoutDate}</span>
                </div>
                <div class="detail-row">
                  <span class="label"><i class="fas fa-users"></i> Guests</span>
                  <span class="value">${booking.guests ? (booking.adults || booking.children || booking.infants ? booking.guests + ' (' + (booking.adults || 0) + ' adults, ' + (booking.children || 0) + ' children, ' + (booking.infants || 0) + ' infants)' : booking.guests) : 'N/A'}</span>
                </div>
                <div class="detail-row" style="border-bottom: none; padding-bottom:0;">
                  <span class="label"><i class="fas fa-tag"></i> Total Amount</span>
                  <span class="value amount">${booking.total_amount ? '₱' + parseFloat(booking.total_amount).toLocaleString() : 'N/A'}</span>
                </div>
              </div>
            </div>

            <div class="payment-proof-grid">
              <div class="info-card" style="margin-bottom:0;">
                <div class="card-title"><i class="fas fa-credit-card"></i> Payment Information</div>
                ${booking.payment_method ? `
                <div class="detail-row">
                  <span class="label"><i class="fas fa-qrcode"></i> Payment Method</span>
                  <span class="value">${booking.payment_method.replace(/_/g, ' ').toUpperCase()}</span>
                </div>
                ` : ''}
                ${booking.amount_sent ? `
                <div class="detail-row" style="border-bottom: none; padding-bottom:0;">
                  <span class="label"><i class="fas fa-money-bill-wave"></i> Amount Sent</span>
                  <span class="value amount" style="color:var(--text-primary);">₱${parseFloat(booking.amount_sent).toLocaleString()}</span>
                </div>
                ` : ''}
              </div>

              ${booking.payment_proof ? `
              <div class="proof-preview">
                <div class="proof-title"><i class="fas fa-image"></i> Payment Proof</div>
                <div class="proof-image-wrapper" onclick="window.open('../${booking.payment_proof}', '_blank')">
                  ${booking.payment_proof.match(/\.(jpg|jpeg|png|gif)$/i) ? `
                  <img src="../${booking.payment_proof}" alt="Payment proof" />
                  ` : `
                  <div style="padding: 2rem; text-align: center; color: var(--text-secondary);">
                    <i class="fas fa-file-alt" style="font-size: 2rem;"></i>
                    <p style="margin-top: 0.5rem;">Click to view</p>
                  </div>
                  `}
                  <span class="expand-hint"><i class="fas fa-expand"></i> click to view full size</span>
                </div>
                <div style="font-size:0.7rem; color:var(--text-tertiary); margin-top:0.4rem; text-align:center;">
                  <i class="far fa-file-image"></i> ${booking.payment_proof.split('/').pop()}
                </div>
              </div>
              ` : ''}
            </div>

            ${booking.review_email_status ? `
            <div class="email-status-card">
              <div class="email-status-item">
                <span class="label"><i class="far fa-envelope"></i> Status</span>
                <span class="value badge-status"><i class="fas fa-clock"></i> ${booking.review_email_status}</span>
              </div>
              ${booking.review_email_scheduled_at ? `
              <div class="email-status-item">
                <span class="label"><i class="far fa-calendar-alt"></i> Scheduled</span>
                <span class="value">${new Date(booking.review_email_scheduled_at).toLocaleString()}</span>
              </div>
              ` : ''}
              ${booking.review_email_sent_at ? `
              <div class="email-status-item">
                <span class="label"><i class="fas fa-check-circle" style="color:var(--success);"></i> Sent</span>
                <span class="value" style="color:var(--accent);">${new Date(booking.review_email_sent_at).toLocaleString()}</span>
              </div>
              ` : ''}
            </div>
            ` : ''}

            ${booking.checkin_reminder_status ? `
            <div class="email-status-card">
              <div class="email-status-item">
                <span class="label"><i class="far fa-bell"></i> Check-in Reminder</span>
                <span class="value badge-status"><i class="fas fa-clock"></i> ${booking.checkin_reminder_status}</span>
              </div>
              ${booking.checkin_reminder_scheduled_at ? `
              <div class="email-status-item">
                <span class="label"><i class="far fa-calendar-alt"></i> Scheduled</span>
                <span class="value">${new Date(booking.checkin_reminder_scheduled_at).toLocaleString()}</span>
              </div>
              ` : ''}
              ${booking.checkin_reminder_sent_at ? `
              <div class="email-status-item">
                <span class="label"><i class="fas fa-check-circle" style="color:var(--success);"></i> Sent</span>
                <span class="value" style="color:var(--accent);">${new Date(booking.checkin_reminder_sent_at).toLocaleString()}</span>
              </div>
              ` : ''}
            </div>
            ` : ''}

            ${booking.requests ? `
            <div class="info-card" style="margin-top: 1.8rem;">
              <div class="card-title"><i class="fas fa-comment-alt"></i> Special Requests</div>
              <p style="color: var(--text-secondary); line-height: 1.6;">${booking.requests}</p>
            </div>
            ` : ''}

            <div style="margin-top:0.8rem; font-size:0.75rem; color:var(--text-tertiary); text-align:right; border-top:1px solid var(--border); padding-top:0.6rem;">
              <i class="far fa-clock"></i> ${statusDisplay} · ${booking.booking_id}
            </div>
          </div>

          ${booking.status === 'booking_confirmed' ? `
          <div class="modal-footer" style="display:flex;gap:8px;justify-content:flex-end;padding:1rem 1.5rem;border-top:1px solid var(--border);background:var(--bg-secondary);">
            <button class="action-btn action-btn-secondary" onclick="openRebookModal('${booking.booking_id}', '${booking.checkin}', '${booking.checkout}', event); this.closest('.modal-overlay').remove();">
              <i class="fas fa-calendar-alt"></i>
              <span>Rebook</span>
            </button>
          </div>
          ` : ''}
        </div>
      `;
      document.body.appendChild(modal);
    };

    // Send arrival notice
    window.sendArrivalNotice = function(bookingId) {
      const formData = new FormData();
      formData.append('action', 'send_arrival_notice');
      formData.append('booking_id', bookingId);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          refreshBookings(bookingsState.currentPage);
          showToast(data.message);
        } else {
          showToast(data.message || 'Failed to send arrival notice');
        }
      })
      .catch(error => {
        console.error('Error sending arrival notice:', error);
        showToast('Error sending arrival notice');
      });
    };



    
    // Toggle admin notification dropdown
    window.toggleAdminNotificationDropdown = function() {
      const dropdown = document.getElementById('adminNotificationDropdown');
      dropdown.style.display = dropdown.style.display === 'none' ? 'block' : 'none';
      
      if (dropdown.style.display === 'block') {
        // Refresh both badge and notifications when opening dropdown
        updateAdminNotificationBadge();
        loadAdminNotifications();
      }
    };
    
    // Load admin notifications
    window.loadAdminNotifications = function() {
      fetch('Admin.php?action=get_admin_notifications')
      .then(response => response.json())
      .then(data => {
        if (!data.success) {
          console.error('Error loading notifications:', data.message);
          return;
        }
        
        const notifications = data.notifications || [];
        const container = document.getElementById('adminNotificationList');
        
        if (notifications.length === 0) {
          container.innerHTML = '<div class="no-notifications">No new notifications</div>';
          return;
        }
        
        container.innerHTML = notifications.map(notif => {
          const date = new Date(notif.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' });
          const isRead = notif.is_read;
          
          // Determine notification type and styling
          let notifType = 'consult';
          let icon = 'fa-bell';
          let statusBadge = 'New';
          
          if (notif.type === 'new_booking' || notif.type === 'pending_booking_confirmation') {
            notifType = 'booking';
            icon = 'fa-calendar-plus';
            statusBadge = 'New';
          }
          
          // Highlight booking ID in message
          let message = notif.message;
          if (notif.booking_id) {
            message = message.replace(`#${notif.booking_id}`, `<span class="highlight">#${notif.booking_id}</span>`);
          }
          
          let clickAction = '';
          
          if (notif.type === 'new_booking' || notif.type === 'pending_booking_confirmation') {
            clickAction = `onclick="openBookingFromNotification('${notif.booking_id}')"`;
          }
          
          return `
            <div class="notification-item notification-type-${notifType} ${isRead ? 'read' : 'unread'}" ${clickAction}>
              <div class="notification-icon">
                <i class="fas ${icon}"></i>
              </div>
              <div class="notification-content">
                <div class="notification-subject">
                  ${notif.subject}
                  <span class="notification-status-badge">${statusBadge}</span>
                </div>
                <div class="notification-message">${message}</div>
                <div class="notification-meta">
                  <span><i class="far fa-clock"></i> ${date}</span>
                  <span><i class="fas fa-tag"></i> ${notifType}</span>
                </div>
              </div>
            </div>
          `;
        }).join('');
      })
      .catch(error => {
        console.error('Error loading notifications:', error);
      });
    };
    
    
    // Store previous notification count
    let previousAdminNotificationCount = 0;
    let notificationBadgeInterval = null;

    function renderAdminNotificationBadge(count) {
      const badge = document.getElementById('adminNoticeBadge');
      if (!badge) return;

      if (count > 0) {
        badge.textContent = count > 99 ? '99+' : count;
        badge.style.display = 'inline-block';
      } else {
        badge.style.display = 'none';
      }
    }
    
    // Update admin notification badge
    window.updateAdminNotificationBadge = function() {
      fetch('Admin.php?action=get_admin_notification_count')
      .then(response => {
        if (!response.ok) {
          console.error('Network response was not ok:', response.status);
          throw new Error('Network response was not ok');
        }
        return response.json();
      })
      .then(data => {
        console.log('Admin notification count response:', data);
        if (!data.success) {
          console.error('Failed to get notification count:', data.message);
          return;
        }
        
        const count = data.count || 0;
        renderAdminNotificationBadge(count);

        if (count > previousAdminNotificationCount) {
          const newCount = count - previousAdminNotificationCount;
          showToast(`${newCount} new notification${newCount > 1 ? 's' : ''} received`);
          const dropdown = document.getElementById('adminNotificationDropdown');
          if (dropdown && dropdown.style.display === 'block') {
            loadAdminNotifications();
          }
        }

        previousAdminNotificationCount = count;
      })
      .catch(error => {
        console.error('Error updating notification badge:', error);
      });
    };

    // Mark booking notification as read
    window.markBookingNotificationAsRead = function(bookingId, notificationType) {
      const formData = new FormData();
      formData.append('action', 'mark_admin_notification_read');
      formData.append('booking_id', bookingId);
      formData.append('notification_type', notificationType);

      return fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          previousAdminNotificationCount = data.count || 0;
          renderAdminNotificationBadge(previousAdminNotificationCount);
          loadAdminNotifications();
        }
        return data;
      })
      .catch(error => {
        console.error('Error marking notification as read:', error);
        return { success: false };
      });
    };

    window.openBookingFromNotification = function(bookingId) {
      const dropdown = document.getElementById('adminNotificationDropdown');
      if (dropdown) {
        dropdown.style.display = 'none';
      }

      markBookingNotificationAsRead(bookingId, 'new_booking').finally(() => {
        viewBooking(bookingId);
      });
    };
    
    // Mark all notifications as read
    window.markAllNotificationsAsRead = function() {
      const formData = new FormData();
      formData.append('action', 'mark_all_admin_notifications_read');

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (!data.success) {
          showToast(data.message || 'Failed to mark notifications as read');
          return;
        }

        previousAdminNotificationCount = data.count || 0;
        renderAdminNotificationBadge(previousAdminNotificationCount);

        const notificationList = document.getElementById('adminNotificationList');
        if (notificationList) {
          notificationList.innerHTML = '<div class="no-notifications">No notifications</div>';
        }

        const dropdown = document.getElementById('adminNotificationDropdown');
        if (dropdown) dropdown.style.display = 'none';

        showToast('All notifications marked as read');
      })
      .catch(error => {
        console.error('Error marking all notifications as read:', error);
        showToast('Error marking notifications as read');
      });
    };
    
    
    // Close admin dropdown when clicking outside
    document.addEventListener('click', function(e) {
      const dropdown = document.getElementById('adminNotificationDropdown');
      const bell = document.querySelector('.notification-bell');
      
      if (dropdown && dropdown.style.display === 'block' && !dropdown.contains(e.target) && !bell.contains(e.target)) {
        dropdown.style.display = 'none';
      }
    });



    // View customer details
    window.viewUser = function(id) {
      Promise.all([
        fetch('Admin.php?action=get_users').then(r => r.json()),
        fetch('Admin.php?action=get_bookings').then(r => r.json())
      ])
      .then(([usersData, bookingsData]) => {
        if (!usersData.success) {
          showToast('Failed to load customer data');
          return;
        }
        if (!bookingsData.success) {
          showToast('Failed to load bookings data');
          return;
        }

        const user = usersData.users.find(u => u.user_id === id);
        if (!user) return;

        const userBookings = bookingsData.bookings.filter(b => b.email === user.email);
        const joinedDate = new Date(user.created_at).toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });

        const modal = document.createElement('div');
        modal.className = 'modal-overlay';
        modal.innerHTML = `
          <div class="modal">
            <button class="close" onclick="this.closest('.modal-overlay').remove()">&times;</button>
            <h3>Customer ${user.user_id}</h3>
            <p><strong>Name:</strong> ${user.name}</p>
            <p><strong>Email:</strong> ${user.email}</p>
            <p><strong>Phone:</strong> ${user.phone || 'N/A'}</p>
            <p><strong>Joined:</strong> ${joinedDate}</p>
            <p><strong>Total Bookings:</strong> ${userBookings.length}</p>
            <div style="margin-top:16px;">
              <strong>Recent Bookings:</strong>
              ${userBookings.length > 0 ? userBookings.map(b => `
                <div style="padding:8px 0;border-bottom:1px solid var(--border);">
                  <small>${b.booking_id} • ${new Date(b.checkin).toLocaleDateString()} - ${new Date(b.checkout).toLocaleDateString()} • ${b.status}</small>
                </div>
              `).join('') : '<p style="color:var(--text-secondary);font-size:14px;">No bookings yet</p>'}
            </div>
            <div class="flex" style="margin-top:16px;gap:12px;">
              <button class="btn btn-sm btn-outline" onclick="deleteUser('${user.user_id}', '${user.email}', true)">Delete Customer</button>
            </div>
          </div>
        `;
        document.body.appendChild(modal);
      })
      .catch(error => console.error('Error loading user:', error));
    };

    // Delete customer
    window.deleteUser = function(id, email, closeModal = false) {
      if (!confirm('Are you sure you want to delete this customer? This will also delete all their bookings.')) {
        return;
      }

      const formData = new FormData();
      formData.append('action', 'delete_user');
      formData.append('user_id', id);
      formData.append('email', email);

      fetch('Admin.php', {
        method: 'POST',
        body: formData
      })
      .then(response => response.json())
      .then(data => {
        if (data.success) {
          refreshUsers();
          refreshBookings();
          updateDashboardStats();
          if (closeModal) {
            document.querySelector('.modal-overlay')?.remove();
          }
          showToast('Customer deleted successfully');
        }
      })
      .catch(error => console.error('Error deleting user:', error));
    };

    // Save property data to localStorage
    window.savePropertyData = function() {
      const propertyData = {
        name: document.getElementById('propertyName').value,
        capacity: document.getElementById('propertyCapacity').value,
        bedrooms: document.getElementById('propertyBedrooms').value,
        bathrooms: document.getElementById('propertyBathrooms').value,
        location: document.getElementById('propertyLocation').value,
        description: document.getElementById('propertyDescription').value,
        contact: {
          phone: document.getElementById('propertyPhone').value,
          email: document.getElementById('propertyEmail').value
        },
        updatedAt: new Date().toISOString()
      };

      const existingData = JSON.parse(localStorage.getItem('solendra_property') || '{}');
      propertyData.amenities = existingData.amenities || [];

      localStorage.setItem('solendra_property', JSON.stringify(propertyData));
      showToast('Property data saved successfully');
    };



    // Page navigation
    window.showPage = function(pageId) {
      const pages = {
        dashboard: document.getElementById('page-dashboard'),
        bookings: document.getElementById('page-bookings'),
        calendar: document.getElementById('page-calendar'),
        property: document.getElementById('page-property'),
        'payment-qr': document.getElementById('page-payment-qr'),
        payment: document.getElementById('page-payment'),
        users: document.getElementById('page-users'),
        gallery: document.getElementById('page-gallery'),
        amenities: document.getElementById('page-amenities'),
        reviews: document.getElementById('page-reviews'),
        reports: document.getElementById('page-reports'),
        settings: document.getElementById('page-settings'),
      };

      // Hide all pages by adding hidden class
      Object.keys(pages).forEach(key => {
        if (pages[key]) pages[key].classList.add('hidden');
      });

      // Show selected page by removing hidden class
      const page = document.getElementById('page-' + pageId);
      if (page) page.classList.remove('hidden');

      // Update nav active state
      document.querySelectorAll('.nav-item').forEach(item => item.classList.remove('active'));
      const nav = document.querySelector('[data-page="' + pageId + '"]');
      if (nav) nav.classList.add('active');

      // Load page-specific data
      if (pageId === 'dashboard') {
        loadDashboardAnalytics();
      } else if (pageId === 'calendar') {
        initCalendar();
      } else if (pageId === 'bookings') {
        refreshBookings(1);
      } else if (pageId === 'reviews') {
        loadReviews();
      }
    };

    // Toast notification
    function showToast(msg) {
      const existing = document.querySelector('.toast');
      if (existing) existing.remove();
      const toast = document.createElement('div');
      toast.className = 'toast';
      toast.innerHTML = `<i class="fas fa-check-circle" style="color:var(--accent);margin-right:10px;"></i> ${msg}`;
      document.body.appendChild(toast);
      setTimeout(() => toast.remove(), 3000);
    }

    function processDueReviewEmails() {
      fetch('Admin.php?action=process_due_review_emails')
        .then(response => response.json())
        .then(data => {
          if (data.success && data.summary && data.summary.sent > 0) {
            console.log('Review emails sent:', data.summary.sent);
          }
        })
        .catch(error => console.error('Review email processor error:', error));
    }


    // ---------- INITIALIZATION ----------
    document.addEventListener('DOMContentLoaded', function() {
      console.log('Admin.js loaded - DOM ready');

      // Initial notification badge update
      updateAdminNotificationBadge();

      // Load dashboard data on initial load
      updateDashboardStats();
      loadDashboardAnalytics();
      processDueReviewEmails();

      // Auto-refresh notification badge every 30 seconds
      notificationBadgeInterval = setInterval(updateAdminNotificationBadge, 30000);

      // Auto-refresh dashboard analytics every 60 seconds
      setInterval(loadDashboardAnalytics, 60000);

      // Process due review emails every 5 minutes (XAMPP fallback when cron is not set up)
      setInterval(processDueReviewEmails, 300000);
      
      // ---------- THEME ----------
      const themeToggle = document.getElementById('themeToggle');
      
      // Load saved theme from localStorage
      const savedTheme = localStorage.getItem('adminTheme');
      const html = document.documentElement;
      
      // Apply saved theme or default to light mode
      if (savedTheme === 'dark') {
        html.setAttribute('data-theme', 'dark');
        if (themeToggle) themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
      } else {
        html.removeAttribute('data-theme');
        if (themeToggle) themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
      }
      
      function toggleTheme() {
        const html = document.documentElement;
        const isDark = html.getAttribute('data-theme') === 'dark';
        
        if (isDark) {
          html.removeAttribute('data-theme');
          localStorage.setItem('adminTheme', 'light');
          if (themeToggle) themeToggle.innerHTML = '<i class="fas fa-moon"></i>';
        } else {
          html.setAttribute('data-theme', 'dark');
          localStorage.setItem('adminTheme', 'dark');
          if (themeToggle) themeToggle.innerHTML = '<i class="fas fa-sun"></i>';
        }
        
        if (typeof loadDashboardAnalytics === 'function') {
          loadDashboardAnalytics();
        }
        if (typeof loadAdminNotifications === 'function') {
          const dropdown = document.getElementById('adminNotificationDropdown');
          if (dropdown && dropdown.style.display === 'block') {
            loadAdminNotifications();
          }
        }
      }
      if (themeToggle) themeToggle.addEventListener('click', toggleTheme);
      
      // ---------- SIDEBAR ----------
      const sidebar = document.getElementById('sidebar');
      const toggleBtn = document.getElementById('toggleSidebar');
      const mobileToggle = document.getElementById('mobileSidebarToggle');

      function updateSidebarToggle() {
        if (!sidebar || !mobileToggle) return;

        const isMobile = window.innerWidth <= 900;

        if (isMobile) {
          sidebar.classList.remove('collapsed');
        } else {
          sidebar.classList.remove('open');
        }

        const isOpen = isMobile
          ? sidebar.classList.contains('open')
          : !sidebar.classList.contains('collapsed');
        const icon = mobileToggle.querySelector('i');

        mobileToggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        mobileToggle.setAttribute('aria-label', isOpen ? 'Close navigation menu' : 'Open navigation menu');
        mobileToggle.setAttribute('title', isOpen ? 'Close navigation menu' : 'Open navigation menu');
        if (icon) {
          icon.className = isMobile && isOpen ? 'fas fa-times' : 'fas fa-bars';
        }
      }

      function toggleSidebar() {
        if (!sidebar) return;

        if (window.innerWidth <= 900) {
          sidebar.classList.toggle('open');
        } else {
          sidebar.classList.toggle('collapsed');
        }

        updateSidebarToggle();
      }
      if (toggleBtn) toggleBtn.addEventListener('click', toggleSidebar);
      if (mobileToggle) mobileToggle.addEventListener('click', toggleSidebar);
      window.addEventListener('resize', updateSidebarToggle);
      updateSidebarToggle();
      // close sidebar on outside click (mobile)
      if (sidebar && mobileToggle) {
        document.addEventListener('click', function(e) {
          if (window.innerWidth <= 900 && sidebar.classList.contains('open')) {
            if (!sidebar.contains(e.target) && !mobileToggle.contains(e.target)) {
              sidebar.classList.remove('open');
              updateSidebarToggle();
            }
          }
        });
      }

      // ---------- PAGE NAVIGATION ----------
      const navItems = document.querySelectorAll('.nav-item[data-page]');
      navItems.forEach(item => {
        item.addEventListener('click', function(e) {
          e.preventDefault();
          const page = this.getAttribute('data-page');
          showPage(page);
        });
      });
      
      // logout
      const logoutBtn = document.getElementById('logoutBtn');
      if (logoutBtn) {
        logoutBtn.addEventListener('click', function(e) {
          e.preventDefault();
          window.location.href = 'Admin.php?action=logout';
        });
      }

      // ---------- EVENT LISTENERS ----------
      const searchBookings = document.getElementById('searchBookings');
      const filterStatus = document.getElementById('filterStatus');
      const itemsPerPage = document.getElementById('itemsPerPage');
      
      // Debounced search for bookings
      let searchTimeout;
      if (searchBookings) {
        searchBookings.addEventListener('input', function() {
          clearTimeout(searchTimeout);
          searchTimeout = setTimeout(() => refreshBookings(1), 500);
        });
      }
      
      if (filterStatus) filterStatus.addEventListener('change', () => refreshBookings(1));
      if (itemsPerPage) itemsPerPage.addEventListener('change', () => refreshBookings(1));
      
      const searchUsers = document.getElementById('searchUsers');
      if (searchUsers) searchUsers.addEventListener('input', refreshUsers);

      // ---------- LOAD DATA ----------
      refreshBookings();
      updateDashboardStats();
      loadPropertyData();
      refreshUsers();
      updateAdminNotificationBadge();
      
      // Poll for notification updates every 1 second for real-time updates
      setInterval(updateAdminNotificationBadge, 1000);
      console.log('Admin notification polling started (1 second interval)');

      // default page: dashboard
      showPage('dashboard');
      
      // toast test
      setTimeout(() => showToast('Dashboard ready ✨'), 400);
    });

  </script>
</body>
</html>
