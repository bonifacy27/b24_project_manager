const modal = document.querySelector('#modal');
const metadataModal = document.querySelector('#metadataModal');
const reportModal = document.querySelector('#reportModal');
const projectModal = document.querySelector('#projectModal');
const toast = document.querySelector('#toast');
const board = document.querySelector('#kanban');
const form = document.querySelector('#issueForm');
const metadataForm = document.querySelector('#metadataForm');
const reportForm = document.querySelector('#reportForm');
const projectForm = document.querySelector('#projectForm');
const state = { issues: [], projects: [], projectEditingIssues: new Set(), project: 'all', statuses: new Set(), tags: new Set(), tagCatalog: [], creationOptions: {}, query: '', view: 'board', draggedKey: null };
const apiUrl = new URL(window.location.href);
apiUrl.search = '';
apiUrl.hash = '';
apiUrl.searchParams.set('project_manager_api', 'issues');

const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
})[character]);
const bucketLabels = Object.fromEntries(
  [...document.querySelectorAll('.column[data-column]')].map(column => [column.dataset.column, column.dataset.label])
);
const complexityLabels = { low: 'Низкий', medium: 'Средний', high: 'Высокий' };

function truncate(value, length) {
  const characters = Array.from(String(value || ''));
  return characters.length > length ? `${characters.slice(0, length).join('')}…` : characters.join('');
}

function formatIssueDate(value) {
  if (!value) return '—';
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short' }).format(new Date(value));
}

function isoWeekValue(date = new Date()) {
  const target = new Date(Date.UTC(date.getFullYear(), date.getMonth(), date.getDate()));
  target.setUTCDate(target.getUTCDate() + 4 - (target.getUTCDay() || 7));
  const yearStart = new Date(Date.UTC(target.getUTCFullYear(), 0, 1));
  const week = Math.ceil((((target - yearStart) / 86400000) + 1) / 7);
  return `${target.getUTCFullYear()}-W${String(week).padStart(2, '0')}`;
}

function weekForBucket(bucket) {
  const date = new Date();
  if (bucket === 'next') date.setDate(date.getDate() + 7);
  return isoWeekValue(date);
}

function weekDateRange(value) {
  const match = /^(\d{4})-W(\d{2})$/.exec(value);
  if (!match) return value;
  const januaryFourth = new Date(Date.UTC(Number(match[1]), 0, 4));
  const monday = new Date(januaryFourth);
  monday.setUTCDate(januaryFourth.getUTCDate() - (januaryFourth.getUTCDay() || 7) + 1 + (Number(match[2]) - 1) * 7);
  const friday = new Date(monday); friday.setUTCDate(monday.getUTCDate() + 4);
  const format = date => new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit', year: '2-digit', timeZone: 'UTC' }).format(date);
  return `${format(monday)}–${format(friday)}`;
}

function initials(name) {
  return String(name || '?').trim().split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase();
}

function targetColumn(issue) {
  return bucketLabels[issue.metadata?.bucket] && !['year', 'next_year'].includes(issue.metadata.bucket)
    ? issue.metadata.bucket
    : 'backlog';
}

function matchesStatus(issue) {
  return state.statuses.size === 0 || state.statuses.has(issue.status);
}

function renderStatusFilter() {
  const filter = document.querySelector('#statusFilter');
  const statuses = [...new Set(state.issues.map(issue => issue.status).filter(Boolean))]
    .sort((left, right) => left.localeCompare(right, 'ru'));
  state.statuses = new Set([...state.statuses].filter(status => statuses.includes(status)));
  filter.querySelector('.status-options').innerHTML = statuses.length
    ? statuses.map(status => `<label><input type="checkbox" value="${escapeHtml(status)}"${state.statuses.has(status) ? ' checked' : ''}><span>${escapeHtml(status)}</span></label>`).join('')
    : '<p>Статусы не найдены</p>';
  filter.querySelector('summary').textContent = state.statuses.size
    ? `Статусы Jira: ${state.statuses.size}`
    : 'Все статусы Jira';
}

function matchesTags(issue) {
  return state.tags.size === 0 || [...state.tags].every(tag => issue.metadata?.tags?.includes(tag));
}

function renderTagFilter() {
  const filter = document.querySelector('#tagFilter');
  state.tags = new Set([...state.tags].filter(tag => state.tagCatalog.includes(tag)));
  filter.querySelector('.tag-filter-options').innerHTML = state.tagCatalog.length
    ? state.tagCatalog.map(tag => `<label><input type="checkbox" value="${escapeHtml(tag)}"${state.tags.has(tag) ? ' checked' : ''}><span>${escapeHtml(tag)}</span></label>`).join('')
    : '<p>Теги ещё не добавлены</p>';
  filter.querySelector('summary').textContent = state.tags.size ? `Теги: ${state.tags.size}` : 'Все теги';
}

function renderTagPicker(selectedTags = []) {
  const selected = new Set(selectedTags);
  metadataForm.querySelector('.tag-picker-options').innerHTML = state.tagCatalog.length
    ? state.tagCatalog.map(tag => `<label><input type="checkbox" value="${escapeHtml(tag)}"${selected.has(tag) ? ' checked' : ''}><span>${escapeHtml(tag)}</span></label>`).join('')
    : '<p>Добавьте первый тег.</p>';
}

function canCompleteStatus(status) {
  return ['уточнение информации', 'взят в работу'].includes(String(status || '').trim().toLowerCase());
}

function renderLinkedIssues(issue, allowCompletion) {
  const block = metadataForm.querySelector('.linked-issues-block');
  const links = issue.linkedIssues || [];
  block.hidden = links.length === 0;
  block.querySelector('.linked-issues-list').innerHTML = links.map(link => {
    const canCompleteLinked = allowCompletion && canCompleteStatus(link.status);
    return `<div class="linked-issue-row"><div><small>${escapeHtml(link.relationship || 'Связана')}</small><a class="issue-link" href="${escapeHtml(link.url)}" target="_blank" rel="noopener">${escapeHtml(link.key)}</a><span>${escapeHtml(link.summary || 'Без названия')}</span><em>${escapeHtml(link.status || 'Без статуса')}</em></div>${canCompleteLinked ? `<label><input type="checkbox" name="linkedIssueKeys" value="${escapeHtml(link.key)}"> Также выполнить</label>` : ''}</div>`;
  }).join('');
}

function renderIssueProjects(issueKey) {
  const block = metadataForm.querySelector('.issue-project-options');
  const attached = state.projects.filter(project => project.issueKeys.includes(issueKey));
  const available = state.projects.filter(project => !project.issueKeys.includes(issueKey));
  const option = (project, checked = false) => `<label><input type="checkbox" value="${project.id}"${checked ? ' checked' : ''}><span>${escapeHtml(project.name)}</span><em>${project.year}</em></label>`;
  block.innerHTML = state.projects.length
    ? `${attached.map(project => option(project, true)).join('')}${available.length ? `<details><summary>Привязать ещё проект</summary><div>${available.map(project => option(project)).join('')}</div></details>` : ''}`
    : '<p>Проекты ещё не созданы.</p>';
}

function renderCreateFields() {
  const project = form.elements.project.value;
  const options = state.creationOptions[project];
  const fields = form.querySelector('.create-jira-fields');
  const component = form.elements.componentId;
  const version = form.elements.fixVersionId;
  fields.hidden = !options;
  component.required = Boolean(options?.requiredFields?.includes('component'));
  version.required = Boolean(options?.requiredFields?.includes('fixVersion'));
  component.innerHTML = '<option value="">Выберите компонент</option>' + (options?.components || [])
    .map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
  version.innerHTML = '<option value="">Выберите версию</option>' + (options?.versions || [])
    .map(item => `<option value="${escapeHtml(item.id)}">${escapeHtml(item.name)}</option>`).join('');
}

function issueCard(issue) {
  const project = issue.project === 'ssis' ? 'ssis' : 'ods';
  const avatar = issue.assignee?.avatar
    ? `<img src="${escapeHtml(issue.assignee.avatar)}" alt="">`
    : escapeHtml(initials(issue.assignee?.name));
  const jiraDescription = issue.description
    ? `<p class="jira-description-preview" title="${escapeHtml(issue.description)}">${escapeHtml(truncate(issue.description, 100))}</p>`
    : '<p class="jira-description-preview muted">Описание в Jira не добавлено</p>';
  const complexity = complexityLabels[issue.metadata?.complexity] ? issue.metadata.complexity : 'medium';
  const plannedWeek = issue.metadata?.plannedWeek;
  const completedWeek = issue.metadata?.completedWeek;
  const tags = issue.metadata?.tags || [];
  const linkedIssues = issue.linkedIssues || [];
  const projects = state.projects.filter(item => item.issueKeys.includes(issue.key));
  return `<article class="task-card" data-project="${project}" tabindex="0" draggable="true" data-key="${escapeHtml(issue.key)}">
    <div class="card-top"><a class="key issue-link ${project}-key" href="${escapeHtml(issue.url)}" target="_blank" rel="noopener" title="Открыть в Jira">${escapeHtml(issue.key)}</a><span class="complexity ${complexity}">${complexityLabels[complexity]}</span><span class="status-pill">${escapeHtml(issue.status)}</span></div>
    <h3>${escapeHtml(issue.summary)}</h3><div class="issue-created">Создано: ${escapeHtml(formatIssueDate(issue.created))}</div>${jiraDescription}${projects.length ? `<div class="card-projects">Проекты: ${projects.map(item => `<span>${escapeHtml(item.name)}</span>`).join('')}</div>` : ''}${linkedIssues.length ? `<div class="card-links">Связано: ${linkedIssues.map(link => `<a class="issue-link" href="${escapeHtml(link.url)}" target="_blank" rel="noopener">${escapeHtml(link.key)}</a>`).join(', ')}</div>` : ''}${tags.length ? `<div class="issue-tags">${tags.map(tag => `<span>${escapeHtml(tag)}</span>`).join('')}</div>` : ''}
    <div class="note-counts"><span>План: ${issue.metadata?.plannedActions?.length || 0}</span><span>Факт: ${issue.metadata?.facts?.length || 0}</span>${plannedWeek ? `<span>Неделя плана: ${escapeHtml(plannedWeek)}</span>` : ''}${completedWeek ? `<span>Выполнено: ${escapeHtml(completedWeek)}</span>` : ''}</div>
    <div class="card-meta"><span class="assignee" title="${escapeHtml(issue.assignee?.name || 'Не назначен')}">${avatar}</span><span>${escapeHtml(issue.type || 'Заявка')}</span><button class="edit-metadata" type="button" aria-label="Редактировать план">✎ План</button></div>
  </article>`;
}

function projectCard(project) {
  const issues = project.issueKeys.map(findIssue).filter(Boolean);
  const issueLink = issue => `<a class="issue-link" href="${escapeHtml(issue.url)}" target="_blank" rel="noopener"><b>${escapeHtml(issue.key)}</b><span>${escapeHtml(issue.summary)}</span><em>${escapeHtml(issue.status)}</em></a>`;
  const visibleIssues = issues.slice(0, 5);
  const remainingIssues = issues.slice(5);
  return `<article class="task-card project-card" tabindex="0" data-project-id="${project.id}">
    <div class="card-top"><span class="key">ПРОЕКТ</span><span class="status-pill">${issues.length} заявок</span></div>
    <h3>${escapeHtml(project.name)}</h3>${project.description ? `<p class="project-description">${escapeHtml(truncate(project.description, 140))}</p>` : ''}
    <div class="project-card-issues">${issues.length ? visibleIssues.map(issueLink).join('') + (remainingIssues.length ? `<details><summary>Ещё ${remainingIssues.length}</summary>${remainingIssues.map(issueLink).join('')}</details>` : '') : '<p>Заявки пока не привязаны</p>'}</div>
    <button class="edit-project" type="button">✎ Проект</button>
  </article>`;
}

function visibleIssues() {
  return state.issues.filter(issue => {
    if (issue.projectOnly) return false;
    const matchesProject = state.project === 'all' || issue.project === state.project;
    const haystack = `${issue.key} ${issue.summary} ${issue.description || ''} ${issue.status} ${issue.assignee?.name || ''} ${issue.metadata?.shortDescription || ''} ${(issue.metadata?.tags || []).join(' ')} ${complexityLabels[issue.metadata?.complexity] || ''}`.toLowerCase();
    return matchesProject && matchesStatus(issue) && matchesTags(issue) && haystack.includes(state.query);
  });
}

function render() {
  const issues = visibleIssues();
  document.querySelectorAll('.column').forEach(column => {
    if (['year', 'next_year'].includes(column.dataset.column)) {
      const year = Number(column.dataset.label);
      const projects = state.projects.filter(project => project.year === year);
      column.querySelector('.cards').innerHTML = projects.length
        ? projects.map(projectCard).join('')
        : '<div class="empty-card">На этот год проектов пока нет.</div>';
      column.querySelector('header b').textContent = projects.length;
      return;
    }
    const columnIssues = issues.filter(issue => targetColumn(issue) === column.dataset.column);
    column.querySelector('.cards').innerHTML = columnIssues.length
      ? columnIssues.map(issueCard).join('')
      : '<div class="empty-card">В этой категории пока нет заявок.</div>';
    column.querySelector('header b').textContent = columnIssues.length;
  });
  document.querySelectorAll('.task-card').forEach(card => {
    if (card.classList.contains('project-card')) {
      card.addEventListener('click', event => {
        if (event.target.closest('.issue-link') || event.target.closest('details')) return;
        openProjectModal(state.projects.find(project => project.id === Number(card.dataset.projectId)));
      });
      card.addEventListener('keydown', event => { if (event.key === 'Enter') openProjectModal(state.projects.find(project => project.id === Number(card.dataset.projectId))); });
      return;
    }
    const issue = findIssue(card.dataset.key);
    card.addEventListener('click', event => {
      if (event.target.closest('.issue-link')) return;
      openMetadata(issue);
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
    state.projects = Array.isArray(data.localProjects) ? data.localProjects : [];
    state.tagCatalog = [...new Set([...(data.tags || []), ...state.issues.flatMap(issue => issue.metadata?.tags || [])])]
      .sort((left, right) => left.localeCompare(right, 'ru'));
    state.creationOptions = data.creationOptions || {};
    renderCreateFields();
    renderStatusFilter(); renderTagFilter(); showDiagnostics(null); updateSummary(data.totals || {}); render();
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
function openModal() { renderCreateFields(); modal.hidden = false; form.elements.title.focus(); }
function closeModal() { modal.hidden = true; form.querySelector('.form-error').hidden = true; }

function renderProjectIssues(query = '') {
  const normalisedQuery = query.trim().toLowerCase();
  const option = (issue, checked = false) => `<label><input type="checkbox" value="${escapeHtml(issue.key)}"${checked ? ' checked' : ''}><span><b>${escapeHtml(issue.key)}</b> ${escapeHtml(issue.summary)}</span><em>${escapeHtml(issue.status)}</em></label>`;
  const attached = state.issues.filter(issue => state.projectEditingIssues.has(issue.key));
  const available = state.issues.filter(issue => !state.projectEditingIssues.has(issue.key)
    && (!normalisedQuery || `${issue.key} ${issue.summary}`.toLowerCase().includes(normalisedQuery)));
  projectForm.querySelector('.project-issue-options').innerHTML = `${attached.length ? `<div class="attached-project-issues">${attached.map(issue => option(issue, true)).join('')}</div>` : '<p>Привязанных заявок пока нет.</p>'}${available.length ? `<details${normalisedQuery ? ' open' : ''}><summary>Привязать ещё заявки (${available.length})</summary><div>${available.map(issue => option(issue)).join('')}</div></details>` : ''}`;
}

function openProjectModal(project = null, year = new Date().getFullYear()) {
  projectForm.reset();
  projectForm.elements.projectId.value = project?.id || '';
  projectForm.elements.name.value = project?.name || '';
  projectForm.elements.description.value = project?.description || '';
  projectForm.elements.year.value = String(project?.year || year);
  state.projectEditingIssues = new Set(project?.issueKeys || []);
  document.querySelector('#projectModalTitle').textContent = project ? 'Проект' : 'Новый проект';
  renderProjectIssues();
  projectForm.querySelector('.project-issue-search input').value = '';
  projectModal.hidden = false;
  projectForm.elements.name.focus();
}
function closeProjectModal() { projectModal.hidden = true; projectForm.querySelector('.form-error').hidden = true; }

function formatEntryDate(value) {
  return new Intl.DateTimeFormat('ru-RU', { dateStyle: 'short', timeStyle: 'short' }).format(new Date(value));
}
function renderHistory(name, title, entries) {
  const block = metadataForm.querySelector(`[data-history="${name}"]`);
  block.innerHTML = entries?.length
    ? `<strong>${title}</strong><ol>${entries.map(entry => `<li><time>${escapeHtml(formatEntryDate(entry.createdAt))}</time><textarea maxlength="10000" data-entry-date="${escapeHtml(entry.createdAt)}">${escapeHtml(entry.text)}</textarea></li>`).join('')}</ol>`
    : `<strong>${title}</strong><p>Записей пока нет.</p>`;
}
function openMetadata(issue, bucket = targetColumn(issue)) {
  if (!issue) return;
  metadataForm.reset();
  metadataForm.elements.issueKey.value = issue.key;
  document.querySelector('#metadataJiraStatus').textContent = issue.status || 'Без статуса';
  document.querySelector('#metadataJiraDetails').innerHTML = `<span>Инициатор: <strong>${escapeHtml(issue.reporter?.name || 'Не указан')}</strong></span><span>Создано: <strong>${escapeHtml(formatIssueDate(issue.created))}</strong></span><span>Обновлено: <strong>${escapeHtml(formatIssueDate(issue.updated))}</strong></span>`;
  const canCompleteInJira = canCompleteStatus(issue.status);
  metadataForm.querySelector('.complete-jira').hidden = !canCompleteInJira;
  metadataForm.querySelector('.complete-comment').hidden = !canCompleteInJira;
  metadataForm.elements.completeComment.value = '';
  metadataForm.elements.bucket.value = bucket;
  metadataForm.elements.complexity.value = issue.metadata?.complexity || 'medium';
  metadataForm.elements.plannedWeek.value = issue.metadata?.plannedWeek
    || (['week', 'next'].includes(bucket) ? weekForBucket(bucket) : '');
  const completedWeekField = metadataForm.querySelector('.completed-week-field');
  completedWeekField.hidden = !issue.metadata?.completedWeek;
  metadataForm.elements.completedWeekDisplay.value = issue.metadata?.completedWeek || '';
  renderLinkedIssues(issue, canCompleteInJira);
  renderIssueProjects(issue.key);
  renderTagPicker(issue.metadata?.tags || []);
  metadataForm.elements.newTag.value = '';
  metadataForm.elements.jiraDescription.value = issue.description || 'Описание в Jira не добавлено';
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
  if (Array.isArray(data.localProjects)) state.projects = data.localProjects;
  updateSummary(); render();
}

document.querySelector('#createBtn').addEventListener('click', openModal);
document.querySelectorAll('.add-card').forEach(button => button.addEventListener('click', openModal));
document.querySelectorAll('.add-project').forEach(button => button.addEventListener('click', () => {
  openProjectModal(null, Number(button.closest('.column').dataset.label));
}));
modal.querySelector('.modal-close').addEventListener('click', closeModal);
modal.querySelector('.cancel').addEventListener('click', closeModal);
modal.addEventListener('click', event => { if (event.target === modal) closeModal(); });
form.elements.project.addEventListener('change', renderCreateFields);
metadataModal.querySelector('.modal-close').addEventListener('click', closeMetadata);
metadataModal.querySelector('.cancel').addEventListener('click', closeMetadata);
metadataModal.addEventListener('click', event => { if (event.target === metadataModal) closeMetadata(); });
projectModal.querySelector('.modal-close').addEventListener('click', closeProjectModal);
projectModal.querySelector('.cancel').addEventListener('click', closeProjectModal);
projectModal.addEventListener('click', event => { if (event.target === projectModal) closeProjectModal(); });
projectForm.querySelector('.project-issue-search input').addEventListener('input', event => renderProjectIssues(event.target.value));
projectForm.querySelector('.project-issue-options').addEventListener('change', event => {
  if (!event.target.matches('input[type="checkbox"]')) return;
  if (event.target.checked) state.projectEditingIssues.add(event.target.value);
  else state.projectEditingIssues.delete(event.target.value);
  renderProjectIssues(projectForm.querySelector('.project-issue-search input').value);
});
function closeReport() { reportModal.hidden = true; }
reportModal.querySelector('.modal-close').addEventListener('click', closeReport);
reportModal.querySelector('.cancel').addEventListener('click', closeReport);
reportModal.addEventListener('click', event => { if (event.target === reportModal) closeReport(); });
document.addEventListener('keydown', event => { if (event.key === 'Escape') { closeModal(); closeMetadata(); closeProjectModal(); closeReport(); } });
metadataForm.elements.bucket.addEventListener('change', event => {
  if (['week', 'next'].includes(event.target.value)) {
    metadataForm.elements.plannedWeek.value = weekForBucket(event.target.value);
  }
});
metadataForm.querySelector('.add-tag button').addEventListener('click', () => {
  const input = metadataForm.elements.newTag;
  const tag = input.value.trim();
  if (!tag) return;
  let canonicalTag = state.tagCatalog.find(item => item.toLowerCase() === tag.toLowerCase());
  if (!canonicalTag) {
    state.tagCatalog.push(tag);
    state.tagCatalog.sort((left, right) => left.localeCompare(right, 'ru'));
    canonicalTag = tag;
  }
  renderTagPicker([...metadataForm.querySelectorAll('.tag-picker-options input:checked')].map(field => field.value).concat(canonicalTag));
  input.value = '';
});
metadataForm.querySelector('.complete-jira').addEventListener('click', async event => {
  const issue = findIssue(metadataForm.elements.issueKey.value);
  const comment = metadataForm.elements.completeComment.value.trim();
  const linkedIssueKeys = [...metadataForm.querySelectorAll('[name="linkedIssueKeys"]:checked')]
    .map(field => field.value);
  const errorBox = metadataForm.querySelector('.form-error');
  if (!comment) {
    errorBox.textContent = 'Укажите комментарий для выполнения заявки в Jira.';
    errorBox.hidden = false;
    metadataForm.elements.completeComment.focus();
    return;
  }
  const linkedPrompt = linkedIssueKeys.length ? ` и связанные заявки ${linkedIssueKeys.join(', ')}` : '';
  if (!issue || !window.confirm(`Перевести ${issue.key}${linkedPrompt} в выполненный статус Jira?`)) return;
  const button = event.currentTarget;
  button.disabled = true; button.textContent = 'Выполняем…'; errorBox.hidden = true;
  try {
    const response = await fetch(apiUrl, {
      method: 'POST', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify({ action: 'complete', issueKey: issue.key, linkedIssueKeys, comment, sessid: metadataForm.elements.sessid.value })
    });
    const data = await readApiResponse(response);
    if (!response.ok) throw new Error(data.error || `Ошибка Jira (${response.status})`);
    const linkedErrors = (data.linkedIssues || []).filter(item => item.error);
    closeMetadata(); await loadIssues();
    showToast(
      linkedErrors.length ? 'Основная заявка выполнена' : 'Заявка выполнена',
      linkedErrors.length ? `Не выполнены: ${linkedErrors.map(item => item.key).join(', ')}` : `${issue.key} · ${data.issue.status}`,
      linkedErrors.length > 0
    );
  } catch (error) {
    errorBox.textContent = error.message; errorBox.hidden = false;
  } finally {
    button.disabled = false; button.textContent = 'Выполнить в Jira';
  }
});

document.querySelectorAll('.column').forEach(column => {
  column.addEventListener('dragover', event => { if (!['year', 'next_year'].includes(column.dataset.column)) { event.preventDefault(); column.classList.add('drag-over'); } });
  column.addEventListener('dragleave', event => { if (!column.contains(event.relatedTarget)) column.classList.remove('drag-over'); });
  column.addEventListener('drop', async event => {
    event.preventDefault(); column.classList.remove('drag-over');
    const issue = findIssue(state.draggedKey);
    const bucket = column.dataset.column;
    if (['year', 'next_year'].includes(bucket)) return;
    if (!issue || targetColumn(issue) === bucket) return;
    if (targetColumn(issue) === 'backlog' && bucket !== 'backlog') { openMetadata(issue, bucket); return; }
    try {
      const values = { issueKey: issue.key, bucket, sessid: metadataForm.elements.sessid.value };
      if (['week', 'next'].includes(bucket)) values.plannedWeek = weekForBucket(bucket);
      await saveMetadata(values);
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

projectForm.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = projectForm.querySelector('[type="submit"]');
  const errorBox = projectForm.querySelector('.form-error');
  submit.disabled = true; submit.textContent = 'Сохраняем…'; errorBox.hidden = true;
  try {
    const values = Object.fromEntries(new FormData(projectForm));
    values.issueKeys = [...state.projectEditingIssues];
    const response = await fetch(apiUrl, { method: 'PATCH', credentials: 'same-origin', headers: { 'Content-Type': 'application/json', Accept: 'application/json' }, body: JSON.stringify(values) });
    const data = await readApiResponse(response);
    if (!response.ok) throw new Error(data.error || `Ошибка сохранения (${response.status})`);
    const index = state.projects.findIndex(project => project.id === data.project.id);
    if (index >= 0) state.projects[index] = data.project; else state.projects.push(data.project);
    closeProjectModal(); render(); showToast('Проект сохранён', data.project.name);
  } catch (error) { errorBox.textContent = error.message; errorBox.hidden = false; }
  finally { submit.disabled = false; submit.textContent = 'Сохранить проект'; }
});

metadataForm.addEventListener('submit', async event => {
  event.preventDefault();
  const submit = metadataForm.querySelector('[type="submit"]'); const errorBox = metadataForm.querySelector('.form-error');
  submit.disabled = true; submit.textContent = 'Сохраняем…'; errorBox.hidden = true;
  try {
    const values = Object.fromEntries(new FormData(metadataForm));
    values.tags = [...metadataForm.querySelectorAll('.tag-picker-options input:checked')].map(field => field.value);
    values.projectIds = [...metadataForm.querySelectorAll('.issue-project-options input:checked')].map(field => Number(field.value));
    ['plannedActions', 'facts'].forEach(name => {
      values[name] = [...metadataForm.querySelectorAll(`[data-history="${name}"] textarea`)].map(field => ({
        text: field.value,
        createdAt: field.dataset.entryDate
      }));
    });
    await saveMetadata(values); renderTagFilter(); closeMetadata(); showToast('План сохранён', `${values.issueKey} · ${bucketLabels[values.bucket]}`);
  } catch (error) { errorBox.textContent = error.message; errorBox.hidden = false; }
  finally { submit.disabled = false; submit.textContent = 'Сохранить'; }
});

document.querySelectorAll('.project-tabs button').forEach(tab => tab.addEventListener('click', () => {
  document.querySelectorAll('.project-tabs button').forEach(item => item.classList.remove('active'));
  tab.classList.add('active'); state.project = tab.dataset.project; render();
}));
document.querySelector('#searchInput').addEventListener('input', event => { state.query = event.target.value.trim().toLowerCase(); render(); });
document.querySelector('#statusFilter').addEventListener('change', event => {
  if (!event.target.matches('input[type="checkbox"]')) return;
  if (event.target.checked) state.statuses.add(event.target.value); else state.statuses.delete(event.target.value);
  renderStatusFilter(); render();
});
document.querySelector('#statusFilter .clear-statuses').addEventListener('click', () => {
  state.statuses.clear(); renderStatusFilter(); render();
});
document.querySelector('#tagFilter').addEventListener('change', event => {
  if (!event.target.matches('input[type="checkbox"]')) return;
  if (event.target.checked) state.tags.add(event.target.value); else state.tags.delete(event.target.value);
  renderTagFilter(); render();
});
document.querySelector('#tagFilter .clear-tags').addEventListener('click', () => {
  state.tags.clear(); renderTagFilter(); render();
});
document.querySelector('#refreshBtn').addEventListener('click', loadIssues);
document.querySelectorAll('.view-switch button').forEach(button => button.addEventListener('click', () => {
  document.querySelectorAll('.view-switch button').forEach(item => item.classList.remove('active'));
  button.classList.add('active'); state.view = button.dataset.view; board.classList.toggle('list-view', state.view === 'list');
}));
document.querySelector('#reportBtn').addEventListener('click', () => {
  const current = new Date();
  const previous = new Date(); previous.setDate(previous.getDate() - 7);
  reportForm.elements.completedWeek.value = isoWeekValue(previous);
  reportForm.elements.plannedWeek.value = isoWeekValue(current);
  reportModal.hidden = false;
  reportForm.elements.completedWeek.focus();
});
reportForm.addEventListener('submit', event => {
  event.preventDefault();
  const completedWeek = reportForm.elements.completedWeek.value;
  const plannedWeek = reportForm.elements.plannedWeek.value;
  const scopedIssues = state.issues.filter(issue =>
    (state.project === 'all' || issue.project === state.project) && matchesStatus(issue) && matchesTags(issue)
  );
  const completed = scopedIssues.filter(issue => issue.metadata?.completedWeek === completedWeek);
  const planned = scopedIssues.filter(issue => issue.metadata?.plannedWeek === plannedWeek && targetColumn(issue) !== 'completed');
  const issueRow = issue => [issue.key, issue.created?.slice(0, 10) || '', issue.project.toUpperCase(), issue.summary, (issue.metadata?.tags || []).join(', '), issue.metadata?.shortDescription || issue.description || '', (issue.metadata?.plannedActions || []).map(entry => entry.text).join('\n'), (issue.metadata?.facts || []).map(entry => entry.text).join('\n')];
  const headers = ['Задача', 'Дата создания', 'ИС', 'Название', 'Теги', 'Краткое описание', 'Планируемые действия', 'Итог'];
  const rows = [
    [`Итоги за неделю ${weekDateRange(completedWeek)}`], headers,
    ...completed.map(issueRow), [],
    [`План на неделю ${weekDateRange(plannedWeek)}`], headers,
    ...planned.map(issueRow)
  ];
  const csv = rows.map(row => row.map(value => `"${String(value).replaceAll('"', '""')}"`).join(';')).join('\r\n');
  const link = document.createElement('a'); link.href = URL.createObjectURL(new Blob([`\uFEFF${csv}`], { type: 'text/csv;charset=utf-8' }));
  link.download = `jira-report-${completedWeek}-${plannedWeek}.csv`; link.click(); URL.revokeObjectURL(link.href);
  closeReport(); showToast('Отчёт сформирован', `Итоги: ${completed.length}, план: ${planned.length}`);
});

loadIssues();
