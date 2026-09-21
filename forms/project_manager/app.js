const modal = document.querySelector('#modal');
const toast = document.querySelector('#toast');
const cards = [...document.querySelectorAll('.task-card')];
let jiraCards = cards;

const escapeHtml = value => String(value ?? '').replace(/[&<>'"]/g, character => ({
  '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
})[character]);

function initials(name) {
  return String(name || '?').split(/\s+/).slice(0, 2).map(part => part[0]).join('').toUpperCase();
}

function targetColumn(issue) {
  if (!issue.dueDate) return 'later';
  const due = new Date(`${issue.dueDate}T23:59:59`);
  const today = new Date();
  const monday = new Date(today);
  monday.setHours(0, 0, 0, 0);
  monday.setDate(today.getDate() - ((today.getDay() + 6) % 7));
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
    <h3>${escapeHtml(issue.summary)}</h3>
    <p>${escapeHtml(issue.type || 'Заявка')} · ${escapeHtml(issue.priority)}</p>
    <div class="card-meta"><span class="assignee" title="${escapeHtml(issue.assignee?.name || 'Не назначен')}">${avatar}</span><span>◴ ${escapeHtml(due)}</span><span>▱ ${Number(issue.comments) || 0}</span></div>
  </article>`;
}

async function loadIssues() {
  const columns = [...document.querySelectorAll('.column')];
  columns.forEach(column => { column.querySelector('.cards').innerHTML = '<div class="loading-card"></div><div class="loading-card"></div>'; });

  try {
    const response = await fetch('api/issues.php', { headers: { Accept: 'application/json' } });
    const data = await response.json();
    if (!response.ok) throw new Error(data.error || `Ошибка Jira (${response.status})`);

    columns.forEach(column => { column.querySelector('.cards').innerHTML = ''; });
    data.issues.forEach(issue => {
      const target = document.querySelector(`[data-column="${targetColumn(issue)}"] .cards`);
      target.insertAdjacentHTML('beforeend', issueCard(issue));
    });
    columns.forEach(column => {
      const count = column.querySelectorAll('.task-card').length;
      column.querySelector('header b').textContent = count;
      if (!count) column.querySelector('.cards').innerHTML = '<div class="error-card">В этом периоде пока нет задач.</div>';
    });
    Object.entries(data.totals).forEach(([project, total]) => {
      const badge = document.querySelector(`[data-project="${project}"] b`);
      if (badge) badge.textContent = total;
    });
    const total = Object.values(data.totals).reduce((sum, value) => sum + Number(value), 0);
    document.querySelector('[data-project="all"] b').textContent = total;
    document.querySelector('#activeTotal').textContent = total;
    document.querySelector('#syncStatus').textContent = `Синхронизировано ${new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(new Date(data.syncedAt))}`;
    jiraCards = [...document.querySelectorAll('.task-card')];
    bindCardLinks();
  } catch (error) {
    columns.forEach(column => { column.querySelector('.cards').innerHTML = `<div class="error-card"><strong>Не удалось загрузить задачи</strong><br>${escapeHtml(error.message)}</div>`; });
    document.querySelector('#integrationNotice').hidden = false;
    document.querySelector('#syncStatus').textContent = 'Нет подключения';
  }
}

function bindCardLinks() {
  jiraCards.forEach(card => card.addEventListener('click', () => {
    if (card.dataset.url) window.open(card.dataset.url, '_blank', 'noopener');
  }));
}

function openModal() { modal.hidden = false; modal.querySelector('input').focus(); }
function closeModal() { modal.hidden = true; }

document.querySelector('#createBtn').addEventListener('click', openModal);
document.querySelectorAll('.add-card').forEach(button => button.addEventListener('click', openModal));
modal.querySelector('.modal-close').addEventListener('click', closeModal);
modal.querySelector('.cancel').addEventListener('click', closeModal);
modal.addEventListener('click', event => { if (event.target === modal) closeModal(); });
document.addEventListener('keydown', event => {
  if (event.key === 'Escape') closeModal();
  if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
    event.preventDefault(); document.querySelector('#searchInput').focus();
  }
});

document.querySelector('#issueForm').addEventListener('submit', event => {
  event.preventDefault(); closeModal(); event.target.reset();
  toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 3200);
});

document.querySelectorAll('.project-tabs button').forEach(tab => tab.addEventListener('click', () => {
  document.querySelectorAll('.project-tabs button').forEach(item => item.classList.remove('active'));
  tab.classList.add('active');
  const project = tab.dataset.project;
  jiraCards.forEach(card => card.classList.toggle('hidden', project !== 'all' && card.dataset.project !== project));
}));

document.querySelector('#searchInput').addEventListener('input', event => {
  const query = event.target.value.trim().toLowerCase();
  jiraCards.forEach(card => card.classList.toggle('hidden', !card.textContent.toLowerCase().includes(query)));
});

document.querySelectorAll('.active-filters span button').forEach(button => button.addEventListener('click', () => button.parentElement.remove()));
document.querySelector('.clear-filters').addEventListener('click', () => document.querySelectorAll('.active-filters span').forEach(item => item.remove()));
document.querySelector('#reportBtn').addEventListener('click', () => {
  toast.querySelector('strong').textContent = 'Отчёт сформирован';
  toast.querySelector('small').textContent = 'Файл готов к скачиванию';
  toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 3200);
});

loadIssues();
