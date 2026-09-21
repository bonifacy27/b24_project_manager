<?php

declare(strict_types=1);

return [
    'jira' => [
        'base_url' => 'https://jira.tricolor.tv',
        'username' => 'jiraService',
        // Укажите пароль пользователя jiraService между кавычками.
        'password' => getenv('JIRA_PASSWORD') ?: '',
        'timeout' => 20,
        'verify_ssl' => true,
        'jql' => [
            'ods' => 'project = ODS AND assignee = currentUser() AND status not in (Done, Closed, "Выполнено", "Закрыто") ORDER BY updated DESC',
            'ssis' => 'status not in (Done, Closed, "Выполнено", "Закрыто") AND ("Customer Request Type" in ("Корпоративный портал_OLD", "Корпоративный портал (SSIS)") OR cf[10866] = "Корпоративный портал Битрикс24 (СУРВ)") ORDER BY created DESC, updated DESC',
        ],
        'projects' => [
            'ods' => ['key' => 'ODS', 'issue_type' => 'Задача'],
            'ssis' => ['key' => 'SSIS', 'issue_type' => 'Задача'],
        ],
    ],
];
