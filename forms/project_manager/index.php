<?php

declare(strict_types=1);

// Serve the JSON API through the page entry point. Some Bitrix installations
// deny direct requests to PHP files in nested directories with HTTP 404.
if (($_GET['project_manager_api'] ?? '') === 'issues') {
    require __DIR__ . '/api/issues.php';
    exit;
}

$bitrixHeader = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/header.php';
$bitrixFooter = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/footer.php';
if (is_file($bitrixHeader)) {
    require $bitrixHeader;
}
if (isset($APPLICATION)) {
    $APPLICATION->SetTitle('Управление проектами');
}

$today = new DateTimeImmutable('today');
$weekWorkdays = static function (DateTimeImmutable $monday): string {
    $dates = [];
    for ($day = 0; $day < 7; $day++) {
        $date = $monday->modify('+' . $day . ' days');
        $formatted = $date->format('d.m.Y');
        $isWorkday = function_exists('isworkday_1c')
            ? (bool) isworkday_1c($formatted)
            : (int) $date->format('N') <= 5;
        if ($isWorkday) {
            $dates[] = $date->format('d.m');
        }
    }

    return implode(', ', $dates);
};
$currentWeekMonday = $today->modify('monday this week');
$currentWeekDates = $weekWorkdays($currentWeekMonday);
$nextWeekDates = $weekWorkdays($currentWeekMonday->modify('+7 days'));
$currentYear = $today->format('Y');
$nextYear = $today->modify('+1 year')->format('Y');
?>
<link rel="stylesheet" href="/forms/project_manager/styles.css?v=24">
<div class="project-manager">
<section class="content">
        <div class="page-heading">
          <div><div class="eyebrow">РАБОЧЕЕ ПРОСТРАНСТВО</div><h1>Проекты</h1><p>Планируйте работу и следите за заявками из Jira</p></div>
          <div class="heading-actions"><button class="btn secondary" id="reportBtn"><span>↗</span> Создать отчёт</button><button class="btn primary" id="createBtn"><span>＋</span> Новая заявка</button></div>
        </div>

        <div class="stats" aria-label="Сводка">
          <article class="summary-card active" data-summary-filter="all" tabindex="0"><div class="stat-icon blue">▤</div><div><span>Всего в работе</span><strong id="activeTotal">—</strong><small>ODS и SSIS</small></div></article>
          <article class="summary-card" data-summary-filter="week" tabindex="0"><div class="stat-icon violet">◷</div><div><span>На этой неделе</span><strong id="weekTotal">—</strong><small>По сроку выполнения</small></div></article>
          <article class="summary-card" data-summary-filter="waiting" tabindex="0"><div class="stat-icon orange">⚑</div><div><span>Ожидают ответа</span><strong id="waitingTotal">—</strong><small>Уточнение информации, waiting, pending, ожидает</small></div></article>
          <article class="summary-card" data-summary-filter="updated" tabindex="0"><div class="stat-icon green">✓</div><div><span>Обновлено сегодня</span><strong id="updatedTotal">—</strong><small>ODS и SSIS</small></div></article>
        </div>

        <div class="entity-tabs" role="tablist" aria-label="Разделы планирования">
          <button class="active" type="button" role="tab" aria-selected="true" aria-controls="issuesPanel" data-section="issues">Запросы</button>
          <button type="button" role="tab" aria-selected="false" aria-controls="projectsPanel" data-section="projects">Проекты</button>
        </div>

        <div class="entity-tabs" role="tablist" aria-label="Разделы планирования">
          <button class="active" type="button" role="tab" aria-selected="true" aria-controls="issuesPanel" data-section="issues">Запросы</button>
          <button type="button" role="tab" aria-selected="false" aria-controls="projectsPanel" data-section="projects">Проекты</button>
        </div>

        <div class="toolbar">
          <div class="project-tabs" role="tablist"><button class="active" data-project="all">Все проекты <b>—</b></button><button data-project="ods"><span class="project-dot ods"></span> ODS <b>—</b></button><button data-project="ssis"><span class="project-dot ssis"></span> SSIS <b>—</b></button></div>
          <div class="view-actions"><details class="tag-filter option-filter" id="tagFilter"><summary>Все теги</summary><div class="option-filter-menu"><div class="tag-filter-options option-list"></div><button type="button" class="clear-tags">Сбросить</button></div></details><details class="status-filter option-filter" id="statusFilter"><summary>Все статусы Jira</summary><div class="option-filter-menu"><div class="status-options option-list"></div><button type="button" class="clear-statuses">Сбросить</button></div></details><label class="issue-search" aria-label="Поиск заявок"><span>⌕</span><input id="searchInput" type="search" placeholder="Поиск"></label><button class="btn tertiary" id="refreshBtn" type="button">↻ <span>Обновить</span></button><div class="view-switch"><button class="active" type="button" data-view="board" aria-label="Канбан">▦</button><button type="button" data-view="list" aria-label="Список">☷</button></div></div>
        </div>

        <div class="integration-notice" id="integrationNotice" hidden><div><strong>Ошибка загрузки Jira</strong><span>Подробности показаны в колонках ниже.</span><details id="jiraDiagnostics" open hidden><summary>Диагностика интеграции</summary><pre></pre></details></div></div>
        <p class="board-hint">Перетаскивайте заявки между категориями. План и заметки хранятся только здесь и не изменяют Jira.</p>
        <div class="board-panel" id="issuesPanel" role="tabpanel">
        <div class="kanban issues-board" id="kanban">
          <section class="column" data-column="backlog" data-label="Бэклог"><header><div><span class="column-dot orange-dot"></span><span class="column-title"><h2>Бэклог</h2></span><b>0</b></div><button aria-label="Меню колонки">•••</button></header><div class="cards"></div><button class="add-card">＋ Добавить задачу</button></section>

          <section class="column" data-column="week" data-label="Эта неделя"><header><div><span class="column-dot blue-dot"></span><span class="column-title"><h2>Эта неделя</h2><small><?= htmlspecialchars($currentWeekDates, ENT_QUOTES) ?></small></span><b>0</b></div><button aria-label="Меню колонки">•••</button></header><div class="cards"></div><button class="add-card">＋ Добавить задачу</button></section>

          <section class="column" data-column="next" data-label="Следующая неделя"><header><div><span class="column-dot violet-dot"></span><span class="column-title"><h2>Следующая неделя</h2><small><?= htmlspecialchars($nextWeekDates, ENT_QUOTES) ?></small></span><b>0</b></div><button aria-label="Меню колонки">•••</button></header><div class="cards"></div><button class="add-card">＋ Добавить задачу</button></section>

          <section class="column" data-column="completed" data-label="Выполнено"><header><div><span class="column-dot completed-dot"></span><span class="column-title"><h2>Выполнено</h2></span><b>0</b></div><button aria-label="Меню колонки">•••</button></header><div class="cards"></div><button class="add-card">＋ Добавить задачу</button></section>

          <section class="column" data-column="deferred" data-label="Отложен"><header><div><span class="column-dot gray-dot"></span><span class="column-title"><h2>Отложен</h2></span><b>0</b></div><button aria-label="Меню колонки">•••</button></header><div class="cards"></div><button class="add-card">＋ Добавить задачу</button></section>
        </div>
        </div>

        <div class="board-panel" id="projectsPanel" role="tabpanel" hidden>
        <div class="kanban projects-board" id="projectsKanban">

          <section class="column" data-column="year" data-label="<?= htmlspecialchars($currentYear, ENT_QUOTES) ?>"><header><div><span class="column-dot green-dot"></span><span class="column-title"><h2><?= htmlspecialchars($currentYear, ENT_QUOTES) ?></h2></span><b>0</b></div><button aria-label="Меню колонки">•••</button></header><div class="cards"></div><button class="add-project">＋ Добавить проект</button></section>

          <section class="column" data-column="next_year" data-label="<?= htmlspecialchars($nextYear, ENT_QUOTES) ?>"><header><div><span class="column-dot gray-dot"></span><span class="column-title"><h2><?= htmlspecialchars($nextYear, ENT_QUOTES) ?></h2></span><b>0</b></div><button aria-label="Меню колонки">•••</button></header><div class="cards"></div><button class="add-project">＋ Добавить проект</button></section>
        </div>
        </div>
	      </section>
</div>
<div class="modal-backdrop" id="modal" hidden><div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle"><button class="modal-close" type="button" aria-label="Закрыть">×</button><div class="modal-icon">＋</div><h2 id="modalTitle">Новая заявка</h2><p>Заявка будет создана в Jira и добавлена в бэклог.</p><form id="issueForm"><input type="hidden" name="sessid" value="<?= htmlspecialchars(function_exists('bitrix_sessid') ? bitrix_sessid() : '', ENT_QUOTES) ?>"><input type="hidden" name="plan" value="later"><label>Название<input required maxlength="255" name="title" placeholder="Кратко опишите задачу"></label><div class="form-row"><label>Проект<select name="project"><option value="ods">ODS</option><option value="ssis">SSIS</option></select></label><label>Сложность<select name="complexity" required><option value="low" selected>Низкая</option><option value="medium">Средняя</option><option value="high">Высокая</option><option value="maximum">Максимальная</option></select></label></div><div class="form-row create-jira-fields" hidden><label>Компонент Jira<select name="componentId"><option value="">Выберите компонент</option></select></label><label>Исправить в версиях<select name="fixVersionId"><option value="">Выберите версию</option></select></label></div><label>Описание для Jira<textarea name="description" maxlength="10000" placeholder="Добавьте детали задачи"></textarea></label><div class="form-error" role="alert" hidden></div><div class="modal-actions"><button type="button" class="btn secondary cancel">Отмена</button><button class="btn primary" type="submit">Создать заявку</button></div></form></div></div>
<div class="modal-backdrop" id="metadataModal" hidden><div class="modal metadata-modal" role="dialog" aria-modal="true" aria-labelledby="metadataTitle"><button class="modal-close" type="button" aria-label="Закрыть">×</button><div class="modal-icon">✎</div><h2 id="metadataTitle">План заявки</h2><p id="metadataSubtitle"></p><div class="jira-status-line">Статус Jira: <strong id="metadataJiraStatus">—</strong></div><div class="jira-details" id="metadataJiraDetails"></div><form id="metadataForm"><input type="hidden" name="sessid" value="<?= htmlspecialchars(function_exists('bitrix_sessid') ? bitrix_sessid() : '', ENT_QUOTES) ?>"><input type="hidden" name="issueKey"><div class="form-row"><label>Категория<select name="bucket"><option value="backlog">Бэклог</option><option value="week">Эта неделя</option><option value="next">Следующая неделя</option><option value="completed">Выполнено</option><option value="deferred">Отложен</option></select></label><label>Сложность<select name="complexity" required><option value="low">Низкая</option><option value="medium">Средняя</option><option value="high">Высокая</option><option value="maximum">Максимальная</option></select></label></div><label class="planned-week-field">Плановая неделя<input type="week" name="plannedWeek"><small>Неделя сохраняется за заявкой и используется в плане отчёта.</small></label><label class="completed-week-field" hidden>Неделя выполнения<input type="week" name="completedWeekDisplay" readonly><small>Фиксируется при переносе заявки в «Выполнено» и используется в итогах отчёта.</small></label><section class="linked-issues-block" hidden><strong>Связанные заявки Jira</strong><div class="linked-issues-list"></div></section><fieldset class="issue-project-picker"><legend>Проекты</legend><div class="issue-project-options"></div></fieldset><fieldset class="tag-picker"><legend>Теги</legend><div class="tag-picker-options"></div><div class="add-tag"><input type="text" name="newTag" maxlength="64" placeholder="Новый тег"><button class="btn secondary" type="button">Добавить</button></div></fieldset><label>Описание из Jira<textarea class="jira-description" name="jiraDescription" readonly></textarea></label><label>Краткое описание<textarea name="shortDescription" maxlength="5000" placeholder="Контекст и ожидаемый результат"></textarea></label><div class="history-block" data-history="plannedActions"></div><div class="entry-input"><label>Неделя планируемого действия<input type="week" name="plannedActionWeek"></label><label>Добавить планируемое действие<textarea name="plannedAction" maxlength="10000" placeholder="Что планируется сделать"></textarea></label></div><div class="history-block" data-history="facts"></div><div class="entry-input"><label>Неделя факта<input type="week" name="factWeek"></label><label>Добавить факт<textarea name="fact" maxlength="10000" placeholder="Что фактически сделано"></textarea></label></div><small class="date-note">Дата и время для действия и факта будут зафиксированы при сохранении.</small><label class="complete-comment" hidden>Комментарий для выполнения в Jira<textarea name="completeComment" maxlength="10000" placeholder="Укажите результат выполнения заявки"></textarea><small>Комментарий будет подписан текущим пользователем Битрикс24 и добавлен в Jira.</small></label><div class="form-error" role="alert" hidden></div><div class="modal-actions"><button type="button" class="btn complete-jira" hidden>Выполнить в Jira</button><button type="button" class="btn secondary cancel">Отмена</button><button class="btn primary" type="submit">Сохранить</button></div></form></div></div>
<div class="modal-backdrop" id="projectModal" hidden><div class="modal project-modal" role="dialog" aria-modal="true" aria-labelledby="projectModalTitle"><button class="modal-close" type="button" aria-label="Закрыть">×</button><div class="modal-icon">▦</div><h2 id="projectModalTitle">Новый проект</h2><p>Объедините связанные Jira-заявки в проект.</p><form id="projectForm"><input type="hidden" name="sessid" value="<?= htmlspecialchars(function_exists('bitrix_sessid') ? bitrix_sessid() : '', ENT_QUOTES) ?>"><input type="hidden" name="action" value="saveProject"><input type="hidden" name="projectId"><label>Название проекта<input required maxlength="255" name="name" placeholder="Название проекта"></label><label>Описание проекта<textarea name="description" maxlength="10000" placeholder="Цель и содержание проекта"></textarea></label><label>Год<select name="year"><option value="<?= htmlspecialchars($currentYear, ENT_QUOTES) ?>"><?= htmlspecialchars($currentYear, ENT_QUOTES) ?></option><option value="<?= htmlspecialchars($nextYear, ENT_QUOTES) ?>"><?= htmlspecialchars($nextYear, ENT_QUOTES) ?></option></select></label><fieldset class="project-issues-picker"><legend>Заявки проекта</legend><label class="project-issue-search">Поиск<input type="search" placeholder="Номер или название заявки"></label><div class="project-issue-options"></div></fieldset><div class="form-error" role="alert" hidden></div><div class="modal-actions"><button type="button" class="btn secondary cancel">Отмена</button><button class="btn primary" type="submit">Сохранить проект</button></div></form></div></div>
<div class="modal-backdrop" id="reportDataModal" hidden><div class="modal report-data-modal" role="dialog" aria-modal="true" aria-labelledby="reportDataTitle"><button class="modal-close" type="button" aria-label="Закрыть">×</button><div class="modal-icon">≣</div><h2 id="reportDataTitle">Дополнить данные отчёта</h2><p>Для выбранных недель не заполнены планируемые действия или факты. Заполните их сейчас либо сформируйте отчёт без них.</p><form id="reportDataForm"><div class="report-data-table"></div><div class="form-error" role="alert" hidden></div><div class="modal-actions"><button type="button" class="btn secondary skip">Пропустить и скачать</button><button class="btn primary" type="submit">Сохранить и скачать</button></div></form></div></div>
<div class="modal-backdrop" id="reportModal" hidden><div class="modal report-modal" role="dialog" aria-modal="true" aria-labelledby="reportTitle"><button class="modal-close" type="button" aria-label="Закрыть">×</button><div class="modal-icon">↗</div><h2 id="reportTitle">Создать недельный отчёт</h2><p>Выберите отдельно неделю итогов и неделю планирования.</p><form id="reportForm"><label>Неделя выполненных задач<input required type="week" name="completedWeek"></label><label>Неделя запланированных задач<input required type="week" name="plannedWeek"></label><div class="modal-actions"><button type="button" class="btn secondary cancel">Отмена</button><button class="btn primary" type="submit">Скачать CSV</button></div></form></div></div>
  <div class="toast" id="toast"><span>✓</span><div><strong>Заявка создана</strong><small>Синхронизация с Jira запущена</small></div></div>
<script src="/forms/project_manager/app.js?v=29"></script>
<?php
if (is_file($bitrixFooter)) {
    require $bitrixFooter;
}
