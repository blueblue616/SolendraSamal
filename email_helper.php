<?php

// Check if PHPMailer is installed
$phpmailer_installed = file_exists(__DIR__ . '/vendor/autoload.php');

if ($phpmailer_installed) {
    require_once __DIR__ . '/vendor/autoload.php';
    require_once __DIR__ . '/email_config.php';
}

// Configurable default check-in and check-out times
$defaultCheckInTime = '14:00';  // 2:00 PM
$defaultCheckOutTime = '11:00'; // 11:00 AM

// Google Review Email Configuration
$googleReviewUrl = 'https://www.google.com/maps/place/Solendra+Samal/@7.1183572,125.7270377,17z/data=!3m1!4b1!4m6!3m5!1s0x32f9696b5369f3ad:0xc9d062bc5b47040e!8m2!3d7.1183572!4d125.7270377!16s%2Fg%2F11zwzp4w9t!5m1!1e2!18m1!1e1?entry=ttu&g_ep=EgoyMDI2MDkwNi4wIKXMDSoASAFQAw%3D%3D';
$reviewEmailDelayMinutes = 60; // Delay after checkout before sending review email (60 minutes = 1:00 PM if checkout is 12:00 PM)
$propertyName = 'Solendra Samal'; // Property name for emails
$logoUrl = 'http://localhost/Picture/LOGO/solendrasamal-removebg-preview.png'; // Replace with your actual domain URL for the logo
$logoPath = __DIR__ . '/Picture/LOGO/solendrasamal-removebg-preview.png'; // Local path for embedded image

/**
 * Add email to queue for async sending
 * 
 * @param string $recipientEmail Recipient's email address
 * @param string $recipientName Recipient's name
 * @param string $subject Email subject
 * @param string $htmlBody HTML email body
 * @param string|null $emailType Type of email for logging (optional)
 * @param string|null $bookingId Booking ID for logging (optional)
 * @param mysqli|null $conn Database connection (optional)
 * @return bool True if queued successfully, false otherwise
 */
function queueEmail($recipientEmail, $recipientName, $subject, $htmlBody, $emailType = null, $bookingId = null, $conn = null) {
    if (!$conn) {
        error_log("Database connection not available for email queue");
        return false;
    }
    
    try {
        $sql = "INSERT INTO email_queue (recipient_email, recipient_name, subject, html_body, email_type, booking_id, status)
                VALUES (?, ?, ?, ?, ?, ?, 'pending')";
        
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('ssssss', $recipientEmail, $recipientName, $subject, $htmlBody, $emailType, $bookingId);
        
        if ($stmt->execute()) {
            error_log("Email queued: $emailType for $bookingId to $recipientEmail");
            return true;
        } else {
            error_log("Failed to queue email: " . $stmt->error);
            return false;
        }
    } catch (Exception $e) {
        error_log("Error queuing email: " . $e->getMessage());
        return false;
    }
}

/**
 * Get suggested check-in time
 * Uses customer's requested time if provided, otherwise uses default
 * 
 * @param string|null $requestedTime Customer's requested check-in time (format: H:i)
 * @return string Formatted time (e.g., "2:00 PM")
 */
function getSuggestedCheckInTime($requestedTime = null) {
    global $defaultCheckInTime;
    
    $timeToUse = $requestedTime ? $requestedTime : $defaultCheckInTime;
    return formatTime($timeToUse);
}

/**
 * Get check-out time
 * 
 * @return string Formatted time (e.g., "11:00 AM")
 */
function getCheckOutTime() {
    global $defaultCheckOutTime;
    return formatTime($defaultCheckOutTime);
}

/**
 * Format time from 24-hour to 12-hour format
 * 
 * @param string $time Time in 24-hour format (H:i)
 * @return string Formatted time (e.g., "2:00 PM")
 */
function formatTime($time) {
    $timestamp = strtotime($time);
    return date('g:i A', $timestamp);
}

/**
 * Log email attempt for tracking
 * 
 * @param string $recipientEmail Recipient email
 * @param string $emailType Type of email (booking_received, admin_notification, approved, rejected)
 * @param string $bookingId Booking ID
 * @param bool $success Whether email was sent successfully
 * @param string|null $errorMessage Error message if failed
 * @return void
 */
function logEmailAttempt($recipientEmail, $emailType, $bookingId, $success, $errorMessage = null) {
    $logMessage = sprintf(
        '[%s] Email %s - Type: %s, Recipient: %s, Booking ID: %s, Success: %s',
        date('Y-m-d H:i:s'),
        $success ? 'SENT' : 'FAILED',
        $emailType,
        $recipientEmail,
        $bookingId,
        $success ? 'YES' : 'NO'
    );
    
    if ($errorMessage) {
        $logMessage .= ', Error: ' . $errorMessage;
    }
    
    error_log($logMessage);
}

/**
 * Validate payment proof file
 * 
 * @param array $file $_FILES array element for the uploaded file
 * @return array Validation result with 'valid' boolean and 'error' message if invalid
 */
function validatePaymentProof($file) {
    // Check if file was uploaded
    if (!isset($file) || $file['error'] !== UPLOAD_ERR_OK) {
        return ['valid' => false, 'error' => 'No file uploaded or upload error occurred'];
    }
    
    // Check file size (max 5MB)
    $maxSize = 5 * 1024 * 1024; // 5MB in bytes
    if ($file['size'] > $maxSize) {
        return ['valid' => false, 'error' => 'File size exceeds 5MB limit'];
    }
    
    // Check file extension
    $allowedExtensions = ['jpg', 'jpeg', 'png', 'pdf'];
    $fileExtension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($fileExtension, $allowedExtensions)) {
        return ['valid' => false, 'error' => 'Invalid file type. Allowed: JPG, JPEG, PNG, PDF'];
    }
    
    // Validate MIME type
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'application/pdf'];
    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mimeType = finfo_file($finfo, $file['tmp_name']);
    finfo_close($finfo);
    
    if (!in_array($mimeType, $allowedMimeTypes)) {
        return ['valid' => false, 'error' => 'Invalid MIME type. Allowed: image/jpeg, image/png, application/pdf'];
    }
    
    // Check if file is actually an image or PDF (additional security check)
    if ($fileExtension === 'pdf') {
        if ($mimeType !== 'application/pdf') {
            return ['valid' => false, 'error' => 'File extension does not match MIME type'];
        }
    } else {
        // For images, verify it's a valid image
        if (!@getimagesize($file['tmp_name'])) {
            return ['valid' => false, 'error' => 'Invalid image file'];
        }
    }
    
    return ['valid' => true, 'error' => null];
}

/**
 * Generate secure filename for payment proof
 * 
 * @param string $bookingId Booking ID
 * @param string $originalExtension Original file extension
 * @return string Secure filename
 */
function generatePaymentProofFilename($bookingId, $originalExtension) {
    // Sanitize booking ID
    $sanitizedBookingId = preg_replace('/[^a-zA-Z0-9\-_]/', '', $bookingId);
    
    // Generate unique identifier
    $uniqueId = bin2hex(random_bytes(8));
    
    // Create secure filename
    return $sanitizedBookingId . '_' . $uniqueId . '.' . $originalExtension;
}

/**
 * Send booking email using PHPMailer
 *
 * @param string $recipientEmail Recipient's email address
 * @param string $recipientName Recipient's name
 * @param string $subject Email subject
 * @param string $htmlBody HTML email body
 * @param string|null $emailType Type of email for logging (optional)
 * @param string|null $bookingId Booking ID for logging (optional)
 * @param string|null $attachmentPath Path to file to attach (optional)
 * @return bool True if email sent successfully, false otherwise
 */
function sendBookingEmail($recipientEmail, $recipientName, $subject, $htmlBody, $emailType = null, $bookingId = null, $attachmentPath = null) {
    global $phpmailer_installed;

    if (!$phpmailer_installed) {
        $error = "PHPMailer not installed. Cannot send email to: $recipientEmail";
        error_log($error);
        if ($emailType && $bookingId) {
            logEmailAttempt($recipientEmail, $emailType, $bookingId, false, $error);
        }
        return false;
    }

    // Validate email
    if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
        $error = "Invalid email address: $recipientEmail";
        error_log($error);
        if ($emailType && $bookingId) {
            logEmailAttempt($recipientEmail, $emailType, $bookingId, false, $error);
        }
        return false;
    }

    $config = require __DIR__ . '/email_config.php';

    $mail = new \PHPMailer\PHPMailer\PHPMailer(true);

    try {
        // Server settings
        $mail->isSMTP();
        $mail->Host = $config['host'];
        $mail->SMTPAuth = true;
        $mail->Username = $config['username'];
        $mail->Password = $config['password'];
        $mail->SMTPSecure = $config['encryption'];
        $mail->Port = $config['port'];

        // Set DKIM signing (if configured)
        if (isset($config['dkim_domain']) && isset($config['dkim_selector']) && isset($config['dkim_private_key'])) {
            $mail->DKIM_domain = $config['dkim_domain'];
            $mail->DKIM_selector = $config['dkim_selector'];
            $mail->DKIM_private = $config['dkim_private_key'];
            $mail->DKIM_passphrase = $config['dkim_passphrase'] ?? '';
        }

        // Recipients
        $mail->setFrom($config['from_email'], $config['from_name']);
        $mail->addAddress($recipientEmail, $recipientName);
        
        // Add Reply-To header for better deliverability
        $mail->addReplyTo($config['from_email'], $config['from_name']);
        
        // Add custom headers to prevent spam filtering
        $mail->addCustomHeader('X-Priority', '3');
        $mail->addCustomHeader('X-MSMail-Priority', 'Normal');
        $mail->addCustomHeader('X-Mailer', 'PHPMailer');
        $mail->addCustomHeader('X-Auto-Response-Suppress', 'All');
        
        // Add List-Unsubscribe header for compliance
        $unsubscribeUrl = 'mailto:' . $config['from_email'] . '?subject=Unsubscribe';
        $mail->addCustomHeader('List-Unsubscribe', '<' . $unsubscribeUrl . '>');
        $mail->addCustomHeader('List-Unsubscribe-Post', 'List-Unsubscribe=One-Click');

        // Embed logo image
        global $logoPath;
        if (file_exists($logoPath)) {
            $mail->addEmbeddedImage($logoPath, 'logo', 'solendrasamal-removebg-preview.png');
            // Replace logo URL in HTML with embedded image CID
            $htmlBody = str_replace('src="' . htmlspecialchars($GLOBALS['logoUrl'] ?? '') . '"', 'src="cid:logo"', $htmlBody);
        }

        // Attach file if provided
        if ($attachmentPath && file_exists($attachmentPath)) {
            $mail->addAttachment($attachmentPath, basename($attachmentPath));
        }

        // Content
        $mail->isHTML(true);
        $mail->CharSet = 'UTF-8';
        $mail->Subject = $subject;
        $mail->Body = $htmlBody;

        // Plain text fallback
        $mail->AltBody = strip_tags($htmlBody);

        $mail->send();
        
        if ($emailType && $bookingId) {
            logEmailAttempt($recipientEmail, $emailType, $bookingId, true);
        }
        
        return true;

    } catch (\PHPMailer\PHPMailer\Exception $e) {
        $error = "Email sending failed: " . $mail->ErrorInfo;
        error_log($error);
        if ($emailType && $bookingId) {
            logEmailAttempt($recipientEmail, $emailType, $bookingId, false, $error);
        }
        return false;
    }
}

/**
 * Generate email HTML template
 * 
 * @param string $title Email title
 * @param string $content Email content
 * @return string HTML email body
 */
function generateEmailTemplate($title, $content) {
    global $logoUrl;
    
    return '
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>' . htmlspecialchars($title) . '</title>
</head>
<body style="margin: 0; padding: 0; font-family: \'Segoe UI\', Tahoma, Geneva, Verdana, sans-serif; background-color: #f5f5f5;">
    <div style="max-width: 600px; margin: 40px auto; background-color: #ffffff; box-shadow: 0 2px 8px rgba(0,0,0,0.1); border-radius: 8px; overflow: hidden;">
        <!-- Header -->
        <div style="background-color: #ffffff; padding: 15px 20px 10px 20px; text-align: center;">
            <img src="' . htmlspecialchars($logoUrl) . '" alt="Solendra Samal Logo" style="max-width: 150px; width: 100%; height: auto; display: block; margin: 0 auto;">
        </div>
        
        <!-- Divider -->
        <div style="height: 3px; background: linear-gradient(90deg, #c9a86c 0%, #b8944d 50%, #c9a86c 100%);"></div>
        
        <!-- Content -->
        <div style="padding: 30px 30px;">
            <h1 style="color: #333333; font-size: 22px; font-weight: 600; margin: 0 0 15px 0; text-align: center;">' . htmlspecialchars($title) . '</h1>
            <div style="color: #555555; font-size: 15px; line-height: 1.6;">
                ' . $content . '
            </div>
        </div>
        
        <!-- Footer -->
        <div style="background-color: #f9f9f9; padding: 20px 30px; border-top: 1px solid #e0e0e0; text-align: center;">
            <p style="margin: 0 0 8px 0; color: #888888; font-size: 13px;">&copy; ' . date('Y') . ' Solendra Samal. All rights reserved.</p>
            <p style="margin: 0; color: #999999; font-size: 12px;">This is an automated email. Please do not reply.</p>
        </div>
    </div>
</body>
</html>';
}

/**
 * Send booking confirmation email to customer
 * 
 * @param string $customerName Customer name
 * @param string $customerEmail Customer email
 * @param string $bookingId Booking ID
 * @param string $checkin Check-in date
 * @param string $checkout Check-out date
 * @param int $guests Number of guests
 * @param string|null $requestedCheckInTime Customer's requested check-in time (optional)
 * @param string $specialRequests Special requests (optional)
 * @param string $paymentStatus Payment status (default: "Payment Proof Submitted")
 * @return bool True if email sent successfully
 */
function sendBookingConfirmationEmail($customerName, $customerEmail, $bookingId, $checkin, $checkout, $guests, $requestedCheckInTime = null, $specialRequests = '', $paymentStatus = 'Payment Proof Submitted') {
    $checkinFormatted = date('F j, Y', strtotime($checkin));
    $checkoutFormatted = date('F j, Y', strtotime($checkout));
    $suggestedCheckInTime = getSuggestedCheckInTime($requestedCheckInTime);
    $checkOutTime = getCheckOutTime();
    
    $content = '
        <p>Dear ' . htmlspecialchars($customerName) . ',</p>
        <p>We have received your booking request.</p>
        <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #c9a86c; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #333;">Booking ID: ' . htmlspecialchars($bookingId) . '</h3>
            <p><strong>Check-in:</strong><br>
            ' . $checkinFormatted . ' at ' . $suggestedCheckInTime . '</p>
            <p><strong>Check-out:</strong><br>
            ' . $checkoutFormatted . ' at ' . $checkOutTime . '</p>
            <p><strong>Guests:</strong> ' . $guests . '</p>';
    
    if (!empty($specialRequests)) {
        $content .= '<p><strong>Special Requests:</strong><br>' . htmlspecialchars($specialRequests) . '</p>';
    }
    
    $content .= '<p><strong>Payment Status:</strong> ' . htmlspecialchars($paymentStatus) . '</p>
            <p><strong>Booking Status:</strong> <span style="color: #ff9800;">Pending</span></p>
        </div>
        <p>Your booking is currently waiting for administrator approval.</p>
        <p>Need to rebook your stay? If you need to change your booking date, please contact us directly at solendrasamal@gmail.com or 0945 588 7095 for assistance with your rebooking request.</p>
    ';
    
    $htmlBody = generateEmailTemplate('Booking Request Received - ' . $bookingId, $content);
    return sendBookingEmail($customerEmail, $customerName, 'Booking Request Received - ' . $bookingId, $htmlBody, 'booking_received', $bookingId);
}

/**
 * Send new booking notification to admin
 * 
 * @param string $customerName Customer name
 * @param string $customerEmail Customer email
 * @param string $customerPhone Customer phone
 * @param string $bookingId Booking ID
 * @param string $checkin Check-in date
 * @param string $checkout Check-out date
 * @param int $guests Number of guests
 * @param string|null $requestedCheckInTime Customer's requested check-in time (optional)
 * @param string $specialRequests Special requests (optional)
 * @param string $paymentStatus Payment status
 * @param string|null $paymentAmount Payment amount (optional)
 * @param string|null $paymentReference Payment reference number (optional)
 * @param string|null $paymentProofPath Payment proof file path (optional)
 * @return bool True if email sent successfully
 */
function sendNewBookingNotificationToAdmin($customerName, $customerEmail, $customerPhone, $bookingId, $checkin, $checkout, $guests, $requestedCheckInTime = null, $specialRequests = '', $paymentStatus = 'Submitted', $paymentAmount = null, $paymentReference = null, $paymentProofPath = null) {
    $config = require __DIR__ . '/email_config.php';
    
    $checkinFormatted = date('F j, Y', strtotime($checkin));
    $checkoutFormatted = date('F j, Y', strtotime($checkout));
    $suggestedCheckInTime = getSuggestedCheckInTime($requestedCheckInTime);
    $checkOutTime = getCheckOutTime();
    
    // Build payment proof display
    $paymentProofDisplay = '';
    if (!empty($paymentProofPath)) {
        if (file_exists($paymentProofPath)) {
            $paymentProofDisplay = basename($paymentProofPath);
        } else {
            $paymentProofDisplay = 'File unavailable or not found';
            error_log("Payment proof file not found: $paymentProofPath for booking $bookingId");
        }
    } else {
        $paymentProofDisplay = 'No payment proof uploaded';
    }
    
    // Build payment amount display
    $paymentAmountDisplay = $paymentAmount ? '₱' . number_format($paymentAmount, 2) : 'Not specified';
    
    // Build payment reference display
    $paymentReferenceDisplay = $paymentReference ? htmlspecialchars($paymentReference) : 'Not provided';
    
    $content = '
        <h2 style="color: #c9a86c; margin-top: 0;">NEW BOOKING REQUEST</h2>
        <hr style="border: none; border-top: 2px solid #c9a86c; margin: 20px 0;">
        
        <h3 style="color: #333; margin-top: 0;">BOOKING INFORMATION</h3>
        <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #c9a86c; margin: 20px 0;">
            <p><strong>Booking ID:</strong> ' . htmlspecialchars($bookingId) . '</p>
            <p><strong>Status:</strong> <span style="color: #ff9800;">Pending</span></p>
            <p><strong>Guest:</strong> ' . htmlspecialchars($customerName) . '</p>
            <p><strong>Email:</strong> ' . htmlspecialchars($customerEmail) . '</p>
            <p><strong>Phone:</strong> ' . htmlspecialchars($customerPhone) . '</p>
            <p><strong>Check-in:</strong><br>
            ' . $checkinFormatted . '<br>
            <strong>Time:</strong> ' . $suggestedCheckInTime . '</p>
            <p><strong>Check-out:</strong><br>
            ' . $checkoutFormatted . '<br>
            <strong>Time:</strong> ' . $checkOutTime . '</p>
            <p><strong>Guests:</strong> ' . $guests . '</p>
        </div>
        
        <h3 style="color: #333; margin-top: 30px;">PAYMENT INFORMATION</h3>
        <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #4CAF50; margin: 20px 0;">
            <p><strong>Payment Status:</strong> ' . htmlspecialchars($paymentStatus) . '</p>
            <p><strong>Amount:</strong> ' . $paymentAmountDisplay . '</p>
            <p><strong>Reference Number:</strong> ' . $paymentReferenceDisplay . '</p>
            <p><strong>Payment Proof:</strong> ' . htmlspecialchars($paymentProofDisplay) . '</p>';
    
    if (!empty($paymentProofPath) && file_exists($paymentProofPath)) {
        $content .= '<p style="color: #4CAF50; font-size: 0.9em;">The payment proof is attached to this email.</p>';
    }
    
    $content .= '</div>';
    
    if (!empty($specialRequests)) {
        $content .= '
        <h3 style="color: #333; margin-top: 30px;">SPECIAL REQUESTS</h3>
        <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #2196F3; margin: 20px 0;">
            <p>' . htmlspecialchars($specialRequests) . '</p>
        </div>';
    }
    
    $content .= '<p style="margin-top: 30px;">Please log in to your admin dashboard to review and approve this booking.</p>';
    
    $htmlBody = generateEmailTemplate('New Booking Request - ' . $bookingId, $content);
    
    // Use sendBookingEmail for consistent spam prevention headers
    // Note: admin email will be the recipient, but sender remains from config
    return sendBookingEmail(
        $config['admin_email'],
        'Admin',
        'New Booking Request - ' . $bookingId,
        $htmlBody,
        'admin_notification',
        $bookingId,
        $paymentProofPath
    );
}

/**
 * Send booking approval email to customer
 * 
 * @param string $customerName Customer name
 * @param string $customerEmail Customer email
 * @param string $bookingId Booking ID
 * @param string $checkin Check-in date
 * @param string $checkout Check-out date
 * @param int $guests Number of guests
 * @param string|null $confirmedCheckInTime Confirmed check-in time (optional, uses default if not provided)
 * @return bool True if email sent successfully
 */
function sendBookingApprovalEmail($customerName, $customerEmail, $bookingId, $checkin, $checkout, $guests, $confirmedCheckInTime = null) {
    $checkinFormatted = date('F j, Y', strtotime($checkin));
    $checkoutFormatted = date('F j, Y', strtotime($checkout));
    $checkInTime = $confirmedCheckInTime ? formatTime($confirmedCheckInTime) : getSuggestedCheckInTime();
    $checkOutTime = getCheckOutTime();
    
    // Path to terms PDF
    $termsPdfPath = __DIR__ . '/Picture/LOGO/Terms and Conditions.pdf';
    
    $content = '
        <p>Dear ' . htmlspecialchars($customerName) . ',</p>
        <p>Your booking has been <strong>approved</strong>.</p>
        <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #4CAF50; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #333;">Booking ID: ' . htmlspecialchars($bookingId) . '</h3>
            <p><strong>Check-in:</strong> ' . $checkinFormatted . ' at ' . $checkInTime . '</p>
            <p><strong>Check-out:</strong> ' . $checkoutFormatted . ' at ' . $checkOutTime . '</p>
            <p><strong>Guests:</strong> ' . $guests . '</p>
            <p><strong>Status:</strong> <span style="color: #4CAF50;">Approved</span></p>
        </div>
        <p>Please find the Terms and Conditions attached to this email for your reference.</p>
        <p>Enjoy your stay and have fun!</p>
        <p>Need to rebook your stay? If you need to change your booking date, please contact us directly at solendrasamal@gmail.com or 0945 588 7095 for assistance with your rebooking request.</p>
    ';
    
    $htmlBody = generateEmailTemplate('Booking Approved - ' . $bookingId, $content);
    
    // Attach terms PDF if file exists
    $attachmentPath = file_exists($termsPdfPath) ? $termsPdfPath : null;
    
    return sendBookingEmail($customerEmail, $customerName, 'Booking Approved - ' . $bookingId, $htmlBody, 'approved', $bookingId, $attachmentPath);
}

/**
 * Send booking rejection email to customer
 * 
 * @param string $customerName Customer name
 * @param string $customerEmail Customer email
 * @param string $bookingId Booking ID
 * @param string $checkin Check-in date
 * @param string $checkout Check-out date
 * @param string|null $rejectionReason Rejection reason (optional)
 * @return bool True if email sent successfully
 */
function sendBookingRejectionEmail($customerName, $customerEmail, $bookingId, $checkin, $checkout, $rejectionReason = null) {
    $checkinFormatted = date('F j, Y', strtotime($checkin));
    $checkoutFormatted = date('F j, Y', strtotime($checkout));
    
    $content = '
        <p>Dear ' . htmlspecialchars($customerName) . ',</p>
        <p>We regret to inform you that your booking request has been <strong>rejected</strong>.</p>
        <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #f44336; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #333;">Booking ID: ' . htmlspecialchars($bookingId) . '</h3>
            <p><strong>Check-in:</strong> ' . $checkinFormatted . '</p>
            <p><strong>Check-out:</strong> ' . $checkoutFormatted . '</p>
            <p><strong>Status:</strong> <span style="color: #f44336;">Rejected</span></p>';
    
    if (!empty($rejectionReason)) {
        $content .= '<p><strong>Reason:</strong><br>' . htmlspecialchars($rejectionReason) . '</p>';
    }
    
    $content .= '</div>
        <p>Need to rebook your stay? If you need to change your booking date, please contact us directly at solendrasamal@gmail.com or 0945 588 7095 for assistance with your rebooking request.</p>
    ';
    
    $htmlBody = generateEmailTemplate('Booking Rejected - ' . $bookingId, $content);
    return sendBookingEmail($customerEmail, $customerName, 'Booking Rejected - ' . $bookingId, $htmlBody, 'rejected', $bookingId);
}

/**
 * Send booking rebook email to customer
 * 
 * @param string $customerName Customer name
 * @param string $customerEmail Customer email
 * @param string $bookingId Booking ID
 * @param string $oldCheckin Original check-in date
 * @param string $oldCheckout Original check-out date
 * @param string $newCheckin New check-in date
 * @param string $newCheckout New check-out date
 * @return bool True if email sent successfully
 */
function sendBookingRebookEmail($customerName, $customerEmail, $bookingId, $oldCheckin, $oldCheckout, $newCheckin, $newCheckout) {
    $oldCheckinFormatted = date('F j, Y', strtotime($oldCheckin));
    $oldCheckoutFormatted = date('F j, Y', strtotime($oldCheckout));
    $newCheckinFormatted = date('F j, Y', strtotime($newCheckin));
    $newCheckoutFormatted = date('F j, Y', strtotime($newCheckout));
    $checkInTime = getSuggestedCheckInTime();
    $checkOutTime = getCheckOutTime();
    
    $content = '
        <p>Dear ' . htmlspecialchars($customerName) . ',</p>
        <p>Your booking has been <strong>rebooked</strong> to new dates.</p>
        <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #c9a86c; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #333;">Booking ID: ' . htmlspecialchars($bookingId) . '</h3>
            <div style="margin-bottom: 15px;">
                <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9rem;"><strong>Previous Dates:</strong></p>
                <p style="margin: 0; color: #999;">' . $oldCheckinFormatted . ' – ' . $oldCheckoutFormatted . '</p>
            </div>
            <div>
                <p style="margin: 0 0 5px 0; color: #666; font-size: 0.9rem;"><strong>New Dates:</strong></p>
                <p style="margin: 0; color: #333; font-weight: 500;">' . $newCheckinFormatted . ' at ' . $checkInTime . ' – ' . $newCheckoutFormatted . ' at ' . $checkOutTime . '</p>
            </div>
        </div>
        <p>Your booking has been successfully rescheduled. Please note the new dates for your stay.</p>
        <p>Need to rebook your stay again? If you need to change your booking date, please contact us directly at solendrasamal@gmail.com or 0945 588 7095 for assistance with your rebooking request.</p>
    ';
    
    $htmlBody = generateEmailTemplate('Booking Rebooked - ' . $bookingId, $content);
    return sendBookingEmail($customerEmail, $customerName, 'Booking Rebooked - ' . $bookingId, $htmlBody, 'rebooked', $bookingId);
}

/**
 * Wrapper function: Send booking received email using booking array
 * 
 * @param array $booking Booking data array
 * @return bool True if email sent successfully
 */
function sendBookingReceivedEmail($booking) {
    return sendBookingConfirmationEmail(
        $booking['customer_name'],
        $booking['customer_email'],
        $booking['booking_id'],
        $booking['checkin'],
        $booking['checkout'],
        $booking['guests'],
        $booking['requested_checkin_time'] ?? null,
        $booking['special_requests'] ?? '',
        $booking['payment_status'] ?? 'Payment Proof Submitted'
    );
}

/**
 * Wrapper function: Send admin new booking email using booking array
 * 
 * @param array $booking Booking data array
 * @return bool True if email sent successfully
 */
function sendAdminNewBookingEmail($booking) {
    return sendNewBookingNotificationToAdmin(
        $booking['customer_name'],
        $booking['customer_email'],
        $booking['customer_phone'],
        $booking['booking_id'],
        $booking['checkin'],
        $booking['checkout'],
        $booking['guests'],
        $booking['requested_checkin_time'] ?? null,
        $booking['special_requests'] ?? '',
        $booking['payment_status'] ?? 'Submitted',
        $booking['payment_amount'] ?? null,
        $booking['payment_reference'] ?? null,
        $booking['payment_proof'] ?? null
    );
}

/**
 * Wrapper function: Send booking approved email using booking array
 * 
 * @param array $booking Booking data array
 * @return bool True if email sent successfully
 */
function sendBookingApprovedEmail($booking) {
    return sendBookingApprovalEmail(
        $booking['customer_name'],
        $booking['customer_email'],
        $booking['booking_id'],
        $booking['checkin'],
        $booking['checkout'],
        $booking['guests'],
        $booking['confirmed_checkin_time'] ?? null
    );
}

/**
 * Wrapper function: Send booking rejected email using booking array
 *
 * @param array $booking Booking data array
 * @return bool True if email sent successfully
 */
function sendBookingRejectedEmail($booking) {
    return sendBookingRejectionEmail(
        $booking['customer_name'],
        $booking['customer_email'],
        $booking['booking_id'],
        $booking['checkin'],
        $booking['checkout'],
        $booking['rejection_reason'] ?? null
    );
}

/**
 * Wrapper function: Send booking rebook email using booking array
 *
 * @param array $booking Booking data array
 * @param string $oldCheckin Original check-in date
 * @param string $oldCheckout Original check-out date
 * @param string $newCheckin New check-in date
 * @param string $newCheckout New check-out date
 * @return bool True if email sent successfully
 */
function sendBookingRebookedEmail($booking, $oldCheckin, $oldCheckout, $newCheckin, $newCheckout) {
    return sendBookingRebookEmail(
        $booking['name'],
        $booking['email'],
        $booking['booking_id'],
        $oldCheckin,
        $oldCheckout,
        $newCheckin,
        $newCheckout
    );
}

/**
 * Ensure check-in reminder email tracking columns exist on bookings table
 */
function ensureCheckinReminderEmailColumns($conn) {
    $columns = [
        'checkin_reminder_scheduled_at' => "ALTER TABLE bookings ADD COLUMN checkin_reminder_scheduled_at DATETIME NULL",
        'checkin_reminder_sent_at' => "ALTER TABLE bookings ADD COLUMN checkin_reminder_sent_at DATETIME NULL",
        'checkin_reminder_status' => "ALTER TABLE bookings ADD COLUMN checkin_reminder_status VARCHAR(50) DEFAULT 'Not Scheduled'"
    ];

    foreach ($columns as $column => $alterSql) {
        $check = $conn->query("SHOW COLUMNS FROM bookings LIKE '$column'");
        if ($check && $check->num_rows === 0) {
            $conn->query($alterSql);
        }
    }
}

/**
 * Ensure review email tracking columns exist on bookings table
 */
function ensureReviewEmailColumns($conn) {
    $columns = [
        'review_email_scheduled_at' => "ALTER TABLE bookings ADD COLUMN review_email_scheduled_at DATETIME NULL",
        'review_email_sent_at' => "ALTER TABLE bookings ADD COLUMN review_email_sent_at DATETIME NULL",
        'review_email_status' => "ALTER TABLE bookings ADD COLUMN review_email_status VARCHAR(50) DEFAULT 'Not Scheduled'"
    ];

    foreach ($columns as $column => $alterSql) {
        $check = $conn->query("SHOW COLUMNS FROM bookings LIKE '$column'");
        if ($check && $check->num_rows === 0) {
            $conn->query($alterSql);
            continue;
        }

        if ($check && ($definition = $check->fetch_assoc())) {
            if (in_array($column, ['review_email_scheduled_at', 'review_email_sent_at'], true)
                && strtoupper((string)$definition['Null']) !== 'YES') {
                $conn->query("ALTER TABLE bookings MODIFY COLUMN $column DATETIME NULL DEFAULT NULL");
            }
        }
    }
}

/**
 * Check if booking status allows check-in reminder email
 */
function isBookingEligibleForCheckinReminder($status) {
    $eligibleStatuses = [
        'booking_confirmed',
        'payment_pending',
        'payment_under_review',
        'payment_confirmed',
        'arrival_notice_sent'
    ];

    return in_array(strtolower((string)$status), $eligibleStatuses, true);
}

/**
 * Check if booking status allows post-checkout review email
 */
function isBookingEligibleForReviewEmail($status) {
    $eligibleStatuses = [
        'booking_confirmed',
        'confirmed',
        'payment_pending',
        'payment_under_review',
        'payment_confirmed',
        'arrival_notice_sent',
        'completed'
    ];

    return in_array(strtolower((string)$status), $eligibleStatuses, true);
}

/**
 * Calculate check-in reminder email scheduled time (on the exact check-in date at 9:00 AM)
 *
 * @param string $checkinDate Check-in date (Y-m-d format)
 * @return string Scheduled datetime in Y-m-d H:i:s format
 */
function calculateCheckinReminderSchedule($checkinDate) {
    $timezone = date_default_timezone_get();
    if (!$timezone) {
        date_default_timezone_set('Asia/Manila');
    }

    // Schedule for 9:00 AM on the check-in date
    return $checkinDate . ' 09:00:00';
}

/**
 * Schedule check-in reminder email for a booking after admin confirmation
 */
function scheduleCheckinReminderForBooking($conn, $bookingId, $checkinDate) {
    ensureCheckinReminderEmailColumns($conn);

    $bookingId = $conn->real_escape_string($bookingId);
    $scheduledTime = calculateCheckinReminderSchedule($checkinDate);

    $sql = "UPDATE bookings SET
            checkin_reminder_scheduled_at = '$scheduledTime',
            checkin_reminder_status = 'Scheduled',
            checkin_reminder_sent_at = NULL
            WHERE booking_id = '$bookingId'";

    return $conn->query($sql);
}

/**
 * Calculate review email scheduled time based on checkout date and time
 *
 * @param string $checkoutDate Checkout date (Y-m-d format)
 * @return string Scheduled datetime in Y-m-d H:i:s format
 */
function calculateReviewEmailSchedule($checkoutDate) {
    global $defaultCheckOutTime, $reviewEmailDelayMinutes;

    $timezone = date_default_timezone_get();
    if (!$timezone) {
        date_default_timezone_set('Asia/Manila');
    }

    $checkoutDateTime = $checkoutDate . ' ' . $defaultCheckOutTime;
    return date('Y-m-d H:i:s', strtotime($checkoutDateTime . " +{$reviewEmailDelayMinutes} minutes"));
}

/**
 * Schedule review email for a booking after admin confirmation
 */
function scheduleReviewEmailForBooking($conn, $bookingId, $checkoutDate) {
    ensureReviewEmailColumns($conn);

    $bookingId = $conn->real_escape_string($bookingId);
    $scheduledTime = calculateReviewEmailSchedule($checkoutDate);

    $sql = "UPDATE bookings SET
            review_email_scheduled_at = '$scheduledTime',
            review_email_status = 'Scheduled',
            review_email_sent_at = NULL
            WHERE booking_id = '$bookingId'";

    return $conn->query($sql);
}

/**
 * Process due check-in reminder emails (used by cron and admin auto-processor)
 *
 * @return array Summary of processed emails
 */
function processDueCheckinReminderEmails($conn) {
    ensureCheckinReminderEmailColumns($conn);

    $summary = [
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0
    ];

    $sql = "SELECT * FROM bookings
            WHERE checkin_reminder_status = 'Scheduled'
            AND checkin_reminder_scheduled_at IS NOT NULL
            AND checkin_reminder_scheduled_at <= NOW()
            AND (checkin_reminder_sent_at IS NULL OR checkin_reminder_sent_at = '0000-00-00 00:00:00')
            AND status NOT IN ('pending_booking_confirmation', 'cancelled', 'completed')
            ORDER BY checkin_reminder_scheduled_at ASC";

    $result = $conn->query($sql);
    if (!$result) {
        error_log('Check-in reminder email query failed: ' . $conn->error);
        return $summary;
    }

    while ($booking = $result->fetch_assoc()) {
        $summary['processed']++;
        $bookingId = $booking['booking_id'];

        if (!isBookingEligibleForCheckinReminder($booking['status'])) {
            $skipSql = "UPDATE bookings SET
                        checkin_reminder_status = 'Skipped - Status Changed',
                        checkin_reminder_sent_at = NOW()
                        WHERE booking_id = '" . $conn->real_escape_string($bookingId) . "'";
            $conn->query($skipSql);
            $summary['skipped']++;
            continue;
        }

        $bookingData = [
            'booking_id' => $booking['booking_id'],
            'customer_name' => $booking['name'],
            'customer_email' => $booking['email'],
            'status' => $booking['status'],
            'checkin' => $booking['checkin'],
            'checkout' => $booking['checkout'],
            'requested_checkin_time' => $booking['requested_checkin_time'] ?? null
        ];

        if (sendCheckinReminderEmail($bookingData)) {
            $updateSql = "UPDATE bookings SET
                          checkin_reminder_sent_at = NOW(),
                          checkin_reminder_status = 'Sent'
                          WHERE booking_id = '" . $conn->real_escape_string($bookingId) . "'";
            $conn->query($updateSql);
            $summary['sent']++;
        } else {
            $updateSql = "UPDATE bookings SET checkin_reminder_status = 'Failed'
                          WHERE booking_id = '" . $conn->real_escape_string($bookingId) . "'";
            $conn->query($updateSql);
            $summary['failed']++;
        }
    }

    return $summary;
}

/**
 * Backfill review email schedule for confirmed bookings that were never scheduled
 */
function backfillUnscheduledReviewEmails($conn) {
    ensureReviewEmailColumns($conn);

    $sql = "SELECT booking_id, checkout FROM bookings
            WHERE status IN ('booking_confirmed', 'payment_pending', 'payment_under_review', 'payment_confirmed', 'arrival_notice_sent', 'completed')
            AND checkout IS NOT NULL
            AND checkout != ''
            AND (review_email_status IS NULL OR review_email_status = '' OR review_email_status = 'Not Scheduled')
            AND (review_email_sent_at IS NULL OR review_email_sent_at = '0000-00-00 00:00:00')";

    $result = $conn->query($sql);
    if (!$result) {
        error_log('Review email backfill query failed: ' . $conn->error);
        return 0;
    }

    $count = 0;
    while ($row = $result->fetch_assoc()) {
        if (scheduleReviewEmailForBooking($conn, $row['booking_id'], $row['checkout'])) {
            $count++;
        }
    }

    return $count;
}

/**
 * Process due review emails (used by cron and admin auto-processor)
 *
 * @return array Summary of processed emails
 */
function processDueReviewEmails($conn) {
    ensureReviewEmailColumns($conn);

    $summary = [
        'processed' => 0,
        'sent' => 0,
        'failed' => 0,
        'skipped' => 0
    ];

    $sql = "SELECT * FROM bookings
            WHERE review_email_status = 'Scheduled'
            AND review_email_scheduled_at IS NOT NULL
            AND review_email_scheduled_at <= NOW()
            AND (review_email_sent_at IS NULL OR review_email_sent_at = '0000-00-00 00:00:00')
            AND status NOT IN ('pending_booking_confirmation', 'cancelled')
            ORDER BY review_email_scheduled_at ASC";

    $result = $conn->query($sql);
    if (!$result) {
        error_log('Review email query failed: ' . $conn->error);
        return $summary;
    }

    while ($booking = $result->fetch_assoc()) {
        $summary['processed']++;
        $bookingId = $booking['booking_id'];

        if (!isBookingEligibleForReviewEmail($booking['status'])) {
            $skipSql = "UPDATE bookings SET
                        review_email_status = 'Skipped - Status Changed',
                        review_email_sent_at = NOW()
                        WHERE booking_id = '" . $conn->real_escape_string($bookingId) . "'";
            $conn->query($skipSql);
            $summary['skipped']++;
            continue;
        }

        $bookingData = [
            'booking_id' => $booking['booking_id'],
            'customer_name' => $booking['name'],
            'customer_email' => $booking['email'],
            'status' => $booking['status'],
            'checkin' => $booking['checkin'],
            'checkout' => $booking['checkout']
        ];

        if (sendGoogleReviewEmail($bookingData)) {
            $updateSql = "UPDATE bookings SET
                          review_email_sent_at = NOW(),
                          review_email_status = 'Sent'
                          WHERE booking_id = '" . $conn->real_escape_string($bookingId) . "'";
            $conn->query($updateSql);
            $summary['sent']++;
        } else {
            $updateSql = "UPDATE bookings SET review_email_status = 'Failed'
                          WHERE booking_id = '" . $conn->real_escape_string($bookingId) . "'";
            $conn->query($updateSql);
            $summary['failed']++;
        }
    }

    return $summary;
}

/**
 * Send check-in reminder email to guest
 *
 * @param array $booking Booking data array
 * @return bool True if email sent successfully, false otherwise
 */
function sendCheckinReminderEmail($booking) {
    global $propertyName, $phpmailer_installed;

    if (!isset($booking['status']) || !isBookingEligibleForCheckinReminder($booking['status'])) {
        error_log("Check-in reminder email not sent: Booking {$booking['booking_id']} has ineligible status '{$booking['status']}'");
        return false;
    }

    // Validate customer email
    if (!isset($booking['customer_email']) || !filter_var($booking['customer_email'], FILTER_VALIDATE_EMAIL)) {
        error_log("Check-in reminder email not sent: Invalid email for booking {$booking['booking_id']}");
        return false;
    }

    if (!$phpmailer_installed) {
        error_log("Check-in reminder email not sent: PHPMailer not installed");
        return false;
    }

    $customerName = htmlspecialchars($booking['customer_name'] ?? 'Guest');
    $bookingId = htmlspecialchars($booking['booking_id']);
    $checkinFormatted = date('F j, Y', strtotime($booking['checkin']));
    $checkoutFormatted = date('F j, Y', strtotime($booking['checkout']));
    $checkInTime = getSuggestedCheckInTime($booking['requested_checkin_time'] ?? null);
    $checkOutTime = getCheckOutTime();

    // Build HTML email content
    $content = '
        <p>Dear ' . $customerName . ',</p>
        <p>This is a friendly reminder that your check-in is <strong>today</strong>!</p>
        <p>We are excited to welcome you to ' . htmlspecialchars($propertyName) . '.</p>
        <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #c9a86c; margin: 20px 0;">
            <h3 style="margin-top: 0; color: #333;">Booking ID: ' . $bookingId . '</h3>
            <p><strong>Check-in:</strong> ' . $checkinFormatted . ' at ' . $checkInTime . '</p>
            <p><strong>Check-out:</strong> ' . $checkoutFormatted . ' at ' . $checkOutTime . '</p>
        </div>
        <p><strong>Check-in Time:</strong> ' . $checkInTime . '</p>
        <p><strong>Check-out Time:</strong> ' . $checkOutTime . '</p>
        <p>Please arrive on time for a smooth check-in process. If you have any questions or need to contact us, please reach out to solendrasamal@gmail.com or 0945 588 7095.</p>
        <p>We look forward to hosting you!</p>
        <p>Best regards,<br>' . htmlspecialchars($propertyName) . '</p>
    ';

    $htmlBody = generateEmailTemplate('Check-in Reminder - ' . $bookingId, $content);

    // Use sendBookingEmail for consistent spam prevention headers
    return sendBookingEmail(
        $booking['customer_email'],
        $booking['customer_name'],
        'Check-in Reminder - ' . $bookingId,
        $htmlBody,
        'checkin_reminder',
        $booking['booking_id']
    );
}

/**
 * Send Google Review email to guest
 *
 * @param array $booking Booking data array
 * @return bool True if email sent successfully, false otherwise
 */
function sendGoogleReviewEmail($booking) {
    global $googleReviewUrl, $propertyName, $phpmailer_installed;

    if (!isset($booking['status']) || !isBookingEligibleForReviewEmail($booking['status'])) {
        error_log("Review email not sent: Booking {$booking['booking_id']} has ineligible status '{$booking['status']}'");
        return false;
    }

    // Validate customer email
    if (!isset($booking['customer_email']) || !filter_var($booking['customer_email'], FILTER_VALIDATE_EMAIL)) {
        error_log("Review email not sent: Invalid email for booking {$booking['booking_id']}");
        return false;
    }

    // Check if Google review URL is configured
    if ($googleReviewUrl === 'YOUR_GOOGLE_REVIEW_LINK') {
        error_log("Review email not sent: Google review URL not configured");
        return false;
    }

    if (!$phpmailer_installed) {
        error_log("Review email not sent: PHPMailer not installed");
        return false;
    }

    $customerName = htmlspecialchars($booking['customer_name'] ?? 'Guest');
    $bookingId = htmlspecialchars($booking['booking_id']);
    $checkinFormatted = date('F j, Y', strtotime($booking['checkin']));
    $checkoutFormatted = date('F j, Y', strtotime($booking['checkout']));

    // Build HTML email content
    $content = '
        <p>Dear ' . $customerName . ',</p>
        <p>Thank you for staying with us!</p>
        <p>We hope you had a wonderful stay at ' . htmlspecialchars($propertyName) . '.</p>
        <p>If you enjoyed your experience, we would really appreciate it if you could take a moment to leave us a review on Google.</p>
        <p>Your feedback helps us improve and also helps future guests know what to expect.</p>

        <div style="text-align: center; margin: 30px 0;">
            <a href="' . htmlspecialchars($googleReviewUrl) . '"
               target="_blank"
               style="display: inline-block; padding: 15px 30px; background-color: #c9a86c; color: #ffffff; text-decoration: none; border-radius: 5px; font-weight: bold; font-size: 16px;">
               ⭐ Leave a Google Review
            </a>
        </div>

        <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #c9a86c; margin: 20px 0;">
            <p style="margin: 0;"><strong>Booking ID:</strong> ' . $bookingId . '</p>
            <p style="margin: 5px 0;"><strong>Stay:</strong> ' . $checkinFormatted . ' – ' . $checkoutFormatted . '</p>
        </div>

        <p>Thank you for choosing ' . htmlspecialchars($propertyName) . '!</p>
        <p>We hope to welcome you again soon.</p>

        <p>Best regards,<br>' . htmlspecialchars($propertyName) . '</p>

        <hr style="border: none; border-top: 1px solid #e0e0e0; margin: 30px 0;">
        <p style="font-size: 12px; color: #666;">If the button doesn\'t work, please copy and paste this link into your browser:</p>
        <p style="font-size: 12px; color: #666; word-break: break-all;">' . htmlspecialchars($googleReviewUrl) . '</p>
    ';

    $htmlBody = generateEmailTemplate('How Was Your Stay at ' . $propertyName . '?', $content);

    // Use sendBookingEmail for consistent spam prevention headers
    return sendBookingEmail(
        $booking['customer_email'],
        $booking['customer_name'],
        'How Was Your Stay at ' . $propertyName . '?',
        $htmlBody,
        'google_review',
        $booking['booking_id']
    );
}
