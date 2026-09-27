const app = document.querySelector('#app');
const toastRegion = document.querySelector('#toast-region');
const API_URL = 'api.php?action=';
const PAGE_SIZE = 10;

const state = {
  adminToken: sessionStorage.getItem('algeAdminToken') || '',
  adminExpiresAt: sessionStorage.getItem('algeAdminExpiresAt') || '',
  studentAccess: readStored('algeStudentSession'),
  attempt: readStored('algeExamAttempt'),
  exams: [], adminExams: [], students: [], questions: [], results: [],
  dashboard: null, settings: null, report: null, review: null,
  selectedExamId: null, questionIndex: 0, secondsLeft: 0, timerId: null, availabilityRefreshId: null, saveState: 'saved',
  loadSerial: 0, route: '', modalTrigger: null,
  filters: {
    students: {q: '', sort: 'fullName', dir: 'asc', page: 1, status: ''},
    exams: {q: '', sort: 'code', dir: 'asc', page: 1, status: ''},
    questions: {q: '', sort: 'text', dir: 'asc', page: 1, status: ''},
    results: {q: '', sort: 'submittedAt', dir: 'desc', page: 1, status: ''}
  },
  meta: {}
};

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
function fmtDate(value) { return value ? new Date(value).toLocaleString() : '—'; }
function fmtTime(seconds) {
  const safe = Math.max(0, Math.floor(Number(seconds) || 0));
  return `${String(Math.floor(safe / 60)).padStart(2, '0')}:${String(safe % 60).padStart(2, '0')}`;
}
function numeric(value, fallback = 0) { const number = Number(value); return Number.isFinite(number) ? number : fallback; }
function examStatusMeta(exam) {
  if (exam.status === 'active' && exam.windowState === 'open') return {label: 'Active / open now', className: ''};
  if (exam.status === 'active' && exam.windowState === 'scheduled') return {label: 'Active / scheduled', className: 'status-warning'};
  if (exam.status === 'active' && exam.windowState === 'closed') return {label: 'Active / window closed', className: 'status-danger'};
  return {label: exam.status === 'published' ? 'Published' : 'Draft', className: 'status-muted'};
}
function candidateInitials(name) {
  return String(name || 'Student').trim().split(/\s+/).filter(Boolean).slice(0, 2).map(part => part[0]).join('').toUpperCase() || 'S';
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
function brand() { return '<div class="brand"><img class="brand-mark" src="CACSA%20Logo.jpeg" alt="CACSA logo"><span>CACSA LAUTECH CBT<small>Assessment centre</small></span></div>'; }
function passwordInput(id, autocomplete, attributes = '') {
  return `<span class="password-input"><input id="${esc(id)}" name="password" type="password" autocomplete="${esc(autocomplete)}" ${attributes}><button class="password-toggle" type="button" data-toggle-password="${esc(id)}" aria-label="Show password" aria-pressed="false" title="Show password"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M2.5 12s3.4-6 9.5-6 9.5 6 9.5 6-3.4 6-9.5 6-9.5-6-9.5-6Z"></path><circle cx="12" cy="12" r="2.7"></circle></svg><span class="sr-only">Show password</span></button></span>`;
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
function query(action, params = {}) {
  const search = new URLSearchParams({action});
  for (const [key, value] of Object.entries(params)) if (value !== '' && value != null) search.set(key, String(value));
  return `api.php?${search}`;
}
async function api(action, options = {}, params = {}) {
  const {admin: requireAdmin = false, ...fetchOptions} = options;
  const adminAction = requireAdmin || ['admin-logout', 'students', 'students-bulk', 'exam-password', 'questions', 'questions-bulk', 'results', 'result-review', 'settings', 'student-results', 'dashboard'].includes(action) || (action === 'exams' && options.method && options.method !== 'GET');
  const headers = {...(options.body ? {'Content-Type': 'application/json'} : {}), ...(adminAction && state.adminToken ? {Authorization: `Bearer ${state.adminToken}`} : {}), ...options.headers};
  let response;
  try { response = await fetch(query(action, params), {...fetchOptions, headers}); }
  catch { throw new Error('Cannot reach the server. Check your connection and try again.'); }
  const data = await response.json().catch(() => ({}));
  if (!response.ok) {
    const error = new Error(data.error || `Request failed (${response.status}).`);
    error.status = response.status;
    if (response.status === 401 && state.adminToken && adminAction) {
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
  state.adminToken = ''; state.adminExpiresAt = '';
  sessionStorage.removeItem('algeAdminToken'); sessionStorage.removeItem('algeAdminExpiresAt');
}
function clearStudentAccess() { state.studentAccess = null; saveStored('algeStudentSession', null); }
function clearAttempt() {
  state.attempt = null; saveStored('algeExamAttempt', null);
  clearInterval(state.timerId); state.timerId = null;
}
function currentRoute() { return decodeURIComponent((location.hash || '#student/selection').slice(1)); }
function navigate(route) {
  closeModal();
  const target = `#${route}`;
  if (location.hash === target) render();
  else location.hash = target;
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
function topbar(showActions = true) {
  return `<header class="topbar">${brand()}${showActions ? '<div class="topbar-actions"><button class="ghost-btn" data-route="admin/overview">Admin panel</button><button class="outline-btn" data-route="student/selection">Student portal</button></div>' : ''}</header>`;
}

async function render() {
  const serial = ++state.loadSerial;
  clearInterval(state.timerId); state.timerId = null;
  clearTimeout(state.availabilityRefreshId); state.availabilityRefreshId = null;
  const route = parts();
  const isAdmin = route[0] === 'admin';
  if (isAdmin && (!state.adminToken || (state.adminExpiresAt && Date.parse(state.adminExpiresAt) <= Date.now()))) {
    if (state.adminToken) { clearAdmin(); toast('Your admin session ended. Please sign in again.', 'error'); }
    if (currentRoute() !== 'admin/login') history.replaceState(null, '', '#admin/login');
    app.innerHTML = adminLoginPage();
    return;
  }
  app.innerHTML = loadingPage(isAdmin);
  try {
    if (isAdmin) await loadAdmin(route);
    else await loadStudent(route);
    if (serial !== state.loadSerial) return;
    app.innerHTML = isAdmin ? adminShell(adminPage(route)) : studentPage(route);
    if (!isAdmin && route[1] === 'exam') startTimer();
    if (!isAdmin && (route[1] || 'selection') === 'selection' && !state.exams.length) scheduleAvailabilityRefresh();
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

async function loadStudent(route) {
  const step = route[1] || 'selection';
  state.exams = normalizeList('activeExams', await api('exams', {}, {active: 'true'}));
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
  try { data = await post('session-resume', {sessionId: attempt.session.id, accessToken: attempt.session.accessToken}); }
  catch (error) {
    if (error.status !== 409) throw error;
    clearAttempt(); clearStudentAccess();
    navigate('student/selection');
    toast('This assessment has already been submitted.', 'info');
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
  if (page === 'overview') state.dashboard = await api('dashboard');
  if (page === 'students') {
    const [students, exams] = await Promise.all([api('students', {}, listQuery('students')), api('exams', {admin: true}, {pageSize: 100})]);
    state.students = normalizeList('students', students); state.adminExams = exams.items || [];
  }
  if (page === 'exams') state.adminExams = normalizeList('exams', await api('exams', {admin: true}, listQuery('exams')));
  if (page === 'questions') {
    const examId = route[2];
    const [exams, questions] = await Promise.all([api('exams', {admin: true}, {pageSize: 100}), api('questions', {}, {...listQuery('questions'), examId})]);
    state.adminExams = exams.items || []; state.questions = normalizeList('questions', questions);
  }
  if (page === 'results') state.results = normalizeList('results', await api('results', {}, listQuery('results')));
  if (page === 'settings') state.settings = (await api('settings')).settings;
  if (page === 'student-results' && route[2]) state.report = await api('student-results', {}, {id: route[2]});
  if (page === 'review' && route[2]) state.review = await api('result-review', {}, {id: route[2]});
}

function studentPage(route) {
  const step = route[1] || 'selection';
  if (step === 'exam') return examPage();
  return `${topbar(step !== 'selection')}${step === 'login' ? studentLoginPage() : step === 'confirm' ? briefingPage() : selectionPage()}`;
}
function selectionPage() {
  const canResume = state.attempt?.session?.id && state.attempt?.examId && state.exams.some(exam => exam.id === state.attempt.examId);
  const empty = emptyState('No assessments are open right now. An assessment must be Active and within its scheduled start and end time. This page checks again automatically.', '<button class="outline-btn" data-action="refresh-exams">Refresh assessments</button>');
  return `<main class="landing"><div class="landing-inner"><section class="hero"><div><div class="eyebrow">Student portal</div><h1>Your next <span>breakthrough</span> starts here.</h1><p class="hero-copy">Select an available assessment. You will need your matric number and the exam password issued by your administrator.</p></div><div class="hero-side"><div class="hero-visual"><img class="hero-illustration" src="student-exam-lab-hero.png" alt="Students taking a computer-based assessment"></div><aside class="hero-aside"><strong>Assessments available now</strong><div class="hero-stat"><span>Open courses</span><b>${state.exams.length}</b></div><div class="hero-stat"><span>Timing</span><b>Set by the server</b></div></aside></div></section>${canResume ? `<section class="panel resume-banner"><strong>You have an assessment in progress.</strong><button class="primary-btn" data-route="student/exam/${esc(state.attempt.examId)}">Resume assessment</button></section>` : ''}<section><div class="section-heading"><div><h2>Available assessments</h2><p>Courses appear here only while they are Active and their scheduled window is open.</p></div></div><div class="exam-grid">${state.exams.map(exam => `<article class="exam-card"><div><div class="exam-code">${esc(exam.code)}</div><h3>${esc(exam.title)}</h3><p>${esc(exam.description)}</p><div class="card-meta"><span><b>${numeric(exam.questionCount)}</b> questions</span><span><b>${numeric(exam.duration)}</b> minutes</span></div></div><div class="card-footer"><span class="table-muted">${esc(exam.window || '')}</span><button class="primary-btn" data-route="student/login/${esc(exam.id)}">Enter exam</button></div></article>`).join('') || empty}</div></section></div></main>`;
}
function studentLoginPage() {
  const exam = selectedExam();
  if (!exam) return missingSelection();
  return `<main class="center-page"><section class="auth-card"><button class="back-link" data-route="student/selection">← Back to assessments</button><div class="eyebrow" style="margin-top:28px">${esc(exam.code)} / Secure access</div><h1>Sign in to begin.</h1><p>Enter the credentials issued for <strong>${esc(exam.title)}</strong>.</p><div id="alert" class="alert" role="alert"></div><form id="student-login-form"><label class="form-field">Matric number<input name="matricNumber" autocomplete="username" required></label><label class="form-field">Exam password${passwordInput('student-exam-password', 'one-time-code', 'minlength="8" maxlength="8" required')}</label><button class="primary-btn wide">Continue to exam briefing →</button></form></section></main>`;
}
function briefingPage() {
  const exam = selectedExam();
  if (!exam) return missingSelection();
  return `<main class="center-page"><section class="confirm-card"><div class="confirm-icon" aria-hidden="true">✓</div><div class="eyebrow" style="margin-top:25px">Ready check / ${esc(exam.code)}</div><h1>${esc(exam.title)}</h1><p>Once you start, the server timer cannot be paused or extended.</p><div class="info-list"><div class="info-item"><span>Questions</span><b>${numeric(exam.questionCount)}</b></div><div class="info-item"><span>Time limit</span><b>${numeric(exam.duration)} min</b></div><div class="info-item"><span>Mode</span><b>Timed</b></div></div><div class="instruction-box"><strong>Before you start</strong>Your answers and flags are saved to the server. If your connection drops, reopen this page and resume the assessment.</div><button class="primary-btn wide" style="margin-top:24px" data-action="start-exam">I'm ready — start exam</button></section></main>`;
}
function missingSelection() { return '<main class="center-page"><section class="auth-card"><h1>Assessment unavailable</h1><button class="primary-btn" data-route="student/selection">Back to assessments</button></section></main>'; }
function examPage() {
  const attempt = state.attempt, exam = selectedExam();
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
  return `<main class="exam-screen"><header class="exam-topbar"><div class="exam-topbar-primary">${brand()}<i class="exam-header-divider" aria-hidden="true"></i><div class="exam-title"><h1>${esc(exam.title)}</h1><p>${esc(exam.code)} · Timed assessment</p></div></div><div class="exam-topbar-actions"><div class="exam-candidate" aria-label="Candidate ${esc(studentName)}, matric number ${esc(studentMatric)}"><span class="candidate-avatar" aria-hidden="true">${esc(candidateInitials(studentName))}</span><span class="candidate-details"><small>Candidate</small><strong>${esc(studentName)}</strong><span>${esc(studentMatric)}</span></span></div><div class="autosave-status" data-state="${save.state}" aria-live="polite"><i aria-hidden="true"></i><span data-save-state>${save.label}</span></div><button class="flag-btn" data-action="flag-question" aria-pressed="${isFlagged}">${isFlagged ? 'Flagged' : 'Flag for review'}</button><div class="timer-block"><span>Time remaining</span><div id="timer" class="timer ${timerClass()}" aria-live="off">${fmtTime(state.secondsLeft)}</div></div></div></header><div class="exam-layout"><section class="question-panel"><div class="question-kicker"><span>Question <b>${state.questionIndex + 1}</b> of ${questions.length}<small>${answerInstruction}</small></span><span id="save-status" class="question-save-status" role="status" aria-live="polite">${selected.length ? 'Answer saved' : 'Not answered'}</span></div><h2 class="math-rendered">${mathHtml(question.text)}</h2><div class="option-list">${(question.options || []).map((option, index) => `<label class="option ${selected.includes(index) ? 'selected' : ''}"><input type="${question.type === 'multiple' ? 'checkbox' : 'radio'}" name="answer" value="${index}" ${selected.includes(index) ? 'checked' : ''}><span class="option-label">${String.fromCharCode(65 + index)}</span><span class="math-rendered">${mathHtml(option)}</span></label>`).join('')}</div><div class="question-actions"><button class="outline-btn" data-action="previous-question" ${!state.questionIndex ? 'disabled' : ''}>← Previous</button>${state.questionIndex === questions.length - 1 ? '<button class="primary-btn" data-action="submit-prompt">Submit assessment</button>' : '<button class="primary-btn" data-action="next-question">Next question →</button>'}</div></section><aside class="exam-sidebar"><section class="exam-progress-card" aria-label="Assessment progress"><div class="exam-progress-heading"><span>Progress</span><strong>${answeredCount}/${questions.length}</strong></div><div class="exam-progress-track" role="progressbar" aria-label="Answered questions" aria-valuemin="0" aria-valuemax="${questions.length}" aria-valuenow="${answeredCount}"><i style="width:${progress}%"></i></div><div class="exam-progress-meta"><span>${unansweredCount} unanswered</span><span>${flaggedCount} flagged</span></div></section><h3>Question map</h3><p class="sidebar-note">Move freely between questions.</p><div class="question-grid">${questions.map((item, index) => `<button class="question-number ${index === state.questionIndex ? 'current' : ''} ${(attempt.answers?.[item.id] || []).length ? 'answered' : ''} ${(attempt.flagged || []).includes(item.id) ? 'flagged' : ''}" data-question-index="${index}" aria-label="Question ${index + 1}${(attempt.answers?.[item.id] || []).length ? ', answered' : ', unanswered'}${(attempt.flagged || []).includes(item.id) ? ', flagged' : ''}">${index + 1}</button>`).join('')}</div><div class="legend"><div class="legend-item"><i class="legend-swatch answered"></i> Answered</div><div class="legend-item"><i class="legend-swatch flagged"></i> Flagged for review</div></div><button class="outline-btn wide" style="margin-top:20px" data-action="submit-prompt">Review and submit</button></aside></div></main>`;
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
  return {sessionId: state.attempt?.session?.id, accessToken: state.attempt?.session?.accessToken, ...extra};
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

function legacyAdminLoginPage() {
  return `<main class="center-page"><section class="auth-card"><button class="back-link" data-route="student/selection">← Student portal</button><div class="eyebrow" style="margin-top:28px">Administrator access</div><h1>Welcome back.</h1><p>Sign in to manage your assessment centre.</p><div id="alert" class="alert" role="alert"></div><form id="admin-login-form"><label class="form-field">Email<input name="email" type="email" autocomplete="username" required></label><label class="form-field">Password${passwordInput('admin-password', 'current-password', 'required')}</label><button class="primary-btn wide">Sign in</button></form></section></main>`;
}
function adminLoginPage() { return `<main class=admin-login-page><section class=admin-login-visual></section>${legacyAdminLoginPage()}</main>`; }
function adminLoginVisual() { return adminLoginVisualA() + adminLoginVisualB() + adminLoginVisualC(); }
function adminLoginForm() { return ''; }

function adminShell(content) {
  const page = parts()[1] || 'overview';
  const active = page === 'questions' ? 'exams' : page === 'student-results' ? 'students' : page === 'review' ? 'results' : page;
  const tabs = [['overview', 'Overview'], ['students', 'Students'], ['exams', 'Exams'], ['results', 'Results'], ['settings', 'Settings']];
  return `<main class="admin-shell"><aside class="admin-sidebar">${brand()}<div class="nav-label">Workspace</div><nav class="admin-nav" aria-label="Admin workspace">${tabs.map(([id, title]) => `<button class="${active === id ? 'active' : ''}" ${active === id ? 'aria-current="page"' : ''} data-route="admin/${id}">${title}</button>`).join('')}</nav><div class="admin-profile"><span class="avatar">A</span><span>Administrator</span></div></aside><section class="admin-content">${content}</section></main>`;
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
  if (page === 'settings') return settingsPage();
  return `${adminHeader('Page not found', 'Choose a page from the navigation.')}<button class="primary-btn" data-route="admin/overview">Go to dashboard</button>`;
}
function statCard(label, value, note = '') {
  return `<div class="stat-card"><div class="stat-label">${esc(label)}</div><div class="stat-value">${esc(value)}</div>${note ? `<div class="stat-change">${esc(note)}</div>` : ''}</div>`;
}
function dashboardPage() {
  const data = state.dashboard || {}, stats = data.stats || {};
  const distributions = data.scoreDistribution || [];
  const courseOutcomes = data.courseOutcomes || [];
  const maxCount = Math.max(1, ...distributions.map(item => numeric(item.count)));
  return `${adminHeader('Dashboard', 'Current assessment activity and outcomes.', '<button class="primary-btn" data-route="admin/students">Register student</button>')}<section class="stat-grid">${statCard('Registered students', numeric(stats.students))}${statCard('Active assessments', numeric(stats.activeExams))}${statCard('Completed today', numeric(stats.completedToday))}${statCard('Average score', `${numeric(stats.averageScore)}%`)}</section><div class="admin-grid"><section class="panel"><div class="panel-heading"><h2>Score distribution</h2></div>${distributions.length ? `<div class="chart-bars" role="img" aria-label="Score distribution">${distributions.map(item => `<div class="chart-row"><span>${esc(item.label)}</span><div class="chart-track"><i style="width:${Math.min(100, numeric(item.count) / maxCount * 100)}%"></i></div><strong>${numeric(item.count)}</strong></div>`).join('')}</div>` : emptyState('No completed exams yet. Score distribution will appear after students submit.')}</section><section class="panel"><div class="panel-heading"><h2>Course outcomes</h2></div>${courseOutcomes.length ? `<div class="mini-list">${courseOutcomes.map(item => `<div class="mini-row"><div><strong>${esc(item.code)} · ${esc(item.title)}</strong><span>${numeric(item.attempts)} submission${numeric(item.attempts) === 1 ? '' : 's'} · ${numeric(item.averageScore)}% average</span></div><div class="outcome-summary"><span>${numeric(item.passed)} pass / ${numeric(item.failed)} fail</span><div class="progress"><i style="width:${Math.max(0, Math.min(100, numeric(item.passRate)))}%"></i></div></div></div>`).join('')}</div>` : emptyState('Course outcomes will appear when results are available.')}</section></div><section class="panel table-panel"><div class="panel-heading"><h2>Recent submissions</h2></div>${resultsTable(data.recent || [], false)}</section>`;
}
function studentsPage() {
  return `${adminHeader('Students', 'Register students and issue exam passwords.', '<button class="outline-btn" data-action="bulk-students">Import CSV</button><button class="primary-btn" data-action="new-student">+ Register student</button>')}<section class="panel table-panel">${filterBar('students', 'Name, matric number, department or email', [['active', 'Active'], ['disabled', 'Disabled']])}${state.students.length ? `<div class="table-scroll"><table class="data-table"><thead><tr>${sortHead('students', 'fullName', 'Student')}${sortHead('students', 'matricNumber', 'Matric')}${sortHead('students', 'department', 'Department')}${sortHead('students', 'email', 'Email')}<th>Access</th><th>Actions</th></tr></thead><tbody>${state.students.map(student => `<tr><td>${esc(student.fullName)}</td><td>${esc(student.matricNumber)}</td><td>${esc(student.department)}</td><td>${esc(student.email)}</td><td><span class="status-pill ${student.active ? '' : 'status-muted'}">${student.active ? 'Active' : 'Disabled'}</span></td><td class="table-actions"><button class="outline-btn small-btn" data-route="admin/student-results/${esc(student.id)}">Results</button><button class="outline-btn small-btn" data-action="student-password" data-id="${esc(student.id)}">Password</button><button class="outline-btn small-btn" data-action="edit-student" data-id="${esc(student.id)}">Edit</button><button class="${student.active ? 'danger-btn' : 'outline-btn'} small-btn" data-action="student-status" data-id="${esc(student.id)}">${student.active ? 'Disable' : 'Reactivate'}</button></td></tr>`).join('')}</tbody></table></div>` : emptyState('No students match your search. Register a student or clear the filters.', '<button class="primary-btn" data-action="new-student">Register student</button>')}${pager('students')}</section>`;
}
function examsPage() {
  const rows = state.adminExams.map(exam => {
    const status = examStatusMeta(exam);
    const editLabel = exam.windowState === 'closed' ? 'Update window' : 'Edit';
    const statusAction = exam.status === 'draft'
      ? `<button class="outline-btn small-btn" data-action="exam-status" data-id="${esc(exam.id)}" data-status="published">Publish</button>`
      : exam.status === 'published'
        ? `<button class="primary-btn small-btn" data-action="exam-status" data-id="${esc(exam.id)}" data-status="active">Activate</button>`
        : `<button class="outline-btn small-btn" data-action="exam-status" data-id="${esc(exam.id)}" data-status="published">Deactivate</button>`;
    return `<tr><td>${esc(exam.code)}</td><td>${esc(exam.title)}</td><td>${numeric(exam.duration)} min</td><td>${numeric(exam.questionCount)}</td><td>${numeric(exam.courseUnit, 3)}</td><td>${esc(exam.session || '—')}</td><td class="table-muted">${fmtDate(exam.startAt)}<br>to ${fmtDate(exam.endAt)}</td><td><span class="status-pill ${status.className}">${esc(status.label)}</span></td><td class="table-actions">${statusAction}<button class="outline-btn small-btn" data-route="admin/questions/${esc(exam.id)}">Questions</button><button class="outline-btn small-btn" data-action="edit-exam" data-id="${esc(exam.id)}">${editLabel}</button><button class="danger-btn small-btn" data-action="delete-exam" data-id="${esc(exam.id)}">Delete</button></td></tr>`;
  }).join('');
  return `${adminHeader('Exams', 'Manage course details, question banks, and exam windows. Students can enter only while an Active exam window is open.', '<button class="primary-btn" data-action="new-exam">+ Create exam</button>')}<section class="panel table-panel">${filterBar('exams', 'Course code or assessment title', [['draft', 'Draft'], ['published', 'Published'], ['active', 'Active']])}${state.adminExams.length ? `<div class="table-scroll"><table class="data-table"><thead><tr>${sortHead('exams', 'code', 'Code')}${sortHead('exams', 'title', 'Assessment')}${sortHead('exams', 'duration', 'Duration')}${sortHead('exams', 'questionCount', 'Questions')}<th>Unit</th><th>Session</th>${sortHead('exams', 'startAt', 'Window')}<th>Status</th><th>Actions</th></tr></thead><tbody>${rows}</tbody></table></div>` : emptyState('No courses match your search. Create an exam to get started.', '<button class="primary-btn" data-action="new-exam">Create exam</button>')}${pager('exams')}</section>`;
}
function questionsPage(examId) {
  const exam = state.adminExams.find(item => item.id === examId);
  if (!exam) return `${adminHeader('Question bank', 'Choose an exam to manage its questions.', '<button class="outline-btn" data-route="admin/exams">Back to exams</button>')}${emptyState('This course was not found. Choose a course from Exams.')}`;
  return `${adminHeader(`${esc(exam.code)} question bank`, `Create and publish questions for ${esc(exam.title)}.`, '<button class="outline-btn" data-action="bulk-questions">Import JSON</button><button class="primary-btn" data-action="new-question">+ Add question</button>')}<section class="panel table-panel">${filterBar('questions', 'Search question text', [['published', 'Published'], ['draft', 'Draft']])}${state.questions.length ? `<div class="table-scroll"><table class="data-table"><thead><tr>${sortHead('questions', 'text', 'Question')}<th>Type</th>${sortHead('questions', 'status', 'Status')}<th>Actions</th></tr></thead><tbody>${state.questions.map(question => `<tr><td class="math-rendered">${mathHtml(question.text)}</td><td>${question.type === 'multiple' ? 'Multiple' : 'Single'}</td><td><span class="status-pill ${question.status === 'published' ? '' : 'status-muted'}">${question.status === 'published' ? 'Published' : 'Draft'}</span></td><td class="table-actions"><button class="${question.status === 'published' ? 'outline-btn' : 'primary-btn'} small-btn" data-action="question-status" data-id="${esc(question.id)}" data-status="${question.status === 'published' ? 'draft' : 'published'}">${question.status === 'published' ? 'Unpublish' : 'Publish'}</button><button class="outline-btn small-btn" data-action="edit-question" data-id="${esc(question.id)}">Edit</button><button class="danger-btn small-btn" data-action="delete-question" data-id="${esc(question.id)}">Delete</button></td></tr>`).join('')}</tbody></table></div>` : emptyState('No questions match your search. Add the first question for this course.', '<button class="primary-btn" data-action="new-question">Add question</button>')}${pager('questions')}</section><button class="outline-btn" style="margin-top:18px" data-route="admin/exams">Back to exams</button>`;
}
function resultsTable(items, includePager = false) {
  if (!items.length) return emptyState('No completed results yet. Submitted assessments will appear here.');
  return `<div class="table-scroll"><table class="data-table"><thead><tr><th>Student</th><th>Assessment</th><th>Submitted</th><th>Score</th><th>Grade</th><th>Status</th><th>Actions</th></tr></thead><tbody>${items.map(result => `<tr><td>${esc(result.studentName || 'Student')}</td><td>${esc(result.examCode || 'Exam')}</td><td class="table-muted">${fmtDate(result.submittedAt)}</td><td><strong>${numeric(result.score)}%</strong></td><td>${esc(result.grade || '—')}</td><td><span class="status-pill ${result.status === 'auto_submitted' ? 'status-warning' : ''}">${result.status === 'auto_submitted' ? 'Auto-submitted' : 'Submitted'}</span></td><td class="table-actions"><button class="outline-btn small-btn" data-route="admin/review/${esc(result.id)}">Review</button><button class="outline-btn small-btn" data-route="admin/student-results/${esc(result.studentId)}">Result sheet</button></td></tr>`).join('')}</tbody></table></div>${includePager ? pager('results') : ''}`;
}
function resultsPage() {
  return `${adminHeader('Results', 'Server-recorded scores and submissions.') }<section class="panel table-panel">${filterBar('results', 'Student, matric number or course', [['submitted', 'Manual'], ['auto_submitted', 'Auto-submitted']])}${resultsTable(state.results, true)}</section>`;
}
function reviewPage() {
  const review = state.review;
  if (!review) return `${adminHeader('Result review', 'Select a result to inspect.')}<button class="outline-btn" data-route="admin/results">Back to results</button>`;
  const result = review.result || {}, student = review.student || {}, exam = review.exam || {};
  return `${adminHeader('Per-question review', `${esc(student.fullName || '')} · ${esc(exam.code || '')} · ${numeric(result.score)}%`, '<button class="outline-btn" data-route="admin/results">Back to results</button>')}<section class="panel review-list">${(review.questions || []).map((question, index) => `<article class="review-question"><div class="review-heading"><strong>Question ${index + 1}</strong><span class="status-pill ${question.isCorrect ? '' : 'status-danger'}">${question.isCorrect ? 'Correct' : 'Incorrect'}</span>${question.flagged ? '<span class="status-pill status-warning">Flagged</span>' : ''}</div><h2 class="math-rendered">${mathHtml(question.text)}</h2><div class="review-options">${(question.options || []).map((option, optionIndex) => `<div class="review-option ${question.correctOptions?.includes(optionIndex) ? 'is-correct' : ''} ${question.answers?.includes(optionIndex) ? 'is-selected' : ''}"><strong>${String.fromCharCode(65 + optionIndex)}.</strong> <span class="math-rendered">${mathHtml(option)}</span>${question.correctOptions?.includes(optionIndex) ? ' <small>Correct answer</small>' : ''}${question.answers?.includes(optionIndex) ? ' <small>Student answer</small>' : ''}</div>`).join('')}</div></article>`).join('') || emptyState('No question details are available for this result.')}</section>`;
}
function studentResultsPage() {
  const report = state.report;
  if (!report) return `${adminHeader('Student result', 'Select a student to view a result sheet.')}<button class="outline-btn" data-route="admin/students">Back to students</button>`;
  const groups = report.sessions || {};
  return `${adminHeader(`${esc(report.student?.fullName || '')} result sheet`, `${esc(report.student?.matricNumber || '')} · ${esc(report.student?.department || '')}`, '<button class="outline-btn" data-action="export-csv">Export CSV</button>')}<section class="panel table-panel"><div class="table-scroll"><table class="data-table"><thead><tr><th>Course</th><th>Unit</th><th>Score</th><th>Grade</th><th>Grade point</th><th>Quality points</th><th>Session</th><th></th></tr></thead><tbody>${(report.items || []).map(item => `<tr><td>${esc(item.courseCode)}<small class="table-muted" style="display:block">${esc(item.courseTitle || '')}</small></td><td>${numeric(item.courseUnit)}</td><td>${numeric(item.score)}%</td><td><strong>${esc(item.grade)}</strong></td><td>${numeric(item.gradePoint)}</td><td>${numeric(item.qualityPoints)}</td><td>${esc(item.session || 'Unassigned')}</td><td><button class="outline-btn small-btn" data-route="admin/review/${esc(item.id)}">Review</button></td></tr>`).join('') || '<tr><td colspan="8">No completed courses yet.</td></tr>'}</tbody></table></div></section><section class="stat-grid" style="margin-top:18px">${Object.entries(groups).map(([session, value]) => statCard(`GPA · ${session}`, numeric(value.gpa).toFixed(2), `${numeric(value.courseUnit)} total units`)).join('')}${statCard('Cumulative GPA', numeric(report.cgpa).toFixed(2), 'Across all completed sessions')}</section>`;
}
function settingsPage() {
  const scale = state.settings?.gradingScale || [];
  return `${adminHeader('Settings', 'Configure grade bands used to calculate GPA and CGPA.')}<section class="panel"><div class="panel-heading"><h2>Grading scale</h2></div><p class="table-muted">Ranges must cover scores from 0 to 100 without overlapping. Saved changes recalculate existing results.</p><form id="grading-form"><div class="table-scroll"><table class="data-table"><thead><tr><th>Minimum</th><th>Maximum</th><th>Grade</th><th>Point</th><th></th></tr></thead><tbody id="grading-rows">${scale.map(gradingRow).join('')}</tbody></table></div><div class="modal-actions"><button type="button" class="outline-btn" data-action="add-grade-row">Add row</button><button class="primary-btn">Save grading scale</button></div></form></section><section class="panel" style="margin-top:18px"><button class="danger-btn" data-action="admin-logout">Sign out</button></section>`;
}
function gradingRow(row = {}) {
  return `<tr><td><input class="select-field" name="minScore" type="number" min="0" max="100" step="0.01" value="${esc(row.minScore ?? '')}" required></td><td><input class="select-field" name="maxScore" type="number" min="0" max="100" step="0.01" value="${esc(row.maxScore ?? '')}" required></td><td><input class="select-field" name="grade" maxlength="5" value="${esc(row.grade ?? '')}" required></td><td><input class="select-field" name="gradePoint" type="number" min="0" max="5" step="0.01" value="${esc(row.gradePoint ?? '')}" required></td><td><button type="button" class="danger-btn small-btn" data-action="remove-grade-row" aria-label="Remove grade row">Remove</button></td></tr>`;
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
  openModal(edit ? 'Edit student' : 'Register student', `<form id="student-form" data-id="${esc(student?.id || '')}"><div class="alert" role="alert"></div><label class="form-field">Full name<input name="fullName" value="${esc(student?.fullName || '')}" required></label><label class="form-field">Email<input name="email" type="email" value="${esc(student?.email || '')}" required></label><label class="form-field">Matric number<input name="matricNumber" value="${esc(student?.matricNumber || '')}" required></label><label class="form-field">Department<input name="department" value="${esc(student?.department || '')}" required></label><div class="modal-actions"><button class="primary-btn">${edit ? 'Save changes' : 'Register student'}</button></div></form>`);
}
function bulkStudentsForm() {
  openModal('Import students', `<p>Upload a UTF-8 CSV file with the headers <strong>fullName,email,matricNumber,department</strong>, or paste the CSV below. Existing matric numbers will be rejected.</p><form id="bulk-students-form"><div class="alert" role="alert"></div><label class="form-field">CSV file<input name="file" type="file" accept=".csv,text/csv"></label><label class="form-field">Or paste CSV<textarea name="csv" rows="7" placeholder="fullName,email,matricNumber,department"></textarea></label><div class="modal-actions"><button class="primary-btn">Import students</button></div></form>`);
}
function passwordForm(student) {
  openModal('Generate exam password', `<form id="password-form"><div class="alert" role="alert"></div><label class="form-field">Matric number<input name="matricNumber" value="${esc(student?.matricNumber || '')}" required></label><label class="form-field">Assessment<select class="select-field" name="examId" required>${state.adminExams.map(exam => `<option value="${esc(exam.id)}">${esc(exam.code)} — ${esc(exam.title)}</option>`).join('')}</select></label><div class="modal-actions"><button class="primary-btn">Generate password</button></div></form>`);
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
function questionForm(question = null) {
  const edit = Boolean(question);
  const options = question?.options || [];
  openModal(edit ? 'Edit question' : 'Add question', `<form id="question-form" data-id="${esc(question?.id || '')}"><div class="alert" role="alert"></div><label class="form-field">Assessment<select class="select-field" name="examId" required>${state.adminExams.map(exam => `<option value="${esc(exam.id)}" ${exam.id === (question?.examId || parts()[2]) ? 'selected' : ''}>${esc(exam.code)}</option>`).join('')}</select></label><label class="form-field">Question text<textarea class="math-input math-question-input" data-math-field="Question text" name="text" rows="3" required>${esc(question?.text || '')}</textarea></label>${mathKeyboard()}${[0, 1, 2, 3].map(index => `<label class="form-field">Option ${String.fromCharCode(65 + index)}<textarea class="math-input math-option-input" data-math-field="Option ${String.fromCharCode(65 + index)}" name="o${index}" rows="2" required>${esc(options[index] || '')}</textarea></label>`).join('')}<fieldset class="correct-options"><legend>Correct answer(s)</legend><p>Tick the correct option. For a multiple-answer question, tick every correct option.</p>${[0, 1, 2, 3].map(index => `<label><input type="checkbox" name="correctOptions" value="${index}" ${question?.correctOptions?.includes(index) ? 'checked' : ''}> Option ${String.fromCharCode(65 + index)}</label>`).join('')}</fieldset><label class="form-field">Type<select class="select-field" name="type"><option value="single" ${question?.type !== 'multiple' ? 'selected' : ''}>Single answer</option><option value="multiple" ${question?.type === 'multiple' ? 'selected' : ''}>Multiple answers</option></select></label><label class="form-field">Status<select class="select-field" name="status"><option value="draft" ${question?.status !== 'published' ? 'selected' : ''}>Draft</option><option value="published" ${question?.status === 'published' ? 'selected' : ''}>Published</option></select></label><div class="modal-actions"><button class="primary-btn">${edit ? 'Save changes' : 'Save question'}</button></div></form>`);
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
  const fields = {fullname: 'fullName', email: 'email', matricnumber: 'matricNumber', department: 'department'};
  for (const key of Object.keys(fields)) if (!headers.includes(key)) throw new Error(`Missing CSV header: ${fields[key]}.`);
  return rows.map((values, index) => {
    const item = {};
    for (const [key, field] of Object.entries(fields)) item[field] = (values[headers.indexOf(key)] || '').trim();
    if (Object.values(item).some(value => !value)) throw new Error(`Row ${index + 2} has an empty required field.`);
    return item;
  });
}

async function handleForm(event) {
  const form = event.target;
  if (!form.matches('form')) return;
  event.preventDefault();
  const submitButton = form.querySelector('button[type="submit"], button:not([type])');
  setBusy(submitButton, true);
  try {
    if (form.id === 'admin-login-form') {
      const response = await post('admin-login', formData(form));
      state.adminToken = response.token;
      state.adminExpiresAt = response.expiresAt || '';
      sessionStorage.setItem('algeAdminToken', response.token);
      if (response.expiresAt) sessionStorage.setItem('algeAdminExpiresAt', response.expiresAt);
      toast('Signed in successfully.', 'success'); navigate('admin/overview');
    } else if (form.id === 'student-login-form') {
      const response = await post('student-login', {...formData(form), examId: state.selectedExamId});
      state.studentAccess = {student: response.student, exam: response.exam, loginToken: response.loginToken, examId: state.selectedExamId};
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
    } else if (form.id === 'grading-form') {
      const rows = [...form.querySelectorAll('#grading-rows tr')];
      const gradingScale = rows.map(row => Object.fromEntries([...row.querySelectorAll('input')].map(input => [input.name, ['minScore', 'maxScore', 'gradePoint'].includes(input.name) ? Number(input.value) : input.value.trim()])));
      if (!gradingScale.length) throw new Error('Add at least one grade band.');
      await put('settings', {gradingScale});
      toast('Grading scale saved. Existing results were recalculated.', 'success'); render();
    } else if (form.dataset.filterForm) {
      const name = form.dataset.filterForm, data = formData(form);
      state.filters[name].q = String(data.q || '').trim(); state.filters[name].status = String(data.status || ''); state.filters[name].page = 1;
      render();
    }
  } catch (error) { showError(error, form); }
  finally { if (submitButton?.isConnected) setBusy(submitButton, false); }
}

async function handleAction(button) {
  const action = button.dataset.action;
  const id = button.dataset.id;
  if (action === 'retry') return render();
  if (action === 'refresh-exams') return render();
  if (action === 'close-modal') return closeModal();
  if (action === 'new-student') return studentForm();
  if (action === 'bulk-students') return bulkStudentsForm();
  if (action === 'edit-student') return studentForm(state.students.find(item => item.id === id));
  if (action === 'student-password') return passwordForm(state.students.find(item => item.id === id));
  if (action === 'new-exam') return examForm();
  if (action === 'edit-exam') return examForm(state.adminExams.find(item => item.id === id));
  if (action === 'new-question') return questionForm();
  if (action === 'edit-question') return questionForm(state.questions.find(item => item.id === id));
  if (action === 'bulk-questions') return bulkQuestionsForm();
  if (action === 'admin-logout') {
    try { await post('admin-logout', {}); } catch { /* Local sign out still revokes this browser's access. */ }
    clearAdmin(); navigate('admin/login'); toast('Signed out.', 'success'); return;
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
  if (action === 'export-csv') {
    try {
      const response = await fetch(query('student-results-csv', {id: parts()[2]}), {headers: {Authorization: `Bearer ${state.adminToken}`}});
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
      const data = await post('session-start', {studentId: state.studentAccess.student.id, examId: state.selectedExamId, loginToken: state.studentAccess.loginToken});
      state.attempt = {examId: state.selectedExamId, exam: selectedExam(), student: state.studentAccess.student, session: data.session, questions: data.questions || [], answers: data.session?.answers || {}, flagged: data.session?.flagged || []};
      state.saveState = 'saved';
      state.questionIndex = 0; saveStored('algeExamAttempt', state.attempt);
      navigate(`student/exam/${state.selectedExamId}`);
    } catch (error) { showError(error); } finally { if (button.isConnected) setBusy(button, false); }
    return;
  }
  if (action === 'next-question' || action === 'previous-question') { state.questionIndex += action === 'next-question' ? 1 : -1; render(); return; }
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
  if (routeButton) { navigate(routeButton.dataset.route); return; }
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
  if (clearButton) { const filter = state.filters[clearButton.dataset.filterClear]; filter.q = ''; filter.status = ''; filter.page = 1; render(); return; }
  const questionButton = event.target.closest('[data-question-index]');
  if (questionButton) { state.questionIndex = numeric(questionButton.dataset.questionIndex); render(); return; }
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
  if (event.target.matches('[data-math-field]')) updateMathPreview(event.target);
});
document.addEventListener('change', async event => {
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
  post('session-integrity', attemptPayload({event: eventName})).catch(() => {});
}
document.addEventListener('visibilitychange', () => { if (document.hidden) logIntegrity('hidden'); });
window.addEventListener('blur', () => logIntegrity('blur'));
window.addEventListener('hashchange', render);
if (!location.hash) history.replaceState(null, '', '#student/selection');
render();
