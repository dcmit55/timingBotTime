<?php
/* ============================================================================
   AUTHENTICATION - LOGIN HANDLER
   File: auth_login.php
   Purpose: Handle user login, verify credentials, generate session token
   
   Security Features:
   - Password verification dengan bcrypt
   - Session token generation (64-char random hex)
   - IP address logging untuk security audit
   - User agent logging
   - Failed login logging
   - SQL injection prevention (prepared statements)
   
   Request Method: POST (JSON)
   Request Body: {
       "email": "user@example.com",
       "password": "password123",
       "rememberMe": true/false
   }
   
   Response: {
       "status": "success"/"error",
       "message": "...",
       "token": "...",
       "user": {...}
   }
   ============================================================================ */

// Start session untuk tracking
session_start();

// Set headers untuk JSON response dan security
header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Include database connection
require 'db.php';

// ============================================================================
// READ JSON INPUT
// Parse JSON body dari request
// ============================================================================
$input = json_decode(file_get_contents('php://input'), true);

// Validate JSON input
if (!$input) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid request format']);
    exit();
}

// ============================================================================
// SANITIZE & VALIDATE INPUT
// Clean input data untuk prevent XSS
// ============================================================================
$email = filter_var($input['email'] ?? '', FILTER_SANITIZE_EMAIL);
$password = $input['password'] ?? '';
$rememberMe = $input['rememberMe'] ?? false;

// Check required fields
if (empty($email) || empty($password)) {
    echo json_encode(['status' => 'error', 'message' => 'Email and password are required']);
    exit();
}

// Validate email format
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['status' => 'error', 'message' => 'Invalid email format']);
    exit();
}

try {
    // ========================================================================
    // FIND USER IN DATABASE
    // Query user by email dan check active status
    // ========================================================================
    $stmt = $pdo->prepare("
        SELECT id, email, password, full_name, role, is_active
        FROM users
        WHERE email = ? AND is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    // User not found atau inactive
    if (!$user) {
        // Log failed attempt untuk security audit
        error_log("Login failed for email: $email - User not found or inactive");

        // 🆕 DEBUG: Log more details untuk troubleshooting
        error_log("DEBUG: Searching for email: $email");

        // Generic error message untuk prevent user enumeration
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid email or password',
            'debug' => 'User not found or inactive', // 🆕 Tambah debug info
        ]);
        exit();
    }

    // 🆕 DEBUG: Log user found
    error_log("DEBUG: User found - ID: {$user['id']}, Email: {$user['email']}, Active: {$user['is_active']}");

    // ========================================================================
    // VERIFY PASSWORD
    // Use password_verify() untuk check bcrypt hash
    // ========================================================================
    if (!password_verify($password, $user['password'])) {
        // Log failed attempt
        error_log("Login failed for email: $email - Invalid password");

        // 🆕 DEBUG: Log password verification details
        error_log("DEBUG: Password verification failed for user ID: {$user['id']}");
        error_log('DEBUG: Hash in DB: ' . substr($user['password'], 0, 30) . '...');

        // Generic error message (jangan kasih tau password salah secara spesifik)
        echo json_encode([
            'status' => 'error',
            'message' => 'Invalid email or password',
            'debug' => 'Password verification failed', // 🆕 Tambah debug info
        ]);
        exit();
    }

    // ========================================================================
    // GENERATE SESSION TOKEN
    // Create random 64-character hex string untuk session
    // random_bytes(32) = 32 bytes = 256 bits security
    // bin2hex() = convert ke hexadecimal string (64 chars)
    // ========================================================================
    $sessionToken = bin2hex(random_bytes(32));

    // Set expiry time based on "Remember Me" option
    // Remember me = 30 days, else = 24 hours
    $expiresAt = $rememberMe ? date('Y-m-d H:i:s', strtotime('+30 days')) : date('Y-m-d H:i:s', strtotime('+24 hours'));

    // ========================================================================
    // GET CLIENT INFORMATION
    // Store IP address dan user agent untuk security audit
    // ========================================================================
    $ipAddress = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? 'unknown';

    // ========================================================================
    // STORE SESSION IN DATABASE
    // Insert new session record
    // ========================================================================
    $stmt = $pdo->prepare("
        INSERT INTO user_sessions (user_id, session_token, ip_address, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([$user['id'], $sessionToken, $ipAddress, $userAgent, $expiresAt]);

    // ========================================================================
    // UPDATE LAST LOGIN TIME
    // Track kapan user terakhir login
    // ========================================================================
    $stmt = $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?');
    $stmt->execute([$user['id']]);

    // ========================================================================
    // CLEANUP OLD SESSIONS
    // Delete expired sessions untuk keep database clean
    // Run maintenance task setiap login
    // ========================================================================
    $pdo->exec('DELETE FROM user_sessions WHERE expires_at < NOW()');

    // ========================================================================
    // RETURN SUCCESS RESPONSE
    // Send token dan user data ke client
    // ========================================================================
    echo json_encode([
        'status' => 'success',
        'message' => 'Login successful',
        'token' => $sessionToken,
        'user' => [
            'id' => $user['id'],
            'email' => $user['email'],
            'name' => $user['full_name'],
            'role' => $user['role'],
        ],
    ]);
} catch (PDOException $e) {
    // ========================================================================
    // HANDLE DATABASE ERROR
    // Log error dan return generic message (jangan expose error details)
    // ========================================================================
    error_log('Database error in auth_login.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Database error occurred']);
}

/* ============================================================================
   END OF auth_login.php
   ============================================================================ */
