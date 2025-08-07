<?php
// Forgot password endpoint
require_once 'config.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, X-Requested-With');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    sendResponse(false, 'Only POST method allowed');
}

$input = json_decode(file_get_contents('php://input'), true);

// Validate required fields
if (!isset($input['email']) || empty(trim($input['email']))) {
    sendResponse(false, 'Email is required');
}

// Validate email format
if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
    sendResponse(false, 'Invalid email format');
}

$pdo = getDbConnection();

try {
    // Check if email exists
    $stmt = $pdo->prepare("SELECT id, name, surname, email FROM clients WHERE email = ?");
    $stmt->execute([trim($input['email'])]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$client) {
        // For security, we don't reveal if email exists or not
        sendResponse(true, 'If this email exists in our system, you will receive a password reset link shortly.');
        exit();
    }
    
    // Generate reset token
    $resetToken = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+1 hour')); // Token expires in 1 hour
    
    // Store reset token in database
    $stmt = $pdo->prepare("
        INSERT INTO password_resets (client_id, email, token, expires_at, created_at) 
        VALUES (?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE 
        token = VALUES(token), 
        expires_at = VALUES(expires_at), 
        created_at = NOW()
    ");
    $stmt->execute([$client['id'], $client['email'], $resetToken, $expiresAt]);
    
    // Send password reset email
    $emailSent = sendPasswordResetEmail($client, $resetToken);
    
    $response = [
        'email_sent' => $emailSent
    ];
    
    sendResponse(true, 'If this email exists in our system, you will receive a password reset link shortly.', $response);
    
} catch (PDOException $e) {
    error_log("Forgot password error: " . $e->getMessage());
    sendResponse(false, 'An error occurred. Please try again later.');
}

function sendPasswordResetEmail($client, $resetToken) {
    try {
        $to = $client['email'];
        $subject = "Password Reset - Khuluma App";
        
        // Create HTML email content
        $htmlMessage = createPasswordResetEmailHTML($client, $resetToken);
        
        // Email headers
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: Khuluma App <communications@khulumaeswatini.com>',
            'Reply-To: support@khulumaeswatini.com',
            'X-Mailer: PHP/' . phpversion(),
            'X-Priority: 3',
            'Return-Path: communications@khulumaeswatini.com'
        ];
        
        $headerString = implode("\r\n", $headers);
        
        // Send email
        $emailSent = mail($to, $subject, $htmlMessage, $headerString);
        
        // Log email attempt
        $logMessage = $emailSent ? "Password reset email sent successfully to: $to" : "Failed to send password reset email to: $to";
        error_log($logMessage);
        
        return $emailSent;
        
    } catch (Exception $e) {
        error_log("Password reset email error: " . $e->getMessage());
        return false;
    }
}

function createPasswordResetEmailHTML($client, $resetToken) {
    $name = htmlspecialchars($client['name']);
    $surname = htmlspecialchars($client['surname']);
    $email = htmlspecialchars($client['email']);
    $resetLink = "https://khulumaeswatini.com/client/reset-password?token=" . $resetToken;
    
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Password Reset - Khuluma App</title>
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
            }
            .logo {
                background: linear-gradient(135deg, #F5D488 0%, #E9C46A 100%);
                color: white;
                padding: 20px;
                border-radius: 20px;
                display: inline-block;
                width: 60px;
                height: 60px;
                line-height: 60px;
                font-size: 24px;
                margin-bottom: 20px;
            }
            .reset-title {
                color: #2c3e50;
                font-size: 28px;
                margin-bottom: 10px;
            }
            .content {
                margin-bottom: 30px;
            }
            .alert {
                background-color: #fff3cd;
                color: #856404;
                padding: 15px;
                border-radius: 8px;
                border: 1px solid #ffeaa7;
                margin: 20px 0;
            }
            .button {
                background: linear-gradient(135deg, #B80000 0%, #8B0000 100%);
                color: white;
                padding: 15px 30px;
                text-decoration: none;
                border-radius: 8px;
                display: inline-block;
                font-weight: bold;
                margin: 20px 0;
            }
            .footer {
                text-align: center;
                margin-top: 30px;
                padding-top: 20px;
                border-top: 1px solid #eee;
                color: #666;
                font-size: 14px;
            }
            .token-box {
                background-color: #f8f9fa;
                padding: 15px;
                border-radius: 8px;
                font-family: monospace;
                word-break: break-all;
                margin: 20px 0;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo'>🔐</div>
                <h1 class='reset-title'>Password Reset Request</h1>
                <p>We received a request to reset your password</p>
            </div>
            
            <div class='content'>
                <p>Dear <strong>$name $surname</strong>,</p>
                
                <p>We received a request to reset the password for your Khuluma App account associated with <strong>$email</strong>.</p>
                
                <div class='alert'>
                    <strong>⚠️ Important:</strong> This link will expire in 1 hour for security reasons.
                </div>
                
                <p>To reset your password, click the button below:</p>
                
                <div style='text-align: center;'>
                    <a href='$resetLink' class='button' style='color: white;'>Reset My Password</a>
                </div>
                
                <p>If the button doesn't work, you can copy and paste this link into your browser:</p>
                <div class='token-box'>$resetLink</div>
                
                <p><strong>If you didn't request this password reset:</strong></p>
                <ul>
                    <li>You can safely ignore this email</li>
                    <li>Your password will remain unchanged</li>
                    <li>Consider changing your password if you suspect unauthorized access</li>
                </ul>
                
                <p>If you continue to have problems, please contact our support team at <a href='mailto:support@khulumaeswatini.com'>support@khulumaeswatini.com</a>.</p>
                
                <p>Best regards,<br>
                <strong>The Khuluma App Team</strong></p>
            </div>
            
            <div class='footer'>
                <p>This email was sent to $email because a password reset was requested for your Khuluma App account.</p>
                <p>© 2025 Khuluma App. All rights reserved.</p>
                <p>If you have any questions, contact us at support@khulumaeswatini.com</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

?>
