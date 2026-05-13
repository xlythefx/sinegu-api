-- Create referral_codes table
CREATE TABLE IF NOT EXISTS referral_codes (
    referral_id INT AUTO_INCREMENT PRIMARY KEY,
    code VARCHAR(50) UNIQUE NOT NULL,
    user_uni_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_code (code),
    INDEX idx_user_uni_id (user_uni_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Create referral_tracking table to track who used which referral code
CREATE TABLE IF NOT EXISTS referral_tracking (
    tracking_id INT AUTO_INCREMENT PRIMARY KEY,
    referral_code VARCHAR(50) NOT NULL,
    referrer_uni_id VARCHAR(255) NOT NULL,
    referred_user_uni_id VARCHAR(255) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_referral_code (referral_code),
    INDEX idx_referrer_uni_id (referrer_uni_id),
    INDEX idx_referred_user_uni_id (referred_user_uni_id),
    UNIQUE KEY unique_referral (referral_code, referred_user_uni_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

