/**
 * EPAP Shared Shell & Live Auto-Refresh Controller
 * Single source of truth for header, sidebar badges, user avatar/initials,
 * settings labels, and background polling with visibility & backoff.
 */
(function (global) {
    let state = {
        role: null,
        onRefresh: null,
        intervalMs: 45000,
        timerId: null,
        ageTimerId: null,
        lastUpdatedAt: null,
        errorStreak: 0,
        stopped: false,
        inFlight: false,
        lastTriggerAt: 0,
        lastSummary: null,
    };

    function escapeHtmlSafe(v) {
        return global.escapeHtml ? global.escapeHtml(v) : String(v ?? '');
    }

    function applyStudentShell(s) {
        const sessionEl = document.getElementById('sidebarSessionText');
        if (sessionEl && s.session) {
            const sem = s.student?.semester || s.session.current_semester || '';
            sessionEl.textContent = `${s.session.academic_year} • Semester ${sem}`;
        }
        const classBadge = document.getElementById('sidebarClassBadge');
        if (classBadge && s.student) {
            classBadge.textContent = `${s.student.year} Division ${s.student.division}`;
        }

        const pendingBadge = document.getElementById('sidebarPendingBadge');
        if (pendingBadge) {
            const cnt = Number(s.pending_activities || 0);
            if (cnt > 0) {
                pendingBadge.textContent = `${cnt} Due`;
                pendingBadge.classList.remove('d-none');
            } else {
                pendingBadge.classList.add('d-none');
            }
        }

        const practiceBadge = document.getElementById('sidebarPracticeBadge');
        if (practiceBadge) {
            const total = Number(s.coding_total || 0);
            if (total > 0) {
                practiceBadge.textContent = `${total} Qs`;
                practiceBadge.classList.remove('d-none');
            } else {
                practiceBadge.classList.add('d-none');
            }
        }

        const viewAllCount = document.getElementById('viewAllActivitiesCount');
        if (viewAllCount) {
            viewAllCount.textContent = String(s.pending_activities ?? 0);
        }

        const notifBadge = document.getElementById('headerNotifBadge');
        if (notifBadge) {
            const u = Number(s.unread_notifications || 0);
            notifBadge.textContent = String(u);
            notifBadge.classList.toggle('d-none', u === 0);
        }
    }

    function applyFacultyShell(s) {
        const desigEl = document.getElementById('sidebarFacultyRole');
        if (desigEl && s.user) {
            desigEl.textContent = s.user.designation || 'Faculty';
        }
        const classesEl = document.getElementById('sidebarFacultyClasses');
        if (classesEl) {
            const badges = Array.isArray(s.class_badges) ? s.class_badges : [];
            classesEl.textContent = badges.length > 0 ? badges.join(' • ') : 'No active allocations';
        }
        const pendBadge = document.getElementById('sidebarFacultyPendingBadge');
        if (pendBadge) {
            const cnt = Number(s.pending_evaluations || 0);
            if (cnt > 0) {
                pendBadge.textContent = `${cnt} Pending`;
                pendBadge.classList.remove('d-none');
            } else {
                pendBadge.classList.add('d-none');
            }
        }
        const allocList = document.getElementById('sidebarAllocatedCourses');
        if (allocList) {
            const allocs = Array.isArray(s.allocations) ? s.allocations : [];
            if (allocs.length === 0) {
                allocList.innerHTML = '<li><span class="nav-link text-white-50 small">No courses allocated</span></li>';
            } else {
                allocList.innerHTML = allocs.map(a => `
                    <li>
                        <a href="faculty-activities.html" class="nav-link">
                            <i class="bi bi-book"></i>
                            <span class="text-truncate">${escapeHtmlSafe(a.subject_code)} (${escapeHtmlSafe(a.year)} ${escapeHtmlSafe(a.division)})</span>
                        </a>
                    </li>`).join('');
            }
        }
    }

    function applyAdminShell(s) {
        const incBadge = document.getElementById('sidebarInconsistentBadge');
        if (incBadge) {
            const cnt = Number(s.inconsistent_students || 0);
            if (cnt > 0) {
                incBadge.textContent = `${cnt} Warn`;
                incBadge.classList.remove('d-none');
            } else {
                incBadge.classList.add('d-none');
            }
        }
    }

    function applyCommonShell(s) {
        if (s.user && global.EPAP_API) {
            global.EPAP_API.updateNavbarUser(s.user);
        }
        if (s.server_now) {
            const serverTs = new Date(s.server_now).getTime();
            if (!Number.isNaN(serverTs)) {
                global.EPAP_SERVER_OFFSET_MS = serverTs - Date.now();
            }
        }
        document.querySelectorAll('[data-setting="institution_name"]').forEach(el => {
            if (s.institution_name) el.textContent = s.institution_name;
        });
        document.querySelectorAll('[data-setting="department_name"]').forEach(el => {
            if (s.department_name) el.textContent = s.department_name;
        });
        document.querySelectorAll('[data-setting="academic_year"]').forEach(el => {
            if (s.session?.academic_year) el.textContent = s.session.academic_year;
        });
        document.querySelectorAll('[data-setting="benchmark_label"]').forEach(el => {
            if (s.benchmark_label) el.textContent = s.benchmark_label;
        });
        document.querySelectorAll('[data-setting="curriculum_label"]').forEach(el => {
            if (s.curriculum_label) el.textContent = s.curriculum_label;
        });
    }

    function ensureRefreshIndicator() {
        if (document.getElementById('epapRefreshStatus')) return;
        const main = document.querySelector('main.main-content');
        if (!main) return;
        const bar = document.createElement('div');
        bar.id = 'epapRefreshBar';
        bar.className = 'd-flex align-items-center justify-content-end gap-2 mb-2 small text-muted no-print';
        bar.innerHTML = `
            <span id="epapStaleNotice" class="badge bg-warning-subtle text-warning border border-warning-subtle d-none" aria-live="polite">
                <i class="bi bi-wifi-off me-1"></i>Couldn't refresh
            </span>
            <span id="epapRefreshStatus" aria-live="polite">Updating...</span>
            <button type="button" id="manualRefreshBtn" class="btn btn-light btn-sm border py-0 px-2" title="Refresh data" aria-label="Refresh data">
                <i class="bi bi-arrow-clockwise"></i>
            </button>
        `;
        main.insertBefore(bar, main.firstChild);
        const btn = document.getElementById('manualRefreshBtn');
        if (btn) {
            btn.addEventListener('click', () => refreshNow(true));
        }
    }

    function updateRefreshAgeLabel() {
        const el = document.getElementById('epapRefreshStatus');
        if (!el || !state.lastUpdatedAt) return;
        const diffSec = Math.floor((Date.now() - state.lastUpdatedAt) / 1000);
        if (diffSec < 15) {
            el.textContent = 'Updated just now';
        } else if (diffSec < 60) {
            el.textContent = `Updated ${diffSec}s ago`;
        } else {
            const mins = Math.floor(diffSec / 60);
            el.textContent = `Updated ${mins}m ago`;
        }
    }

    function setStaleNotice(show) {
        const el = document.getElementById('epapStaleNotice');
        if (el) el.classList.toggle('d-none', !show);
    }

    function scheduleNext() {
        if (state.timerId) clearTimeout(state.timerId);
        if (state.stopped || document.hidden) return;
        const backoff = Math.min(4, Math.pow(2, state.errorStreak));
        const delay = state.errorStreak > 0 ? state.intervalMs * backoff : state.intervalMs;
        state.timerId = setTimeout(() => {
            if (!document.hidden && !state.stopped) {
                refreshNow(false);
            }
        }, delay);
    }

    async function refreshNow(force = false) {
        if (state.stopped || state.inFlight) return;
        const now = Date.now();
        if (!force && now - state.lastTriggerAt < 2500) return;
        state.lastTriggerAt = now;
        state.inFlight = true;

        const btn = document.getElementById('manualRefreshBtn');
        if (btn) btn.disabled = true;

        try {
            const res = await global.EPAP_API.epapFetch(`/api/${state.role}/summary`);
            if (!res.ok) throw new Error('Summary fetch failed');
            const summary = await res.json();
            state.lastSummary = summary;

            applyCommonShell(summary);
            if (state.role === 'student') applyStudentShell(summary);
            else if (state.role === 'faculty') applyFacultyShell(summary);
            else if (state.role === 'admin') applyAdminShell(summary);

            if (typeof state.onRefresh === 'function') {
                await state.onRefresh(summary);
            }

            state.errorStreak = 0;
            state.lastUpdatedAt = Date.now();
            setStaleNotice(false);
            updateRefreshAgeLabel();
        } catch (err) {
            if (err && err.message === 'Not authenticated') {
                stop();
                return;
            }
            state.errorStreak += 1;
            setStaleNotice(true);
        } finally {
            state.inFlight = false;
            if (btn) btn.disabled = false;
            scheduleNext();
        }
    }

    function stop() {
        state.stopped = true;
        if (state.timerId) clearTimeout(state.timerId);
        if (state.ageTimerId) clearInterval(state.ageTimerId);
    }

    function init(opts = {}) {
        state.role = opts.role;
        state.onRefresh = opts.onRefresh || null;
        state.intervalMs = opts.intervalMs || 45000;
        state.stopped = false;

        ensureRefreshIndicator();

        document.addEventListener('visibilitychange', () => {
            if (document.hidden) {
                if (state.timerId) clearTimeout(state.timerId);
            } else if (!state.stopped) {
                refreshNow(false);
            }
        });

        global.addEventListener('focus', () => {
            if (!document.hidden && !state.stopped) {
                refreshNow(false);
            }
        });

        if (state.ageTimerId) clearInterval(state.ageTimerId);
        state.ageTimerId = setInterval(updateRefreshAgeLabel, 15000);

        return refreshNow(true);
    }

    global.EPAP_SHELL = {
        init,
        refreshNow,
        stop,
        getSummary: () => state.lastSummary,
    };
})(window);
