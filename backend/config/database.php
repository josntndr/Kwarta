<?php

declare(strict_types=1);

$pdo = null;
$databaseConnectionError = null;

const KWARTA_SCHEMA_VERSION = '2026_06_12_performance_indexes';

function kwarta_env(array $keys, ?string $default = null): ?string
{
    foreach ($keys as $key) {
        $value = getenv($key);
        if ($value !== false && trim((string) $value) !== '') {
            return kwarta_clean_env_value($key, (string) $value);
        }
    }

    return $default;
}

function kwarta_clean_env_value(string $key, string $value): string
{
    $clean = trim($value);
    $clean = trim($clean, "\"'");
    $clean = preg_replace('/^(Value|Host|Database|User|Password|Port|MYSQL[A-Z_]*|DB_[A-Z_]*)\s*:\s*/i', '', $clean) ?? $clean;
    $clean = trim($clean);

    if (in_array($key, ['DB_HOST', 'MYSQLHOST'], true)) {
        if (str_starts_with($clean, 'mysql://')) {
            $parts = parse_url($clean);
            if ($parts !== false && isset($parts['host'])) {
                return (string) $parts['host'];
            }
        }

        if (preg_match('/^([^:\s]+):\d+$/', $clean, $matches)) {
            return $matches[1];
        }
    }

    if (in_array($key, ['DB_PORT', 'MYSQLPORT'], true) && preg_match('/\d+/', $clean, $matches)) {
        return $matches[0];
    }

    return $clean;
}

function kwarta_is_guest_route(): bool
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '/';
    $guestScripts = ['index.php', 'login.php', 'register.php'];
    $guestPaths = ['/', '/login', '/register', '/index.php', '/login.php', '/register.php'];

    return in_array($script, $guestScripts, true) || in_array($requestPath, $guestPaths, true);
}

function kwarta_database_unavailable(string $publicMessage, ?Throwable $error = null): void
{
    global $databaseConnectionError;

    $databaseConnectionError = $publicMessage;

    if ($error !== null) {
        error_log('[Kwarta database] ' . $error->getMessage());
    }

    if (!kwarta_is_guest_route() && isset($_SESSION['user_id'])) {
        http_response_code(503);
        exit($publicMessage);
    }
}

function kwarta_table_exists(PDO $pdo, string $table): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute(['table_name' => $table]);

    return (int) $stmt->fetchColumn() > 0;
}

function kwarta_column_exists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        'table_name' => $table,
        'column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function kwarta_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void
{
    if (!kwarta_column_exists($pdo, $table, $column)) {
        try {
            $pdo->exec("ALTER TABLE {$table} ADD COLUMN {$column} {$definition}");
        } catch (Throwable $error) {
            error_log("[Kwarta schema repair {$table}.{$column}] " . $error->getMessage());
        }
    }
}

function kwarta_index_exists(PDO $pdo, string $table, string $index): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND INDEX_NAME = :index_name
    ");
    $stmt->execute([
        'table_name' => $table,
        'index_name' => $index,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

function kwarta_add_index_if_missing(PDO $pdo, string $table, string $index, string $definition): void
{
    if (!kwarta_table_exists($pdo, $table) || kwarta_index_exists($pdo, $table, $index)) {
        return;
    }

    try {
        $pdo->exec("ALTER TABLE {$table} ADD INDEX {$index} {$definition}");
    } catch (Throwable $error) {
        error_log("[Kwarta index {$table}.{$index}] " . $error->getMessage());
    }
}

function kwarta_create_schema_migrations_table(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
        version VARCHAR(80) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB");
}

function kwarta_schema_migration_exists(PDO $pdo, string $version): bool
{
    kwarta_create_schema_migrations_table($pdo);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM schema_migrations WHERE version = :version');
    $stmt->execute(['version' => $version]);

    return (int) $stmt->fetchColumn() > 0;
}

function kwarta_apply_schema_file(PDO $pdo): void
{
    $sqlPath = dirname(__DIR__, 2) . '/database/kwarta.sql';

    if (!is_file($sqlPath)) {
        return;
    }

    $sql = (string) file_get_contents($sqlPath);
    $statements = array_filter(array_map('trim', explode(';', $sql)));

    foreach ($statements as $statement) {
        if ($statement === '' || preg_match('/^\s*(CREATE\s+DATABASE|USE)\b/i', $statement)) {
            continue;
        }

        $statement = preg_replace('/^\s*CREATE\s+TABLE\s+/i', 'CREATE TABLE IF NOT EXISTS ', $statement) ?? $statement;
        $statement = preg_replace('/^\s*INSERT\s+INTO\s+/i', 'INSERT IGNORE INTO ', $statement) ?? $statement;

        try {
            $pdo->exec($statement);
        } catch (Throwable $error) {
            error_log('[Kwarta schema apply] ' . $error->getMessage());
        }
    }
}

function kwarta_apply_inline_schema(PDO $pdo): void
{
    $statements = [
        "CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(180) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NULL,
            role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
            status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
            last_login_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS categories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(80) NOT NULL UNIQUE,
            type ENUM('income', 'expense', 'both') NOT NULL DEFAULT 'expense',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS transactions (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            type ENUM('income', 'expense') NOT NULL,
            amount DECIMAL(12, 2) NOT NULL,
            transaction_date DATE NOT NULL,
            notes VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_transactions_user_date (user_id, transaction_date),
            INDEX idx_transactions_user_type (user_id, type)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS budgets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            category_id INT UNSIGNED NOT NULL,
            month CHAR(7) NOT NULL,
            amount DECIMAL(12, 2) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_user_category_month (user_id, category_id, month)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS savings_goals (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(255),
            target_amount DECIMAL(12, 2) NOT NULL,
            saved_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
            target_date DATE,
            is_bought TINYINT(1) NOT NULL DEFAULT 0,
            bought_at TIMESTAMP NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS savings_goal_histories (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            savings_goal_id INT UNSIGNED NOT NULL,
            action_type VARCHAR(40) NOT NULL,
            amount_changed DECIMAL(12, 2),
            previous_amount DECIMAL(12, 2),
            new_amount DECIMAL(12, 2),
            notes VARCHAR(255),
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_savings_history_goal_created (savings_goal_id, created_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS wallets (
            user_id INT UNSIGNED PRIMARY KEY,
            current_money DECIMAL(12, 2) NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS user_game_stats (
            user_id INT UNSIGNED PRIMARY KEY,
            xp INT UNSIGNED NOT NULL DEFAULT 0,
            level INT UNSIGNED NOT NULL DEFAULT 1,
            coins INT UNSIGNED NOT NULL DEFAULT 0,
            current_streak INT UNSIGNED NOT NULL DEFAULT 0,
            longest_streak INT UNSIGNED NOT NULL DEFAULT 0,
            last_activity_date DATE,
            avatar_stage INT UNSIGNED NOT NULL DEFAULT 1,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS xp_events (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            action_key VARCHAR(80) NOT NULL,
            description VARCHAR(160) NOT NULL,
            xp INT UNSIGNED NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_xp_events_user_created (user_id, created_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS achievements (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            achievement_key VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(180) NOT NULL,
            icon VARCHAR(40) NOT NULL,
            xp_reward INT UNSIGNED NOT NULL DEFAULT 0
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS user_achievements (
            user_id INT UNSIGNED NOT NULL,
            achievement_id INT UNSIGNED NOT NULL,
            unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, achievement_id)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS challenges (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            challenge_key VARCHAR(80) NOT NULL UNIQUE,
            name VARCHAR(120) NOT NULL,
            description VARCHAR(180) NOT NULL,
            target INT UNSIGNED NOT NULL,
            xp_reward INT UNSIGNED NOT NULL,
            cadence ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'daily',
            metric_key VARCHAR(80) NOT NULL
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS user_challenge_completions (
            user_id INT UNSIGNED NOT NULL,
            challenge_id INT UNSIGNED NOT NULL,
            period_key VARCHAR(20) NOT NULL,
            completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (user_id, challenge_id, period_key)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS monthly_receipt_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id INT UNSIGNED NOT NULL,
            selected_month CHAR(7) NOT NULL,
            generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_receipt_logs_user_month (user_id, selected_month),
            INDEX idx_receipt_logs_generated (generated_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS admin_activity_logs (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            admin_id INT UNSIGNED NOT NULL,
            action VARCHAR(255) NOT NULL,
            description TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_admin_logs_created (created_at),
            INDEX idx_admin_logs_admin_created (admin_id, created_at)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS login_attempts (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(180) NOT NULL,
            ip_address VARCHAR(45) NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            last_attempt_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_login_attempt (email, ip_address),
            INDEX idx_login_attempts_locked (locked_until)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS password_resets (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            email VARCHAR(180) NOT NULL,
            token VARCHAR(255) NOT NULL,
            expires_at DATETIME NOT NULL,
            used_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_password_resets_token (token),
            INDEX idx_password_resets_email (email)
        ) ENGINE=InnoDB",
        "CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(80) PRIMARY KEY,
            applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB",
    ];

    foreach ($statements as $statement) {
        $pdo->exec($statement);
    }

    $pdo->exec("INSERT IGNORE INTO categories (name, type) VALUES
        ('Food', 'expense'),
        ('Transportation', 'expense'),
        ('Bills', 'expense'),
        ('School', 'expense'),
        ('Salary', 'income'),
        ('Savings', 'both'),
        ('Shopping', 'expense'),
        ('Emergency', 'both'),
        ('Others', 'both')");

    $pdo->exec("INSERT IGNORE INTO achievements (achievement_key, name, description, icon, xp_reward) VALUES
        ('first_transaction', 'First Transaction Badge', 'Add your first income or expense transaction.', 'coin', 25),
        ('budget_guardian', 'Budget Keeper Badge', 'Stay under at least one active category budget this month.', 'shield', 30),
        ('savings_starter', 'Savings Starter Badge', 'Create your first savings goal.', 'piggy-bank', 25),
        ('goal_finisher', 'Goal Crusher Badge', 'Complete your first savings goal.', 'flag', 50),
        ('income_10000', 'PHP 10,000 Earner Badge', 'Earn at least PHP 10,000 in a month.', 'cash-stack', 60),
        ('no_overspending', 'No Overspending Badge', 'Keep every active budget within its limit this month.', 'check2-square', 40),
        ('expense_streak_3', 'Weekly Tracker Badge', 'Log transactions for 3 different days.', 'calendar', 40),
        ('level_5', 'Financial Hero Badge', 'Reach level 5 through smart money actions.', 'star', 80)");

    $pdo->exec("INSERT IGNORE INTO challenges (challenge_key, name, description, target, xp_reward, cadence, metric_key) VALUES
        ('daily_three_logs', 'Daily Log Combo', 'Log 3 transactions today.', 3, 60, 'daily', 'transactions_today'),
        ('weekly_save_500', 'Save 500 This Week', 'Record at least PHP 500 in savings this week.', 500, 100, 'weekly', 'savings_week'),
        ('food_budget_watch', 'Food Budget Watch', 'Keep Food spending within budget this month.', 1, 80, 'monthly', 'food_budget_safe')");
}

function kwarta_repair_users_table(PDO $pdo): void
{
    if (!kwarta_table_exists($pdo, 'users')) {
        return;
    }

    kwarta_add_column_if_missing($pdo, 'users', 'name', 'VARCHAR(120) NOT NULL DEFAULT "" AFTER id');
    kwarta_add_column_if_missing($pdo, 'users', 'email', 'VARCHAR(180) NOT NULL DEFAULT "" AFTER name');
    kwarta_add_column_if_missing($pdo, 'users', 'password_hash', 'VARCHAR(255) NULL AFTER email');
    kwarta_add_column_if_missing($pdo, 'users', 'role', "ENUM('user', 'admin') NOT NULL DEFAULT 'user'");
    kwarta_add_column_if_missing($pdo, 'users', 'status', "ENUM('active', 'inactive') NOT NULL DEFAULT 'active'");
    kwarta_add_column_if_missing($pdo, 'users', 'last_login_at', 'DATETIME NULL');
    kwarta_add_column_if_missing($pdo, 'users', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');

    if (kwarta_column_exists($pdo, 'users', 'password')) {
        try {
            $pdo->exec('ALTER TABLE users MODIFY password VARCHAR(255) NULL');
        } catch (Throwable $error) {
            error_log('[Kwarta schema repair password column] ' . $error->getMessage());
        }

        $pdo->exec("
            UPDATE users
            SET password_hash = password
            WHERE (password_hash IS NULL OR password_hash = '')
              AND password LIKE '\$2%'
        ");
    }
}

function kwarta_repair_savings_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS savings_goals (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id INT UNSIGNED NOT NULL,
        name VARCHAR(120) NOT NULL,
        description VARCHAR(255),
        target_amount DECIMAL(12, 2) NOT NULL,
        saved_amount DECIMAL(12, 2) NOT NULL DEFAULT 0,
        target_date DATE,
        is_bought TINYINT(1) NOT NULL DEFAULT 0,
        bought_at TIMESTAMP NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_savings_user_created (user_id, created_at)
    ) ENGINE=InnoDB");

    kwarta_add_column_if_missing($pdo, 'savings_goals', 'user_id', 'INT UNSIGNED NOT NULL AFTER id');
    kwarta_add_column_if_missing($pdo, 'savings_goals', 'name', 'VARCHAR(120) NOT NULL DEFAULT "" AFTER user_id');
    kwarta_add_column_if_missing($pdo, 'savings_goals', 'description', 'VARCHAR(255) NULL AFTER name');
    kwarta_add_column_if_missing($pdo, 'savings_goals', 'target_amount', 'DECIMAL(12, 2) NOT NULL DEFAULT 0 AFTER description');
    kwarta_add_column_if_missing($pdo, 'savings_goals', 'saved_amount', 'DECIMAL(12, 2) NOT NULL DEFAULT 0 AFTER target_amount');
    kwarta_add_column_if_missing($pdo, 'savings_goals', 'target_date', 'DATE NULL AFTER saved_amount');
    kwarta_add_column_if_missing($pdo, 'savings_goals', 'is_bought', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER target_date');
    kwarta_add_column_if_missing($pdo, 'savings_goals', 'bought_at', 'TIMESTAMP NULL AFTER is_bought');
    kwarta_add_column_if_missing($pdo, 'savings_goals', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
    kwarta_add_column_if_missing($pdo, 'savings_goals', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');

    $pdo->exec("CREATE TABLE IF NOT EXISTS savings_goal_histories (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        savings_goal_id INT UNSIGNED NOT NULL,
        action_type VARCHAR(40) NOT NULL,
        amount_changed DECIMAL(12, 2),
        previous_amount DECIMAL(12, 2),
        new_amount DECIMAL(12, 2),
        notes VARCHAR(255),
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_savings_history_goal_created (savings_goal_id, created_at)
    ) ENGINE=InnoDB");

    kwarta_add_column_if_missing($pdo, 'savings_goal_histories', 'savings_goal_id', 'INT UNSIGNED NOT NULL AFTER id');
    kwarta_add_column_if_missing($pdo, 'savings_goal_histories', 'action_type', 'VARCHAR(40) NOT NULL DEFAULT "goal_edited" AFTER savings_goal_id');
    kwarta_add_column_if_missing($pdo, 'savings_goal_histories', 'amount_changed', 'DECIMAL(12, 2) NULL AFTER action_type');
    kwarta_add_column_if_missing($pdo, 'savings_goal_histories', 'previous_amount', 'DECIMAL(12, 2) NULL AFTER amount_changed');
    kwarta_add_column_if_missing($pdo, 'savings_goal_histories', 'new_amount', 'DECIMAL(12, 2) NULL AFTER previous_amount');
    kwarta_add_column_if_missing($pdo, 'savings_goal_histories', 'notes', 'VARCHAR(255) NULL AFTER new_amount');
    kwarta_add_column_if_missing($pdo, 'savings_goal_histories', 'created_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP');
    kwarta_add_column_if_missing($pdo, 'savings_goal_histories', 'updated_at', 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP');
}

function kwarta_add_performance_indexes(PDO $pdo): void
{
    kwarta_add_index_if_missing($pdo, 'transactions', 'idx_transactions_user_type_date', '(user_id, type, transaction_date)');
    kwarta_add_index_if_missing($pdo, 'transactions', 'idx_transactions_user_category_type_date', '(user_id, category_id, type, transaction_date)');
    kwarta_add_index_if_missing($pdo, 'budgets', 'idx_budgets_user_month', '(user_id, month)');
    kwarta_add_index_if_missing($pdo, 'savings_goals', 'idx_savings_user_target', '(user_id, target_date)');
    kwarta_add_index_if_missing($pdo, 'savings_goals', 'idx_savings_user_created', '(user_id, created_at)');
    kwarta_add_index_if_missing($pdo, 'wallets', 'idx_wallets_updated', '(updated_at)');
}

function kwarta_run_migrations(PDO $pdo, bool $force = false): array
{
    kwarta_create_schema_migrations_table($pdo);

    if (!$force && kwarta_schema_migration_exists($pdo, KWARTA_SCHEMA_VERSION)) {
        return [
            'applied' => false,
            'version' => KWARTA_SCHEMA_VERSION,
            'message' => 'Database schema is already up to date.',
        ];
    }

    kwarta_apply_inline_schema($pdo);
    kwarta_apply_schema_file($pdo);
    kwarta_repair_users_table($pdo);
    kwarta_repair_savings_tables($pdo);
    kwarta_add_performance_indexes($pdo);

    $stmt = $pdo->prepare('
        INSERT INTO schema_migrations (version)
        VALUES (:version)
        ON DUPLICATE KEY UPDATE applied_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute(['version' => KWARTA_SCHEMA_VERSION]);

    return [
        'applied' => true,
        'version' => KWARTA_SCHEMA_VERSION,
        'message' => 'Database schema migration completed.',
    ];
}

function kwarta_ensure_database_schema(PDO $pdo): void
{
    try {
        kwarta_run_migrations($pdo);
    } catch (Throwable $error) {
        error_log('[Kwarta schema repair] ' . $error->getMessage());
    }
}

$databaseUrl = kwarta_env(['DATABASE_URL', 'MYSQL_URL', 'JAWSDB_URL', 'CLEARDB_DATABASE_URL']);
$dbHost = kwarta_env(['DB_HOST', 'MYSQLHOST'], '127.0.0.1');
$dbPort = kwarta_env(['DB_PORT', 'MYSQLPORT'], '3306');
$dbName = kwarta_env(['DB_NAME', 'MYSQLDATABASE'], 'kwarta');
$dbUser = kwarta_env(['DB_USER', 'MYSQLUSER'], 'root');
$dbPass = kwarta_env(['DB_PASSWORD', 'MYSQLPASSWORD', 'DB_PASS'], '');
$isProduction = getenv('VERCEL') === '1' || kwarta_env(['APP_ENV']) === 'production';

if ($databaseUrl !== null) {
    $parts = parse_url($databaseUrl);

    if ($parts !== false) {
        $dbHost = isset($parts['host']) ? (string) $parts['host'] : $dbHost;
        $dbPort = isset($parts['port']) ? (string) $parts['port'] : $dbPort;
        $dbUser = isset($parts['user']) ? rawurldecode((string) $parts['user']) : $dbUser;
        $dbPass = isset($parts['pass']) ? rawurldecode((string) $parts['pass']) : $dbPass;

        if (isset($parts['path']) && trim((string) $parts['path'], '/') !== '') {
            $dbName = trim((string) $parts['path'], '/');
        }
    }
}

$productionDbHost = kwarta_env(['DB_HOST', 'MYSQLHOST']) !== null || $databaseUrl !== null;

if ($isProduction && !$productionDbHost) {
    kwarta_database_unavailable(
        'Kwarta is online, but the production database is not configured yet. Add DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD in Vercel Project Settings.'
    );
} else {
    try {
        $pdo = new PDO(
            'mysql:host=' . $dbHost . ';port=' . $dbPort . ';dbname=' . $dbName . ';charset=utf8mb4',
            $dbUser,
            $dbPass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );

        $shouldAutoSchema = !$isProduction && kwarta_env(['KWARTA_AUTO_SCHEMA'], '1') !== '0';
        if ($shouldAutoSchema) {
            kwarta_ensure_database_schema($pdo);
        }
    } catch (PDOException $e) {
        kwarta_database_unavailable(
            'Kwarta is online, but it cannot connect to the production database yet. Please verify DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASSWORD in Vercel.',
            $e
        );
    }
}
