<?php
/**
 * Test Email Deliverability
 * This script tests the email configuration and sends a test email
 * to verify that the spam prevention headers are working correctly.
 */

require_once __DIR__ . '/email_helper.php';

// Test email recipient
$testEmail = 'emirencegumban@gmail.com'; // Change to your test email
$testName = 'Test Recipient';

// Generate test email content
$testContent = '
    <h2>Email Deliverability Test</h2>
    <p>This is a test email to verify that your booking emails are configured correctly for inbox delivery.</p>
    <div style="background-color: #f9f9f9; padding: 20px; border-left: 4px solid #4CAF50; margin: 20px 0;">
        <h3 style="margin-top: 0; color: #333;">Test Details</h3>
        <p><strong>Test Date:</strong> ' . date('F j, Y, g:i a') . '</p>
        <p><strong>Headers Added:</strong></p>
        <ul>
            <li>X-Priority: 3 (Normal)</li>
            <li>X-MSMail-Priority: Normal</li>
            <li>X-Mailer: PHPMailer</li>
            <li>X-Auto-Response-Suppress: All</li>
            <li>List-Unsubscribe: One-click unsubscribe</li>
            <li>Reply-To: Set to sender</li>
        </ul>
    </div>
    <p>If you receive this email in your inbox, the spam prevention headers are working correctly.</p>
    <p><strong>Next Steps:</strong></p>
    <ol>
        <li>Check the email headers (in Gmail: More → Show original)</li>
        <li>Verify the custom headers are present</li>
        <li>Test booking emails to ensure they also reach the inbox</li>
    </ol>
';

$htmlBody = generateEmailTemplate('Email Deliverability Test', $testContent);

echo "Sending test email to: $testEmail\n";
echo "--------------------------------------------------\n";

$result = sendBookingEmail($testEmail, $testName, 'Email Deliverability Test', $htmlBody, 'test_deliverability', 'TEST001');

if ($result) {
    echo "✓ Test email sent successfully!\n";
    echo "Please check your inbox (and spam folder) for the test email.\n";
    echo "\nIf the email goes to spam, check the email headers to see which spam filters triggered.\n";
} else {
    echo "✗ Failed to send test email.\n";
    echo "Check the error logs for details.\n";
}

echo "--------------------------------------------------\n";
