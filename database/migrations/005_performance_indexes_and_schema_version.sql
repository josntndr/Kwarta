CREATE TABLE IF NOT EXISTS schema_migrations (
    version VARCHAR(80) PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'transactions'
      AND INDEX_NAME = 'idx_transactions_user_type_date'
);
SET @sql := IF(@index_exists = 0, 'ALTER TABLE transactions ADD INDEX idx_transactions_user_type_date (user_id, type, transaction_date)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'transactions'
      AND INDEX_NAME = 'idx_transactions_user_category_type_date'
);
SET @sql := IF(@index_exists = 0, 'ALTER TABLE transactions ADD INDEX idx_transactions_user_category_type_date (user_id, category_id, type, transaction_date)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'budgets'
      AND INDEX_NAME = 'idx_budgets_user_month'
);
SET @sql := IF(@index_exists = 0, 'ALTER TABLE budgets ADD INDEX idx_budgets_user_month (user_id, month)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'savings_goals'
      AND INDEX_NAME = 'idx_savings_user_target'
);
SET @sql := IF(@index_exists = 0, 'ALTER TABLE savings_goals ADD INDEX idx_savings_user_target (user_id, target_date)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'savings_goals'
      AND INDEX_NAME = 'idx_savings_user_created'
);
SET @sql := IF(@index_exists = 0, 'ALTER TABLE savings_goals ADD INDEX idx_savings_user_created (user_id, created_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

SET @index_exists := (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'wallets'
      AND INDEX_NAME = 'idx_wallets_updated'
);
SET @sql := IF(@index_exists = 0, 'ALTER TABLE wallets ADD INDEX idx_wallets_updated (updated_at)', 'SELECT 1');
PREPARE stmt FROM @sql;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;

INSERT INTO schema_migrations (version)
VALUES ('2026_06_12_performance_indexes')
ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP;
