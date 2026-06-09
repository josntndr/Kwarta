<?php

require_once __DIR__ . '/../../backend/includes/auth.php';

if (is_logged_in()) {
    redirect('dashboard.php');
}

$pageTitle = 'Welcome';
$guestMainClass = 'landing-shell';

require_once __DIR__ . '/../../backend/includes/header.php';
?>

<section class="landing-hero">
    <div class="landing-nav">
        <a class="landing-brand" href="index.php">
            <?= mascot_img('logo', 'landing-logo', 'Kwarta mascot logo') ?>
            <span>Kwarta</span>
        </a>
        <div class="landing-nav-actions">
            <a class="btn btn-outline-light pixel-landing-btn" href="login.php">Login</a>
            <a class="btn btn-warning pixel-landing-btn" href="register.php">Register</a>
        </div>
    </div>

    <div class="landing-hero-content">
        <div class="landing-copy">
            <span class="level-pill mb-3">Pixel Finance Tracker</span>
            <h1>Kwarta</h1>
            <p class="landing-tagline">Track your money. Build your savings. Level up your financial habits.</p>
            <p class="landing-description">
                Kwarta helps you manage income, expenses, budgets, savings cart items, streaks, rewards, and financial progress in a fun Filipino-inspired pixel dashboard.
            </p>
            <div class="landing-cta">
                <a class="btn btn-warning btn-lg pixel-landing-btn" href="register.php">Start Tracking</a>
                <a class="btn btn-outline-light btn-lg pixel-landing-btn" href="login.php">Login</a>
            </div>
        </div>
        <div class="landing-mascot-stage" aria-hidden="true">
            <?= mascot_img('dashboard', 'landing-mascot', 'Kwarta mascot') ?>
            <span class="landing-pixel-icon coin"></span>
            <span class="landing-pixel-icon wallet"></span>
            <span class="landing-pixel-icon chart"></span>
        </div>
    </div>
</section>

<section class="landing-features">
    <div class="landing-section-heading">
        <span class="text-muted-small text-uppercase fw-bold">Feature Preview</span>
        <h2>Everything you need to start managing money.</h2>
    </div>
    <div class="landing-feature-grid">
        <article class="landing-feature-card">
            <span class="pixel-nav-icon nav-icon-coin" aria-hidden="true"></span>
            <h3>Track Income and Expenses</h3>
            <p>Log money in and out, categorize records, and understand where your pesos go.</p>
        </article>
        <article class="landing-feature-card">
            <span class="pixel-nav-icon nav-icon-piggy" aria-hidden="true"></span>
            <h3>Manage Savings Cart</h3>
            <p>Plan items you want to buy, set target months, and mark items as already bought.</p>
        </article>
        <article class="landing-feature-card">
            <span class="pixel-nav-icon nav-icon-wallet" aria-hidden="true"></span>
            <h3>Monitor Budgets</h3>
            <p>Set monthly category limits and see progress before spending gets out of hand.</p>
        </article>
        <article class="landing-feature-card">
            <span class="pixel-nav-icon nav-icon-trophy" aria-hidden="true"></span>
            <h3>Earn Streaks and Badges</h3>
            <p>Build better habits with XP, rewards, quests, and friendly progress signals.</p>
        </article>
        <article class="landing-feature-card">
            <span class="pixel-nav-icon nav-icon-receipt" aria-hidden="true"></span>
            <h3>Review Monthly Receipts</h3>
            <p>Generate receipt-style monthly summaries to see income, expenses, and remaining balance.</p>
        </article>
    </div>
</section>

<?php require_once __DIR__ . '/../../backend/includes/footer.php'; ?>
