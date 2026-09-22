<?php

declare(strict_types=1);

namespace ProjectManager;

use Bitrix\Main\Application;
use RuntimeException;

final class IssueMetadataStore
{
    private const TABLE = 'b_project_manager_issue_meta';
    private const BUCKETS = ['backlog', 'week', 'next', 'completed', 'year', 'next_year'];
    private const COMPLEXITIES = ['low', 'medium', 'high'];

    private readonly object $connection;

    public function __construct()
    {
        if (!class_exists(Application::class)) {
            throw new RuntimeException('Ядро Битрикс недоступно для хранения плана заявок.');
        }

        $this->connection = Application::getConnection();
        $this->connection->queryExecute(
            'CREATE TABLE IF NOT EXISTS ' . self::TABLE . ' (' .
            ' ISSUE_KEY varchar(64) NOT NULL,' .
            ' BUCKET varchar(32) NOT NULL DEFAULT \'backlog\',' .
            ' COMPLEXITY varchar(16) NOT NULL DEFAULT \'medium\',' .
            ' SHORT_DESCRIPTION text NULL,' .
            ' PLANNED_ACTIONS text NULL,' .
            ' FACTS text NULL,' .
            ' UPDATED_AT datetime NOT NULL,' .
            ' PRIMARY KEY (ISSUE_KEY)' .
            ')'
        );
        $complexityColumn = $this->connection->query("SHOW COLUMNS FROM " . self::TABLE . " LIKE 'COMPLEXITY'")->fetch();
        if (!$complexityColumn) {
            $this->connection->queryExecute(
                "ALTER TABLE " . self::TABLE . " ADD COMPLEXITY varchar(16) NOT NULL DEFAULT 'medium' AFTER BUCKET"
            );
        }
    }

    /** @return array<string, array<string, mixed>> */
    public function all(): array
    {
        $items = [];
        $result = $this->connection->query('SELECT * FROM ' . self::TABLE);
        while ($row = $result->fetch()) {
            $item = $this->normaliseRow($row);
            $items[$item['issueKey']] = $item;
        }

        return $items;
    }

    /** @param array<string, mixed> $input
     *  @return array<string, mixed>
     */
    public function save(string $issueKey, array $input): array
    {
        $issueKey = strtoupper(trim($issueKey));
        $bucket = strtolower(trim((string) ($input['bucket'] ?? '')));
        if (!preg_match('/^[A-Z][A-Z0-9_]+-\d+$/', $issueKey) || !in_array($bucket, self::BUCKETS, true)) {
            throw new \InvalidArgumentException('Неверно указаны ключ Jira или категория.');
        }

        $current = $this->find($issueKey);
        $complexity = array_key_exists('complexity', $input)
            ? strtolower(trim((string) $input['complexity']))
            : (string) ($current['complexity'] ?? 'medium');
        if (!in_array($complexity, self::COMPLEXITIES, true)) {
            throw new \InvalidArgumentException('Неверно указана сложность заявки.');
        }
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
        $this->appendEntry($plannedActions, (string) ($input['plannedAction'] ?? ''), $now);
        $this->appendEntry($facts, (string) ($input['fact'] ?? ''), $now);

        $sql = $this->connection->getSqlHelper();
        $values = [
            'key' => $sql->forSql($issueKey, 64),
            'bucket' => $sql->forSql($bucket, 32),
            'complexity' => $sql->forSql($complexity, 16),
            'description' => $sql->forSql($shortDescription),
            'actions' => $sql->forSql((string) json_encode($plannedActions, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'facts' => $sql->forSql((string) json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'updated' => $sql->forSql(gmdate('Y-m-d H:i:s')),
        ];
        $this->connection->queryExecute(
            "INSERT INTO " . self::TABLE . " (ISSUE_KEY, BUCKET, COMPLEXITY, SHORT_DESCRIPTION, PLANNED_ACTIONS, FACTS, UPDATED_AT) VALUES " .
            "('{$values['key']}', '{$values['bucket']}', '{$values['complexity']}', '{$values['description']}', '{$values['actions']}', '{$values['facts']}', '{$values['updated']}') " .
            "ON DUPLICATE KEY UPDATE BUCKET=VALUES(BUCKET), COMPLEXITY=VALUES(COMPLEXITY), SHORT_DESCRIPTION=VALUES(SHORT_DESCRIPTION), " .
            "PLANNED_ACTIONS=VALUES(PLANNED_ACTIONS), FACTS=VALUES(FACTS), UPDATED_AT=VALUES(UPDATED_AT)"
        );

        return $this->find($issueKey) ?? [];
    }

    /** @return array<string, mixed>|null */
    private function find(string $issueKey): ?array
    {
        $safeKey = $this->connection->getSqlHelper()->forSql($issueKey, 64);
        $row = $this->connection->query("SELECT * FROM " . self::TABLE . " WHERE ISSUE_KEY = '{$safeKey}'")->fetch();
        return $row ? $this->normaliseRow($row) : null;
    }

    /** @param list<array<string, string>> $entries */
    private function appendEntry(array &$entries, string $text, string $createdAt): void
    {
        $text = trim($text);
        if ($text === '') {
            return;
        }
        if (mb_strlen($text) > 10000) {
            throw new \InvalidArgumentException('Текст действия или факта не должен превышать 10000 символов.');
        }
        $entries[] = ['text' => $text, 'createdAt' => $createdAt];
    }

    /** @return list<array{text: string, createdAt: string}> */
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
            $validated[] = ['text' => $text, 'createdAt' => $createdAt];
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
            'bucket' => in_array($row['BUCKET'], self::BUCKETS, true) ? $row['BUCKET'] : 'backlog',
            'complexity' => in_array($row['COMPLEXITY'] ?? '', self::COMPLEXITIES, true) ? $row['COMPLEXITY'] : 'medium',
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
        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }
}
