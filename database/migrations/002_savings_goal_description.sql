SET @description_column_exists = (
    SELECT COUNT(*)
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'savings_goals'
      AND COLUMN_NAME = 'description'
);

SET @description_migration_sql = IF(
    @description_column_exists = 0,
    'ALTER TABLE savings_goals ADD COLUMN description VARCHAR(255) NULL AFTER name',
    'SELECT "description column already exists"'
);

PREPARE description_migration_stmt FROM @description_migration_sql;
EXECUTE description_migration_stmt;
DEALLOCATE PREPARE description_migration_stmt;
