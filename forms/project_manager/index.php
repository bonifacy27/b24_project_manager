<?php

declare(strict_types=1);

$bitrixHeader = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/header.php';
$bitrixFooter = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/footer.php';
if (is_file($bitrixHeader)) {
    require $bitrixHeader;
}
if (isset($APPLICATION)) {
    $APPLICATION->SetTitle('Управление проектами');
}
?>
<link rel="stylesheet" href="/forms/project_manager/styles.css?v=4">
<div class="project-manager">
<section class="content">
        <div class="page-heading">
          <div><div class="eyebrow">РАБОЧЕЕ ПРОСТРАНСТВО</div><h1>Проекты</h1><p>Планируйте работу и следите за заявками из Jira</p></div>
          <div class="heading-actions"><button class="btn secondary" id="reportBtn"><span>↗</span> Создать отчёт</button><button class="btn primary" id="createBtn"><span>＋</span> Новая заявка</button></div>
        </div>

        <div class="stats" aria-label="Сводка">
          <article><div class="stat-icon blue">▤</div><div><span>Всего в работе</span><strong id="activeTotal">—</strong><small>ODS и SSIS</small></div></article>
          <article><div class="stat-icon violet">◷</div><div><span>На этой неделе</span><strong id="weekTotal">—</strong><small>По сроку выполнения</small></div></article>
          <article><div class="stat-icon orange">⚑</div><div><span>Ожидают ответа</span><strong id="waitingTotal">—</strong><small>По статусу Jira</small></div></article>
          <article><div class="stat-icon green">✓</div><div><span>Обновлено сегодня</span><strong id="updatedTotal">—</strong><small>ODS и SSIS</small></div></article>
        </div>

        <div class="toolbar">
          <div class="project-tabs" role="tablist"><button class="active" data-project="all">Все проекты <b>—</b></button><button data-project="ods"><span class="project-dot ods"></span> ODS <b>—</b></button><button data-project="ssis"><span class="project-dot ssis"></span> SSIS <b>—</b></button></div>
          <div class="view-actions"><label class="issue-search" aria-label="Поиск заявок"><span>⌕</span><input id="searchInput" type="search" placeholder="Поиск"></label><button class="btn tertiary" id="refreshBtn" type="button">↻ <span>Обновить</span></button><div class="view-switch"><button class="active" type="button" data-view="board" aria-label="Канбан">▦</button><button type="button" data-view="list" aria-label="Список">☷</button></div></div>
        </div>

        <div class="integration-notice" id="integrationNotice" hidden><strong>Ошибка загрузки Jira</strong><span>Подробности показаны в колонках ниже.</span></div>
        <div class="kanban" id="kanban">
          <section class="column" data-column="week"><header><div><span class="column-dot blue-dot"></span><h2>Эта неделя</h2><b>0</b></div><button aria-label="Меню колонки">•••</button></header><div class="cards"></div><button class="add-card">＋ Добавить задачу</button></section>

          <section class="column" data-column="next"><header><div><span class="column-dot violet-dot"></span><h2>Следующая неделя</h2><b>0</b></div><button>•••</button></header><div class="cards"></div><button class="add-card">＋ Добавить задачу</button></section>

          <section class="column" data-column="later"><header><div><span class="column-dot orange-dot"></span><h2>Позже</h2><b>0</b></div><button>•••</button></header><div class="cards"></div><button class="add-card">＋ Добавить задачу</button></section>
        </div>
      </section>
</div>
<div class="modal-backdrop" id="modal" hidden><div class="modal" role="dialog" aria-modal="true" aria-labelledby="modalTitle"><button class="modal-close" type="button" aria-label="Закрыть">×</button><div class="modal-icon">＋</div><h2 id="modalTitle">Новая заявка</h2><p>Заявка будет создана в Jira и появится на доске.</p><form id="issueForm"><input type="hidden" name="sessid" value="<?= htmlspecialchars(function_exists('bitrix_sessid') ? bitrix_sessid() : '', ENT_QUOTES) ?>"><label>Название<input required maxlength="255" name="title" placeholder="Кратко опишите задачу"></label><div class="form-row"><label>Проект<select name="project"><option value="ods">ODS</option><option value="ssis">SSIS</option></select></label><label>План<select name="plan"><option value="week">Эта неделя</option><option value="next">Следующая неделя</option><option value="later">Позже</option></select></label></div><label>Описание<textarea name="description" maxlength="10000" placeholder="Добавьте детали задачи"></textarea></label><div class="form-error" role="alert" hidden></div><div class="modal-actions"><button type="button" class="btn secondary cancel">Отмена</button><button class="btn primary" type="submit">Создать заявку</button></div></form></div></div>
  <div class="toast" id="toast"><span>✓</span><div><strong>Заявка создана</strong><small>Синхронизация с Jira запущена</small></div></div>
<script src="/forms/project_manager/app.js?v=5"></script>
<?php
if (is_file($bitrixFooter)) {
    require $bitrixFooter;
}
