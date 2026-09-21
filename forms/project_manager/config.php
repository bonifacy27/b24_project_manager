<?php

declare(strict_types=1);

/**
 * Loads a small, dependency-free .env file. Values already provided by the
 * web server take precedence, which is preferable in production.
 */
function loadProjectManagerEnv(string $path): void
{
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }

        [$name, $value] = array_map('trim', explode('=', $line, 2));
        if (getenv($name) !== false) {
            continue;
        }

        if (strlen($value) >= 2 && $value[0] === $value[-1] && in_array($value[0], ['"', "'"], true)) {
            $value = substr($value, 1, -1);
        }
        putenv($name . '=' . $value);
    }
}

loadProjectManagerEnv(__DIR__ . '/.env');

return [
    'jira' => [
        'base_url' => rtrim((string) getenv('JIRA_BASE_URL'), '/'),
        'username' => (string) getenv('JIRA_USERNAME'),
        'password' => (string) getenv('JIRA_PASSWORD'),
        'timeout' => 20,
        'verify_ssl' => filter_var(getenv('JIRA_VERIFY_SSL') ?: 'true', FILTER_VALIDATE_BOOL),
        'jql' => [
            'ods' => getenv('JIRA_ODS_JQL') ?: 'project = ODS AND assignee = currentUser() AND status not in (Done, Closed, "Выполнено", "Закрыто") ORDER BY updated DESC',
            'ssis' => getenv('JIRA_SSIS_JQL') ?: 'status not in (Done, Closed, "Выполнено", "Закрыто") AND ("Customer Request Type" in ("Корпоративный портал_OLD", "Корпоративный портал (SSIS)") OR cf[10866] = "Корпоративный портал Битрикс24 (СУРВ)") ORDER BY created DESC, updated DESC',
        ],
    ],
];
