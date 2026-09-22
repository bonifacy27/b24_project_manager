<?php

declare(strict_types=1);

namespace ProjectManager;

use JsonException;
use RuntimeException;

final class JiraClient
{
    /** @var array<string, mixed> */
    private array $diagnostics = [];

    public function __construct(private readonly array $config)
    {
        if (!empty($this->config['credentials_error'])) {
            throw new RuntimeException('Не удалось получить учётные данные Jira: ' . $this->config['credentials_error']);
        }
        foreach (['base_url', 'username', 'password'] as $key) {
            if (empty($this->config[$key])) {
                throw new RuntimeException('Интеграция с Jira не настроена: отсутствует параметр ' . $key . '.');
            }
        }
        $password = (string) $this->config['password'];
        $this->diagnostics = [
            'baseUrl' => (string) $this->config['base_url'],
            'username' => (string) $this->config['username'],
            // Never send a reusable password to a browser. Length and a short
            // fingerprint are enough to verify which constant was decoded.
            'password' => str_repeat('•', min(strlen($password), 12)),
            'passwordLength' => strlen($password),
            'passwordFingerprint' => substr(hash('sha256', $password), 0, 12),
        ];
    }

    /** @return array<string, mixed> */
    public function getDiagnostics(): array
    {
        return $this->diagnostics;
    }

    /** @return array{issues: list<array<string, mixed>>, total: int} */
    public function search(string $jql, int $pageSize = 100): array
    {
        $pageSize = min(max($pageSize, 1), 100);
        $startAt = 0;
        $total = 0;
        $issues = [];

        do {
            $response = $this->request('GET', '/rest/api/2/search', [
                'jql' => $jql,
                'startAt' => $startAt,
                'maxResults' => $pageSize,
                'fields' => 'summary,description,status,priority,assignee,created,updated,resolutiondate,duedate,issuetype,project,comment,issuelinks',
            ]);
            $page = is_array($response['issues'] ?? null) ? $response['issues'] : [];
            $issues = [...$issues, ...$page];
            $total = (int) ($response['total'] ?? count($issues));
            $startAt += count($page);
        } while ($page !== [] && $startAt < $total);

        return [
            'issues' => array_map(fn (array $issue): array => $this->normaliseIssue($issue), $issues),
            'total' => $total,
        ];
    }

    /** @return array<string, mixed> */
    public function createIssue(
        string $projectKey,
        string $issueType,
        string $summary,
        string $description,
        ?string $dueDate,
        ?string $componentId = null,
        ?string $fixVersionId = null,
        ?string $reporter = null,
        ?string $assignee = null
    ): array
    {
        $fields = [
            'project' => ['key' => $projectKey],
            'issuetype' => ['name' => $issueType],
            'summary' => $summary,
            'description' => $description,
        ];
        if ($dueDate !== null) {
            $fields['duedate'] = $dueDate;
        }
        if ($componentId !== null) {
            $fields['components'] = [['id' => $componentId]];
        }
        if ($fixVersionId !== null) {
            $fields['fixVersions'] = [['id' => $fixVersionId]];
        }
        if ($reporter !== null) {
            $fields['reporter'] = ['name' => $reporter];
        }
        if ($assignee !== null) {
            $fields['assignee'] = ['name' => $assignee];
        }

        $created = $this->request('POST', '/rest/api/2/issue', [], ['fields' => $fields]);
        $key = (string) ($created['key'] ?? '');
        if ($key === '') {
            throw new RuntimeException('Jira не вернула ключ созданной заявки.');
        }

        return ['key' => $key, 'url' => $this->config['base_url'] . '/browse/' . rawurlencode($key)];
    }

    /** @return array{components: list<array{id: string, name: string}>, versions: list<array{id: string, name: string}>} */
    public function getCreateOptions(string $projectKey): array
    {
        $encodedKey = rawurlencode($projectKey);
        $components = $this->request('GET', '/rest/api/2/project/' . $encodedKey . '/components');
        $versions = $this->request('GET', '/rest/api/2/project/' . $encodedKey . '/versions');

        $normalise = static fn (array $item): array => [
            'id' => (string) ($item['id'] ?? ''),
            'name' => (string) ($item['name'] ?? ''),
        ];
        $availableVersions = array_filter(
            $versions,
            static fn (mixed $version): bool => is_array($version)
                && empty($version['archived'])
                && empty($version['released'])
        );

        return [
            'components' => array_values(array_map($normalise, array_filter($components, 'is_array'))),
            'versions' => array_values(array_map($normalise, $availableVersions)),
        ];
    }

    /** @return list<array<string, string>> */
    public function getLinkedIssues(string $issueKey): array
    {
        $issueKey = strtoupper(trim($issueKey));
        if (!preg_match('/^[A-Z][A-Z0-9_]+-\d+$/', $issueKey)) {
            throw new \InvalidArgumentException('Неверно указан ключ Jira.');
        }
        $issue = $this->request(
            'GET',
            '/rest/api/2/issue/' . rawurlencode($issueKey),
            ['fields' => 'issuelinks']
        );

        return $this->normaliseLinks($issue['fields']['issuelinks'] ?? []);
    }

    /** @return array{key: string, status: string} */
    public function completeIssue(string $issueKey, string $comment): array
    {
        $issueKey = strtoupper(trim($issueKey));
        $comment = trim($comment);
        if (!preg_match('/^[A-Z][A-Z0-9_]+-\d+$/', $issueKey)) {
            throw new \InvalidArgumentException('Неверно указан ключ Jira.');
        }
        if ($comment === '' || mb_strlen($comment) > 10000) {
            throw new \InvalidArgumentException('Для выполнения заявки укажите комментарий длиной до 10000 символов.');
        }

        $issue = $this->request('GET', '/rest/api/2/issue/' . rawurlencode($issueKey), ['fields' => 'status']);
        $currentStatus = (string) ($issue['fields']['status']['name'] ?? '');
        if (!in_array(mb_strtolower(trim($currentStatus)), ['уточнение информации', 'взят в работу'], true)) {
            throw new \InvalidArgumentException(
                'Выполнить заявку можно только из статусов «Уточнение информации» или «Взят в работу».'
            );
        }

        $response = $this->request('GET', '/rest/api/2/issue/' . rawurlencode($issueKey) . '/transitions', ['expand' => 'transitions.fields']);
        $transitions = is_array($response['transitions'] ?? null) ? $response['transitions'] : [];
        $transition = null;
        foreach ($transitions as $candidate) {
            $targetName = (string) ($candidate['to']['name'] ?? '');
            $category = (string) ($candidate['to']['statusCategory']['key'] ?? '');
            if ($category === 'done' || preg_match('/выполн|закры|решен/iu', $targetName)) {
                $transition = $candidate;
                break;
            }
        }
        $transitionId = (string) ($transition['id'] ?? '');
        if ($transitionId === '') {
            throw new RuntimeException('Для заявки Jira не найден доступный переход в выполненный статус.');
        }

        $this->request('POST', '/rest/api/2/issue/' . rawurlencode($issueKey) . '/transitions', [], [
            'transition' => ['id' => $transitionId],
            'update' => ['comment' => [['add' => ['body' => $comment]]]],
        ]);

        return ['key' => $issueKey, 'status' => (string) ($transition['to']['name'] ?? 'Выполнено')];
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $query = [], ?array $payload = null): array
    {
        $url = $this->config['base_url'] . $path;
        if ($query !== []) {
            $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        }

        $handle = curl_init($url);
        curl_setopt_array($handle, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
            CURLOPT_USERPWD => $this->config['username'] . ':' . $this->config['password'],
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 20),
            CURLOPT_SSL_VERIFYPEER => (bool) ($this->config['verify_ssl'] ?? true),
        ]);
        if ($payload !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        }

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $contentType = (string) curl_getinfo($handle, CURLINFO_CONTENT_TYPE);
        $curlError = curl_error($handle);
        curl_close($handle);

        $responseBody = (string) $body;
        foreach ([(string) $this->config['username'], (string) $this->config['password']] as $secret) {
            if ($secret !== '') {
                $responseBody = str_replace($secret, '[hidden]', $responseBody);
            }
        }
        $this->diagnostics = array_merge($this->diagnostics, [
            'method' => $method,
            'url' => $url,
            'httpStatus' => $status,
            'contentType' => $contentType,
            'curlError' => $curlError,
            'jiraResponse' => mb_substr($responseBody, 0, 4000),
        ]);

        if ($body === false) {
            throw new RuntimeException('Не удалось подключиться к Jira: ' . $curlError);
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Jira отклонила учётные данные интеграции.', $status);
        }
        if ($status < 200 || $status >= 300) {
            $details = json_decode((string) $body, true);
            $messages = array_merge($details['errorMessages'] ?? [], array_values($details['errors'] ?? []));
            $suffix = $messages ? ': ' . implode('; ', array_map('strval', $messages)) : '';
            throw new RuntimeException('Jira вернула ошибку HTTP ' . $status . $suffix . '.', $status);
        }
        if (trim((string) $body) === '') {
            return [];
        }

        try {
            return json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('Jira вернула некорректный JSON.', 0, $exception);
        }
    }

    /** @return array<string, mixed> */
    private function normaliseIssue(array $issue): array
    {
        $fields = $issue['fields'] ?? [];
        $assignee = $fields['assignee'] ?? null;

        return [
            'key' => (string) ($issue['key'] ?? ''),
            'project' => strtolower((string) ($fields['project']['key'] ?? strtok((string) ($issue['key'] ?? ''), '-'))),
            'summary' => (string) ($fields['summary'] ?? 'Без названия'),
            'description' => $this->descriptionToText($fields['description'] ?? ''),
            'status' => (string) ($fields['status']['name'] ?? 'Без статуса'),
            'statusCategory' => (string) ($fields['status']['statusCategory']['key'] ?? ''),
            'priority' => (string) ($fields['priority']['name'] ?? 'Без приоритета'),
            'assignee' => $assignee ? [
                'name' => (string) ($assignee['displayName'] ?? $assignee['name'] ?? ''),
                'avatar' => (string) ($assignee['avatarUrls']['48x48'] ?? ''),
            ] : null,
            'created' => (string) ($fields['created'] ?? ''),
            'updated' => (string) ($fields['updated'] ?? ''),
            'resolved' => (string) ($fields['resolutiondate'] ?? ''),
            'dueDate' => $fields['duedate'] ?? null,
            'type' => (string) ($fields['issuetype']['name'] ?? ''),
            'comments' => (int) ($fields['comment']['total'] ?? 0),
            'linkedIssues' => $this->normaliseLinks($fields['issuelinks'] ?? []),
            'url' => $this->config['base_url'] . '/browse/' . rawurlencode((string) ($issue['key'] ?? '')),
        ];
    }

    /** @return list<array<string, string>> */
    private function normaliseLinks(mixed $links): array
    {
        if (!is_array($links)) {
            return [];
        }
        $normalised = [];
        foreach ($links as $link) {
            if (!is_array($link)) {
                continue;
            }
            $isOutward = isset($link['outwardIssue']);
            $linkedIssue = $isOutward ? ($link['outwardIssue'] ?? []) : ($link['inwardIssue'] ?? []);
            if (!is_array($linkedIssue) || empty($linkedIssue['key'])) {
                continue;
            }
            $linkedFields = $linkedIssue['fields'] ?? [];
            $linkType = is_array($link['type'] ?? null) ? $link['type'] : [];
            $normalised[] = [
                'key' => (string) $linkedIssue['key'],
                'summary' => (string) ($linkedFields['summary'] ?? ''),
                'status' => (string) ($linkedFields['status']['name'] ?? ''),
                'relationship' => (string) ($linkType[$isOutward ? 'outward' : 'inward'] ?? $linkType['name'] ?? ''),
                'url' => $this->config['base_url'] . '/browse/' . rawurlencode((string) $linkedIssue['key']),
            ];
        }

        return $normalised;
    }

    private function descriptionToText(mixed $description): string
    {
        if (is_string($description)) {
            return $description;
        }
        if (!is_array($description)) {
            return '';
        }

        $parts = [];
        $collect = function (array $node) use (&$collect, &$parts): void {
            if (isset($node['text']) && is_string($node['text'])) {
                $parts[] = $node['text'];
            }
            foreach ($node['content'] ?? [] as $child) {
                if (is_array($child)) {
                    $collect($child);
                }
            }
            if (in_array($node['type'] ?? '', ['paragraph', 'heading', 'listItem'], true)) {
                $parts[] = "\n";
            }
        };
        $collect($description);

        return trim(preg_replace('/[ \t]*\n[ \t]*/u', "\n", implode('', $parts)) ?? '');
    }
}
