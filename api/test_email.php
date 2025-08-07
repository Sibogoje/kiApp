<?php
// Email testing endpoint
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Test basic mail configuration
    testMailConfiguration();
} elseif ($method === 'POST') {
    // Send test email
    $input = json_decode(file_get_contents('php://input'), true);
    sendTestEmail($input);
} else {
    sendResponse(false, 'Method not allowed');
}

function testMailConfiguration() {
    $info = [
        'php_version' => phpversion(),
        'mail_function_exists' => function_exists('mail'),
        'sendmail_path' => ini_get('sendmail_path'),
        'smtp_server' => ini_get('SMTP'),
        'smtp_port' => ini_get('smtp_port'),
        'server_name' => $_SERVER['SERVER_NAME'] ?? 'unknown',
        'server_admin' => $_SERVER['SERVER_ADMIN'] ?? 'not set'
    ];
    
    sendResponse(true, 'Mail configuration retrieved', $info);
}

function sendTestEmail($input) {
    $to = $input['email'] ?? null;
    
    if (!$to) {
        sendResponse(false, 'Email address required');
    }
    
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        sendResponse(false, 'Invalid email address');
    }
    
    try {
        $subject = "Test Email from OpportunityTracker";
        $message = createTestEmailHTML($to);
        
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: OpportunityTracker <noreply@khulumaeswatini.com>',
            'Reply-To: support@khulumaeswatini.com',
            'X-Mailer: PHP/' . phpversion()
        ];
        
        $headerString = implode("\r\n", $headers);
        
        $emailSent = mail($to, $subject, $message, $headerString);
        
        if ($emailSent) {
            error_log("Test email sent successfully to: $to");
            sendResponse(true, 'Test email sent successfully', [
                'recipient' => $to,
                'timestamp' => date('Y-m-d H:i:s')
            ]);
        } else {
            error_log("Failed to send test email to: $to");
            sendResponse(false, 'Failed to send test email');
        }
        
    } catch (Exception $e) {
        error_log("Test email error: " . $e->getMessage());
        sendResponse(false, 'Email sending error: ' . $e->getMessage());
    }
}

function createTestEmailHTML($email) {
    $timestamp = date('F j, Y \a\t g:i A');
    
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Test Email from OpportunityTracker</title>
        <style>
            body {
                font-family: Arial, sans-serif;
                line-height: 1.6;
                color: #333;
                max-width: 600px;
                margin: 0 auto;
                padding: 20px;
                background-color: #f4f4f4;
            }
            .container {
                background-color: white;
                padding: 30px;
                border-radius: 10px;
                box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            }
            .header {
                text-align: center;
                margin-bottom: 30px;
                color: #667eea;
            }
            .success {
                background-color: #d4edda;
                color: #155724;
                padding: 15px;
                border-radius: 8px;
                border: 1px solid #c3e6cb;
                margin: 20px 0;
            }
            .info {
                background-color: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>🎯 OpportunityTracker</h1>
                <h2>Email Test Successful!</h2>
            </div>
            
            <div class='success'>
                <strong>✅ Email functionality is working correctly!</strong>
            </div>
            
            <div class='info'>
                <h3>Test Details:</h3>
                <ul>
                    <li><strong>Recipient:</strong> $email</li>
                    <li><strong>Sent at:</strong> $timestamp</li>
                    <li><strong>Server:</strong> " . ($_SERVER['SERVER_NAME'] ?? 'Unknown') . "</li>
                    <li><strong>PHP Version:</strong> " . phpversion() . "</li>
                </ul>
            </div>
            
            <p>If you received this email, it means the mail configuration is working properly and welcome emails will be sent to new users when they register.</p>
            
            <p>This was a test email sent from the OpportunityTracker email testing system.</p>
            
            <hr>
            <p style='text-align: center; color: #666; font-size: 14px;'>
                © 2025 OpportunityTracker. All rights reserved.
            </p>
        </div>
    </body>
    </html>
    ";
}

?>
