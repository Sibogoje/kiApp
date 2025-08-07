<?php
// Reset password endpoint
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
if (!isset($input['token']) || empty(trim($input['token']))) {
    sendResponse(false, 'Reset token is required');
}

if (!isset($input['password']) || empty(trim($input['password']))) {
    sendResponse(false, 'New password is required');
}

// Validate password strength
if (strlen($input['password']) < 6) {
    sendResponse(false, 'Password must be at least 6 characters long');
}

$pdo = getDbConnection();

try {
    // Check if token exists and is valid
    $stmt = $pdo->prepare("
        SELECT pr.id, pr.client_id, pr.email, c.name, c.surname 
        FROM password_resets pr
        JOIN clients c ON pr.client_id = c.id
        WHERE pr.token = ? AND pr.expires_at > NOW() AND pr.used_at IS NULL
    ");
    $stmt->execute([trim($input['token'])]);
    $resetRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$resetRecord) {
        sendResponse(false, 'Invalid or expired reset token');
    }
    
    // Hash the new password
    $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);
    
    // Begin transaction
    $pdo->beginTransaction();
    
    try {
        // Update the client's password
        $stmt = $pdo->prepare("UPDATE clients SET password_hash = ? WHERE id = ?");
        $stmt->execute([$passwordHash, $resetRecord['client_id']]);
        
        // Mark the reset token as used
        $stmt = $pdo->prepare("UPDATE password_resets SET used_at = NOW() WHERE id = ?");
        $stmt->execute([$resetRecord['id']]);
        
        // Delete any other unused reset tokens for this client
        $stmt = $pdo->prepare("DELETE FROM password_resets WHERE client_id = ? AND used_at IS NULL AND id != ?");
        $stmt->execute([$resetRecord['client_id'], $resetRecord['id']]);
        
        // Commit transaction
        $pdo->commit();
        
        // Send confirmation email
        $emailSent = sendPasswordResetConfirmationEmail($resetRecord);
        
        $response = [
            'email_sent' => $emailSent
        ];
        
        sendResponse(true, 'Password reset successfully! You can now log in with your new password.', $response);
        
    } catch (Exception $e) {
        $pdo->rollback();
        throw $e;
    }
    
} catch (PDOException $e) {
    error_log("Reset password error: " . $e->getMessage());
    sendResponse(false, 'An error occurred while resetting your password. Please try again.');
}

function sendPasswordResetConfirmationEmail($client) {
    try {
        $to = $client['email'];
        $subject = "Password Reset Confirmation - Khuluma App";
        
        // Create HTML email content
        $htmlMessage = createPasswordResetConfirmationEmailHTML($client);
        
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
        $logMessage = $emailSent ? "Password reset confirmation email sent successfully to: $to" : "Failed to send password reset confirmation email to: $to";
        error_log($logMessage);
        
        return $emailSent;
        
    } catch (Exception $e) {
        error_log("Password reset confirmation email error: " . $e->getMessage());
        return false;
    }
}

function createPasswordResetConfirmationEmailHTML($client) {
    $name = htmlspecialchars($client['name']);
    $surname = htmlspecialchars($client['surname']);
    $email = htmlspecialchars($client['email']);
    $timestamp = date('F j, Y \a\t g:i A');
    
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Password Reset Confirmation - Khuluma App</title>
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
            .success-title {
                color: #2c3e50;
                font-size: 28px;
                margin-bottom: 10px;
            }
            .content {
                margin-bottom: 30px;
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
                padding: 15px;
                border-radius: 8px;
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
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo'>✅</div>
                <h1 class='success-title'>Password Reset Successful</h1>
                <p>Your password has been updated successfully</p>
            </div>
            
            <div class='content'>
                <p>Dear <strong>$name $surname</strong>,</p>
                
                <div class='success'>
                    <strong>✅ Your password has been reset successfully!</strong>
                </div>
                
                <p>This email confirms that the password for your Khuluma App account (<strong>$email</strong>) has been successfully reset on <strong>$timestamp</strong>.</p>
                
                <div class='info'>
                    <strong>What's next?</strong><br>
                    You can now log in to your account using your new password. For your security, please:
                    <ul>
                        <li>Use a strong, unique password</li>
                        <li>Don't share your password with anyone</li>
                        <li>Log out from shared devices</li>
                    </ul>
                </div>
                
                <div style='text-align: center;'>
                    <a href='https://khulumaeswatini.com/client' class='button' style='color: white;'>Login to Your Account</a>
                </div>
                
                <p><strong>If you didn't make this change:</strong></p>
                <p>If you didn't reset your password, please contact our support team immediately at <a href='mailto:support@khulumaeswatini.com'>support@khulumaeswatini.com</a>. This could indicate unauthorized access to your account.</p>
                
                <p>Thank you for using Khuluma App!</p>
                
                <p>Best regards,<br>
                <strong>The Khuluma App Team</strong></p>
            </div>
            
            <div class='footer'>
                <p>This email was sent to $email because your password was reset for your Khuluma App account.</p>
                <p>© 2025 Khuluma App. All rights reserved.</p>
                <p>If you have any questions, contact us at support@khulumaeswatini.com</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

?>
