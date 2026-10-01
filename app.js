const app = document.querySelector('#app');
const toastRegion = document.querySelector('#toast-region');
const APP_BASE = '/CACSALAUTECHCBT';
const API_URL = `${APP_BASE}/api.php?action=`;
const PAGE_SIZE = 10;
const INTEGRITY_EVENT_TYPES = ['tab_switch', 'blur', 'context_menu', 'copy', 'paste', 'devtools'];
// Edit this one list to change the student-portal hero words.
const HERO_ROTATING_WORDS = ['breakthrough', 'milestone', 'result', 'success', 'excellence'];

const state = {
  adminAuthenticated: false,
  adminUser: readStored('algeAdminUser'),
  studentAccess: readStored('algeStudentSession'),
  attempt: readStored('algeExamAttempt'),
  exams: [], landingStatus: {openComponents: 0, studentsTesting: 0, clientIp: 'Unavailable', singleSessionLockActive: false}, activePeriod: {sessionLabel: '', semesterLabel: ''}, landingFilters: {search: '', type: 'all', category: 'all'}, adminExams: [], courses: [], academicSessions: [], semesters: [], students: [], questions: [], results: [],
  dashboard: null, dashboardSelectedOutcomes: [], settings: null, backups: {items: [], settings: {}, nextRunAt: null, directory: 'database/backups/'}, backupRestoreFile: null, backupRestoreInfo: null, report: null, review: null, auditMonitor: [], auditEvents: [], roles: [], adminUsers: [], adminApprovals: {pending: [], recent: [], mailConfigured: false}, pendingApprovalCount: 0, account: null, accountTab: 'profile', newsletterSubscribers: [], newsletterStats: null, newsletters: [], newsletterMailConfigured: false, resultPeriods: [],
  selectedExamId: null, questionIndex: 0, secondsLeft: 0, timerId: null, availabilityRefreshId: null, landingCountdownId: null, landingCountFrame: null, landingLiveValues: {}, landingStepObserver: null, landingStepsRevealPlayed: false, headlineRotationId: null, loginCountdownId: null, auditPollId: null, approvalPollId: null, devtoolsTimer: null, integrityLast: {}, saveState: 'saved', examTextScale: Math.max(.85, Math.min(1.35, Number(sessionStorage.getItem('algeExamTextScale')) || 1)), calculatorOpen: false, calculatorValue: '0', sidebarOpen: sessionStorage.getItem('algeSidebarOpen') == null ? !window.matchMedia('(max-width: 900px)').matches : sessionStorage.getItem('algeSidebarOpen') !== 'false', landingHeroEntrancePlayed: false,
  loadSerial: 0, route: '', modalTrigger: null, csrfToken: '', passwordResetEmail: sessionStorage.getItem('algeAdminPasswordResetEmail') || '', theme: localStorage.getItem('algeTheme') || 'dark',
  filters: {
    students: {q: '', sort: 'fullName', dir: 'asc', page: 1, status: ''},
    exams: {q: '', sort: 'code', dir: 'asc', page: 1, status: ''},
    questions: {q: '', sort: 'text', dir: 'asc', page: 1, status: ''},
    results: {q: '', sort: 'latestSubmittedAt', dir: 'desc', page: 1, status: '', period: ''},
    audit: {from: '', to: '', actor: '', type: '', course: '', page: 1},
    newsletter: {q: '', sort: 'subscribedAt', dir: 'desc', page: 1, status: ''},
    outcomes: {page: 1}
  },
  meta: {}
};

function applyTheme(theme) {
  const dark = theme === 'dark';
  document.body.classList.toggle('dark-theme', dark);
  document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
  document.querySelector('meta[name="theme-color"]')?.setAttribute('content', dark ? '#101714' : '#16774d');
}
applyTheme(state.theme);

function ensureAdminSidebarControls() {
  const shell = document.querySelector('.admin-shell');
  const sidebar = shell?.querySelector('.admin-sidebar');
  if (!shell || !sidebar) return;
  if (!shell.querySelector('.admin-sidebar-backdrop')) {
    const backdrop = document.createElement('button');
    backdrop.type = 'button'; backdrop.className = 'admin-sidebar-backdrop';
    backdrop.dataset.action = 'close-sidebar';
    backdrop.setAttribute('aria-label', 'Close sidebar');
    shell.insertBefore(backdrop, sidebar);
  }
  if (!sidebar.querySelector('.admin-sidebar-close')) {
    const close = document.createElement('button');
    close.type = 'button'; close.className = 'admin-sidebar-close';
    close.dataset.action = 'close-sidebar'; close.setAttribute('aria-label', 'Close sidebar');
    close.textContent = '×'; sidebar.prepend(close);
  }
}
function setAdminSidebarOpen(open) {
  ensureAdminSidebarControls();
  state.sidebarOpen = Boolean(open);
  sessionStorage.setItem('algeSidebarOpen', String(state.sidebarOpen));
  const shell = document.querySelector('.admin-shell');
  shell?.classList.toggle('sidebar-collapsed', !state.sidebarOpen);
  document.querySelectorAll('[data-action="toggle-sidebar"]').forEach(control => {
    control.setAttribute('aria-expanded', String(state.sidebarOpen));
    control.setAttribute('aria-label', state.sidebarOpen ? 'Close sidebar' : 'Open sidebar');
    control.setAttribute('title', state.sidebarOpen ? 'Close sidebar' : 'Open sidebar');
    control.textContent = state.sidebarOpen ? '×' : '☰';
  });
}

function readStored(key) {
  try { return JSON.parse(sessionStorage.getItem(key) || 'null'); }
  catch { sessionStorage.removeItem(key); return null; }
}
function saveStored(key, value) {
  if (value) sessionStorage.setItem(key, JSON.stringify(value));
  else sessionStorage.removeItem(key);
}
function esc(value) {
  return String(value ?? '').replace(/[&<>"']/g, character => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[character]));
}
function fmtDate(value) { return value ? new Date(value).toLocaleString() : '---'; }
function fmtTime(seconds) {
  const safe = Math.max(0, Math.floor(Number(seconds) || 0));
  return `${String(Math.floor(safe / 60)).padStart(2, '0')}:${String(safe % 60).padStart(2, '0')}`;
}
function displayIp(value) {
  const ip = String(value || '').trim();
  if (ip === '::1') return '127.0.0.1 (local)';
  return ip.startsWith('::ffff:') ? ip.slice(7) : (ip || 'Unavailable');
}
function numeric(value, fallback = 0) { const number = Number(value); return Number.isFinite(number) ? number : fallback; }
function can(permission) {
  if (permission === 'roles' || permission === 'users' || permission === 'approvals' || permission === 'backups') return !state.adminUser || state.adminUser.roleId === 'superadmin';
  if (permission === 'newsletter') return true;
  return !state.adminUser?.permissions || state.adminUser.permissions.includes(permission);
}
function examStatusMeta(exam) {
  if (exam.status === 'active' && exam.windowState === 'open') return {label: 'Active / open now', className: ''};
  if (exam.status === 'active' && exam.windowState === 'scheduled') return {label: 'Active / scheduled', className: 'status-warning'};
  if (exam.status === 'active' && exam.windowState === 'closed') return {label: 'Active / window closed', className: 'status-danger'};
  return {label: exam.status === 'published' ? 'Published' : 'Draft', className: 'status-muted'};
}
function candidateInitials(name) {
  return String(name || 'Student').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'S';
}
function sidebarIcon(id) {
  const paths = {
    overview: '<path d="M3 10.5 10 4l7 6.5v6.2a1.3 1.3 0 0 1-1.3 1.3H4.3A1.3 1.3 0 0 1 3 16.7z"/><path d="M8 18v-5h4v5"/>',
    students: '<circle cx="7.3" cy="8" r="2.5"/><circle cx="14.8" cy="8.6" r="2"/><path d="M2.8 17c.5-3 2.2-4.5 4.5-4.5s4 1.5 4.5 4.5M12.3 13.1c2.2-.2 3.9 1 4.4 3.3"/>',
    exams: '<rect x="3" y="4.5" width="14" height="13" rx="2"/><path d="M6 3v3M14 3v3M3 8.5h14M6.5 12h2M11.5 12h2M6.5 15h2"/>',
    questions: '<path d="M5 3h8l3 3v11H5z"/><path d="M13 3v4h3M7.5 11.5c0-1.2.8-2.1 2.2-2.1 1.2 0 2 .7 2 1.8 0 1.7-2.1 1.6-2.1 3M9.6 15.7h.1"/>',
    results: '<path d="M4 17V9M9 17V5M14 17v-7"/><path d="M2.5 17.5h14"/>',
    audit: '<path d="M10 2.8 16 5v4.5c0 4-2.5 6.8-6 8-3.5-1.2-6-4-6-8V5z"/><path d="m7.2 10 1.8 1.8 3.6-3.8"/>',
    newsletter: '<rect x="2.5" y="5" width="15" height="11" rx="2"/><path d="m3.3 6 6.7 5 6.7-5"/>',
    roles: '<path d="M10 2.5 12 5l3.2.3.8 3 2 2.4-2 2.4-.8 3-3.2.3-2 2.5-2-2.5-3.2-.3-.8-3-2-2.4 2-2.4.8-3L8 5z"/><circle cx="10" cy="10.5" r="2"/>',
    users: '<circle cx="10" cy="7" r="3"/><path d="M4.5 17c.7-3.3 2.5-5 5.5-5s4.8 1.7 5.5 5M15.5 5.5h2M16.5 4.5v2"/>',
    approvals: '<circle cx="10" cy="10" r="7.5"/><path d="M10 5.8v4.5l3 1.8"/>',
    backups: '<ellipse cx="10" cy="5.5" rx="5.8" ry="2.5"/><path d="M4.2 5.5v8c0 1.4 2.6 2.5 5.8 2.5s5.8-1.1 5.8-2.5v-8M4.2 9.5C4.2 10.9 6.8 12 10 12s5.8-1.1 5.8-2.5"/>',
    settings: '<circle cx="10" cy="10" r="2.6"/><path d="M10 2.8v2M10 15.2v2M17.2 10h-2M4.8 10h-2M15.1 4.9l-1.4 1.4M6.3 13.7l-1.4 1.4M15.1 15.1l-1.4-1.4M6.3 6.3 4.9 4.9"/>'
  };
  return `<svg class="sidebar-icon" viewBox="0 0 20 20" aria-hidden="true">${paths[id] || paths.overview}</svg>`;
}
function saveStateMeta() {
  if (state.saveState === 'saving') return {label: 'Saving...', state: 'saving'};
  if (state.saveState === 'error') return {label: 'Save failed', state: 'error'};
  return {label: 'Autosave on', state: 'saved'};
}
function setSaveState(value) {
  state.saveState = value;
  const meta = saveStateMeta();
  document.querySelectorAll('[data-save-state]').forEach(element => element.textContent = meta.label);
  document.querySelectorAll('.autosave-status').forEach(element => element.dataset.state = meta.state);
}
function brand(subtitle = 'Assessment centre') { return `<div class="brand"><img class="brand-mark" src="CACSA%20Logo.jpeg" alt="CACSA logo"><span>CACSA LAUTECH CBT<small>${esc(subtitle)}</small></span></div>`; }
function themeToggleContents(isDark) {
  return `<span class="theme-toggle-icon" aria-hidden="true"><span class="theme-icon theme-icon-sun">☀</span><span class="theme-icon theme-icon-moon">☾</span></span><span class="theme-toggle-label">${isDark ? 'Light' : 'Dark'}</span>`;
}
function themeToggle() {
  const isDark = state.theme === 'dark';
  return `<button class="theme-toggle" type="button" data-action="toggle-theme" data-theme="${isDark ? 'dark' : 'light'}" aria-label="Switch to ${isDark ? 'light' : 'dark'} theme" title="Switch to ${isDark ? 'light' : 'dark'} theme">${themeToggleContents(isDark)}</button>`;
}
function passwordInput(id, autocomplete, attributes = '', name = 'password') {
  return `<span class="password-input"><input id="${esc(id)}" name="${esc(name)}" type="password" autocomplete="${esc(autocomplete)}" ${attributes}><button class="password-toggle" type="button" data-toggle-password="${esc(id)}" aria-label="Show password" aria-pressed="false" title="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.7"></circle></svg><span class="sr-only">Show password</span></button></span>`;
}
function mathHtml(value) {
  return esc(value).replace(/\r?\n/g, '<br>')
    .replace(/([A-Za-z0-9)])\^\(([^<>()]*)\)/g, '$1<sup>$2</sup>')
    .replace(/([A-Za-z0-9)])\^([A-Za-z0-9+\-]+)/g, '$1<sup>$2</sup>');
}
function toast(message, kind = 'info') {
  const element = document.createElement('div');
  element.className = `toast toast-${kind}`;
  element.setAttribute('role', kind === 'error' ? 'alert' : 'status');
  element.textContent = message;
  toastRegion.append(element);
  setTimeout(() => element.remove(), 5000);
}
function showError(error, form = null) {
  const message = error?.message || 'Something went wrong. Please try again.';
  const alert = form?.querySelector('.alert') || document.querySelector('#alert');
  if (alert) { alert.textContent = message; alert.classList.add('visible'); }
  toast(message, 'error');
}
function setBusy(element, busy) {
  if (!element) return;
  if (busy) { element.dataset.originalText = element.textContent; element.textContent = 'Working…'; element.disabled = true; }
  else { element.textContent = element.dataset.originalText || element.textContent; element.disabled = false; }
}
function formData(form) { return Object.fromEntries(new FormData(form)); }
async function lightweightDeviceFingerprint() {
  const timezone = Intl.DateTimeFormat().resolvedOptions().timeZone || '';
  const raw = [navigator.userAgent || '', navigator.platform || '', `${screen.width}x${screen.height}x${screen.colorDepth}`, timezone].join('|');
  try {
    const bytes = new TextEncoder().encode(raw);
    const digest = await crypto.subtle.digest('SHA-256', bytes);
    return [...new Uint8Array(digest)].map(value => value.toString(16).padStart(2, '0')).join('');
  } catch {
    // Older browsers can still take an exam; they simply do not participate in this optional signal.
    return '';
  }
}
function query(action, params = {}) {
  const search = new URLSearchParams({action});
  for (const [key, value] of Object.entries(params)) if (value !== '' && value != null) search.set(key, String(value));
  return `api.php?${search}`;
}
async function api(action, options = {}, params = {}) {
  const {admin: requireAdmin = false, ...fetchOptions} = options;
  const adminAction = requireAdmin || ['admin-logout', 'admin-account', 'students', 'students-bulk', 'exam-password', 'questions', 'questions-bulk', 'results', 'result-review', 'calculate-student-result', 'settings', 'student-results', 'dashboard', 'dashboard-outcomes', 'audit-monitor', 'audit-events', 'exam-session-unlock', 'roles', 'admin-users', 'admin-approvals', 'newsletter-subscribers', 'newsletters', 'courses', 'course-components', 'academic-sessions', 'semesters', 'backups'].includes(action) || (action === 'exams' && options.method && options.method !== 'GET');
  const mutating = ['POST', 'PUT', 'DELETE'].includes(String(options.method || 'GET').toUpperCase());
  if (mutating && action !== 'auth-csrf') await refreshCsrfToken();
  const headers = {...(options.body ? {'Content-Type': 'application/json'} : {}), ...(mutating && state.csrfToken ? {'X-CSRF-Token': state.csrfToken} : {}), ...options.headers};
  let response;
  try { response = await fetch(query(action, params), {...fetchOptions, headers, credentials: 'same-origin'}); }
  catch { throw new Error('Cannot reach the server. Check your connection and try again.'); }
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(data.error || `Request failed (${response.status}).`);
    error.status = response.status;
    if (response.status === 401 && adminAction) {
      clearAdmin();
      navigate('admin/login');
      toast('Your admin session ended. Please sign in again.', 'error');
    }
    throw error;
  }
  return data;
}
function post(action, body, params = {}) { return api(action, {method: 'POST', body: JSON.stringify(body)}, params); }
function put(action, body, params = {}) { return api(action, {method: 'PUT', body: JSON.stringify(body)}, params); }
function remove(action, params = {}) { return api(action, {method: 'DELETE'}, params); }
function clearAdmin() {
  state.adminAuthenticated = false; state.adminUser = null;
  sessionStorage.removeItem('algeAdminToken'); sessionStorage.removeItem('algeAdminExpiresAt'); sessionStorage.removeItem('algeAdminUser');
}
async function refreshCsrfToken() {
  const response = await fetch(query('auth-csrf'), {credentials: 'same-origin'});
  const data = await response.json().catch(() => ({}));
  if (!response.ok || !data.csrfToken) throw new Error('Could not establish a secure request session. Refresh and try again.');
  state.csrfToken = data.csrfToken;
}
function clearStudentAccess() { state.studentAccess = null; saveStored('algeStudentSession', null); }
function clearAttempt() {
  state.attempt = null; saveStored('algeExamAttempt', null);
  state.calculatorOpen = false; state.calculatorValue = '0'; state.examTextScale = 1; sessionStorage.removeItem('algeExamTextScale');
  clearInterval(state.timerId); state.timerId = null;
  clearInterval(state.devtoolsTimer); state.devtoolsTimer = null;
}
function currentRoute() {
  const segments = decodeURIComponent(location.pathname).split('/').filter(Boolean);
  const routeIndex = segments.findIndex(segment => segment === 'admin' || segment === 'student');
  return routeIndex >= 0 ? segments.slice(routeIndex).join('/') : 'student/selection';
}
function navigate(route) {
  closeModal();
  const target = `${APP_BASE}/${String(route).replace(/^\/+/, '')}`;
  if (location.pathname === target) render();
  else { history.pushState(null, '', target); render(); }
}
function parts() { return currentRoute().split('/').filter(Boolean); }
function selectedExam() {
  return state.exams.find(exam => exam.id === state.selectedExamId)
    || (state.attempt?.examId === state.selectedExamId ? state.attempt.exam : null)
    || (state.studentAccess?.examId === state.selectedExamId ? state.studentAccess.exam : null);
}
function listQuery(name) {
  const filter = state.filters[name];
  return {search: filter.q, sort: filter.sort, order: filter.dir, page: filter.page, pageSize: PAGE_SIZE, status: filter.status};
}
function resultPeriodParts(value = state.filters.results.period) {
  const [sessionId = '', semesterId = ''] = String(value || '').split('|');
  return {sessionId, semesterId};
}
function auditQuery() {
  const filter = state.filters.audit;
  return {from: filter.from, to: filter.to, actor: filter.actor, type: filter.type, course: filter.course, page: filter.page, pageSize: PAGE_SIZE};
}
function normalizeList(name, response) {
  const items = response.items || [];
  state.meta[name] = response.meta || {page: 1, pages: 1, total: items.length};
  return items;
}
function loadingPage(admin = false) {
  const loading = '<div class="loading-state" role="status"><span class="spinner" aria-hidden="true"></span><span>Loading…</span></div>';
  if (admin) return adminShell(loading);
  return `${topbar()}<main class="center-page">${loading}</main>`;
}
function assessmentIcon(exam) {
  const subject = `${exam.code || ''} ${exam.title || ''}`.toLowerCase();
  if (/math|mth|stat|calculus|algebra/.test(subject)) return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 5h14v14H5zM8 9h8M12 7v4M8 15h8"/></svg>';
  if (/computer|csc|ict|program|tech/.test(subject)) return '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="4" y="5" width="16" height="11" rx="2"/><path d="m8 20 2-4m6 4-2-4M9 9l-2 2 2 2m6-4 2 2-2 2"/></svg>';
  if (/science|bio|chem|phy|lab/.test(subject)) return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M9 3h6M10 3v6l-5 8a3 3 0 0 0 2.6 4.5h8.8A3 3 0 0 0 19 17l-5-8V3"/><path d="M8 15h8"/></svg>';
  return '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 4.5c2.8-.8 5.2-.3 7 1.3 1.8-1.6 4.2-2.1 7-1.3v14c-2.8-.8-5.2-.3-7 1.3-1.8-1.6-4.2-2.1-7-1.3z"/><path d="M12 5.8v14"/></svg>';
}
function assessmentUrgency(exam) {
  const milliseconds = Date.parse(exam.endAt || '') - Date.now();
  return Number.isFinite(milliseconds) && milliseconds > 0 && milliseconds <= 30 * 60 * 1000;
}
function startHeroWordRotation() {
  clearTimeout(state.headlineRotationId); state.headlineRotationId = null;
  const word = document.querySelector('[data-hero-rotating-word]');
  if (!word) return;
  const words = HERO_ROTATING_WORDS;
  word.textContent = words[0] || '';
  if (!words.length || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
  let wordIndex = 0, characterCount = 0, deleting = false;
  const tick = () => {
    const current = words[wordIndex];
    word.textContent = current.slice(0, characterCount);
    let delay;
    if (!deleting && characterCount < current.length) { characterCount += 1; delay = 78; }
    else if (!deleting) { deleting = true; delay = 1750; }
    else if (characterCount > 0) { characterCount -= 1; delay = 36; }
    else { deleting = false; wordIndex = (wordIndex + 1) % words.length; delay = 180; }
    state.headlineRotationId = setTimeout(tick, delay);
  };
  tick();
}
function startHeroEntrance() {
  const hero = document.querySelector('.portal-hero');
  if (!hero) return;
  const reducedMotion = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
  if (reducedMotion || state.landingHeroEntrancePlayed) {
    hero.classList.remove('hero-entrance-pending');
    return;
  }
  state.landingHeroEntrancePlayed = true;
  requestAnimationFrame(() => {
    if (!hero.isConnected) return;
    hero.classList.remove('hero-entrance-pending');
    hero.classList.add('hero-entrance-active');
  });
}
function startLandingLiveCountUp() {
  if (state.landingCountFrame != null) cancelAnimationFrame(state.landingCountFrame);
  state.landingCountFrame = null;
  const metrics = {
    'open-components': {
      target: Math.max(0, Math.round(numeric(state.landingStatus?.openComponents, state.exams.length))),
      nodes: [...document.querySelectorAll('.portal-hero-status dl > div:first-child dd, .live-status-strip dl > div:first-child dd')]
    },
    'students-testing': {
      target: Math.max(0, Math.round(numeric(state.landingStatus?.studentsTesting))),
      nodes: [...document.querySelectorAll('.live-status-strip dl > div:nth-child(2) dd')]
    }
  };
  const availableMetrics = Object.fromEntries(Object.entries(metrics).filter(([, metric]) => metric.nodes.length));
  if (!Object.keys(availableMetrics).length) return;
  const targets = Object.fromEntries(Object.entries(availableMetrics).map(([name, metric]) => [name, metric.target]));
  const starts = Object.fromEntries(Object.entries(targets).map(([metric]) => [metric,
    Object.prototype.hasOwnProperty.call(state.landingLiveValues, metric) ? numeric(state.landingLiveValues[metric]) : 0
  ]));
  const render = progress => {
    Object.entries(targets).forEach(([metric, target]) => {
      const value = Math.round(starts[metric] + ((target - starts[metric]) * progress));
      availableMetrics[metric].nodes.forEach(node => { node.textContent = value; });
    });
  };
  state.landingLiveValues = {...state.landingLiveValues, ...targets};
  if (window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) { render(1); return; }
  const startTime = performance.now(), duration = 600;
  const tick = now => {
    const elapsed = Math.min(1, (now - startTime) / duration);
    render(1 - Math.pow(1 - elapsed, 3));
    if (elapsed < 1) state.landingCountFrame = requestAnimationFrame(tick);
    else state.landingCountFrame = null;
  };
  render(0);
  state.landingCountFrame = requestAnimationFrame(tick);
}
function startLandingStepReveal() {
  state.landingStepObserver?.disconnect();
  state.landingStepObserver = null;
  const steps = [...document.querySelectorAll('.candidate-step-grid article')];
  if (!steps.length || state.landingStepsRevealPlayed || window.matchMedia?.('(prefers-reduced-motion: reduce)').matches) return;
  state.landingStepsRevealPlayed = true;
  if (!('IntersectionObserver' in window)) return;
  steps.forEach((step, index) => {
    step.classList.add('landing-step-await');
    step.style.setProperty('--landing-step-delay', `${index * 60}ms`);
  });
  const observer = new IntersectionObserver(entries => {
    entries.forEach(entry => {
      if (!entry.isIntersecting) return;
      entry.target.classList.add('landing-step-revealed');
      observer.unobserve(entry.target);
    });
  }, {threshold: .18});
  steps.forEach(step => observer.observe(step));
  state.landingStepObserver = observer;
}
function startLandingCountdowns() {
  clearInterval(state.landingCountdownId); state.landingCountdownId = null;
  clearInterval(state.loginCountdownId); state.loginCountdownId = null;
  const update = () => {
    document.querySelectorAll('[data-assessment-countdown]').forEach(badge => {
      const seconds = Math.max(0, Math.ceil((Date.parse(badge.dataset.assessmentCountdown || '') - Date.now()) / 1000));
      const minutes = Math.floor(seconds / 60), remainder = seconds % 60;
      badge.textContent = seconds ? `Closes in ${minutes}:${String(remainder).padStart(2, '0')}` : 'Closing now';
      badge.classList.toggle('critical', seconds > 0 && seconds <= 10 * 60);
    });
  };
  if (document.querySelector('[data-assessment-countdown]')) { update(); state.landingCountdownId = setInterval(update, 1000); }
  startHeroEntrance();
  startLandingLiveCountUp();
  startLandingStepReveal();
  startHeroWordRotation();
}
function examWindowCountdown(endAt) {
  const seconds = Math.max(0, Math.ceil((Date.parse(endAt || '') - Date.now()) / 1000));
  if (!seconds) return 'Window closing now';
  const hours = Math.floor(seconds / 3600), minutes = Math.floor((seconds % 3600) / 60), remainder = seconds % 60;
  return hours ? `${hours}h ${minutes}m remaining` : `${minutes}m ${String(remainder).padStart(2, '0')}s remaining`;
}
function startLoginCountdown() {
  clearInterval(state.loginCountdownId); state.loginCountdownId = null;
  const update = () => document.querySelectorAll('[data-login-countdown]').forEach(node => node.textContent = examWindowCountdown(node.dataset.loginCountdown));
  if (document.querySelector('[data-login-countdown]')) { update(); state.loginCountdownId = setInterval(update, 1000); }
}
function topbar(login = false) {
  const actions = login
    ? `${themeToggle()}<button class="invigilator-help-btn" type="button" data-action="invigilator-help">Invigilator help</button><span class="student-account-placeholder" aria-label="Student account placeholder">●</span>`
    : `${themeToggle()}<button class="portal-help-btn" type="button" data-action="candidate-rules" aria-label="Open candidate rules" title="Candidate rules">i</button>`;
  return `<header class="topbar">${brand(login ? 'Examination & verification center' : 'Assessment centre')}<div class="topbar-actions">${actions}</div></header>`;
}

async function render() {
  const serial = ++state.loadSerial;
  clearInterval(state.timerId); state.timerId = null;
  clearInterval(state.landingCountdownId); state.landingCountdownId = null;
  if (state.landingCountFrame != null) cancelAnimationFrame(state.landingCountFrame);
  state.landingCountFrame = null;
  state.landingStepObserver?.disconnect();
  state.landingStepObserver = null;
  clearTimeout(state.headlineRotationId); state.headlineRotationId = null;
  clearTimeout(state.availabilityRefreshId); state.availabilityRefreshId = null;
  clearTimeout(state.auditPollId); state.auditPollId = null;
  clearTimeout(state.approvalPollId); state.approvalPollId = null;
  const route = parts();
  const routeKey = route.join('/') || 'student/selection';
  if (routeKey !== 'student/selection') {
    state.landingHeroEntrancePlayed = false;
    state.landingLiveValues = {};
    state.landingStepsRevealPlayed = false;
  }
  state.route = routeKey;
  const isAdmin = route[0] === 'admin';
  if (isAdmin && !state.adminAuthenticated && ['register', 'forgot-password', 'reset-password', 'login'].includes(route[1])) {
    if (route[1] === 'register') { app.innerHTML = adminRequestPage(); return; }
    if (route[1] === 'forgot-password') { app.innerHTML = adminForgotPasswordPage(); return; }
    if (route[1] === 'reset-password') { app.innerHTML = adminResetPasswordPage(); return; }
    app.innerHTML = adminLoginPage();
    return;
  }
  app.innerHTML = loadingPage(isAdmin);
  try {
    if (isAdmin) await loadAdmin(route);
    else await loadStudent(route);
    if (serial !== state.loadSerial) return;
    app.innerHTML = isAdmin ? adminShell(adminPage(route)) : studentPage(route);
    if (isAdmin) setAdminSidebarOpen(state.sidebarOpen);
    if (!isAdmin && route[1] === 'exam') { startTimer(); startDevtoolsSignal(); }
    if (!isAdmin && route[1] === 'login') startLoginCountdown();
    if (!isAdmin && (route[1] || 'selection') === 'selection') startLandingCountdowns();
    // Refresh the same public assessment payload so windows and the live-status strip
    // stay current without inventing a separate monitoring feed.
    if (!isAdmin && (route[1] || 'selection') === 'selection') scheduleAvailabilityRefresh();
    if (isAdmin && route[1] === 'audit' && route[2] !== 'trail') scheduleAuditMonitorPoll();
    if (isAdmin && state.adminUser?.roleId === 'superadmin') scheduleApprovalBadgePoll();
  } catch (error) {
    if (serial !== state.loadSerial) return;
    if (isAdmin && error.status === 401) return;
    app.innerHTML = `${isAdmin ? '' : topbar()}<main class="center-page"><section class="auth-card"><h1>Unable to load</h1><p>${esc(error.message)}</p><button class="primary-btn" data-action="retry">Try again</button></section></main>`;
  }
}

function scheduleAvailabilityRefresh() {
  state.availabilityRefreshId = setTimeout(() => {
    state.availabilityRefreshId = null;
    if (currentRoute() === 'student/selection') render();
  }, 15000);
}
function scheduleAuditMonitorPoll() {
  state.auditPollId = setTimeout(async () => {
    state.auditPollId = null;
    if (currentRoute() !== 'admin/audit') return;
    try {
      state.auditMonitor = (await api('audit-monitor')).items || [];
      const rows = document.querySelector('#audit-monitor-rows');
      if (rows) rows.innerHTML = auditMonitorRows();
    } catch { /* The next route render will show authentication or network errors. */ }
    if (currentRoute() === 'admin/audit') scheduleAuditMonitorPoll();
  }, 5000);
}
function scheduleApprovalBadgePoll() {
  state.approvalPollId = setTimeout(async () => {
    state.approvalPollId = null;
    if (!state.adminAuthenticated || state.adminUser?.roleId !== 'superadmin') return;
    try {
      const response = await api('admin-approvals', {}, {summary: 'true'});
      state.pendingApprovalCount = numeric(response.summary?.pending);
      document.querySelectorAll('[data-approval-badge]').forEach(badge => {
        badge.textContent = state.pendingApprovalCount;
        badge.classList.toggle('is-empty', !state.pendingApprovalCount);
      });
    } catch { /* The next navigation will surface a session or connectivity error. */ }
    if (state.adminAuthenticated && state.adminUser?.roleId === 'superadmin') scheduleApprovalBadgePoll();
  }, 20000);
}

async function loadStudent(route) {
  const step = route[1] || 'selection';
  const activeAssessments = await api('exams', {}, {active: 'true'});
  state.exams = normalizeList('activeExams', activeAssessments);
  state.landingStatus = activeAssessments.liveStatus || {openComponents: state.exams.length, studentsTesting: 0, clientIp: 'Unavailable', singleSessionLockActive: false};
  state.activePeriod = activeAssessments.activePeriod || {sessionLabel: '', semesterLabel: ''};
  state.selectedExamId = route[2] || null;
  if (step !== 'selection' && !selectedExam()) throw new Error('This assessment is no longer available. Return to the assessment list.');
  if (step === 'confirm' && (!state.studentAccess || state.studentAccess.examId !== state.selectedExamId)) {
    navigate(`student/login/${state.selectedExamId}`); return;
  }
  if (step === 'exam') {
    if (!state.attempt || state.attempt.examId !== state.selectedExamId) {
      navigate(`student/login/${state.selectedExamId}`); return;
    }
    await resumeAttempt();
  }
}
async function resumeAttempt() {
  const attempt = state.attempt;
  let data;
  try { data = await post('session-resume', {sessionId: attempt.session.id}); }
  catch (error) {
    if (error.status !== 409) throw error;
    clearAttempt(); clearStudentAccess();
    navigate('student/selection');
    toast('This assessment has already been submitted.', 'info');
    return;
  }
  if (data.session?.status === 'locked') {
    state.attempt = {...attempt, session: data.session, answers: data.session.answers || attempt.answers || {}, flagged: data.session.flagged || attempt.flagged || []};
    saveStored('algeExamAttempt', state.attempt);
    return;
  }
  if (!data.session || data.session.status !== 'in_progress') {
    clearAttempt(); clearStudentAccess();
    navigate('student/selection');
    toast('That assessment has ended. Your answers were saved.', 'info');
    return;
  }
  state.attempt = {
    ...attempt,
    session: data.session,
    questions: data.questions || attempt.questions || [],
    answers: data.session.answers || data.answers || {},
    flagged: data.session.flagged || data.flagged || [],
    examId: attempt.examId
  };
  saveStored('algeExamAttempt', state.attempt);
  state.questionIndex = Math.max(0, Math.min(state.questionIndex, state.attempt.questions.length - 1));
  state.secondsLeft = Math.max(0, Math.ceil((Date.parse(data.session.endsAt) - Date.now()) / 1000));
  state.saveState = 'saved';
}
async function loadAdmin(route) {
  const page = route[1] || 'overview';
  // Roles can be changed while an administrator is signed in. Refresh the menu
  // permissions from the server so new workspaces appear without a forced logout.
  const accountSnapshot = await api('admin-account');
  if (accountSnapshot.account) {
    state.adminAuthenticated = true;
    state.adminUser = {...state.adminUser, id: accountSnapshot.account.id, name: accountSnapshot.account.name, email: accountSnapshot.account.email, roleId: accountSnapshot.account.roleId, role: accountSnapshot.account.roleName || state.adminUser?.role, permissions: accountSnapshot.account.permissions || []};
    saveStored('algeAdminUser', state.adminUser);
  }
  if (state.adminUser?.roleId === 'superadmin') {
    const summary = await api('admin-approvals', {}, {summary: 'true'});
    state.pendingApprovalCount = numeric(summary.summary?.pending);
  }
  if (page === 'overview') {
    state.dashboard = await api('dashboard', {}, {outcomePage: state.filters.outcomes.page, outcomePageSize: 5});
    state.meta.outcomes = state.dashboard.courseOutcomesMeta || {page: 1, pages: 1, total: (state.dashboard.courseOutcomes || []).length};
  }
  if (page === 'students') {
    const [students, exams] = await Promise.all([api('students', {}, listQuery('students')), api('exams', {admin: true}, {pageSize: 100})]);
    state.students = normalizeList('students', students); state.adminExams = exams.items || [];
  }
  if (page === 'exams') {
    const [courses, sessions, semesters] = await Promise.all([api('courses', {}, listQuery('exams')), api('academic-sessions'), api('semesters')]);
    state.courses = normalizeList('exams', courses); state.academicSessions = sessions.items || []; state.semesters = semesters.items || [];
  }
  if (page === 'questions') {
    const examId = route[2];
    const [exams, questions] = await Promise.all([api('exams', {admin: true}, {pageSize: 100}), api('questions', {}, {...listQuery('questions'), examId})]);
    state.adminExams = exams.items || []; state.questions = normalizeList('questions', questions);
  }
  if (page === 'results') {
    const response = await api('results', {}, {...listQuery('results'), ...resultPeriodParts()});
    state.results = normalizeList('results', response); state.resultPeriods = response.periods || [];
  }
  if (page === 'settings') {
    const [settings, sessions, semesters] = await Promise.all([api('settings'), api('academic-sessions'), api('semesters')]);
    state.settings = settings.settings; state.academicSessions = sessions.items || []; state.semesters = semesters.items || [];
  }
  if (page === 'backups') state.backups = await api('backups');
  if (page === 'audit') {
    if (route[2] === 'trail') { const events = await api('audit-events', {}, auditQuery()); state.auditEvents = events.items || []; state.meta.audit = events.meta || {}; }
    else state.auditMonitor = (await api('audit-monitor')).items || [];
  }
  if (page === 'roles') {
    const [roles, users] = await Promise.all([api('roles'), api('admin-users')]);
    state.roles = roles.items || []; state.adminUsers = users.items || [];
  }
  if (page === 'users') {
    const [roles, users] = await Promise.all([api('roles'), api('admin-users')]);
    state.roles = roles.items || []; state.adminUsers = users.items || [];
  }
  if (page === 'approvals') {
    const [approvals, roles] = await Promise.all([api('admin-approvals'), api('roles')]);
    state.adminApprovals = approvals; state.roles = roles.items || [];
  }
  if (page === 'newsletter') {
    const [subscribers, newsletters] = await Promise.all([api('newsletter-subscribers', {}, listQuery('newsletter')), api('newsletters')]);
    state.newsletterSubscribers = normalizeList('newsletter', subscribers); state.newsletterStats = subscribers.stats || {}; state.newsletters = newsletters.items || []; state.newsletterMailConfigured = Boolean(newsletters.mailConfigured);
  }
  if (page === 'student-results' && route[2]) {
    const [report, settings] = await Promise.all([api('student-results', {}, {id: route[2], sessionId: route[3] || '', semesterId: route[4] || ''}), api('settings')]);
    state.report = report; state.settings = settings.settings;
  }
  if (page === 'review' && route[2]) state.review = await api('result-review', {}, {id: route[2]});
}

function studentPage(route) {
  const step = route[1] || 'selection';
  if (step === 'exam') return examPage();
  return `${topbar(step === 'login')}${step === 'login' ? studentLoginPage() : step === 'confirm' ? briefingPage() : selectionPage()}`;
}
function assessmentCategory(exam) { return String(exam.category || '').trim() || 'Uncategorized'; }
function assessmentType(exam) { return String(exam.component || 'exam').toLowerCase() === 'test' ? 'test' : 'exam'; }
function candidateRulesModal() {
  openModal('Candidate rules', `<div class="candidate-rules"><p>These rules protect every candidate and keep the assessment fair.</p><ol><li>Use only the device and browser approved for this assessment.</li><li>Do not switch tabs, copy, paste, use developer tools, or open other applications.</li><li>Keep your connection open and submit before the timer reaches zero.</li><li>Report any technical problem to your invigilator immediately.</li></ol><div class="modal-actions"><button class="primary-btn" data-action="close-modal">I understand</button></div></div>`);
}
function invigilatorHelpModal() {
  openModal('Invigilator help', '<p>If you cannot access the assessment, contact your hall invigilator. They can confirm your registration and regenerate an assessment password where appropriate.</p><div class="modal-actions"><button class="primary-btn" data-action="close-modal">I understand</button></div>');
}
function selectionPage() {
  const canResume = state.attempt?.session?.id && state.attempt?.examId && state.exams.some(exam => exam.id === state.attempt.examId);
  const all = state.exams;
  const categories = [...new Set(all.map(assessmentCategory))].sort((a, b) => a.localeCompare(b));
  const filter = state.landingFilters;
  const query = filter.search.trim().toLowerCase();
  const filtered = all.filter(exam => (filter.type === 'all' || assessmentType(exam) === filter.type) && (filter.category === 'all' || assessmentCategory(exam) === filter.category) && (!query || `${exam.code || ''} ${exam.title || ''} ${exam.description || ''}`.toLowerCase().includes(query)));
  const period = [state.activePeriod?.semesterLabel, state.activePeriod?.sessionLabel].filter(Boolean).join(' · ') || 'Active academic period';
  const typeCount = type => all.filter(exam => assessmentType(exam) === type).length;
  const pill = (label, count, type = 'all', category = 'all') => `<button class="assessment-filter ${filter.type === type && filter.category === category ? 'active' : ''}" data-action="landing-filter" data-filter-type="${esc(type)}" data-filter-category="${esc(category)}">${esc(label)} <b>${numeric(count)}</b></button>`;
  const empty = emptyState(all.length ? 'No open assessments match your search or filter.' : 'No assessments are open right now. An assessment must be Active and within its scheduled start and end time. This page checks again automatically.', '<button class="outline-btn" data-action="refresh-exams">Refresh assessments</button>');
  const cards = filtered.map((exam, index) => {
    const component = assessmentType(exam), urgent = assessmentUrgency(exam);
    const status = urgent ? `<span class="assessment-window urgent" data-assessment-countdown="${esc(exam.endAt)}">Closing soon</span>` : '<span class="assessment-window"><i></i> Window open</span>';
    return `<article class="assessment-directory-card assessment-card-${component}" style="--card-delay:${index * 50}ms"><div class="assessment-directory-top"><div><span class="assessment-type-badge ${component}">${component === 'test' ? 'CA test' : 'Final exam'}</span><span class="assessment-category-tag">${esc(assessmentCategory(exam))}</span></div>${status}</div><div class="assessment-directory-heading"><span class="course-icon" aria-hidden="true">${assessmentIcon(exam)}</span><h3>${esc(exam.code)} --- ${esc(exam.title)}</h3></div><p>${esc(exam.description || 'Assessment details are provided by your academic coordinator.')}</p><dl class="assessment-stat-row"><div><dt>Questions</dt><dd>${numeric(exam.availableQuestionCount, numeric(exam.questionCount))}</dd></div><div><dt>Duration</dt><dd>${numeric(exam.duration)}m</dd></div><div><dt>Weight</dt><dd>${numeric(exam.maxMark)}%</dd></div><div><dt>Window</dt><dd>${esc(exam.window || 'Open now')}</dd></div></dl><div class="assessment-directory-footer"><span>${esc(exam.componentLabel || 'Exam')} component</span><button class="primary-btn" data-route="student/login/${esc(exam.id)}">Proceed to login →</button></div></article>`;
  }).join('');
  return `<main class="landing landing-directory"><div class="landing-inner"><section class="portal-hero hero-entrance-pending"><div class="portal-hero-copy"><img class="portal-hero-watermark" src="CACSA%20Logo.jpeg" alt="" aria-hidden="true"><div class="portal-period hero-entrance-item hero-entrance-eyebrow">Student portal <span>${esc(period)}</span></div><h1 class="hero-entrance-item hero-entrance-headline">Your next <span class="hero-rotating-word" data-hero-rotating-word>breakthrough</span><br>starts here.</h1><p class="hero-entrance-item hero-entrance-copy">Select an available Test or Exam below. You will need your official university matriculation number and the unique component password issued by your administrator.</p><div class="portal-hero-actions hero-entrance-item hero-entrance-actions"><button class="primary-btn" data-action="scroll-assessments">View active assessments ↓</button><button class="outline-btn" data-action="candidate-rules">Candidate rules</button></div></div><div class="portal-hero-visual hero-entrance-item hero-entrance-visual"><img src="student-exam-lab-hero.png" alt="Students taking a computer-based assessment"><aside class="portal-hero-status"><div><strong>Assessments available now</strong><span class="live-indicator">Live</span></div><dl><div><dt>Open components</dt><dd>${numeric(state.landingStatus?.openComponents, all.length)}</dd></div><div><dt>Timing</dt><dd>Synchronized with Central LAUTECH server</dd></div><div><dt>Security protocol</dt><dd><em>Terminal lock active</em></dd></div></dl></aside></div></section><section class="candidate-step-grid" aria-label="How to take an assessment"><article><b>01</b><div><h2>Select course</h2><p>Identify your registered course code below and verify the scheduled exam duration and window.</p></div></article><article><b>02</b><div><h2>Supply credentials</h2><p>Input your university matric number and the component password announced by your invigilator.</p></div></article><article><b>03</b><div><h2>Launch workstation</h2><p>The secure lockdown interface takes over. Do not close the browser or toggle tabs during the test.</p></div></article></section>${canResume ? `<section class="panel resume-banner"><strong>You have an assessment in progress.</strong><button class="primary-btn" data-route="student/exam/${esc(state.attempt.examId)}">Resume assessment</button></section>` : ''}<section class="assessment-directory" id="active-assessments"><div class="assessment-directory-header"><div><div class="section-kicker">Live examination sessions</div><h2>Available assessments</h2><p>Each Test and Exam is entered separately while its active window is open.</p></div><label class="assessment-search"><span class="sr-only">Search assessments</span><input type="search" data-assessment-search value="${esc(filter.search)}" placeholder="Search code e.g. MTH 201, CHM…"></label></div><div class="assessment-filter-row">${pill('All assessments', all.length)}${pill('Final examinations', typeCount('exam'), 'exam')}${pill('Continuous assessment tests', typeCount('test'), 'test')}${categories.map(category => pill(category, all.filter(exam => assessmentCategory(exam) === category).length, 'all', category)).join('')}</div><div class="assessment-directory-grid">${cards || empty}</div></section><section class="live-status-strip" aria-label="Live CBT status"><span class="live-status-icon" aria-hidden="true">▣</span><div><strong>CBT session status <em><i></i> Live</em></strong><span>Real-time activity from the assessment service</span></div><dl><div><dt>Open components</dt><dd>${numeric(state.landingStatus?.openComponents, all.length)}</dd></div><div><dt>Students currently testing</dt><dd>${numeric(state.landingStatus?.studentsTesting)}</dd></div></dl></section></div><footer class="portal-footer"><span>LAUTECH Academic Directorate Certified Node</span><span>Assessment timing supplied by the CBT service</span><span>© ${new Date().getFullYear()} CACSA LAUTECH. All rights reserved.</span></footer></main>`;
}
function refreshLandingPage() {
  const current = document.querySelector('.landing-directory');
  if (!current) return render();
  const template = document.createElement('template'); template.innerHTML = selectionPage().trim();
  current.replaceWith(template.content.firstElementChild);
  startLandingCountdowns();
}
function studentLoginPage() {
  const exam = selectedExam();
  if (!exam) return missingSelection();
  const component = exam.componentLabel || (exam.component === 'test' ? 'Test' : 'Exam');
  const period = [state.activePeriod?.semesterLabel, state.activePeriod?.sessionLabel].filter(Boolean).join(' · ') || 'Current academic period';
  const passThreshold = numeric(exam.passThreshold, numeric(exam.maxMark) * .5);
  const singleSessionLock = Boolean(state.landingStatus?.singleSessionLockActive);
  const questionMode = exam.questionMode || 'Question format verified on start';
  return `<main class="student-authentication-page"><div class="auth-breadcrumb"><button class="back-link" data-route="student/selection">← Assessments directory</button><span>/</span><span>${esc(period)}</span><span>/</span><b>Candidate authentication</b></div><div class="student-authentication-grid"><section class="candidate-auth-card"><div class="candidate-auth-heading"><div><h1>Candidate authentication <span aria-hidden="true">✿</span></h1><p>Enter your official LAUTECH matriculation number and the 8-character passcode generated for you by the administrator.</p></div><span class="single-session-badge ${singleSessionLock ? 'active' : ''}"><i></i>${singleSessionLock ? 'Single session lock active' : 'Single session protection unavailable'}</span></div><div class="allocated-assessment"><span aria-hidden="true">▣</span><div><small>Allocated assessment</small><strong>${esc(exam.code)} --- ${esc(exam.title)} <em>${esc(component)}</em></strong></div><button class="text-button" type="button" data-route="student/selection">Change course →</button></div><div id="alert" class="alert" role="alert"></div><form id="student-login-form"><label class="auth-login-field">LAUTECH matriculation number<input name="matricNumber" autocomplete="username" placeholder="2023007387" required><small>Use your standard matriculation format.</small></label><label class="auth-login-field">8-character assessment passcode${passwordInput('student-exam-password', 'one-time-code', 'minlength="8" maxlength="8" required')}<small>Case-sensitive.</small></label><section class="connection-identity"><div><strong>Connection identity</strong><span>Server-detected address for this sign-in attempt</span></div><b>${esc(state.landingStatus?.clientIp || 'Unavailable')}</b></section><button class="primary-btn authentication-submit">Authenticate & proceed to briefing <span aria-hidden="true">→</span></button></form><div class="auth-help-actions"><button type="button" data-action="invigilator-help">Having trouble with credentials?</button><button type="button" data-action="invigilator-help">Raise hand / contact hall invigilator</button></div></section><aside class="candidate-assessment-summary"><div class="component-summary-badges"><span>${esc(period)} ${component === 'Test' ? 'continuous assessment' : 'final examination'}</span><b>Weight: ${numeric(exam.maxMark)}%</b></div><h2>${esc(exam.code)} --- ${esc(exam.title)}</h2><p>${esc(exam.category || 'Course category')} · ${esc(component)} component</p><dl class="candidate-assessment-stats"><div><dt>Total scope</dt><dd>${numeric(exam.availableQuestionCount, numeric(exam.questionCount))} <small>${esc(questionMode)}</small></dd></div><div><dt>Duration</dt><dd>${numeric(exam.duration)} min <small>Server-timed component</small></dd></div><div><dt>Pass threshold</dt><dd>${passThreshold} / ${numeric(exam.maxMark)} <small>Set by your coordinator</small></dd></div><div><dt>Window closes</dt><dd class="login-window-countdown" data-login-countdown="${esc(exam.endAt)}">${examWindowCountdown(exam.endAt)}</dd><small>${esc(exam.window || '')}</small></div></dl></aside><aside class="candidate-protocols"><h2><span aria-hidden="true">⛨</span> Active session protocols</h2><ol><li><b>1</b><div><strong>Server-synchronized clock</strong><p>The countdown starts when you begin. Closing this browser tab does not pause the server-side timer.</p></div></li><li><b>2</b><div><strong>Screen-change monitoring</strong><p>Tab switches and browser blur events are logged. Your institution’s policy may warn you or lock further answering after repeat events.</p></div></li><li><b>3</b><div><strong>Continuous auto-save sync</strong><p>Every saved answer is sent to the server so a temporary interruption does not remove recorded responses.</p></div></li></ol></aside></div><section class="candidate-conduct-note"><span aria-hidden="true">◎</span><p>By proceeding, you agree to follow the LAUTECH CBT Code of Academic Conduct. Unauthorized materials, communication, or repeated screen changes may lead to a recorded integrity review.</p></section><footer class="candidate-auth-footer"><span>LAUTECH Academic Integrity & Assessment Directorate</span><span>© ${new Date().getFullYear()} Ladoke Akintola University of Technology · CACSA Campus Chapter CBT Center.</span></footer></main>`;
}
function calculatorResult(expression) {
  const value = String(expression || '').trim();
  if (!value || !/^[0-9+\-*/%.()\s]+$/.test(value)) return null;
  try { const result = Function(`"use strict"; return (${value})`)(); return Number.isFinite(result) ? Number(result.toFixed(10)).toString() : null; }
  catch { return null; }
}
function updateCalculator(key) {
  const current = String(state.calculatorValue || '0');
  if (key === 'clear') state.calculatorValue = '0';
  else if (key === 'back') state.calculatorValue = current.length > 1 ? current.slice(0, -1) : '0';
  else if (key === 'equals') state.calculatorValue = calculatorResult(current) ?? 'Error';
  else if (key === 'sqrt') { const value = Number(calculatorResult(current)); state.calculatorValue = Number.isFinite(value) && value >= 0 ? Math.sqrt(value).toString() : 'Error'; }
  else if (key === 'percent') { const value = Number(calculatorResult(current)); state.calculatorValue = Number.isFinite(value) ? (value / 100).toString() : 'Error'; }
  else if (key === 'sign') state.calculatorValue = current === '0' ? '0' : current.startsWith('-') ? current.slice(1) : `-${current}`;
  else if (/^[0-9.+\-*/%()]$/.test(key)) state.calculatorValue = current === '0' && /^[0-9.]$/.test(key) ? key : `${current === 'Error' ? '' : current}${key}`;
  const display = document.querySelector('[data-calculator-display]'); if (display) display.textContent = state.calculatorValue;
}
function calculatorWidget() {
  const keys = [['clear','C'], ['back','⌫'], ['percent','%'], ['/','÷'], ['7','7'], ['8','8'], ['9','9'], ['*','×'], ['4','4'], ['5','5'], ['6','6'], ['-','−'], ['1','1'], ['2','2'], ['3','3'], ['+','+'], ['sign','+/-'], ['0','0'], ['.','.'], ['equals','='], ['sqrt','√']];
  return `<aside class="exam-calculator" ${state.calculatorOpen ? '' : 'hidden'} aria-label="Scratch calculator"><div><strong>Scratch calculator</strong><button type="button" data-action="toggle-calculator" aria-label="Close calculator">×</button></div><output data-calculator-display>${esc(state.calculatorValue)}</output><div class="calculator-keys">${keys.map(([key, label]) => `<button type="button" data-action="calculator-key" data-calculator-key="${key}" class="${key === 'equals' ? 'equals' : ''}">${label}</button>`).join('')}</div><small>Scratch calculations are not saved or submitted.</small></aside>`;
}
function resetScratchCalculator() {
  state.calculatorOpen = false;
  state.calculatorValue = '0';
}
function briefingPage() {
  const exam = selectedExam();
  if (!exam) return missingSelection();
  return `<main class="exam-prestart-page"><section class="exam-prestart-card"><span class="prestart-icon" aria-hidden="true">▶</span><div class="eyebrow">Assessment briefing</div><h1>Are you ready to begin?</h1><p><strong>${esc(exam.code)} --- ${esc(exam.title)}</strong> will begin only when you choose Start assessment. The server timer cannot be paused after that point.</p><dl><div><dt>Questions</dt><dd>${numeric(exam.availableQuestionCount, numeric(exam.questionCount))}</dd></div><div><dt>Duration</dt><dd>${numeric(exam.duration)} min</dd></div><div><dt>Marks</dt><dd>${numeric(exam.maxMark)}</dd></div></dl><div class="prestart-note"><strong>Before you start</strong><span>Your answers are autosaved to the server. Tab switches and browser blur events are recorded under your institution’s integrity policy.</span></div><div class="prestart-actions"><button class="outline-btn" data-route="student/selection">Cancel</button><button class="primary-btn" data-action="start-exam">Start assessment →</button></div></section></main>`;
}
function missingSelection() { return '<main class="center-page"><section class="auth-card"><h1>Assessment unavailable</h1><button class="primary-btn" data-route="student/selection">Back to assessments</button></section></main>'; }
function examPage() {
  const attempt = state.attempt, exam = selectedExam();
  if (attempt?.session?.status === 'locked') return lockedExamPage(exam, attempt);
  const questions = attempt?.questions || [];
  const question = questions[state.questionIndex];
  if (!attempt || !exam || !question) return missingSelection();
  const selected = attempt.answers?.[question.id] || [];
  const isFlagged = (attempt.flagged || []).includes(question.id);
  const student = state.studentAccess?.student || attempt.student || {};
  const studentName = student.fullName || 'Candidate';
  const studentMatric = student.matricNumber || 'Authenticated student';
  const answeredCount = questions.filter(item => (attempt.answers?.[item.id] || []).length).length;
  const flaggedCount = (attempt.flagged || []).length;
  const unansweredCount = questions.length - answeredCount;
  const progress = questions.length ? Math.round((answeredCount / questions.length) * 100) : 0;
  const save = saveStateMeta();
  const answerInstruction = question.type === 'multiple' ? 'Select all correct answers' : 'Choose one answer';
  const sessionReference = String(attempt.session?.id || '').slice(-8).toUpperCase() || 'Unavailable';
  const perQuestionMark = questions.length ? (numeric(exam.maxMark) / questions.length).toFixed(1).replace(/\.0$/, '') : '0';
  const sessionSecured = Boolean(state.landingStatus?.singleSessionLockActive);
  return `<main class="exam-screen exam-workspace" style="--exam-text-scale:${state.examTextScale}"><header class="exam-workspace-header"><div class="exam-brand-block">${brand('Harmattan semester examination')}<span class="exam-security-badge ${sessionSecured ? 'active' : ''}"><i></i>${sessionSecured ? 'Session secured' : 'Session protection limited'}</span></div><div class="exam-candidate-card" aria-label="Candidate ${esc(studentName)}, matric number ${esc(studentMatric)}"><span class="candidate-avatar" aria-hidden="true">${esc(candidateInitials(studentName))}</span><span><strong>${esc(studentName)}</strong><small>Matric: ${esc(studentMatric)} · ${esc(student.department || 'Department not set')}</small><small>IP: ${esc(displayIp(attempt.session?.ipAddress || state.landingStatus?.clientIp || 'Unavailable'))}</small></span></div></header><div class="exam-session-reference">Session reference: <b>${esc(sessionReference)}</b><span data-save-state>${save.label}</span></div><div class="exam-workspace-layout"><section class="exam-main-column"><section class="exam-overview-card"><div><h1><span>${esc(exam.code)}</span> ${esc(exam.title)}</h1><p>Marks: ${perQuestionMark} per question · Question <b>${state.questionIndex + 1}</b> of ${questions.length} · Attempted: <b>${answeredCount}/${questions.length}</b> · Progress: <b>${progress}%</b></p></div><div class="workspace-timer"><span>Remaining time</span><strong id="timer" class="${timerClass()}" aria-live="off">${fmtTime(state.secondsLeft)}</strong></div></section><nav class="exam-workspace-toolbar" aria-label="Assessment tools"><button class="exam-tool-btn ${isFlagged ? 'active' : ''}" data-action="flag-question" aria-pressed="${isFlagged}">⚑ ${isFlagged ? 'Flagged for review' : 'Flag for review'}</button><button class="exam-tool-btn" data-action="toggle-calculator" aria-expanded="${state.calculatorOpen}">▣ Scratch calculator</button><div class="font-size-control" aria-label="Question text size"><button data-action="exam-font-size" data-font-change="-0.05" aria-label="Decrease question text size">A−</button><span>Aa</span><button data-action="exam-font-size" data-font-change="0.05" aria-label="Increase question text size">A+</button></div></nav><section class="workspace-question-card"><div class="workspace-question-heading"><span><b>${state.questionIndex + 1}</b> Question</span><small id="save-status" role="status" aria-live="polite">${selected.length ? 'Answer saved' : 'Not answered'} · ${answerInstruction}</small></div><h2>${esc(question.text)}</h2><div class="option-list workspace-option-list">${(question.options || []).map((option, index) => `<label class="option ${selected.includes(index) ? 'selected' : ''}"><input type="${question.type === 'multiple' ? 'checkbox' : 'radio'}" name="answer" value="${index}" ${selected.includes(index) ? 'checked' : ''}><span class="option-label">${String.fromCharCode(65 + index)}</span><span>${esc(option)}</span><i class="option-selected-indicator" aria-hidden="true"></i></label>`).join('')}</div>${calculatorWidget()}<div class="workspace-question-actions"><button class="outline-btn" data-action="previous-question" ${!state.questionIndex ? 'disabled' : ''}>← Previous question</button><button class="danger-btn" data-action="submit-prompt">Submit exam session</button>${state.questionIndex === questions.length - 1 ? '<button class="primary-btn" data-action="submit-prompt">Review & submit →</button>' : '<button class="primary-btn" data-action="next-question">Next question →</button>'}</div></section></section><aside class="workspace-palette"><div class="palette-heading"><h2>▦ Question palette</h2><span>Total: ${questions.length} questions</span></div><div class="palette-legend"><span><i class="answered"></i> Answered (${answeredCount})</span><span><i class="flagged"></i> Flagged (${flaggedCount})</span><span><i class="unanswered"></i> Unanswered (${unansweredCount})</span></div><div class="question-grid">${questions.map((item, index) => `<button class="question-number ${index === state.questionIndex ? 'current' : ''} ${(attempt.answers?.[item.id] || []).length ? 'answered' : ''} ${(attempt.flagged || []).includes(item.id) ? 'flagged' : ''}" data-question-index="${index}" aria-label="Question ${index + 1}${(attempt.answers?.[item.id] || []).length ? ', answered' : ', unanswered'}${(attempt.flagged || []).includes(item.id) ? ', flagged' : ''}">${index + 1}</button>`).join('')}</div></aside></div><footer class="exam-workspace-footer">© ${new Date().getFullYear()} Christian Association of Computer Science Assessment (CACSA) · Ladoke Akintola University of Technology Center</footer></main>`;
}
function lockedExamPage(exam, attempt) {
  const reason = String(attempt?.session?.lockedReason || 'integrity event').replaceAll('_', ' ');
  return `<main class="exam-screen locked-screen"><header class="exam-topbar"><div class="exam-topbar-primary">${brand()}<i class="exam-header-divider" aria-hidden="true"></i><div class="exam-title"><h1>${esc(exam?.displayTitle || exam?.title || 'Assessment')}</h1><p>${esc(exam?.code || '')} · Session locked</p></div></div></header><section class="exam-locked" aria-labelledby="locked-title"><div class="locked-card-header"><span class="locked-alert-icon" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M12 8v5M12 17h.01M10.2 3.9 2.9 17.1A2 2 0 0 0 4.7 20h14.6a2 2 0 0 0 1.8-2.9L13.8 3.9a2 2 0 0 0-3.6 0Z"/></svg></span><div><div class="eyebrow">Session locked</div><span class="locked-status">Answering disabled</span></div></div><h1 id="locked-title">Your assessment is secure.</h1><p class="locked-lead">We paused answer changes after a suspicious-activity signal: <strong>${esc(reason)}.</strong> Your invigilator can review the session and advise on the next step.</p><div class="locked-next-steps"><div><span class="locked-step-number">1</span><p><strong>Your answers are preserved</strong><span>Everything saved before the lock remains on the server.</span></p></div><div><span class="locked-step-number">2</span><p><strong>Contact your invigilator</strong><span>They can review the session record in the Audit Log.</span></p></div></div><div class="locked-reassurance"><span aria-hidden="true">✓</span><div><strong>No further action is needed here</strong><p>You cannot change answers while this session is locked. Please wait for your invigilator’s instruction.</p></div></div><button class="outline-btn wide locked-refresh" data-action="check-session-status">Check whether my session was unlocked</button></section></main>`;
}
function timerClass() { return state.secondsLeft <= 60 ? 'critical' : state.secondsLeft <= 300 ? 'warning' : ''; }
function startTimer() {
  if (!state.attempt?.session?.endsAt) return;
  const update = () => {
    state.secondsLeft = Math.max(0, Math.ceil((Date.parse(state.attempt.session.endsAt) - Date.now()) / 1000));
    const element = document.querySelector('#timer');
    if (element) { element.textContent = fmtTime(state.secondsLeft); element.className = `timer ${timerClass()}`; }
    if (state.secondsLeft === 0) {
      clearInterval(state.timerId); state.timerId = null;
      submitExam(true);
    }
  };
  update();
  if (state.secondsLeft > 0) state.timerId = setInterval(update, 1000);
}
function attemptPayload(extra = {}) {
  return {sessionId: state.attempt?.session?.id, ...extra};
}
async function submitExam(auto = false) {
  if (!state.attempt?.session?.id || state.submitting) return;
  state.submitting = true;
  try {
    await post('session-submit', attemptPayload({auto}));
    closeModal(); clearAttempt(); clearStudentAccess();
    navigate('student/selection');
    toast(auto ? 'Time is up. Your assessment was submitted.' : 'Your assessment was submitted successfully.', 'success');
  } catch (error) {
    showError(error);
    if (auto) openModal('Submission needs attention', `<p>The time has expired, but we could not confirm submission with the server. Keep this page open and try again.</p><div class="modal-actions"><button class="primary-btn" data-action="retry-submit">Retry submission</button></div>`, {closeable: false});
  } finally { state.submitting = false; }
}

function mathKeyboard() {
  const groups = [
    ['Powers', [['x²', '²'], ['x³', '³'], ['xⁿ', '^()', 1], ['y′', '′'], ['y″', '″']]],
    ['Calculus', [['dy/dx', 'dy/dx'], ['d²y/dx²', 'd²y/dx²'], ['∂y/∂x', '∂y/∂x'], ['∫', '∫() dx', 4], ['lim', 'lim x→∞']]],
    ['Functions', [['√', '√()', 1], ['a/b', '()/()', 4], ['sin', 'sin()', 1], ['cos', 'cos()', 1], ['log', 'log()', 1], ['ln', 'ln()', 1]]],
    ['Symbols', [['π', 'π'], ['θ', 'θ'], ['±', '±'], ['≤', '≤'], ['≥', '≥'], ['≠', '≠'], ['∞', '∞'], ['Σ', 'Σ'], ['×', '×'], ['÷', '÷']]]
  ];
  return `<section class="math-keyboard" aria-label="Mathematics keyboard"><div class="math-keyboard-heading"><div><strong>Mathematics keyboard</strong><span>Choose a field, then a key to insert a symbol.</span></div><span class="math-target">Editing: <b data-math-label>Question text</b></span></div><p class="math-keyboard-tip">For any power, use <b>xⁿ</b> and type the exponent between the parentheses.</p><div class="math-key-groups">${groups.map(([title, keys]) => `<div class="math-key-group"><span>${title}</span><div>${keys.map(([label, value, back = 0]) => `<button type="button" class="math-key" data-math-insert="${esc(value)}" data-math-back="${back}">${esc(label)}</button>`).join('')}</div></div>`).join('')}</div><div class="math-preview-wrap"><span>Live preview</span><output class="math-preview" data-math-preview>Start typing a mathematical expression.</output></div></section>`;
}
let activeMathField = null;
function updateMathPreview(field) {
  if (!field) return;
  activeMathField = field;
  document.querySelectorAll('[data-math-label]').forEach(node => node.textContent = field.dataset.mathField || 'selected field');
  document.querySelectorAll('[data-math-preview]').forEach(node => {
    if (field.value.trim()) node.innerHTML = mathHtml(field.value);
    else node.textContent = 'Start typing a mathematical expression.';
  });
}
function insertMath(button) {
  const field = activeMathField && document.body.contains(activeMathField) ? activeMathField : document.querySelector('[data-math-field]');
  if (!field) return;
  const value = button.dataset.mathInsert || '';
  const start = field.selectionStart ?? field.value.length;
  const end = field.selectionEnd ?? start;
  field.value = `${field.value.slice(0, start)}${value}${field.value.slice(end)}`;
  const position = start + value.length - numeric(button.dataset.mathBack);
  field.focus(); field.setSelectionRange(position, position);
  field.dispatchEvent(new Event('input', {bubbles: true}));
}

function adminLoginPage() {
  const notice = sessionStorage.getItem('algeAdminAuthNotice') || '';
  const rememberedEmail = localStorage.getItem('algeAdminRememberedEmail') || '';
  sessionStorage.removeItem('algeAdminAuthNotice');
  return `<main class="admin-login-page"><section class="admin-login-visual"></section><section class="center-page"><section class="admin-auth-card login-pattern-card"><p class="auth-caption">Sign in to your account</p>${notice ? `<div class="login-success-alert" role="status"><span aria-hidden="true">✓</span>${esc(notice)}</div>` : ''}<form id="admin-login-form"><div class="alert" role="alert"></div><label class="form-field">Email Address<input name="email" type="email" autocomplete="username" value="${esc(rememberedEmail)}" placeholder="admin@example.com" required></label><label class="form-field">Password${passwordInput('admin-password', 'current-password', 'required')}</label><div class="auth-options"><label class="remember-control"><input name="remember" type="checkbox" ${rememberedEmail ? 'checked' : ''}> <span>Remember email</span></label><button class="auth-link" type="button" data-route="admin/forgot-password">Forgot password?</button></div><button class="primary-btn wide auth-submit">Sign In</button></form><p class="form-note">Don't have an account? <button class="text-button" data-route="admin/register">Create account</button></p></section></section></main>`;
}
function adminForgotPasswordPage() {
  return `<main class="admin-login-page"><section class="admin-login-visual"></section><section class="center-page"><section class="admin-auth-card reset-pattern-card"><div class="reset-key-icon" aria-hidden="true">⌕</div><h1>Reset Your Password</h1><p>Enter your email address and we'll send a six-digit code to reset your password.</p><form id="admin-password-reset-request-form"><div class="alert" role="alert"></div><label class="form-field">Email Address<input name="email" type="email" autocomplete="email" placeholder="admin@example.com" required></label><button class="primary-btn wide auth-submit">Send Reset Code</button></form><div class="reset-explainer"><strong>ⓘ What happens next?</strong><span>We'll verify that this is an active administrator account, then email a secure six-digit code. The code expires in 15 minutes.</span></div><p class="form-note">Remember your password? <button class="text-button" data-route="admin/login">Back to Sign In</button></p><p class="auth-muted-action">Superadmin recovery is handled by the protected system-owner process.</p></section></section></main>`;
}
function adminResetPasswordPage() {
  const email = state.passwordResetEmail;
  return `<main class="admin-login-page"><section class="admin-login-visual"></section><section class="center-page"><section class="admin-auth-card reset-pattern-card"><div class="reset-key-icon" aria-hidden="true">⌕</div><h1>Enter your reset code</h1><p>Enter the six-digit code sent to your active account, then choose a new password.</p><form id="admin-password-reset-confirm-form"><div class="alert" role="alert"></div><label class="form-field">Email Address<input name="email" type="email" autocomplete="email" value="${esc(email)}" placeholder="admin@example.com" required></label><label class="form-field">Six-digit code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="123456" required></label><label class="form-field">New password${passwordInput('admin-reset-password', 'new-password', 'minlength="8" required')}</label><label class="form-field">Confirm new password${passwordInput('admin-reset-confirm-password', 'new-password', 'minlength="8" required', 'confirmPassword')}</label><button class="primary-btn wide auth-submit">Reset Password</button></form><div class="reset-explainer"><strong>ⓘ Security safeguard</strong><span>After a successful reset, this administrator account is suspended. A Superadmin must reactivate it before the next sign-in.</span></div><p class="form-note"><button class="text-button" data-route="admin/forgot-password">Request another code</button> · <button class="text-button" data-route="admin/login">Back to Sign In</button></p></section></section></main>`;
}
function adminRequestPage() {
  return `<main class="admin-login-page"><section class="admin-login-visual"></section><main class="center-page"><section class="auth-card"><button class="back-link" data-route="admin/login">← Back to sign in</button><div class="eyebrow" style="margin-top:28px">Administrator access request</div><h1>Request an account.</h1><p>Submit your details for Superadmin review. You cannot sign in until a role has been assigned and approval is emailed to you.</p><div id="alert" class="alert" role="alert"></div><form id="admin-registration-form"><label class="form-field">Full name<input name="name" autocomplete="name" required></label><label class="form-field">Email<input name="email" type="email" autocomplete="email" required></label><label class="form-field">Phone number<input name="phoneNumber" type="tel" inputmode="tel" autocomplete="tel" placeholder="e.g. 0801 234 5678" required></label><label class="form-field">Password${passwordInput('admin-request-password', 'new-password', 'minlength="8" required')}</label><label class="form-field">Confirm password<input name="confirmPassword" type="password" autocomplete="new-password" minlength="8" required></label><button class="primary-btn wide">Submit access request</button></form></section></main></main>`;
}
function adminLoginVisual() { return adminLoginVisualA() + adminLoginVisualB() + adminLoginVisualC(); }
function adminLoginForm() { return ''; }

function adminShell(content) {
  const page = parts()[1] || 'overview';
  const active = page === 'student-results' ? 'students' : page === 'review' ? 'results' : page;
  const approvalTitle = `Pending Approval <span class="nav-badge ${state.pendingApprovalCount ? '' : 'is-empty'}" data-approval-badge>${numeric(state.pendingApprovalCount)}</span>`;
  const pageTitles = {overview: 'Overview', students: 'Students', exams: 'Exams', questions: 'Question bank', results: 'Results', audit: 'Audit Log', newsletter: 'Newsletter', roles: 'Roles', users: 'User Management', approvals: 'Pending Approval', backups: 'Database Backup', settings: 'Settings'};
  const tabMap = new Map([['overview', 'Overview'], ['students', 'Students'], ['exams', 'Exams'], ['questions', 'Question bank'], ['results', 'Results'], ['audit', 'Audit log'], ['newsletter', 'Newsletter'], ['roles', 'Roles'], ['users', 'Users'], ['approvals', approvalTitle], ['backups', 'Database backup'], ['settings', 'Settings']].filter(([id]) => can(id)));
  const navigationGroups = [
    ['Workspace', ['overview']],
    ['Academic management', ['students', 'exams', 'questions', 'results']],
    ['Communication', ['newsletter']],
    ['Administration', ['approvals', 'users', 'roles']],
    ['System', ['audit', 'backups', 'settings']]
  ];
  const navigation = navigationGroups.map(([label, ids]) => {
    const items = ids.filter(id => tabMap.has(id)); if (!items.length) return '';
    return `<section class="admin-nav-section"><div class="nav-section-label">${esc(label)}</div>${items.map(id => `<button class="${active === id ? 'active' : ''}" ${active === id ? 'aria-current="page"' : ''} data-route="admin/${id}" title="${esc(pageTitles[id] || id)}"><span class="nav-icon">${sidebarIcon(id)}</span><span class="nav-text">${tabMap.get(id)}</span></button>`).join('')}</section>`;
  }).join('');
  const name = state.adminUser?.name || 'Administrator', role = state.adminUser?.role || 'Superadmin';
  const systemSettings = can('settings') ? '<button data-route="admin/settings">Settings</button>' : '';
  const pageTitle = pageTitles[active] || 'Admin workspace';
  return `<main class="admin-shell ${state.sidebarOpen ? '' : 'sidebar-collapsed'}"><aside class="admin-sidebar">${brand()}<nav class="admin-nav" aria-label="Admin workspace">${navigation}</nav><div class="admin-profile"><span class="avatar">${esc(candidateInitials(name))}</span><span><strong>${esc(name)}</strong><small>${esc(role)}</small></span></div></aside><header class="admin-topbar"><button class="sidebar-toggle" data-action="toggle-sidebar" aria-label="${state.sidebarOpen ? 'Collapse' : 'Expand'} sidebar" aria-expanded="${state.sidebarOpen}">☰</button><div class="admin-topbar-title"><span aria-hidden="true">⌂</span><strong>${esc(pageTitle)}</strong></div><div class="admin-topbar-actions">${themeToggle()}<div class="account-menu-wrap"><button class="account-menu-toggle" data-action="toggle-account-menu" aria-expanded="false"><span class="avatar">${esc(candidateInitials(name))}</span><span><strong>${esc(name)}</strong><small>${esc(role)}</small></span><b aria-hidden="true">⌄</b></button><div class="account-menu" hidden><div class="account-menu-identity"><strong>${esc(name)}</strong><span>${esc(state.adminUser?.email || '')}</span></div><button data-action="account-settings">Account Settings</button>${systemSettings}<hr><button class="account-logout" data-action="admin-logout">Logout</button></div></div></div></header><section class="admin-content">${content}</section></main>`;
}
function adminHeader(title, description, actions = '') {
  return `<div class="admin-header"><div><div class="eyebrow">Admin workspace</div><h1>${title}</h1><p>${description}</p></div><div class="header-actions">${actions}</div></div>`;
}
function emptyState(message, action = '') { return `<div class="empty-state"><p>${message}</p>${action}</div>`; }
function filterBar(name, placeholder, statuses = []) {
  const filter = state.filters[name];
  return `<form class="filter-bar" data-filter-form="${name}"><label class="filter-search"><span class="sr-only">Search ${name}</span><input type="search" name="q" value="${esc(filter.q)}" placeholder="${esc(placeholder)}"></label>${statuses.length ? `<label><span class="sr-only">Status</span><select class="select-field" name="status"><option value="">All statuses</option>${statuses.map(([value, label]) => `<option value="${value}" ${filter.status === value ? 'selected' : ''}>${label}</option>`).join('')}</select></label>` : ''}<button class="outline-btn">Search</button>${filter.q || filter.status ? `<button class="ghost-btn" type="button" data-filter-clear="${name}">Clear</button>` : ''}</form>`;
}
function sortHead(name, field, label) {
  const filter = state.filters[name];
  const arrow = filter.sort === field ? (filter.dir === 'asc' ? ' ↑' : ' ↓') : '';
  return `<th><button class="sort-button" data-sort-list="${name}" data-sort-field="${field}" aria-label="Sort by ${esc(label)}">${esc(label)}${arrow}</button></th>`;
}
function pager(name) {
  const meta = state.meta[name] || {};
  const filter = state.filters[name];
  const page = numeric(meta.page, filter.page);
  const totalPages = Math.max(1, numeric(meta.pages, 1));
  const total = numeric(meta.total, 0);
  if (totalPages < 2) return `<p class="pagination-caption">${total} record${total === 1 ? '' : 's'}</p>`;
  return `<div class="pagination"><span>${total} records · Page ${page} of ${totalPages}</span><div><button class="outline-btn small-btn" data-page-list="${name}" data-page="${page - 1}" ${page <= 1 ? 'disabled' : ''}>Previous</button><button class="outline-btn small-btn" data-page-list="${name}" data-page="${page + 1}" ${page >= totalPages ? 'disabled' : ''}>Next</button></div></div>`;
}
function adminPage(route) {
  const page = route[1] || 'overview';
  if (page === 'overview') return dashboardPage();
  if (page === 'students') return studentsPage();
  if (page === 'exams') return examsPage();
  if (page === 'questions') return questionsPage(route[2]);
  if (page === 'results') return resultsPage();
  if (page === 'review') return reviewPage();
  if (page === 'student-results') return studentResultsPage();
  if (page === 'audit') return auditPage(route);
  if (page === 'newsletter') return newsletterPage();
  if (page === 'roles') return rolesPage();
  if (page === 'users') return usersPage();
  if (page === 'approvals') return approvalsPage();
  if (page === 'backups') return backupPage();
  if (page === 'force-password') return `${adminHeader('Secure your Superadmin account', 'The seeded deployment password must be replaced before administrative access can continue.')}<section class="panel"><form id="account-password-form"><div class="alert" role="alert"></div><label class="form-field">Current seeded password<input name="currentPassword" type="password" autocomplete="current-password" required></label><label class="form-field">New password<input name="newPassword" type="password" autocomplete="new-password" minlength="12" required></label><label class="form-field">Confirm new password<input name="confirmPassword" type="password" autocomplete="new-password" minlength="12" required></label><div class="modal-actions"><button class="primary-btn">Set secure password</button></div></form></section>`;
  if (page === 'settings') return settingsPage();
  return `${adminHeader('Page not found', 'Choose a page from the navigation.')}<button class="primary-btn" data-route="admin/overview">Go to dashboard</button>`;
}
function statCard(label, value, note = '') {
  return `<div class="stat-card"><div class="stat-label">${esc(label)}</div><div class="stat-value">${esc(value)}</div>${note ? `<div class="stat-change">${esc(note)}</div>` : ''}</div>`;
}
function fileSize(bytes) {
  const value = Math.max(0, numeric(bytes));
  if (value < 1024) return `${value} B`;
  if (value < 1024 * 1024) return `${(value / 1024).toFixed(1)} KB`;
  return `${(value / (1024 * 1024)).toFixed(1)} MB`;
}
function backupPage() {
  const backup = state.backups || {}, settings = backup.settings || {}, items = backup.items || [];
  const rows = items.map(item => `<tr><td><strong>${esc(item.filename)}</strong></td><td>${esc(fmtDate(item.createdAt))}</td><td>${esc(fileSize(item.size))}</td><td class="table-actions"><button class="outline-btn small-btn" data-action="download-backup" data-id="${esc(item.filename)}">Download</button><button class="danger-btn small-btn" data-action="delete-backup" data-id="${esc(item.filename)}">Delete</button></td></tr>`).join('');
  const next = backup.nextRunAt ? fmtDate(backup.nextRunAt) : 'Automatic backups are disabled';
  return `${adminHeader('Database Backup', 'Create, protect, and recover the complete CBT system record.', '<button class="primary-btn" data-action="create-backup">Create Backup Now</button>')}<section class="panel backup-overview"><div><span class="section-kicker">Secure backup control</span><h2>System snapshots</h2><p>Backups include students, courses, questions, academic periods, grading settings, assessment sessions, submissions, results, audit events, and administrator account details. Password hashes are never exported.</p></div><div class="backup-next-run"><span>Next scheduled run</span><strong>${esc(next)}</strong></div></section><section class="panel" style="margin-top:18px"><div class="panel-heading"><div><h2>Available backups</h2><p class="table-muted">${numeric(items.length)} file${items.length === 1 ? '' : 's'} stored in ${esc(backup.directory || 'database/backups/')}.</p></div></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Filename</th><th>Created</th><th>Size</th><th>Actions</th></tr></thead><tbody>${rows || '<tr><td colspan="4">No backups have been created yet.</td></tr>'}</tbody></table></div></section><section class="panel backup-settings" style="margin-top:18px"><div class="panel-heading"><div><h2>Automatic backup schedule</h2><p class="table-muted">The server creates the next daily backup when it receives a request at or after the configured server time.</p></div></div><form id="backup-settings-form"><label class="checkbox-field"><input type="checkbox" name="enabled" ${settings.enabled !== false ? 'checked' : ''}> Enable daily automatic backup</label><div class="form-grid"><label class="form-field">Daily server time<input name="time" type="time" value="${esc(settings.time || '02:00')}" required></label><label class="form-field">Maximum backups to keep<input name="retentionCount" type="number" min="1" max="365" value="${numeric(settings.retentionCount, 14)}" required></label></div><p class="table-muted">Oldest files are deleted automatically after the retention limit is reached.</p><div class="modal-actions"><button class="primary-btn">Save backup settings</button></div></form></section><section class="panel backup-restore-panel" style="margin-top:18px"><div class="panel-heading"><div><h2>Restore from backup</h2><p class="table-muted">Restore replaces the current academic and system data. A fresh safety backup is created immediately before any restore.</p></div><button class="danger-btn" data-action="restore-backup">Restore backup</button></div><p class="backup-warning">Sensitive student data is contained in every backup. Store downloaded files securely and keep an off-site copy. Current administrator credentials are retained during a restore because password hashes are intentionally excluded from exports.</p></section><section class="panel backup-information" style="margin-top:18px"><h2>Important information</h2><ul><li>Server copies are stored in <code>database/backups/</code>.</li><li>Download backups regularly and store them in a secure off-site location.</li><li>Retention automatically removes the oldest files after the configured limit.</li></ul></section>`;
}
function dashboardPage() {
  const data = state.dashboard || {}, stats = data.stats || {};
  const distributions = data.scoreDistribution || [];
  const courseOutcomes = data.courseOutcomes || [];
  const hiddenOutcomes = data.hiddenCourseOutcomes || [];
  const selectedOutcomes = state.dashboardSelectedOutcomes || [];
  const selectedCount = selectedOutcomes.length;
  const maxCount = Math.max(1, ...distributions.map(item => numeric(item.count)));
  const scoreChart = distributions.length ? `<div class="score-chart" role="img" aria-label="Score distribution: ${distributions.map(item => `${item.label}, ${numeric(item.count)} students`).join('; ')}"><div class="score-chart-scale" aria-hidden="true"><span>${maxCount}</span><span>${Math.ceil(maxCount / 2)}</span><span>0</span></div><div class="score-chart-plot">${distributions.map(item => { const count = numeric(item.count); const height = count ? Math.max(9, count / maxCount * 100) : 3; return `<div class="score-chart-column"><strong class="score-chart-value">${count}</strong><div class="score-chart-bar-wrap"><i class="score-chart-bar" style="--bar-height:${height}%"></i></div><span class="score-chart-label">${esc(item.label)}</span></div>`; }).join('')}</div></div>` : emptyState('No completed exams yet. Score distribution will appear after students submit.');
  const outcomeList = courseOutcomes.length ? `<div class="mini-list dashboard-outcome-list">${courseOutcomes.map(item => {
    const selected = selectedOutcomes.includes(item.examId);
    const component = item.componentLabel ? ` · ${esc(item.componentLabel)}` : '';
    return `<div class="outcome-select-row ${selected ? 'selected' : ''}"><label class="outcome-select-control"><input type="checkbox" data-dashboard-outcome-select value="${esc(item.examId)}" ${selected ? 'checked' : ''} aria-label="Select ${esc(item.code)} ${esc(item.title)}"><span aria-hidden="true"></span></label><div class="mini-row"><div><strong>${esc(item.code)} · ${esc(item.title)}</strong><span>${component ? `${component.slice(3)} · ` : ''}${numeric(item.attempts)} submission${numeric(item.attempts) === 1 ? '' : 's'} · ${numeric(item.averageScore)}% average</span></div><div class="outcome-summary"><span>${numeric(item.passed)} pass / ${numeric(item.failed)} fail</span><div class="progress"><i style="width:${Math.max(0, Math.min(100, numeric(item.passRate)))}%"></i></div></div></div></div>`;
  }).join('')}</div>` : emptyState('No visible course outcomes. Restore hidden rows if you want them shown again.');
  const outcomeActions = `<div class="dashboard-outcome-actions"><span class="table-muted">Select rows to hide only from this dashboard.</span><div><button class="danger-btn small-btn" data-action="hide-dashboard-outcomes" ${selectedCount ? '' : 'disabled'}>Hide selected (${selectedCount})</button>${hiddenOutcomes.length ? `<button class="outline-btn small-btn" data-action="restore-dashboard-outcomes">Restore hidden (${hiddenOutcomes.length})</button>` : ''}</div></div>`;
  return `${adminHeader('Dashboard', 'Current assessment activity and outcomes.', '<button class="primary-btn" data-route="admin/students">Register student</button>')}<section class="stat-grid">${statCard('Registered students', numeric(stats.students))}${statCard('Active assessments', numeric(stats.activeExams))}${statCard('Completed today', numeric(stats.completedToday))}${statCard('Average score', `${numeric(stats.averageScore)}%`)}</section><div class="admin-grid"><section class="panel"><div class="panel-heading"><h2>Score distribution</h2><span class="chart-caption">Students per score band</span></div>${scoreChart}</section><section class="panel dashboard-outcomes-panel"><div class="panel-heading"><div><h2>Course outcomes</h2><span class="table-muted">Visible assessment components</span></div></div>${outcomeActions}${outcomeList}${pager('outcomes')}</section></div><section class="panel table-panel"><div class="panel-heading"><h2>Recent submissions</h2></div>${resultsTable(data.recent || [], false)}</section>`;
}
function studentsPage() {
  const rows = state.students.map(student => `<tr><td>${esc(student.fullName)}</td><td>${esc(student.matricNumber)}</td><td>${esc(student.department)}</td><td>${esc(student.email)}</td><td>${esc(student.phoneNumber || '---')}</td><td><span class="status-pill ${student.active ? '' : 'status-muted'}">${student.active ? 'Active' : 'Disabled'}</span></td><td class="table-actions"><button class="outline-btn small-btn" data-route="admin/student-results/${esc(student.id)}">Results</button><button class="outline-btn small-btn" data-action="student-password" data-id="${esc(student.id)}">Password</button><button class="outline-btn small-btn" data-action="edit-student" data-id="${esc(student.id)}">Edit</button><button class="${student.active ? 'danger-btn' : 'outline-btn'} small-btn" data-action="student-status" data-id="${esc(student.id)}">${student.active ? 'Disable' : 'Reactivate'}</button><button class="danger-btn small-btn" data-action="delete-student" data-id="${esc(student.id)}">Delete</button></td></tr>`).join('');
  const table = state.students.length ? `<div class="table-scroll"><table class="data-table"><thead><tr>${sortHead('students', 'fullName', 'Student')}${sortHead('students', 'matricNumber', 'Matric')}${sortHead('students', 'department', 'Department')}${sortHead('students', 'email', 'Email')}${sortHead('students', 'phoneNumber', 'Phone')}<th>Access</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table></div>` : emptyState('No students match your search. Register a student or clear the filters.', '<button class="primary-btn" data-action="new-student">Register student</button>');
  return `${adminHeader('Students', 'Register students and issue exam passwords.', '<button class="outline-btn" data-action="bulk-students">Import CSV</button><button class="primary-btn" data-action="new-student">+ Register student</button>')}<section class="panel table-panel">${filterBar('students', 'Name, matric number, department, email or phone', [['active', 'Active'], ['disabled', 'Disabled']])}${table}${pager('students')}</section>`;
}
function legacyExamsPage() {
  const rows = state.adminExams.map(exam => {
    const status = examStatusMeta(exam);
    const editLabel = exam.windowState === 'closed' ? 'Update window' : 'Edit';
    const statusAction = exam.status === 'draft'
      ? `<button class="outline-btn small-btn" data-action="exam-status" data-id="${esc(exam.id)}" data-status="published">Publish</button>`
      : exam.status === 'published'
        ? `<button class="primary-btn small-btn" data-action="exam-status" data-id="${esc(exam.id)}" data-status="active">Activate</button>`
        : `<button class="outline-btn small-btn" data-action="exam-status" data-id="${esc(exam.id)}" data-status="published">Deactivate</button>`;
    return `<tr><td>${esc(exam.code)}</td><td>${esc(exam.title)}</td><td>${numeric(exam.duration)} min</td><td>${numeric(exam.questionCount)}</td><td>${numeric(exam.courseUnit, 3)}</td><td>${esc(exam.session || '---')}</td><td class="table-muted">${fmtDate(exam.startAt)}<br>to ${fmtDate(exam.endAt)}</td><td><span class="status-pill ${status.className}">${esc(status.label)}</span></td><td class="table-actions">${statusAction}<button class="outline-btn small-btn" data-route="admin/questions/${esc(exam.id)}">Questions</button><button class="outline-btn small-btn" data-action="edit-exam" data-id="${esc(exam.id)}">${editLabel}</button><button class="danger-btn small-btn" data-action="delete-exam" data-id="${esc(exam.id)}">Delete</button></td></tr>`;
  }).join('');
  return `${adminHeader('Exams', 'Manage course details, question banks, and exam windows. Students can enter only while an Active exam window is open.', '<button class="primary-btn" data-action="new-exam">+ Create exam</button>')}<section class="panel table-panel">${filterBar('exams', 'Course code or assessment title', [['draft', 'Draft'], ['published', 'Published'], ['active', 'Active']])}${state.adminExams.length ? `<div class="table-scroll"><table class="data-table"><thead><tr>${sortHead('exams', 'code', 'Code')}${sortHead('exams', 'title', 'Assessment')}${sortHead('exams', 'duration', 'Duration')}${sortHead('exams', 'questionCount', 'Questions')}<th>Unit</th><th>Session</th>${sortHead('exams', 'startAt', 'Window')}<th>Status</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table></div>` : emptyState('No courses match your search. Create an exam to get started.', '<button class="primary-btn" data-action="new-exam">Create exam</button>')}${pager('exams')}</section>`;
}
function examsPage() {
  const componentCell = component => {
    if (!component) return '<span class="table-muted">Not configured</span>';
    const status = examStatusMeta(component);
    const nextStatus = component.status === 'draft' ? 'published' : component.status === 'published' ? 'active' : 'published';
    const statusLabel = component.status === 'draft' ? 'Publish' : component.status === 'published' ? 'Activate' : 'Deactivate';
    return `<div class="component-summary"><strong>${esc(component.componentLabel || component.component || 'Exam')}</strong><span>${numeric(component.maxMark)} marks · ${numeric(component.questionCount)} questions · ${numeric(component.duration)} min</span><span class="status-pill ${status.className}">${esc(status.label)}</span><div class="table-actions"><button class="${component.status === 'published' ? 'primary-btn' : 'outline-btn'} small-btn" data-action="component-status" data-id="${esc(component.id)}" data-status="${nextStatus}">${statusLabel}</button><button class="outline-btn small-btn" data-route="admin/questions/${esc(component.id)}">Questions</button><button class="outline-btn small-btn" data-action="edit-component" data-id="${esc(component.id)}">Edit</button><button class="danger-btn small-btn" data-action="delete-component" data-id="${esc(component.id)}">Delete</button></div></div>`;
  };
  const rows = state.courses.map(course => {
    const components = course.components || [];
    const test = components.find(item => item.component === 'test');
    const exam = components.find(item => item.component === 'exam');
    return `<tr><td><strong>${esc(course.code)}</strong></td><td>${esc(course.title)}<small class="table-muted" style="display:block">${esc(course.description || '')}</small></td><td>${numeric(course.courseUnit)}</td><td>${esc(course.sessionLabel || '---')}<small class="table-muted" style="display:block">${esc(course.semesterLabel || '')}</small></td><td>${componentCell(test)}</td><td>${componentCell(exam)}</td><td class="table-actions"><button class="outline-btn small-btn" data-action="edit-course" data-id="${esc(course.id)}">Edit course</button><button class="danger-btn small-btn" data-action="delete-course" data-id="${esc(course.id)}">Delete</button></td></tr>`;
  }).join('');
  return `${adminHeader('Courses & assessments', 'Create a course once, then manage its separate Test and Exam components.', '<button class="primary-btn" data-action="new-course">+ Create course</button>')}<section class="panel table-panel">${filterBar('exams', 'Course code, title, session or semester')}${state.courses.length ? `<div class="table-scroll"><table class="data-table"><thead><tr>${sortHead('exams', 'code', 'Code')}${sortHead('exams', 'title', 'Course')}<th>Unit</th><th>Session / semester</th><th>Test</th><th>Exam</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table></div>` : emptyState('No courses have been created. Start by setting the active session and semester in Settings.', '<button class="primary-btn" data-action="new-course">Create course</button>')}${pager('exams')}</section>`;
}

function questionsPage(examId) {
  const exam = state.adminExams.find(item => item.id === examId);
  if (!exam) return `${adminHeader('Question bank', 'Choose a course to manage its questions.')}<section class="panel table-panel">${state.adminExams.length ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Course</th><th>Assessment</th><th>Question target</th><th></th></tr></thead><tbody>${state.adminExams.map(item => `<tr><td><strong>${esc(item.code)}</strong></td><td>${esc(item.title)}</td><td>${numeric(item.questionCount)} questions</td><td><button class="outline-btn small-btn" data-route="admin/questions/${esc(item.id)}">Manage questions</button></td></tr>`).join('')}</tbody></table></div>` : emptyState('Create an exam first, then add its questions.', '<button class="primary-btn" data-route="admin/exams">Create exam</button>')}</section>`;
  return `${adminHeader(`${esc(exam.code)} question bank`, `Create and publish questions for ${esc(exam.title)}.`, '<button class="outline-btn" data-action="bulk-questions">Import JSON</button><button class="primary-btn" data-action="new-question">+ Add question</button>')}<section class="panel table-panel">${filterBar('questions', 'Search question text', [['published', 'Published'], ['draft', 'Draft']])}${state.questions.length ? `<div class="table-scroll"><table class="data-table"><thead><tr>${sortHead('questions', 'text', 'Question')}<th>Type</th>${sortHead('questions', 'status', 'Status')}<th>Actions</th></tr></thead><tbody>${state.questions.map(question => `<tr><td class="math-rendered">${mathHtml(question.text)}</td><td>${question.type === 'multiple' ? 'Multiple' : 'Single'}</td><td><span class="status-pill ${question.status === 'published' ? '' : 'status-muted'}">${question.status === 'published' ? 'Published' : 'Draft'}</span></td><td class="table-actions"><button class="${question.status === 'published' ? 'outline-btn' : 'primary-btn'} small-btn" data-action="question-status" data-id="${esc(question.id)}" data-status="${question.status === 'published' ? 'draft' : 'published'}">${question.status === 'published' ? 'Unpublish' : 'Publish'}</button><button class="outline-btn small-btn" data-action="edit-question" data-id="${esc(question.id)}">Edit</button><button class="danger-btn small-btn" data-action="delete-question" data-id="${esc(question.id)}">Delete</button></td></tr>`).join('')}</tbody></table></div>` : emptyState('No questions match your search. Add the first question for this course.', '<button class="primary-btn" data-action="new-question">Add question</button>')}${pager('questions')}</section><button class="outline-btn" style="margin-top:18px" data-route="admin/exams">Back to exams</button>`;
}
function resultsTable(items, includePager = false) {
  if (!items.length) return emptyState('No completed results yet. Submitted assessments will appear here.');
  return `<div class="table-scroll"><table class="data-table"><thead><tr><th>Student</th><th>Assessment</th><th>Submitted</th><th>Score</th><th>Grade</th><th>Status</th><th>Actions</th></tr></thead><tbody>${items.map(result => `<tr><td>${esc(result.studentName || 'Student')}</td><td>${esc(result.examCode || 'Exam')}</td><td class="table-muted">${fmtDate(result.submittedAt)}</td><td><strong>${numeric(result.score)}%</strong></td><td>${esc(result.grade || '---')}</td><td><span class="status-pill ${result.status === 'auto_submitted' ? 'status-warning' : ''}">${result.status === 'auto_submitted' ? 'Auto-submitted' : 'Submitted'}</span></td><td class="table-actions"><button class="outline-btn small-btn" data-route="admin/review/${esc(result.id)}">Review</button><button class="outline-btn small-btn" data-route="admin/student-results/${esc(result.studentId)}">Result sheet</button></td></tr>`).join('')}</tbody></table></div>${includePager ? pager('results') : ''}`;
}
function resultsTable(items, includePager = false) {
  if (!items.length) return emptyState('No completed results yet. Submitted assessments will appear here.');
  return `<div class="table-scroll"><table class="data-table"><thead><tr><th>Student</th><th>Assessment</th><th>Submitted</th><th>Component mark</th><th>Grade</th><th>Status</th><th>Actions</th></tr></thead><tbody>${items.map(result => `<tr><td>${esc(result.studentName || 'Student')}</td><td><strong>${esc(result.examCode || 'Exam')}</strong><small class="table-muted" style="display:block">${esc(result.examTitle || '')} --- ${esc(result.componentLabel || 'Exam')}</small></td><td class="table-muted">${fmtDate(result.submittedAt)}</td><td><strong>${numeric(result.score).toFixed(1)} / ${numeric(result.maxMark, 100)}</strong><small class="table-muted" style="display:block">${numeric(result.rawScore, result.score).toFixed(1)}% raw</small></td><td>${esc(result.grade || 'Shown on course sheet')}</td><td><span class="status-pill ${result.status === 'auto_submitted' ? 'status-warning' : ''}">${result.status === 'auto_submitted' ? 'Auto-submitted' : 'Submitted'}</span></td><td class="table-actions"><button class="outline-btn small-btn" data-route="admin/review/${esc(result.id)}">Review</button><button class="outline-btn small-btn" data-route="admin/student-results/${esc(result.studentId)}">Result sheet</button></td></tr>`).join('')}</tbody></table></div>${includePager ? pager('results') : ''}`;
}

function resultsPage() {
  const filter = state.filters.results;
  const periodOptions = (state.resultPeriods || []).map(period => {
    const value = `${period.sessionId}|${period.semesterId}`;
    return `<option value="${esc(value)}" ${filter.period === value ? 'selected' : ''}>${esc(period.sessionLabel)} · ${esc(period.semesterLabel)}</option>`;
  }).join('');
  const rows = state.results.map(item => `<tr class="result-student-row" data-route="admin/student-results/${esc(item.studentId)}/${esc(item.sessionId)}/${esc(item.semesterId)}" tabindex="0"><td><strong>${esc(item.studentName)}</strong></td><td>${esc(item.matricNumber || '---')}</td><td>${numeric(item.completedComponents)}</td><td class="table-muted">${fmtDate(item.latestSubmittedAt)}</td><td><small>${esc(item.sessionLabel)} · ${esc(item.semesterLabel)}</small></td><td><span class="status-pill ${item.calculated ? '' : 'status-warning'}">${item.calculated ? 'Calculated' : 'Not calculated'}</span></td><td><button class="outline-btn small-btn" data-route="admin/student-results/${esc(item.studentId)}/${esc(item.sessionId)}/${esc(item.semesterId)}">View results</button></td></tr>`).join('');
  return `${adminHeader('Results', 'Select a student to review submitted components and calculate a session result.')}<section class="panel table-panel results-student-list"><form class="filter-bar" data-filter-form="results"><label class="filter-search"><span class="sr-only">Search students</span><input type="search" name="q" value="${esc(filter.q)}" placeholder="Search student name or matric number"></label><label><span class="sr-only">Academic period</span><select class="select-field" name="period"><option value="">All sessions and semesters</option>${periodOptions}</select></label><button class="outline-btn">Search</button>${filter.q || filter.period ? '<button class="ghost-btn" type="button" data-filter-clear="results">Clear</button>' : ''}</form>${rows ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Student</th><th>Matric number</th><th>Completed components</th><th>Most recent submission</th><th>Most recent period</th><th>Result status</th><th></th></tr></thead><tbody>${rows}</tbody></table></div>` : emptyState('No submitted Test or Exam components match this filter.')}${pager('results')}</section>`;
}
function shortDuration(seconds) {
  const value = Math.max(0, numeric(seconds));
  return `${Math.floor(value / 60)}m ${String(value % 60).padStart(2, '0')}s`;
}
function auditMonitorRows(items = state.auditMonitor) {
  if (!items.length) return '<tr><td colspan="9"><div class="empty-state"><p>No students are currently taking an assessment.</p></div></td></tr>';
  return items.map(item => `<tr class="audit-session-row" data-action="audit-session-detail" data-id="${esc(item.id)}" tabindex="0" role="button" aria-label="View integrity events for ${esc(item.studentName)}"><td>${esc(item.studentName)}</td><td>${esc(item.matricNumber)}</td><td><strong>${esc(item.course)}</strong><small class="table-muted" style="display:block">${esc(item.courseTitle)}</small></td><td class="table-muted">${fmtDate(item.startedAt)}</td><td>${shortDuration(item.elapsedSeconds)}</td><td>${shortDuration(item.remainingSeconds)}</td><td>${esc(displayIp(item.ipAddress))}</td><td><span class="status-pill ${item.locked ? 'status-danger' : item.status !== 'Normal' ? 'status-warning' : ''}">${esc(item.status)}</span></td><td class="table-actions"><button class="outline-btn small-btn" data-action="audit-session-detail" data-id="${esc(item.id)}">View</button>${item.locked && numeric(item.remainingSeconds) > 0 ? `<button class="primary-btn small-btn" data-action="unlock-exam-session" data-id="${esc(item.id)}">Unlock</button>` : ''}</td></tr>`).join('');
}
function auditFilterBar() {
  const filter = state.filters.audit;
  return `<form class="filter-bar" id="audit-filter-form"><label>From<input name="from" type="date" value="${esc(filter.from)}"></label><label>To<input name="to" type="date" value="${esc(filter.to)}"></label><label>Actor<input name="actor" value="${esc(filter.actor)}" placeholder="Admin email or matric"></label><label>Event type<select class="select-field" name="type"><option value="">All event types</option>${['admin_login','admin_logout','administrator_registration_requested','administrator_request_approved','administrator_request_rejected','administrator_password_reset_requested','administrator_password_reset_completed','student_registered','student_updated','student_disabled','students_bulk_imported','exam_created','exam_updated','exam_deleted','question_added','question_updated','question_deleted','questions_bulk_imported','exam_password_generated','student_exam_login','exam_started','exam_session_unlocked','exam_submitted_manual','exam_submitted_auto','audit_events_deleted','settings_updated','exam_integrity_tab_switch','exam_integrity_blur','exam_integrity_context_menu','exam_integrity_copy','exam_integrity_paste','exam_integrity_devtools'].map(type => `<option value="${type}" ${filter.type === type ? 'selected' : ''}>${type.replaceAll('_', ' ')}</option>`).join('')}</select></label><label>Course<input name="course" value="${esc(filter.course)}" placeholder="e.g. MTH 101"></label><button class="outline-btn">Filter</button><button type="button" class="ghost-btn" data-action="clear-audit-filter">Clear</button></form>`;
}
function auditPage(route) {
  const trail = route[2] === 'trail';
  const tabs = `<div class="inline-actions audit-tabs"><button class="${trail ? 'outline-btn' : 'primary-btn'}" data-route="admin/audit">Live exam monitor</button><button class="${trail ? 'primary-btn' : 'outline-btn'}" data-route="admin/audit/trail">Full audit trail</button></div>`;
  const help = '<p class="table-muted audit-help">Integrity signals are browser-based indicators, not proof of misconduct. Browsers cannot detect operating-system screenshots or mobile screenshot gestures.</p>';
  const count = trail ? numeric(state.meta.audit?.total, state.auditEvents.length) : state.auditMonitor.length;
  const controls = `<div class="audit-controls"><button class="audit-control" data-action="export-audit-csv">CSV</button><button class="audit-control" data-action="export-audit-excel">Excel</button><button class="audit-control" data-action="export-audit-pdf">PDF</button><button class="audit-control" data-action="print-audit">Print</button><button class="audit-control audit-refresh" data-action="refresh-audit">Refresh</button></div>`;
  const hero = `<section class="audit-hero"><div><span class="audit-kicker">Security center</span><h1>Audit Log</h1><p>Monitor logins, admin actions, account changes, and active sessions in one place.</p><strong><i></i>${count} ${trail ? 'events in view' : 'active sessions in view'}, refreshed live as you work.</strong></div>${controls}</section>`;
  if (!trail) return `${hero}${tabs}${help}<section class="panel table-panel"><div class="panel-heading"><h2>Live exam monitor</h2><span class="table-muted" aria-live="polite">Refreshes every 5 seconds</span></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Student</th><th>Matric</th><th>Course</th><th>Started</th><th>Elapsed</th><th>Remaining</th><th>IP address</th><th>Status</th><th></th></tr></thead><tbody id="audit-monitor-rows">${auditMonitorRows()}</tbody></table></div></section>`;
  const rows = state.auditEvents.length ? state.auditEvents.map(item => `<tr class="audit-event-row"><td class="table-muted">${fmtDate(item.timestamp)}</td><td>${esc(item.actor)}</td><td><span class="status-pill ${item.actionType.includes('integrity') ? 'status-warning' : ''}">${esc(item.actionType.replaceAll('_', ' '))}</span></td><td>${esc(item.target)}</td><td>${esc(displayIp(item.ipAddress))}</td></tr>`).join('') : '<tr><td colspan="5"><div class="empty-state"><p>No events match these filters.</p></div></td></tr>';
  return `${hero}${tabs}${help}<section class="panel table-panel audit-trail-panel">${auditFilterBar()}<p class="table-muted audit-immutable-note">Audit records are immutable and can only be written by server-side system actions.</p><div class="table-scroll"><table class="data-table"><thead><tr><th>Timestamp</th><th>Actor</th><th>Action</th><th>Target</th><th>IP address</th></tr></thead><tbody>${rows}</tbody></table></div>${pager('audit')}</section>`;
}
function auditExportRows() {
  if (parts()[2] === 'trail') return state.auditEvents.map(item => [fmtDate(item.timestamp), item.actor || '', String(item.actionType || '').replaceAll('_', ' '), item.target || '', displayIp(item.ipAddress)]);
  return state.auditMonitor.map(item => [item.studentName || '', item.matricNumber || '', item.course || '', fmtDate(item.startedAt), shortDuration(item.elapsedSeconds), shortDuration(item.remainingSeconds), displayIp(item.ipAddress), item.status || '']);
}
function downloadAuditExport(kind) {
  const trail = parts()[2] === 'trail';
  const headers = trail ? ['Timestamp', 'Actor', 'Action', 'Target', 'IP address'] : ['Student', 'Matric', 'Course', 'Started', 'Elapsed', 'Remaining', 'IP address', 'Status'];
  const value = cell => `"${String(cell ?? '').replaceAll('"', '""')}"`;
  const csv = [headers, ...auditExportRows()].map(row => row.map(value).join(',')).join('\r\n');
  const blob = new Blob([`\uFEFF${csv}`], {type: 'text/csv;charset=utf-8'}); const url = URL.createObjectURL(blob), link = document.createElement('a');
  link.href = url; link.download = `audit-${trail ? 'trail' : 'live-monitor'}-${new Date().toISOString().slice(0, 10)}.${kind === 'excel' ? 'xls' : 'csv'}`; link.click();
  setTimeout(() => URL.revokeObjectURL(url), 1000); toast(`${kind === 'excel' ? 'Excel-compatible' : 'CSV'} audit export downloaded.`, 'success');
}
function newsletterPage() {
  const stats = state.newsletterStats || {};
  const rows = state.newsletterSubscribers.map(subscriber => `<tr><td><div class="user-cell"><span class="table-avatar">${esc(candidateInitials(subscriber.name))}</span><span><strong>${esc(subscriber.email)}</strong><small class="table-muted">${esc(subscriber.source === 'student' ? 'Student record' : 'Manual subscriber')}</small></span></div></td><td>${esc(subscriber.name)}</td><td><span class="status-pill ${subscriber.status === 'active' ? '' : 'status-muted'}">${subscriber.status === 'active' ? 'Active' : 'Unsubscribed'}</span></td><td><span class="status-pill">${subscriber.verifiedAt ? 'Verified' : 'Pending'}</span></td><td class="table-muted">${fmtDate(subscriber.subscribedAt)}</td><td class="table-actions">${subscriber.status === 'active' ? `<button class="outline-btn small-btn" data-action="unsubscribe-subscriber" data-id="${esc(subscriber.id)}">Unsubscribe</button>` : `<button class="primary-btn small-btn" data-action="subscribe-subscriber" data-id="${esc(subscriber.id)}">Subscribe</button>`}<button class="danger-btn small-btn" data-action="delete-subscriber" data-id="${esc(subscriber.id)}">Delete</button></td></tr>`).join('');
  const history = state.newsletters.length ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Subject</th><th>Created</th><th>Recipients</th><th>Mail server result</th></tr></thead><tbody>${state.newsletters.map(item => `<tr><td><strong>${esc(item.subject)}</strong><small class="table-muted" style="display:block">From CACSA LAUTECH &lt;${esc(item.sender)}&gt;</small></td><td class="table-muted">${fmtDate(item.createdAt)}</td><td>${numeric(item.recipientCount)}</td><td><span class="status-pill ${item.failedCount ? 'status-warning' : ''}">${numeric(item.acceptedCount)} accepted · ${numeric(item.failedCount)} failed</span></td></tr>`).join('')}</tbody></table></div>` : emptyState('No newsletters have been sent yet. Compose the first message when ready.');
  return `${adminHeader('Newsletter Management', 'Manage subscribers and send updates to registered students and other subscribers.', '<button class="primary-btn" data-action="compose-newsletter">Compose newsletter</button>')}<section class="stat-grid newsletter-stats">${statCard('Total subscribers', numeric(stats.total))}${statCard('Active subscribers', numeric(stats.active), 'Receive newsletters')}${statCard('Student subscribers', numeric(stats.student), 'Synced from registration')}${statCard('Unsubscribed', numeric(stats.unsubscribed), 'Excluded from sends')}</section><section class="panel newsletter-add-panel"><div class="panel-heading"><h2>Add new subscriber</h2><span class="table-muted">Student emails are added automatically.</span></div><form id="newsletter-subscriber-form" class="inline-form"><label class="form-field">Name<input name="name" placeholder="Subscriber name" required></label><label class="form-field">Email address<input type="email" name="email" placeholder="subscriber@example.com" required></label><button class="primary-btn">+ Add subscriber</button></form></section><section class="panel table-panel" style="margin-top:18px"><div class="panel-heading"><h2>Subscribers list</h2></div>${filterBar('newsletter', 'Search email or name', [['active', 'Active'], ['unsubscribed', 'Unsubscribed']])}${state.newsletterSubscribers.length ? `<div class="table-scroll"><table class="data-table newsletter-table"><thead><tr><th>Email</th><th>Name</th><th>Status</th><th>Verified</th><th>Subscribed</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table></div>` : emptyState('No subscribers match this filter. Student emails will appear here as students are registered.')}${pager('newsletter')}</section><section class="panel table-panel" style="margin-top:18px"><div class="panel-heading"><h2>Newsletter history</h2><div class="table-actions"><span class="table-muted">Mail-server handoff results</span>${state.newsletters.length ? '<button class="danger-btn small-btn" data-action="clear-newsletter-history">Clear history</button>' : ''}</div></div>${history}</section>`;
}
function newsletterForm() {
  const active = numeric(state.newsletterStats?.active);
  const configured = state.newsletterMailConfigured;
  openModal('Compose newsletter', `<form id="newsletter-compose-form"><div class="alert ${configured ? '' : 'visible'}" role="alert">${configured ? '' : 'Gmail SMTP is not configured yet. Add the Gmail App Password to the local .env file, then refresh this page.'}</div><p class="table-muted">From: <strong>CACSA LAUTECH &lt;cacsalautech001@gmail.com&gt;</strong> · ${active} active subscriber${active === 1 ? '' : 's'} will receive this message.</p><label class="form-field">Subject<input name="subject" maxlength="180" required></label><label class="form-field">Message<textarea name="content" rows="10" maxlength="10000" required></textarea></label><p class="table-muted">“Accepted” means Gmail accepted the message for delivery. It cannot confirm an inbox delivery.</p><div class="modal-actions"><button class="primary-btn" ${active && configured ? '' : 'disabled'}>Send newsletter</button></div></form>`);
}
function rolesPage() {
  const roleRows = state.roles.map(role => `<tr><td><span class="role-initial">${esc(role.name.charAt(0))}</span></td><td><strong>${esc(role.name)}</strong><small class="table-muted" style="display:block">${esc(role.description)}</small></td><td><span class="status-pill">${numeric(role.userCount)} user${numeric(role.userCount) === 1 ? '' : 's'}</span></td><td>${numeric(role.maxUsers)}</td><td><span class="status-pill ${role.systemLocked ? 'status-warning' : ''}">${role.systemLocked ? 'System locked' : 'Configured role'}</span></td><td class="table-actions">${role.systemLocked ? '<span class="table-muted">Protected</span>' : `<button class="outline-btn small-btn" data-action="role-config" data-id="${esc(role.id)}">Configure</button>`}</td></tr>`).join('');
  const users = state.adminUsers.filter(user => !user.systemLocked);
  return `${adminHeader('Roles', 'Manage administrator access levels and feature permissions.', '<button class="outline-btn" data-route="admin/users">Manage users</button>')}<section class="panel table-panel"><div class="panel-heading"><h2>Roles & permissions</h2><span class="table-muted">Three fixed academic roles</span></div><div class="table-scroll"><table class="data-table roles-table"><thead><tr><th></th><th>Role details</th><th>Users</th><th>Max users</th><th>Type</th><th>Actions</th></tr></thead><tbody>${roleRows}</tbody></table></div></section><section class="panel table-panel" style="margin-top:18px"><div class="panel-heading"><h2>Coordinator accounts</h2><button class="outline-btn small-btn" data-route="admin/users">View all users</button></div>${users.length ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Administrator</th><th>Email</th><th>Role</th><th>Status</th></tr></thead><tbody>${users.map(user => `<tr><td><strong>${esc(user.name)}</strong></td><td>${esc(user.email)}</td><td>${esc(user.roleName)}</td><td><span class="status-pill ${user.active ? '' : 'status-danger'}">${user.active ? 'Active' : 'Disabled'}</span></td></tr>`).join('')}</tbody></table></div>` : emptyState('No coordinator accounts yet. Add one from User Management.', '<button class="primary-btn" data-route="admin/users">Manage users</button>')}</section>`;
}
function usersPage() {
  const editableRoles = state.roles.filter(role => !role.systemLocked);
  const users = state.adminUsers;
  const rows = users.map(user => {
    const protectedUser = Boolean(user.systemLocked);
    const roleOptions = editableRoles.map(role => `<option value="${esc(role.id)}" ${user.roleId === role.id ? 'selected' : ''}>${esc(role.name)}</option>`).join('');
    const controls = protectedUser
      ? '<span class="table-muted">System protected</span>'
      : `<form class="user-inline-form" data-id="${esc(user.id)}" data-name="${esc(user.name)}" data-email="${esc(user.email)}"><select class="select-field small-select" name="roleId">${roleOptions}</select><select class="select-field small-select" name="active"><option value="true" ${user.active ? 'selected' : ''}>Active</option><option value="false" ${user.active ? '' : 'selected'}>Disabled</option></select><button class="outline-btn small-btn">Save</button><button type="button" class="outline-btn small-btn" data-action="edit-admin-user" data-id="${esc(user.id)}">Edit</button><button type="button" class="danger-btn small-btn" data-action="disable-admin-user" data-id="${esc(user.id)}">Disable</button><button type="button" class="danger-btn small-btn" data-action="delete-admin-user" data-id="${esc(user.id)}">Delete</button></form>`;
    return `<tr><td><div class="user-cell"><span class="table-avatar">${esc(candidateInitials(user.name))}</span><span><strong>${esc(user.name)}</strong><small class="table-muted">ID: ${esc(user.id)}</small></span></div></td><td>${esc(user.email)}</td><td>${protectedUser ? '<span class="status-pill status-warning">Superadmin</span>' : esc(user.roleName)}</td><td><span class="status-pill">${user.verified ? 'Verified' : 'Unverified'}</span></td><td><span class="status-pill ${user.active ? '' : 'status-danger'}">${user.active ? 'Active' : 'Disabled'}</span></td><td class="table-muted">${user.lastLoginAt ? fmtDate(user.lastLoginAt) : 'Never'}</td><td class="table-actions">${controls}</td></tr>`;
  }).join('');
  return `${adminHeader('User Management', 'Manage administrator accounts, roles, status, and sign-in access.', '<button class="primary-btn" data-action="new-admin-user">+ Add user</button>')}<section class="panel table-panel"><div class="panel-heading"><h2>All users</h2><span class="table-muted">Superadmin-only workspace</span></div>${users.length ? `<div class="table-scroll"><table class="data-table users-table"><thead><tr><th>User</th><th>Email</th><th>Role</th><th>Verification</th><th>Account</th><th>Last login</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table></div>` : emptyState('No administrator accounts are available.', '<button class="primary-btn" data-action="new-admin-user">Add user</button>')}</section>`;
}
function approvalsPage() {
  const approvals = state.adminApprovals || {}, pending = approvals.pending || [], recent = approvals.recent || [];
  const pendingRows = pending.map(request => `<tr><td><div class="user-cell"><span class="table-avatar">${esc(candidateInitials(request.name))}</span><span><strong>${esc(request.name)}</strong><small class="table-muted">Requested ${fmtDate(request.createdAt)}</small></span></div></td><td>${esc(request.email)}</td><td class="table-muted">${esc(request.ipAddress || '---')}</td><td class="table-actions"><button class="primary-btn small-btn" data-action="approve-admin-request" data-id="${esc(request.id)}">Assign role & approve</button><button class="danger-btn small-btn" data-action="reject-admin-request" data-id="${esc(request.id)}">Reject</button></td></tr>`).join('');
  const recentRows = recent.map(request => `<tr><td>${esc(request.name)}</td><td>${esc(request.email)}</td><td>${esc(request.roleName || '---')}</td><td class="table-muted">${fmtDate(request.resolvedAt)}</td><td><span class="status-pill ${request.status === 'approved' ? '' : 'status-danger'}">${request.status === 'approved' ? 'Approved' : 'Rejected'}</span></td></tr>`).join('');
  const mailNotice = approvals.mailConfigured ? '<span class="status-pill">Approval mail ready</span>' : '<span class="status-pill status-warning">Email setup required before approval</span>';
  return `${adminHeader('Pending Approval', 'Review administrator access requests, assign a role, and send the approved access notice.', '')}<section class="panel table-panel"><div class="panel-heading"><div><h2>Pending requests <span class="count-badge">${pending.length}</span></h2><span class="table-muted">Only the Superadmin can approve administrator access.</span></div>${mailNotice}</div>${pending.length ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Applicant</th><th>Email</th><th>Request IP</th><th>Actions</th></tr></thead><tbody>${pendingRows}</tbody></table></div>` : emptyState('No administrator access requests are waiting for approval.')}</section><section class="panel table-panel" style="margin-top:18px"><div class="panel-heading"><h2>Recent decisions</h2><span class="table-muted">Last 20 resolved requests</span></div>${recent.length ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Name</th><th>Email</th><th>Assigned role</th><th>Resolved</th><th>Status</th></tr></thead><tbody>${recentRows}</tbody></table></div>` : emptyState('Approved and rejected requests will appear here.')}</section>`;
}
function roleForm(role) {
  const permissions = role.permissions || [];
  openModal(`Configure ${role.name}`, `<form id="role-form" data-id="${esc(role.id)}"><div class="alert" role="alert"></div><p class="table-muted">Choose the workspaces this role can access. Course management includes separate Test and Exam controls; Settings includes academic sessions and semesters. Superadmin access and the Roles workspace remain system-locked.</p><label class="form-field">Maximum users<input name="maxUsers" type="number" min="1" max="100" value="${numeric(role.maxUsers)}" required></label><fieldset class="correct-options"><legend>Permissions</legend>${['overview', 'students', 'exams', 'questions', 'results', 'audit', 'newsletter', 'settings'].map(permission => `<label><input type="checkbox" name="permissions" value="${permission}" ${permissions.includes(permission) ? 'checked' : ''}> ${permission.replaceAll('_', ' ')}</label>`).join('')}</fieldset><div class="modal-actions"><button class="primary-btn">Save role</button></div></form>`);
}
function adminUserForm(user = null) {
  const editing = Boolean(user);
  openModal(editing ? 'Edit administrator' : 'Add administrator', `<form id="admin-user-form" data-id="${esc(user?.id || '')}"><div class="alert" role="alert"></div><label class="form-field">Full name<input name="name" value="${esc(user?.name || '')}" required></label><label class="form-field">Email<input name="email" type="email" value="${esc(user?.email || '')}" required></label><label class="form-field">${editing ? 'New password (optional)' : 'Password'}${passwordInput('admin-user-password', 'new-password', editing ? 'minlength="8"' : 'minlength="8" required')}</label><label class="form-field">Role<select class="select-field" name="roleId" required>${state.roles.map(role => `<option value="${esc(role.id)}" ${role.id === (user?.roleId || 'academic_coordinator') ? 'selected' : ''} ${role.systemLocked ? 'disabled' : ''}>${esc(role.name)}</option>`).join('')}</select></label><div class="modal-actions"><button class="primary-btn">${editing ? 'Save administrator' : 'Create administrator'}</button></div></form>`);
}
function approvalForm(request) {
  const roles = state.roles.filter(role => !role.systemLocked);
  const sendReady = state.adminApprovals?.mailConfigured;
  openModal('Assign role and approve', `<form id="approval-form" data-id="${esc(request?.id || '')}"><div class="alert ${sendReady ? '' : 'visible'}" role="alert">${sendReady ? '' : 'Email is not configured. Configure Gmail SMTP before approving this request.'}</div><p class="table-muted"><strong>${esc(request?.name || '')}</strong><br>${esc(request?.email || '')}</p><label class="form-field">Role<select class="select-field" name="roleId" required>${roles.map(role => `<option value="${esc(role.id)}">${esc(role.name)}</option>`).join('')}</select></label><p class="form-help">The applicant will receive an approval email listing the assigned role and permitted workspaces, then can sign in with the password submitted in their request.</p><div class="modal-actions"><button class="primary-btn" ${sendReady ? '' : 'disabled'}>Approve and send email</button></div></form>`);
}
function accountSettingsForm(account, tab = state.accountTab || 'profile') {
  state.account = account; state.accountTab = tab;
  const tabs = `<nav class="account-settings-tabs" aria-label="Account settings"><button class="${tab === 'profile' ? 'active' : ''}" data-action="account-tab" data-tab="profile">Profile</button><button class="${tab === 'security' ? 'active' : ''}" data-action="account-tab" data-tab="security">Security</button><button class="${tab === 'privacy' ? 'active' : ''}" data-action="account-tab" data-tab="privacy">Privacy</button></nav>`;
  let content = '';
  if (tab === 'profile') {
    const emailHelp = account.emailManagedByEnvironment ? '<p class="form-help">This Superadmin email is managed by the server environment.</p>' : '<p class="form-help">Changing your email sends a six-digit verification code to the new address before your sign-in email changes.</p>';
    const verification = account.pendingEmail ? `<form id="account-email-verification-form" class="account-verification"><p><strong>Verify ${esc(account.pendingEmail)}</strong><br>Enter the six-digit code sent to this address.</p><label class="form-field">Verification code<input name="code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label><button class="outline-btn">Verify email</button></form>` : '';
    content = `<form id="account-profile-form"><div class="alert" role="alert"></div><label class="form-field">Full name<input name="name" value="${esc(account.name || '')}" autocomplete="name" required></label><label class="form-field">Email address<input name="email" type="email" value="${esc(account.email || '')}" autocomplete="email" ${account.emailManagedByEnvironment ? 'readonly' : 'required'}></label>${emailHelp}<label class="form-field">Phone number<input name="phoneNumber" type="tel" inputmode="tel" autocomplete="tel" value="${esc(account.phoneNumber || '')}" placeholder="e.g. 0801 234 5678"></label><div class="modal-actions"><button class="primary-btn">Save changes</button></div></form>${verification}`;
  } else if (tab === 'security') {
    const disabled = account.passwordManagedByEnvironment ? 'disabled' : '';
    content = `<form id="account-password-form"><div class="alert ${account.passwordManagedByEnvironment ? 'visible' : ''}" role="alert">${account.passwordManagedByEnvironment ? 'This Superadmin password is managed in the server environment.' : ''}</div><p class="form-help">Choose a unique password of at least eight characters. Saving it signs out your other administrator sessions.</p><label class="form-field">Current password<input name="currentPassword" type="password" autocomplete="current-password" required ${disabled}></label><label class="form-field">New password<input name="newPassword" type="password" autocomplete="new-password" minlength="8" required ${disabled}></label><label class="form-field">Confirm new password<input name="confirmPassword" type="password" autocomplete="new-password" minlength="8" required ${disabled}></label><div class="modal-actions"><button class="primary-btn" ${disabled}>Update password</button></div></form>`;
  } else {
    content = `<section class="account-privacy"><h3>Signed-in devices</h3><p>Your current session remains active. You can end all other administrator sessions for this account if you used a shared device or suspect someone else has access.</p><button class="danger-btn" data-action="account-signout-others">Sign out other sessions</button><hr><h3>Account visibility</h3><p>Your name, email, role, and sign-in activity are visible to the Superadmin for account administration and audit purposes.</p></section>`;
  }
  openModal('Account Settings', `${tabs}<div class="account-settings-content">${content}</div>`);
}
function reviewPage() {
  const review = state.review;
  if (!review) return `${adminHeader('Result review', 'Select a result to inspect.')}<button class="outline-btn" data-route="admin/results">Back to results</button>`;
  const result = review.result || {}, student = review.student || {}, exam = review.exam || {};
  return `${adminHeader('Per-question review', `${esc(student.fullName || '')} · ${esc(exam.code || '')} · ${numeric(result.score)}%`, '<button class="outline-btn" data-route="admin/results">Back to results</button>')}<section class="panel review-list">${(review.questions || []).map((question, index) => `<article class="review-question"><div class="review-heading"><strong>Question ${index + 1}</strong><span class="status-pill ${question.isCorrect ? '' : 'status-danger'}">${question.isCorrect ? 'Correct' : 'Incorrect'}</span>${question.flagged ? '<span class="status-pill status-warning">Flagged</span>' : ''}</div><h2 class="math-rendered">${mathHtml(question.text)}</h2><div class="review-options">${(question.options || []).map((option, optionIndex) => `<div class="review-option ${question.correctOptions?.includes(optionIndex) ? 'is-correct' : ''} ${question.answers?.includes(optionIndex) ? 'is-selected' : ''}"><strong>${String.fromCharCode(65 + optionIndex)}.</strong> <span class="math-rendered">${mathHtml(option)}</span>${question.correctOptions?.includes(optionIndex) ? ' <small>Correct answer</small>' : ''}${question.answers?.includes(optionIndex) ? ' <small>Student answer</small>' : ''}</div>`).join('')}</div></article>`).join('') || emptyState('No question details are available for this result.')}</section>`;
}
function legacyStudentResultsPage() {
  const report = state.report;
  if (!report) return `${adminHeader('Student result', 'Select a student to view a result sheet.')}<button class="outline-btn" data-route="admin/students">Back to students</button>`;
  const groups = report.sessions || {};
  return `${adminHeader(`${esc(report.student?.fullName || '')} result sheet`, `${esc(report.student?.matricNumber || '')} · ${esc(report.student?.department || '')}`, '<button class="outline-btn" data-action="export-csv">Export CSV</button>')}<section class="panel table-panel"><div class="table-scroll"><table class="data-table"><thead><tr><th>Course</th><th>Unit</th><th>Score</th><th>Grade</th><th>Grade point</th><th>Quality points</th><th>Session</th><th></th></tr></thead><tbody>${(report.items || []).map(item => `<tr><td>${esc(item.courseCode)}<small class="table-muted" style="display:block">${esc(item.courseTitle || '')}</small></td><td>${numeric(item.courseUnit)}</td><td>${numeric(item.score)}%</td><td><strong>${esc(item.grade)}</strong></td><td>${numeric(item.gradePoint)}</td><td>${numeric(item.qualityPoints)}</td><td>${esc(item.session || 'Unassigned')}</td><td><button class="outline-btn small-btn" data-route="admin/review/${esc(item.id)}">Review</button></td></tr>`).join('') || '<tr><td colspan="8">No completed courses yet.</td></tr>'}</tbody></table></div></section><section class="stat-grid" style="margin-top:18px">${Object.entries(groups).map(([session, value]) => statCard(`GPA · ${session}`, numeric(value.gpa).toFixed(2), `${numeric(value.courseUnit)} total units`)).join('')}${statCard('Cumulative GPA', numeric(report.cgpa).toFixed(2), 'Across all completed sessions')}</section>`;
}
function studentResultsPage() {
  const report = state.report;
  if (!report) return `${adminHeader('Student result', 'Select a student to view a result sheet.')}<button class="outline-btn" data-route="admin/results">Back to results</button>`;
  const student = report.student || {};
  const periods = report.availablePeriods || [];
  const selectedSession = report.selectedSession || {};
  const selectedSemester = report.selectedSemester || {};
  const selectedValue = `${selectedSession.id || ''}|${selectedSemester.id || ''}`;
  const periodSelector = `<label class="result-period-select">Academic session and semester<select class="select-field" data-result-period-select>${periods.map(period => { const value = `${period.sessionId}|${period.semesterId}`; return `<option value="${esc(value)}" ${value === selectedValue ? 'selected' : ''}>${esc(period.sessionLabel)} · ${esc(period.semesterLabel)}</option>`; }).join('')}</select></label>`;
  const componentRow = (label, result, maxMark) => {
    if (numeric(maxMark) <= 0) return `<div class="result-component-row not-applicable"><strong>${label}</strong><span>Not applicable</span></div>`;
    if (!result) return `<div class="result-component-row pending"><strong>${label}</strong><span>Not submitted</span><em>In progress</em></div>`;
    const automatic = result.status === 'auto_submitted';
    return `<div class="result-component-row"><strong>${label}</strong><span><b>${numeric(result.rawScore).toFixed(1)}%</b> raw</span><span><b>${numeric(result.scaledScore, result.score).toFixed(1)} / ${numeric(maxMark)}</b> scaled</span><span>${fmtDate(result.submittedAt)}</span><span class="status-pill ${automatic ? 'status-warning' : ''}">${automatic ? 'Auto-submitted' : 'Submitted'}</span><button class="outline-btn small-btn" data-route="admin/review/${esc(result.id)}">Review</button></div>`;
  };
  const groups = (report.items || []).map(item => `<article class="result-course-group"><header><div><strong>${esc(item.courseCode)}</strong><h2>${esc(item.courseTitle)}</h2><small>${numeric(item.courseUnit)} unit${numeric(item.courseUnit) === 1 ? '' : 's'}</small></div><span class="status-pill ${item.status === 'completed' ? '' : 'status-warning'}">${item.status === 'completed' ? 'Ready to calculate' : 'In progress'}</span></header><div class="result-component-list">${componentRow('Test', item.testResult, item.testMaxMark)}${componentRow('Exam', item.examResult, item.examMaxMark)}</div></article>`).join('');
  const incomplete = (report.items || []).filter(item => item.status !== 'completed').length;
  const complete = (report.items || []).filter(item => item.status === 'completed').length;
  const calculated = Boolean(report.calculated && report.calculatedResult);
  const savedItems = report.calculatedResult?.items || [];
  const calculatedRows = savedItems.map(item => `<tr><td><strong>${esc(item.courseCode)}</strong><small class="table-muted" style="display:block">${esc(item.courseTitle || '')}</small></td><td>${numeric(item.courseUnit)}</td><td>${item.testScore == null ? '---' : numeric(item.testScore).toFixed(1)}</td><td>${item.examScore == null ? '---' : numeric(item.examScore).toFixed(1)}</td><td><strong>${numeric(item.total).toFixed(1)}</strong></td><td>${esc(item.grade)}</td><td>${numeric(item.gradePoint).toFixed(2)}</td><td>${numeric(item.qualityPoints).toFixed(2)}</td></tr>`).join('');
  const calculationAction = complete ? `<button class="primary-btn" data-action="calculate-student-result">${calculated ? 'Recalculate result' : 'Calculate result'}</button>` : '';
  const printHeader = `<div class="print-result-header"><img src="${esc(state.settings?.resultLogoUrl || 'CACSA%20Logo.jpeg')}" alt="CACSA LAUTECH logo"><div><h1>CACSA LAUTECH CBT</h1><p>Academic result sheet</p></div><div><strong>Date printed</strong><br>${esc(new Date().toLocaleDateString())}</div></div><div class="print-student-meta"><span><strong>Student:</strong> ${esc(student.fullName || '')}</span><span><strong>Matric:</strong> ${esc(student.matricNumber || '')}</span><span><strong>Session:</strong> ${esc(selectedSession.label || '')}</span><span><strong>Semester:</strong> ${esc(selectedSemester.label || '')}</span></div>`;
  const resultTable = calculated ? `<section class="result-sheet panel table-panel calculated-result">${printHeader}<div class="panel-heading"><div><h2>Calculated result</h2><span class="table-muted">Calculated ${fmtDate(report.calculatedResult.calculatedAt)}</span></div><div class="table-actions"><button class="outline-btn" data-action="export-csv">Export CSV</button><button class="primary-btn" data-action="print-result-sheet">Print result</button></div></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Course</th><th>Unit</th><th>Test</th><th>Exam</th><th>Total</th><th>Grade</th><th>Grade point</th><th>Quality points</th></tr></thead><tbody>${calculatedRows}</tbody></table></div><div class="print-gpa"><strong>Semester GPA: ${numeric(report.calculatedResult.semesterGpa).toFixed(2)}</strong><strong>Cumulative CGPA: ${numeric(report.calculatedResult.cgpa).toFixed(2)}</strong></div></section>` : '';
  return `${adminHeader(`${esc(student.fullName || '')} · results`, `${esc(student.matricNumber || '')} · ${esc(student.department || '')}`, '<button class="outline-btn" data-route="admin/results">← Back to student results</button>')}<section class="panel result-detail-period"><div><span class="section-kicker">Student result detail</span><h2>${esc(student.fullName || '')}</h2><p>${esc(student.matricNumber || '')}</p></div>${periods.length ? periodSelector : '<span class="table-muted">No submitted periods yet.</span>'}</section><section class="result-course-summary"><div class="panel-heading"><div><h2>Submitted components by course</h2><span class="table-muted">Test and Exam submissions are kept together for each course.</span></div>${calculationAction}</div>${incomplete ? `<p class="result-in-progress-note">${incomplete} of ${report.items.length} course${report.items.length === 1 ? '' : 's'} still in progress. They will be excluded until all required components are submitted.</p>` : '<p class="result-ready-note">All submitted courses are ready for result calculation.</p>'}${groups || emptyState('No submitted components exist for this academic period.')}</section>${resultTable}`;
}

function settingsPage() {
  const scale = state.settings?.gradingScale || [];
  const policy = state.settings?.integrityPolicy || {};
  return `${adminHeader('Settings', 'Configure grading and exam-integrity responses.')}<form id="grading-form"><section class="panel"><div class="panel-heading"><h2>Grading scale</h2></div><p class="table-muted">Ranges must cover scores from 0 to 100 without overlapping. Saved changes recalculate existing results.</p><div class="table-scroll"><table class="data-table"><thead><tr><th>Minimum</th><th>Maximum</th><th>Grade</th><th>Point</th><th></th></tr></thead><tbody id="grading-rows">${scale.map(gradingRow).join('')}</tbody></table></div><div class="modal-actions"><button type="button" class="outline-btn" data-action="add-grade-row">Add row</button></div></section><section class="panel" style="margin-top:18px"><div class="panel-heading"><h2>Exam-integrity response</h2></div><p class="table-muted">A browser signal is not proof of misconduct. Browsers cannot detect operating-system screenshots or mobile screenshot gestures. “Warn student” locks after the configured number of repeat events.</p><div class="table-scroll"><table class="data-table"><thead><tr><th>Signal</th><th>Response</th><th>Lock after</th></tr></thead><tbody>${INTEGRITY_EVENT_TYPES.map(event => integrityPolicyRow(event, policy[event])).join('')}</tbody></table></div></section><div class="modal-actions" style="margin-top:18px"><button class="primary-btn">Save settings</button></div></form><section class="panel" style="margin-top:18px"><button class="danger-btn" data-action="admin-logout">Sign out</button></section>`;
}
function academicSettingsPage() {
  const scale = state.settings?.gradingScale || [];
  const policy = state.settings?.integrityPolicy || {};
  const activeSession = state.academicSessions.find(item => item.isActive);
  const activeSemester = state.semesters.find(item => item.isActive);
  const sessionRows = state.academicSessions.map(session => `<tr><td><strong>${esc(session.label)}</strong></td><td><span class="status-pill ${session.isActive ? '' : 'status-muted'}">${session.isActive ? 'Active' : 'Inactive'}</span></td><td class="table-actions"><button class="outline-btn small-btn" data-action="edit-academic-session" data-id="${esc(session.id)}">Edit</button><button class="danger-btn small-btn" data-action="delete-academic-session" data-id="${esc(session.id)}">Delete</button></td></tr>`).join('');
  const semesterRows = state.semesters.map(semester => `<tr><td>${esc(state.academicSessions.find(item => item.id === semester.sessionId)?.label || 'Unknown')}</td><td><strong>${esc(semester.label)}</strong></td><td>${esc(semester.startDate)} to ${esc(semester.endDate)}</td><td><span class="status-pill ${semester.isActive ? '' : 'status-muted'}">${semester.isActive ? 'Active' : 'Inactive'}</span></td><td class="table-actions"><button class="outline-btn small-btn" data-action="edit-semester" data-id="${esc(semester.id)}">Edit</button><button class="danger-btn small-btn" data-action="delete-semester" data-id="${esc(semester.id)}">Delete</button></td></tr>`).join('');
  return `${adminHeader('Settings', 'Configure academic periods, grading, and exam-integrity responses.')}<section class="panel"><div class="panel-heading"><div><h2>Academic sessions & semesters</h2><p class="table-muted">New courses use the active session and semester by default. Current: <strong>${esc(activeSession?.label || 'Not set')}</strong> / <strong>${esc(activeSemester?.label || 'Not set')}</strong>.</p></div><div class="table-actions"><button class="outline-btn" data-action="new-semester">+ Semester</button><button class="primary-btn" data-action="new-academic-session">+ Academic session</button></div></div><div class="table-scroll"><table class="data-table"><thead><tr><th>Academic session</th><th>Status</th><th>Actions</th></tr></thead><tbody>${sessionRows || '<tr><td colspan="3">No academic sessions yet.</td></tr>'}</tbody></table></div><div class="table-scroll" style="margin-top:18px"><table class="data-table"><thead><tr><th>Session</th><th>Semester</th><th>Dates</th><th>Status</th><th>Actions</th></tr></thead><tbody>${semesterRows || '<tr><td colspan="5">No semesters yet.</td></tr>'}</tbody></table></div></section><form id="grading-form"><section class="panel" style="margin-top:18px"><div class="panel-heading"><h2>Grading scale</h2></div><p class="table-muted">Ranges must cover scores from 0 to 100 without overlapping. Completed course totals use this scale.</p><div class="table-scroll"><table class="data-table"><thead><tr><th>Minimum</th><th>Maximum</th><th>Grade</th><th>Point</th><th></th></tr></thead><tbody id="grading-rows">${scale.map(gradingRow).join('')}</tbody></table></div><div class="modal-actions"><button type="button" class="outline-btn" data-action="add-grade-row">Add row</button></div></section><section class="panel" style="margin-top:18px"><div class="panel-heading"><h2>Exam-integrity response</h2></div><p class="table-muted">A browser signal is not proof of misconduct. Browsers cannot detect operating-system screenshots or mobile screenshot gestures. “Warn student” locks after the configured number of repeat events.</p><div class="table-scroll"><table class="data-table"><thead><tr><th>Signal</th><th>Response</th><th>Lock after</th></tr></thead><tbody>${INTEGRITY_EVENT_TYPES.map(event => integrityPolicyRow(event, policy[event])).join('')}</tbody></table></div></section><div class="modal-actions" style="margin-top:18px"><button class="primary-btn">Save settings</button></div></form><section class="panel" style="margin-top:18px"><button class="danger-btn" data-action="admin-logout">Sign out</button></section>`;
}

function settingsPage() {
  const logoField = `<section class="panel" style="margin-top:18px"><div class="panel-heading"><h2>Result-sheet branding</h2></div><label class="form-field">Logo image path or URL<input name="resultLogoUrl" value="${esc(state.settings?.resultLogoUrl || 'CACSA%20Logo.jpeg')}" placeholder="CACSA%20Logo.jpeg" required></label><p class="table-muted">This image appears at the top of every printable result sheet.</p></section>`;
  const security = state.settings?.examSecurity || {};
  const optionSecurityField = `<section class="panel" style="margin-top:18px"><div class="panel-heading"><h2>Assessment security</h2></div><label class="checkbox-field"><input type="checkbox" name="optionShuffleEnabled" ${security.optionShuffleEnabled !== false ? 'checked' : ''}> Shuffle answer options separately for every student session</label><p class="table-muted">The selected answer is mapped back to the original answer key during grading. Admin question-bank and result-review screens always keep the original option order.</p><label class="checkbox-field"><input type="checkbox" name="fingerprintFlaggingEnabled" ${security.fingerprintFlaggingEnabled !== false ? 'checked' : ''}> Flag a device used by different students for the same assessment</label><p class="table-muted">Uses a lightweight hash of browser, screen, platform, and timezone only. It is an investigation signal---not proof---and never automatically locks a student in a shared computer lab.</p><label class="checkbox-field"><input type="checkbox" name="concurrentIpBlockEnabled" ${security.concurrentIpBlockEnabled !== false ? 'checked' : ''}> Block a second login for the same active assessment from a different IP address</label><p class="table-muted">A refresh or reconnect from the same network is allowed. A rejected different-network attempt is retained in the live audit monitor.</p><label class="checkbox-field"><input type="checkbox" name="loginRateLimitEnabled" ${security.loginRateLimitEnabled !== false ? 'checked' : ''}> Limit repeated exam-login attempts</label><div class="form-grid"><label class="form-field">Failed attempts before lock<input name="loginAttemptLimit" type="number" min="3" max="20" value="${numeric(security.loginAttemptLimit, 5)}"></label><label class="form-field">Failure window (minutes)<input name="loginAttemptWindowMinutes" type="number" min="1" max="60" value="${numeric(security.loginAttemptWindowMinutes, 10)}"></label><label class="form-field">Lockout time (minutes)<input name="loginLockoutMinutes" type="number" min="1" max="120" value="${numeric(security.loginLockoutMinutes, 15)}"></label><label class="form-field">Network attempts per minute<input name="ipAttemptLimit" type="number" min="5" max="200" value="${numeric(security.ipAttemptLimit, 30)}"></label><label class="form-field">Network window (minutes)<input name="ipAttemptWindowMinutes" type="number" min="1" max="60" value="${numeric(security.ipAttemptWindowMinutes, 1)}"></label></div><p class="table-muted">The default allows normal mistakes: 5 failed attempts in 10 minutes, then a 15-minute lock. The network limit counts all login requests to slow broad password guessing.</p></section>`;
  return academicSettingsPage().replace('<form id="grading-form">', `<form id="grading-form">${logoField}${optionSecurityField}`);
}

function gradingRow(row = {}) {
  return `<tr><td><input class="select-field" name="minScore" type="number" min="0" max="100" step="0.01" value="${esc(row.minScore ?? '')}" required></td><td><input class="select-field" name="maxScore" type="number" min="0" max="100" step="0.01" value="${esc(row.maxScore ?? '')}" required></td><td><input class="select-field" name="grade" maxlength="5" value="${esc(row.grade ?? '')}" required></td><td><input class="select-field" name="gradePoint" type="number" min="0" max="5" step="0.01" value="${esc(row.gradePoint ?? '')}" required></td><td><button type="button" class="danger-btn small-btn" data-action="remove-grade-row" aria-label="Remove grade row">Remove</button></td></tr>`;
}
function integrityPolicyRow(event, rule = {}) {
  const mode = rule.mode || 'warn', label = event.replaceAll('_', ' ');
  return `<tr class="integrity-policy-row" data-event="${esc(event)}"><td><strong>${esc(label)}</strong></td><td><select class="select-field" name="mode"><option value="log" ${mode === 'log' ? 'selected' : ''}>Log only</option><option value="warn" ${mode === 'warn' ? 'selected' : ''}>Warn student</option><option value="lock" ${mode === 'lock' ? 'selected' : ''}>Auto-lock immediately</option></select></td><td><input class="select-field" name="lockAfter" type="number" min="1" max="20" value="${numeric(rule.lockAfter, 3)}" aria-label="Lock after repeat events for ${esc(label)}"></td></tr>`;
}

function openModal(title, content, options = {}) {
  closeModal();
  state.modalTrigger = document.activeElement;
  const backdrop = document.createElement('div');
  backdrop.className = 'modal-backdrop'; backdrop.id = 'modal';
  backdrop.dataset.closeable = options.closeable === false ? 'false' : 'true';
  backdrop.innerHTML = `<section class="modal" role="dialog" aria-modal="true" aria-labelledby="modal-title" tabindex="-1">${options.closeable === false ? '' : '<button class="back-link modal-close" data-action="close-modal">× Close</button>'}<h2 id="modal-title">${esc(title)}</h2><div id="modal-content">${content}</div></section>`;
  document.body.append(backdrop);
  backdrop.querySelector('input, textarea, select, button, [tabindex]')?.focus();
  updateMathPreview(document.querySelector('#modal [data-math-field]'));
}
function closeModal() {
  const modal = document.querySelector('#modal');
  if (modal) modal.remove();
  if (state.modalTrigger?.isConnected) state.modalTrigger.focus();
  state.modalTrigger = null; activeMathField = null;
}
function confirmAction(title, message, label, callback) {
  state.confirmCallback = callback;
  openModal(title, `<p>${esc(message)}</p><div class="modal-actions"><button class="outline-btn" data-action="close-modal">Cancel</button><button class="danger-btn" data-action="confirm-action">${esc(label)}</button></div>`);
}
function studentForm(student = null) {
  const edit = Boolean(student);
  openModal(edit ? 'Edit student' : 'Register student', `<form id="student-form" data-id="${esc(student?.id || '')}"><div class="alert" role="alert"></div><label class="form-field">Full name<input name="fullName" value="${esc(student?.fullName || '')}" required></label><label class="form-field">Email<input name="email" type="email" value="${esc(student?.email || '')}" required></label><label class="form-field">Phone number<input name="phoneNumber" type="tel" inputmode="tel" autocomplete="tel" value="${esc(student?.phoneNumber || '')}" placeholder="e.g. 0801 234 5678" required></label><label class="form-field">Matric number<input name="matricNumber" value="${esc(student?.matricNumber || '')}" required></label><label class="form-field">Department<input name="department" value="${esc(student?.department || '')}" required></label><div class="modal-actions"><button class="primary-btn">${edit ? 'Save changes' : 'Register student'}</button></div></form>`);
}
function bulkStudentsForm() {
  openModal('Import students', `<p>Upload a UTF-8 CSV file with the headers <strong>fullName,email,phoneNumber,matricNumber,department</strong>, or paste the CSV below. Existing matric numbers will be rejected.</p><form id="bulk-students-form"><div class="alert" role="alert"></div><label class="form-field">CSV file<input name="file" type="file" accept=".csv,text/csv"></label><label class="form-field">Or paste CSV<textarea name="csv" rows="7" placeholder="fullName,email,phoneNumber,matricNumber,department"></textarea></label><div class="modal-actions"><button class="primary-btn">Import students</button></div></form>`);
}
function passwordForm(student) {
  const components = [...state.adminExams].sort((a, b) => Number(b.active) - Number(a.active) || String(a.code).localeCompare(String(b.code)) || String(a.component || '').localeCompare(String(b.component || '')));
  openModal('Generate assessment password', `<form id="password-form"><div class="alert" role="alert"></div><p class="table-muted">A Test and an Exam are separate components and each needs its own password. Active components are listed first.</p><label class="form-field">Matric number<input name="matricNumber" value="${esc(student?.matricNumber || '')}" required></label><label class="form-field">Assessment component<select class="select-field" name="examId" required>${components.map(exam => `<option value="${esc(exam.id)}">${esc(exam.code)} --- ${esc(exam.courseTitle || exam.title)} (${esc(exam.componentLabel || (exam.component === 'test' ? 'Test' : 'Exam'))})${exam.active ? ' --- active now' : ''}</option>`).join('')}</select></label><div class="modal-actions"><button class="primary-btn">Generate password</button></div></form>`);
}
function toLocalInput(value) {
  if (!value) return '';
  const date = new Date(value);
  if (Number.isNaN(date.getTime())) return '';
  const part = number => String(number).padStart(2, '0');
  return `${date.getFullYear()}-${part(date.getMonth() + 1)}-${part(date.getDate())}T${part(date.getHours())}:${part(date.getMinutes())}`;
}
function examForm(exam = null) {
  const edit = Boolean(exam);
  openModal(edit ? 'Edit exam' : 'Create exam', `<form id="exam-form" data-id="${esc(exam?.id || '')}"><div class="alert" role="alert"></div><p class="form-help">Students can enter only while the status is <strong>Active</strong> and the current server time falls between Start and End.</p><div class="form-grid"><label class="form-field">Code<input name="code" value="${esc(exam?.code || '')}" required></label><label class="form-field">Title<input name="title" value="${esc(exam?.title || '')}" required></label><label class="form-field wide">Description<input name="description" value="${esc(exam?.description || '')}"></label><label class="form-field">Duration (minutes)<input name="duration" type="number" min="1" value="${numeric(exam?.duration, 30)}" required></label><label class="form-field">Question count<input name="questionCount" type="number" min="1" value="${numeric(exam?.questionCount, 10)}" required></label><label class="form-field">Course unit<input name="courseUnit" type="number" min="1" max="6" value="${numeric(exam?.courseUnit, 3)}" required></label><label class="form-field wide">Session / Semester<input name="session" value="${esc(exam?.session || '')}" placeholder="2025/2026 - First Semester"></label><label class="form-field">Start<input name="startAt" type="datetime-local" value="${toLocalInput(exam?.startAt)}" required></label><label class="form-field">End<input name="endAt" type="datetime-local" value="${toLocalInput(exam?.endAt)}" required></label><label class="form-field wide">Status<select class="select-field" name="status"><option value="draft" ${!exam || exam.status === 'draft' ? 'selected' : ''}>Draft</option><option value="published" ${exam?.status === 'published' ? 'selected' : ''}>Published</option><option value="active" ${exam?.status === 'active' ? 'selected' : ''}>Active</option></select></label></div><div class="modal-actions"><button class="primary-btn">Save assessment</button></div></form>`);
}
function academicSessionForm(session = null) {
  openModal(session ? 'Edit academic session' : 'Create academic session', `<form id="academic-session-form" data-id="${esc(session?.id || '')}"><div class="alert" role="alert"></div><label class="form-field">Session label<input name="label" value="${esc(session?.label || '')}" placeholder="e.g. 2026/2027" required></label><label class="checkbox-field"><input type="checkbox" name="isActive" ${session?.isActive || !state.academicSessions.length ? 'checked' : ''}> Make this the active session</label><div class="modal-actions"><button class="primary-btn">Save session</button></div></form>`);
}
function semesterForm(semester = null) {
  const activeSession = state.academicSessions.find(item => item.isActive)?.id || '';
  openModal(semester ? 'Edit semester' : 'Create semester', `<form id="semester-form" data-id="${esc(semester?.id || '')}"><div class="alert" role="alert"></div><label class="form-field">Academic session<select class="select-field" name="sessionId" required>${state.academicSessions.map(session => `<option value="${esc(session.id)}" ${session.id === (semester?.sessionId || activeSession) ? 'selected' : ''}>${esc(session.label)}</option>`).join('')}</select></label><label class="form-field">Semester label<input name="label" value="${esc(semester?.label || '')}" placeholder="e.g. Harmattan Semester" required></label><div class="form-grid"><label class="form-field">Start date<input name="startDate" type="date" value="${esc(semester?.startDate || '')}" required></label><label class="form-field">End date<input name="endDate" type="date" value="${esc(semester?.endDate || '')}" required></label></div><label class="checkbox-field"><input type="checkbox" name="isActive" ${semester?.isActive || !state.semesters.length ? 'checked' : ''}> Make this the active semester for this session</label><div class="modal-actions"><button class="primary-btn">Save semester</button></div></form>`);
}
function courseForm(course = null) {
  const edit = Boolean(course);
  const activeSession = state.academicSessions.find(item => item.isActive)?.id || '';
  const activeSemester = state.semesters.find(item => item.isActive)?.id || '';
  const components = course?.components || [];
  const exam = components.find(item => item.component === 'exam') || {};
  // Legacy courses are intentionally Exam-only. Give their zero-mark Test a usable
  // inherited window if an administrator opens the course editor, without changing it.
  const test = components.find(item => item.component === 'test') || (course ? {maxMark: numeric(course.testMaxMark, 0), duration: exam.duration, questionCount: exam.questionCount, startAt: exam.startAt, endAt: exam.endAt, status: 'draft'} : {});
  const dateInput = value => toLocalInput(value);
  const componentFields = (label, key, data, maxMark) => `<fieldset class="component-fields"><legend>${label} component</legend><div class="form-grid"><label class="form-field">Maximum mark<input name="${key}MaxMark" type="number" min="0" max="100" step="0.1" value="${numeric(data.maxMark, maxMark)}" required></label><label class="form-field">Duration (minutes)<input name="${key}Duration" type="number" min="1" value="${numeric(data.duration, 30)}" required></label><label class="form-field">Question count<input name="${key}QuestionCount" type="number" min="1" value="${numeric(data.questionCount, 10)}" required></label><label class="form-field">Status<select class="select-field" name="${key}Status"><option value="draft" ${(!data.status || data.status === 'draft') ? 'selected' : ''}>Draft</option><option value="published" ${data.status === 'published' ? 'selected' : ''}>Published</option><option value="active" ${data.status === 'active' ? 'selected' : ''}>Active</option></select></label><label class="form-field">Start<input name="${key}StartAt" type="datetime-local" value="${dateInput(data.startAt)}" required></label><label class="form-field">End<input name="${key}EndAt" type="datetime-local" value="${dateInput(data.endAt)}" required></label></div></fieldset>`;
  openModal(edit ? 'Edit course and components' : 'Create course and components', `<form id="course-form" data-id="${esc(course?.id || '')}"><div class="alert" role="alert"></div><p class="form-help">Test and Exam marks may total up to 100. Students receive distinct passwords and question banks for each component.</p><div class="form-grid"><label class="form-field">Course code<input name="code" value="${esc(course?.code || '')}" required></label><label class="form-field">Course title<input name="title" value="${esc(course?.title || '')}" required></label><label class="form-field">Course unit<input name="courseUnit" type="number" min="1" max="6" value="${numeric(course?.courseUnit, 3)}" required></label><label class="form-field">Academic session<select class="select-field" name="sessionId" required>${state.academicSessions.map(session => `<option value="${esc(session.id)}" ${session.id === (course?.sessionId || activeSession) ? 'selected' : ''}>${esc(session.label)}</option>`).join('')}</select></label><label class="form-field">Semester<select class="select-field" name="semesterId" required>${state.semesters.map(semester => `<option value="${esc(semester.id)}" ${semester.id === (course?.semesterId || activeSemester) ? 'selected' : ''}>${esc(semester.label)}</option>`).join('')}</select></label><label class="form-field wide">Description<input name="description" value="${esc(course?.description || '')}"></label></div>${componentFields('Test', 'test', test, course ? numeric(course.testMaxMark, 0) : 30)}${componentFields('Exam', 'exam', exam, course ? numeric(course.examMaxMark, 100) : 70)}<div class="modal-actions"><button class="primary-btn">Save course</button></div></form>`);
}

function courseForm(course = null) {
  const edit = Boolean(course), components = course?.components || [];
  const exam = components.find(item => item.component === 'exam') || {};
  const test = components.find(item => item.component === 'test') || (course ? {maxMark: numeric(course.testMaxMark, 0), duration: exam.duration, questionCount: exam.questionCount, startAt: exam.startAt, endAt: exam.endAt, status: 'draft'} : {});
  const activeSession = state.academicSessions.find(item => item.isActive);
  const activeSemester = state.semesters.find(item => item.isActive);
  const sessionLabel = state.academicSessions.find(item => item.id === course?.sessionId)?.label || activeSession?.label || '';
  const semesterLabel = state.semesters.find(item => item.id === course?.semesterId)?.label || activeSemester?.label || 'Harmattan Semester';
  const componentFields = (label, key, data, fallbackMark) => `<fieldset class="component-fields"><legend>${label} component</legend><div class="form-grid"><label class="form-field">Maximum mark<input name="${key}MaxMark" type="number" min="0" max="100" step="0.1" value="${numeric(data.maxMark, fallbackMark)}" required></label><label class="form-field">Pass threshold<input name="${key}PassThreshold" type="number" min="0" max="${numeric(data.maxMark, fallbackMark)}" step="0.1" value="${numeric(data.passThreshold, numeric(data.maxMark, fallbackMark) * .5)}" required></label><label class="form-field">Duration (minutes)<input name="${key}Duration" type="number" min="1" value="${numeric(data.duration, 30)}" required></label><label class="form-field">Question count<input name="${key}QuestionCount" type="number" min="1" value="${numeric(data.questionCount, 10)}" required></label><label class="form-field">Status<select class="select-field" name="${key}Status"><option value="draft" ${(!data.status || data.status === 'draft') ? 'selected' : ''}>Draft</option><option value="published" ${data.status === 'published' ? 'selected' : ''}>Published</option><option value="active" ${data.status === 'active' ? 'selected' : ''}>Active</option></select></label><label class="form-field">Start<input name="${key}StartAt" type="datetime-local" value="${toLocalInput(data.startAt)}" required></label><label class="form-field">End<input name="${key}EndAt" type="datetime-local" value="${toLocalInput(data.endAt)}" required></label></div></fieldset>`;
  openModal(edit ? 'Edit course and components' : 'Create course and components', `<form id="course-form" data-id="${esc(course?.id || '')}"><div class="alert" role="alert"></div><p class="form-help">Type an academic session (for example, 2026/2027). Existing values are suggested and a new one is saved automatically. Each course uses either Harmattan or Rain Semester.</p><div class="form-grid"><label class="form-field">Course code<input name="code" value="${esc(course?.code || '')}" required></label><label class="form-field">Course title<input name="title" value="${esc(course?.title || '')}" required></label><label class="form-field">Course category<input name="category" value="${esc(course?.category || '')}" placeholder="e.g. Pure & Applied Sciences" required></label><label class="form-field">Course unit<input name="courseUnit" type="number" min="1" max="6" value="${numeric(course?.courseUnit, 3)}" required></label><label class="form-field">Academic session<input name="sessionLabel" list="academic-session-labels" value="${esc(sessionLabel)}" placeholder="e.g. 2026/2027" required><datalist id="academic-session-labels">${state.academicSessions.map(item => `<option value="${esc(item.label)}">`).join('')}</datalist></label><label class="form-field">Semester<select class="select-field" name="semesterLabel" required><option value="Harmattan Semester" ${semesterLabel === 'Harmattan Semester' ? 'selected' : ''}>Harmattan Semester</option><option value="Rain Semester" ${semesterLabel === 'Rain Semester' ? 'selected' : ''}>Rain Semester</option></select></label><label class="form-field wide">Description<input name="description" value="${esc(course?.description || '')}"></label></div>${componentFields('Test', 'test', test, course ? numeric(course.testMaxMark, 0) : 30)}${componentFields('Exam', 'exam', exam, course ? numeric(course.examMaxMark, 100) : 70)}<div class="modal-actions"><button class="primary-btn">Save course</button></div></form>`);
}

function semesterForm(semester = null) {
  const activeSession = state.academicSessions.find(item => item.isActive)?.id || '';
  openModal(semester ? 'Edit semester' : 'Create semester', `<form id="semester-form" data-id="${esc(semester?.id || '')}"><div class="alert" role="alert"></div><label class="form-field">Academic session<select class="select-field" name="sessionId" required>${state.academicSessions.map(session => `<option value="${esc(session.id)}" ${session.id === (semester?.sessionId || activeSession) ? 'selected' : ''}>${esc(session.label)}</option>`).join('')}</select></label><label class="form-field">Semester<select class="select-field" name="label" required><option value="Harmattan Semester" ${(semester?.label || 'Harmattan Semester') === 'Harmattan Semester' ? 'selected' : ''}>Harmattan Semester</option><option value="Rain Semester" ${semester?.label === 'Rain Semester' ? 'selected' : ''}>Rain Semester</option></select></label><div class="form-grid"><label class="form-field">Start date<input name="startDate" type="date" value="${esc(semester?.startDate || '')}" required></label><label class="form-field">End date<input name="endDate" type="date" value="${esc(semester?.endDate || '')}" required></label></div><label class="checkbox-field"><input type="checkbox" name="isActive" ${semester?.isActive || !state.semesters.length ? 'checked' : ''}> Make this the active semester for this session</label><div class="modal-actions"><button class="primary-btn">Save semester</button></div></form>`);
}

function componentForm(component) {
  if (!component) return toast('Assessment component not found.', 'error');
  const label = component.componentLabel || component.component || 'Assessment';
  openModal(`Edit ${label}`, `<form id="component-form" data-id="${esc(component.id)}"><div class="alert" role="alert"></div><p class="form-help">This updates only the ${esc(label)} component. It appears to students only when its status is Active and the current time is inside its window.</p><div class="form-grid"><label class="form-field">Maximum mark<input name="maxMark" type="number" min="0" max="100" step="0.1" value="${numeric(component.maxMark)}" required></label><label class="form-field">Pass threshold<input name="passThreshold" type="number" min="0" max="${numeric(component.maxMark)}" step="0.1" value="${numeric(component.passThreshold, numeric(component.maxMark) * .5)}" required></label><label class="form-field">Duration (minutes)<input name="duration" type="number" min="1" value="${numeric(component.duration)}" required></label><label class="form-field">Question count<input name="questionCount" type="number" min="1" value="${numeric(component.questionCount)}" required></label><label class="form-field">Status<select class="select-field" name="status"><option value="draft" ${component.status === 'draft' ? 'selected' : ''}>Draft</option><option value="published" ${component.status === 'published' ? 'selected' : ''}>Published</option><option value="active" ${component.status === 'active' ? 'selected' : ''}>Active</option></select></label><label class="form-field">Start<input name="startAt" type="datetime-local" value="${toLocalInput(component.startAt)}" required></label><label class="form-field">End<input name="endAt" type="datetime-local" value="${toLocalInput(component.endAt)}" required></label></div><div class="modal-actions"><button class="primary-btn">Save ${esc(label)}</button></div></form>`);
}

function questionForm(question = null) {
  const edit = Boolean(question);
  const options = question?.options || [];
  openModal(edit ? 'Edit question' : 'Add question', `<form id="question-form" data-id="${esc(question?.id || '')}"><div class="alert" role="alert"></div><label class="form-field">Assessment component<select class="select-field" name="examId" required>${state.adminExams.map(exam => `<option value="${esc(exam.id)}" ${exam.id === (question?.examId || parts()[2]) ? 'selected' : ''}>${esc(exam.code)} --- ${esc(exam.componentLabel || exam.component || 'Exam')}</option>`).join('')}</select></label><label class="form-field">Question text<textarea class="math-input math-question-input" data-math-field="Question text" name="text" rows="3" required>${esc(question?.text || '')}</textarea></label>${mathKeyboard()}${[0, 1, 2, 3].map(index => `<label class="form-field">Option ${String.fromCharCode(65 + index)}<textarea class="math-input math-option-input" data-math-field="Option ${String.fromCharCode(65 + index)}" name="o${index}" rows="2" required>${esc(options[index] || '')}</textarea></label>`).join('')}<fieldset class="correct-options"><legend>Correct answer(s)</legend><p>Tick the correct option. For a multiple-answer question, tick every correct option.</p>${[0, 1, 2, 3].map(index => `<label><input type="checkbox" name="correctOptions" value="${index}" ${question?.correctOptions?.includes(index) ? 'checked' : ''}> Option ${String.fromCharCode(65 + index)}</label>`).join('')}</fieldset><label class="form-field">Type<select class="select-field" name="type"><option value="single" ${question?.type !== 'multiple' ? 'selected' : ''}>Single answer</option><option value="multiple" ${question?.type === 'multiple' ? 'selected' : ''}>Multiple answers</option></select></label><label class="form-field">Status<select class="select-field" name="status"><option value="draft" ${question?.status !== 'published' ? 'selected' : ''}>Draft</option><option value="published" ${question?.status === 'published' ? 'selected' : ''}>Published</option></select></label><div class="modal-actions"><button class="primary-btn">${edit ? 'Save changes' : 'Save question'}</button></div></form>`);
}
function bulkQuestionsForm() {
  openModal('Import questions', `<p>Paste a JSON array. Each entry needs <code>text</code>, <code>options</code> (an array of four strings), <code>correctOptions</code> (zero-based option indexes), and <code>type</code> (<code>single</code> or <code>multiple</code>). Questions are added to this course as drafts unless their status is published.</p><form id="bulk-questions-form"><div class="alert" role="alert"></div><label class="form-field">Questions JSON<textarea name="items" rows="12" spellcheck="false" required></textarea></label><div class="modal-actions"><button class="primary-btn">Import questions</button></div></form>`);
}
function parseCsv(text) {
  const rows = []; let row = [], cell = '', quoted = false;
  const source = String(text).replace(/^\uFEFF/, '');
  for (let index = 0; index < source.length; index++) {
    const character = source[index];
    if (character === '"') {
      if (quoted && source[index + 1] === '"') { cell += '"'; index++; }
      else quoted = !quoted;
    } else if (character === ',' && !quoted) { row.push(cell); cell = ''; }
    else if ((character === '\r' || character === '\n') && !quoted) {
      if (character === '\r' && source[index + 1] === '\n') index++;
      row.push(cell); if (row.some(value => value.trim())) rows.push(row);
      row = []; cell = '';
    } else cell += character;
  }
  if (quoted) throw new Error('The CSV has an unclosed quoted field.');
  row.push(cell); if (row.some(value => value.trim())) rows.push(row);
  if (rows.length < 2) throw new Error('The CSV needs a header and at least one student.');
  const headers = rows.shift().map(value => value.trim().toLowerCase());
  const fields = {fullname: 'fullName', email: 'email', phonenumber: 'phoneNumber', matricnumber: 'matricNumber', department: 'department'};
  for (const key of Object.keys(fields)) if (!headers.includes(key)) throw new Error(`Missing CSV header: ${fields[key]}.`);
  return rows.map((values, index) => {
    const item = {};
    for (const [key, field] of Object.entries(fields)) item[field] = (values[headers.indexOf(key)] || '').trim();
    if (Object.values(item).some(value => !value)) throw new Error(`Row ${index + 2} has an empty required field.`);
    return item;
  });
}

async function uploadBackup(action, file, fields = {}) {
  if (!(file instanceof File)) throw new Error('Choose a backup file first.');
  const data = new FormData(); data.set('backup', file);
  Object.entries(fields).forEach(([key, value]) => data.set(key, String(value)));
  let response;
  await refreshCsrfToken();
  try { response = await fetch(query(action), {method: 'POST', headers: {'X-CSRF-Token': state.csrfToken}, body: data, credentials: 'same-origin'}); }
  catch { throw new Error('Cannot reach the server. Check your connection and try again.'); }
  const payload = await response.json().catch(() => ({}));
  if (!response.ok) throw new Error(payload.error || 'Backup upload failed.');
  return payload;
}
function restoreBackupValidationForm() {
  openModal('Restore from backup', `<form id="backup-restore-validate-form"><div class="alert" role="alert"></div><p>Choose a CACSA CBT backup JSON file. The server will validate its structure before any restore confirmation is shown.</p><label class="form-field">Backup JSON file<input name="backup" type="file" accept="application/json,.json" required></label><div class="modal-actions"><button class="outline-btn" type="button" data-action="close-modal">Cancel</button><button class="danger-btn">Validate backup</button></div></form>`);
}
function restoreBackupConfirmationForm(info) {
  const summary = info.summary || {};
  openModal('Confirm data restore', `<form id="backup-restore-confirm-form"><div class="alert" role="alert"></div><p class="backup-warning"><strong>This will replace current academic and system data.</strong> A safety backup of the live system will be created first.</p><div class="backup-restore-summary"><strong>${esc(info.filename)}</strong><span>${numeric(summary.students)} students · ${numeric(summary.courses)} courses · ${numeric(summary.components)} components · ${numeric(summary.submissions)} submissions</span></div><label class="form-field">Type <strong>RESTORE</strong> to enable the restore<input name="confirmation" autocomplete="off" required></label><div class="modal-actions"><button class="outline-btn" type="button" data-action="close-modal">Cancel</button><button class="danger-btn" type="submit" disabled data-restore-submit>Restore current data</button></div></form>`);
}

async function handleForm(event) {
  const form = event.target;
  if (!form.matches('form')) return;
  event.preventDefault();
  const submitButton = form.querySelector('button[type="submit"], button:not([type])');
  setBusy(submitButton, true);
  try {
    if (form.id === 'admin-login-form') {
      const values = formData(form);
      const response = await post('admin-login', values);
      if (values.remember) localStorage.setItem('algeAdminRememberedEmail', String(values.email || '').trim().toLowerCase());
      else localStorage.removeItem('algeAdminRememberedEmail');
      state.adminAuthenticated = true;
      state.adminUser = response.user || null;
      sessionStorage.removeItem('algeAdminToken'); sessionStorage.removeItem('algeAdminExpiresAt');
      saveStored('algeAdminUser', state.adminUser);
      toast('Signed in successfully.', 'success'); navigate(response.user?.mustChangePassword ? 'admin/force-password' : 'admin/overview');
    } else if (form.id === 'admin-password-reset-request-form') {
      const values = formData(form);
      await post('admin-password-reset-request', {email: values.email});
      state.passwordResetEmail = String(values.email || '').trim().toLowerCase();
      sessionStorage.setItem('algeAdminPasswordResetEmail', state.passwordResetEmail);
      toast('A six-digit password reset code was sent to your email address.', 'success');
      navigate('admin/reset-password');
    } else if (form.id === 'admin-password-reset-confirm-form') {
      const values = formData(form);
      if (values.password !== values.confirmPassword) throw new Error('The new passwords do not match.');
      await post('admin-password-reset-confirm', {email: values.email, code: values.code, password: values.password});
      state.passwordResetEmail = '';
      sessionStorage.removeItem('algeAdminPasswordResetEmail');
      sessionStorage.setItem('algeAdminAuthNotice', 'Password reset successful. Your account is suspended until a Superadmin reactivates it.');
      navigate('admin/login');
    } else if (form.id === 'admin-registration-form') {
      const values = formData(form);
      if (values.password !== values.confirmPassword) throw new Error('The passwords do not match.');
      await post('admin-registration', {name: values.name, email: values.email, phoneNumber: values.phoneNumber, password: values.password});
      form.reset();
      toast('Your request was sent for Superadmin approval. You will receive an email when access is approved.', 'success');
    } else if (form.id === 'account-profile-form') {
      const response = await put('admin-account', formData(form));
      state.account = response.account;
      if (response.verificationRequired) toast('A verification code was sent to the new email address.', 'success');
      else toast('Profile updated.', 'success');
      state.adminUser = {...state.adminUser, name: response.account.name, email: response.account.email};
      saveStored('algeAdminUser', state.adminUser);
      accountSettingsForm(response.account, 'profile');
    } else if (form.id === 'account-email-verification-form') {
      const response = await post('admin-account', {operation: 'verify-email', code: formData(form).code});
      state.account = response.account; state.adminUser = {...state.adminUser, name: response.account.name, email: response.account.email};
      saveStored('algeAdminUser', state.adminUser);
      toast('Your new email address has been verified.', 'success'); accountSettingsForm(response.account, 'profile');
    } else if (form.id === 'account-password-form') {
      const values = formData(form);
      if (values.newPassword !== values.confirmPassword) throw new Error('The new passwords do not match.');
      await post('admin-account', {operation: 'change-password', currentPassword: values.currentPassword, newPassword: values.newPassword});
      form.reset(); toast('Password updated. Other sessions were signed out.', 'success');
      if (currentRoute() === 'admin/force-password') navigate('admin/overview');
    } else if (form.id === 'student-login-form') {
      const response = await post('student-login', {...formData(form), examId: state.selectedExamId, deviceFingerprint: await lightweightDeviceFingerprint()});
      state.studentAccess = {student: response.student, exam: response.exam, examId: state.selectedExamId};
      saveStored('algeStudentSession', state.studentAccess);
      navigate(`student/confirm/${state.selectedExamId}`);
    } else if (form.id === 'student-form') {
      const id = form.dataset.id;
      if (id) await put('students', formData(form), {id});
      else await post('students', formData(form));
      closeModal(); toast(id ? 'Student updated.' : 'Student registered. Generate an exam password when ready.', 'success'); render();
    } else if (form.id === 'bulk-students-form') {
      const file = form.elements.file.files[0];
      const source = file ? await file.text() : form.elements.csv.value;
      const items = parseCsv(source);
      const response = await post('students-bulk', {items});
      closeModal(); toast(`${numeric(response.added, items.length)} students imported.`, 'success'); render();
    } else if (form.id === 'password-form') {
      const response = await post('exam-password', formData(form));
      openModal('Exam password generated', `<p>Give this password to ${esc(response.student?.fullName)} for ${esc(response.exam?.code)}.</p><div class="instruction-box"><strong class="generated-password">${esc(response.password)}</strong>This password is shown only now. Copy it before closing.</div><div class="modal-actions"><button class="primary-btn" data-action="close-modal">Done</button></div>`);
      toast('Exam password generated.', 'success');
    } else if (form.id === 'academic-session-form') {
      const values = formData(form), id = form.dataset.id;
      values.isActive = form.elements.isActive.checked;
      if (id) await put('academic-sessions', values, {id}); else await post('academic-sessions', values);
      closeModal(); toast(id ? 'Academic session updated.' : 'Academic session created.', 'success'); render();
    } else if (form.id === 'semester-form') {
      const values = formData(form), id = form.dataset.id;
      values.isActive = form.elements.isActive.checked;
      if (values.endDate < values.startDate) throw new Error('The semester end date must not be before its start date.');
      if (id) await put('semesters', values, {id}); else await post('semesters', values);
      closeModal(); toast(id ? 'Semester updated.' : 'Semester created.', 'success'); render();
    } else if (form.id === 'component-form') {
      const values = formData(form);
      values.startAt = new Date(values.startAt).toISOString(); values.endAt = new Date(values.endAt).toISOString();
      if (new Date(values.startAt) >= new Date(values.endAt)) throw new Error('The component end time must be later than its start time.');
      await put('course-components', values, {id: form.dataset.id});
      closeModal(); toast('Assessment component updated.', 'success'); render();
    } else if (form.id === 'course-form') {
      const values = formData(form), id = form.dataset.id;
      for (const key of ['testStartAt', 'testEndAt', 'examStartAt', 'examEndAt']) values[key] = new Date(values[key]).toISOString();
      if (new Date(values.testStartAt) >= new Date(values.testEndAt) || new Date(values.examStartAt) >= new Date(values.examEndAt)) throw new Error('Each component needs an end time later than its start time.');
      if (numeric(values.testMaxMark) + numeric(values.examMaxMark) > 100 || numeric(values.testMaxMark) + numeric(values.examMaxMark) <= 0) throw new Error('Test and Exam maximum marks must total more than 0 and not exceed 100.');
      if (id) await put('courses', values, {id}); else await post('courses', values);
      closeModal(); toast(id ? 'Course and components updated.' : 'Course and components created.', 'success'); render();
    } else if (form.id === 'exam-form') {
      const value = formData(form), id = form.dataset.id;
      if (new Date(value.startAt) >= new Date(value.endAt)) throw new Error('The end must be later than the start.');
      value.startAt = new Date(value.startAt).toISOString(); value.endAt = new Date(value.endAt).toISOString();
      if (id) await put('exams', value, {id}); else await post('exams', value);
      closeModal(); toast(id ? 'Exam updated.' : 'Exam created.', 'success'); render();
    } else if (form.id === 'question-form') {
      const data = new FormData(form), id = form.dataset.id;
      const correctOptions = data.getAll('correctOptions').map(Number);
      if (!correctOptions.length) throw new Error('Select at least one correct answer.');
      if (data.get('type') === 'single' && correctOptions.length !== 1) throw new Error('Select exactly one correct answer for a single-answer question.');
      const value = {examId: data.get('examId'), text: data.get('text'), options: [0, 1, 2, 3].map(index => data.get(`o${index}`)), correctOptions, type: data.get('type'), status: data.get('status')};
      if (id) await put('questions', value, {id}); else await post('questions', value);
      closeModal(); toast(id ? 'Question updated.' : 'Question saved.', 'success'); render();
    } else if (form.id === 'bulk-questions-form') {
      let items;
      try { items = JSON.parse(form.elements.items.value); }
      catch { throw new Error('This is not valid JSON. Paste a JSON array starting with [ and ending with ].'); }
      if (!Array.isArray(items) || !items.length) throw new Error('Add at least one question to a JSON array.');
      const examId = parts()[2];
      items = items.map(item => ({...item, examId}));
      const response = await post('questions-bulk', {items});
      closeModal(); toast(`${numeric(response.added, items.length)} questions imported.`, 'success'); render();
    } else if (form.id === 'backup-settings-form') {
      const values = formData(form);
      await put('backups', {enabled: form.elements.enabled.checked, time: values.time, retentionCount: Number(values.retentionCount)});
      toast('Backup schedule and retention saved.', 'success'); render();
    } else if (form.id === 'backup-restore-validate-form') {
      const file = form.elements.backup.files?.[0];
      const info = await uploadBackup('backup-restore-validate', file);
      state.backupRestoreFile = file; state.backupRestoreInfo = info; restoreBackupConfirmationForm(info);
    } else if (form.id === 'backup-restore-confirm-form') {
      const confirmation = String(form.elements.confirmation.value || '');
      if (confirmation !== 'RESTORE') throw new Error('Type RESTORE exactly to continue.');
      const response = await uploadBackup('backup-restore', state.backupRestoreFile, {confirmation});
      state.backupRestoreFile = null; state.backupRestoreInfo = null;
      openModal('Restore complete', `<p><strong>${esc(response.restoredBackup)}</strong> was restored successfully.</p><p>A safety backup of the previous live state was created: <strong>${esc(response.safetyBackup)}</strong>.</p><p>Please verify your data before continuing.</p><div class="modal-actions"><button class="outline-btn" data-action="close-modal">Stay here</button><button class="primary-btn" data-route="admin/overview">Go to dashboard</button></div>`);
    } else if (form.id === 'grading-form') {
      const rows = [...form.querySelectorAll('#grading-rows tr')];
      const gradingScale = rows.map(row => Object.fromEntries([...row.querySelectorAll('input')].map(input => [input.name, ['minScore', 'maxScore', 'gradePoint'].includes(input.name) ? Number(input.value) : input.value.trim()])));
      if (!gradingScale.length) throw new Error('Add at least one grade band.');
      const integrityPolicy = Object.fromEntries([...form.querySelectorAll('.integrity-policy-row')].map(row => [row.dataset.event, {mode: row.querySelector('[name="mode"]').value, lockAfter: Number(row.querySelector('[name="lockAfter"]').value)}]));
      const examSecurity = {...(state.settings?.examSecurity || {}), optionShuffleEnabled: Boolean(form.elements.optionShuffleEnabled?.checked), fingerprintFlaggingEnabled: Boolean(form.elements.fingerprintFlaggingEnabled?.checked), concurrentIpBlockEnabled: Boolean(form.elements.concurrentIpBlockEnabled?.checked), loginRateLimitEnabled: Boolean(form.elements.loginRateLimitEnabled?.checked), loginAttemptLimit: Number(form.elements.loginAttemptLimit?.value), loginAttemptWindowMinutes: Number(form.elements.loginAttemptWindowMinutes?.value), loginLockoutMinutes: Number(form.elements.loginLockoutMinutes?.value), ipAttemptLimit: Number(form.elements.ipAttemptLimit?.value), ipAttemptWindowMinutes: Number(form.elements.ipAttemptWindowMinutes?.value)};
      await put('settings', {gradingScale, integrityPolicy, examSecurity, resultLogoUrl: String(form.elements.resultLogoUrl?.value || 'CACSA%20Logo.jpeg').trim()});
      toast('Settings saved. Existing results were recalculated.', 'success'); render();
    } else if (form.id === 'audit-filter-form') {
      const values = formData(form);
      state.filters.audit = {from: String(values.from || ''), to: String(values.to || ''), actor: String(values.actor || '').trim(), type: String(values.type || ''), course: String(values.course || '').trim(), page: 1};
      render();
    } else if (form.id === 'role-form') {
      const values = new FormData(form);
      const permissions = values.getAll('permissions');
      if (!permissions.length) throw new Error('Select at least one permission for this role.');
      await put('roles', {maxUsers: Number(values.get('maxUsers')), permissions}, {id: form.dataset.id});
      closeModal(); toast('Role permissions updated.', 'success'); render();
    } else if (form.id === 'admin-user-form') {
      const values = formData(form), id = form.dataset.id;
      if (!id && String(values.password || '').length < 8) throw new Error('Use a password with at least 8 characters.');
      if (id) await put('admin-users', values, {id}); else await post('admin-users', values);
      closeModal(); toast(id ? 'Administrator updated.' : 'Administrator created.', 'success'); render();
    } else if (form.id === 'approval-form') {
      const response = await post('admin-approvals', {id: form.dataset.id, decision: 'approve', roleId: formData(form).roleId});
      closeModal(); toast(`Request approved and email accepted by the mail server (${response.emailDelivery}).`, 'success'); render();
    } else if (form.classList.contains('user-inline-form')) {
      const values = formData(form);
      await put('admin-users', {
        name: form.dataset.name,
        email: form.dataset.email,
        roleId: values.roleId,
        active: values.active === 'true'
      }, {id: form.dataset.id});
      toast('User account updated.', 'success'); render();
    } else if (form.id === 'newsletter-subscriber-form') {
      await post('newsletter-subscribers', formData(form));
      toast('Subscriber saved.', 'success'); render();
    } else if (form.id === 'newsletter-compose-form') {
      const response = await post('newsletters', formData(form));
      closeModal();
      toast(`${numeric(response.accepted)} message${numeric(response.accepted) === 1 ? '' : 's'} accepted by the mail server${response.failed ? `; ${numeric(response.failed)} failed.` : '.'}`, response.failed ? 'error' : 'success');
      render();
    } else if (form.dataset.filterForm) {
      const name = form.dataset.filterForm, data = formData(form);
      state.filters[name].q = String(data.q || '').trim(); state.filters[name].status = String(data.status || ''); state.filters[name].page = 1;
      if (name === 'results') state.filters.results.period = String(data.period || '');
      render();
    }
  } catch (error) { showError(error, form); }
  finally { if (submitButton?.isConnected) setBusy(submitButton, false); }
}

async function handleAction(button) {
  const action = button.dataset.action;
  const id = button.dataset.id;
  if (action === 'toggle-theme') {
    state.theme = state.theme === 'dark' ? 'light' : 'dark';
    localStorage.setItem('algeTheme', state.theme);
    applyTheme(state.theme);
    document.querySelectorAll('[data-action="toggle-theme"]').forEach(control => {
      const dark = state.theme === 'dark';
      control.dataset.theme = dark ? 'dark' : 'light';
      control.setAttribute('aria-label', `Switch to ${dark ? 'light' : 'dark'} theme`);
      control.setAttribute('title', `Switch to ${dark ? 'light' : 'dark'} theme`);
      const label = control.querySelector('.theme-toggle-label');
      if (label) label.textContent = dark ? 'Light' : 'Dark';
      else control.innerHTML = themeToggleContents(dark);
    });
    return;
  }
  if (action === 'retry') return render();
  if (action === 'refresh-exams') return render();
  if (action === 'calculate-student-result') {
    const route = parts(); const studentId = route[2] || state.report?.student?.id;
    const sessionId = route[3] || state.report?.selectedSession?.id;
    const semesterId = route[4] || state.report?.selectedSemester?.id;
    if (!studentId || !sessionId || !semesterId) return toast('Choose an academic session and semester first.', 'error');
    openModal('Calculating result', '<div class="result-calculation-progress" role="status"><span class="spinner" aria-hidden="true"></span><div><strong>Calculating…</strong><p>Combining completed Test and Exam marks with the current grading scale.</p></div></div>', {closeable: false});
    const startedAt = Date.now();
    try {
      await post('calculate-student-result', {studentId, sessionId, semesterId});
      await new Promise(resolve => setTimeout(resolve, Math.max(0, 2200 - (Date.now() - startedAt))));
      closeModal(); toast('Result calculated and saved.', 'success'); render();
    } catch (error) { closeModal(); throw error; }
    return;
  }
  if (action === 'toggle-calculator') {
    state.calculatorOpen = !state.calculatorOpen;
    const calculator = document.querySelector('.exam-calculator'); if (calculator) calculator.hidden = !state.calculatorOpen;
    button.setAttribute('aria-expanded', String(state.calculatorOpen));
    return;
  }
  if (action === 'calculator-key') return updateCalculator(button.dataset.calculatorKey || '');
  if (action === 'exam-font-size') {
    state.examTextScale = Math.max(.85, Math.min(1.35, Number((state.examTextScale + numeric(button.dataset.fontChange)).toFixed(2))));
    sessionStorage.setItem('algeExamTextScale', String(state.examTextScale));
    document.querySelector('.exam-workspace')?.style.setProperty('--exam-text-scale', String(state.examTextScale));
    return;
  }
  if (action === 'candidate-rules') return candidateRulesModal();
  if (action === 'invigilator-help') return invigilatorHelpModal();
  if (action === 'scroll-assessments') return document.querySelector('#active-assessments')?.scrollIntoView({behavior: 'smooth', block: 'start'});
  if (action === 'landing-filter') {
    state.landingFilters.type = button.dataset.filterType || 'all';
    state.landingFilters.category = button.dataset.filterCategory || 'all';
    return refreshLandingPage();
  }
  if (action === 'check-session-status') return render();
  if (action === 'refresh-audit') return render();
  if (action === 'hide-dashboard-outcomes') {
    const selected = state.dashboardSelectedOutcomes || [];
    if (!selected.length) return toast('Select one or more outcome rows first.', 'error');
    return confirmAction('Hide selected dashboard rows', `Hide ${selected.length} selected outcome row${selected.length === 1 ? '' : 's'} from this dashboard? Courses, assessments, questions, and results will remain exactly as they are.`, 'Hide rows', async () => {
      await post('dashboard-outcomes', {operation: 'hide', examIds: selected});
      state.dashboardSelectedOutcomes = [];
      toast('Selected outcome rows are now hidden from the dashboard only.', 'success'); render();
    });
  }
  if (action === 'restore-dashboard-outcomes') {
    const hidden = state.dashboard?.hiddenCourseOutcomes || [];
    if (!hidden.length) return toast('There are no hidden outcome rows to restore.', 'info');
    return confirmAction('Restore hidden dashboard rows', `Restore all ${hidden.length} hidden outcome row${hidden.length === 1 ? '' : 's'} to the dashboard?`, 'Restore rows', async () => {
      await post('dashboard-outcomes', {operation: 'restore', examIds: hidden.map(item => item.examId)});
      toast('Hidden outcome rows restored to the dashboard.', 'success'); render();
    });
  }
  if (action === 'export-audit-csv') return downloadAuditExport('csv');
  if (action === 'export-audit-excel') return downloadAuditExport('excel');
  if (action === 'export-audit-pdf' || action === 'print-audit') { window.print(); return; }
  if (action === 'create-backup') {
    setBusy(button, true);
    try { const response = await post('backups', {}); toast(`Backup created: ${response.backup.filename}`, 'success'); render(); }
    finally { if (button.isConnected) setBusy(button, false); }
    return;
  }
  if (action === 'download-backup') {
    const response = await fetch(query('backups', {download: id}), {credentials: 'same-origin'});
    if (!response.ok) { const error = await response.json().catch(() => ({})); throw new Error(error.error || 'Unable to download this backup.'); }
    const blob = await response.blob(), url = URL.createObjectURL(blob), link = document.createElement('a');
    link.href = url; link.download = id; link.click(); setTimeout(() => URL.revokeObjectURL(url), 1000); toast('Backup download started.', 'success'); return;
  }
  if (action === 'delete-backup') return confirmAction('Delete backup', `Permanently delete ${id}? This backup cannot be recovered after deletion.`, 'Delete backup', async () => {
    await remove('backups', {filename: id}); toast('Backup deleted.', 'success'); render();
  });
  if (action === 'restore-backup') return restoreBackupValidationForm();
  if (action === 'close-modal') return closeModal();
  if (action === 'clear-audit-filter') { state.filters.audit = {from: '', to: '', actor: '', type: '', course: '', page: 1}; return render(); }
  if (action === 'audit-session-detail') {
    const session = state.auditMonitor.find(item => item.id === id);
    if (!session) return;
    const events = session.events || [];
    return openModal(`${session.studentName} · ${session.course}`, `<p class="table-muted">${esc(session.matricNumber)} · ${esc(displayIp(session.ipAddress))} · ${esc(session.status)}</p><h3 style="margin-top:22px">Integrity events</h3>${events.length ? `<div class="table-scroll"><table class="data-table"><thead><tr><th>Timestamp</th><th>Event</th><th>Response</th></tr></thead><tbody>${events.map(event => `<tr><td class="table-muted">${fmtDate(event.at || event.timestamp)}</td><td>${esc(String(event.event || event.flagType || '').replaceAll('_', ' '))}</td><td><span class="status-pill ${event.resultingAction === 'locked' ? 'status-danger' : event.resultingAction === 'warn' ? 'status-warning' : ''}">${esc(event.resultingAction || 'logged')}</span></td></tr>`).join('')}</tbody></table></div>` : emptyState('No integrity events have been recorded for this session.')}`);
  }
  if (action === 'unlock-exam-session') {
    const session = state.auditMonitor.find(item => item.id === id);
    if (!session) return;
    if (!session.locked || numeric(session.remainingSeconds) <= 0) return toast('This session can no longer be unlocked because its assessment time has ended.', 'error');
    return confirmAction('Unlock assessment session', `Unlock ${session.studentName}'s ${session.course} session? Their saved answers will remain and they can continue for the remaining ${shortDuration(session.remainingSeconds)}.`, 'Unlock session', async () => {
      await post('exam-session-unlock', {sessionId: id});
      toast('Session unlocked. The student can resume the saved assessment.', 'success'); render();
    });
  }
  if (action === 'new-admin-user') return adminUserForm();
  if (action === 'toggle-sidebar') {
    setAdminSidebarOpen(!state.sidebarOpen); return;
  }
  if (action === 'close-sidebar') { setAdminSidebarOpen(false); return; }
  if (action === 'toggle-account-menu') {
    const menu = button.parentElement?.querySelector('.account-menu');
    if (!menu) return;
    menu.hidden = !menu.hidden; button.setAttribute('aria-expanded', String(!menu.hidden)); return;
  }
  if (action === 'account-settings') {
    const response = await api('admin-account');
    return accountSettingsForm(response.account, 'profile');
  }
  if (action === 'account-tab') return accountSettingsForm(state.account, button.dataset.tab || 'profile');
  if (action === 'account-signout-others') {
    return confirmAction('Sign out other sessions', 'End every other active administrator session for this account? Your current session will remain active.', 'Sign out sessions', async () => {
      await post('admin-account', {operation: 'signout-others'});
      toast('Other administrator sessions were signed out.', 'success'); accountSettingsForm(state.account, 'privacy');
    });
  }
  if (action === 'compose-newsletter') return newsletterForm();
  if (action === 'edit-admin-user') return adminUserForm(state.adminUsers.find(user => user.id === id));
  if (action === 'approve-admin-request') return approvalForm((state.adminApprovals?.pending || []).find(request => request.id === id));
  if (action === 'reject-admin-request') {
    const request = (state.adminApprovals?.pending || []).find(item => item.id === id);
    return confirmAction('Reject access request', `Reject the administrator access request from ${request?.email || 'this applicant'}? They will not be able to sign in.`, 'Reject request', async () => {
      await post('admin-approvals', {id, decision: 'reject'});
      toast('Access request rejected.', 'success'); render();
    });
  }
  if (action === 'role-config') return roleForm(state.roles.find(role => role.id === id));
  if (action === 'disable-admin-user') {
    const user = state.adminUsers.find(item => item.id === id);
    return confirmAction('Disable administrator', `Disable ${user?.name || 'this administrator'}? They will no longer be able to sign in.`, 'Disable administrator', async () => { await remove('admin-users', {id}); toast('Administrator disabled.', 'success'); render(); });
  }
  if (action === 'delete-admin-user') {
    const user = state.adminUsers.find(item => item.id === id);
    if (!user || user.systemLocked || user.roleId === 'superadmin') return toast('The Superadmin account is protected and cannot be deleted.', 'error');
    return confirmAction('Delete administrator', `Permanently delete ${user.name}? Their account and active sign-in sessions will be removed. This cannot be undone.`, 'Delete administrator', async () => { await remove('admin-users', {id, permanently: 'true'}); toast('Administrator deleted.', 'success'); render(); });
  }
  if (action === 'reactivate-admin-user') {
    const user = state.adminUsers.find(item => item.id === id);
    if (!user) return;
    return confirmAction('Reactivate administrator', `Allow ${user.name} to sign in again?`, 'Reactivate administrator', async () => {
      await put('admin-users', {name: user.name, email: user.email, roleId: user.roleId, active: true}, {id});
      toast('Administrator reactivated.', 'success'); render();
    });
  }
  if (action === 'unsubscribe-subscriber') {
    const subscriber = state.newsletterSubscribers.find(item => item.id === id);
    return confirmAction('Unsubscribe recipient', `Remove ${subscriber?.email || 'this recipient'} from future newsletter sends?`, 'Unsubscribe', async () => {
      await remove('newsletter-subscribers', {id}); toast('Subscriber unsubscribed.', 'success'); render();
    });
  }
  if (action === 'subscribe-subscriber') {
    setBusy(button, true);
    try { await put('newsletter-subscribers', {status: 'active'}, {id}); toast('Subscriber restored to the mailing list.', 'success'); render(); }
    catch (error) { showError(error); } finally { if (button.isConnected) setBusy(button, false); }
    return;
  }
  if (action === 'delete-subscriber') {
    const subscriber = state.newsletterSubscribers.find(item => item.id === id);
    return confirmAction('Delete subscriber', `Permanently delete ${subscriber?.email || 'this subscriber'} from the newsletter list?`, 'Delete subscriber', async () => {
      await remove('newsletter-subscribers', {id, purge: 'true'}); toast('Subscriber deleted.', 'success'); render();
    });
  }
  if (action === 'clear-newsletter-history') {
    return confirmAction('Clear newsletter history', 'Clear all newsletter history records? This will not remove subscribers or Audit Log entries.', 'Clear history', async () => {
      const response = await remove('newsletters');
      const cleared = numeric(response.cleared);
      toast(`${cleared} newsletter history record${cleared === 1 ? '' : 's'} cleared.`, 'success');
      render();
    });
  }
  if (action === 'new-student') return studentForm();
  if (action === 'bulk-students') return bulkStudentsForm();
  if (action === 'edit-student') return studentForm(state.students.find(item => item.id === id));
  if (action === 'student-password') return passwordForm(state.students.find(item => item.id === id));
  if (action === 'new-academic-session') return academicSessionForm();
  if (action === 'edit-academic-session') return academicSessionForm(state.academicSessions.find(item => item.id === id));
  if (action === 'new-semester') {
    if (!state.academicSessions.length) return toast('Create an academic session before adding a semester.', 'error');
    return semesterForm();
  }
  if (action === 'edit-semester') return semesterForm(state.semesters.find(item => item.id === id));
  if (action === 'delete-academic-session') {
    const item = state.academicSessions.find(session => session.id === id);
    return confirmAction('Delete academic session', `Delete ${item?.label || 'this session'}? Sessions that contain semesters or courses cannot be deleted.`, 'Delete session', async () => { await remove('academic-sessions', {id}); toast('Academic session deleted.', 'success'); render(); });
  }
  if (action === 'delete-semester') {
    const item = state.semesters.find(semester => semester.id === id);
    return confirmAction('Delete semester', `Delete ${item?.label || 'this semester'}? Semesters that contain courses cannot be deleted.`, 'Delete semester', async () => { await remove('semesters', {id}); toast('Semester deleted.', 'success'); render(); });
  }
  if (action === 'new-course') {
    if (!state.academicSessions.length || !state.semesters.length) return toast('Create an academic session and semester in Settings first.', 'error');
    return courseForm();
  }
  if (action === 'edit-course') return courseForm(state.courses.find(course => course.id === id));
  if (action === 'delete-course') {
    const course = state.courses.find(item => item.id === id);
    return confirmAction('Delete course', `Delete ${course?.code || 'this course'} and its Test/Exam question banks? Courses with submitted results cannot be deleted.`, 'Delete course', async () => { await remove('courses', {id}); toast('Course deleted.', 'success'); render(); });
  }
  if (action === 'edit-component') return componentForm(state.courses.flatMap(course => course.components || []).find(component => component.id === id));
  if (action === 'component-status') {
    const component = state.courses.flatMap(course => course.components || []).find(item => item.id === id);
    if (!component) return;
    setBusy(button, true);
    try {
      await put('course-components', {...component, status: button.dataset.status}, {id});
      toast(`Component ${button.dataset.status === 'active' ? 'activated' : button.dataset.status === 'published' ? 'published' : 'deactivated'}.`, 'success'); render();
    } catch (error) { showError(error); } finally { if (button.isConnected) setBusy(button, false); }
    return;
  }
  if (action === 'delete-component') {
    const component = state.courses.flatMap(course => course.components || []).find(item => item.id === id);
    return confirmAction(`Delete ${component?.componentLabel || 'component'}`, `Delete this ${component?.componentLabel || 'assessment'} and its question bank? Submitted-result components cannot be deleted.`, 'Delete component', async () => { await remove('course-components', {id}); toast('Assessment component deleted.', 'success'); render(); });
  }
  if (action === 'new-exam') return examForm();
  if (action === 'edit-exam') return examForm(state.adminExams.find(item => item.id === id));
  if (action === 'new-question') return questionForm();
  if (action === 'edit-question') return questionForm(state.questions.find(item => item.id === id));
  if (action === 'bulk-questions') return bulkQuestionsForm();
  if (action === 'admin-logout') {
    try { await post('admin-logout', {}); } catch { /* Local sign out still revokes this browser's access. */ }
    clearAdmin(); sessionStorage.setItem('algeAdminAuthNotice', 'You have been logged out successfully.'); navigate('admin/login'); return;
  }
  if (action === 'add-grade-row') { document.querySelector('#grading-rows')?.insertAdjacentHTML('beforeend', gradingRow()); return; }
  if (action === 'remove-grade-row') { button.closest('tr')?.remove(); return; }
  if (action === 'student-status') {
    const student = state.students.find(item => item.id === id);
    if (!student) return;
    return confirmAction(student.active ? 'Disable student' : 'Reactivate student', `${student.active ? 'Disable' : 'Reactivate'} ${student.fullName}?`, student.active ? 'Disable student' : 'Reactivate student', async () => {
      await put('students', {active: !student.active}, {id}); toast(student.active ? 'Student disabled.' : 'Student reactivated.', 'success'); render();
    });
  }
  if (action === 'delete-student') {
    const student = state.students.find(item => item.id === id);
    if (!student) return;
    return confirmAction('Delete student permanently', `Permanently delete ${student.fullName}? Their account, assessment passwords, sessions, submitted results, and calculated result sheets will be removed. This cannot be undone.`, 'Delete student', async () => {
      await remove('students', {id, permanently: 'true'}); toast('Student and associated assessment records deleted.', 'success'); render();
    });
  }
  if (action === 'delete-exam') {
    const exam = state.adminExams.find(item => item.id === id);
    return confirmAction('Delete exam', `Delete ${exam?.code || 'this exam'}? This can affect its questions and existing result links.`, 'Delete exam', async () => { await remove('exams', {id}); toast('Exam deleted.', 'success'); render(); });
  }
  if (action === 'delete-question') return confirmAction('Delete question', 'Delete this question permanently?', 'Delete question', async () => { await remove('questions', {id}); toast('Question deleted.', 'success'); render(); });
  if (action === 'confirm-action') {
    const callback = state.confirmCallback; state.confirmCallback = null;
    setBusy(button, true);
    try { await callback(); closeModal(); } catch (error) { showError(error); }
    finally { if (button.isConnected) setBusy(button, false); }
    return;
  }
  if (action === 'exam-status') {
    setBusy(button, true);
    try { await put('exams', {status: button.dataset.status}, {id}); toast('Exam status updated.', 'success'); render(); }
    catch (error) { showError(error); } finally { if (button.isConnected) setBusy(button, false); }
    return;
  }
  if (action === 'question-status') {
    setBusy(button, true);
    try { await put('questions', {status: button.dataset.status}, {id}); toast('Question status updated.', 'success'); render(); }
    catch (error) { showError(error); } finally { if (button.isConnected) setBusy(button, false); }
    return;
  }
  if (action === 'print-result-sheet') { window.print(); return; }
  if (action === 'export-csv') {
    try {
      const response = await fetch(query('student-results-csv', {id: parts()[2], sessionId: parts()[3] || state.report?.selectedSession?.id || '', semesterId: parts()[4] || state.report?.selectedSemester?.id || ''}), {credentials: 'same-origin'});
      if (!response.ok) throw new Error('Could not export this result sheet.');
      const blob = await response.blob(), url = URL.createObjectURL(blob), link = document.createElement('a');
      link.href = url; link.download = `${state.report?.student?.matricNumber || 'student'}-result-sheet.csv`; link.click();
      setTimeout(() => URL.revokeObjectURL(url), 1000);
      toast('CSV downloaded.', 'success');
    } catch (error) { showError(error); }
    return;
  }
  if (action === 'start-exam') {
    if (!state.studentAccess || state.studentAccess.examId !== state.selectedExamId) return navigate(`student/login/${state.selectedExamId}`);
    setBusy(button, true);
    try {
      const data = await post('session-start', {});
      state.attempt = {examId: state.selectedExamId, exam: selectedExam(), student: state.studentAccess.student, session: data.session, questions: data.questions || [], answers: data.session?.answers || {}, flagged: data.session?.flagged || []};
      state.saveState = 'saved';
      resetScratchCalculator();
      state.integrityLast = {}; state.questionIndex = 0; saveStored('algeExamAttempt', state.attempt);
      navigate(`student/exam/${state.selectedExamId}`);
    } catch (error) { showError(error); } finally { if (button.isConnected) setBusy(button, false); }
    return;
  }
  if (action === 'next-question' || action === 'previous-question') { state.questionIndex += action === 'next-question' ? 1 : -1; resetScratchCalculator(); render(); return; }
  if (action === 'flag-question') {
    const question = state.attempt?.questions[state.questionIndex]; if (!question) return;
    const flagged = !state.attempt.flagged.includes(question.id);
    setBusy(button, true);
    setSaveState('saving');
    try {
      await post('session-flag', attemptPayload({questionId: question.id, flagged}));
      state.attempt.flagged = flagged ? [...state.attempt.flagged, question.id] : state.attempt.flagged.filter(item => item !== question.id);
      saveStored('algeExamAttempt', state.attempt); setSaveState('saved'); render();
    } catch (error) { setSaveState('error'); showError(error); } finally { if (button.isConnected) setBusy(button, false); }
    return;
  }
  if (action === 'submit-prompt') {
    const unanswered = state.attempt.questions.filter(question => !(state.attempt.answers[question.id] || []).length).length;
    return openModal('Submit assessment', `<p>${unanswered ? `${unanswered} question${unanswered === 1 ? ' is' : 's are'} unanswered. ` : 'All questions have an answer. '}Once submitted, you cannot change your responses.</p><div class="modal-actions"><button class="outline-btn" data-action="close-modal">Continue exam</button><button class="primary-btn" data-action="confirm-submit">Submit assessment</button></div>`);
  }
  if (action === 'confirm-submit' || action === 'retry-submit') { setBusy(button, true); await submitExam(action === 'retry-submit'); if (button.isConnected) setBusy(button, false); }
}

document.addEventListener('submit', handleForm);
document.addEventListener('click', async event => {
  const routeButton = event.target.closest('[data-route]');
  if (routeButton) {
    if (routeButton.dataset.route.startsWith('admin/') && window.matchMedia?.('(max-width: 900px)').matches) setAdminSidebarOpen(false);
    navigate(routeButton.dataset.route); return;
  }
  const passwordToggle = event.target.closest('[data-toggle-password]');
  if (passwordToggle) {
    const input = document.getElementById(passwordToggle.dataset.togglePassword);
    if (!input) return;
    const visible = input.type === 'password';
    input.type = visible ? 'text' : 'password';
    passwordToggle.classList.toggle('is-visible', visible);
    passwordToggle.setAttribute('aria-pressed', String(visible));
    passwordToggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
    passwordToggle.title = visible ? 'Hide password' : 'Show password';
    passwordToggle.querySelector('.sr-only').textContent = visible ? 'Hide password' : 'Show password';
    input.focus({preventScroll: true});
    return;
  }
  const sortButton = event.target.closest('[data-sort-list]');
  if (sortButton) {
    const filter = state.filters[sortButton.dataset.sortList], field = sortButton.dataset.sortField;
    filter.dir = filter.sort === field && filter.dir === 'asc' ? 'desc' : 'asc';
    filter.sort = field; filter.page = 1; render(); return;
  }
  const pageButton = event.target.closest('[data-page-list]');
  if (pageButton) { state.filters[pageButton.dataset.pageList].page = numeric(pageButton.dataset.page, 1); render(); return; }
  const clearButton = event.target.closest('[data-filter-clear]');
  if (clearButton) { const filter = state.filters[clearButton.dataset.filterClear]; filter.q = ''; filter.status = ''; if (clearButton.dataset.filterClear === 'results') filter.period = ''; filter.page = 1; render(); return; }
  const questionButton = event.target.closest('[data-question-index]');
  if (questionButton) { state.questionIndex = numeric(questionButton.dataset.questionIndex); resetScratchCalculator(); render(); return; }
  const mathButton = event.target.closest('[data-math-insert]');
  if (mathButton) { insertMath(mathButton); return; }
  const actionButton = event.target.closest('[data-action]');
  if (actionButton) { try { await handleAction(actionButton); } catch (error) { showError(error); } return; }
  if (event.target.id === 'modal' && event.target.dataset.closeable !== 'false') closeModal();
});
document.addEventListener('mousedown', event => {
  if (event.target.closest('[data-math-insert]')) event.preventDefault();
});
document.addEventListener('focusin', event => {
  if (event.target.matches('[data-math-field]')) updateMathPreview(event.target);
});
document.addEventListener('input', event => {
  if (event.target.matches('#backup-restore-confirm-form [name="confirmation"]')) {
    const submit = document.querySelector('[data-restore-submit]');
    if (submit) submit.disabled = event.target.value !== 'RESTORE';
    return;
  }
  if (event.target.matches('[data-assessment-search]')) {
    state.landingFilters.search = event.target.value;
    refreshLandingPage();
    const search = document.querySelector('[data-assessment-search]');
    search?.focus(); search?.setSelectionRange(state.landingFilters.search.length, state.landingFilters.search.length);
    return;
  }
  if (event.target.matches('[data-math-field]')) updateMathPreview(event.target);
});
document.addEventListener('change', async event => {
  if (event.target.matches('[data-result-period-select]')) {
    const [sessionId = '', semesterId = ''] = String(event.target.value || '').split('|');
    const studentId = parts()[2] || state.report?.student?.id;
    if (studentId && sessionId && semesterId) navigate(`admin/student-results/${studentId}/${sessionId}/${semesterId}`);
    return;
  }
  if (event.target.matches('[data-dashboard-outcome-select]')) {
    const id = String(event.target.value || '');
    if (!id) return;
    const selected = new Set(state.dashboardSelectedOutcomes || []);
    if (event.target.checked) selected.add(id); else selected.delete(id);
    state.dashboardSelectedOutcomes = [...selected];
    const row = event.target.closest('.outcome-select-row');
    row?.classList.toggle('selected', event.target.checked);
    const hideButton = document.querySelector('[data-action="hide-dashboard-outcomes"]');
    if (hideButton) { hideButton.disabled = !state.dashboardSelectedOutcomes.length; hideButton.textContent = `Hide selected (${state.dashboardSelectedOutcomes.length})`; }
    return;
  }
  if (event.target.matches('#question-form select[name="type"]') && event.target.value === 'single') {
    const checked = [...document.querySelectorAll('#question-form input[name="correctOptions"]:checked')];
    checked.slice(1).forEach(input => input.checked = false);
    return;
  }
  if (event.target.matches('#question-form input[name="correctOptions"]')) {
    const type = document.querySelector('#question-form select[name="type"]')?.value;
    if (type === 'single' && event.target.checked) document.querySelectorAll('#question-form input[name="correctOptions"]').forEach(input => { if (input !== event.target) input.checked = false; });
    return;
  }
  if (!event.target.matches('input[name="answer"]') || !state.attempt) return;
  const question = state.attempt.questions[state.questionIndex];
  const before = state.attempt.answers[question.id] || [];
  const answers = [...document.querySelectorAll('input[name="answer"]:checked')].map(input => Number(input.value));
  const status = document.querySelector('#save-status');
  if (status) status.textContent = 'Saving…';
  setSaveState('saving');
  try {
    await post('session-answer', attemptPayload({questionId: question.id, answers}));
    state.attempt.answers[question.id] = answers;
    saveStored('algeExamAttempt', state.attempt);
    if (status) status.textContent = answers.length ? 'Answer saved' : 'Not answered';
    setSaveState('saved');
    document.querySelectorAll('.option').forEach(option => option.classList.toggle('selected', option.querySelector('input')?.checked));
    const mapButton = document.querySelector(`[data-question-index="${state.questionIndex}"]`);
    mapButton?.classList.toggle('answered', Boolean(answers.length));
  } catch (error) {
    state.attempt.answers[question.id] = before;
    document.querySelectorAll('input[name="answer"]').forEach(input => { input.checked = before.includes(Number(input.value)); });
    if (status) status.textContent = 'Answer not saved';
    setSaveState('error');
    showError(error);
  }
});
document.addEventListener('keydown', event => {
  if ((event.key === 'Enter' || event.key === ' ') && event.target.matches('.audit-session-row')) { event.preventDefault(); event.target.click(); return; }
  const modal = document.querySelector('#modal'); if (!modal) return;
  if (event.key === 'Escape' && modal.dataset.closeable !== 'false') { event.preventDefault(); closeModal(); return; }
  if (event.key !== 'Tab') return;
  const focusable = [...modal.querySelectorAll('button:not([disabled]), input:not([disabled]), select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])')].filter(element => element.getClientRects().length);
  if (!focusable.length) return;
  if (event.shiftKey && document.activeElement === focusable[0]) { event.preventDefault(); focusable.at(-1).focus(); }
  else if (!event.shiftKey && document.activeElement === focusable.at(-1)) { event.preventDefault(); focusable[0].focus(); }
});
function logIntegrity(eventName) {
  if (!state.attempt?.session?.id || parts()[1] !== 'exam') return;
  const now = Date.now();
  if (now - numeric(state.integrityLast[eventName]) < 2200) return;
  state.integrityLast[eventName] = now;
  post('session-integrity', attemptPayload({event: eventName})).then(response => {
    if (response.locked) {
      state.attempt.session = {...state.attempt.session, status: 'locked', lockedReason: response.lockedReason};
      saveStored('algeExamAttempt', state.attempt); toast('This session has been locked. Contact your invigilator.', 'error'); render();
    } else if (response.resultingAction === 'warn') toast('Integrity notice: remain on the assessment screen.', 'warning');
  }).catch(() => {});
}
function startDevtoolsSignal() {
  // This is a best-effort browser signal only. It can be bypassed and must never be treated as proof that DevTools was opened.
  clearInterval(state.devtoolsTimer);
  if (!state.attempt?.session?.id || parts()[1] !== 'exam') return;
  state.devtoolsTimer = setInterval(() => {
    const widthDelta = Math.abs(window.outerWidth - window.innerWidth), heightDelta = Math.abs(window.outerHeight - window.innerHeight);
    if (window.innerWidth >= 700 && (widthDelta > 180 || heightDelta > 180)) logIntegrity('devtools');
  }, 1800);
}
document.addEventListener('visibilitychange', () => { if (document.hidden) logIntegrity('tab_switch'); });
window.addEventListener('blur', () => logIntegrity('blur'));
document.addEventListener('contextmenu', event => { if (parts()[1] === 'exam' && state.attempt?.session?.id) { event.preventDefault(); logIntegrity('context_menu'); } });
document.addEventListener('copy', event => { if (parts()[1] === 'exam' && state.attempt?.session?.id) { event.preventDefault(); logIntegrity('copy'); } });
document.addEventListener('paste', event => { if (parts()[1] === 'exam' && state.attempt?.session?.id) { event.preventDefault(); logIntegrity('paste'); } });
window.addEventListener('popstate', render);
if (!/\/(?:admin|student)(?:\/|$)/.test(location.pathname)) history.replaceState(null, '', `${APP_BASE}/student/selection`);
render();
