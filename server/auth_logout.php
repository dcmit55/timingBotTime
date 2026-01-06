<?php
/* ============================================================================
   AUTHENTICATION - LOGOUT HANDLER
   File: auth_logout.php
   Purpose: Handle user logout dan delete session token
   
   Process:
   - Delete session token dari database
   - Client harus clear token dari storage
   
   Security:
   - Immediate session invalidation
   - No residual session data
   
   Request Method: POST (JSON)
   Request Body: {
       "token": "session_token_here"
   }
   
   Response: {
       "status": "success"/"error",
       "message": "..."
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
    // DELETE SESSION FROM DATABASE
    // Remove session token untuk logout user
    // Note: Tidak perlu check apakah session exists, langsung delete saja
    // ========================================================================
    $stmt = $pdo->prepare('DELETE FROM user_sessions WHERE session_token = ?');
    $stmt->execute([$token]);

    // ========================================================================
    // RETURN SUCCESS RESPONSE
    // Session berhasil dihapus (atau tidak ada dari awal)
    // ========================================================================
    echo json_encode([
        'status' => 'success',
        'message' => 'Logged out successfully',
    ]);
} catch (PDOException $e) {
    // ========================================================================
    // HANDLE DATABASE ERROR
    // Log error dan return error message
    // ========================================================================
    error_log('Database error in auth_logout.php: ' . $e->getMessage());
    echo json_encode(['status' => 'error', 'message' => 'Logout failed']);
}

/* ============================================================================
   END OF auth_logout.php
   ============================================================================ */
