<?php
// Client registration endpoint
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
$requiredFields = ['name', 'surname', 'email', 'password', 'phone', 'date_of_birth'];
foreach ($requiredFields as $field) {
    if (!isset($input[$field]) || empty(trim($input[$field]))) {
        sendResponse(false, ucfirst($field) . ' is required');
    }
}

// Validate email format
if (!filter_var($input['email'], FILTER_VALIDATE_EMAIL)) {
    sendResponse(false, 'Invalid email format');
}

// Validate password strength
if (strlen($input['password']) < 6) {
    sendResponse(false, 'Password must be at least 6 characters long');
}

// Validate phone number (basic validation)
$phone = preg_replace('/[^0-9+]/', '', $input['phone']);
if (strlen($phone) < 8) {
    sendResponse(false, 'Invalid phone number');
}

// Validate date of birth
try {
    $dob = new DateTime($input['date_of_birth']);
    $today = new DateTime();
    $age = $today->diff($dob)->y;
    
    if ($age < 16) {
        sendResponse(false, 'You must be at least 16 years old to register');
    }
    if ($age > 100) {
        sendResponse(false, 'Invalid date of birth');
    }
} catch (Exception $e) {
    sendResponse(false, 'Invalid date of birth format. Use YYYY-MM-DD');
}

$pdo = getDbConnection();

try {
    // Check if email already exists
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE email = ?");
    $stmt->execute([$input['email']]);
    if ($stmt->fetch()) {
        sendResponse(false, 'Email address is already registered');
    }

    // Check if phone already exists
    $stmt = $pdo->prepare("SELECT id FROM clients WHERE phone = ?");
    $stmt->execute([$phone]);
    if ($stmt->fetch()) {
        sendResponse(false, 'Phone number is already registered');
    }

    // Hash password
    $passwordHash = password_hash($input['password'], PASSWORD_DEFAULT);

    // Insert new client
    $stmt = $pdo->prepare("
        INSERT INTO clients (
            name, surname, email, password_hash, phone, 
            date_of_birth, address, status, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', NOW())
    ");
    
    $stmt->execute([
        trim($input['name']),
        trim($input['surname']),
        trim($input['email']),
        $passwordHash,
        $phone,
        $input['date_of_birth'],
        isset($input['address']) ? trim($input['address']) : null
    ]);

    $clientId = $pdo->lastInsertId();

    // Get the created client data (without password)
    $stmt = $pdo->prepare("
        SELECT id, name, surname, email, phone, date_of_birth, 
               address, profile_image, status, created_at 
        FROM clients WHERE id = ?
    ");
    $stmt->execute([$clientId]);
    $client = $stmt->fetch(PDO::FETCH_ASSOC);

    // Send welcome email
    $emailSent = sendWelcomeEmail($client);
    
    $response = [
        'client' => $client,
        'email_sent' => $emailSent
    ];

    sendResponse(true, 'Registration successful! You can now log in.', $response);

} catch (PDOException $e) {
    error_log("Registration error: " . $e->getMessage());
    
    // Check for specific constraint violations
    if (strpos($e->getMessage(), 'email') !== false) {
        sendResponse(false, 'Email address is already registered');
    } elseif (strpos($e->getMessage(), 'phone') !== false) {
        sendResponse(false, 'Phone number is already registered');
    } else {
        sendResponse(false, 'Registration failed. Please try again.');
    }
}

function sendWelcomeEmail($client) {
    try {
        $to = $client['email'];
        $subject = "Welcome to Khuluma App - Registration Successful!";
        
        // Create HTML email content
        $htmlMessage = createWelcomeEmailHTML($client);
        
        // Create plain text version as fallback
        $textMessage = createWelcomeEmailText($client);
        
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
        $logMessage = $emailSent ? "Welcome email sent successfully to: $to" : "Failed to send welcome email to: $to";
        error_log($logMessage);
        
        return $emailSent;
        
    } catch (Exception $e) {
        error_log("Email sending error: " . $e->getMessage());
        return false;
    }
}

function createWelcomeEmailHTML($client) {
    $name = htmlspecialchars($client['name']);
    $surname = htmlspecialchars($client['surname']);
    $email = htmlspecialchars($client['email']);
    $registrationDate = date('F j, Y', strtotime($client['created_at']));
    
    return "
    <!DOCTYPE html>
    <html lang='en'>
    <head>
        <meta charset='UTF-8'>
        <meta name='viewport' content='width=device-width, initial-scale=1.0'>
        <title>Welcome to Khuluma App</title>
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
                background: linear-gradient(135deg, #ff0a0aff 0%, #0d0c0eff 100%);
                color: white;
                padding: 20px;
                border-radius: 50%;
                display: inline-block;
                width: 60px;
                height: 60px;
                line-height: 60px;
                font-size: 24px;
                margin-bottom: 20px;
            }
            .welcome-title {
                color: #2c3e50;
                font-size: 28px;
                margin-bottom: 10px;
            }
            .content {
                margin-bottom: 30px;
            }
            .highlight {
                background-color: #f8f9fa;
                padding: 20px;
                border-radius: 8px;
                margin: 20px 0;
                border-left: 4px solid #667eea;
            }
            .button {
                background: linear-gradient(135deg, #ff0000ff 0%, #0d0d0eff 100%);
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
            .features {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 20px;
                margin: 20px 0;
            }
            .feature {
                text-align: center;
                padding: 15px;
                background-color: #f8f9fa;
                border-radius: 8px;
            }
            .feature-icon {
                font-size: 24px;
                margin-bottom: 10px;
                color: #ec0000ff;
            }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <div class='logo'>🎯</div>
                <h1 class='welcome-title'>Welcome to Khuluma App!</h1>
                <p>Your journey to finding the perfect opportunity starts here</p>
            </div>
            
            <div class='content'>
                <p>Dear <strong>$name $surname</strong>,</p>
                
                <p>Welcome to Khuluma App! We're thrilled to have you join our community of ambitious individuals seeking their next big opportunity.</p>
                
                <div class='highlight'>
                    <strong>Your Account Details:</strong><br>
                    📧 Email: $email<br>
                    📅 Registration Date: $registrationDate<br>
                    ✅ Status: Active
                </div>
                
                <h3>What you can do with Khuluma App:</h3>
                
                <div class='features'>
                    <div class='feature'>
                        <div class='feature-icon'>🔍</div>
                        <strong>Discover Opportunities</strong>
                        <p>Browse and search through hundreds of opportunities</p>
                    </div>
                    <div class='feature'>
                        <div class='feature-icon'>💼</div>
                        <strong>Apply Instantly</strong>
                        <p>Submit applications with just a few clicks</p>
                    </div>
                    <div class='feature'>
                        <div class='feature-icon'>📋</div>
                        <strong>Track Applications</strong>
                        <p>Monitor your application status in real-time</p>
                    </div>
                    <div class='feature'>
                        <div class='feature-icon'>⭐</div>
                        <strong>Save Favorites</strong>
                        <p>Bookmark opportunities to apply later</p>
                    </div>
                </div>
                
                <div style='text-align: center;'>
                    <a href='https://khulumaeswatini.com/client' class='button'>Start Exploring Opportunities</a>
                </div>
                
                <h3>Next Steps:</h3>
                <ol>
                    <li><strong>Complete your profile</strong> - Add more details to attract employers</li>
                    <li><strong>Upload your documents</strong> - Keep your CV and certificates ready</li>
                    <li><strong>Browse opportunities</strong> - Find the perfect match for your skills</li>
                    <li><strong>Apply confidently</strong> - Submit your applications with ease</li>
                </ol>
                
                <p>If you have any questions or need assistance, our support team is here to help. Simply reply to this email or contact us at <a href='mailto:support@khulumaeswatini.com'>support@khulumaeswatini.com</a>.</p>
                
                <p>Best of luck with your opportunity search!</p>
                
                <p>Warm regards,<br>
                <strong>The Khuluma Eswatini Team</strong></p>
            </div>
            
            <div class='footer'>
                <p>This email was sent to $email because you registered for Khuluma Eswatini.</p>
                <p>© 2025 Khuluma Eswatini. All rights reserved.</p>
                <p>If you have any questions, contact us at support@khulumaeswatini.com</p>
            </div>
        </div>
    </body>
    </html>
    ";
}

function createWelcomeEmailText($client) {
    $name = $client['name'];
    $surname = $client['surname'];
    $email = $client['email'];
    $registrationDate = date('F j, Y', strtotime($client['created_at']));
    
    return "
Welcome to Khuluma App!

Dear $name $surname,

Welcome to Khuluma App! We're thrilled to have you join our community of ambitious individuals seeking their next big opportunity.

Your Account Details:
- Email: $email
- Registration Date: $registrationDate
- Status: Active

What you can do with Khuluma App:
• Discover Opportunities - Browse and search through hundreds of opportunities
• Apply Instantly - Submit applications with just a few clicks
• Track Applications - Monitor your application status in real-time
• Save Favorites - Bookmark opportunities to apply later

Next Steps:
1. Complete your profile - Add more details to attract employers
2. Upload your documents - Keep your CV and certificates ready
3. Browse opportunities - Find the perfect match for your skills
4. Apply confidently - Submit your applications with ease

Visit Khuluma App: https://khulumaeswatini.com/client

If you have any questions or need assistance, our support team is here to help. Simply reply to this email or contact us at support@khulumaeswatini.com.

Best of luck with your opportunity search!

Warm regards,
The Khuluma App Team

---
This email was sent to $email because you registered for Khuluma App.
© 2025 Khuluma App. All rights reserved.
If you have any questions, contact us at support@khulumaeswatini.com
    ";
}

?>
