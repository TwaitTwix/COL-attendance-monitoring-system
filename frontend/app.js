const API_BASE = 'http://localhost:8000/api';

const state = {
  token: localStorage.getItem('attendance_token') || '',
  user: JSON.parse(localStorage.getItem('attendance_user') || 'null'),
};

const loginForm = document.getElementById('login-form');
const loginMessage = document.getElementById('login-message');
const navButtons = document.querySelectorAll('.nav-button');
const panels = {
  login: document.getElementById('login-panel'),
  overview: document.getElementById('overview-panel'),
  student: document.getElementById('student-panel'),
};

const overviewCards = document.getElementById('overview-cards');
const overviewTable = document.getElementById('overview-table');
const studentCards = document.getElementById('student-cards');
const studentTable = document.getElementById('student-table');

function setMessage(element, text, kind = '') {
  element.textContent = text;
  element.className = 'message';
  if (kind) element.classList.add(kind);
}

function showView(name) {
  Object.entries(panels).forEach(([key, panel]) => {
    panel.classList.toggle('active', key === name);
  });

  navButtons.forEach((button) => {
    button.classList.toggle('active', button.dataset.view === name);
  });
}

function apiHeaders() {
  return {
    'Content-Type': 'application/json',
    ...(state.token ? { Authorization: `Bearer ${state.token}` } : {}),
  };
}

async function requestJson(url, options = {}) {
  const response = await fetch(url, {
    ...options,
    headers: {
      ...apiHeaders(),
      ...(options.headers || {}),
    },
  });

  const data = await response.json().catch(() => ({}));

  if (!response.ok) {
    throw new Error(data.message || 'Request failed.');
  }

  return data;
}

async function handleLogin(event) {
  event.preventDefault();
  setMessage(loginMessage, 'Signing in...', 'success');

  const email = document.getElementById('email').value.trim();
  const password = document.getElementById('password').value;

  try {
    const result = await requestJson(`${API_BASE}/login.php`, {
      method: 'POST',
      body: JSON.stringify({ email, password }),
    });

    state.token = result.access_token;
    state.user = result.profile;
    localStorage.setItem('attendance_token', state.token);
    localStorage.setItem('attendance_user', JSON.stringify(state.user));

    setMessage(loginMessage, `Welcome, ${result.profile.full_name || result.profile.email}.`, 'success');
    showView('overview');
    loadAdminOverview();
  } catch (error) {
    setMessage(loginMessage, error.message, 'error');
  }
}

function renderCards(container, items) {
  container.innerHTML = items
    .map(
      (item) => `
        <div class="card">
          <div class="label">${item.label}</div>
          <div class="value">${item.value}</div>
        </div>
      `
    )
    .join('');
}

function renderRows(table, rows, columns) {
  if (!rows || rows.length === 0) {
    table.innerHTML = '<tr><td colspan="' + columns.length + '" class="empty">No records found.</td></tr>';
    return;
  }

  table.innerHTML = rows
    .map(
      (row) => `
        <tr>
          ${columns.map((column) => `<td>${row[column] ?? '—'}</td>`).join('')}
        </tr>
      `
    )
    .join('');
}

async function loadAdminOverview() {
  if (!state.token) {
    setMessage(loginMessage, 'Please log in first.', 'error');
    return;
  }

  try {
    const result = await requestJson(`${API_BASE}/admin/dashboard-overview.php`);
    const stats = result.stats || {};
    const recent = result.recent_events || [];

    renderCards(overviewCards, [
      { label: 'Total events', value: stats.total_events ?? 0 },
      { label: 'Total sessions', value: stats.total_sessions ?? 0 },
      { label: 'Active students', value: stats.total_active_students ?? 0 },
      { label: 'Admins', value: stats.total_admins ?? 0 },
    ]);

    renderRows(
      overviewTable,
      recent.map((event) => ({
        title: event.title,
        status: event.status,
        sessions: event.session_count,
        students: event.student_count,
      })),
      ['title', 'status', 'sessions', 'students']
    );
  } catch (error) {
    setMessage(loginMessage, error.message, 'error');
  }
}

async function loadStudentDashboard() {
  if (!state.token) {
    setMessage(loginMessage, 'Please log in first.', 'error');
    return;
  }

  try {
    const result = await requestJson(`${API_BASE}/student/student-dashboard.php`);
    const stats = result.stats || {};
    const recent = result.recent_records || [];

    renderCards(studentCards, [
      { label: 'Sessions attended', value: stats.attended_sessions ?? 0 },
      { label: 'Present', value: stats.present_count ?? 0 },
      { label: 'Late', value: stats.late_count ?? 0 },
      { label: 'Pending review', value: stats.pending_review_count ?? 0 },
    ]);

    renderRows(
      studentTable,
      recent.map((record) => ({
        event: record.event_title,
        session: record.session_name,
        status: record.status,
        checked_in: record.checked_in_at ? new Date(record.checked_in_at).toLocaleString() : '—',
      })),
      ['event', 'session', 'status', 'checked_in']
    );
  } catch (error) {
    setMessage(loginMessage, error.message, 'error');
  }
}

navButtons.forEach((button) => {
  button.addEventListener('click', () => {
    const view = button.dataset.view;
    showView(view);

    if (view === 'overview') loadAdminOverview();
    if (view === 'student') loadStudentDashboard();
  });
});

document.getElementById('load-overview').addEventListener('click', loadAdminOverview);
document.getElementById('load-student').addEventListener('click', loadStudentDashboard);
loginForm.addEventListener('submit', handleLogin);

if (state.token && state.user) {
  setMessage(loginMessage, `Session restored for ${state.user.full_name || state.user.email}.`, 'success');
  showView('overview');
  loadAdminOverview();
}
