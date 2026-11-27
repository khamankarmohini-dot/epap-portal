/**
 * Zeal EPAP - Shared API Client, CSRF Manager, Auth Guard & XSS Escaping
 */
(function (global) {
    const BASE_PATH = typeof global.EPAP_BASE_PATH === 'string' ? global.EPAP_BASE_PATH : '';
    let csrfTokenCache = null;

    function apiUrl(path) {
        if (path.startsWith('http://') || path.startsWith('https://')) return path;
        return BASE_PATH + path;
    }

    function escapeHtml(value) {
        if (value === null || value === undefined) return '';
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    async function ensureCsrfToken() {
        if (csrfTokenCache) return csrfTokenCache;
        const res = await fetch(apiUrl('/api/auth/csrf'), { credentials: 'same-origin', cache: 'no-store' });
        if (res.ok) {
            const data = await res.json();
            csrfTokenCache = data.csrf_token || '';
        }
        return csrfTokenCache || '';
    }

    function showWakeBanner(show) {
        let el = document.getElementById('epapWakeBanner');
        if (!show) {
            if (el) el.remove();
            return;
        }
        if (!el) {
            el = document.createElement('div');
            el.id = 'epapWakeBanner';
            el.className = 'alert alert-info shadow-sm position-fixed top-0 start-50 translate-middle-x mt-2 py-2 px-3 small';
            el.style.zIndex = '2050';
            el.setAttribute('role', 'status');
            el.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span>The server is waking up, please wait up to a minute...';
            document.body.appendChild(el);
        }
    }

    async function fetchWithWakeRetry(url, fetchOpts, allowRetry) {
        const delays = [3000, 6000, 10000, 15000, 20000];
        let attempt = 0;
        while (true) {
            const controller = new AbortController();
            const timer = setTimeout(() => controller.abort(), 15000);
            try {
                const res = await fetch(url, { ...fetchOpts, signal: controller.signal });
                clearTimeout(timer);
                if (allowRetry && [502, 503, 504].includes(res.status) && attempt < delays.length) {
                    showWakeBanner(true);
                    await new Promise(r => setTimeout(r, delays[attempt++]));
                    continue;
                }
                showWakeBanner(false);
                return res;
            } catch (err) {
                clearTimeout(timer);
                if (allowRetry && attempt < delays.length) {
                    showWakeBanner(true);
                    await new Promise(r => setTimeout(r, delays[attempt++]));
                    continue;
                }
                showWakeBanner(false);
                const wakeErr = new Error('The server is waking up, please wait up to a minute.');
                wakeErr.isColdStart = true;
                throw wakeErr;
            }
        }
    }

    async function epapFetch(path, options = {}) {
        const method = (options.method || 'GET').toUpperCase();
        const headers = new Headers(options.headers || {});

        if (!['GET', 'HEAD', 'OPTIONS'].includes(method)) {
            const token = await ensureCsrfToken();
            if (token) {
                headers.set('X-CSRF-Token', token);
            }
            if (options.body && !(options.body instanceof FormData) && !headers.has('Content-Type')) {
                headers.set('Content-Type', 'application/json');
            }
        }

        const allowRetry = options.wakeRetry !== false && (method === 'GET' || path.startsWith('/api/auth/'));
        const res = await fetchWithWakeRetry(apiUrl(path), {
            cache: 'no-store',
            ...options,
            method,
            headers,
            credentials: 'same-origin',
        }, allowRetry);

        if (res.status === 401 && !path.startsWith('/api/auth/')) {
            if (global.EPAP_SHELL && typeof global.EPAP_SHELL.stop === 'function') {
                global.EPAP_SHELL.stop();
            }
            window.location.href = apiUrl('/login.html');
            throw new Error('Not authenticated');
        }
        return res;
    }

    async function epapRequireAuth(expectedRole) {
        const res = await fetch(apiUrl('/api/auth/me'), { credentials: 'same-origin', cache: 'no-store' });
        if (res.status === 401) {
            window.location.href = apiUrl('/login.html');
            return null;
        }
        const me = await res.json();
        if (me.csrf_token) {
            csrfTokenCache = me.csrf_token;
        }
        if (expectedRole && me.role !== expectedRole) {
            const target = me.role === 'student'
                ? '/student-dashboard.html'
                : me.role === 'faculty'
                    ? '/faculty-dashboard.html'
                    : '/admin-dashboard.html';
            window.location.href = apiUrl(target);
            return null;
        }
        if (me.must_change_password && !window.location.pathname.endsWith('profile.html')) {
            window.location.href = apiUrl('/profile.html?force=1');
            return null;
        }
        loadPublicSettings();
        return me;
    }

    function deriveInitials(fullName) {
        const words = String(fullName || 'U').trim().split(/\s+/).filter(Boolean);
        if (words.length >= 2) {
            return (words[0][0] + words[words.length - 1][0]).toUpperCase();
        }
        return (words[0] || 'U').slice(0, 2).toUpperCase();
    }

    function updateNavbarUser(me) {
        if (!me) return;
        const initials = me.initials || deriveInitials(me.full_name);
        const avatar = document.querySelector('[data-shell="avatar"]') || document.querySelector('header .dropdown .rounded-circle');
        if (avatar) avatar.textContent = initials;
        const nameEl = document.querySelector('[data-shell="user-name"]') || document.querySelector('header .d-block.fw-bold.small.text-dark');
        if (nameEl) nameEl.textContent = me.full_name || '';
        const idEl = document.querySelector('[data-shell="user-id"]') || document.querySelector('header .d-block.text-muted.font-monospace');
        if (idEl) idEl.textContent = me.identifier || '';
    }

    async function loadPublicSettings() {
        try {
            const res = await fetch(apiUrl('/api/settings/public'), { credentials: 'same-origin', cache: 'no-store' });
            if (!res.ok) return null;
            const s = await res.json();
            document.querySelectorAll('[data-setting]').forEach(el => {
                const key = el.getAttribute('data-setting');
                if (key && s[key] !== undefined && s[key] !== '') {
                    el.textContent = s[key];
                }
            });
            const supportWrap = document.getElementById('supportEmailWrap');
            if (supportWrap && s.support_email) {
                supportWrap.textContent = ' (' + s.support_email + ')';
            }
            return s;
        } catch (_) {
            return null;
        }
    }

    global.EPAP_API = {
        apiUrl,
        escapeHtml,
        ensureCsrfToken,
        epapFetch,
        epapRequireAuth,
        updateNavbarUser,
        loadPublicSettings,
        setCsrfToken: (t) => { csrfTokenCache = t; },
    };
    global.escapeHtml = escapeHtml;
})(window);
