CREATE DATABASE IF NOT EXISTS kwarta
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE kwarta;

CREATE TABLE users (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(120) NOT NULL,
    email VARCHAR(180) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user',
    status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
    last_login_at DATETIME NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE categories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(80) NOT NULL UNIQUE,
    type ENUM('income', 'expense', 'both') NOT NULL DEFAULT 'expense',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE transactions (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    type ENUM('income', 'expense') NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    transaction_date DATE NOT NULL,
    notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_transactions_category FOREIGN KEY (category_id) REFERENCES categories(id),
    INDEX idx_transactions_user_date (user_id, transaction_date),
    INDEX idx_transactions_user_type (user_id, type)
) ENGINE=InnoDB;

CREATE TABLE budgets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    category_id INT UNSIGNED NOT NULL,
    month CHAR(7) NOT NULL,
    amount DECIMAL(12, 2) NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_budgets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_budgets_category FOREIGN KEY (category_id) REFERENCES categories(id),
    UNIQUE KEY unique_user_category_month (user_id, category_id, month)
) ENGINE=InnoDB;

CREATE TABLE savings_goals (
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
    CONSTRAINT fk_savings_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE savings_goal_histories (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    savings_goal_id INT UNSIGNED NOT NULL,
    action_type VARCHAR(40) NOT NULL,
    amount_changed DECIMAL(12, 2),
    previous_amount DECIMAL(12, 2),
    new_amount DECIMAL(12, 2),
    notes VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_savings_history_goal FOREIGN KEY (savings_goal_id) REFERENCES savings_goals(id) ON DELETE CASCADE,
    INDEX idx_savings_history_goal_created (savings_goal_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE wallets (
    user_id INT UNSIGNED PRIMARY KEY,
    current_money DECIMAL(12, 2) NOT NULL DEFAULT 0,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_wallets_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE user_game_stats (
    user_id INT UNSIGNED PRIMARY KEY,
    xp INT UNSIGNED NOT NULL DEFAULT 0,
    level INT UNSIGNED NOT NULL DEFAULT 1,
    coins INT UNSIGNED NOT NULL DEFAULT 0,
    current_streak INT UNSIGNED NOT NULL DEFAULT 0,
    longest_streak INT UNSIGNED NOT NULL DEFAULT 0,
    last_activity_date DATE,
    avatar_stage INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_game_stats_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE xp_events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    action_key VARCHAR(80) NOT NULL,
    description VARCHAR(160) NOT NULL,
    xp INT UNSIGNED NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_xp_events_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_xp_events_user_created (user_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE achievements (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    achievement_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(180) NOT NULL,
    icon VARCHAR(40) NOT NULL,
    xp_reward INT UNSIGNED NOT NULL DEFAULT 0
) ENGINE=InnoDB;

CREATE TABLE user_achievements (
    user_id INT UNSIGNED NOT NULL,
    achievement_id INT UNSIGNED NOT NULL,
    unlocked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, achievement_id),
    CONSTRAINT fk_user_achievements_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_user_achievements_achievement FOREIGN KEY (achievement_id) REFERENCES achievements(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE challenges (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    challenge_key VARCHAR(80) NOT NULL UNIQUE,
    name VARCHAR(120) NOT NULL,
    description VARCHAR(180) NOT NULL,
    target INT UNSIGNED NOT NULL,
    xp_reward INT UNSIGNED NOT NULL,
    cadence ENUM('daily', 'weekly', 'monthly') NOT NULL DEFAULT 'daily',
    metric_key VARCHAR(80) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE user_challenge_completions (
    user_id INT UNSIGNED NOT NULL,
    challenge_id INT UNSIGNED NOT NULL,
    period_key VARCHAR(20) NOT NULL,
    completed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, challenge_id, period_key),
    CONSTRAINT fk_challenge_completions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    CONSTRAINT fk_challenge_completions_challenge FOREIGN KEY (challenge_id) REFERENCES challenges(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE monthly_receipt_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    selected_month CHAR(7) NOT NULL,
    generated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_receipt_logs_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_receipt_logs_user_month (user_id, selected_month),
    INDEX idx_receipt_logs_generated (generated_at)
) ENGINE=InnoDB;

CREATE TABLE admin_activity_logs (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    admin_id INT UNSIGNED NOT NULL,
    action VARCHAR(255) NOT NULL,
    description TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_admin_logs_admin FOREIGN KEY (admin_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_admin_logs_created (created_at),
    INDEX idx_admin_logs_admin_created (admin_id, created_at)
) ENGINE=InnoDB;

INSERT INTO categories (name, type) VALUES
('Food', 'expense'),
('Transportation', 'expense'),
('Bills', 'expense'),
('School', 'expense'),
('Salary', 'income'),
('Savings', 'both'),
('Shopping', 'expense'),
('Emergency', 'both'),
('Others', 'both');

INSERT INTO achievements (achievement_key, name, description, icon, xp_reward) VALUES
('first_transaction', 'First Transaction Badge', 'Add your first income or expense transaction.', 'coin', 25),
('budget_guardian', 'Budget Keeper Badge', 'Stay under at least one active category budget this month.', 'shield', 30),
('savings_starter', 'Savings Starter Badge', 'Create your first savings goal.', 'piggy-bank', 25),
('goal_finisher', 'Goal Crusher Badge', 'Complete your first savings goal.', 'flag', 50),
('income_10000', 'PHP 10,000 Earner Badge', 'Earn at least PHP 10,000 in a month.', 'cash-stack', 60),
('no_overspending', 'No Overspending Badge', 'Keep every active budget within its limit this month.', 'check2-square', 40),
('expense_streak_3', 'Weekly Tracker Badge', 'Log transactions for 3 different days.', 'calendar', 40),
('level_5', 'Financial Hero Badge', 'Reach level 5 through smart money actions.', 'star', 80);

INSERT INTO challenges (challenge_key, name, description, target, xp_reward, cadence, metric_key) VALUES
('daily_three_logs', 'Daily Log Combo', 'Log 3 transactions today.', 3, 60, 'daily', 'transactions_today'),
('weekly_save_500', 'Save 500 This Week', 'Record at least PHP 500 in savings this week.', 500, 100, 'weekly', 'savings_week'),
('food_budget_watch', 'Food Budget Watch', 'Keep Food spending within budget this month.', 1, 80, 'monthly', 'food_budget_safe');
