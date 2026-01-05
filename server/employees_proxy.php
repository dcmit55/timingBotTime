<?php
// <?php
// /**
//  * Proxy endpoint untuk mengambil data employees dari Laravel API
//  * Endpoint Laravel: http://127.0.0.1:8000/api/v1/employees
//  * 
//  * Response format yang diharapkan dari Laravel:
//  * [
//  *   {
//  *     "id": 1,
//  *     "name": "John Doe",
//  *     "employee_code": "EMP001",
//  *     "department": "costume"
//  *   },
//  *   ...
//  * ]
//  */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// SymCore API Configuration
$apiUrl = 'https://mascotenterpriseid.com/api/v1/employees';
$apiToken = '8a66f4e52e5e2b9c6a44e72dc9a83b2ffb632eb3c929a6fff1ede976206ffe9a';

try {
    // Inisialisasi cURL
    $ch = curl_init();
    
    curl_setopt_array($ch, [
        CURLOPT_URL => $apiUrl,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            'Content-Type: application/json',
            'x-api-key: ' . $apiToken
        ]
    ]);
    
    // Eksekusi request
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    
    // Cek error cURL
    if (curl_errno($ch)) {
        throw new Exception('cURL Error: ' . curl_error($ch));
    }
    
    curl_close($ch);
    
    // Validasi HTTP response code
    if ($httpCode === 401) {
        // Token tidak valid atau expired
        http_response_code(401);
        echo json_encode([
            'error' => 'Token API tidak valid atau sudah tidak aktif',
            'message' => 'Silakan hubungi administrator untuk memperbarui token API',
            'http_code' => 401,
            'source' => 'employees_proxy.php'
        ]);
        exit;
    }
    
    if ($httpCode !== 200) {
        throw new Exception("Laravel API returned HTTP $httpCode");
    }
    
    // Decode JSON response
    $data = json_decode($response, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        throw new Exception('Invalid JSON response from Laravel API');
    }
    
    // 🔧 FIX: Unwrap 'data' key jika Laravel return {data: [...]}
    $employees = $data;
    if (isset($data['data']) && is_array($data['data'])) {
        $employees = $data['data'];
    }
    
    // Validasi struktur data
    if (!is_array($employees)) {
        throw new Exception('Expected array response from Laravel API');
    }
    
    // Return data ke frontend (sudah unwrapped)
    echo json_encode($employees);
    
} catch (Exception $e) {
    // Log error (optional)
    error_log('[employees_proxy.php] Error: ' . $e->getMessage());
    
    // Return error response
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'source' => 'employees_proxy.php'
    ]);
}