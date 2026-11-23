const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');
const { test } = require('node:test');

const source = fs.readFileSync(path.join(__dirname, '../app.js'), 'utf8');
const context = vm.createContext({
  state: { issues: [], reportCompletionIssues: [], project: 'all', statuses: new Set(), tags: new Set() },
  bucketLabels: { week: 'Эта неделя', next: 'Следующая неделя', backlog: 'Бэклог', completed: 'Выполнено', deferred: 'Позже' }
});
for (const name of [
  'isoWeekValue', 'defaultReportWeeks', 'weekDateRange', 'targetColumn', 'isJiraCompleted',
  'matchesStatus', 'matchesTags', 'weeklyReportScope', 'isReportIssueCompleted', 'reportIssues',
  'entriesForWeek', 'requiresReportDetails', 'missingReportData'
]) {
  const start = source.indexOf(`function ${name}(`);
  assert.notEqual(start, -1);
  const end = source.indexOf('\n}', start) + 2;
  vm.runInContext(source.slice(start, end), context);
}

const completedWeek = '2026-W46';
const plannedWeek = '2026-W47';
const entry = (week, text = 'Работа') => ({ week, text });
const issue = (key, bucket, metadata = {}, fields = {}) => ({
  key, project: 'ods', status: 'В работе', metadata: { bucket, complexity: 'low', ...metadata }, ...fields
});
const keys = issues => Array.from(issues, issue => issue.key);

test('results and plan use their own weeks, tasks can appear in both', () => {
  context.state.issues = [
    issue('done', 'completed', { completedWeek, plannedActions: [entry(plannedWeek)] }),
    issue('closed-in-jira', 'week', { completedWeek }, { status: 'Закрыт' }),
    issue('done-other-week', 'completed', { completedWeek: plannedWeek, facts: [entry(completedWeek)] }),
    issue('in-both', 'week', { facts: [entry(completedWeek)], plannedActions: [entry(plannedWeek)] }),
    issue('backlog-plan', 'backlog', { plannedActions: [entry(plannedWeek)] }),
    issue('deferred-fact', 'deferred', { facts: [entry(completedWeek)] }),
    issue('label-only', 'next', { plannedWeek }),
    issue('old-completion-label', 'week', { completedWeek }),
    issue('wrong-week', 'week', { facts: [entry(plannedWeek)], plannedActions: [entry(completedWeek)] })
  ];
  const result = context.reportIssues(completedWeek, plannedWeek);
  assert.deepEqual(keys(result.completed), ['done', 'closed-in-jira', 'in-both', 'deferred-fact']);
  assert.deepEqual(keys(result.planned), ['in-both', 'backlog-plan']);
});

test('asks for missing entries even if a task has yet to qualify for the report', () => {
  context.state.issues = [
    issue('needs-fact', 'week', { facts: [entry(plannedWeek), entry(completedWeek, '  ')] }),
    issue('needs-plan', 'next', { plannedWeek, plannedActions: [entry(completedWeek)] }),
    issue('ready', 'next', { plannedActions: [entry(plannedWeek)] }),
    issue('closed', 'next', {}, { status: 'Выполнено' }),
    issue('backlog', 'backlog')
  ];
  const missing = context.missingReportData(completedWeek, plannedWeek);
  assert.deepEqual(Array.from(missing, row => [row.issue.key, row.fact, row.plan]), [
    ['needs-fact', true, false], ['needs-plan', false, true]
  ]);
  context.state.issues[0].metadata.facts.push(entry(completedWeek));
  context.state.issues[1].metadata.plannedActions.push(entry(plannedWeek));
  const result = context.reportIssues(completedWeek, plannedWeek);
  assert.deepEqual(keys(result.completed), ['needs-fact']);
  assert.deepEqual(keys(result.planned), ['needs-plan', 'ready']);
});

test('report preserves scope and includes historical completions missing from the board', () => {
  context.state.project = 'ods';
  context.state.statuses = new Set(['В работе']);
  context.state.tags = new Set(['report']);
  const done = { completedWeek, tags: ['report'] };
  context.state.issues = [
    issue('included', 'completed', done),
    issue('project-only', 'completed', done, { projectOnly: true }),
    issue('other-project', 'completed', done, { project: 'ssis' }),
    issue('other-status', 'completed', done, { status: 'Закрыт' }),
    issue('other-tag', 'completed', { completedWeek })
  ];
  context.state.reportCompletionIssues = [
    issue('included', 'completed', done),
    issue('historical', 'completed', done),
    issue('reopened', 'week', done)
  ];
  const result = context.reportIssues(completedWeek, plannedWeek);
  assert.deepEqual(keys(result.completed), ['included', 'historical']);
});

test('Friday and Monday select the same weeks, including across the ISO year boundary', () => {
  for (const [date, completed, planned] of [
    ['2026-11-13T12:00:00', '2026-W46', '2026-W47'],
    ['2026-11-16T12:00:00', '2026-W46', '2026-W47'],
    ['2027-01-01T12:00:00', '2026-W53', '2027-W01'],
    ['2027-01-04T12:00:00', '2026-W53', '2027-W01']
  ]) {
    const weeks = context.defaultReportWeeks(new Date(date));
    assert.equal(weeks.completedWeek, completed);
    assert.equal(weeks.plannedWeek, planned);
  }
  assert.equal(context.weekDateRange(completedWeek), '09.11.26–13.11.26');
  assert.equal(context.weekDateRange(plannedWeek), '16.11.26–20.11.26');
});

test('Excel request preserves the fact and action for a low-complexity task', async () => {
  const start = source.indexOf('async function downloadReport(');
  const end = source.indexOf('\n}', start) + 2;
  vm.runInContext(source.slice(start, end), context);
  const errorBox = {};
  const reportForm = {
    elements: { sessid: { value: 'session' } },
    querySelector: () => ({ form: { querySelector: () => errorBox } })
  };
  let payload;
  Object.assign(context, {
    URL,
    apiUrl: 'https://example.invalid/',
    reportDataModal: { hidden: true },
    reportForm,
    document: { createElement: () => ({ click() {} }) },
    closeReport() {},
    showToast() {},
    fetch: async (url, options) => {
      payload = JSON.parse(options.body);
      return { ok: true, blob: async () => new Blob() };
    }
  });
  Object.assign(context.state, {
    project: 'all', statuses: new Set(), tags: new Set(), projects: [], reportCompletionIssues: [],
    issues: [issue('both', 'next', {
      facts: [entry(completedWeek, 'Готов анализ')],
      plannedActions: [entry(plannedWeek, 'Провести тестирование')]
    })]
  });

  await context.downloadReport(completedWeek, plannedWeek);
  assert.equal(errorBox.hidden, true);
  assert.deepEqual(payload.completed.map(row => [row.task, row.result]), [['both', 'Готов анализ']]);
  assert.deepEqual(payload.planned.map(row => [row.task, row.actions]), [['both', 'Провести тестирование']]);
});
