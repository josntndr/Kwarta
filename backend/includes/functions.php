<?php

declare(strict_types=1);

function e(?string $value): string
{
    return htmlspecialchars($value ?? '', ENT_QUOTES, 'UTF-8');
}

function redirect(string $path): never
{
    header("Location: {$path}");
    exit;
}

function peso(float $amount): string
{
    return 'PHP ' . number_format($amount, 2);
}

function current_month(): string
{
    return date('Y-m');
}

function month_label(string $month): string
{
    $date = DateTime::createFromFormat('Y-m', $month);
    return $date ? $date->format('F Y') : $month;
}

function month_range(string $month): array
{
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $month . '-01');
    if (!$date) {
        $date = new DateTimeImmutable('first day of this month');
    }

    return [
        $date->format('Y-m-d'),
        $date->modify('first day of next month')->format('Y-m-d'),
    ];
}

function flash(string $key, ?string $message = null): ?string
{
    if ($message !== null) {
        $_SESSION['flash'][$key] = $message;
        return null;
    }

    if (!isset($_SESSION['flash'][$key])) {
        return null;
    }

    $value = $_SESSION['flash'][$key];
    unset($_SESSION['flash'][$key]);
    return $value;
}

function get_categories(PDO $pdo, ?string $type = null): array
{
    if ($type === null) {
        $stmt = $pdo->query('SELECT * FROM categories ORDER BY name');
        return $stmt->fetchAll();
    }

    $stmt = $pdo->prepare('
        SELECT * FROM categories
        WHERE type = :type OR type = "both"
        ORDER BY name
    ');
    $stmt->execute(['type' => $type]);
    return $stmt->fetchAll();
}

function validate_amount(string $amount): ?float
{
    if (!is_numeric($amount)) {
        return null;
    }

    $value = (float) $amount;
    return $value > 0 ? $value : null;
}

function validate_non_negative_amount(string $amount): ?float
{
    if (!is_numeric($amount)) {
        return null;
    }

    $value = (float) $amount;
    return $value >= 0 ? $value : null;
}

function validate_date(string $date): bool
{
    $parsed = DateTime::createFromFormat('Y-m-d', $date);
    return $parsed && $parsed->format('Y-m-d') === $date;
}

function category_belongs_to_type(PDO $pdo, int $categoryId, string $type): bool
{
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM categories WHERE id = :id AND (type = :type OR type = "both")');
    $stmt->execute(['id' => $categoryId, 'type' => $type]);
    return (int) $stmt->fetchColumn() > 0;
}

function mascot_src(string $variant = 'default'): string
{
    $mascots = [
        'default' => 'mascot-default.png',
        'logo' => 'mascot-default.png',
        'money' => 'mascot-rewards.png',
        'rewards' => 'mascot-rewards.png',
        'dashboard' => 'mascot-dashboard.png',
        'guide' => 'mascot-guide.png',
        'savings' => 'mascot-savings.png',
        'empty' => 'mascot-guide.png',
    ];

    $fileName = $mascots[$variant] ?? $mascots['default'];

    $scriptName = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
    $prefix = str_contains($scriptName, '/admin/') ? '../' : '';

    return $prefix . 'images/' . $fileName;
}

function mascot_img(string $variant = 'default', string $class = 'mascot-img', string $alt = 'Kwarta mascot'): string
{
    $fallback = mascot_src('default');
    return '<img class="' . e($class) . '" src="' . e(mascot_src($variant)) . '" alt="' . e($alt) . '" onerror="this.onerror=null;this.src=\'' . e($fallback) . '\';">';
}

function ensure_wallet(PDO $pdo, int $userId, float $defaultMoney = 0): void
{
    $stmt = $pdo->prepare('
        INSERT IGNORE INTO wallets (user_id, current_money)
        VALUES (:user_id, :current_money)
    ');
    $stmt->execute([
        'user_id' => $userId,
        'current_money' => $defaultMoney,
    ]);
}

function get_current_money(PDO $pdo, int $userId, float $defaultMoney = 0): float
{
    $stmt = $pdo->prepare('SELECT current_money FROM wallets WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    $currentMoney = $stmt->fetchColumn();

    if ($currentMoney !== false) {
        return (float) $currentMoney;
    }

    ensure_wallet($pdo, $userId, $defaultMoney);
    return $defaultMoney;
}

function update_current_money(PDO $pdo, int $userId, float $amount): void
{
    ensure_wallet($pdo, $userId, $amount);

    $stmt = $pdo->prepare('
        UPDATE wallets
        SET current_money = :current_money
        WHERE user_id = :user_id
    ');
    $stmt->execute([
        'current_money' => $amount,
        'user_id' => $userId,
    ]);
}

function xp_level(int $xp): int
{
    return min(5, max(1, (int) floor($xp / 120) + 1));
}

function xp_for_level(int $level): int
{
    return max(0, $level - 1) * 120;
}

function level_title(int $level): string
{
    return match (min(5, max(1, $level))) {
        1 => 'Budget Beginner',
        2 => 'Ipon Starter',
        3 => 'Kwarta Keeper',
        4 => 'Savings Master',
        default => 'Financial Hero',
    };
}

function ensure_game_stats(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('
        INSERT IGNORE INTO user_game_stats (user_id, xp, level, coins)
        VALUES (:user_id, 0, 1, 0)
    ');
    $stmt->execute(['user_id' => $userId]);
}

function award_xp(PDO $pdo, int $userId, string $actionKey, string $description, int $xp): void
{
    ensure_game_stats($pdo, $userId);

    $today = date('Y-m-d');
    $stmt = $pdo->prepare('SELECT * FROM user_game_stats WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    $stats = $stmt->fetch();
    $lastActivity = $stats['last_activity_date'] ?? null;
    $streak = (int) ($stats['current_streak'] ?? 0);

    if ($lastActivity !== $today) {
        $yesterday = date('Y-m-d', strtotime('-1 day'));
        $streak = $lastActivity === $yesterday ? $streak + 1 : 1;
    }

    $newXp = (int) $stats['xp'] + $xp;
    $newLevel = xp_level($newXp);
    $newCoins = (int) $stats['coins'] + max(1, (int) floor($xp / 10));
    $avatarStage = min(5, max(1, (int) floor($newLevel / 2) + 1));

    $stmt = $pdo->prepare('
        UPDATE user_game_stats
        SET xp = :xp,
            level = :level,
            coins = :coins,
            current_streak = :current_streak,
            longest_streak = GREATEST(longest_streak, :current_streak),
            last_activity_date = :last_activity_date,
            avatar_stage = :avatar_stage
        WHERE user_id = :user_id
    ');
    $stmt->execute([
        'xp' => $newXp,
        'level' => $newLevel,
        'coins' => $newCoins,
        'current_streak' => $streak,
        'last_activity_date' => $today,
        'avatar_stage' => $avatarStage,
        'user_id' => $userId,
    ]);

    $stmt = $pdo->prepare('
        INSERT INTO xp_events (user_id, action_key, description, xp)
        VALUES (:user_id, :action_key, :description, :xp)
    ');
    $stmt->execute([
        'user_id' => $userId,
        'action_key' => $actionKey,
        'description' => $description,
        'xp' => $xp,
    ]);
}

function unlock_achievement(PDO $pdo, int $userId, string $achievementKey): void
{
    $stmt = $pdo->prepare('SELECT * FROM achievements WHERE achievement_key = :achievement_key LIMIT 1');
    $stmt->execute(['achievement_key' => $achievementKey]);
    $achievement = $stmt->fetch();

    if (!$achievement) {
        return;
    }

    $stmt = $pdo->prepare('
        INSERT IGNORE INTO user_achievements (user_id, achievement_id)
        VALUES (:user_id, :achievement_id)
    ');
    $stmt->execute([
        'user_id' => $userId,
        'achievement_id' => (int) $achievement['id'],
    ]);

    if ($stmt->rowCount() > 0 && (int) $achievement['xp_reward'] > 0) {
        award_xp($pdo, $userId, 'achievement_' . $achievementKey, 'Achievement unlocked: ' . $achievement['name'], (int) $achievement['xp_reward']);
        flash('success', 'Achievement unlocked: ' . $achievement['name'] . ' +' . (int) $achievement['xp_reward'] . ' XP');
    }
}

function evaluate_achievements(PDO $pdo, int $userId): void
{
    ensure_game_stats($pdo, $userId);
    $month = current_month();
    [$monthStart, $monthEnd] = month_range($month);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    if ((int) $stmt->fetchColumn() >= 1) {
        unlock_achievement($pdo, $userId, 'first_transaction');
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(DISTINCT transaction_date)
        FROM transactions
        WHERE user_id = :user_id
    ');
    $stmt->execute(['user_id' => $userId]);
    if ((int) $stmt->fetchColumn() >= 3) {
        unlock_achievement($pdo, $userId, 'expense_streak_3');
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM savings_goals
        WHERE user_id = :user_id AND saved_amount >= target_amount
    ');
    $stmt->execute(['user_id' => $userId]);
    if ((int) $stmt->fetchColumn() >= 1) {
        unlock_achievement($pdo, $userId, 'goal_finisher');
    }

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM savings_goals WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    if ((int) $stmt->fetchColumn() >= 1) {
        unlock_achievement($pdo, $userId, 'savings_starter');
    }

    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(amount), 0)
        FROM transactions
        WHERE user_id = :user_id
          AND type = "income"
          AND transaction_date >= :month_start
          AND transaction_date < :month_end
    ');
    $stmt->execute([
        'user_id' => $userId,
        'month_start' => $monthStart,
        'month_end' => $monthEnd,
    ]);
    if ((float) $stmt->fetchColumn() >= 10000) {
        unlock_achievement($pdo, $userId, 'income_10000');
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM budgets b
        LEFT JOIN (
            SELECT category_id, SUM(amount) AS spent
            FROM transactions
            WHERE user_id = :spent_user_id
              AND type = "expense"
              AND transaction_date >= :month_start
              AND transaction_date < :month_end
            GROUP BY category_id
        ) spent ON spent.category_id = b.category_id
        WHERE b.user_id = :user_id
          AND b.month = :month
          AND COALESCE(spent.spent, 0) <= b.amount
    ');
    $stmt->execute([
        'spent_user_id' => $userId,
        'month_start' => $monthStart,
        'month_end' => $monthEnd,
        'user_id' => $userId,
        'month' => $month,
    ]);
    if ((int) $stmt->fetchColumn() >= 1) {
        unlock_achievement($pdo, $userId, 'budget_guardian');
    }

    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM budgets b
        LEFT JOIN (
            SELECT category_id, SUM(amount) AS spent
            FROM transactions
            WHERE user_id = :spent_user_id
              AND type = "expense"
              AND transaction_date >= :month_start
              AND transaction_date < :month_end
            GROUP BY category_id
        ) spent ON spent.category_id = b.category_id
        WHERE b.user_id = :user_id
          AND b.month = :month
          AND COALESCE(spent.spent, 0) > b.amount
    ');
    $stmt->execute([
        'spent_user_id' => $userId,
        'month_start' => $monthStart,
        'month_end' => $monthEnd,
        'user_id' => $userId,
        'month' => $month,
    ]);
    $overBudgetCount = (int) $stmt->fetchColumn();
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM budgets WHERE user_id = :user_id AND month = :month');
    $stmt->execute([
        'user_id' => $userId,
        'month' => $month,
    ]);
    if ((int) $stmt->fetchColumn() > 0 && $overBudgetCount === 0) {
        unlock_achievement($pdo, $userId, 'no_overspending');
    }

    $stmt = $pdo->prepare('SELECT level FROM user_game_stats WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    if ((int) $stmt->fetchColumn() >= 5) {
        unlock_achievement($pdo, $userId, 'level_5');
    }
}

function gamification_profile(PDO $pdo, int $userId, bool $refreshProgress = false): array
{
    ensure_game_stats($pdo, $userId);
    if ($refreshProgress) {
        evaluate_achievements($pdo, $userId);
    }

    $stmt = $pdo->prepare('SELECT * FROM user_game_stats WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    $stats = $stmt->fetch();
    $level = (int) $stats['level'];
    $levelStart = xp_for_level($level);
    $levelEnd = xp_for_level($level + 1);
    $levelProgress = $levelEnd > $levelStart ? round((((int) $stats['xp'] - $levelStart) / ($levelEnd - $levelStart)) * 100) : 0;

    $stmt = $pdo->prepare('
        SELECT a.*, ua.unlocked_at
        FROM achievements a
        LEFT JOIN user_achievements ua
            ON ua.achievement_id = a.id AND ua.user_id = :user_id
        ORDER BY ua.unlocked_at IS NULL, a.id
    ');
    $stmt->execute(['user_id' => $userId]);
    $achievements = $stmt->fetchAll();

    $today = date('Y-m-d');
    $weekStart = date('Y-m-d', strtotime('monday this week'));
    $month = current_month();
    [$monthStart, $monthEnd] = month_range($month);

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM transactions WHERE user_id = :user_id AND transaction_date = :today');
    $stmt->execute(['user_id' => $userId, 'today' => $today]);
    $transactionsToday = (int) $stmt->fetchColumn();

    $stmt = $pdo->prepare('
        SELECT COALESCE(SUM(amount), 0)
        FROM transactions
        WHERE user_id = :user_id
          AND type = "expense"
          AND category_id = (SELECT id FROM categories WHERE name = "Savings" LIMIT 1)
          AND transaction_date >= :week_start
    ');
    $stmt->execute(['user_id' => $userId, 'week_start' => $weekStart]);
    $savingsWeek = (float) $stmt->fetchColumn();

    $stmt = $pdo->prepare('
        SELECT b.amount,
               COALESCE(SUM(t.amount), 0) AS spent
        FROM budgets b
        JOIN categories c ON c.id = b.category_id AND c.name = "Food"
        LEFT JOIN transactions t
            ON t.user_id = b.user_id
           AND t.category_id = b.category_id
           AND t.type = "expense"
           AND t.transaction_date >= :month_start
           AND t.transaction_date < :month_end
        WHERE b.user_id = :user_id AND b.month = :month
        GROUP BY b.id, b.amount
        LIMIT 1
    ');
    $stmt->execute([
        'month_start' => $monthStart,
        'month_end' => $monthEnd,
        'user_id' => $userId,
        'month' => $month,
    ]);
    $foodBudget = $stmt->fetch();
    $foodSafe = $foodBudget ? ((float) $foodBudget['spent'] <= (float) $foodBudget['amount'] ? 1 : 0) : 0;

    $stmt = $pdo->query('SELECT * FROM challenges ORDER BY id');
    $challengeDefs = $stmt->fetchAll();
    $metricValues = [
        'transactions_today' => $transactionsToday,
        'savings_week' => $savingsWeek,
        'food_budget_safe' => $foodSafe,
    ];
    $challenges = [];
    foreach ($challengeDefs as $challenge) {
        $value = (float) ($metricValues[$challenge['metric_key']] ?? 0);
        $target = (float) $challenge['target'];
        $complete = $target > 0 && $value >= $target;
        $periodKey = match ($challenge['cadence']) {
            'weekly' => date('o-W'),
            'monthly' => current_month(),
            default => date('Y-m-d'),
        };

        if ($complete && $refreshProgress) {
            $stmt = $pdo->prepare('
                INSERT IGNORE INTO user_challenge_completions (user_id, challenge_id, period_key)
                VALUES (:user_id, :challenge_id, :period_key)
            ');
            $stmt->execute([
                'user_id' => $userId,
                'challenge_id' => (int) $challenge['id'],
                'period_key' => $periodKey,
            ]);

            if ($stmt->rowCount() > 0) {
                award_xp($pdo, $userId, 'challenge_' . $challenge['challenge_key'], 'Quest completed: ' . $challenge['name'], (int) $challenge['xp_reward']);
                flash('success', 'Quest completed: ' . $challenge['name'] . ' +' . (int) $challenge['xp_reward'] . ' XP');
            }
        }

        $challenges[] = [
            ...$challenge,
            'current' => $value,
            'progress' => $target > 0 ? min(100, round(($value / $target) * 100)) : 0,
            'complete' => $complete,
        ];
    }

    $stmt = $pdo->prepare('SELECT * FROM user_game_stats WHERE user_id = :user_id');
    $stmt->execute(['user_id' => $userId]);
    $stats = $stmt->fetch();
    $level = (int) $stats['level'];
    $levelStart = xp_for_level($level);
    $levelEnd = xp_for_level($level + 1);
    $levelProgress = $levelEnd > $levelStart ? round((((int) $stats['xp'] - $levelStart) / ($levelEnd - $levelStart)) * 100) : 0;

    $stmt = $pdo->prepare('
        SELECT * FROM xp_events
        WHERE user_id = :user_id
        ORDER BY created_at DESC
        LIMIT 6
    ');
    $stmt->execute(['user_id' => $userId]);

    return [
        'stats' => [
            ...$stats,
            'level_progress' => $levelProgress,
            'xp_next' => $levelEnd,
        ],
        'achievements' => $achievements,
        'challenges' => $challenges,
        'events' => $stmt->fetchAll(),
    ];
}
