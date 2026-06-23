<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

require_login();

$pageTitle = 'Game Hub';
$userId = current_user_id();
$game = gamification_profile($pdo, $userId, true);
$stats = $game['stats'];
$unlockedBadges = array_filter($game['achievements'], static fn ($achievement) => !empty($achievement['unlocked_at']));
$completedQuests = array_filter($game['challenges'], static fn ($challenge) => !empty($challenge['complete']));

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<section class="page-header-panel page-header-game">
    <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-game" aria-hidden="true"></span></span>
        <div class="page-header-copy">
            <span class="level-pill mb-2"><i class="bi bi-controller"></i> Level <?= (int) $stats['level'] ?>: <?= e(level_title((int) $stats['level'])) ?></span>
            <h1 class="page-header-title">Game Hub</h1>
            <p class="page-header-subtitle">Complete financial missions, earn XP, unlock badges, and keep your Kwarta streak alive.</p>
            <div class="page-header-meta">
                <span class="page-header-chip"><?= (int) $stats['xp'] ?> XP</span>
                <span class="page-header-chip"><?= (int) $stats['coins'] ?> coins</span>
                <span class="page-header-chip"><?= (int) $stats['current_streak'] ?> day current streak</span>
                <span class="page-header-chip"><?= (int) $stats['longest_streak'] ?> day best streak</span>
            </div>
            <?php if ($completedQuests): ?>
                <div class="game-complete-message mt-3">Quest Complete: <?= count($completedQuests) ?> mission<?= count($completedQuests) === 1 ? '' : 's' ?> ready for rewards.</div>
            <?php endif; ?>
        </div>
    </div>
    <div class="page-header-actions">
        <div class="page-header-progress">
            <div class="d-flex justify-content-between text-muted-small mb-1">
                <span>Level Progress</span>
                <strong><?= (int) $stats['level_progress'] ?>%</strong>
            </div>
            <div class="progress">
                <div class="progress-bar bg-success" style="width: <?= (int) $stats['level_progress'] ?>%"></div>
            </div>
        </div>
        <?= mascot_img('rewards', 'mascot-section-img', 'Kwarta rewards mascot') ?>
    </div>
</section>

<div class="row g-3 mb-4">
    <div class="col-md-3"><div class="game-stat-tile"><span>XP</span><strong><?= (int) $stats['xp'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>Level</span><strong><?= (int) $stats['level'] ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>Badges</span><strong><?= count($unlockedBadges) ?>/<?= count($game['achievements']) ?></strong></div></div>
    <div class="col-md-3"><div class="game-stat-tile"><span>Best Streak</span><strong><?= (int) $stats['longest_streak'] ?> days</strong></div></div>
</div>

<div class="row g-4">
    <div class="col-lg-7">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Active Money Quests</h2>
                <div class="mascot-message mb-3">
                    <?= mascot_img('guide', 'mascot-message-img', 'Kwarta guide mascot') ?>
                    <span><?= (int) $stats['current_streak'] > 0 ? 'Nice streak! Keep logging today to protect your progress.' : 'Start your streak by logging your first money move today.' ?></span>
                </div>
                <div class="row g-3">
                    <?php foreach ($game['challenges'] as $challenge): ?>
                        <div class="col-md-6">
                            <div class="quest-card mission-card <?= $challenge['complete'] ? 'complete' : '' ?>">
                                <div class="d-flex justify-content-between gap-2 mb-2">
                                    <strong><?= e($challenge['name']) ?></strong>
                                    <span class="badge <?= $challenge['complete'] ? 'text-bg-success' : 'text-bg-dark' ?>">
                                        <?= $challenge['complete'] ? 'Complete' : '+' . (int) $challenge['xp_reward'] . ' XP' ?>
                                    </span>
                                </div>
                                <div class="text-muted-small mb-3"><?= e($challenge['description']) ?></div>
                                <div class="progress mb-2">
                                    <div class="progress-bar bg-success" style="width: <?= (int) $challenge['progress'] ?>%"></div>
                                </div>
                                <div class="d-flex justify-content-between text-muted-small">
                                    <span><?= e((string) $challenge['current']) ?> / <?= (int) $challenge['target'] ?></span>
                                    <span><?= e(ucfirst($challenge['cadence'])) ?></span>
                                </div>
                                <?php if ($challenge['complete']): ?>
                                    <div class="game-complete-message mt-3">Completion message: <?= e($challenge['name']) ?> cleared.</div>
                                <?php endif; ?>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="col-12">
                        <div class="text-muted-small text-uppercase fw-bold mt-2 mb-1">Quick Actions</div>
                    </div>
                    <div class="col-md-6">
                        <div class="quest-card mission-card shortcut-card">
                            <div class="d-flex justify-content-between gap-2 mb-1">
                                <strong>Review Monthly Receipt</strong>
                                <span class="badge text-bg-secondary">Shortcut</span>
                            </div>
                            <div class="text-muted-small">See where your money went this month in a printable receipt summary.</div>
                            <div class="shortcut-meta">
                                <a class="btn btn-sm btn-outline-primary" href="receipt.php"><i class="bi bi-receipt"></i> Review Receipt</a>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="quest-card mission-card shortcut-card">
                            <div class="d-flex justify-content-between gap-2 mb-1">
                                <strong>Plan a Savings Item</strong>
                                <span class="badge text-bg-secondary">Shortcut</span>
                            </div>
                            <div class="text-muted-small">Add an item to your savings cart and set a clear target for your next purchase.</div>
                            <div class="shortcut-meta">
                                <a class="btn btn-sm btn-outline-success" href="savings.php"><i class="bi bi-piggy-bank"></i> Open Savings</a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-lg-5">
        <div class="card content-card h-100">
            <div class="card-body">
                <h2 class="h5 fw-bold mb-3">Reward Feed</h2>
                <div class="d-grid gap-2">
                    <?php if (!$game['events']): ?>
                        <div class="empty-state">
                            <?= mascot_img('rewards', 'mascot-empty-img', 'Kwarta reward mascot') ?>
                            <div>
                                <strong>No XP events yet.</strong>
                                <div class="text-muted-small">Log your first transaction to trigger your first reward popup.</div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php foreach ($game['events'] as $event): ?>
                        <div class="xp-event">
                            <div class="d-flex justify-content-between gap-2">
                                <strong><?= str_contains($event['action_key'], 'achievement_') ? 'Badge Unlocked: ' : '' ?><?= e($event['description']) ?></strong>
                                <span class="badge text-bg-success">+<?= (int) $event['xp'] ?> XP</span>
                            </div>
                            <div class="text-muted-small"><?= e(date('M d, Y h:i A', strtotime($event['created_at']))) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card content-card mt-4">
    <div class="card-body">
        <h2 class="h5 fw-bold mb-3">Unlockable Badges</h2>
        <div class="row g-3">
            <?php foreach ($game['achievements'] as $achievement): ?>
                <div class="col-md-4 col-xl-3">
                    <div class="achievement-tile h-100 <?= $achievement['unlocked_at'] ? '' : 'locked' ?>">
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?= mascot_img($achievement['unlocked_at'] ? 'rewards' : 'guide', 'mascot-badge-img', 'Kwarta badge mascot') ?>
                            <strong><?= e($achievement['name']) ?></strong>
                        </div>
                        <div class="text-muted-small mb-2"><?= e($achievement['description']) ?></div>
                        <?php if ($achievement['unlocked_at']): ?>
                            <span class="badge text-bg-success">Unlocked</span>
                        <?php else: ?>
                            <span class="badge text-bg-secondary">Locked</span>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
