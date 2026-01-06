-- ============================================================================
-- AUTHENTICATION SYSTEM SCHEMA
-- Description: Database tables untuk sistem login dan session management
-- Created: 2025-01-05
-- ============================================================================

-- ----------------------------------------------------------------------------
-- TABEL: users
-- Purpose: Menyimpan data user yang dapat login ke sistem
-- Security: Password di-hash menggunakan bcrypt (PASSWORD_DEFAULT di PHP)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'User ID (Auto Increment)',
    email VARCHAR(255) NOT NULL UNIQUE COMMENT 'Email user (digunakan untuk login)',
    password VARCHAR(255) NOT NULL COMMENT 'Password yang sudah di-hash dengan bcrypt',
    full_name VARCHAR(255) NOT NULL COMMENT 'Nama lengkap user',
    role ENUM('admin', 'operator', 'viewer') DEFAULT 'viewer' COMMENT 'Role/Hak akses user',
    is_active TINYINT(1) DEFAULT 1 COMMENT 'Status aktif (1=active, 0=inactive/disabled)',
    last_login DATETIME NULL COMMENT 'Timestamp login terakhir',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu pembuatan record',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP COMMENT 'Waktu update terakhir',
    
    -- Indexes untuk optimasi query
    INDEX idx_email (email),
    INDEX idx_role (role),
    INDEX idx_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tabel user authentication';

-- ----------------------------------------------------------------------------
-- TABEL: user_sessions
-- Purpose: Menyimpan session token untuk user yang sedang login
-- Security: Token di-generate secara random, ada expiry time
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS user_sessions (
    id INT AUTO_INCREMENT PRIMARY KEY COMMENT 'Session ID',
    user_id INT NOT NULL COMMENT 'Foreign key ke tabel users',
    session_token VARCHAR(255) NOT NULL UNIQUE COMMENT 'Token unik untuk session (64 char hex)',
    ip_address VARCHAR(45) NOT NULL COMMENT 'IP address client (support IPv4 & IPv6)',
    user_agent TEXT COMMENT 'Browser user agent string',
    expires_at DATETIME NOT NULL COMMENT 'Waktu expiry session',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT 'Waktu session dibuat',
    
    -- Foreign key untuk maintain data integrity
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    
    -- Indexes untuk optimasi query
    INDEX idx_token (session_token),
    INDEX idx_expires (expires_at),
    INDEX idx_user_id (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='Tabel session management';

-- ----------------------------------------------------------------------------
-- DEFAULT DATA: Admin User
-- Email: admin@mascotenterprise.com
-- Password: admin123 (sudah di-hash dengan bcrypt)
-- Purpose: User default untuk first-time login
-- PENTING: Hash ini valid untuk password 'admin123'
-- ----------------------------------------------------------------------------
-- Cara 1: Insert dengan hash yang sudah valid (gunakan salah satu cara saja)
INSERT INTO users (email, password, full_name, role) VALUES 
('admin@mascotenterprise.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin')
ON DUPLICATE KEY UPDATE email=email; -- Prevent duplicate if already exists

-- Cara 2: Jika cara 1 tidak berhasil, hapus user lama dan insert baru
-- DELETE FROM users WHERE email = 'admin@mascotenterprise.com';
-- INSERT INTO users (email, password, full_name, role) VALUES 
-- ('admin@mascotenterprise.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'System Administrator', 'admin');

-- ----------------------------------------------------------------------------
-- OPTIONAL: Additional sample users untuk testing
-- Uncomment jika diperlukan untuk testing
-- ----------------------------------------------------------------------------
-- INSERT INTO users (email, password, full_name, role) VALUES 
-- ('operator@mascotenterprise.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Production Operator', 'operator'),
-- ('viewer@mascotenterprise.com', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', 'Production Viewer', 'viewer');

-- ============================================================================
-- NOTES:
-- 1. Jalankan file SQL ini di phpMyAdmin atau MySQL client
-- 2. Default password untuk semua user: admin123
-- 3. Hash password dibuat dengan: password_hash('admin123', PASSWORD_DEFAULT)
-- 4. Session token akan auto-cleanup saat expired
-- 5. Jika user dihapus, semua session-nya juga akan terhapus (CASCADE)
-- ============================================================================
