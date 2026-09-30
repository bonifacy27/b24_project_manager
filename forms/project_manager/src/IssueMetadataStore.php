<?php

declare(strict_types=1);

namespace ProjectManager;

use Bitrix\Main\Application;
use RuntimeException;

final class IssueMetadataStore
{
    private const TABLE = 'b_project_manager_issue_meta';
    private const TAGS_TABLE = 'b_project_manager_tags';
    private const PROJECTS_TABLE = 'b_project_manager_projects';
    private const PROJECT_ISSUES_TABLE = 'b_project_manager_project_issues';
    private const ISSUE_TAGS_TABLE = 'b_project_manager_issue_tags';
    private const LATER_SECTIONS_TABLE = 'b_project_manager_later_sections';
    private const LEGACY_USER_ID = 3532;
    private const BUCKETS = ['backlog', 'week', 'next', 'completed', 'deferred'];
    private const COMPLEXITIES = ['low', 'medium', 'high', 'maximum'];

    private readonly object $connection;

    public function __construct(private readonly int $userId)
    {
        if (!class_exists(Application::class)) {
            throw new RuntimeException('Ядро Битрикс недоступно для хранения плана заявок.');
        }

        $this->connection = Application::getConnection();
        if ($this->userId < 1) {
            throw new RuntimeException('Некорректный пользователь хранилища плана.');
        }
        $this->connection->queryExecute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (' .
            ' ISSUE_KEY varchar(64) NOT NULL,' .
            ' BUCKET varchar(32) NOT NULL DEFAULT \'backlog\',' .
            ' COMPLEXITY varchar(16) NOT NULL DEFAULT \'low\',' .
            ' PLANNED_WEEK varchar(8) NULL,' .
            ' COMPLETED_WEEK varchar(8) NULL,' .
            ' TAGS text NULL,' .
            ' SHORT_DESCRIPTION text NULL,' .
            ' PLANNED_ACTIONS text NULL,' .
            ' FACTS text NULL,' .
            ' UPDATED_AT datetime NOT NULL,' .
            ' PRIMARY KEY (ISSUE_KEY)' .
            ')'
        );
        $this->connection->queryExecute(
            'CREATE TABLE IF NOT EXISTS ' . self::TAGS_TABLE . ' (' .
            ' NAME varchar(64) NOT NULL,' .
            ' CREATED_AT datetime NOT NULL,' .
            ' PRIMARY KEY (NAME)' .
            ')'
        );
        $this->connection->queryExecute(
            'CREATE TABLE IF NOT EXISTS ' . self::PROJECTS_TABLE . ' (' .
            ' ID int unsigned NOT NULL AUTO_INCREMENT,' .
            ' NAME varchar(255) NOT NULL,' .
            ' DESCRIPTION text NULL,' .
            ' PLAN_YEAR smallint unsigned NOT NULL,' .
            ' CREATED_AT datetime NOT NULL,' .
            ' UPDATED_AT datetime NOT NULL,' .
            ' PRIMARY KEY (ID)' .
            ')'
        );
        $projectDescriptionColumn = $this->connection->query(
            "SHOW COLUMNS FROM " . self::PROJECTS_TABLE . " LIKE 'DESCRIPTION'"
        )->fetch();
        if (!$projectDescriptionColumn) {
            $this->connection->queryExecute(
                'ALTER TABLE ' . self::PROJECTS_TABLE . ' ADD DESCRIPTION text NULL AFTER NAME'
            );
        }
        $this->connection->queryExecute(
            'CREATE TABLE IF NOT EXISTS ' . self::PROJECT_ISSUES_TABLE . ' (' .
            ' PROJECT_ID int unsigned NOT NULL,' .
            ' ISSUE_KEY varchar(64) NOT NULL,' .
            ' PRIMARY KEY (PROJECT_ID, ISSUE_KEY),' .
            ' KEY IX_ISSUE_KEY (ISSUE_KEY)' .
            ')'
        );
        $this->connection->queryExecute(
            'CREATE TABLE IF NOT EXISTS ' . self::LATER_SECTIONS_TABLE . ' (' .
            ' ID int unsigned NOT NULL AUTO_INCREMENT,' .
            ' USER_ID int unsigned NOT NULL,' .
            ' NAME varchar(64) NOT NULL,' .
            ' SORT int unsigned NOT NULL DEFAULT 500,' .
            ' PRIMARY KEY (ID),' .
            ' KEY IX_USER_SORT (USER_ID, SORT)' .
            ')'
        );
        $this->connection->queryExecute(
            'UPDATE ' . self::TABLE . " SET BUCKET = 'deferred' WHERE BUCKET IN ('later_ready', 'later_second')"
        );
        $complexityColumn = $this->connection->query("SHOW COLUMNS FROM " . self::TABLE . " LIKE 'COMPLEXITY'")->fetch();
        if (!$complexityColumn) {
            $this->connection->queryExecute(
                "ALTER TABLE " . self::TABLE . " ADD COMPLEXITY varchar(16) NOT NULL DEFAULT 'low' AFTER BUCKET"
            );
        }
        foreach (['PLANNED_WEEK', 'COMPLETED_WEEK'] as $column) {
            $existingColumn = $this->connection->query("SHOW COLUMNS FROM " . self::TABLE . " LIKE '{$column}'")->fetch();
            if (!$existingColumn) {
                $this->connection->queryExecute(
                    "ALTER TABLE " . self::TABLE . " ADD {$column} varchar(8) NULL AFTER COMPLEXITY"
                );
                $currentWeek = (new \DateTimeImmutable('today'))->format('o-\WW');
                if ($column === 'PLANNED_WEEK') {
                    $nextWeek = (new \DateTimeImmutable('+1 week'))->format('o-\WW');
                    $this->connection->queryExecute(
                        "UPDATE " . self::TABLE . " SET PLANNED_WEEK = CASE " .
                        "WHEN BUCKET = 'week' THEN '{$currentWeek}' WHEN BUCKET = 'next' THEN '{$nextWeek}' ELSE NULL END"
                    );
                } else {
                    $this->connection->queryExecute(
                        "UPDATE " . self::TABLE . " SET COMPLETED_WEEK = '{$currentWeek}' WHERE BUCKET = 'completed'"
                    );
                }
            }
        }
        $tagsColumn = $this->connection->query("SHOW COLUMNS FROM " . self::TABLE . " LIKE 'TAGS'")->fetch();
        if (!$tagsColumn) {
            $this->connection->queryExecute('ALTER TABLE ' . self::TABLE . ' ADD TAGS text NULL AFTER COMPLETED_WEEK');
        }
        $this->ensureUserOwnership();
    }

    private function ensureUserOwnership(): void
    {
        $tagUserColumn = $this->connection->query("SHOW COLUMNS FROM " . self::TAGS_TABLE . " LIKE 'USER_ID'")->fetch();
        if (!$tagUserColumn) {
            $this->connection->queryExecute('ALTER TABLE ' . self::TAGS_TABLE . ' DROP PRIMARY KEY');
            $this->connection->queryExecute('ALTER TABLE ' . self::TAGS_TABLE . ' ADD USER_ID int unsigned NOT NULL DEFAULT ' . self::LEGACY_USER_ID . ' FIRST, ADD PRIMARY KEY (USER_ID, NAME)');
        }
        $projectUserColumn = $this->connection->query("SHOW COLUMNS FROM " . self::PROJECTS_TABLE . " LIKE 'USER_ID'")->fetch();
        if (!$projectUserColumn) {
            $this->connection->queryExecute('ALTER TABLE ' . self::PROJECTS_TABLE . ' ADD USER_ID int unsigned NOT NULL DEFAULT ' . self::LEGACY_USER_ID . ' AFTER ID, ADD KEY IX_USER_ID (USER_ID)');
        }
        $this->connection->queryExecute(
            'CREATE TABLE IF NOT EXISTS ' . self::ISSUE_TAGS_TABLE . ' (' .
            ' USER_ID int unsigned NOT NULL,' .
            ' ISSUE_KEY varchar(64) NOT NULL,' .
            ' TAG_NAME varchar(64) NOT NULL,' .
            ' PRIMARY KEY (USER_ID, ISSUE_KEY, TAG_NAME),' .
            ' KEY IX_TAG (USER_ID, TAG_NAME)' .
            ')'
        );

        $legacyRows = $this->connection->query('SELECT ISSUE_KEY, TAGS FROM ' . self::TABLE . " WHERE TAGS IS NOT NULL AND TAGS <> ''");
        $sql = $this->connection->getSqlHelper();
        while ($row = $legacyRows->fetch()) {
            foreach ($this->decodeTags((string) $row['TAGS']) as $tag) {
                $safeKey = $sql->forSql((string) $row['ISSUE_KEY'], 64);
                $safeTag = $sql->forSql($tag, 64);
                $this->connection->queryExecute(
                    'INSERT IGNORE INTO ' . self::ISSUE_TAGS_TABLE . ' (USER_ID, ISSUE_KEY, TAG_NAME) VALUES (' . self::LEGACY_USER_ID . ", '{$safeKey}', '{$safeTag}')"
                );
            }
        }
        $this->connection->queryExecute('UPDATE ' . self::TABLE . " SET TAGS = NULL WHERE TAGS IS NOT NULL AND TAGS <> ''");
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $items = [];
        $result = $this->connection->query('SELECT * FROM ' . self::TABLE);
        while ($row = $result->fetch()) {
            $item = $this->normaliseRow($row);
            $item['tags'] = $this->issueTags($item['issueKey']);
            $items[$item['issueKey']] = $item;
        }

        return $items;
    }

    /** @return list<string> */
    public function tags(): array
    {
        $tags = [];
        $result = $this->connection->query('SELECT NAME FROM ' . self::TAGS_TABLE . ' WHERE USER_ID = ' . $this->userId . ' ORDER BY NAME');
        while ($row = $result->fetch()) {
            $tags[] = (string) $row['NAME'];
        }

        return $tags;
    }

    /** @return list<array{id: int, name: string, description: string, year: int, issueKeys: list<string>}> */
    public function projects(): array
    {
        $projects = [];
        $result = $this->connection->query(
            'SELECT P.ID, P.NAME, P.DESCRIPTION, P.PLAN_YEAR, PI.ISSUE_KEY FROM ' . self::PROJECTS_TABLE . ' P ' .
            'LEFT JOIN ' . self::PROJECT_ISSUES_TABLE . ' PI ON PI.PROJECT_ID = P.ID ' .
            'WHERE P.USER_ID = ' . $this->userId . ' ORDER BY P.PLAN_YEAR, P.NAME, PI.ISSUE_KEY'
        );
        while ($row = $result->fetch()) {
            $id = (int) $row['ID'];
            $projects[$id] ??= [
                'id' => $id,
                'name' => (string) $row['NAME'],
                'description' => (string) ($row['DESCRIPTION'] ?? ''),
                'year' => (int) $row['PLAN_YEAR'],
                'issueKeys' => [],
            ];
            if (!empty($row['ISSUE_KEY'])) {
                $projects[$id]['issueKeys'][] = (string) $row['ISSUE_KEY'];
            }
        }

        return array_values($projects);
    }

    /** @return list<array{id: int, bucket: string, name: string, sort: int}> */
    public function laterSections(): array
    {
        $sections = [];
        $result = $this->connection->query(
            'SELECT ID, NAME, SORT FROM ' . self::LATER_SECTIONS_TABLE . ' WHERE USER_ID = ' . $this->userId . ' ORDER BY SORT, ID'
        );
        while ($row = $result->fetch()) {
            $id = (int) $row['ID'];
            $sections[] = ['id' => $id, 'bucket' => 'later_' . $id, 'name' => (string) $row['NAME'], 'sort' => (int) $row['SORT']];
        }
        return $sections;
    }

    /** @return array{id: int, bucket: string, name: string, sort: int} */
    public function saveLaterSection(string $name): array
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 64) {
            throw new \InvalidArgumentException('Название раздела обязательно и не должно превышать 64 символа.');
        }
        $sql = $this->connection->getSqlHelper();
        $safeName = $sql->forSql($name, 64);
        $existing = $this->connection->query(
            'SELECT COUNT(*) AS CNT FROM ' . self::LATER_SECTIONS_TABLE . ' WHERE USER_ID = ' . $this->userId
        )->fetch();
        if ((int) ($existing['CNT'] ?? 0) >= 20) {
            throw new \InvalidArgumentException('Можно создать не более 20 разделов в колонке «Позже».');
        }
        $row = $this->connection->query(
            'SELECT COALESCE(MAX(SORT), 0) AS MAX_SORT FROM ' . self::LATER_SECTIONS_TABLE . ' WHERE USER_ID = ' . $this->userId
        )->fetch();
        $sort = (int) ($row['MAX_SORT'] ?? 0) + 100;
        $this->connection->queryExecute(
            'INSERT INTO ' . self::LATER_SECTIONS_TABLE . " (USER_ID, NAME, SORT) VALUES ({$this->userId}, '{$safeName}', {$sort})"
        );
        $id = (int) $this->connection->getInsertedId();
        return ['id' => $id, 'bucket' => 'later_' . $id, 'name' => $name, 'sort' => $sort];
    }

    public function deleteLaterSection(int $id): void
    {
        if ($id < 1 || !$this->connection->query(
            'SELECT ID FROM ' . self::LATER_SECTIONS_TABLE . " WHERE ID = {$id} AND USER_ID = {$this->userId}"
        )->fetch()) {
            throw new \InvalidArgumentException('Раздел не найден.');
        }
        $bucket = $this->connection->getSqlHelper()->forSql('later_' . $id, 32);
        $this->connection->queryExecute('UPDATE ' . self::TABLE . " SET BUCKET = 'deferred' WHERE BUCKET = '{$bucket}'");
        $this->connection->queryExecute(
            'DELETE FROM ' . self::LATER_SECTIONS_TABLE . " WHERE ID = {$id} AND USER_ID = {$this->userId}"
        );
    }

    private function isValidBucket(string $bucket): bool
    {
        if (in_array($bucket, self::BUCKETS, true)) {
            return true;
        }
        if (!preg_match('/^later_(\d+)$/', $bucket, $matches)) {
            return false;
        }
        return (bool) $this->connection->query(
            'SELECT ID FROM ' . self::LATER_SECTIONS_TABLE . ' WHERE ID = ' . (int) $matches[1] . ' AND USER_ID = ' . $this->userId
        )->fetch();
    }

    /** @param list<string> $issueKeys
     *  @return array{id: int, name: string, description: string, year: int, issueKeys: list<string>}
     */
    public function saveProject(?int $id, string $name, string $description, int $year, array $issueKeys): array
    {
        $name = trim($name);
        $currentYear = (int) date('Y');
        if ($name === '' || mb_strlen($name) > 255) {
            throw new \InvalidArgumentException('Название проекта обязательно и не должно превышать 255 символов.');
        }
        $description = trim($description);
        if (mb_strlen($description) > 10000) {
            throw new \InvalidArgumentException('Описание проекта не должно превышать 10000 символов.');
        }
        if (!in_array($year, [$currentYear, $currentYear + 1], true)) {
            throw new \InvalidArgumentException('Проект можно запланировать на текущий или следующий год.');
        }
        if (count($issueKeys) > 500) {
            throw new \InvalidArgumentException('К проекту можно привязать не более 500 заявок.');
        }
        $validatedKeys = [];
        foreach ($issueKeys as $issueKey) {
            $issueKey = strtoupper(trim((string) $issueKey));
            if (!preg_match('/^[A-Z][A-Z0-9_]+-\d+$/', $issueKey)) {
                throw new \InvalidArgumentException('Некорректный ключ заявки в проекте.');
            }
            $validatedKeys[$issueKey] = $issueKey;
        }

        $sql = $this->connection->getSqlHelper();
        $safeName = $sql->forSql($name, 255);
        $safeDescription = $sql->forSql($description);
        $now = $sql->forSql(gmdate('Y-m-d H:i:s'));
        if ($id === null) {
            $this->connection->queryExecute(
                'INSERT INTO ' . self::PROJECTS_TABLE . " (USER_ID, NAME, DESCRIPTION, PLAN_YEAR, CREATED_AT, UPDATED_AT) VALUES ({$this->userId}, '{$safeName}', '{$safeDescription}', {$year}, '{$now}', '{$now}')"
            );
            $id = (int) $this->connection->getInsertedId();
        } else {
            if ($id < 1 || !$this->connection->query('SELECT ID FROM ' . self::PROJECTS_TABLE . " WHERE ID = {$id} AND USER_ID = {$this->userId}")->fetch()) {
                throw new \InvalidArgumentException('Проект не найден.');
            }
            $this->connection->queryExecute(
                'UPDATE ' . self::PROJECTS_TABLE . " SET NAME = '{$safeName}', DESCRIPTION = '{$safeDescription}', PLAN_YEAR = {$year}, UPDATED_AT = '{$now}' WHERE ID = {$id} AND USER_ID = {$this->userId}"
            );
        }
        $this->connection->queryExecute('DELETE FROM ' . self::PROJECT_ISSUES_TABLE . " WHERE PROJECT_ID = {$id}");
        foreach ($validatedKeys as $issueKey) {
            $safeKey = $sql->forSql($issueKey, 64);
            $this->connection->queryExecute(
                'INSERT INTO ' . self::PROJECT_ISSUES_TABLE . " (PROJECT_ID, ISSUE_KEY) VALUES ({$id}, '{$safeKey}')"
            );
        }

        foreach ($this->projects() as $project) {
            if ($project['id'] === $id) {
                return $project;
            }
        }
        throw new RuntimeException('Не удалось прочитать сохранённый проект.');
    }

    /** @param list<int|string> $projectIds */
    public function setIssueProjects(string $issueKey, array $projectIds): void
    {
        $issueKey = strtoupper(trim($issueKey));
        if (!preg_match('/^[A-Z][A-Z0-9_]+-\d+$/', $issueKey)) {
            throw new \InvalidArgumentException('Некорректный ключ заявки.');
        }
        $ids = array_values(array_unique(array_map('intval', $projectIds)));
        if (count($ids) > 100 || in_array(0, $ids, true)) {
            throw new \InvalidArgumentException('Некорректный список проектов заявки.');
        }
        if ($ids !== []) {
            $existing = $this->connection->query(
                'SELECT COUNT(*) AS CNT FROM ' . self::PROJECTS_TABLE . ' WHERE USER_ID = ' . $this->userId . ' AND ID IN (' . implode(',', $ids) . ')'
            )->fetch();
            if ((int) ($existing['CNT'] ?? 0) !== count($ids)) {
                throw new \InvalidArgumentException('Один из выбранных проектов не найден.');
            }
        }
        $safeKey = $this->connection->getSqlHelper()->forSql($issueKey, 64);
        $this->connection->queryExecute(
            'DELETE PI FROM ' . self::PROJECT_ISSUES_TABLE . ' PI INNER JOIN ' . self::PROJECTS_TABLE .
            " P ON P.ID = PI.PROJECT_ID WHERE PI.ISSUE_KEY = '{$safeKey}' AND P.USER_ID = {$this->userId}"
        );
        foreach ($ids as $id) {
            $this->connection->queryExecute(
                'INSERT INTO ' . self::PROJECT_ISSUES_TABLE . " (PROJECT_ID, ISSUE_KEY) VALUES ({$id}, '{$safeKey}')"
            );
        }
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function save(string $issueKey, array $input): array
    {
        $issueKey = strtoupper(trim($issueKey));
        $bucket = strtolower(trim((string) ($input['bucket'] ?? '')));
        if (!preg_match('/^[A-Z][A-Z0-9_]+-\d+$/', $issueKey) || !$this->isValidBucket($bucket)) {
            throw new \InvalidArgumentException('Неверно указаны ключ Jira или категория.');
        }

        $current = $this->find($issueKey);
        $complexity = array_key_exists('complexity', $input)
            ? strtolower(trim((string) $input['complexity']))
            : (string) ($current['complexity'] ?? 'low');
        if (!in_array($complexity, self::COMPLEXITIES, true)) {
            throw new \InvalidArgumentException('Неверно указана сложность заявки.');
        }
        $plannedWeek = array_key_exists('plannedWeek', $input)
            ? strtoupper(trim((string) $input['plannedWeek']))
            : (string) ($current['plannedWeek'] ?? '');
        if ($plannedWeek !== '' && !preg_match('/^\d{4}-W(?:0[1-9]|[1-4]\d|5[0-3])$/', $plannedWeek)) {
            throw new \InvalidArgumentException('Неверно указана плановая неделя.');
        }
        if ($plannedWeek === '' && in_array($bucket, ['week', 'next'], true)) {
            $weekDate = new \DateTimeImmutable($bucket === 'next' ? '+1 week' : 'today');
            $plannedWeek = $weekDate->format('o-\WW');
        }
        $completedWeek = (string) ($current['completedWeek'] ?? '');
        if ($bucket === 'completed' && ($current['bucket'] ?? '') !== 'completed') {
            $completedWeek = (new \DateTimeImmutable('today'))->format('o-\WW');
        }
        $tags = array_key_exists('tags', $input)
            ? $this->validateTags($input['tags'])
            : ($current['tags'] ?? []);
        $shortDescription = array_key_exists('shortDescription', $input)
            ? trim((string) $input['shortDescription'])
            : (string) ($current['shortDescription'] ?? '');
        if (mb_strlen($shortDescription) > 5000) {
            throw new \InvalidArgumentException('Краткое описание не должно превышать 5000 символов.');
        }

        $plannedActions = array_key_exists('plannedActions', $input)
            ? $this->validateEntries($input['plannedActions'], $current['plannedActions'] ?? [])
            : ($current['plannedActions'] ?? []);
        $facts = array_key_exists('facts', $input)
            ? $this->validateEntries($input['facts'], $current['facts'] ?? [])
            : ($current['facts'] ?? []);
        $now = gmdate(DATE_ATOM);
        $this->appendEntry($plannedActions, (string) ($input['plannedAction'] ?? ''), (string) ($input['plannedActionWeek'] ?? ''), $now);
        $this->appendEntry($facts, (string) ($input['fact'] ?? ''), (string) ($input['factWeek'] ?? ''), $now);

        $sql = $this->connection->getSqlHelper();
        $values = [
            'key' => $sql->forSql($issueKey, 64),
            'bucket' => $sql->forSql($bucket, 32),
            'complexity' => $sql->forSql($complexity, 16),
            'plannedWeek' => $sql->forSql($plannedWeek, 8),
            'completedWeek' => $sql->forSql($completedWeek, 8),
            // Per-user assignments live in ISSUE_TAGS_TABLE. Keep the legacy
            // column empty so another user's tags are never migrated to 3532.
            'tags' => '',
            'description' => $sql->forSql($shortDescription),
            'actions' => $sql->forSql((string) json_encode($plannedActions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'facts' => $sql->forSql((string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'updated' => $sql->forSql(gmdate('Y-m-d H:i:s')),
        ];
        $this->connection->queryExecute(
            "INSERT INTO " . self::TABLE . " (ISSUE_KEY, BUCKET, COMPLEXITY, PLANNED_WEEK, COMPLETED_WEEK, TAGS, SHORT_DESCRIPTION, PLANNED_ACTIONS, FACTS, UPDATED_AT) VALUES " .
            "('{$values['key']}', '{$values['bucket']}', '{$values['complexity']}', NULLIF('{$values['plannedWeek']}', ''), NULLIF('{$values['completedWeek']}', ''), '{$values['tags']}', '{$values['description']}', '{$values['actions']}', '{$values['facts']}', '{$values['updated']}') " .
            "ON DUPLICATE KEY UPDATE BUCKET=VALUES(BUCKET), COMPLEXITY=VALUES(COMPLEXITY), PLANNED_WEEK=VALUES(PLANNED_WEEK), COMPLETED_WEEK=VALUES(COMPLETED_WEEK), TAGS=VALUES(TAGS), SHORT_DESCRIPTION=VALUES(SHORT_DESCRIPTION), " .
            "PLANNED_ACTIONS=VALUES(PLANNED_ACTIONS), FACTS=VALUES(FACTS), UPDATED_AT=VALUES(UPDATED_AT)"
        );
        foreach ($tags as $tag) {
            $safeTag = $sql->forSql($tag, 64);
            $this->connection->queryExecute(
                'INSERT IGNORE INTO ' . self::TAGS_TABLE . " (USER_ID, NAME, CREATED_AT) VALUES ({$this->userId}, '{$safeTag}', '{$values['updated']}')"
            );
        }
        $this->connection->queryExecute(
            'DELETE FROM ' . self::ISSUE_TAGS_TABLE . " WHERE USER_ID = {$this->userId} AND ISSUE_KEY = '{$values['key']}'"
        );
        foreach ($tags as $tag) {
            $safeTag = $sql->forSql($tag, 64);
            $this->connection->queryExecute(
                'INSERT INTO ' . self::ISSUE_TAGS_TABLE . " (USER_ID, ISSUE_KEY, TAG_NAME) VALUES ({$this->userId}, '{$values['key']}', '{$safeTag}')"
            );
        }

        return $this->find($issueKey) ?? [];
    }

    /** @return array<string, mixed>|null */
    private function find(string $issueKey): ?array
    {
        $safeKey = $this->connection->getSqlHelper()->forSql($issueKey, 64);
        $row = $this->connection->query("SELECT * FROM " . self::TABLE . " WHERE ISSUE_KEY = '{$safeKey}'")->fetch();
        if (!$row) {
            return null;
        }
        $item = $this->normaliseRow($row);
        $item['tags'] = $this->issueTags($issueKey);
        return $item;
    }

    /** @return list<string> */
    private function issueTags(string $issueKey): array
    {
        $safeKey = $this->connection->getSqlHelper()->forSql($issueKey, 64);
        $tags = [];
        $result = $this->connection->query(
            'SELECT TAG_NAME FROM ' . self::ISSUE_TAGS_TABLE . " WHERE USER_ID = {$this->userId} AND ISSUE_KEY = '{$safeKey}' ORDER BY TAG_NAME"
        );
        while ($row = $result->fetch()) {
            $tags[] = (string) $row['TAG_NAME'];
        }
        return $tags;
    }

    /** @param list<array{text: string, week: string, createdAt: string}> $entries */
    private function appendEntry(array &$entries, string $text, string $week, string $createdAt): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > 10000) {
            throw new \InvalidArgumentException('Текст действия или факта не должен превышать 10000 символов.');
        }
        $week = strtoupper(trim($week));
        if (!preg_match('/^\d{4}-W(?:0[1-9]|[1-4]\d|5[0-3])$/', $week)) {
            throw new \InvalidArgumentException('Для действия или факта укажите корректную неделю.');
        }
        $entries[] = ['text' => $text, 'week' => $week, 'createdAt' => $createdAt];
    }

    /** @return list<string> */
    private function validateTags(mixed $tags): array
    {
        if (!is_array($tags) || count($tags) > 50) {
            throw new \InvalidArgumentException('Некорректный список тегов.');
        }
        $validated = [];
        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            if ($tag === '' || mb_strlen($tag) > 64) {
                throw new \InvalidArgumentException('Название тега должно содержать от 1 до 64 символов.');
            }
            $validated[mb_strtolower($tag)] = $tag;
        }

        return array_values($validated);
    }

    /** @return list<array{text: string, week: string, createdAt: string}> */
    private function validateEntries(mixed $entries, array $currentEntries): array
    {
        if (!is_array($entries)) {
            throw new \InvalidArgumentException('Некорректная история действий или фактов.');
        }

        $validated = [];
        $knownDates = array_column($currentEntries, 'createdAt');
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $text = trim((string) ($entry['text'] ?? ''));
            if ($text === '') {
                continue;
            }
            if (mb_strlen($text) > 10000) {
                throw new \InvalidArgumentException('Текст действия или факта не должен превышать 10000 символов.');
            }
            $createdAt = (string) ($entry['createdAt'] ?? '');
            if ($createdAt === '' || !in_array($createdAt, $knownDates, true)) {
                throw new \InvalidArgumentException('Некорректная дата действия или факта.');
            }
            $week = strtoupper(trim((string) ($entry['week'] ?? '')));
            if ($week === '') {
                $week = (new \DateTimeImmutable($createdAt))->format('o-\WW');
            }
            if (!preg_match('/^\d{4}-W(?:0[1-9]|[1-4]\d|5[0-3])$/', $week)) {
                throw new \InvalidArgumentException('Некорректная неделя действия или факта.');
            }
            $validated[] = ['text' => $text, 'week' => $week, 'createdAt' => $createdAt];
        }

        return $validated;
    }

    /** @param array<string, mixed> $row
     *  @return array<string, mixed>
     */
    private function normaliseRow(array $row): array
    {
        return [
            'issueKey' => (string) $row['ISSUE_KEY'],
            'bucket' => $this->isValidBucket((string) $row['BUCKET']) ? (string) $row['BUCKET'] : 'deferred',
            'complexity' => in_array($row['COMPLEXITY'] ?? '', self::COMPLEXITIES, true) ? $row['COMPLEXITY'] : 'low',
            'plannedWeek' => (string) ($row['PLANNED_WEEK'] ?? ''),
            'completedWeek' => (string) ($row['COMPLETED_WEEK'] ?? ''),
            'tags' => $this->decodeTags((string) ($row['TAGS'] ?? '')),
            'shortDescription' => (string) ($row['SHORT_DESCRIPTION'] ?? ''),
            'plannedActions' => $this->decodeEntries((string) ($row['PLANNED_ACTIONS'] ?? '')),
            'facts' => $this->decodeEntries((string) ($row['FACTS'] ?? '')),
            'updatedAt' => isset($row['UPDATED_AT']) ? gmdate(DATE_ATOM, strtotime((string) $row['UPDATED_AT'] . ' UTC')) : null,
        ];
    }

    /** @return list<array<string, string>> */
    private function decodeEntries(string $json): array
    {
        $entries = json_decode($json, true);
        if (!is_array($entries)) {
            return [];
        }
        return array_values(array_map(static function (array $entry): array {
            if (empty($entry['week']) && !empty($entry['createdAt'])) {
                $entry['week'] = (new \DateTimeImmutable((string) $entry['createdAt']))->format('o-\WW');
            }
            return $entry;
        }, array_filter($entries, 'is_array')));
    }

    /** @return list<string> */
    private function decodeTags(string $json): array
    {
        $tags = json_decode($json, true);
        return is_array($tags) ? array_values(array_filter($tags, 'is_string')) : [];
    }
}
