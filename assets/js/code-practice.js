/**
 * Zeal EPAP - Database-Driven Coding Practice Arena
 */

let currentTrack = 'dsa';
let currentDifficulty = '';
let currentSearch = '';
let problemsList = [];
let activeQuestion = null;
let currentLanguage = 'python';

async function initCodingArena() {
    const me = await EPAP_API.epapRequireAuth('student');
    if (!me) return;
    EPAP_API.updateNavbarUser(me);

    const subjSelect = document.getElementById('codingSubjectSelect');
    if (subjSelect) {
        subjSelect.addEventListener('change', (e) => {
            currentTrack = e.target.value;
            fetchProblems();
        });
    }

    const searchInput = document.querySelector('.problem-list-pane input[type="text"]');
    if (searchInput) {
        searchInput.addEventListener('input', (e) => {
            currentSearch = e.target.value.trim();
            fetchProblems();
        });
    }

    document.querySelectorAll('.problem-list-pane .btn-sm').forEach(btn => {
        btn.addEventListener('click', () => {
            document.querySelectorAll('.problem-list-pane .btn-sm').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            const label = btn.textContent.trim();
            currentDifficulty = label === 'All' ? '' : (label === 'Med' ? 'Medium' : label);
            fetchProblems();
        });
    });

    const langSelect = document.getElementById('codeLanguageSelect');
    if (langSelect) {
        langSelect.addEventListener('change', (e) => {
            currentLanguage = e.target.value;
            const editor = document.getElementById('codeEditorArea');
            if (editor && activeQuestion && activeQuestion.starter_code) {
                editor.value = activeQuestion.starter_code[currentLanguage] || '';
            }
        });
    }

    const runBtn = document.getElementById('runCodeBtn');
    if (runBtn) runBtn.addEventListener('click', runCode);

    const submitBtn = document.getElementById('submitCodeBtn');
    if (submitBtn) submitBtn.addEventListener('click', submitSolution);

    await fetchProblems();
}

async function fetchProblems() {
    const listContainer = document.getElementById('questionListItems');
    if (listContainer) {
        listContainer.innerHTML = '<div class="p-3 text-center text-muted small"><span class="spinner-border spinner-border-sm me-1"></span>Loading problems...</div>';
    }
    try {
        const params = new URLSearchParams({ track: currentTrack });
        if (currentDifficulty) params.set('difficulty', currentDifficulty);
        if (currentSearch) params.set('q', currentSearch);

        const res = await EPAP_API.epapFetch('/api/practice/problems?' + params.toString());
        const data = await res.json();
        problemsList = data.problems || [];

        const badge = document.querySelector('header .badge');
        if (badge) {
            if (data.judge0_configured) {
                badge.className = 'badge bg-success-subtle text-success border border-success border-opacity-25 px-3 py-1';
                badge.innerHTML = '<i class="bi bi-terminal-check me-1"></i>Judge0 Sandbox Ready';
            } else {
                badge.className = 'badge bg-warning-subtle text-warning border border-warning border-opacity-25 px-3 py-1';
                badge.innerHTML = '<i class="bi bi-info-circle me-1"></i>Demo Mode (Judge0 Unconfigured)';
            }
        }

        updateProgressCounter(data.solved_count || 0, data.total_count || 0);
        renderQuestionList();

        if (problemsList.length > 0 && (!activeQuestion || !problemsList.some(p => p.id === activeQuestion.id))) {
            await loadQuestion(problemsList[0].id);
        }
    } catch (err) {
        if (listContainer) listContainer.innerHTML = '<div class="p-3 text-danger small">Failed to load problems.</div>';
    }
}

function renderQuestionList() {
    const listContainer = document.getElementById('questionListItems');
    if (!listContainer) return;
    if (problemsList.length === 0) {
        listContainer.innerHTML = '<div class="p-3 text-center text-muted small">No problems match your filter.</div>';
        return;
    }

    listContainer.innerHTML = problemsList.map(q => {
        const isSolved = Boolean(Number(q.is_solved));
        const badgeColor = q.difficulty === 'Easy' ? 'success' : q.difficulty === 'Medium' ? 'warning' : 'danger';
        const isActive = activeQuestion && activeQuestion.id === q.id;
        return `
            <a href="javascript:void(0)" onclick="loadQuestion(${q.id})" class="list-group-item list-group-item-action d-flex justify-content-between align-items-center py-2 px-3 ${isActive ? 'active text-white' : ''}">
                <div>
                    <span class="me-2">${isSolved ? '<i class="bi bi-check-circle-fill text-success"></i>' : '<i class="bi bi-circle text-muted"></i>'}</span>
                    <strong class="font-monospace small me-1">#${q.problem_number}</strong>
                    <span class="small">${escapeHtml(q.title)}</span>
                </div>
                <span class="badge bg-${badgeColor} text-uppercase" style="font-size:0.65rem;">${escapeHtml(q.difficulty)}</span>
            </a>
        `;
    }).join('');
}

async function loadQuestion(id) {
    try {
        const res = await EPAP_API.epapFetch(`/api/practice/problems/${id}`);
        if (!res.ok) return;
        const q = await res.json();
        activeQuestion = q;
        renderQuestionList();

        document.getElementById('problemTitle').innerText = `#${q.problem_number}. ${q.title}`;
        const diffBadge = document.getElementById('problemDifficultyBadge');
        diffBadge.innerText = q.difficulty;
        diffBadge.className = `badge bg-${q.difficulty === 'Easy' ? 'success' : q.difficulty === 'Medium' ? 'warning' : 'danger'}`;

        document.getElementById('problemTopicBadge').innerText = q.topic;
        document.getElementById('problemDescription').innerHTML = q.description;

        const exampleBox = document.getElementById('problemExamples');
        exampleBox.innerHTML = (q.examples || []).map((ex, idx) => `
            <div class="p-3 bg-light rounded-3 mb-2 border">
                <h6 class="fw-bold mb-1 small text-dark">Example ${idx + 1}:</h6>
                <div class="small text-muted font-monospace"><strong>Input:</strong> ${escapeHtml(ex.input)}</div>
                <div class="small text-muted font-monospace"><strong>Output:</strong> ${escapeHtml(ex.output)}</div>
                ${ex.explanation ? `<div class="small text-muted mt-1"><strong>Explanation:</strong> ${escapeHtml(ex.explanation)}</div>` : ''}
            </div>
        `).join('');

        const constraintsList = document.getElementById('problemConstraints');
        constraintsList.innerHTML = (q.constraints || []).map(c => `<li class="small font-monospace">${escapeHtml(c)}</li>`).join('');

        const editor = document.getElementById('codeEditorArea');
        if (editor) {
            editor.value = (q.starter_code && q.starter_code[currentLanguage]) || '// Write code here';
        }

        const consoleBox = document.getElementById('consoleOutputText');
        if (consoleBox) {
            consoleBox.innerHTML = '<span class="text-muted">Console output will appear here after running your code.</span>';
        }
    } catch (err) {
        showToast('Failed to load problem details.', 'error');
    }
}

async function runCode() {
    if (!activeQuestion) return;
    const code = document.getElementById('codeEditorArea').value;
    const consoleBox = document.getElementById('consoleOutputText');
    consoleBox.innerHTML = '<span class="text-warning"><i class="bi bi-hourglass-split me-1"></i> Running sample test case...</span>';

    try {
        const res = await EPAP_API.epapFetch('/api/practice/run', {
            method: 'POST',
            body: JSON.stringify({ problem_id: activeQuestion.id, language: currentLanguage, code })
        });
        const data = await res.json();
        if (!res.ok) {
            consoleBox.innerHTML = `<div class="text-danger">${escapeHtml(data.error || 'Execution failed.')}</div>`;
            return;
        }
        if (data.mode === 'demo') {
            consoleBox.innerHTML = `
                <div class="text-warning fw-bold"><i class="bi bi-info-circle me-1"></i>${escapeHtml(data.status)}</div>
                <div class="text-light small mt-1">${escapeHtml(data.message)}</div>
                <div class="text-muted small mt-1 font-monospace">Sample Input: ${escapeHtml(data.input || '')}</div>
                <div class="text-muted small font-monospace">Expected Output: ${escapeHtml(data.expected_output || '')}</div>
            `;
            return;
        }
        consoleBox.innerHTML = `
            <div class="text-info fw-bold">Status: ${escapeHtml(data.status)}</div>
            <div class="text-muted small mt-1 font-monospace">Input: ${escapeHtml(data.input || '')}</div>
            <div class="text-muted small font-monospace">Expected: ${escapeHtml(data.expected_output || '')}</div>
            <div class="text-light small font-monospace">Stdout: ${escapeHtml(data.stdout || '')}</div>
            ${data.stderr ? `<div class="text-danger small font-monospace">Stderr: ${escapeHtml(data.stderr)}</div>` : ''}
            ${data.compile_output ? `<div class="text-warning small font-monospace">Compile: ${escapeHtml(data.compile_output)}</div>` : ''}
        `;
    } catch (err) {
        consoleBox.innerHTML = '<div class="text-danger">Network error running code.</div>';
    }
}

async function submitSolution() {
    if (!activeQuestion) return;
    const code = document.getElementById('codeEditorArea').value;
    const consoleBox = document.getElementById('consoleOutputText');
    consoleBox.innerHTML = '<span class="text-warning"><i class="bi bi-cpu me-1"></i> Submitting solution...</span>';

    try {
        const res = await EPAP_API.epapFetch('/api/practice/submit', {
            method: 'POST',
            body: JSON.stringify({ problem_id: activeQuestion.id, language: currentLanguage, code })
        });
        const data = await res.json();
        if (!res.ok) {
            consoleBox.innerHTML = `<div class="text-danger">${escapeHtml(data.error || 'Submission failed.')}</div>`;
            return;
        }
        if (data.mode === 'demo') {
            consoleBox.innerHTML = `
                <div class="text-warning fw-bold"><i class="bi bi-info-circle me-1"></i>${escapeHtml(data.verdict)}</div>
                <div class="text-light small mt-1">${escapeHtml(data.message)}</div>
            `;
            showToast('Recorded in Demo Mode (Judge0 not configured).', 'info');
            return;
        }
        const ok = data.verdict === 'Accepted';
        consoleBox.innerHTML = `
            <div class="${ok ? 'text-success' : 'text-danger'} fw-bold fs-6">
                ${escapeHtml(data.verdict)} (${data.passed}/${data.total} Test Cases Passed)
            </div>
            <div class="text-light small mt-1">Runtime: <strong>${data.runtime_ms || 0} ms</strong></div>
        `;
        if (ok) {
            showToast('Solution Accepted!', 'success');
            await fetchProblems();
        }
    } catch (err) {
        consoleBox.innerHTML = '<div class="text-danger">Network error submitting solution.</div>';
    }
}

function updateProgressCounter(solved, total) {
    const pct = total > 0 ? Math.round((solved / total) * 100) : 0;
    const countEl = document.getElementById('solvedCountText');
    if (countEl) countEl.innerText = `${solved} / ${total} Solved`;
    const barEl = document.getElementById('solvedProgressBar');
    if (barEl) {
        barEl.style.width = `${pct}%`;
        barEl.innerText = `${pct}%`;
    }
}
