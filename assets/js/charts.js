// Chart.js Configuration and Helpers

// Common chart colors
const chartColors = {
    primary: '#0d6efd',
    success: '#198754',
    danger: '#dc3545',
    warning: '#ffc107',
    info: '#0dcaf0',
    dark: '#212529',
    light: '#f8f9fa',
    purple: '#6f42c1',
    pink: '#d63384',
    orange: '#fd7e14',
    teal: '#20c997'
};

// Create Pie Chart
function createPieChart(canvasId, labels, data, colors = null) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    const defaultColors = Object.values(chartColors);
    
    return new Chart(ctx, {
        type: 'pie',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors || defaultColors.slice(0, data.length),
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom',
                    labels: {
                        font: {
                            size: 12
                        }
                    }
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

// Create Bar Chart
function createBarChart(canvasId, labels, datasets, options = {}) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            ...options
        }
    });
}

// Create Line Chart
function createLineChart(canvasId, labels, datasets, options = {}) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    return new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels,
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'top',
                },
                tooltip: {
                    mode: 'index',
                    intersect: false
                }
            },
            scales: {
                y: {
                    beginAtZero: true,
                    grid: {
                        drawBorder: false
                    }
                },
                x: {
                    grid: {
                        display: false
                    }
                }
            },
            ...options
        }
    });
}

// Create Doughnut Chart
function createDoughnutChart(canvasId, labels, data, colors = null) {
    const ctx = document.getElementById(canvasId).getContext('2d');
    const defaultColors = Object.values(chartColors);
    
    return new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: labels,
            datasets: [{
                data: data,
                backgroundColor: colors || defaultColors.slice(0, data.length),
                borderWidth: 2,
                borderColor: '#fff'
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                legend: {
                    position: 'bottom'
                },
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            const label = context.label || '';
                            const value = context.parsed;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return `${label}: ${value} (${percentage}%)`;
                        }
                    }
                }
            }
        }
    });
}

// Create Gantt Chart (simplified using Chart.js)
function createGanttChart(canvasId, tasks) {
    // This is a simplified Gantt chart using horizontal bars
    const ctx = document.getElementById(canvasId).getContext('2d');
    
    const labels = tasks.map(t => t.name);
    const startDates = tasks.map(t => new Date(t.start_date));
    const durations = tasks.map(t => t.duration);
    
    // For a more complex Gantt chart, consider using a specialized library
    // This is a placeholder for basic visualization
    return new Chart(ctx, {
        type: 'bar',
        data: {
            labels: labels,
            datasets: [{
                label: 'Task Duration',
                data: durations,
                backgroundColor: chartColors.primary,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: true,
            plugins: {
                tooltip: {
                    callbacks: {
                        label: function(context) {
                            return `Duration: ${context.raw} days`;
                        }
                    }
                }
            },
            scales: {
                x: {
                    title: {
                        display: true,
                        text: 'Days'
                    }
                }
            }
        }
    });
}

// Update chart with new data
function updateChart(chart, newData) {
    if (!chart) return;
    
    chart.data.datasets.forEach((dataset, index) => {
        if (newData.datasets && newData.datasets[index]) {
            dataset.data = newData.datasets[index].data;
        }
    });
    
    if (newData.labels) {
        chart.data.labels = newData.labels;
    }
    
    chart.update();
}

// Create project progress chart
function createProjectProgressChart(canvasId, projects) {
    const labels = projects.map(p => p.name);
    const progress = projects.map(p => p.progress);
    const statusColors = projects.map(p => {
        if (p.progress >= 80) return chartColors.success;
        if (p.progress >= 50) return chartColors.warning;
        if (p.progress >= 20) return chartColors.info;
        return chartColors.danger;
    });
    
    return createBarChart(canvasId, labels, [{
        label: 'Progress (%)',
        data: progress,
        backgroundColor: statusColors,
        borderRadius: 4
    }], {
        scales: {
            y: {
                min: 0,
                max: 100,
                title: {
                    display: true,
                    text: 'Progress (%)'
                }
            }
        }
    });
}

// Create resource utilization chart
function createResourceUtilizationChart(canvasId, resources) {
    const labels = resources.map(r => r.name);
    const allocated = resources.map(r => r.allocated);
    const available = resources.map(r => r.available);
    
    return createBarChart(canvasId, labels, [
        {
            label: 'Allocated',
            data: allocated,
            backgroundColor: chartColors.primary,
            borderRadius: 4
        },
        {
            label: 'Available',
            data: available,
            backgroundColor: chartColors.success,
            borderRadius: 4
        }
    ], {
        scales: {
            y: {
                stacked: false,
                title: {
                    display: true,
                    text: 'Hours'
                }
            }
        }
    });
}

// Create cost breakdown chart
function createCostBreakdownChart(canvasId, costs) {
    const labels = Object.keys(costs);
    const data = Object.values(costs);
    
    return createPieChart(canvasId, labels, data);
}

// Create team performance chart
function createTeamPerformanceChart(canvasId, performance) {
    const labels = performance.map(p => p.name);
    const completed = performance.map(p => p.completed);
    const inProgress = performance.map(p => p.in_progress);
    
    return createBarChart(canvasId, labels, [
        {
            label: 'Completed',
            data: completed,
            backgroundColor: chartColors.success,
            borderRadius: 4
        },
        {
            label: 'In Progress',
            data: inProgress,
            backgroundColor: chartColors.warning,
            borderRadius: 4
        }
    ], {
        scales: {
            x: {
                stacked: true
            },
            y: {
                stacked: true,
                title: {
                    display: true,
                    text: 'Number of Tasks'
                }
            }
        }
    });
}

// Create timeline chart
function createTimelineChart(canvasId, events) {
    const labels = events.map(e => e.date);
    const values = events.map(e => e.value);
    
    return createLineChart(canvasId, labels, [{
        label: 'Events',
        data: values,
        borderColor: chartColors.primary,
        backgroundColor: 'rgba(13, 110, 253, 0.1)',
        fill: true,
        tension: 0.4
    }]);
}

// Export chart as image
function exportChartAsImage(chartId, filename = 'chart.png') {
    const canvas = document.getElementById(chartId);
    if (!canvas) return;
    
    const link = document.createElement('a');
    link.download = filename;
    link.href = canvas.toDataURL();
    link.click();
}

// Print chart
function printChart(chartId) {
    const canvas = document.getElementById(chartId);
    if (!canvas) return;
    
    const printWindow = window.open('', '_blank');
    printWindow.document.write(`
        <html>
            <head>
                <title>Chart Print</title>
                <style>
                    body { text-align: center; padding: 20px; }
                    img { max-width: 100%; height: auto; }
                </style>
            </head>
            <body>
                <img src="${canvas.toDataURL()}">
                <script>window.print();<\/script>
            </body>
        </html>
    `);
    printWindow.document.close();
}