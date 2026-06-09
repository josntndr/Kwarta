ALTER TABLE users
    ADD COLUMN role ENUM('user', 'admin') NOT NULL DEFAULT 'user' AFTER password_hash,
    ADD COLUMN status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER role,
    ADD COLUMN last_login_at DATETIME NULL AFTER status;

CREATE TABLE IF NOT EXISTS monthly_receipt_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selected_month CHAR(7) NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_receipt_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_receipt_logs_user_month (user_id, selected_month),
    INDEX idx_receipt_logs_generated (generated_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS admin_activity_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    action VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_logs_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin_logs_created (created_at),
    INDEX idx_admin_logs_admin_created (admin_id, created_at)
) ENGINE=InnoDB;
