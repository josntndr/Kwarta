SET @is_bought_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'savings_goals'
      AND COLUMN_NAME = 'is_bought'
);

SET @is_bought_migration_sql = IF(
    @is_bought_column_exists = 0,
    'ALTER TABLE savings_goals ADD COLUMN is_bought TINYINT(1) NOT NULL DEFAULT 0 AFTER target_date',
    'SELECT "is_bought column already exists"'
);

PREPARE is_bought_migration_stmt FROM @is_bought_migration_sql;
EXECUTE is_bought_migration_stmt;
DEALLOCATE PREPARE is_bought_migration_stmt;

SET @bought_at_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'savings_goals'
      AND COLUMN_NAME = 'bought_at'
);

SET @bought_at_migration_sql = IF(
    @bought_at_column_exists = 0,
    'ALTER TABLE savings_goals ADD COLUMN bought_at TIMESTAMP NULL AFTER is_bought',
    'SELECT "bought_at column already exists"'
);

PREPARE bought_at_migration_stmt FROM @bought_at_migration_sql;
EXECUTE bought_at_migration_stmt;
DEALLOCATE PREPARE bought_at_migration_stmt;
