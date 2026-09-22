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
    public function createIssue(string $projectKey, string $issueType, string $summary, string $description, ?string $dueDate): array
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

        $created = $this->request('POST', '/rest/api/2/issue', [], ['fields' => $fields]);
        $key = (string) ($created['key'] ?? '');
        if ($key === '') {
            throw new RuntimeException('Jira не вернула ключ созданной заявки.');
        }

        return ['key' => $key, 'url' => $this->config['base_url'] . '/browse/' . rawurlencode($key)];
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
            'url' => $this->config['base_url'] . '/browse/' . rawurlencode((string) ($issue['key'] ?? '')),
        ];
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
