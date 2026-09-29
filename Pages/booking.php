<?php
session_start();
require_once '../config.php';

// Disable error output to prevent JSON errors
ini_set('display_errors', 0);
error_reporting(E_ALL);

// Handle POST requests for submitting booking
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit_booking') {
    header('Content-Type: application/json');

    try {
        // Allow guest bookings - no login required
        $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'GUEST-' . substr(time(), -6);
        $booking_id = 'B-' . substr(time(), -6);
        $name = $conn->real_escape_string($_POST['name']);
        $email = $conn->real_escape_string($_POST['email']);
        $phone = $conn->real_escape_string($_POST['phone'] ?? '');
        $checkin = $conn->real_escape_string($_POST['checkin']);
        $checkout = $conn->real_escape_string($_POST['checkout']);
        $guests = intval($_POST['guests']);
        $adults = intval($_POST['adults'] ?? 0);
        $children = intval($_POST['children'] ?? 0);
        $infants = intval($_POST['infants'] ?? 0);
        $requests = $conn->real_escape_string($_POST['requests'] ?? '');
        $total_amount = floatval($_POST['total_amount'] ?? 0);
        
        // Payment fields
        $payment_method = $conn->real_escape_string($_POST['payment_method'] ?? '');
        $amount_sent = floatval($_POST['amount_sent'] ?? 0);
        $payment_notes = $conn->real_escape_string($_POST['payment_notes'] ?? '');
        
        // Handle payment proof upload
        $payment_proof_path = null;
        if (isset($_FILES['payment_proof']) && $_FILES['payment_proof']['error'] === UPLOAD_ERR_OK) {
            require_once __DIR__ . '/../email_helper.php';
            
            $validation = validatePaymentProof($_FILES['payment_proof']);
            if (!$validation['valid']) {
                echo json_encode(['success' => false, 'message' => $validation['error']]);
                exit;
            }
            
            // Create uploads directory if it doesn't exist
            $upload_dir = __DIR__ . '/../uploads/payment_proofs/';
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0755, true);
            }
            
            // Generate secure filename
            $file_extension = strtolower(pathinfo($_FILES['payment_proof']['name'], PATHINFO_EXTENSION));
            $secure_filename = generatePaymentProofFilename($booking_id, $file_extension);
            $payment_proof_path = $upload_dir . $secure_filename;
            
            // Move uploaded file
            if (!move_uploaded_file($_FILES['payment_proof']['tmp_name'], $payment_proof_path)) {
                error_log("Failed to move payment proof file for booking $booking_id");
                $payment_proof_path = null;
            }
        }
        
        // Determine payment status
        $payment_status = 'pending';
        if ($payment_method === 'qr' && $payment_proof_path) {
            $payment_status = 'submitted';
        } elseif ($payment_method === 'cash') {
            $payment_status = 'pending';
        }
        
        // Store relative path for database
        $payment_proof_db = $payment_proof_path ? str_replace(__DIR__ . '/../', '', $payment_proof_path) : null;

        $sql = "INSERT INTO bookings (booking_id, user_id, name, email, phone, checkin, checkout, guests, adults, children, infants, requests, total_amount, payment_method, amount_sent, payment_notes, payment_proof, review_email_scheduled_at, review_email_sent_at, review_email_status, payment_status, status)
            VALUES ('$booking_id', '$user_id', '$name', '$email', '$phone', '$checkin', '$checkout', $guests, $adults, $children, $infants, '$requests', $total_amount, '$payment_method', $amount_sent, '$payment_notes', '$payment_proof_db', NULL, NULL, 'Not Scheduled', '$payment_status', 'pending_booking_confirmation')";

        if ($conn->query($sql)) {
            error_log("Booking created: $booking_id with status: pending_booking_confirmation");

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

            $notif_subject = 'New Booking';
            $notif_message = "Booking #{$booking_id} by {$name} - Check-in: {$checkin}, Check-out: {$checkout}, {$guests} guest(s) ({$adults} adults, {$children} children, {$infants} infants)";
            $existing_notif = $conn->query("SELECT id FROM admin_notifications WHERE booking_id = '$booking_id' AND type = 'new_booking' LIMIT 1");
            if (!$existing_notif || $existing_notif->num_rows === 0) {
                $notif_result = $conn->query("INSERT INTO admin_notifications (type, subject, message, booking_id)
                              VALUES ('new_booking', '$notif_subject', '$notif_message', '$booking_id')");
                error_log("Notification creation result: " . ($notif_result ? 'success' : 'failed: ' . $conn->error));
            }

            /*
             * IMPORTANT:
             * The database booking is already successful at this point.
             * Send the HTTP response before attempting SMTP so an email
             * problem cannot turn a successful booking into a 500 response.
             */
            http_response_code(200);
            $response = json_encode([
                'success' => true,
                'message' => 'Booking submitted successfully',
                'booking_id' => $booking_id
            ]);

            echo $response;

            // On PHP-FPM/FastCGI, finish the HTTP request now.
            // SMTP processing below will no longer affect the customer's response.
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } else {
                // Try to flush the response when FastCGI is unavailable.
                if (function_exists('ob_get_level')) {
                    while (ob_get_level() > 0) {
                        @ob_end_flush();
                    }
                }
                @flush();
            }

            /* Email processing is deliberately isolated from booking creation. */
            try {
                $emailHelper = __DIR__ . '/../email_helper.php';

                if (!file_exists($emailHelper)) {
                    error_log("BOOKING EMAIL ERROR [$booking_id]: email_helper.php not found at $emailHelper");
                } else {
                    require_once $emailHelper;

                    if (!function_exists('sendBookingEmail') || !function_exists('generateEmailTemplate')) {
                        error_log("BOOKING EMAIL ERROR [$booking_id]: required email helper functions are unavailable");
                    } else {
                        $checkinFormatted = date('F j, Y', strtotime($checkin));
                        $checkoutFormatted = date('F j, Y', strtotime($checkout));
                        $suggestedCheckInTime = getSuggestedCheckInTime();
                        $checkOutTime = getCheckOutTime();

                        // -----------------------------
                        // CUSTOMER EMAIL
                        // -----------------------------
                        $customerContent = '
                            <p>Dear ' . htmlspecialchars($name) . ',</p>
                            <p>We have received your booking request.</p>
                            <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #c9a86c; margin: 20px 0;">
                                <h3 style="margin-top: 0; color: #333;">Booking ID: ' . htmlspecialchars($booking_id) . '</h3>
                                <p><strong>Check-in:</strong><br>
                                ' . $checkinFormatted . ' at ' . $suggestedCheckInTime . '</p>
                                <p><strong>Check-out:</strong><br>
                                ' . $checkoutFormatted . ' at ' . $checkOutTime . '</p>
                                <p><strong>Guests:</strong> ' . $guests . ' (' . $adults . ' adults, ' . $children . ' children, ' . $infants . ' infants)</p>';

                        if (!empty($requests)) {
                            $customerContent .= '<p><strong>Special Requests:</strong><br>' . htmlspecialchars($requests) . '</p>';
                        }

                        $customerContent .= '<p><strong>Payment Status:</strong> ' . htmlspecialchars($payment_status) . '</p>
                                <p><strong>Booking Status:</strong> <span style="color: #ff9800;">Pending</span></p>
                            </div>
                            <p>Your booking is currently waiting for administrator approval.</p>
                            <p>If you have any questions, please contact us:
                            <br>Gmail: solendrasamal@gmail.com
                            <br>Contact#: 0945 588 7095
                            <br>Facebook: Solendra Samal</p>
                        ';

                        $customerHtmlBody = generateEmailTemplate(
                            'Booking Request Received - ' . $booking_id,
                            $customerContent
                        );

                        error_log("EMAIL DEBUG [$booking_id]: BEFORE customer SMTP");
                        $customerEmailSent = sendBookingEmail(
                            $email,
                            $name,
                            'Booking Request Received - ' . $booking_id,
                            $customerHtmlBody,
                            'booking_received',
                            $booking_id
                        );
                        error_log("EMAIL DEBUG [$booking_id]: AFTER customer SMTP = " . ($customerEmailSent ? 'SUCCESS' : 'FAILED'));

                        // -----------------------------
                        // ADMIN EMAIL
                        // -----------------------------
                        $configFile = __DIR__ . '/../email_config.php';
                        if (!file_exists($configFile)) {
                            error_log("EMAIL DEBUG [$booking_id]: email_config.php not found at $configFile");
                        } else {
                            $config = require $configFile;
                            $adminEmail = $config['admin_email'] ?? '';

                            if (!filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
                                error_log("EMAIL DEBUG [$booking_id]: invalid admin_email in email_config.php");
                            } else {
                                $adminContent = '
                                    <h2 style="color: #c9a86c; margin-top: 0;">NEW BOOKING REQUEST</h2>
                                    <hr style="border: none; border-top: 2px solid #c9a86c; margin: 20px 0;">
                                    <h3 style="color: #333; margin-top: 0;">BOOKING INFORMATION</h3>
                                    <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #c9a86c; margin: 20px 0;">
                                        <p><strong>Booking ID:</strong> ' . htmlspecialchars($booking_id) . '</p>
                                        <p><strong>Customer Name:</strong> ' . htmlspecialchars($name) . '</p>
                                        <p><strong>Email:</strong> ' . htmlspecialchars($email) . '</p>
                                        <p><strong>Phone:</strong> ' . htmlspecialchars($phone) . '</p>
                                        <p><strong>Check-in:</strong> ' . $checkinFormatted . ' at ' . $suggestedCheckInTime . '</p>
                                        <p><strong>Check-out:</strong> ' . $checkoutFormatted . ' at ' . $checkOutTime . '</p>
                                        <p><strong>Guests:</strong> ' . $guests . ' (' . $adults . ' adults, ' . $children . ' children, ' . $infants . ' infants)</p>
                                        <p><strong>Payment Method:</strong> ' . htmlspecialchars($payment_method) . '</p>
                                        <p><strong>Amount Sent:</strong> ₱' . number_format($amount_sent, 2) . '</p>
                                        <p><strong>Payment Notes:</strong> ' . htmlspecialchars($payment_notes) . '</p>
                                        <p><strong>Payment Status:</strong> ' . htmlspecialchars($payment_status) . '</p>
                                    </div>
                                ';

                                if (!empty($requests)) {
                                    $adminContent .= '<p><strong>Special Requests:</strong> ' . htmlspecialchars($requests) . '</p>';
                                }

                                if (!empty($payment_proof_path) && file_exists($payment_proof_path)) {
                                    $adminContent .= '<p><strong>Payment Proof:</strong> The payment proof is attached to this email.</p>';
                                } else {
                                    $adminContent .= '<p><strong>Payment Proof:</strong> No payment proof uploaded.</p>';
                                }

                                $adminHtmlBody = generateEmailTemplate(
                                    'New Booking Request - ' . $booking_id,
                                    $adminContent
                                );

                                error_log("EMAIL DEBUG [$booking_id]: BEFORE admin SMTP");
                                $adminEmailSent = sendBookingEmail(
                                    $adminEmail,
                                    'Admin',
                                    'New Booking Request - ' . $booking_id,
                                    $adminHtmlBody,
                                    'admin_notification',
                                    $booking_id,
                                    $payment_proof_path
                                );
                                error_log("EMAIL DEBUG [$booking_id]: AFTER admin SMTP = " . ($adminEmailSent ? 'SUCCESS' : 'FAILED'));
                            }
                        }
                    }
                }
            } catch (Throwable $emailError) {
                // Never change the successful booking response because of email failure.
                error_log('Booking email processing failed for ' . $booking_id . ': ' . $emailError->getMessage());
            }
        } else {
            error_log("Booking creation failed: " . $conn->error);
            echo json_encode(['success' => false, 'message' => 'Error submitting booking: ' . $conn->error]);
        }
    } catch (Throwable $e) {
        error_log('Booking submission failed: ' . $e->getMessage());
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
    exit;
}
?>
