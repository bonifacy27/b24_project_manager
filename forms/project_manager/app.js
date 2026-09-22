const modal = document.querySelector('#modal');
const toast = document.querySelector('#toast');
const board = document.querySelector('#kanban');
const form = document.querySelector('#issueForm');
const state = { issues: [], project: 'all', query: '', view: 'board' };
const apiUrl = new URL(window.location.href);
apiUrl.search = '';
apiUrl.hash = '';
apiUrl.searchParams.set('project_manager_api', 'issues');

const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
})[character]);

function initials(name) {
  return String(name || '?').trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase();
}

function targetColumn(issue) {
  if (!issue.dueDate) return 'later';
  const due = new Date(`${issue.dueDate}T23:59:59`);
  const monday = new Date();
  monday.setHours(0, 0, 0, 0);
  monday.setDate(monday.getDate() - ((monday.getDay() + 6) % 7));
  const nextMonday = new Date(monday); nextMonday.setDate(monday.getDate() + 7);
  const afterNextMonday = new Date(monday); afterNextMonday.setDate(monday.getDate() + 14);
  if (due < nextMonday) return 'week';
  if (due < afterNextMonday) return 'next';
  return 'later';
}

function issueCard(issue) {
  const project = issue.project === 'ssis' ? 'ssis' : 'ods';
  const avatar = issue.assignee?.avatar
    ? `<img src="${escapeHtml(issue.assignee.avatar)}" alt="">`
    : escapeHtml(initials(issue.assignee?.name));
  const due = issue.dueDate
    ? new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'short' }).format(new Date(`${issue.dueDate}T00:00:00`))
    : 'Без срока';
  return `<article class="task-card" data-project="${project}" tabindex="0" data-url="${escapeHtml(issue.url)}">
    <div class="card-top"><span class="key ${project}-key">${escapeHtml(issue.key)}</span><span class="status-pill">${escapeHtml(issue.status)}</span></div>
    <h3>${escapeHtml(issue.summary)}</h3><p>${escapeHtml(issue.type || 'Заявка')} · ${escapeHtml(issue.priority)}</p>
    <div class="card-meta"><span class="assignee" title="${escapeHtml(issue.assignee?.name || 'Не назначен')}">${avatar}</span><span>◴ ${escapeHtml(due)}</span><span>▱ ${Number(issue.comments) || 0}</span></div>
  </article>`;
}

function visibleIssues() {
  return state.issues.filter(issue => {
    const matchesProject = state.project === 'all' || issue.project === state.project;
    const haystack = `${issue.key} ${issue.summary} ${issue.status} ${issue.assignee?.name || ''}`.toLowerCase();
    return matchesProject && haystack.includes(state.query);
  });
}

function render() {
  const issues = visibleIssues();
  document.querySelectorAll('.column').forEach(column => {
    const columnIssues = issues.filter(issue => targetColumn(issue) === column.dataset.column);
    column.querySelector('.cards').innerHTML = columnIssues.length
      ? columnIssues.map(issueCard).join('')
      : '<div class="empty-card">В этом периоде пока нет задач.</div>';
    column.querySelector('header b').textContent = columnIssues.length;
  });
  document.querySelectorAll('.task-card').forEach(card => {
    const open = () => card.dataset.url && window.open(card.dataset.url, '_blank', 'noopener');
    card.addEventListener('click', open);
    card.addEventListener('keydown', event => { if (event.key === 'Enter') open(); });
  });
}

function updateSummary(totals = {}) {
  ['ods', 'ssis'].forEach(project => {
    document.querySelector(`[data-project="${project}"] b`).textContent = totals[project] ?? state.issues.filter(i => i.project === project).length;
  });
  const total = Object.values(totals).reduce((sum, value) => sum + Number(value), 0) || state.issues.length;
  document.querySelector('[data-project="all"] b').textContent = total;
  document.querySelector('#activeTotal').textContent = total;
  document.querySelector('#weekTotal').textContent = state.issues.filter(issue => targetColumn(issue) === 'week').length;
  document.querySelector('#waitingTotal').textContent = state.issues.filter(issue => /ожида|waiting|pending/i.test(issue.status)).length;
  const today = new Date().toISOString().slice(0, 10);
  document.querySelector('#updatedTotal').textContent = state.issues.filter(issue => issue.updated?.slice(0, 10) === today).length;
}

async function readApiResponse(response) {
  const body = await response.text();
  try {
    return JSON.parse(body);
  } catch (error) {
    const isHtml = /^\s*(?:<!doctype|<html)/i.test(body);
    const message = isHtml
      ? `API вернул HTML вместо JSON (HTTP ${response.status}). Проверьте журнал PHP/Bitrix.`
      : `API вернул некорректный ответ (HTTP ${response.status}).`;
    throw new Error(message, { cause: error });
  }
}

function showDiagnostics(diagnostics) {
  const panel = document.querySelector('#jiraDiagnostics');
  if (!diagnostics) { panel.hidden = true; return; }
  const labels = {
    baseUrl: 'Jira URL', username: 'Логин', password: 'Пароль (маска)', passwordLength: 'Длина пароля',
    passwordFingerprint: 'SHA-256 (первые 12)', method: 'Метод', url: 'Запрос Jira', httpStatus: 'HTTP статус',
    contentType: 'Content-Type', curlError: 'cURL', jiraResponse: 'Ответ Jira'
  };
  panel.querySelector('pre').textContent = Object.entries(diagnostics)
    .map(([key, value]) => `${labels[key] || key}: ${value === '' ? '—' : value}`)
    .join('\n');
  panel.hidden = false;
}

async function loadIssues() {
  document.querySelector('#integrationNotice').hidden = true;
  document.querySelectorAll('.cards').forEach(cards => { cards.innerHTML = '<div class="loading-card"></div><div class="loading-card"></div>'; });
  try {
    const response = await fetch(apiUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const data = await readApiResponse(response);
    if (!response.ok) {
      showDiagnostics(data.diagnostics);
      throw new Error(data.error || `Ошибка Jira (${response.status})`);
    }
    state.issues = Array.isArray(data.issues) ? data.issues : [];
    showDiagnostics(null);
    updateSummary(data.totals || {});
    render();
  } catch (error) {
    document.querySelectorAll('.cards').forEach(cards => { cards.innerHTML = `<div class="error-card"><strong>Не удалось загрузить задачи</strong><br>${escapeHtml(error.message)}</div>`; });
    document.querySelector('#integrationNotice').hidden = false;
  }
}

function showToast(title, detail, error = false) {
  toast.querySelector('strong').textContent = title;
  toast.querySelector('small').textContent = detail;
  toast.classList.toggle('error', error);
  toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 4000);
}
function openModal(plan = 'week') { modal.hidden = false; form.elements.plan.value = plan; form.elements.title.focus(); }
function closeModal() { modal.hidden = true; form.querySelector('.form-error').hidden = true; }

document.querySelector('#createBtn').addEventListener('click', () => openModal());
document.querySelectorAll('.add-card').forEach(button => button.addEventListener('click', () => openModal(button.closest('.column').dataset.column)));
modal.querySelector('.modal-close').addEventListener('click', closeModal);
modal.querySelector('.cancel').addEventListener('click', closeModal);
modal.addEventListener('click', event => { if (event.target === modal) closeModal(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') closeModal(); });

form.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = form.querySelector('[type="submit"]');
  const errorBox = form.querySelector('.form-error');
  submit.disabled = true; submit.textContent = 'Создаём…'; errorBox.hidden = true;
  try {
    const values = Object.fromEntries(new FormData(form));
    const response = await fetch(apiUrl, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(values)
    });
    const data = await readApiResponse(response);
    if (!response.ok) {
      showDiagnostics(data.diagnostics);
      throw new Error(data.error || `Ошибка Jira (${response.status})`);
    }
    closeModal(); form.reset();
    showToast(`Заявка ${data.issue.key} создана`, 'Доска обновлена');
    await loadIssues();
  } catch (error) {
    errorBox.textContent = error.message; errorBox.hidden = false;
  } finally {
    submit.disabled = false; submit.textContent = 'Создать заявку';
  }
});

document.querySelectorAll('.project-tabs button').forEach(tab => tab.addEventListener('click', () => {
  document.querySelectorAll('.project-tabs button').forEach(item => item.classList.remove('active'));
  tab.classList.add('active'); state.project = tab.dataset.project; render();
}));
document.querySelector('#searchInput').addEventListener('input', event => { state.query = event.target.value.trim().toLowerCase(); render(); });
document.querySelector('#refreshBtn').addEventListener('click', loadIssues);
document.querySelectorAll('.view-switch button').forEach(button => button.addEventListener('click', () => {
  document.querySelectorAll('.view-switch button').forEach(item => item.classList.remove('active'));
  button.classList.add('active'); state.view = button.dataset.view; board.classList.toggle('list-view', state.view === 'list');
}));
document.querySelector('#reportBtn').addEventListener('click', () => {
  const rows = [['Ключ', 'Проект', 'Название', 'Статус', 'Срок'], ...visibleIssues().map(i => [i.key, i.project.toUpperCase(), i.summary, i.status, i.dueDate || ''])];
  const csv = rows.map(row => row.map(value => `"${String(value).replaceAll('"', '""')}"`).join(';')).join('\r\n');
  const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
  link.download = `jira-report-${new Date().toISOString().slice(0, 10)}.csv`; link.click(); URL.revokeObjectURL(link.href);
  showToast('Отчёт сформирован', 'Файл CSV скачан');
});

loadIssues();
