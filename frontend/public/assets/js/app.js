function renderDoughnutChart(canvasId, labels, values) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || typeof Chart === 'undefined') return;

    new Chart(canvas, {
        type: 'doughnut',
        data: {
            labels,
            datasets: [{
                data: values,
                backgroundColor: ['#0f766e', '#f59e0b', '#2563eb', '#dc2626', '#7c3aed', '#0891b2', '#64748b'],
            }],
        },
        options: {
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'bottom' },
            },
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
                { label: 'Income', data: income, backgroundColor: '#0f766e' },
                { label: 'Expenses', data: expenses, backgroundColor: '#dc2626' },
            ],
        },
        options: {
            maintainAspectRatio: false,
            responsive: true,
            scales: {
                y: { beginAtZero: true },
            },
        },
    });
}
