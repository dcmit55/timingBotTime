<?php
/* ============================================================================
   AUTHENTICATION - TOKEN VERIFICATION
   File: auth_verify.php
   Purpose: Verify session token validity untuk authentication check
   
   Use Cases:
   - Check jika user sudah login saat page load
   - Validate token sebelum allow access ke protected pages
   - Get user info dari token
   
   Security Features:
   - Token expiry check
   - User active status check
   - Session validity check
   
   Request Method: POST (JSON)
   Request Body: {
       "token": "session_token_here"
   }
   
   Response: {
       "status": "success"/"error",
       "message": "...",
       "user": {...}  // Only on success
   }
   ============================================================================ */

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
$token = $input['token'] ?? '';

// ============================================================================
// VALIDATE TOKEN INPUT
// Check jika token provided
// ============================================================================
if (empty($token)) {
    echo json_encode(['status' => 'error', 'message' => 'Token required']);
    exit();
}

try {
    // ========================================================================
    // VERIFY TOKEN IN DATABASE
    // Query session dengan JOIN ke users table
    // Check:
    //   1. Token exists
    //   2. Token belum expired (expires_at > NOW())
    //   3. User masih active (is_active = 1)
    // ========================================================================
    $stmt = $pdo->prepare("
        SELECT
            s.user_id,
            s.expires_at,
            u.email,
            u.full_name,
            u.role,
            u.is_active
        FROM user_sessions s
        JOIN users u ON s.user_id = u.id
        WHERE s.session_token = ?
          AND s.expires_at > NOW()
          AND u.is_active = 1
        LIMIT 1
    ");
    $stmt->execute([$token]);
    $session = $stmt->fetch();

    // ========================================================================
    // HANDLE INVALID/EXPIRED TOKEN
    // Token tidak ditemukan, sudah expired, atau user inactive
    // ========================================================================
    if (!$session) {
        echo json_encode(['status' => 'error', 'message' => 'Invalid or expired token']);
        exit();
    }

    // ========================================================================
    // RETURN SUCCESS RESPONSE
    // Token valid, return user data
    // ========================================================================
    echo json_encode([
        'status' => 'success',
        'user' => [
            'id' => $session['user_id'],
            'email' => $session['email'],
            'name' => $session['full_name'],
            'role' => $session['role'],
        ],
    ]);
} catch (PDOException $e) {
    // ========================================================================
    // HANDLE DATABASE ERROR
    // Log error dan return generic message
    // ========================================================================
    error_log('Database error in auth_verify.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Verification failed']);
}

/* ============================================================================
   END OF auth_verify.php
   ============================================================================ */
