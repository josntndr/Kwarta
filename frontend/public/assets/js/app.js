// Kwarta Frontend Interactive JS Engine

function renderDoughnutChart(canvasId, labels, values) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;

    // Premium pixel-theme financial color palette
    const kwartaColors = [
        '#2E8B57', // Success Mint Green
        '#FCD116', // Gold / Secondary
        '#0038A8', // Accent Blue
        '#CE1126', // Danger Red
        '#5F3CA0', // Violet
        '#0891B2', // Cyan
        '#E67E22', // Orange
        '#72583F'  // Wood Brown
    ];

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: kwartaColors.slice(0, values.length),
                borderColor: '#6B3F1D',
                borderWidth: 2,
                hoverOffset: 6
            }],
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: { family: 'inherit', weight: 'bold', size: 12 },
                        color: '#1E1E1E',
                        padding: 14,
                        usePointStyle: true,
                        pointStyle: 'rectRounded'
                    }
                },
                tooltip: {
                    backgroundColor: '#0F3D2E',
                    titleColor: '#FCD116',
                    bodyColor: '#FFF8E7',
                    borderColor: '#6B3F1D',
                    borderWidth: 2,
                    padding: 10,
                    boxPadding: 6,
                    usePointStyle: true,
                    callbacks: {
                        label: function(context) {
                            const val = context.parsed || 0;
                            return ` ${context.label}: PHP ${val.toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
                        }
                    }
                }
            },
            cutout: '62%'
        },
    });
}

function renderBarChart(canvasId, labels, income, expenses) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;

    new Chart(canvas, {
        type: 'bar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Income',
                    data: income,
                    backgroundColor: '#2E8B57',
                    borderColor: '#0F3D2E',
                    borderWidth: 2,
                    borderRadius: 4
                },
                {
                    label: 'Expenses',
                    data: expenses,
                    backgroundColor: '#CE1126',
                    borderColor: '#6B3F1D',
                    borderWidth: 2,
                    borderRadius: 4
                },
            ],
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            plugins: {
                legend: {
                    position: 'top',
                    labels: {
                        font: { weight: 'bold', size: 12 },
                        color: '#1E1E1E'
                    }
                },
                tooltip: {
                    backgroundColor: '#0F3D2E',
                    titleColor: '#FCD116',
                    bodyColor: '#FFF8E7',
                    borderColor: '#6B3F1D',
                    borderWidth: 2,
                    padding: 10,
                    callbacks: {
                        label: function(context) {
                            return ` ${context.dataset.label}: PHP ${(context.parsed.y || 0).toLocaleString('en-PH', { minimumFractionDigits: 2 })}`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: { display: false },
                    ticks: { font: { weight: 'bold' }, color: '#1E1E1E' }
                },
                y: {
                    beginAtZero: true,
                    grid: { color: 'rgba(107, 63, 29, 0.12)' },
                    ticks: {
                        font: { weight: 'bold' },
                        color: '#72583F',
                        callback: function(val) { return 'PHP ' + val.toLocaleString(); }
                    }
                },
            },
        },
    });
}

/**
 * Kwarta Toast Notification Launcher
 * Displays dynamic feedback toasts for actions like updates, budget alerts, and cart items
 */
function showKwartaToast(message, type = 'info') {
    let container = document.querySelector('.kwarta-toast-container');
    if (!container) {
        container = document.createElement('div');
        container.className = 'kwarta-toast-container';
        document.body.appendChild(container);
    }

    const toast = document.createElement('div');
    toast.className = `kwarta-toast kwarta-toast-${type}`;
    
    const icon = type === 'success' ? 'bi-check-circle-fill' : type === 'danger' ? 'bi-exclamation-triangle-fill' : 'bi-info-circle-fill';
    toast.innerHTML = `<i class="bi ${icon}"></i> <span>${message}</span>`;
    
    container.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateY(-10px)';
        toast.style.transition = 'all 300ms ease';
        setTimeout(() => toast.remove(), 300);
    }, 3500);
}

/**
 * Instant Client-Side Table Filter / Search
 */
function initTableSearch(inputId, tableId) {
    const input = document.getElementById(inputId);
    const table = document.getElementById(tableId);
    if (!input || !table) return;

    input.addEventListener('input', function () {
        const query = this.value.toLowerCase().trim();
        const rows = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const text = row.textContent.toLowerCase();
            row.style.display = text.includes(query) ? '' : 'none';
        });
    });
}

// Auto-initialize UI listeners on DOM Load
document.addEventListener('DOMContentLoaded', function () {
    // Tooltip initializations if bootstrap exists
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = document.querySelectorAll('[data-bs-toggle="tooltip"]');
        tooltipTriggerList.forEach(el => new bootstrap.Tooltip(el));
    }
});
