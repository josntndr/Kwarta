const http = require('http');
const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

const ROOT = path.resolve(__dirname, '..');
const PUBLIC = path.join(ROOT, 'frontend', 'public');
const DATA_DIR = path.join(ROOT, 'runtime');
const DATA_FILE = path.join(DATA_DIR, 'kwarta-data.json');
const LOG_FILE = path.join(DATA_DIR, 'local-node-server.log');
const PORT = Number(process.env.PORT || 8000);

const categories = [
  { id: 1, name: 'Food', type: 'expense' },
  { id: 2, name: 'Transportation', type: 'expense' },
  { id: 3, name: 'Bills', type: 'expense' },
  { id: 4, name: 'School', type: 'expense' },
  { id: 5, name: 'Salary', type: 'income' },
  { id: 6, name: 'Savings', type: 'both' },
  { id: 7, name: 'Shopping', type: 'expense' },
  { id: 8, name: 'Emergency', type: 'both' },
  { id: 9, name: 'Others', type: 'both' },
];

const sessions = new Map();

function ensureData() {
  if (!fs.existsSync(DATA_DIR)) fs.mkdirSync(DATA_DIR, { recursive: true });
  if (!fs.existsSync(DATA_FILE)) {
    fs.writeFileSync(DATA_FILE, JSON.stringify({
      users: [],
      transactions: [],
      budgets: [],
      savingsGoals: [],
      savingsHistories: [],
      receiptLogs: [],
      adminActivityLogs: [],
      nextIds: { user: 1, transaction: 1, budget: 1, goal: 1, history: 1, receiptLog: 1, adminLog: 1 },
    }, null, 2));
  }
}

function loadData() {
  ensureData();
  const data = JSON.parse(fs.readFileSync(DATA_FILE, 'utf8'));
  let changed = false;
  if (!Array.isArray(data.savingsHistories)) {
    data.savingsHistories = [];
    changed = true;
  }
  if (!Array.isArray(data.receiptLogs)) {
    data.receiptLogs = [];
    changed = true;
  }
  if (!Array.isArray(data.adminActivityLogs)) {
    data.adminActivityLogs = [];
    changed = true;
  }
  for (const user of data.users) {
    if (!user.role) {
      user.role = 'user';
      changed = true;
    }
    if (!user.status) {
      user.status = 'active';
      changed = true;
    }
    if (!Object.prototype.hasOwnProperty.call(user, 'lastLoginAt')) {
      user.lastLoginAt = null;
      changed = true;
    }
    if (!user.createdAt) {
      user.createdAt = new Date().toISOString();
      changed = true;
    }
  }
  if (!data.nextIds) {
    data.nextIds = { user: 1, transaction: 1, budget: 1, goal: 1, history: 1, receiptLog: 1, adminLog: 1 };
    changed = true;
  }
  if (!data.nextIds.history) {
    const maxHistoryId = data.savingsHistories.reduce((max, item) => Math.max(max, Number(item.id || 0)), 0);
    data.nextIds.history = maxHistoryId + 1;
    changed = true;
  }
  if (!data.nextIds.receiptLog) {
    const maxReceiptId = data.receiptLogs.reduce((max, item) => Math.max(max, Number(item.id || 0)), 0);
    data.nextIds.receiptLog = maxReceiptId + 1;
    changed = true;
  }
  if (!data.nextIds.adminLog) {
    const maxAdminLogId = data.adminActivityLogs.reduce((max, item) => Math.max(max, Number(item.id || 0)), 0);
    data.nextIds.adminLog = maxAdminLogId + 1;
    changed = true;
  }
  if (changed) saveData(data);
  return data;
}

function saveData(data) {
  fs.writeFileSync(DATA_FILE, JSON.stringify(data, null, 2));
}

function escapeHtml(value = '') {
  return String(value).replace(/[&<>"']/g, (char) => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;',
  }[char]));
}

function money(value) {
  return `PHP ${Number(value || 0).toLocaleString('en-PH', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
}

function historyTime(value) {
  return new Intl.DateTimeFormat('en-PH', {
    month: 'long',
    day: 'numeric',
    year: 'numeric',
    hour: 'numeric',
    minute: '2-digit',
  }).format(new Date(value));
}

function savingsActionLabel(actionType) {
  return ({
    increase: 'Increase',
    decrease: 'Decrease',
    target_updated: 'Price Updated',
    saved_updated: 'Saved Updated',
    goal_edited: 'Item Edited',
    goal_created: 'Added to Cart',
    item_bought: 'Already Bought',
    item_unbought: 'Marked Not Bought',
  }[actionType]) || String(actionType).replace(/_/g, ' ').replace(/\b\w/g, (char) => char.toUpperCase());
}

function monthLabel(value = '') {
  if (!value) return '';
  const [year, month] = String(value).split('-').map(Number);
  if (!year || !month) return '';
  return new Intl.DateTimeFormat('en-PH', { month: 'long', year: 'numeric' }).format(new Date(year, month - 1, 1));
}

function dateLabel(value = '') {
  const date = new Date(`${value}T00:00:00`);
  return Number.isNaN(date.getTime()) ? value : new Intl.DateTimeFormat('en-PH', { month: 'long', day: 'numeric', year: 'numeric' }).format(date);
}

function logSavingsHistory(data, goalId, actionType, amountChanged, previousAmount, newAmount, notes = '') {
  data.savingsHistories.push({
    id: data.nextIds.history++,
    goalId: Number(goalId),
    actionType,
    amountChanged,
    previousAmount,
    newAmount,
    notes,
    createdAt: new Date().toISOString(),
    updatedAt: new Date().toISOString(),
  });
}

function parseCookies(req) {
  return Object.fromEntries((req.headers.cookie || '').split(';').filter(Boolean).map((part) => {
    const [key, ...rest] = part.trim().split('=');
    return [key, decodeURIComponent(rest.join('='))];
  }));
}

function currentUser(req, data) {
  const sid = parseCookies(req).kwarta_sid;
  const session = sessions.get(sid);
  if (session && typeof session === 'object') {
    const maxAge = session.role === 'admin' || !session.keepLogin ? 30 * 60 * 1000 : 30 * 24 * 60 * 60 * 1000;
    if (Date.now() - Number(session.lastActivityAt || Date.now()) > maxAge) {
      sessions.delete(sid);
      return null;
    }
    session.lastActivityAt = Date.now();
  }
  const userId = typeof session === 'object' ? session.userId : session;
  const user = userId ? data.users.find((item) => item.id === userId) : null;
  if (user && (user.status || 'active') !== 'active') {
    sessions.delete(sid);
    return null;
  }
  return user ? { ...user, keepLogin: Boolean(session?.keepLogin) } : null;
}

function redirect(res, location) {
  res.writeHead(302, { Location: location });
  res.end();
}

function hashPassword(password, salt = crypto.randomBytes(16).toString('hex')) {
  const hash = crypto.scryptSync(password, salt, 64).toString('hex');
  return `${salt}:${hash}`;
}

function verifyPassword(password, stored) {
  const [salt, hash] = stored.split(':');
  return hashPassword(password, salt) === `${salt}:${hash}`;
}

function body(req) {
  return new Promise((resolve) => {
    let raw = '';
    req.on('data', (chunk) => { raw += chunk; });
    req.on('end', () => resolve(Object.fromEntries(new URLSearchParams(raw))));
  });
}

function isStrongPassword(password = '') {
  return String(password).length >= 8 && /[A-Z]/.test(password) && /[a-z]/.test(password) && /\d/.test(password);
}

function logAdminActivity(data, adminId, action, description = '') {
  data.adminActivityLogs.push({
    id: data.nextIds.adminLog++,
    adminId: Number(adminId),
    action,
    description,
    createdAt: new Date().toISOString(),
  });
}

function renderNav(req, user) {
  const activeUrl = new URL(req.url, `http://${req.headers.host}`);
  const activePath = activeUrl.pathname;
  const isAdminSection = activePath.startsWith('/admin');
  const items = isAdminSection ? [
    { href: '/admin/dashboard', label: 'Admin Dashboard', icon: 'dashboard', paths: ['/admin/dashboard'] },
    { href: '/admin/stats', label: 'User Statistics', icon: 'chart', paths: ['/admin/stats'] },
    { href: '/admin/users', label: 'User Management', icon: 'avatar', paths: ['/admin/users'] },
    { href: '/admin/activity', label: 'System Activity', icon: 'receipt', paths: ['/admin/activity'] },
    { href: '/admin/profile', label: 'Admin Profile', icon: 'game', paths: ['/admin/profile'] },
  ] : [
    { href: '/dashboard', label: 'Dashboard', icon: 'dashboard', paths: ['/dashboard'] },
    { href: '/transactions', label: 'Transactions', icon: 'coin', paths: ['/transactions', '/transaction/new', '/transaction/edit'] },
    { href: '/budgets', label: 'Budget', icon: 'wallet', paths: ['/budgets'] },
    { href: '/savings', label: 'Savings', icon: 'piggy', paths: ['/savings'] },
    { href: '/receipt', label: 'Receipt', icon: 'receipt', paths: ['/receipt'] },
    { href: '/gamification', label: 'Game Hub', icon: 'game', paths: ['/gamification'] },
    { href: '/profile', label: 'Profile', icon: 'avatar', paths: ['/profile'] },
  ];
  const links = items.map((item) => {
    const active = item.paths.includes(activePath);
    return `<li class="nav-item"><a class="nav-link pixel-nav-link ${active ? 'active' : ''}" href="${item.href}" ${active ? 'aria-current="page"' : ''}><span class="pixel-nav-icon nav-icon-${item.icon}" aria-hidden="true"></span><span class="nav-label">${item.label}</span></a></li>`;
  }).join('');

  return `
    <nav class="navbar navbar-expand-xl navbar-dark bg-kwarta sticky-top pixel-navbar">
      <div class="container">
        <a class="navbar-brand fw-bold" href="${isAdminSection ? '/admin/dashboard' : '/dashboard'}"><img class="app-logo" src="/images/mascot-default.png" alt="Kwarta mascot logo" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><span>Kwarta</span></a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav" aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
          <span class="navbar-toggler-icon"></span>
        </button>
        <div class="collapse navbar-collapse" id="mainNav">
          <ul class="navbar-nav pixel-nav-list me-auto mb-2 mb-xl-0">${links}</ul>
          <div class="pixel-nav-actions">
            <span class="navbar-text player-chip"><span class="pixel-nav-icon nav-icon-avatar" aria-hidden="true"></span> ${isAdminSection ? 'Admin' : 'Player'} ${escapeHtml(user.name)}</span>
            <a class="btn btn-sm btn-light pixel-logout" href="/logout"><span class="pixel-nav-icon nav-icon-exit" aria-hidden="true"></span> Logout</a>
          </div>
        </div>
      </div>
    </nav>`;
}

function page(req, user, title, content, guestMainClass = 'auth-shell') {
  const nav = user ? renderNav(req, user) : '';
  const sessionGuard = user && !user.keepLogin ? `<script>
  (function () {
    const marker = 'kwarta_tab_session_active';
    const params = new URLSearchParams(window.location.search);
    if (params.get('fresh') === '1') {
      sessionStorage.setItem(marker, '1');
      params.delete('fresh');
      const query = params.toString();
      window.history.replaceState({}, '', window.location.pathname + (query ? '?' + query : '') + window.location.hash);
      return;
    }
    if (!sessionStorage.getItem(marker)) {
      window.location.replace('/logout?expired=1');
    }
  })();
  </script>` : '';

  return `<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>${escapeHtml(title)} | Kwarta</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
  <link href="/assets/css/styles.css" rel="stylesheet">
</head>
<body>
${nav}
<main class="${user ? 'container py-4' : guestMainClass}">${content}</main>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
<script src="/assets/js/app.js"></script>
${sessionGuard}
</body>
</html>`;
}

function sendHtml(res, html) {
  res.writeHead(200, { 'Content-Type': 'text/html; charset=utf-8' });
  res.end(html);
}

function authCard(title, subtitle, fields, action, footer) {
  const keepLogin = action === '/login' ? '<div class="form-check mb-4"><input class="form-check-input" id="keepLogin" type="checkbox" name="keepLogin" value="1"><label class="form-check-label" for="keepLogin">Keep me logged in</label></div>' : '';
  return `<div class="card auth-card"><div class="card-body p-4 p-md-5">
    <div class="text-center mb-4">
      <img class="auth-logo mx-auto mb-3" src="/images/mascot-default.png" alt="Kwarta mascot logo" onerror="this.onerror=null;this.src='/images/mascot-default.png';">
      <h1 class="h3 fw-bold mb-1">${title}</h1>
      <p class="text-muted mb-0">${subtitle}</p>
    </div>
    <form method="post" action="${action}">
      ${fields}
      ${keepLogin}
      <button class="btn btn-success w-100" type="submit">${action === '/register' ? 'Register' : 'Login'}</button>
    </form>
    <p class="text-center mt-4 mb-0">${footer}</p>
  </div></div>`;
}

function landing(req, res) {
  sendHtml(res, page(req, null, 'Welcome', `
    <section class="landing-hero">
      <div class="landing-nav">
        <a class="landing-brand" href="/"><img class="landing-logo" src="/images/mascot-default.png" alt="Kwarta mascot logo" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><span>Kwarta</span></a>
        <div class="landing-nav-actions"><a class="btn btn-outline-light pixel-landing-btn" href="/login">Login</a><a class="btn btn-warning pixel-landing-btn" href="/register">Register</a></div>
      </div>
      <div class="landing-hero-content">
        <div class="landing-copy">
          <span class="level-pill mb-3">Pixel Finance Tracker</span>
          <h1>Kwarta</h1>
          <p class="landing-tagline">Track your money. Build your savings. Level up your financial habits.</p>
          <p class="landing-description">Kwarta helps you manage income, expenses, budgets, savings cart items, streaks, rewards, and financial progress in a fun Filipino-inspired pixel dashboard.</p>
          <div class="landing-cta"><a class="btn btn-warning btn-lg pixel-landing-btn" href="/register">Start Tracking</a><a class="btn btn-outline-light btn-lg pixel-landing-btn" href="/login">Login</a></div>
        </div>
        <div class="landing-mascot-stage" aria-hidden="true"><img class="landing-mascot" src="/images/mascot-dashboard.png" alt="Kwarta mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><span class="landing-pixel-icon coin"></span><span class="landing-pixel-icon wallet"></span><span class="landing-pixel-icon chart"></span></div>
      </div>
    </section>
    <section class="landing-features">
      <div class="landing-section-heading"><span class="text-muted-small text-uppercase fw-bold">Feature Preview</span><h2>Everything you need to start managing money.</h2></div>
      <div class="landing-feature-grid">
        <article class="landing-feature-card"><span class="pixel-nav-icon nav-icon-coin" aria-hidden="true"></span><h3>Track Income and Expenses</h3><p>Log money in and out, categorize records, and understand where your pesos go.</p></article>
        <article class="landing-feature-card"><span class="pixel-nav-icon nav-icon-piggy" aria-hidden="true"></span><h3>Manage Savings Cart</h3><p>Plan items you want to buy, set target months, and mark items as already bought.</p></article>
        <article class="landing-feature-card"><span class="pixel-nav-icon nav-icon-wallet" aria-hidden="true"></span><h3>Monitor Budgets</h3><p>Set monthly category limits and see progress before spending gets out of hand.</p></article>
        <article class="landing-feature-card"><span class="pixel-nav-icon nav-icon-trophy" aria-hidden="true"></span><h3>Earn Streaks and Badges</h3><p>Build better habits with XP, rewards, quests, and friendly progress signals.</p></article>
        <article class="landing-feature-card"><span class="pixel-nav-icon nav-icon-receipt" aria-hidden="true"></span><h3>Review Monthly Receipts</h3><p>Generate receipt-style monthly summaries to see income, expenses, and remaining balance.</p></article>
      </div>
    </section>
  `, 'landing-shell'));
}

function categoryName(id) {
  return categories.find((category) => category.id === Number(id))?.name || 'Others';
}

function userTransactions(data, userId) {
  return data.transactions
    .filter((item) => item.userId === userId)
    .sort((a, b) => b.date.localeCompare(a.date) || b.id - a.id);
}

function requireUser(req, res, data) {
  const user = currentUser(req, data);
  if (!user) redirect(res, '/login');
  return user;
}

function requireAdmin(req, res, data) {
  const user = currentUser(req, data);
  if (!user) {
    redirect(res, '/login');
    return null;
  }
  if (user.role !== 'admin') {
    redirect(res, '/dashboard');
    return null;
  }
  return user;
}

function dashboard(req, res, data, user) {
  const tx = userTransactions(data, user.id);
  const income = tx.filter((item) => item.type === 'income').reduce((sum, item) => sum + item.amount, 0);
  const expenses = tx.filter((item) => item.type === 'expense').reduce((sum, item) => sum + item.amount, 0);
  const month = new Date().toISOString().slice(0, 7);
  const monthExpenses = tx.filter((item) => item.type === 'expense' && item.date.startsWith(month));
  const byCategory = categories.map((category) => ({
    name: category.name,
    total: monthExpenses.filter((item) => Number(item.categoryId) === category.id).reduce((sum, item) => sum + item.amount, 0),
  })).filter((item) => item.total > 0);
  const balance = income - expenses;
  const currentMoney = Number(user.currentMoney ?? balance);
  const savingsPercent = income > 0 ? Math.max(0, Math.min(100, Math.round((balance / income) * 100))) : 0;
  const recentRows = tx.slice(0, 6).map((item) => `<tr>
    <td>${escapeHtml(item.date)}</td><td>${escapeHtml(categoryName(item.categoryId))}</td>
    <td><span class="badge ${item.type === 'income' ? 'badge-soft-success' : 'badge-soft-danger'}">${item.type}</span></td>
    <td class="text-end fw-semibold">${money(item.amount)}</td>
  </tr>`).join('') || `<tr><td colspan="4"><div class="empty-state"><img class="mascot-empty-img" src="/images/mascot-guide.png" alt="Kwarta empty transaction mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><div><strong>No transactions yet.</strong><div class="text-muted-small">Your mascot is ready. Log your first income or expense to start earning XP.</div></div></div></td></tr>`;

  sendHtml(res, page(req, user, 'Dashboard', `
    <section class="page-header-panel">
      <div class="page-header-main">
        <span class="page-header-icon"><span class="pixel-nav-icon nav-icon-dashboard" aria-hidden="true"></span></span>
        <div class="page-header-copy">
          <span class="level-pill mb-2"><i class="bi bi-stars"></i> Level ${Math.max(1, Math.floor(tx.length * 20 / 250) + 1)}: ${tx.length >= 13 ? 'Kwarta Keeper' : 'Budget Beginner'}</span>
          <h1 class="page-header-title">Dashboard</h1>
          <p class="page-header-subtitle">Quick view of your balance, progress, and financial activity.</p>
          <div class="page-header-meta"><span class="page-header-chip">${tx.length * 20} XP total</span><span class="page-header-chip">${new Set(tx.map((item) => item.date)).size} day streak</span><span class="page-header-chip">${tx.length} transactions</span></div>
        </div>
      </div>
      <div class="page-header-actions"><a class="btn btn-success" href="/transaction/new"><i class="bi bi-plus-circle"></i> Add Transaction</a></div>
    </section>
    <section class="current-money-card pixel-card mb-4">
      <div class="row g-4 align-items-center">
        <div class="col-lg-3 text-center"><img class="mascot-money-img" src="/images/mascot-rewards.png" alt="Kwarta current money mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"></div>
        <div class="col-lg-5"><span class="level-pill mb-3"><i class="bi bi-wallet2"></i> Current Money</span><div class="balance-label text-muted-small text-uppercase fw-bold">Available Balance</div><div class="current-money-amount">${money(currentMoney)}</div><p class="mascot-message mb-0">${balance >= 0 ? `Nice! You saved ${money(balance)} overall.` : `Careful! Expenses are ahead by ${money(Math.abs(balance))}.`}</p><form class="current-money-form mt-3" method="post" action="/wallet/update"><label class="form-label">Edit Current Money</label><div class="input-group"><span class="input-group-text">PHP</span><input class="form-control" type="number" step="0.01" min="0" name="currentMoney" value="${currentMoney}" required><button class="btn btn-success" type="submit"><i class="bi bi-save"></i> Save</button></div></form></div>
        <div class="col-lg-4"><div class="money-summary-grid"><div><span>Income</span><strong>${money(income)}</strong></div><div><span>Expenses</span><strong>${money(expenses)}</strong></div><div><span>Ledger Balance</span><strong>${money(balance)}</strong></div><div><span>Current Money</span><strong>${money(currentMoney)}</strong></div></div><div class="mt-3"><div class="d-flex justify-content-between text-muted-small mb-1"><span>Savings Progress</span><span>${savingsPercent}%</span></div><div class="progress"><div class="progress-bar bg-success" style="width:${savingsPercent}%"></div></div></div></div>
      </div>
    </section>
    <div class="row g-3 mb-4">
      <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="text-muted-small">Total Income</div><div class="h4 fw-bold mb-0">${money(income)}</div></div></div></div>
      <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="text-muted-small">Total Expenses</div><div class="h4 fw-bold mb-0">${money(expenses)}</div></div></div></div>
      <div class="col-md-4"><div class="card stat-card"><div class="card-body"><div class="text-muted-small">Current Balance</div><div class="h4 fw-bold mb-0">${money(balance)}</div></div></div></div>
    </div>
    <div class="row g-4">
      <div class="col-lg-6"><div class="card content-card"><div class="card-body"><h2 class="h5 fw-bold">Income vs Expenses</h2><div class="chart-box"><canvas id="comparisonChart"></canvas></div></div></div></div>
      <div class="col-lg-6"><div class="card content-card"><div class="card-body"><h2 class="h5 fw-bold">Spending Categories</h2><div class="chart-box"><canvas id="categoryChart"></canvas></div></div></div></div>
    </div>
    <div class="card content-card mt-4"><div class="card-body"><h2 class="h5 fw-bold mb-3">Recent Transactions</h2><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Date</th><th>Category</th><th>Type</th><th class="text-end">Amount</th></tr></thead><tbody>${recentRows}</tbody></table></div></div></div>
    <script>document.addEventListener('DOMContentLoaded',function(){renderDoughnutChart('comparisonChart',['Income','Expenses'],[${income},${expenses || 1}]);renderDoughnutChart('categoryChart',${JSON.stringify(byCategory.map((i) => i.name).length ? byCategory.map((i) => i.name) : ['No expenses'])},${JSON.stringify(byCategory.map((i) => i.total).length ? byCategory.map((i) => i.total) : [1])});});</script>
  `));
}

function transactionForm(req, res, user, item = {}, errors = []) {
  const isEdit = Boolean(item.id);
  const type = item.type || 'expense';
  const options = categories
    .filter((category) => category.type === type || category.type === 'both')
    .map((category) => `<option value="${category.id}" ${Number(item.categoryId) === category.id ? 'selected' : ''}>${escapeHtml(category.name)}</option>`)
    .join('');
  const errorHtml = errors.length ? `<div class="alert alert-danger">${errors.map((error) => `<div>${escapeHtml(error)}</div>`).join('')}</div>` : '';
  sendHtml(res, page(req, user, isEdit ? 'Edit Transaction' : 'Add Transaction', `
    <div class="row justify-content-center"><div class="col-lg-7"><div class="card content-card"><div class="card-body p-4">
      <div class="d-flex justify-content-between align-items-center mb-3"><h1 class="h4 fw-bold mb-0">${isEdit ? 'Edit' : 'Add'} Transaction</h1><a class="btn btn-sm btn-outline-secondary" href="/transactions">Back</a></div>
      ${errorHtml}
      <form method="post" action="${isEdit ? `/transaction/edit?id=${item.id}` : '/transaction/new'}" id="transactionForm" novalidate>
        <div class="mb-3"><label class="form-label">Type</label><select class="form-select" name="type"><option value="expense" ${type === 'expense' ? 'selected' : ''}>Expense</option><option value="income" ${type === 'income' ? 'selected' : ''}>Income</option></select></div>
        <div class="mb-3"><label class="form-label">Category</label><select class="form-select" name="categoryId">${options}</select></div>
        <div class="row g-3"><div class="col-md-6"><label class="form-label">Amount</label><input class="form-control" id="amount" type="number" step="0.01" min="0.01" name="amount" value="${escapeHtml(item.amount || '')}" required><div class="invalid-feedback" id="amountFeedback">Amount is required.</div></div><div class="col-md-6"><label class="form-label">Date</label><input class="form-control" type="date" name="date" value="${escapeHtml(item.date || new Date().toISOString().slice(0, 10))}" required></div></div>
        <div class="my-3"><label class="form-label">Notes</label><textarea class="form-control" name="notes" rows="3" maxlength="255">${escapeHtml(item.notes || '')}</textarea></div>
        <button class="btn btn-success" type="submit">Save Transaction</button>
      </form>
      <script>
        document.getElementById('transactionForm').addEventListener('submit', function (event) {
          const input = document.getElementById('amount');
          const feedback = document.getElementById('amountFeedback');
          const value = input.value.trim();
          input.classList.remove('is-invalid');
          if (value === '') {
            event.preventDefault();
            feedback.textContent = 'Amount is required.';
            input.classList.add('is-invalid');
            input.focus();
          } else if (Number(value) <= 0) {
            event.preventDefault();
            feedback.textContent = 'Amount must be greater than zero.';
            input.classList.add('is-invalid');
            input.focus();
          }
        });
      </script>
    </div></div></div></div>
  `));
}

function transactions(req, res, data, user) {
  const rows = userTransactions(data, user.id).map((item) => `<tr>
    <td>${escapeHtml(item.date)}</td><td>${escapeHtml(categoryName(item.categoryId))}</td><td>${escapeHtml(item.type)}</td><td>${escapeHtml(item.notes || '')}</td><td class="text-end fw-semibold">${money(item.amount)}</td>
    <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="/transaction/edit?id=${item.id}"><i class="bi bi-pencil"></i></a>
    <form class="d-inline" method="post" action="/transaction/delete"><input type="hidden" name="id" value="${item.id}"><button class="btn btn-sm btn-outline-danger" type="submit"><i class="bi bi-trash"></i></button></form></td>
  </tr>`).join('') || '<tr><td colspan="6" class="text-center text-muted py-4">No transactions yet.</td></tr>';
  sendHtml(res, page(req, user, 'Transactions', `
    <section class="page-header-panel"><div class="page-header-main"><span class="page-header-icon"><span class="pixel-nav-icon nav-icon-coin" aria-hidden="true"></span></span><div class="page-header-copy"><h1 class="page-header-title">Transactions</h1><p class="page-header-subtitle">Manage your income and expense records in one place.</p></div></div><div class="page-header-actions"><a class="btn btn-success" href="/transaction/new"><i class="bi bi-plus-circle"></i> Add Transaction</a></div></section>
    <div class="card content-card"><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Date</th><th>Category</th><th>Type</th><th>Notes</th><th class="text-end">Amount</th><th class="text-end">Actions</th></tr></thead><tbody>${rows}</tbody></table></div></div></div>
  `));
}

function budgets(req, res, data, user) {
  const month = new Date().toISOString().slice(0, 7);
  const tx = userTransactions(data, user.id).filter((item) => item.type === 'expense' && item.date.startsWith(month));
  const rows = categories.filter((category) => category.type !== 'income').map((category) => {
    const budget = data.budgets.find((item) => item.userId === user.id && item.categoryId === category.id && item.month === month);
    const spent = tx.filter((item) => Number(item.categoryId) === category.id).reduce((sum, item) => sum + item.amount, 0);
    const amount = budget?.amount || 0;
    const pct = amount ? Math.min(100, Math.round((spent / amount) * 100)) : 0;
    return `<div class="col-md-6"><div class="border rounded-2 p-3 h-100"><div class="fw-semibold">${escapeHtml(category.name)}</div><div class="text-muted-small mb-2">${money(spent)} spent ${amount ? `of ${money(amount)}` : 'with no budget set'}</div><div class="progress mb-2"><div class="progress-bar ${spent >= amount && amount ? 'bg-danger' : pct >= 80 ? 'bg-warning' : 'bg-success'}" style="width:${pct}%"></div></div><span class="badge text-bg-${amount ? (spent >= amount ? 'danger' : pct >= 80 ? 'warning' : 'success') : 'secondary'}">${amount ? `${pct}% used` : 'Not set'}</span></div></div>`;
  }).join('');
  const options = categories.filter((category) => category.type !== 'income').map((category) => `<option value="${category.id}">${escapeHtml(category.name)}</option>`).join('');
  sendHtml(res, page(req, user, 'Budgets', `
    <section class="page-header-panel"><div class="page-header-main"><span class="page-header-icon"><span class="pixel-nav-icon nav-icon-wallet" aria-hidden="true"></span></span><div class="page-header-copy"><h1 class="page-header-title">Budget Management</h1><p class="page-header-subtitle">Set spending limits and track your monthly budget progress.</p></div></div><div class="page-header-actions"><span class="page-header-chip">This Month</span></div></section><div class="row g-4"><div class="col-lg-4"><div class="card content-card"><div class="card-body"><h2 class="h5 fw-bold mb-3">Set Budget</h2><form method="post" action="/budgets"><div class="mb-3"><label class="form-label">Category</label><select class="form-select" name="categoryId">${options}</select></div><div class="mb-3"><label class="form-label">Monthly Amount</label><input class="form-control" type="number" step="0.01" min="0.01" name="amount" required></div><button class="btn btn-success w-100">Save Budget</button></form></div></div></div><div class="col-lg-8"><div class="card content-card"><div class="card-body"><h2 class="h5 fw-bold mb-3">This Month</h2><div class="row g-3">${rows}</div></div></div></div></div>
  `));
}

function savings(req, res, data, user) {
  const userGoals = data.savingsGoals.filter((goal) => goal.userId === user.id);
  const totalBudgetNeed = userGoals.reduce((sum, goal) => sum + Number(goal.targetAmount || 0), 0);
  const currentBudget = userGoals.reduce((sum, goal) => sum + Number(goal.savedAmount || 0), 0);
  const remainingBudget = Math.max(0, totalBudgetNeed - currentBudget);
  const cartProgress = totalBudgetNeed > 0 ? Math.min(100, Math.round((currentBudget / totalBudgetNeed) * 100)) : 0;
  const rows = userGoals.map((goal) => {
    const pct = goal.targetAmount > 0 ? Math.min(100, Math.round((goal.savedAmount / goal.targetAmount) * 100)) : 0;
    const remaining = Math.max(0, goal.targetAmount - goal.savedAmount);
    const targetMonth = goal.targetMonth || '';
    const targetMonthText = monthLabel(targetMonth);
    const description = String(goal.description || '').trim();
    const isBought = Boolean(goal.isBought);
    const boughtText = goal.boughtAt ? ` on ${new Intl.DateTimeFormat('en-PH', { month: 'short', day: 'numeric', year: 'numeric' }).format(new Date(goal.boughtAt))}` : '';
    const histories = data.savingsHistories
      .filter((item) => item.goalId === goal.id)
      .sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt) || b.id - a.id);
    const historyRows = histories.map((item) => `<div class="savings-history-item">
      <div class="d-flex justify-content-between gap-3">
        <div><strong>${escapeHtml(savingsActionLabel(item.actionType))}</strong><div class="text-muted-small">${escapeHtml(historyTime(item.createdAt))}</div></div>
        <form method="post" action="/savings/history/delete"><input type="hidden" name="goalId" value="${goal.id}"><input type="hidden" name="historyId" value="${item.id}"><button class="btn btn-sm btn-outline-danger savings-history-delete" type="submit" onclick="return confirm('Delete this history entry? This will not change the saved amount.');"><i class="bi bi-trash"></i> Delete</button></form>
      </div>
      <div class="text-muted-small mt-1">${escapeHtml(item.notes || '')}</div>
      <div class="savings-history-meta mt-2">
        ${item.amountChanged !== null && item.amountChanged !== undefined ? `<span>Changed: ${money(Math.abs(item.amountChanged))}</span>` : ''}
        ${item.previousAmount !== null && item.previousAmount !== undefined && item.newAmount !== null && item.newAmount !== undefined ? `<span>${money(item.previousAmount)} to ${money(item.newAmount)}</span>` : ''}
      </div>
    </div>`).join('') || `<div class="empty-state"><img class="mascot-empty-img" src="/images/mascot-guide.png" alt="Kwarta guide mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><div><strong>No activity yet.</strong><div class="text-muted-small">Add or reduce savings to start the history log.</div></div></div>`;

    return `<div class="col-md-6" id="goal-${goal.id}">
      <button class="card content-card savings-goal-card h-100 text-start w-100" type="button" data-bs-toggle="modal" data-bs-target="#goalModal${goal.id}">
        <div class="card-body">
          <div class="d-flex align-items-start justify-content-between gap-3 mb-3"><div><h2 class="h5 fw-bold mb-1">${escapeHtml(goal.name)}</h2><div class="d-flex flex-wrap align-items-center gap-2"><div class="text-muted-small">Tap to manage this item</div>${isBought ? '<span class="savings-status-badge bought">Already Bought</span>' : ''}</div></div><img class="mascot-mini-img" src="/images/mascot-savings.png" alt="Kwarta savings mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"></div>
          ${description ? `<p class="savings-description-preview">${escapeHtml(description)}</p>` : ''}
          <div class="progress mb-2"><div class="progress-bar bg-success" style="width:${pct}%"></div></div>
          <div class="d-flex justify-content-between gap-3 text-muted-small"><span>${pct}% saved</span><span>${money(goal.savedAmount)} / ${money(goal.targetAmount)}</span></div>
          ${targetMonthText ? `<div class="text-muted-small mt-2">Target: ${escapeHtml(targetMonthText)}</div>` : ''}
          <div class="savings-card-footer mt-3"><span>Remaining</span><strong>${money(remaining)}</strong></div>
        </div>
      </button>
      <div class="modal fade" id="goalModal${goal.id}" tabindex="-1" aria-labelledby="goalModalLabel${goal.id}" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-scrollable">
          <div class="modal-content pixel-modal">
            <div class="modal-header"><div><h2 class="modal-title h4 fw-bold" id="goalModalLabel${goal.id}">${escapeHtml(goal.name)}</h2><div class="text-muted-small">Cart item details, target month, and activity history</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
            <div class="modal-body"><div class="row g-4">
              <div class="col-lg-5">
                <div class="d-flex align-items-center gap-3 mb-3"><img class="mascot-section-img" src="/images/mascot-savings.png" alt="Kwarta savings mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><div class="flex-grow-1"><div class="d-flex justify-content-between text-muted-small mb-1"><span>${pct}% saved</span><span>${money(remaining)} remaining</span></div><div class="progress"><div class="progress-bar bg-success" style="width:${pct}%"></div></div>${isBought ? `<span class="savings-status-badge bought mt-2">Already Bought${escapeHtml(boughtText)}</span>` : ''}</div></div>
                <form method="post" action="/savings/update" class="mb-4">
                  <input type="hidden" name="id" value="${goal.id}">
                  <div class="mb-3"><label class="form-label">Item Name</label><input class="form-control" name="name" value="${escapeHtml(goal.name)}" maxlength="120" required></div>
                  <div class="mb-3"><label class="form-label">Item Description</label><textarea class="form-control" name="description" rows="3" maxlength="255" placeholder="What is this item for?">${escapeHtml(description)}</textarea></div>
                  <div class="row g-3"><div class="col-md-6"><label class="form-label">Item Price</label><input class="form-control" type="number" step="0.01" min="0.01" name="targetAmount" value="${goal.targetAmount}" required></div><div class="col-md-6"><label class="form-label">Amount Saved</label><input class="form-control" type="number" step="0.01" min="0" name="savedAmount" value="${goal.savedAmount}" required></div><div class="col-12"><label class="form-label">Target Month to Avail</label><input class="form-control" type="month" name="targetMonth" value="${escapeHtml(targetMonth)}"><div class="form-text">Choose the month when you plan to buy or avail this item.</div></div></div>
                  <button class="btn btn-success w-100 mt-3">Update Item</button>
                </form>
                <form method="post" action="/savings/bought" class="mb-4"><input type="hidden" name="id" value="${goal.id}"><button class="btn ${isBought ? 'btn-outline-secondary' : 'btn-warning'} w-100" type="submit"><i class="bi ${isBought ? 'bi-arrow-counterclockwise' : 'bi-bag-check'}"></i> ${isBought ? 'Undo Already Bought' : 'Mark as Already Bought'}</button></form>
                <div class="row g-3"><div class="col-md-6"><form method="post" action="/savings/adjust" class="savings-adjust-box"><input type="hidden" name="id" value="${goal.id}"><input type="hidden" name="direction" value="increase"><label class="form-label">Add Savings</label><div class="input-group"><span class="input-group-text">PHP</span><input class="form-control" type="number" step="0.01" min="0.01" name="amountChange" placeholder="500" required></div><button class="btn btn-outline-success w-100 mt-2">Add Savings</button></form></div><div class="col-md-6"><form method="post" action="/savings/adjust" class="savings-adjust-box"><input type="hidden" name="id" value="${goal.id}"><input type="hidden" name="direction" value="decrease"><label class="form-label">Reduce Savings</label><div class="input-group"><span class="input-group-text">PHP</span><input class="form-control" type="number" step="0.01" min="0.01" max="${goal.savedAmount}" name="amountChange" placeholder="200" required></div><button class="btn btn-outline-danger w-100 mt-2">Reduce Savings</button></form></div></div>
              </div>
              <div class="col-lg-7">
                <div class="d-flex justify-content-between align-items-center gap-3 mb-3"><h3 class="h5 fw-bold mb-0">Cart Activity</h3><form method="post" action="/savings/delete"><input type="hidden" name="id" value="${goal.id}"><button class="btn btn-sm btn-outline-danger" type="submit" onclick="return confirm('Remove this cart item and its history?');"><i class="bi bi-trash"></i> Remove Item</button></form></div>
                <div class="savings-history-list">${historyRows}</div>
              </div>
            </div></div>
          </div>
        </div>
      </div>
    </div>`;
  }).join('') || '<div class="col-12"><div class="card content-card"><div class="card-body"><div class="empty-state justify-content-center"><img class="mascot-empty-img" src="/images/mascot-savings.png" alt="Kwarta savings mascot" onerror="this.onerror=null;this.src=\'/images/mascot-default.png\';"><div><strong>No cart items yet.</strong><div class="text-muted-small">Add an item, choose when you want to avail it, and start saving toward it.</div></div></div></div></div></div>';
  sendHtml(res, page(req, user, 'Savings Cart', `
    <section class="page-header-panel"><div class="page-header-main"><span class="page-header-icon"><span class="pixel-nav-icon nav-icon-piggy" aria-hidden="true"></span></span><div class="page-header-copy"><h1 class="page-header-title">Savings Cart</h1><p class="page-header-subtitle">Add items you want to buy, set a target month, and save toward each one.</p></div></div><div class="page-header-actions"><img class="mascot-section-img" src="/images/mascot-savings.png" alt="Kwarta savings mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"></div></section>
    <div class="card content-card savings-cart-summary mb-4"><div class="card-body"><div class="row g-3 align-items-center"><div class="col-lg-4"><div class="text-muted-small text-uppercase fw-bold">Total Budget Need</div><div class="h3 fw-bold mb-0">${money(totalBudgetNeed)}</div><div class="text-muted-small">Full price of all cart items.</div></div><div class="col-lg-4"><div class="text-muted-small text-uppercase fw-bold">Current Budget</div><div class="h3 fw-bold mb-0 text-success">${money(currentBudget)}</div><div class="text-muted-small">Money already saved for your cart.</div></div><div class="col-lg-4"><div class="d-flex justify-content-between text-muted-small mb-1"><span>Cart Progress</span><strong>${cartProgress}%</strong></div><div class="progress mb-2"><div class="progress-bar bg-success" style="width:${cartProgress}%"></div></div><div class="text-muted-small">${money(remainingBudget)} still needed.</div></div></div></div></div>
    <div class="row g-4"><div class="col-lg-4"><div class="card content-card"><div class="card-body"><h2 class="h5 fw-bold mb-3">Add Item to Cart</h2><form method="post" action="/savings"><div class="mb-3"><label class="form-label">Item Name</label><input class="form-control" name="name" maxlength="120" required></div><div class="mb-3"><label class="form-label">Item Description</label><textarea class="form-control" name="description" rows="3" maxlength="255" placeholder="Example: For online class, school project, or birthday gift"></textarea></div><div class="mb-3"><label class="form-label">Item Price</label><input class="form-control" type="number" step="0.01" min="0.01" name="targetAmount" required></div><div class="mb-3"><label class="form-label">Amount Saved</label><input class="form-control" type="number" step="0.01" min="0" name="savedAmount" value="0"></div><div class="mb-3"><label class="form-label">Target Month to Avail</label><input class="form-control" type="month" name="targetMonth"><div class="form-text">Choose the month when you plan to buy or avail this item.</div></div><button class="btn btn-success w-100">Add to Cart</button></form></div></div></div><div class="col-lg-8"><div class="row g-3">${rows}</div></div></div>
  `));
}

function gamification(req, res, data, user) {
  const tx = userTransactions(data, user.id);
  const today = new Date().toISOString().slice(0, 10);
  const weekStart = new Date();
  weekStart.setDate(weekStart.getDate() - weekStart.getDay() + 1);
  const weekKey = weekStart.toISOString().slice(0, 10);
  const savingsDone = data.savingsGoals.filter((goal) => goal.userId === user.id && goal.savedAmount >= goal.targetAmount).length;
  const savingsCount = data.savingsGoals.filter((goal) => goal.userId === user.id).length;
  const dailyExpenses = tx.filter((item) => item.type === 'expense' && item.date === today).length;
  const weeklySavings = tx.filter((item) => item.type === 'expense' && item.date >= weekKey && categoryName(item.categoryId).toLowerCase() === 'savings').reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const income = tx.filter((item) => item.type === 'income').reduce((sum, item) => sum + item.amount, 0);
  const expenses = tx.filter((item) => item.type === 'expense').reduce((sum, item) => sum + item.amount, 0);
  const xp = (tx.length * 20) + (savingsDone * 150) + (income >= 10000 ? 100 : 0) + (income > expenses && tx.length ? 80 : 0);
  const level = Math.max(1, Math.floor(xp / 250) + 1);
  const levelPct = Math.min(100, Math.round((xp % 250) / 250 * 100));
  const streakDays = new Set(tx.map((item) => item.date)).size;
  const badgesData = [
    { name: 'First Transaction', earned: tx.length > 0, copy: 'Badge Unlocked: First Transaction' },
    { name: 'Budget Hero', earned: income >= expenses && tx.length > 0, copy: 'Keep spending below income.' },
    { name: 'Cart Finisher', earned: savingsDone > 0, copy: 'Fully save for a cart item.' },
    { name: 'PHP 10,000 Earner', earned: income >= 10000, copy: "You've earned PHP 10,000 this month!" },
  ];
  const earnedBadges = badgesData.filter((badge) => badge.earned).length;
  const badges = badgesData.map((badge) => `<div class="col-md-6 col-xl-3"><div class="achievement-tile ${badge.earned ? '' : 'locked'}"><div class="d-flex align-items-center gap-2 mb-2"><span class="pixel-nav-icon nav-icon-trophy" aria-hidden="true"></span><strong>${escapeHtml(badge.name)}</strong></div><p class="text-muted-small mb-2">${escapeHtml(badge.copy)}</p><span class="badge ${badge.earned ? 'text-bg-success' : 'text-bg-secondary'}">${badge.earned ? 'Unlocked' : 'Locked'}</span></div></div>`).join('');
  const questData = [
    { name: 'Log 3 expenses today', progress: Math.min(100, Math.round(dailyExpenses / 3 * 100)), meta: `${dailyExpenses} / 3`, href: '/transaction/new', cta: 'Log Expense' },
    { name: 'Save PHP 500 this week', progress: Math.min(100, Math.round(weeklySavings / 500 * 100)), meta: `${money(weeklySavings)} / PHP 500.00`, href: '/transactions', cta: 'Add Savings Record' },
    { name: 'Add one savings item', progress: savingsCount ? 100 : 25, meta: `${savingsCount} item${savingsCount === 1 ? '' : 's'}`, href: '/savings', cta: 'Open Savings' },
    { name: 'Complete monthly receipt review', progress: tx.length ? 65 : 10, meta: 'Review your summary', href: '/receipt', cta: 'Review Receipt' },
  ];
  const completedQuests = questData.filter((quest) => quest.progress >= 100).length;
  const quests = questData.map((quest) => `<div class="col-md-6"><div class="quest-card mission-card ${quest.progress >= 100 ? 'complete' : ''}"><div class="d-flex justify-content-between gap-2 mb-2"><strong>${escapeHtml(quest.name)}</strong><span class="badge ${quest.progress >= 100 ? 'text-bg-success' : 'text-bg-dark'}">${quest.progress >= 100 ? 'Complete' : '+XP'}</span></div><div class="progress mb-2"><div class="progress-bar bg-success" style="width:${quest.progress}%"></div></div><div class="d-flex justify-content-between text-muted-small"><span>${escapeHtml(quest.meta)}</span><span>${quest.progress}%</span></div>${quest.progress >= 100 ? '<div class="game-complete-message mt-3">Completion message: Mission cleared.</div>' : ''}<a class="btn btn-sm btn-outline-primary mt-3" href="${quest.href}">${quest.cta}</a></div></div>`).join('');

  sendHtml(res, page(req, user, 'Game Hub', `
    <section class="page-header-panel page-header-game"><div class="page-header-main"><span class="page-header-icon"><span class="pixel-nav-icon nav-icon-game" aria-hidden="true"></span></span><div class="page-header-copy"><span class="level-pill mb-2"><i class="bi bi-controller"></i> Level ${level}: ${level >= 3 ? 'Kwarta Keeper' : 'Budget Beginner'}</span><h1 class="page-header-title">Game Hub</h1><p class="page-header-subtitle">Complete financial missions, earn XP, unlock badges, and keep your Kwarta streak alive.</p><div class="page-header-meta"><span class="page-header-chip">${xp} XP</span><span class="page-header-chip">${earnedBadges}/${badgesData.length} badges</span><span class="page-header-chip">${streakDays} tracking days</span><span class="page-header-chip">${completedQuests} quests complete</span></div>${completedQuests ? `<div class="game-complete-message mt-3">Quest Complete: ${completedQuests} mission${completedQuests === 1 ? '' : 's'} ready for rewards.</div>` : ''}</div></div><div class="page-header-actions"><div class="page-header-progress"><div class="d-flex justify-content-between text-muted-small mb-1"><span>Level Progress</span><strong>${levelPct}%</strong></div><div class="progress"><div class="progress-bar bg-success" style="width:${levelPct}%"></div></div></div><img class="mascot-section-img" src="/images/mascot-rewards.png" alt="Kwarta rewards mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"></div></section>
    <div class="row g-3 mb-4"><div class="col-md-3"><div class="game-stat-tile"><span>XP</span><strong>${xp}</strong></div></div><div class="col-md-3"><div class="game-stat-tile"><span>Level</span><strong>${level}</strong></div></div><div class="col-md-3"><div class="game-stat-tile"><span>Badges</span><strong>${earnedBadges}/${badgesData.length}</strong></div></div><div class="col-md-3"><div class="game-stat-tile"><span>Streak</span><strong>${streakDays} days</strong></div></div></div>
    <div class="row g-4"><div class="col-lg-7"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Interactive Money Missions</h2><div class="mascot-message mb-3"><img class="mascot-message-img" src="/images/mascot-guide.png" alt="Kwarta guide mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><span>${streakDays ? 'Nice streak! Keep logging to protect your progress.' : 'Start your streak by logging your first money move today.'}</span></div><div class="row g-3">${quests}</div></div></div></div><div class="col-lg-5"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Reward Feed</h2><div class="d-grid gap-2"><div class="xp-event"><div class="d-flex justify-content-between gap-2"><strong>${tx.length ? 'Badge Unlocked: First Transaction' : 'Log your first transaction to unlock a badge.'}</strong><span class="badge text-bg-success">+25 XP</span></div></div><div class="xp-event"><div class="d-flex justify-content-between gap-2"><strong>${income >= 10000 ? "You've earned PHP 10,000 this month!" : 'Earn PHP 10,000 this month.'}</strong><span class="badge text-bg-success">+60 XP</span></div></div><div class="xp-event"><div class="d-flex justify-content-between gap-2"><strong>${streakDays >= 7 ? '7-Day Tracking Streak Completed' : 'Build a 7-day tracking streak.'}</strong><span class="badge text-bg-success">+40 XP</span></div></div></div></div></div></div></div>
    <div class="card content-card mt-4"><div class="card-body"><h2 class="h5 fw-bold mb-3">Unlockable Badges</h2><div class="row g-3">${badges}</div></div></div>
  `));
}

function profile(req, res, data, user, errors = []) {
  const tx = userTransactions(data, user.id);
  const income = tx.filter((item) => item.type === 'income').reduce((sum, item) => sum + item.amount, 0);
  const expenses = tx.filter((item) => item.type === 'expense').reduce((sum, item) => sum + item.amount, 0);
  const currentMoney = Number(user.currentMoney ?? income - expenses);
  const xp = tx.length * 20;
  const level = Math.max(1, Math.floor(xp / 250) + 1);
  const errorHtml = errors.length ? `<div class="alert alert-danger pixel-card">${errors.map((error) => `<div>${escapeHtml(error)}</div>`).join('')}</div>` : '';

  sendHtml(res, page(req, user, 'Profile', `
    <section class="page-header-panel"><div class="profile-header-main"><img class="profile-header-avatar" src="/images/mascot-dashboard.png" alt="Kwarta profile avatar" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><div class="page-header-copy"><span class="level-pill mb-2"><i class="bi bi-person-badge"></i> Kwarta Player Profile</span><h1 class="page-header-title">${escapeHtml(user.name)}</h1><p class="page-header-subtitle">${escapeHtml(user.email)}</p><div class="page-header-meta"><span class="page-header-chip">Level ${level}</span><span class="page-header-chip">${xp} XP</span><span class="page-header-chip">${tx.length} transactions</span></div></div></div><div class="page-header-actions"><div class="page-header-progress"><div class="d-flex justify-content-between text-muted-small mb-1"><span>Player Progress</span><strong>${Math.min(100, Math.round((xp % 250) / 250 * 100))}%</strong></div><div class="progress"><div class="progress-bar bg-success" style="width:${Math.min(100, Math.round((xp % 250) / 250 * 100))}%"></div></div></div></div></section>
    ${errorHtml}
    <div class="row g-4"><div class="col-lg-4"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Player Card</h2><div class="text-center mb-3"><img class="mascot-section-img" src="/images/mascot-default.png" alt="Kwarta profile avatar" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><div class="h4 fw-bold mt-2 mb-0">${escapeHtml(user.name)}</div><div class="text-muted-small">${escapeHtml(user.email)}</div></div><div class="d-grid gap-3"><div class="profile-info-row"><span>Current Money</span><strong>${money(currentMoney)}</strong></div><div class="profile-info-row"><span>Transactions</span><strong>${tx.length}</strong></div><div class="profile-info-row"><span>Security</span><strong>Protected</strong></div><a class="btn btn-outline-danger" href="/logout"><i class="bi bi-box-arrow-right"></i> Logout</a></div></div></div></div><div class="col-lg-8"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Player Stats</h2><div class="row g-3"><div class="col-md-3"><div class="quest-card"><div class="text-muted-small">Level</div><div class="h3 fw-bold">${level}</div></div></div><div class="col-md-3"><div class="quest-card"><div class="text-muted-small">XP</div><div class="h3 fw-bold">${xp}</div></div></div><div class="col-md-3"><div class="quest-card"><div class="text-muted-small">Income</div><div class="h5 fw-bold">${money(income)}</div></div></div><div class="col-md-3"><div class="quest-card"><div class="text-muted-small">Expenses</div><div class="h5 fw-bold">${money(expenses)}</div></div></div></div></div></div></div></div>
    <div class="row g-4 mt-1"><div class="col-lg-6"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Account Settings</h2><form method="post" action="/profile"><input type="hidden" name="action" value="update_profile"><div class="mb-3"><label class="form-label">Display Name</label><input class="form-control" name="name" value="${escapeHtml(user.name)}" required maxlength="120"></div><div class="mb-3"><label class="form-label">Email Address</label><input class="form-control" value="${escapeHtml(user.email)}" disabled><div class="form-text">Email editing is locked for account safety.</div></div><button class="btn btn-success" type="submit"><i class="bi bi-save"></i> Save Profile</button></form></div></div></div><div class="col-lg-6"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Change Password</h2><form method="post" action="/profile"><input type="hidden" name="action" value="change_password"><div class="mb-3"><label class="form-label">Current Password</label><input class="form-control" type="password" name="currentPassword" required></div><div class="mb-3"><label class="form-label">New Password</label><input class="form-control" type="password" name="newPassword" required minlength="8"></div><div class="mb-3"><label class="form-label">Confirm New Password</label><input class="form-control" type="password" name="confirmPassword" required minlength="8"></div><button class="btn btn-success" type="submit"><i class="bi bi-shield-lock"></i> Update Password</button></form></div></div></div></div>
    <div class="row g-4 mt-1"><div class="col-lg-6"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Financial Preferences</h2><div class="settings-list"><div class="settings-row"><span>Default currency</span><strong>PHP</strong></div><label class="settings-row"><span>Monthly receipt reminders</span><input class="form-check-input" type="checkbox" checked></label><label class="settings-row"><span>Budget warnings</span><input class="form-check-input" type="checkbox" checked></label><label class="settings-row"><span>Pixel theme</span><input class="form-check-input" type="checkbox" checked></label></div></div></div></div><div class="col-lg-6"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Account Security</h2><div class="settings-list"><div class="settings-row"><span>Password hashing</span><strong>Enabled</strong></div><div class="settings-row"><span>Session protection</span><strong>Enabled</strong></div><div class="settings-row"><span>User data isolation</span><strong>Enabled</strong></div><div class="settings-row"><span>Keep me logged in</span><strong>${user.keepLogin ? 'On' : 'Off'}</strong></div></div></div></div></div></div>
  `));
}

function adminDashboard(req, res, data, user) {
  const today = new Date().toISOString().slice(0, 10);
  const now = new Date();
  const weekStart = new Date(now);
  weekStart.setDate(now.getDate() - now.getDay() + 1);
  const weekKey = weekStart.toISOString().slice(0, 10);
  const monthKey = today.slice(0, 7);
  const monthLabels = [];
  const monthCounts = [];
  for (let i = 5; i >= 0; i--) {
    const date = new Date(now.getFullYear(), now.getMonth() - i, 1);
    const key = date.toISOString().slice(0, 7);
    monthLabels.push(new Intl.DateTimeFormat('en-PH', { month: 'short', year: 'numeric' }).format(date));
    monthCounts.push(data.users.filter((item) => String(item.createdAt || '').startsWith(key)).length);
  }
  const activity = data.adminActivityLogs.slice().sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)).slice(0, 6).map((log) => {
    const admin = data.users.find((item) => item.id === log.adminId);
    return `<div class="xp-event"><strong>${escapeHtml(log.action)}</strong><div class="text-muted-small">${escapeHtml(admin?.name || 'Admin')} - ${historyTime(log.createdAt)}</div>${log.description ? `<div class="text-muted-small">${escapeHtml(log.description)}</div>` : ''}</div>`;
  }).join('') || `<div class="empty-state"><img class="mascot-empty-img" src="/images/mascot-guide.png" alt="Kwarta guide mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><div><strong>No admin activity yet.</strong><div class="text-muted-small">Actions like activating users will appear here.</div></div></div>`;

  const statCards = [
    ['Total Users', data.users.length],
    ['New Today', data.users.filter((item) => String(item.createdAt || '').startsWith(today)).length],
    ['New This Week', data.users.filter((item) => String(item.createdAt || '') >= weekKey).length],
    ['New This Month', data.users.filter((item) => String(item.createdAt || '').startsWith(monthKey)).length],
    ['Active Users', data.users.filter((item) => item.status !== 'inactive').length],
    ['Inactive Users', data.users.filter((item) => item.status === 'inactive').length],
    ['Transactions', data.transactions.length],
    ['Savings Items', data.savingsGoals.length],
    ['Receipts', data.receiptLogs.length],
    ['Game Hub Users', data.users.filter((item) => userTransactions(data, item.id).length > 0).length],
  ].map(([label, value]) => `<div class="col-md-3"><div class="game-stat-tile"><span>${label}</span><strong>${value}</strong></div></div>`).join('');

  sendHtml(res, page(req, user, 'Admin Dashboard', `
    <section class="page-header-panel"><div class="page-header-main"><span class="page-header-icon"><span class="pixel-nav-icon nav-icon-dashboard" aria-hidden="true"></span></span><div class="page-header-copy"><span class="level-pill mb-2"><i class="bi bi-shield-lock"></i> Secure Admin Side</span><h1 class="page-header-title">Admin Dashboard</h1><p class="page-header-subtitle">Monitor safe website-level statistics without opening private financial records.</p></div></div><div class="page-header-actions"><span class="page-header-chip">${dateLabel(today)}</span></div></section>
    <div class="admin-privacy-note mb-4"><strong>Privacy note:</strong> Admin statistics are used only for system monitoring. Personal financial data of users is not accessible from this dashboard.</div>
    <div class="row g-3 mb-4">${statCards}</div>
    <div class="row g-4"><div class="col-lg-7"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Monthly Registration Chart</h2><div class="chart-box"><canvas id="adminRegistrationChart"></canvas></div></div></div></div><div class="col-lg-5"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Basic System Activity</h2><div class="d-grid gap-2">${activity}</div></div></div></div></div>
    <script>document.addEventListener('DOMContentLoaded',function(){const canvas=document.getElementById('adminRegistrationChart');if(!canvas||typeof Chart==='undefined')return;new Chart(canvas,{type:'bar',data:{labels:${JSON.stringify(monthLabels)},datasets:[{label:'New users',data:${JSON.stringify(monthCounts)},backgroundColor:'#2E8B57'}]},options:{maintainAspectRatio:false,responsive:true,scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});});</script>
  `));
}

function adminStats(req, res, data, user) {
  const byMonth = new Map();
  for (const item of data.users) {
    const key = String(item.createdAt || new Date().toISOString()).slice(0, 7);
    byMonth.set(key, (byMonth.get(key) || 0) + 1);
  }
  const keys = [...byMonth.keys()].sort().slice(-12);
  const labels = keys.map((key) => monthLabel(key));
  const values = keys.map((key) => byMonth.get(key));
  const roleRows = ['admin', 'user'].map((role) => `<div class="settings-row"><span>${role[0].toUpperCase() + role.slice(1)}</span><strong>${data.users.filter((item) => item.role === role).length}</strong></div>`).join('');
  const statusRows = ['active', 'inactive'].map((status) => `<div class="settings-row"><span>${status[0].toUpperCase() + status.slice(1)}</span><strong>${data.users.filter((item) => (item.status || 'active') === status).length}</strong></div>`).join('');
  sendHtml(res, page(req, user, 'User Statistics', `
    <section class="page-header-panel"><div class="page-header-main"><span class="page-header-icon"><span class="pixel-nav-icon nav-icon-chart" aria-hidden="true"></span></span><div class="page-header-copy"><h1 class="page-header-title">User Statistics</h1><p class="page-header-subtitle">Review registration growth, account roles, and account status without opening user finances.</p></div></div></section>
    <div class="admin-privacy-note mb-4"><strong>Privacy note:</strong> This page contains account-level and aggregate statistics only.</div>
    <div class="row g-4"><div class="col-lg-8"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">User Growth</h2><div class="chart-box"><canvas id="userGrowthChart"></canvas></div></div></div></div><div class="col-lg-4"><div class="card content-card mb-4"><div class="card-body"><h2 class="h5 fw-bold mb-3">Roles</h2><div class="settings-list">${roleRows}</div></div></div><div class="card content-card"><div class="card-body"><h2 class="h5 fw-bold mb-3">Account Status</h2><div class="settings-list">${statusRows}</div></div></div></div></div>
    <script>document.addEventListener('DOMContentLoaded',function(){const canvas=document.getElementById('userGrowthChart');if(!canvas||typeof Chart==='undefined')return;new Chart(canvas,{type:'line',data:{labels:${JSON.stringify(labels)},datasets:[{label:'Registered users',data:${JSON.stringify(values)},borderColor:'#2E8B57',backgroundColor:'rgba(46,139,87,0.18)',tension:.25,fill:true}]},options:{maintainAspectRatio:false,responsive:true,scales:{y:{beginAtZero:true,ticks:{precision:0}}}}});});</script>
  `));
}

function adminUsers(req, res, data, user) {
  const rows = data.users.slice().sort((a, b) => new Date(b.createdAt || 0) - new Date(a.createdAt || 0) || b.id - a.id).map((item) => `<tr><td>${item.id}</td><td>${escapeHtml(item.name)}</td><td>${escapeHtml(item.email)}</td><td>${historyTime(item.createdAt || new Date())}</td><td>${item.lastLoginAt ? historyTime(item.lastLoginAt) : 'Never'}</td><td><span class="badge ${(item.status || 'active') === 'active' ? 'text-bg-success' : 'text-bg-secondary'}">${escapeHtml((item.status || 'active').replace(/^\w/, (c) => c.toUpperCase()))}</span></td><td><span class="badge ${item.role === 'admin' ? 'text-bg-warning' : 'text-bg-light'}">${escapeHtml((item.role || 'user').replace(/^\w/, (c) => c.toUpperCase()))}</span></td><td class="text-end">${item.role === 'admin' ? '<span class="text-muted-small">Database only</span>' : `<form method="post" action="/admin/users" class="d-inline"><input type="hidden" name="userId" value="${item.id}"><input type="hidden" name="status" value="${(item.status || 'active') === 'active' ? 'inactive' : 'active'}"><button class="btn btn-sm ${(item.status || 'active') === 'active' ? 'btn-outline-danger' : 'btn-outline-success'}" type="submit">${(item.status || 'active') === 'active' ? 'Deactivate' : 'Activate'}</button></form>`}</td></tr>`).join('');
  sendHtml(res, page(req, user, 'User Management', `
    <section class="page-header-panel"><div class="page-header-main"><span class="page-header-icon"><span class="pixel-nav-icon nav-icon-avatar" aria-hidden="true"></span></span><div class="page-header-copy"><h1 class="page-header-title">User Management</h1><p class="page-header-subtitle">Activate or deactivate user accounts without accessing private financial records.</p></div></div></section>
    <div class="admin-privacy-note mb-4"><strong>Privacy note:</strong> Admins can see basic account metadata only. Transaction records, budgets, cart items, and receipt details are not shown here.</div>
    <div class="card content-card"><div class="card-body"><div class="table-responsive"><table class="table align-middle"><thead><tr><th>ID</th><th>Name</th><th>Email</th><th>Created</th><th>Last Login</th><th>Status</th><th>Role</th><th class="text-end">Action</th></tr></thead><tbody>${rows}</tbody></table></div></div></div>
  `));
}

function adminActivity(req, res, data, user) {
  const rows = data.adminActivityLogs.slice().sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt)).slice(0, 100).map((log) => {
    const admin = data.users.find((item) => item.id === log.adminId);
    return `<div class="xp-event"><div class="d-flex flex-column flex-md-row justify-content-between gap-2"><div><strong>${escapeHtml(log.action)}</strong><div class="text-muted-small">${escapeHtml(log.description || '')}</div></div><div class="text-md-end text-muted-small"><div>${escapeHtml(admin?.name || 'Admin')}</div><div>${historyTime(log.createdAt)}</div></div></div></div>`;
  }).join('') || `<div class="empty-state"><img class="mascot-empty-img" src="/images/mascot-guide.png" alt="Kwarta guide mascot" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><div><strong>No admin activity yet.</strong><div class="text-muted-small">Logs will appear when admin actions are performed.</div></div></div>`;
  sendHtml(res, page(req, user, 'System Activity', `
    <section class="page-header-panel"><div class="page-header-main"><span class="page-header-icon"><span class="pixel-nav-icon nav-icon-receipt" aria-hidden="true"></span></span><div class="page-header-copy"><h1 class="page-header-title">System Activity</h1><p class="page-header-subtitle">Review admin-side actions such as login events and account status updates.</p></div></div></section>
    <div class="card content-card"><div class="card-body"><h2 class="h5 fw-bold mb-3">Admin Activity Logs</h2><div class="d-grid gap-2">${rows}</div></div></div>
  `));
}

function adminProfile(req, res, data, user) {
  const activityCount = data.adminActivityLogs.filter((log) => log.adminId === user.id).length;
  sendHtml(res, page(req, user, 'Admin Profile', `
    <section class="page-header-panel"><div class="profile-header-main"><img class="profile-header-avatar" src="/images/mascot-rewards.png" alt="Kwarta admin avatar" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><div class="page-header-copy"><span class="level-pill mb-2"><i class="bi bi-shield-lock"></i> Admin Profile</span><h1 class="page-header-title">${escapeHtml(user.name)}</h1><p class="page-header-subtitle">${escapeHtml(user.email)}</p><div class="page-header-meta"><span class="page-header-chip">Role: Admin</span><span class="page-header-chip">Status: ${escapeHtml(user.status || 'active')}</span><span class="page-header-chip">${activityCount} admin actions</span></div></div></div></section>
    <div class="row g-4"><div class="col-lg-6"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Account Security</h2><div class="settings-list"><div class="settings-row"><span>Shared login page</span><strong>Enabled</strong></div><div class="settings-row"><span>Admin route guard</span><strong>Enabled</strong></div><div class="settings-row"><span>Session timeout</span><strong>30 minutes</strong></div><div class="settings-row"><span>Admin registration</span><strong>Disabled</strong></div><div class="settings-row"><span>Role changes</span><strong>Database only</strong></div></div></div></div></div><div class="col-lg-6"><div class="card content-card h-100"><div class="card-body"><h2 class="h5 fw-bold mb-3">Admin Details</h2><div class="settings-list"><div class="settings-row"><span>Joined</span><strong>${user.createdAt ? historyTime(user.createdAt) : 'Unknown'}</strong></div><div class="settings-row"><span>Last Login</span><strong>${user.lastLoginAt ? historyTime(user.lastLoginAt) : 'Never'}</strong></div><div class="settings-row"><span>Financial data access</span><strong>Aggregate only</strong></div><a class="btn btn-outline-danger" href="/logout"><i class="bi bi-box-arrow-right"></i> Logout</a></div></div></div></div></div>
  `));
}

function receiptData(req, data, user) {
  const url = new URL(req.url, `http://${req.headers.host}`);
  const nowMonth = new Date().toISOString().slice(0, 7);
  const selectedMonth = /^\d{4}-\d{2}$/.test(url.searchParams.get('month') || '') ? url.searchParams.get('month') : nowMonth;
  data.receiptLogs.push({ id: data.nextIds.receiptLog++, userId: user.id, selectedMonth, generatedAt: new Date().toISOString() });
  saveData(data);
  const tx = userTransactions(data, user.id).filter((item) => item.date.startsWith(selectedMonth));
  const expenses = tx.filter((item) => item.type === 'expense').sort((a, b) => a.date.localeCompare(b.date) || a.id - b.id);
  const totalExpenses = expenses.reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const totalIncome = tx.filter((item) => item.type === 'income').reduce((sum, item) => sum + Number(item.amount || 0), 0);
  const remainingBalance = totalIncome - totalExpenses;
  const savingsCategory = categories.find((category) => category.name.toLowerCase() === 'savings');
  const totalSavings = savingsCategory ? expenses.filter((item) => Number(item.categoryId) === savingsCategory.id).reduce((sum, item) => sum + Number(item.amount || 0), 0) : 0;
  const byCategory = categories.map((category) => ({
    name: category.name,
    total: expenses.filter((item) => Number(item.categoryId) === category.id).reduce((sum, item) => sum + Number(item.amount || 0), 0),
  })).filter((item) => item.total > 0).sort((a, b) => b.total - a.total);
  const highestCategory = byCategory.length ? `${byCategory[0].name} (${money(byCategory[0].total)})` : 'No expenses yet';
  const generatedAt = new Intl.DateTimeFormat('en-PH', { month: 'long', day: 'numeric', year: 'numeric', hour: 'numeric', minute: '2-digit' }).format(new Date()).replace(',', ' -');

  return {
    selectedMonth,
    monthText: monthLabel(selectedMonth),
    expenses,
    totalExpenses,
    totalIncome,
    remainingBalance,
    totalSavings,
    highestCategory,
    generatedAt,
  };
}

function receipt(req, res, data, user) {
  const summary = receiptData(req, data, user);
  const rows = summary.expenses.map((item, index) => `<div class="receipt-line-item"><div class="receipt-item-main"><span class="receipt-item-number">${index + 1}.</span><div><strong>${escapeHtml(categoryName(item.categoryId))}</strong><span>${escapeHtml(item.notes || 'No notes')}</span><small>${escapeHtml(dateLabel(item.date))}</small></div></div><div class="receipt-item-amount">${money(item.amount)}</div></div>`).join('') || `<div class="receipt-empty">No expenses recorded for ${escapeHtml(summary.monthText)}.</div>`;

  sendHtml(res, page(req, user, 'Monthly Receipt', `
    <section class="page-header-panel receipt-toolbar no-print"><div class="page-header-main"><span class="page-header-icon"><span class="pixel-nav-icon nav-icon-receipt" aria-hidden="true"></span></span><div class="page-header-copy"><h1 class="page-header-title">Monthly Expense Receipt</h1><p class="page-header-subtitle">Generate a receipt-style summary for a selected month.</p></div></div><form class="page-header-actions" method="get"><div><label class="form-label">Month and Year</label><input class="form-control" type="month" name="month" value="${escapeHtml(summary.selectedMonth)}"></div><button class="btn btn-success" type="submit"><i class="bi bi-receipt"></i> Generate</button><button class="btn btn-outline-secondary" type="button" onclick="window.print()"><i class="bi bi-printer"></i> Print Receipt</button><a class="btn btn-outline-primary" href="/receipt-pdf?month=${encodeURIComponent(summary.selectedMonth)}"><i class="bi bi-file-earmark-pdf"></i> Download PDF</a><a class="btn btn-outline-secondary" href="/dashboard">Back</a></form></section>
    <section class="monthly-receipt-card"><div class="receipt-brand"><img class="receipt-logo" src="/images/mascot-default.png" alt="Kwarta mascot logo" onerror="this.onerror=null;this.src='/images/mascot-default.png';"><div><div class="receipt-app-name">Kwarta</div><div class="receipt-title">Monthly Expense Summary</div></div></div><div class="receipt-divider"></div><div class="receipt-meta-grid"><div><span>Month</span><strong>${escapeHtml(summary.monthText)}</strong></div><div><span>Generated</span><strong>${escapeHtml(summary.generatedAt)}</strong></div><div><span>User</span><strong>${escapeHtml(user.name)}</strong></div><div><span>Transactions</span><strong>${summary.expenses.length}</strong></div></div><div class="receipt-divider"></div><h2 class="receipt-section-title">Expenses</h2><div class="receipt-list">${rows}</div><div class="receipt-divider"></div><div class="receipt-totals"><div><span>Total Expenses</span><strong>${money(summary.totalExpenses)}</strong></div><div><span>Total Income</span><strong>${money(summary.totalIncome)}</strong></div><div><span>Remaining Balance</span><strong>${money(summary.remainingBalance)}</strong></div><div><span>Total Savings</span><strong>${money(summary.totalSavings)}</strong></div><div><span>Highest Spending Category</span><strong>${escapeHtml(summary.highestCategory)}</strong></div></div><div class="receipt-divider"></div><p class="receipt-footer-note">Keep tracking, keep saving, and keep leveling up your Kwarta habits.</p></section>
  `));
}

function pdfEscape(value = '') {
  return String(value).normalize('NFKD').replace(/[^\x09\x0A\x0D\x20-\x7E]/g, '').replace(/[\\()]/g, '\\$&');
}

function wrapPdfLine(text, max = 78) {
  const words = String(text || '').trim().replace(/\s+/g, ' ').split(' ').filter(Boolean);
  const lines = [];
  let current = '';
  for (const word of words) {
    if (!current) current = word;
    else if (`${current} ${word}`.length <= max) current += ` ${word}`;
    else {
      lines.push(current);
      current = word;
    }
  }
  if (current) lines.push(current);
  return lines.length ? lines : [''];
}

function pdfText(x, y, text, size = 10) {
  return `BT /F1 ${size} Tf ${x} ${y} Td (${pdfEscape(text)}) Tj ET\n`;
}

function buildPdf(lines) {
  const pages = [];
  let current = [];
  let y = 760;

  for (const line of lines) {
    const size = line.size || 10;
    const x = line.x || 48;
    const space = line.space || 16;
    for (const wrapped of wrapPdfLine(line.text, line.wrap || 78)) {
      if (y < 52) {
        pages.push(current.join(''));
        current = [];
        y = 760;
      }
      current.push(pdfText(x, y, wrapped, size));
      y -= space;
    }
  }
  pages.push(current.join(''));

  const objects = new Map();
  objects.set(1, '<< /Type /Catalog /Pages 2 0 R >>');
  objects.set(3, '<< /Type /Font /Subtype /Type1 /BaseFont /Courier >>');
  const kids = [];

  pages.forEach((content, index) => {
    const pageId = 4 + (index * 2);
    const contentId = pageId + 1;
    kids.push(`${pageId} 0 R`);
    objects.set(pageId, `<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /Font << /F1 3 0 R >> >> /Contents ${contentId} 0 R >>`);
    objects.set(contentId, `<< /Length ${Buffer.byteLength(content)} >>\nstream\n${content}endstream`);
  });

  objects.set(2, `<< /Type /Pages /Kids [${kids.join(' ')}] /Count ${pages.length} >>`);
  const sorted = [...objects.entries()].sort((a, b) => a[0] - b[0]);
  const chunks = ['%PDF-1.4\n'];
  const offsets = new Map([[0, 0]]);
  let length = Buffer.byteLength(chunks[0]);

  for (const [id, object] of sorted) {
    offsets.set(id, length);
    const chunk = `${id} 0 obj\n${object}\nendobj\n`;
    chunks.push(chunk);
    length += Buffer.byteLength(chunk);
  }

  const xref = length;
  chunks.push(`xref\n0 ${sorted.length + 1}\n`);
  chunks.push('0000000000 65535 f \n');
  for (const [id] of sorted) {
    chunks.push(`${String(offsets.get(id)).padStart(10, '0')} 00000 n \n`);
  }
  chunks.push(`trailer\n<< /Size ${sorted.length + 1} /Root 1 0 R >>\nstartxref\n${xref}\n%%EOF`);
  return Buffer.from(chunks.join(''), 'ascii');
}

function receiptPdf(req, res, data, user) {
  const summary = receiptData(req, data, user);
  const lines = [
    { text: 'KWARTA', size: 20, space: 24 },
    { text: 'Monthly Expense Summary', size: 14, space: 22 },
    { text: '-'.repeat(72), space: 14 },
    { text: `Month: ${summary.monthText}` },
    { text: `Generated: ${summary.generatedAt}` },
    { text: `User: ${user.name}` },
    { text: `Transactions: ${summary.expenses.length}` },
    { text: '-'.repeat(72), space: 18 },
    { text: 'EXPENSES', size: 12, space: 18 },
  ];

  if (!summary.expenses.length) {
    lines.push({ text: `No expenses recorded for ${summary.monthText}.` });
  } else {
    summary.expenses.forEach((item, index) => {
      lines.push({
        text: `${index + 1}. ${categoryName(item.categoryId)} - ${money(item.amount)} - ${item.notes || 'No notes'} - ${dateLabel(item.date)}`,
        wrap: 74,
      });
    });
  }

  lines.push(
    { text: '-'.repeat(72), space: 18 },
    { text: `Total Expenses: ${money(summary.totalExpenses)}`, size: 12 },
    { text: `Total Income: ${money(summary.totalIncome)}`, size: 12 },
    { text: `Remaining Balance: ${money(summary.remainingBalance)}`, size: 12 },
    { text: `Total Savings: ${money(summary.totalSavings)}`, size: 12 },
    { text: `Highest Spending Category: ${summary.highestCategory}`, size: 12, wrap: 70 },
    { text: '-'.repeat(72), space: 18 },
    { text: 'Keep tracking, keep saving, and keep leveling up your Kwarta habits.', wrap: 70 },
  );

  const pdf = buildPdf(lines);
  res.writeHead(200, {
    'Content-Type': 'application/pdf',
    'Content-Length': pdf.length,
    'Content-Disposition': `attachment; filename="kwarta-monthly-receipt-${summary.selectedMonth}.pdf"`,
  });
  res.end(pdf);
}

async function route(req, res) {
  const data = loadData();
  const url = new URL(req.url, `http://${req.headers.host}`);
  const user = currentUser(req, data);

  if (url.pathname.startsWith('/assets/') || url.pathname.startsWith('/images/')) {
    const file = path.join(PUBLIC, url.pathname);
    if (!file.startsWith(PUBLIC) || !fs.existsSync(file)) return res.writeHead(404).end('Not found');
    const type = file.endsWith('.css') ? 'text/css' : file.endsWith('.png') ? 'image/png' : file.endsWith('.svg') ? 'image/svg+xml' : 'application/javascript';
    res.writeHead(200, { 'Content-Type': type });
    return fs.createReadStream(file).pipe(res);
  }

  if (url.pathname === '/') return user ? redirect(res, user.role === 'admin' ? '/admin/dashboard' : '/dashboard') : landing(req, res);
  if (url.pathname === '/login' && req.method === 'GET') {
    if (user) return redirect(res, user.role === 'admin' ? '/admin/dashboard' : '/dashboard');
    return sendHtml(res, page(req, null, 'Login', authCard('Welcome back to Kwarta', 'Sign in to view your dashboard.', '<div class="mb-3"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required></div><div class="mb-4"><label class="form-label">Password</label><input class="form-control" type="password" name="password" required></div>', '/login', 'New to Kwarta? <a href="/register">Create an account</a>')));
  }
  if (url.pathname === '/register' && req.method === 'GET') {
    if (user) return redirect(res, user.role === 'admin' ? '/admin/dashboard' : '/dashboard');
    return sendHtml(res, page(req, null, 'Register', authCard('Create your Kwarta account', 'Track your money with simple tools.', '<div class="mb-3"><label class="form-label">Name</label><input class="form-control" name="name" required></div><div class="mb-3"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required></div><div class="mb-4"><label class="form-label">Password</label><input class="form-control" type="password" minlength="8" required></div>', '/register', 'Already have an account? <a href="/login">Log in</a>')));
  }
  if (url.pathname === '/logout') {
    const sid = parseCookies(req).kwarta_sid;
    sessions.delete(sid);
    res.writeHead(302, { Location: '/', 'Set-Cookie': 'kwarta_sid=; Max-Age=0; Path=/; HttpOnly' });
    return res.end();
  }

  if (url.pathname === '/register' && req.method === 'POST') {
    if (user) return redirect(res, user.role === 'admin' ? '/admin/dashboard' : '/dashboard');
    const form = await body(req);
    if (data.users.some((existing) => existing.email === String(form.email).toLowerCase())) return redirect(res, '/login');
    if (!isStrongPassword(form.password)) return redirect(res, '/register');
    const newUser = { id: data.nextIds.user++, name: form.name, email: String(form.email).toLowerCase(), passwordHash: hashPassword(form.password), currentMoney: 0, role: 'user', status: 'active', createdAt: new Date().toISOString(), lastLoginAt: null };
    data.users.push(newUser);
    saveData(data);
    return redirect(res, '/login');
  }

  if (url.pathname === '/login' && req.method === 'POST') {
    if (user) return redirect(res, user.role === 'admin' ? '/admin/dashboard' : '/dashboard');
    const form = await body(req);
    const found = data.users.find((item) => item.email === String(form.email).toLowerCase());
    if (!found || !verifyPassword(form.password, found.passwordHash)) return redirect(res, '/login');
    if ((found.status || 'active') !== 'active') return redirect(res, '/login');
    found.lastLoginAt = new Date().toISOString();
    const role = found.role || 'user';
    const keepLogin = role === 'admin' ? false : (form.keepLogin === '1' || form.keepLogin === 'on');
    const sid = crypto.randomBytes(24).toString('hex');
    sessions.set(sid, { userId: found.id, keepLogin, role, lastActivityAt: Date.now() });
    const maxAge = keepLogin ? '; Max-Age=2592000' : '';
    if (role === 'admin') {
      logAdminActivity(data, found.id, 'Admin login', 'Admin signed in through the shared login page.');
    }
    saveData(data);
    const location = role === 'admin' ? '/admin/dashboard?fresh=1' : (keepLogin ? '/dashboard' : '/dashboard?fresh=1');
    res.writeHead(302, { Location: location, 'Set-Cookie': `kwarta_sid=${sid}; Path=/; HttpOnly; SameSite=Lax${maxAge}` });
    return res.end();
  }

  if (url.pathname.startsWith('/admin')) {
    const adminUser = requireAdmin(req, res, data);
    if (!adminUser) return;
    if (url.pathname === '/admin/dashboard') return adminDashboard(req, res, data, adminUser);
    if (url.pathname === '/admin/stats') return adminStats(req, res, data, adminUser);
    if (url.pathname === '/admin/activity') return adminActivity(req, res, data, adminUser);
    if (url.pathname === '/admin/profile') return adminProfile(req, res, data, adminUser);
    if (url.pathname === '/admin/users' && req.method === 'GET') return adminUsers(req, res, data, adminUser);
    if (url.pathname === '/admin/users' && req.method === 'POST') {
      const form = await body(req);
      const target = data.users.find((item) => item.id === Number(form.userId));
      const status = String(form.status || '');
      if (target && target.role !== 'admin' && ['active', 'inactive'].includes(status)) {
        target.status = status;
        logAdminActivity(data, adminUser.id, 'User status updated', `User ID ${target.id} set to ${status}.`);
        saveData(data);
      }
      return redirect(res, '/admin/users');
    }
    return redirect(res, '/admin/dashboard');
  }

  const protectedUser = requireUser(req, res, data);
  if (!protectedUser) return;

  if (url.pathname === '/dashboard') return dashboard(req, res, data, protectedUser);
  if (url.pathname === '/wallet/update' && req.method === 'POST') {
    const form = await body(req);
    const amount = Number(form.currentMoney);
    const found = data.users.find((item) => item.id === protectedUser.id);
    if (found && Number.isFinite(amount) && amount >= 0) {
      found.currentMoney = amount;
      saveData(data);
    }
    return redirect(res, '/dashboard');
  }
  if (url.pathname === '/transactions') return transactions(req, res, data, protectedUser);
  if (url.pathname === '/import') return redirect(res, '/transactions');
  if (url.pathname === '/transaction/new' && req.method === 'GET') return transactionForm(req, res, protectedUser);
  if (url.pathname === '/transaction/edit' && req.method === 'GET') {
    const item = data.transactions.find((tx) => tx.id === Number(url.searchParams.get('id')) && tx.userId === protectedUser.id);
    return item ? transactionForm(req, res, protectedUser, item) : redirect(res, '/transactions');
  }
  if ((url.pathname === '/transaction/new' || url.pathname === '/transaction/edit') && req.method === 'POST') {
    const form = await body(req);
    const amountRaw = String(form.amount || '').trim();
    const amount = Number(amountRaw);
    const item = { userId: protectedUser.id, categoryId: Number(form.categoryId), type: form.type, amount: amountRaw, date: form.date, notes: form.notes || '' };
    const errors = [];
    if (amountRaw === '') errors.push('Amount is required.');
    else if (!Number.isFinite(amount) || amount <= 0) errors.push('Amount must be greater than zero.');
    if (!['income', 'expense'].includes(item.type)) errors.push('Please choose income or expense.');
    if (!item.categoryId) errors.push('Please choose a category.');
    if (!/^\d{4}-\d{2}-\d{2}$/.test(String(item.date || ''))) errors.push('Please enter a valid transaction date.');
    if (errors.length) return transactionForm(req, res, protectedUser, item, errors);
    item.amount = amount;
    if (url.pathname === '/transaction/edit') {
      const existing = data.transactions.find((tx) => tx.id === Number(url.searchParams.get('id')) && tx.userId === protectedUser.id);
      if (existing) Object.assign(existing, item);
    } else {
      data.transactions.push({ id: data.nextIds.transaction++, ...item });
    }
    saveData(data);
    return redirect(res, '/transactions');
  }
  if (url.pathname === '/transaction/delete' && req.method === 'POST') {
    const form = await body(req);
    data.transactions = data.transactions.filter((tx) => !(tx.id === Number(form.id) && tx.userId === protectedUser.id));
    saveData(data);
    return redirect(res, '/transactions');
  }
  if (url.pathname === '/budgets' && req.method === 'GET') return budgets(req, res, data, protectedUser);
  if (url.pathname === '/budgets' && req.method === 'POST') {
    const form = await body(req);
    const month = new Date().toISOString().slice(0, 7);
    const existing = data.budgets.find((budget) => budget.userId === protectedUser.id && budget.categoryId === Number(form.categoryId) && budget.month === month);
    if (existing) existing.amount = Number(form.amount);
    else data.budgets.push({ id: data.nextIds.budget++, userId: protectedUser.id, categoryId: Number(form.categoryId), month, amount: Number(form.amount) });
    saveData(data);
    return redirect(res, '/budgets');
  }
  if (url.pathname === '/savings' && req.method === 'GET') return savings(req, res, data, protectedUser);
  if (url.pathname === '/savings' && req.method === 'POST') {
    const form = await body(req);
    const targetAmount = Number(form.targetAmount);
    const savedAmount = Number(form.savedAmount || 0);
    const name = String(form.name || '').trim();
    const description = String(form.description || '').trim();
    const targetMonth = String(form.targetMonth || '').trim();
    if (!name || name.length > 120 || description.length > 255 || !Number.isFinite(targetAmount) || targetAmount <= 0 || !Number.isFinite(savedAmount) || savedAmount < 0 || (targetMonth && !/^\d{4}-\d{2}$/.test(targetMonth))) {
      return redirect(res, '/savings');
    }
    const goal = { id: data.nextIds.goal++, userId: protectedUser.id, name, description, targetAmount, savedAmount, targetMonth, isBought: false, boughtAt: null };
    data.savingsGoals.push(goal);
    logSavingsHistory(data, goal.id, 'goal_created', goal.savedAmount, 0, goal.savedAmount, 'Added item to cart');
    saveData(data);
    return redirect(res, `/savings#goal-${goal.id}`);
  }
  if (url.pathname === '/savings/update' && req.method === 'POST') {
    const form = await body(req);
    const goal = data.savingsGoals.find((item) => item.id === Number(form.id) && item.userId === protectedUser.id);
    if (goal) {
      const previousTarget = Number(goal.targetAmount || 0);
      const previousSaved = Number(goal.savedAmount || 0);
      const nextTarget = Number(form.targetAmount);
      const nextSaved = Number(form.savedAmount || 0);
      const nextName = String(form.name || '').trim() || goal.name;
      const nextDescription = String(form.description || '').trim();
      const nextTargetMonth = String(form.targetMonth || '').trim();
      if (nextName.length > 120 || nextDescription.length > 255 || !Number.isFinite(nextTarget) || nextTarget <= 0 || !Number.isFinite(nextSaved) || nextSaved < 0 || (nextTargetMonth && !/^\d{4}-\d{2}$/.test(nextTargetMonth))) {
        return redirect(res, `/savings#goal-${goal.id}`);
      }
      const nameChanged = nextName !== goal.name;
      const descriptionChanged = nextDescription !== String(goal.description || '');
      const monthChanged = nextTargetMonth !== String(goal.targetMonth || '');
      if (nextTarget !== previousTarget) {
        logSavingsHistory(data, goal.id, 'target_updated', nextTarget - previousTarget, previousTarget, nextTarget, `Updated item price from ${money(previousTarget)} to ${money(nextTarget)}`);
      }
      if (nextSaved !== previousSaved) {
        logSavingsHistory(data, goal.id, 'saved_updated', Math.abs(nextSaved - previousSaved), previousSaved, nextSaved, `${nextSaved > previousSaved ? 'Increased' : 'Decreased'} saved amount from ${money(previousSaved)} to ${money(nextSaved)}`);
      }
      if (nameChanged || descriptionChanged || monthChanged) {
        const note = monthChanged ? `Updated target month to ${monthLabel(nextTargetMonth) || 'Not set'}` : 'Updated item details';
        logSavingsHistory(data, goal.id, 'goal_edited', null, nextSaved, nextSaved, note);
      }
      Object.assign(goal, { name: nextName, description: nextDescription, targetAmount: nextTarget, savedAmount: nextSaved, targetMonth: nextTargetMonth });
    }
    saveData(data);
    return redirect(res, `/savings#goal-${form.id}`);
  }
  if (url.pathname === '/savings/adjust' && req.method === 'POST') {
    const form = await body(req);
    const goal = data.savingsGoals.find((item) => item.id === Number(form.id) && item.userId === protectedUser.id);
    const amount = Number(form.amountChange);
    if (goal && Number.isFinite(amount) && amount > 0) {
      const previousSaved = Number(goal.savedAmount || 0);
      const direction = form.direction === 'decrease' ? 'decrease' : 'increase';
      if (direction === 'decrease' && amount > previousSaved) {
        return redirect(res, `/savings#goal-${goal.id}`);
      }
      const nextSaved = direction === 'increase' ? previousSaved + amount : previousSaved - amount;
      goal.savedAmount = nextSaved;
      logSavingsHistory(data, goal.id, direction, amount, previousSaved, nextSaved, `${direction === 'increase' ? 'Increased' : 'Decreased'} saved amount by ${money(amount)}`);
      saveData(data);
      return redirect(res, `/savings#goal-${goal.id}`);
    }
    return redirect(res, '/savings');
  }
  if (url.pathname === '/savings/bought' && req.method === 'POST') {
    const form = await body(req);
    const goal = data.savingsGoals.find((item) => item.id === Number(form.id) && item.userId === protectedUser.id);
    if (goal) {
      const nextStatus = !Boolean(goal.isBought);
      goal.isBought = nextStatus;
      goal.boughtAt = nextStatus ? new Date().toISOString() : null;
      logSavingsHistory(
        data,
        goal.id,
        nextStatus ? 'item_bought' : 'item_unbought',
        null,
        Number(goal.savedAmount || 0),
        Number(goal.savedAmount || 0),
        nextStatus ? 'Marked item as already bought' : 'Removed already bought status',
      );
      saveData(data);
      return redirect(res, `/savings#goal-${goal.id}`);
    }
    return redirect(res, '/savings');
  }
  if (url.pathname === '/savings/delete' && req.method === 'POST') {
    const form = await body(req);
    const id = Number(form.id);
    data.savingsGoals = data.savingsGoals.filter((goal) => !(goal.id === id && goal.userId === protectedUser.id));
    data.savingsHistories = data.savingsHistories.filter((history) => history.goalId !== id);
    saveData(data);
    return redirect(res, '/savings');
  }
  if (url.pathname === '/savings/history/delete' && req.method === 'POST') {
    const form = await body(req);
    const goalId = Number(form.goalId);
    const historyId = Number(form.historyId);
    const goal = data.savingsGoals.find((item) => item.id === goalId && item.userId === protectedUser.id);
    if (goal) {
      data.savingsHistories = data.savingsHistories.filter((history) => !(history.id === historyId && history.goalId === goalId));
      saveData(data);
    }
    return redirect(res, `/savings#goal-${goalId}`);
  }
  if (url.pathname === '/reports') return redirect(res, '/receipt');
  if (url.pathname === '/receipt') return receipt(req, res, data, protectedUser);
  if (url.pathname === '/receipt-pdf') return receiptPdf(req, res, data, protectedUser);
  if (url.pathname === '/gamification') return gamification(req, res, data, protectedUser);
  if (url.pathname === '/profile' && req.method === 'POST') {
    const form = await body(req);
    const found = data.users.find((item) => item.id === protectedUser.id);
    const errors = [];

    if (form.action === 'update_profile') {
      const name = String(form.name || '').trim();
      if (!name) errors.push('Display name is required.');
      if (name.length > 120) errors.push('Display name must be 120 characters or fewer.');
      if (errors.length) return profile(req, res, data, protectedUser, errors);
      if (found) {
        found.name = name;
        saveData(data);
      }
      return redirect(res, '/profile');
    }

    if (form.action === 'change_password') {
      if (!found || !verifyPassword(String(form.currentPassword || ''), found.passwordHash)) errors.push('Current password is incorrect.');
      if (String(form.newPassword || '').length < 8) errors.push('New password must be at least 8 characters.');
      if (String(form.newPassword || '') !== String(form.confirmPassword || '')) errors.push('New password and confirmation do not match.');
      if (errors.length) return profile(req, res, data, protectedUser, errors);
      found.passwordHash = hashPassword(String(form.newPassword));
      saveData(data);
      return redirect(res, '/profile');
    }

    return redirect(res, '/profile');
  }
  if (url.pathname === '/profile') return profile(req, res, data, protectedUser);

  res.writeHead(404, { 'Content-Type': 'text/plain' });
  res.end('Not found');
}

http.createServer((req, res) => {
  route(req, res).catch((error) => {
    console.error(error);
    res.writeHead(500, { 'Content-Type': 'text/plain' });
    res.end('Kwarta local runner error.');
  });
}).listen(PORT, () => {
  ensureData();
  fs.appendFileSync(LOG_FILE, `Kwarta local runner is open at http://localhost:${PORT}\n`);
});

process.stdout.on('error', () => {});
process.stderr.on('error', () => {});
