/**
 * Zeal EPAP - Data-Driven Analytics & Visualization Engine (Chart.js)
 */

const chartInstances = {};

function destroyChart(id) {
    if (chartInstances[id]) {
        chartInstances[id].destroy();
        delete chartInstances[id];
    }
}

function toggleChartEmpty(canvasId, isEmpty, message = 'Not enough data yet') {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !canvas.parentElement) return false;
    const parent = canvas.parentElement;
    let emptyEl = parent.querySelector('.epap-chart-empty');
    if (isEmpty) {
        canvas.classList.add('d-none');
        if (!emptyEl) {
            emptyEl = document.createElement('div');
            emptyEl.className = 'epap-chart-empty d-flex flex-column align-items-center justify-content-center h-100 text-muted small py-4';
            parent.appendChild(emptyEl);
        }
        emptyEl.innerHTML = `<i class="bi bi-bar-chart-line fs-3 mb-2 opacity-50"></i><span>${window.escapeHtml ? window.escapeHtml(message) : message}</span>`;
        emptyEl.classList.remove('d-none');
        return true;
    }
    canvas.classList.remove('d-none');
    if (emptyEl) emptyEl.classList.add('d-none');
    return false;
}

// 1. Student Academic Performance Line Chart (Semester-wise SGPA)
function renderSemesterProgressChart(canvasId, semesters = []) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    destroyChart(canvasId);
    if (toggleChartEmpty(canvasId, !semesters || semesters.length === 0, 'Not enough published semester data yet.')) return;

    const labels = semesters.length ? semesters.map(s => s.label) : ['No Data'];
    const stuData = semesters.length ? semesters.map(s => s.student_sgpa) : [0];
    const deptData = semesters.length ? semesters.map(s => s.dept_avg) : [0];

    chartInstances[canvasId] = new Chart(ctx, {
        type: 'line',
        data: {
            labels,
            datasets: [
                {
                    label: 'Student SGPA',
                    data: stuData,
                    borderColor: '#4f46e5',
                    backgroundColor: 'rgba(79, 70, 229, 0.1)',
                    borderWidth: 3,
                    fill: true,
                    tension: 0.35,
                    pointBackgroundColor: '#4f46e5',
                    pointRadius: 5,
                },
                {
                    label: 'IT Dept Average SGPA',
                    data: deptData,
                    borderColor: '#94a3b8',
                    borderWidth: 2,
                    borderDash: [5, 5],
                    pointRadius: 3,
                    tension: 0.35,
                    fill: false,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { position: 'top' },
                tooltip: {
                    callbacks: {
                        label: (c) => `${c.dataset.label}: ${c.raw} SGPA`
                    }
                }
            },
            scales: {
                y: { min: 4, max: 10, title: { display: true, text: 'SGPA (Scale 10.0)' } }
            }
        }
    });
}

// 2. Timely vs Late Submissions Doughnut Chart
function renderSubmissionTimelinessChart(canvasId, timeliness = { on_time: 0, late: 0, pending: 0 }) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    destroyChart(canvasId);
    const total = (timeliness.on_time || 0) + (timeliness.late || 0) + (timeliness.pending || 0);
    if (toggleChartEmpty(canvasId, total === 0, 'No activity submission data yet.')) return;

    chartInstances[canvasId] = new Chart(ctx, {
        type: 'doughnut',
        data: {
            labels: ['On-Time Submissions', 'Late Submissions', 'Pending/Overdue'],
            datasets: [{
                data: [timeliness.on_time || 0, timeliness.late || 0, timeliness.pending || 0],
                backgroundColor: ['#10b981', '#f59e0b', '#ef4444'],
                borderWidth: 2,
                borderColor: '#ffffff',
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '70%',
            plugins: { legend: { position: 'bottom' } }
        }
    });
}

// 3. Subject-wise Competency Radar Chart
function renderSubjectRadarChart(canvasId, subjects = []) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    destroyChart(canvasId);
    if (toggleChartEmpty(canvasId, !subjects || subjects.length === 0, 'Not enough subject marks yet.')) return;

    const labels = subjects.length ? subjects.map(s => `${s.code}`) : ['No Data'];
    const stuScores = subjects.length ? subjects.map(s => s.student_pct) : [0];
    const benchScores = subjects.length ? subjects.map(s => s.class_pct) : [0];

    chartInstances[canvasId] = new Chart(ctx, {
        type: 'radar',
        data: {
            labels,
            datasets: [
                {
                    label: 'Student Score %',
                    data: stuScores,
                    backgroundColor: 'rgba(79, 70, 229, 0.2)',
                    borderColor: '#4f46e5',
                    pointBackgroundColor: '#4f46e5',
                    borderWidth: 2,
                },
                {
                    label: 'Class Benchmark %',
                    data: benchScores,
                    backgroundColor: 'rgba(148, 163, 184, 0.2)',
                    borderColor: '#94a3b8',
                    borderWidth: 1.5,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: {
                r: {
                    angleLines: { color: '#e2e8f0' },
                    grid: { color: '#e2e8f0' },
                    suggestedMin: 40,
                    suggestedMax: 100,
                }
            }
        }
    });
}

// 4. Explainable Predictive Analytics Forecast Chart
function renderPredictiveForecastChart(canvasId, semesters = [], prediction = null) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    destroyChart(canvasId);
    if (toggleChartEmpty(canvasId, !semesters || semesters.length === 0, 'Not enough historical semester data yet.')) return;

    const labels = semesters.map(s => s.label);
    const actualData = semesters.map(s => s.student_sgpa);
    const forecastData = new Array(semesters.length).fill(null);

    if (prediction && prediction.enough_data && semesters.length > 0) {
        labels.push(`Sem ${prediction.next_semester} (Predicted)`);
        forecastData[semesters.length - 1] = actualData[actualData.length - 1];
        forecastData.push(prediction.predicted_sgpa);
        actualData.push(null);
    }

    chartInstances[canvasId] = new Chart(ctx, {
        type: 'line',
        data: {
            labels: labels.length ? labels : ['No Data'],
            datasets: [
                {
                    label: 'Historical Actual SGPA',
                    data: actualData.length ? actualData : [0],
                    borderColor: '#002147',
                    backgroundColor: '#002147',
                    borderWidth: 3,
                    pointRadius: 6,
                    tension: 0.2,
                },
                {
                    label: 'Predictive Forecast',
                    data: forecastData,
                    borderColor: '#10b981',
                    borderDash: [6, 6],
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 2.5,
                    pointRadius: 6,
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: { legend: { position: 'top' } },
            scales: { y: { min: 5.0, max: 10.0 } }
        }
    });
}

// 5. Faculty Class Performance Bar Chart
function renderClassPerformanceBar(canvasId, activities = []) {
    const ctx = document.getElementById(canvasId);
    if (!ctx) return;
    destroyChart(canvasId);
    if (toggleChartEmpty(canvasId, !activities || activities.length === 0, 'No graded activities yet.')) return;

    const labels = activities.length ? activities.map(a => `${a.subject_code}: ${a.title.slice(0, 22)}`) : ['No Graded Activities'];
    const data = activities.length ? activities.map(a => a.avg_pct || 0) : [0];

    chartInstances[canvasId] = new Chart(ctx, {
        type: 'bar',
        data: {
            labels,
            datasets: [{
                label: 'Average Score (%)',
                data,
                backgroundColor: '#4f46e5',
                borderRadius: 6,
            }]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            scales: { y: { min: 0, max: 100 } }
        }
    });
}
