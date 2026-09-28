<?php

declare(strict_types=1);

use ProjectManager\JiraClient;

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

// Some Bitrix installations print service markup while loading the prolog.
// It must never become part of an API response.
ob_start();

$bitrixBootstrap = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/modules/main/include/prolog_before.php';
if (is_file($bitrixBootstrap)) {
    require_once $bitrixBootstrap;
}
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (isset($USER) && is_object($USER) && !$USER->IsAuthorized()) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация в Битрикс24.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST', 'PATCH'], true)) {
    http_response_code(405);
    header('Allow: GET, POST, PATCH');
    echo json_encode(['error' => 'Метод не поддерживается.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/JiraClient.php';
require_once dirname(__DIR__) . '/src/IssueMetadataStore.php';

$project = strtolower((string) ($_GET['project'] ?? 'all'));
if (!in_array($project, ['all', 'ods', 'ssis', 'ditsd'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Неизвестный проект.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$client = null;
try {
    $currentUserId = isset($USER) && is_object($USER) && method_exists($USER, 'GetID') ? (int) $USER->GetID() : 0;
    if ($currentUserId < 1) {
        throw new RuntimeException('Не удалось определить пользователя Битрикс24.');
    }
    $metadataStore = new ProjectManager\IssueMetadataStore($currentUserId);
    if ($_SERVER['REQUEST_METHOD'] === 'PATCH') {
        $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        if (function_exists('bitrix_sessid') && !hash_equals((string) bitrix_sessid(), (string) ($input['sessid'] ?? ''))) {
            throw new InvalidArgumentException('Сессия истекла. Обновите страницу.');
        }
        if (($input['action'] ?? '') === 'saveProject') {
            $savedProject = $metadataStore->saveProject(
                isset($input['projectId']) && $input['projectId'] !== '' ? (int) $input['projectId'] : null,
                (string) ($input['name'] ?? ''),
                (string) ($input['description'] ?? ''),
                (int) ($input['year'] ?? 0),
                is_array($input['issueKeys'] ?? null) ? $input['issueKeys'] : []
            );
            echo json_encode(['project' => $savedProject], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            exit;
        }
        $metadata = $metadataStore->save((string) ($input['issueKey'] ?? ''), is_array($input) ? $input : []);
        if (array_key_exists('projectIds', $input)) {
            $metadataStore->setIssueProjects(
                (string) ($input['issueKey'] ?? ''),
                is_array($input['projectIds']) ? $input['projectIds'] : []
            );
        }
        echo json_encode(
            ['metadata' => $metadata, 'localProjects' => $metadataStore->projects()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        exit;
    }
    $client = new JiraClient($config['jira']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        if (function_exists('bitrix_sessid') && !hash_equals((string) bitrix_sessid(), (string) ($input['sessid'] ?? ''))) {
            throw new InvalidArgumentException('Сессия истекла. Обновите страницу.');
        }
        if (($input['action'] ?? '') === 'complete') {
            $comment = trim((string) ($input['comment'] ?? ''));
            $currentUserLogin = isset($USER) && is_object($USER) && method_exists($USER, 'GetLogin')
                ? trim((string) $USER->GetLogin())
                : '';
            if ($currentUserLogin === '') {
                throw new RuntimeException('Не удалось определить пользователя, выполняющего заявку.');
            }
            $requestedLinkedKeys = is_array($input['linkedIssueKeys'] ?? null)
                ? array_values(array_unique(array_map('strval', $input['linkedIssueKeys'])))
                : [];
            if (count($requestedLinkedKeys) > 20) {
                throw new InvalidArgumentException('Можно выполнить не более 20 связанных заявок за один раз.');
            }
            $actualLinkedKeys = array_column(
                $client->getLinkedIssues((string) ($input['issueKey'] ?? '')),
                'key'
            );
            foreach ($requestedLinkedKeys as $linkedKey) {
                if (!in_array($linkedKey, $actualLinkedKeys, true)) {
                    throw new InvalidArgumentException('Выбрана заявка, которая не связана с основной заявкой Jira.');
                }
            }
            $issue = $client->completeIssue((string) ($input['issueKey'] ?? ''), $comment, $currentUserLogin);
            $metadata = $metadataStore->save($issue['key'], [
                'bucket' => 'completed',
                'fact' => $currentUserLogin . ': ' . $comment,
                'factWeek' => (new DateTimeImmutable('today'))->format('o-\WW'),
            ]);
            $linkedResults = [];
            foreach ($requestedLinkedKeys as $linkedKey) {
                try {
                    $linkedIssue = $client->completeIssue($linkedKey, $comment, $currentUserLogin);
                    $metadataStore->save($linkedIssue['key'], [
                        'bucket' => 'completed',
                        'fact' => $currentUserLogin . ': ' . $comment,
                        'factWeek' => (new DateTimeImmutable('today'))->format('o-\WW'),
                    ]);
                    $linkedResults[] = $linkedIssue + ['error' => ''];
                } catch (Throwable $linkedException) {
                    $linkedResults[] = ['key' => $linkedKey, 'status' => '', 'error' => $linkedException->getMessage()];
                }
            }
            echo json_encode(
                ['issue' => $issue, 'metadata' => $metadata, 'linkedIssues' => $linkedResults],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            );
            exit;
        }
        $project = strtolower(trim((string) ($input['project'] ?? '')));
        $summary = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $complexity = strtolower(trim((string) ($input['complexity'] ?? '')));
        $componentId = trim((string) ($input['componentId'] ?? ''));
        $fixVersionId = trim((string) ($input['fixVersionId'] ?? ''));
        $plan = strtolower((string) ($input['plan'] ?? 'later'));
        if (!isset($config['jira']['projects'][$project]) || !in_array($plan, ['week', 'next', 'later'], true)) {
            throw new InvalidArgumentException('Неверно указан проект или план.');
        }
        if ($summary === '' || mb_strlen($summary) > 255 || mb_strlen($description) > 10000) {
            throw new InvalidArgumentException('Название обязательно и должно быть короче 256 символов.');
        }
        if (!in_array($complexity, ['low', 'medium', 'high', 'maximum'], true)) {
            throw new InvalidArgumentException('Неверно указана сложность заявки.');
        }
        $due = new DateTimeImmutable('today');
        $daysUntilSunday = 7 - (int) $due->format('N');
        $dueDate = match ($plan) {
            'week' => $due->modify('+' . $daysUntilSunday . ' days')->format('Y-m-d'),
            'next' => $due->modify('+' . ($daysUntilSunday + 7) . ' days')->format('Y-m-d'),
            default => null,
        };
        $settings = $config['jira']['projects'][$project];
        if (!empty($settings['read_only'])) {
            throw new InvalidArgumentException('Создание заявок для этого проекта не поддерживается.');
        }
        $requiredFields = $settings['required_create_fields'] ?? [];
        if ((in_array('component', $requiredFields, true) && $componentId === '')
            || (in_array('fixVersion', $requiredFields, true) && $fixVersionId === '')) {
            throw new InvalidArgumentException('Для проекта ODS выберите компонент и версию исправления.');
        }
        foreach ([$componentId, $fixVersionId] as $jiraId) {
            if ($jiraId !== '' && !ctype_digit($jiraId)) {
                throw new InvalidArgumentException('Некорректно выбраны компонент или версия исправления.');
            }
        }
        $jiraCurrentUser = null;
        if (!empty($settings['use_current_user'])) {
            $jiraCurrentUser = isset($USER) && is_object($USER) && method_exists($USER, 'GetLogin')
                ? trim((string) $USER->GetLogin())
                : '';
            if ($jiraCurrentUser === '') {
                throw new RuntimeException('Не удалось определить пользователя для автора и исполнителя ODS.');
            }
        }
        $issue = $client->createIssue(
            $settings['key'],
            $settings['issue_type'],
            $summary,
            $description,
            $dueDate,
            $componentId !== '' ? $componentId : null,
            $fixVersionId !== '' ? $fixVersionId : null,
            $jiraCurrentUser,
            $jiraCurrentUser
        );
        $metadataStore->save($issue['key'], ['bucket' => 'backlog', 'complexity' => $complexity]);
        http_response_code(201);
        echo json_encode(['issue' => $issue], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
    $projects = $project === 'all' ? ['ods', 'ssis', 'ditsd'] : [$project];
    $issues = [];
    $totals = [];
    $creationOptions = [];
    $currentUserLogin = isset($USER) && is_object($USER) && method_exists($USER, 'GetLogin')
        ? trim((string) $USER->GetLogin())
        : '';
    if ($currentUserLogin === '') {
        throw new RuntimeException('Не удалось определить LOGIN текущего пользователя Битрикс24.');
    }
    $jiraAssignee = '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $currentUserLogin) . '"';

    $reportMonth = trim((string) ($_GET['report_month'] ?? ''));
    if ($reportMonth !== '') {
        if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $reportMonth)) {
            throw new InvalidArgumentException('Некорректно указан месяц отчёта.');
        }
        $monthStart = new DateTimeImmutable($reportMonth . '-01');
        $monthEnd = $monthStart->modify('+1 month');
        foreach (array_keys($config['jira']['projects']) as $projectKey) {
            $jql = sprintf(
                'project = %s AND assignee = %s AND resolved >= "%s" AND resolved < "%s" ORDER BY resolved DESC',
                strtoupper($projectKey), $jiraAssignee, $monthStart->format('Y-m-d'), $monthEnd->format('Y-m-d')
            );
            $result = $client->search($jql);
            $issues = [...$issues, ...$result['issues']];
        }
        $metadata = $metadataStore->all();
        $issues = array_map(static function (array $issue) use ($metadata): array {
            $issue['metadata'] = $metadata[$issue['key']] ?? [
                'issueKey' => $issue['key'], 'bucket' => 'completed', 'shortDescription' => '',
                'complexity' => 'low', 'plannedWeek' => '', 'completedWeek' => '',
                'tags' => [], 'plannedActions' => [], 'facts' => [], 'updatedAt' => null,
            ];
            return $issue;
        }, $issues);
        echo json_encode(
            ['issues' => $issues, 'localProjects' => $metadataStore->projects()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
        );
        exit;
    }

    foreach ($projects as $projectKey) {
        $jql = sprintf($config['jira']['jql'][$projectKey], $jiraAssignee);
        $result = $client->search($jql);
        $issues = [...$issues, ...$result['issues']];
        $totals[$projectKey] = $result['total'];
        $settings = $config['jira']['projects'][$projectKey];
        if (($settings['required_create_fields'] ?? []) !== []) {
            $creationOptions[$projectKey] = $client->getCreateOptions($settings['key']) + [
                'requiredFields' => $settings['required_create_fields'],
            ];
        }
    }

    $localProjects = $metadataStore->projects();
    $loadedKeys = array_column($issues, 'key');
    $projectIssueKeyGroups = array_map(
        static fn (array $localProject): array => $localProject['issueKeys'],
        $localProjects
    );
    $projectIssueKeys = array_values(array_unique(
        $projectIssueKeyGroups === [] ? [] : array_merge(...$projectIssueKeyGroups)
    ));
    $missingProjectIssueKeys = array_values(array_diff($projectIssueKeys, $loadedKeys));
    foreach (array_chunk($missingProjectIssueKeys, 100) as $keyChunk) {
        if ($keyChunk === []) {
            continue;
        }
        $projectIssues = $client->search('key in (' . implode(', ', $keyChunk) . ') ORDER BY updated DESC');
        $projectOnlyIssues = array_map(static function (array $issue): array {
            $issue['projectOnly'] = true;
            return $issue;
        }, $projectIssues['issues']);
        $issues = [...$issues, ...$projectOnlyIssues];
    }

    $metadata = $metadataStore->all();
    $issues = array_map(static function (array $issue) use ($metadata): array {
        $issue['metadata'] = $metadata[$issue['key']] ?? [
            'issueKey' => $issue['key'], 'bucket' => 'backlog', 'shortDescription' => '',
            'complexity' => 'low', 'plannedWeek' => '', 'completedWeek' => '',
            'tags' => [], 'plannedActions' => [], 'facts' => [], 'updatedAt' => null,
        ];
        return $issue;
    }, $issues);

    usort($issues, static fn (array $a, array $b): int => strcmp($b['updated'], $a['updated']));
    echo json_encode([
        'issues' => $issues,
        'totals' => $totals,
        'tags' => $metadataStore->tags(),
        'creationOptions' => $creationOptions,
        'localProjects' => $localProjects,
        'syncedAt' => gmdate(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $status = $exception instanceof InvalidArgumentException ? 422 : (in_array($exception->getCode(), [401, 403], true) ? $exception->getCode() : 502);
    http_response_code($status);
    echo json_encode([
        'error' => $exception->getMessage(),
        'diagnostics' => $client instanceof JiraClient ? $client->getDiagnostics() : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
