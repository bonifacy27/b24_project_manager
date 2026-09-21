const modal = document.querySelector('#modal');
const toast = document.querySelector('#toast');
const cards = [...document.querySelectorAll('.task-card')];

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
  cards.forEach(card => card.classList.toggle('hidden', project !== 'all' && card.dataset.project !== project));
}));

document.querySelector('#searchInput').addEventListener('input', event => {
  const query = event.target.value.trim().toLowerCase();
  cards.forEach(card => card.classList.toggle('hidden', !card.textContent.toLowerCase().includes(query)));
});

document.querySelectorAll('.active-filters span button').forEach(button => button.addEventListener('click', () => button.parentElement.remove()));
document.querySelector('.clear-filters').addEventListener('click', () => document.querySelectorAll('.active-filters span').forEach(item => item.remove()));
document.querySelector('#reportBtn').addEventListener('click', () => {
  toast.querySelector('strong').textContent = 'Отчёт сформирован';
  toast.querySelector('small').textContent = 'Файл готов к скачиванию';
  toast.classList.add('show'); setTimeout(() => toast.classList.remove('show'), 3200);
});
