const modal = document.querySelector('#modal');
const metadataModal = document.querySelector('#metadataModal');
const toast = document.querySelector('#toast');
const board = document.querySelector('#kanban');
const form = document.querySelector('#issueForm');
const metadataForm = document.querySelector('#metadataForm');
const state = { issues: [], project: 'all', query: '', view: 'board', draggedKey: null };
const apiUrl = new URL(window.location.href);
apiUrl.search = '';
apiUrl.hash = '';
apiUrl.searchParams.set('project_manager_api', 'issues');

const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
})[character]);
const bucketLabels = { week: 'Эта неделя', next: 'Следующая неделя', backlog: 'Бэклог', year: 'Проекты на этот год', next_year: 'Проекты на следующий год' };

function initials(name) {
  return String(name || '?').trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase();
}

function targetColumn(issue) {
  return bucketLabels[issue.metadata?.bucket] ? issue.metadata.bucket : 'backlog';
}

function issueCard(issue) {
  const project = issue.project === 'ssis' ? 'ssis' : 'ods';
  const avatar = issue.assignee?.avatar
    ? `<img src="${escapeHtml(issue.assignee.avatar)}" alt="">`
    : escapeHtml(initials(issue.assignee?.name));
  const description = issue.metadata?.shortDescription
    ? `<p class="local-description">${escapeHtml(issue.metadata.shortDescription)}</p>`
    : '<p class="local-description muted">Краткое описание не добавлено</p>';
  return `<article class="task-card" data-project="${project}" tabindex="0" draggable="true" data-key="${escapeHtml(issue.key)}" data-url="${escapeHtml(issue.url)}">
    <div class="card-top"><span class="key ${project}-key">${escapeHtml(issue.key)}</span><span class="status-pill">${escapeHtml(issue.status)}</span></div>
    <h3>${escapeHtml(issue.summary)}</h3>${description}
    <div class="note-counts"><span>План: ${issue.metadata?.plannedActions?.length || 0}</span><span>Факт: ${issue.metadata?.facts?.length || 0}</span></div>
    <div class="card-meta"><span class="assignee" title="${escapeHtml(issue.assignee?.name || 'Не назначен')}">${avatar}</span><span>${escapeHtml(issue.type || 'Заявка')}</span><button class="edit-metadata" type="button" aria-label="Редактировать план">✎ План</button></div>
  </article>`;
}

function visibleIssues() {
  return state.issues.filter(issue => {
    const matchesProject = state.project === 'all' || issue.project === state.project;
    const haystack = `${issue.key} ${issue.summary} ${issue.status} ${issue.assignee?.name || ''} ${issue.metadata?.shortDescription || ''}`.toLowerCase();
    return matchesProject && haystack.includes(state.query);
  });
}

function render() {
  const issues = visibleIssues();
  document.querySelectorAll('.column').forEach(column => {
    const columnIssues = issues.filter(issue => targetColumn(issue) === column.dataset.column);
    column.querySelector('.cards').innerHTML = columnIssues.length
      ? columnIssues.map(issueCard).join('')
      : '<div class="empty-card">В этой категории пока нет заявок.</div>';
    column.querySelector('header b').textContent = columnIssues.length;
  });
  document.querySelectorAll('.task-card').forEach(card => {
    const issue = findIssue(card.dataset.key);
    card.addEventListener('click', event => {
      if (event.target.closest('.edit-metadata')) openMetadata(issue);
      else if (card.dataset.url) window.open(card.dataset.url, '_blank', 'noopener');
    });
    card.addEventListener('keydown', event => { if (event.key === 'Enter') openMetadata(issue); });
    card.addEventListener('dragstart', () => { state.draggedKey = card.dataset.key; card.classList.add('dragging'); });
    card.addEventListener('dragend', () => { state.draggedKey = null; card.classList.remove('dragging'); });
  });
}

function findIssue(key) {
  return state.issues.find(issue => issue.key === key);
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
  try { return JSON.parse(body); } catch (error) {
    const message = /^\s*(?:<!doctype|<html)/i.test(body)
      ? `API вернул HTML вместо JSON (HTTP ${response.status}). Проверьте журнал PHP/Bitрикс.`
      : `API вернул некорректный ответ (HTTP ${response.status}).`;
    throw new Error(message, { cause: error });
  }
}

function showDiagnostics(diagnostics) {
  const panel = document.querySelector('#jiraDiagnostics');
  if (!diagnostics) { panel.hidden = true; return; }
  const labels = { baseUrl: 'Jira URL', username: 'Логин', password: 'Пароль (маска)', passwordLength: 'Длина пароля', passwordFingerprint: 'SHA-256 (первые 12)', method: 'Метод', url: 'Запрос Jira', httpStatus: 'HTTP статус', contentType: 'Content-Type', curlError: 'cURL', jiraResponse: 'Ответ Jira' };
  panel.querySelector('pre').textContent = Object.entries(diagnostics).map(([key, value]) => `${labels[key] || key}: ${value === '' ? '—' : value}`).join('\n');
  panel.hidden = false;
}

async function loadIssues() {
  document.querySelector('#integrationNotice').hidden = true;
  document.querySelectorAll('.cards').forEach(cards => { cards.innerHTML = '<div class="loading-card"></div><div class="loading-card"></div>'; });
  try {
    const response = await fetch(apiUrl, { headers: { Accept: 'application/json' }, credentials: 'same-origin' });
    const data = await readApiResponse(response);
    if (!response.ok) { showDiagnostics(data.diagnostics); throw new Error(data.error || `Ошибка Jira (${response.status})`); }
    state.issues = Array.isArray(data.issues) ? data.issues : [];
    showDiagnostics(null); updateSummary(data.totals || {}); render();
  } catch (error) {
    document.querySelectorAll('.cards').forEach(cards => { cards.innerHTML = `<div class="error-card"><strong>Не удалось загрузить задачи</strong><br>${escapeHtml(error.message)}</div>`; });
    document.querySelector('#integrationNotice').hidden = false;
  }
}

function showToast(title, detail, error = false) {
  toast.querySelector('strong').textContent = title; toast.querySelector('small').textContent = detail;
  toast.classList.toggle('error', error); toast.classList.add('show');
  setTimeout(() => toast.classList.remove('show'), 4000);
}
function openModal() { modal.hidden = false; form.elements.title.focus(); }
function closeModal() { modal.hidden = true; form.querySelector('.form-error').hidden = true; }

function formatEntryDate(value) {
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}
function renderHistory(name, title, entries) {
  const block = metadataForm.querySelector(`[data-history="${name}"]`);
  block.innerHTML = entries?.length
    ? `<strong>${title}</strong><ol>${entries.map(entry => `<li><time>${escapeHtml(formatEntryDate(entry.createdAt))}</time>${escapeHtml(entry.text)}</li>`).join('')}</ol>`
    : `<strong>${title}</strong><p>Записей пока нет.</p>`;
}
function openMetadata(issue, bucket = targetColumn(issue)) {
  if (!issue) return;
  metadataForm.reset();
  metadataForm.elements.issueKey.value = issue.key;
  metadataForm.elements.bucket.value = bucket;
  metadataForm.elements.shortDescription.value = issue.metadata?.shortDescription || '';
  document.querySelector('#metadataSubtitle').textContent = `${issue.key} · ${issue.summary}`;
  renderHistory('plannedActions', 'Планируемые действия', issue.metadata?.plannedActions);
  renderHistory('facts', 'Факты', issue.metadata?.facts);
  metadataModal.hidden = false;
  metadataForm.elements.shortDescription.focus();
}
function closeMetadata() { metadataModal.hidden = true; metadataForm.querySelector('.form-error').hidden = true; }

async function saveMetadata(values) {
  const response = await fetch(apiUrl, { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(values) });
  const data = await readApiResponse(response);
  if (!response.ok) throw new Error(data.error || `Ошибка сохранения (${response.status})`);
  const issue = findIssue(values.issueKey);
  if (issue) issue.metadata = data.metadata;
  updateSummary(); render();
}

document.querySelector('#createBtn').addEventListener('click', openModal);
document.querySelectorAll('.add-card').forEach(button => button.addEventListener('click', openModal));
modal.querySelector('.modal-close').addEventListener('click', closeModal);
modal.querySelector('.cancel').addEventListener('click', closeModal);
modal.addEventListener('click', event => { if (event.target === modal) closeModal(); });
metadataModal.querySelector('.modal-close').addEventListener('click', closeMetadata);
metadataModal.querySelector('.cancel').addEventListener('click', closeMetadata);
metadataModal.addEventListener('click', event => { if (event.target === metadataModal) closeMetadata(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeModal(); closeMetadata(); } });

document.querySelectorAll('.column').forEach(column => {
  column.addEventListener('dragover', event => { event.preventDefault(); column.classList.add('drag-over'); });
  column.addEventListener('dragleave', event => { if (!column.contains(event.relatedTarget)) column.classList.remove('drag-over'); });
  column.addEventListener('drop', async event => {
    event.preventDefault(); column.classList.remove('drag-over');
    const issue = findIssue(state.draggedKey);
    const bucket = column.dataset.column;
    if (!issue || targetColumn(issue) === bucket) return;
    if (targetColumn(issue) === 'backlog' && bucket !== 'backlog') { openMetadata(issue, bucket); return; }
    try {
      await saveMetadata({ issueKey: issue.key, bucket, sessid: metadataForm.elements.sessid.value });
      showToast('Категория изменена', `${issue.key} → ${bucketLabels[bucket]}`);
    } catch (error) { showToast('Не удалось переместить', error.message, true); }
  });
});

form.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = form.querySelector('[type="submit"]'); const errorBox = form.querySelector('.form-error');
  submit.disabled = true; submit.textContent = 'Создаём…'; errorBox.hidden = true;
  try {
    const response = await fetch(apiUrl, { method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(Object.fromEntries(new FormData(form))) });
    const data = await readApiResponse(response);
    if (!response.ok) { showDiagnostics(data.diagnostics); throw new Error(data.error || `Ошибка Jira (${response.status})`); }
    closeModal(); form.reset(); showToast(`Заявка ${data.issue.key} создана`, 'Добавлена в бэклог'); await loadIssues();
  } catch (error) { errorBox.textContent = error.message; errorBox.hidden = false; }
  finally { submit.disabled = false; submit.textContent = 'Создать заявку'; }
});

metadataForm.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = metadataForm.querySelector('[type="submit"]'); const errorBox = metadataForm.querySelector('.form-error');
  submit.disabled = true; submit.textContent = 'Сохраняем…'; errorBox.hidden = true;
  try {
    const values = Object.fromEntries(new FormData(metadataForm));
    await saveMetadata(values); closeMetadata(); showToast('План сохранён', `${values.issueKey} · ${bucketLabels[values.bucket]}`);
  } catch (error) { errorBox.textContent = error.message; errorBox.hidden = false; }
  finally { submit.disabled = false; submit.textContent = 'Сохранить'; }
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
  const rows = [['Ключ', 'Проект', 'Название', 'Категория', 'Краткое описание', 'Статус Jira'], ...visibleIssues().map(i => [i.key, i.project.toUpperCase(), i.summary, bucketLabels[targetColumn(i)], i.metadata?.shortDescription || '', i.status])];
  const csv = rows.map(row => row.map(value => `"${String(value).replaceAll('"', '""')}"`).join(';')).join('\r\n');
  const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
  link.download = `jira-report-${new Date().toISOString().slice(0, 10)}.csv`; link.click(); URL.revokeObjectURL(link.href);
  showToast('Отчёт сформирован', 'Файл CSV скачан');
});

loadIssues();
