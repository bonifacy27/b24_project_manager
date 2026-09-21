<?php

declare(strict_types=1);

namespace ProjectManager;

use JsonException;
use RuntimeException;

final class JiraClient
{
    public function __construct(private readonly array $config)
    {
        foreach (['base_url', 'username', 'password'] as $key) {
            if (empty($this->config[$key])) {
                throw new RuntimeException('Интеграция с Jira не настроена. Укажите адрес, логин и пароль в config.php.');
            }
        }
    }

    /** @return array{issues: list<array<string, mixed>>, total: int} */
    public function search(string $jql, int $maxResults = 100): array
    {
        $response = $this->request('GET', '/rest/api/2/search', [
            'jql' => $jql,
            'startAt' => 0,
            'maxResults' => min(max($maxResults, 1), 100),
            'fields' => 'summary,status,priority,assignee,created,updated,duedate,issuetype,project,comment,issuelinks',
        ]);

        return [
            'issues' => array_map(fn (array $issue): array => $this->normaliseIssue($issue), $response['issues'] ?? []),
            'total' => (int) ($response['total'] ?? 0),
        ];
    }

    /** @return array<string, mixed> */
    private function request(string $method, string $path, array $query = []): array
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
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => (int) ($this->config['timeout'] ?? 20),
            CURLOPT_SSL_VERIFYPEER => (bool) ($this->config['verify_ssl'] ?? true),
        ]);

        $body = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $curlError = curl_error($handle);
        curl_close($handle);

        if ($body === false) {
            throw new RuntimeException('Не удалось подключиться к Jira: ' . $curlError);
        }
        if ($status === 401 || $status === 403) {
            throw new RuntimeException('Jira отклонила учётные данные интеграции.', $status);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Jira вернула ошибку HTTP ' . $status . '.', $status);
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
            'status' => (string) ($fields['status']['name'] ?? 'Без статуса'),
            'statusCategory' => (string) ($fields['status']['statusCategory']['key'] ?? ''),
            'priority' => (string) ($fields['priority']['name'] ?? 'Без приоритета'),
            'assignee' => $assignee ? [
                'name' => (string) ($assignee['displayName'] ?? $assignee['name'] ?? ''),
                'avatar' => (string) ($assignee['avatarUrls']['48x48'] ?? ''),
            ] : null,
            'created' => (string) ($fields['created'] ?? ''),
            'updated' => (string) ($fields['updated'] ?? ''),
            'dueDate' => $fields['duedate'] ?? null,
            'type' => (string) ($fields['issuetype']['name'] ?? ''),
            'comments' => (int) ($fields['comment']['total'] ?? 0),
            'url' => $this->config['base_url'] . '/browse/' . rawurlencode((string) ($issue['key'] ?? '')),
        ];
    }
}
