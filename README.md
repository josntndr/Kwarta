# Kwarta Financial Tracker

Kwarta is a Filipino-inspired personal finance tracker built with PHP, MySQL, Bootstrap, and Chart.js. It supports user registration, secure login sessions, income and expense tracking, category budgets, a Savings Cart for planned purchases, monthly receipts, and a pixel-game Game Hub with XP, levels, quests, badges, and streaks.

## Folder Structure

Kwarta is separated into application layers:

```text
Kwarta/
+-- backend/
|   +-- config/
|   |   +-- database.php
|   +-- includes/
|   |   +-- auth.php
|   |   +-- footer.php
|   |   +-- functions.php
|   |   +-- header.php
+-- database/
|   +-- migrations/
|   |   +-- 001_savings_goal_histories.sql
|   |   +-- 002_savings_goal_description.sql
|   |   +-- 003_savings_goal_bought_status.sql
|   +-- kwarta.sql
+-- deployment/
|   +-- README.md
|   +-- apache-vhost.conf
|   +-- local-node-server.js
|   +-- nginx.conf
+-- frontend/
|   +-- public/
|   |   +-- images/
|   |   |   +-- mascot-default.png
|   |   |   +-- mascot-dashboard.png
|   |   |   +-- mascot-guide.png
|   |   |   +-- mascot-rewards.png
|   |   |   +-- mascot-savings.png
|   |   +-- assets/
|   |   |   +-- css/styles.css
|   |   |   +-- js/app.js
|   |   +-- budgets.php
|   |   +-- dashboard.php
|   |   +-- index.php
|   |   +-- login.php
|   |   +-- logout.php
|   |   +-- profile.php
|   |   +-- register.php
|   |   +-- receipt-pdf.php
|   |   +-- receipt.php
|   |   +-- savings.php
|   |   +-- gamification.php
|   |   +-- transaction-delete.php
|   |   +-- transaction-form.php
|   |   +-- transactions.php
+-- .gitignore
+-- README.md
```

## Layer Responsibilities

- `frontend/public`: Browser-facing PHP pages, forms, Bootstrap UI, CSS, JavaScript, icons, and Chart.js usage.
- `backend/config`: Database configuration.
- `backend/includes`: Shared backend logic for sessions, authentication, CSRF protection, validation, escaping, layout, reusable helpers, and gamification scoring.
- `database`: MySQL schema and starter category data.
- `deployment`: Local/server deployment notes and sample Apache/Nginx configs.

## Setup Instructions

1. Install PHP 8+, MySQL or MariaDB, and a local server such as XAMPP, Laragon, WAMP, or PHP's built-in server.
2. Create the database by importing [database/kwarta.sql](database/kwarta.sql) in phpMyAdmin or MySQL CLI.
3. Update [backend/config/database.php](backend/config/database.php) with your local MySQL username and password.
4. From the project root, run:

```bash
php -S localhost:8000 -t frontend/public
```

5. Open `http://localhost:8000` in your browser.
6. Register a user account, then add transactions, budgets, and Savings Cart items.

Bootstrap, Bootstrap Icons, and Chart.js are loaded through CDN links, so the UI needs internet access unless you download those assets locally.

For Apache or Nginx deployment, see [deployment/README.md](deployment/README.md).

If you already imported an older version of the database, run [database/migrations/001_savings_goal_histories.sql](database/migrations/001_savings_goal_histories.sql), [database/migrations/002_savings_goal_description.sql](database/migrations/002_savings_goal_description.sql), and [database/migrations/003_savings_goal_bought_status.sql](database/migrations/003_savings_goal_bought_status.sql) to add the Savings Cart activity table, item descriptions, and already-bought status without rebuilding your data.

Run [database/migrations/004_admin_roles_and_logs.sql](database/migrations/004_admin_roles_and_logs.sql) to add the secure admin role system, account status, last-login tracking, monthly receipt generation logs, and admin activity logs.

### Admin Account Setup

Kwarta does not show a public Admin Login button and users cannot register as admins. Create a normal account first, then manually promote it in MySQL:

```sql
UPDATE users
SET role = 'admin'
WHERE email = 'your-email@gmail.com';
```

Admins use the same login page. After login, admin accounts are redirected to `admin/dashboard.php`, while normal users are redirected to `dashboard.php`.

### Docker Option

If PHP and MySQL are not installed locally, Docker can run both services:

```bash
docker compose up --build
```

Then open `http://localhost:8000`.

### No-Install Node Demo

If PHP, MySQL, and Docker are not working, run the local demo server with Node.js:

```bash
node deployment/local-node-server.js
```

Then open `http://localhost:8000`.

This mode stores data in `runtime/kwarta-data.json`. It is meant for local preview and class/demo use; the PHP/MySQL version remains the production-style implementation.

## Database Design

[database/kwarta.sql](database/kwarta.sql) creates these tables:

- `users`: stores account name, unique email, and `password_hash`.
- `users.role`, `users.status`, and `users.last_login_at`: support secure role-based access, account activation/deactivation, and login tracking.
- `categories`: stores reusable transaction categories such as Food, Transportation, Bills, School, Salary, Savings, Shopping, Emergency, and Others.
- `transactions`: stores income and expense records with amount, date, category, type, notes, and `user_id`.
- `budgets`: stores monthly category budgets for each user with a unique `user_id + category_id + month` relationship.
- `savings_goals`: stores Savings Cart items with item name, description, item price, saved amount, optional target month, already-bought status, and `user_id`.
- `savings_goal_histories`: stores each cart item's increase, decrease, price update, saved amount update, and item edit history.
- `wallets`: stores each user's manually editable Current Money / available balance.
- `user_game_stats`: stores XP, level, coins, streaks, and avatar stage per user.
- `xp_events`: stores XP history for reward activity feeds.
- `achievements` and `user_achievements`: define and track badge unlocks.
- `challenges` and `user_challenge_completions`: define daily/weekly/monthly money quests and track one-time rewards per period.
- `monthly_receipt_logs`: stores receipt generation counts for aggregate admin statistics.
- `admin_activity_logs`: stores admin-side actions such as login and account status updates.

All financial tables are linked to `users`, so each account only sees its own records.

## File Guide

- [backend/config/database.php](backend/config/database.php): Creates the PDO database connection using prepared-statement friendly settings.
- [backend/includes/auth.php](backend/includes/auth.php): Starts sessions, checks protected pages, manages CSRF tokens, and handles login/logout session state.
- [backend/includes/functions.php](backend/includes/functions.php): Contains shared helpers for escaping output, redirects, money formatting, categories, and validation.
- [backend/includes/header.php](backend/includes/header.php): Shared page header, Bootstrap imports, navigation, and flash messages.
- [backend/includes/footer.php](backend/includes/footer.php): Shared scripts and closing layout markup.
- [frontend/public/register.php](frontend/public/register.php): Validates registration input and hashes passwords with `password_hash`.
- [frontend/public/login.php](frontend/public/login.php): Verifies passwords with `password_verify` and starts a secure session.
- [frontend/public/logout.php](frontend/public/logout.php): Clears the active session.
- [frontend/public/profile.php](frontend/public/profile.php): Shows and manages the user's account, display name, password, settings, preferences, Current Money, player level, XP, streaks, and profile mascot panel.
- [frontend/public/index.php](frontend/public/index.php): Shows the public Kwarta landing page with hero copy, login/register CTAs, mascot art, and feature previews before authentication.
- [frontend/public/dashboard.php](frontend/public/dashboard.php): Shows editable Current Money, total income, total expenses, balance, recent transactions, mascot messages, progress meters, and charts.
- [frontend/public/transactions.php](frontend/public/transactions.php): Lists and filters transactions by date, category, and type.
- [frontend/public/transaction-form.php](frontend/public/transaction-form.php): Adds and edits income or expense records.
- [frontend/public/transaction-delete.php](frontend/public/transaction-delete.php): Deletes a transaction only when it belongs to the logged-in user.
- [frontend/public/budgets.php](frontend/public/budgets.php): Sets monthly category budgets, shows progress, and warns near or over budget.
- [frontend/public/savings.php](frontend/public/savings.php): Creates the Savings Cart, shows Total Budget Need and Current Budget, opens detailed item modals, tracks item descriptions, item price, saved money, target month to avail, already-bought status, add/reduce actions, item deletion, and latest-first cart activity history.
- [frontend/public/receipt.php](frontend/public/receipt.php): Generates a print-friendly Monthly Expense Receipt with selected-month expenses, income, balance, savings, top category, and transaction count.
- [frontend/public/receipt-pdf.php](frontend/public/receipt-pdf.php): Downloads the selected Monthly Expense Receipt as a generated PDF file.
- [frontend/public/gamification.php](frontend/public/gamification.php): Shows the Game Hub with level progress, interactive money quests, reward feed, achievement badges, avatar progress, streaks, and XP events.
- [frontend/public/admin/dashboard.php](frontend/public/admin/dashboard.php): Privacy-safe admin dashboard with aggregate user, transaction, savings, receipt, Game Hub, and registration statistics.
- [frontend/public/admin/users.php](frontend/public/admin/users.php): Privacy-safe user management for account activation/deactivation only.
- [frontend/public/admin/stats.php](frontend/public/admin/stats.php): User growth and role/status charts without financial records.
- [frontend/public/admin/activity.php](frontend/public/admin/activity.php): Admin activity log viewer.
- [frontend/public/admin/profile.php](frontend/public/admin/profile.php): Admin account/security overview.
- [frontend/public/assets/css/styles.css](frontend/public/assets/css/styles.css): Custom responsive styling for Kwarta, including the pixel navbar, CSS-drawn nav icons, active states, hover effects, cards, meters, and mascot layouts.
- [frontend/public/assets/js/app.js](frontend/public/assets/js/app.js): Reusable Chart.js rendering helpers.
- [frontend/public/images](frontend/public/images): Public copies of the Philippine Eagle pixel mascot assets sourced from the root `images` folder.

## Security Features

- Passwords are stored with PHP's `password_hash`.
- Login uses `password_verify`.
- PDO prepared statements are used for all database input.
- Output is escaped through the `e()` helper to reduce XSS risk.
- Protected pages call `require_login`.
- Mutating forms use CSRF tokens.
- Queries include `user_id` checks so users can only read, update, or delete their own financial records.
- Inputs are validated for required fields, numeric amounts, dates, category/type matching, and length limits.
- Backend source is kept outside the public document root.
- Gamification rewards are server-side and tied to the authenticated user account.
- Role-based access control protects every admin page with `require_admin`.
- Admin dashboards expose only aggregate system statistics and basic user account metadata.
- Admins cannot view individual user transactions, notes, budgets, savings items, receipt contents, password hashes, or financial history.
- Admin accounts have a server-side inactivity timeout.

## Vercel Deployment

Kwarta includes [vercel.json](vercel.json), [package.json](package.json), [composer.json](composer.json), and [.env.example](.env.example) for a PHP/MySQL Vercel deployment using a PHP runtime.

Use this Vercel project name:

```text
kwarta-financial-tracker
```

If the name is available, the production URL will be:

```text
https://kwarta-financial-tracker.vercel.app
```

Add these environment variables in Vercel:

```env
DB_HOST=
DB_PORT=3306
DB_NAME=
DB_USER=
DB_PASSWORD=
DATABASE_URL=
MYSQL_URL=
APP_SECRET=
APP_URL=
APP_ENV=production
```

Deployment checklist:

1. Push the project to GitHub.
2. Connect the GitHub repository to Vercel.
3. Set the Vercel project name to `kwarta-financial-tracker`.
4. Add the environment variables above in Vercel Project Settings.
5. Use a production MySQL-compatible database such as PlanetScale, Aiven, Railway, or another hosted MySQL provider.
6. Import [database/kwarta.sql](database/kwarta.sql) into the production database.
7. If upgrading an existing database, run all files in [database/migrations](database/migrations).
8. Register your owner account.
9. Promote your owner account manually with `UPDATE users SET role = 'admin' WHERE email = 'your-email@gmail.com';`.
10. Test `/`, `/login`, `/register`, `/dashboard`, `/admin/dashboard`, `/admin/users`, and `/logout`.
11. Confirm a normal user who visits `/admin/dashboard` is redirected away.

If the deployed link opens but login/register show a database warning, Vercel is working but the hosted MySQL environment variables are missing or incorrect.

### Production Database Setup

Vercel runs the PHP app, but it does not host MySQL for this project. Create a hosted MySQL database first, then import [database/kwarta.sql](database/kwarta.sql). Good beginner-friendly options are Aiven MySQL, Railway MySQL, PlanetScale-compatible MySQL, or any cPanel/MySQL host that allows remote connections.

In Vercel, open **Project > Settings > Environment Variables** and add these for **Production**:

```env
DB_HOST=your-production-mysql-host
DB_PORT=3306
DB_NAME=your-database-name
DB_USER=your-database-user
DB_PASSWORD=your-database-password
APP_SECRET=use-a-long-random-string
APP_URL=https://kwarta-financial-tracker.vercel.app
APP_ENV=production
```

If your database provider gives one connection string instead, add it as `DATABASE_URL` or `MYSQL_URL`:

```env
DATABASE_URL=mysql://user:password@host:3306/database_name
```

After saving environment variables, redeploy the project. Vercel does not apply new environment variables to an already-built deployment until a new deployment is created.

## Gamification System

- Users earn XP for logging transactions, setting budgets, adding Savings Cart items, and updating saved money.
- Levels are calculated from total XP, with a visible pixel-style progress meter.
- Streaks update when users perform finance actions on consecutive days.
- Achievements unlock for milestones such as the first transaction, fully saving for a cart item, logging across multiple days, staying under budget, and reaching level 5.
- Daily, weekly, and monthly challenges give one-time XP rewards per period.
- The dashboard includes a pixel avatar, active money quests, badge shelf, and animated meters.
- The visual style uses custom CSS inspired by pixel coins, piggy banks, money bags, wallets, cash bundles, and patterned money backgrounds.
- Mascot images are loaded from `frontend/public/images` with fallback to `mascot-default.png` if a variant is missing.

## Security Improvements For Production

- Serve over HTTPS and set secure cookie flags.
- Move secrets to environment variables instead of editing `backend/config/database.php`.
- Add email verification and password reset flows.
- Add rate limiting for login attempts.
- Add stricter Content Security Policy headers.
- Store third-party assets locally or pin CDN integrity hashes.
- Add audit logs for sensitive account actions.

## Future Enhancements

- Add yearly receipt summaries and richer receipt exports.
- Add recurring transactions.
- Add custom user-created categories.
- Add dark mode.
- Add weekly and yearly receipt views.
- Add budget notification emails.
- Add multi-currency support.
- Add automated tests with PHPUnit.
- Add richer avatar customization and a longer financial journey map.
