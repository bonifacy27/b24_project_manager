const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../app.js'), 'utf8');
const context = vm.createContext({
  state: { issues: [], project: 'all', statuses: new Set(), tags: new Set() },
  bucketLabels: { week: 'Эта неделя', next: 'Следующая неделя', backlog: 'Бэклог', completed: 'Выполнено', deferred: 'Позже' }
});
for (const name of ['isoWeekValue', 'targetColumn', 'matchesStatus', 'matchesTags', 'reportIssues', 'entriesForWeek']) {
  const start = source.indexOf(`function ${name}(`);
  assert.notEqual(start, -1);
  const end = source.indexOf('\n}', start) + 2;
  vm.runInContext(source.slice(start, end), context);
}

test('plan includes matching plannedWeek or action week only in week and next columns', () => {
  const issues = [];
  const expected = [];
  for (const bucket of ['week', 'next', 'backlog', 'completed', 'deferred']) {
    for (const match of ['label', 'entry', 'both', 'neither']) {
      const key = `${bucket}-${match}`;
      issues.push({ key, project: 'ods', metadata: {
        bucket,
        plannedWeek: ['label', 'both'].includes(match) ? '2026-W45' : '2026-W44',
        plannedActions: [{ week: ['entry', 'both'].includes(match) ? '2026-W45' : '2026-W44', text: 'Работа' }]
      } });
      if (['week', 'next'].includes(bucket) && match !== 'neither') expected.push(key);
    }
  }
  context.state.issues = issues;
  const result = context.reportIssues('2026-W44', '2026-W45');
  assert.deepEqual(Array.from(result.planned, issue => issue.key), expected);
});

test('report scope filters and completed week still apply', () => {
  const base = { project: 'ods', status: 'В работе', metadata: {
    bucket: 'next', plannedWeek: '2026-W45', completedWeek: '2026-W44', tags: ['report']
  } };
  context.state.project = 'ods';
  context.state.statuses = new Set(['В работе']);
  context.state.tags = new Set(['report']);
  context.state.issues = [
    { ...base, key: 'included' },
    { ...base, key: 'project-only', projectOnly: true },
    { ...base, key: 'other-project', project: 'ssis' },
    { ...base, key: 'other-status', status: 'Ожидает' },
    { ...base, key: 'other-tag', metadata: { ...base.metadata, tags: [] } }
  ];
  const result = context.reportIssues('2026-W44', '2026-W45');
  assert.deepEqual(Array.from(result.planned, issue => issue.key), ['included']);
  assert.deepEqual(Array.from(result.completed, issue => issue.key), ['included']);
});

test('Monday report uses current ISO week for plan, including year boundary', () => {
  for (const [date, planned, completed] of [
    ['2026-11-02T12:00:00', '2026-W45', '2026-W44'],
    ['2027-01-04T12:00:00', '2027-W01', '2026-W53']
  ]) {
    const current = new Date(date);
    const previous = new Date(current);
    previous.setDate(previous.getDate() - 7);
    assert.equal(context.isoWeekValue(current), planned);
    assert.equal(context.isoWeekValue(previous), completed);
  }
});
