<?php

declare(strict_types=1);

/** Значения PROJECT_MANAGER_JIRA_* задаются в конфигурации Битрикс24. */
$setting = static function (string $constant, string $environment = ''): string {
    if (defined($constant)) {
        return (string) constant($constant);
    }

    return $environment !== '' ? (string) getenv($environment) : '';
};

return [
    'jira' => [
        'base_url' => rtrim($setting('PROJECT_MANAGER_JIRA_URL', 'JIRA_BASE_URL'), '/'),
        'username' => $setting('PROJECT_MANAGER_JIRA_LOGIN', 'JIRA_USERNAME'),
        'password' => $setting('PROJECT_MANAGER_JIRA_PASSWORD', 'JIRA_PASSWORD'),
        'timeout' => 20,
        'verify_ssl' => filter_var(getenv('JIRA_VERIFY_SSL') ?: 'true', FILTER_VALIDATE_BOOL),
        'jql' => [
            'ods' => $setting('PROJECT_MANAGER_ODS_JQL') ?: 'project = ODS AND assignee = currentUser() AND status not in (Done, Closed, "Выполнено", "Закрыто") ORDER BY updated DESC',
            'ssis' => $setting('PROJECT_MANAGER_SSIS_JQL') ?: 'status not in (Done, Closed, "Выполнено", "Закрыто") AND ("Customer Request Type" in ("Корпоративный портал_OLD", "Корпоративный портал (SSIS)") OR cf[10866] = "Корпоративный портал Битрикс24 (СУРВ)") ORDER BY created DESC, updated DESC',
        ],
    ],
];
