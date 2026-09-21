<?php

declare(strict_types=1);

use ProjectManager\JiraClient;

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);

$bitrixBootstrap = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/modules/main/include/prolog_before.php';
if (is_file($bitrixBootstrap)) {
    require_once $bitrixBootstrap;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

if (isset($USER) && is_object($USER) && !$USER->IsAuthorized()) {
    http_response_code(401);
    echo json_encode(['error' => 'Требуется авторизация в Битрикс24.'], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['error' => 'Метод не поддерживается.'], JSON_UNESCAPED_UNICODE);
    exit;
}

$config = require dirname(__DIR__) . '/config.php';
require_once dirname(__DIR__) . '/src/JiraClient.php';

$project = strtolower((string) ($_GET['project'] ?? 'all'));
if (!in_array($project, ['all', 'ods', 'ssis'], true)) {
    http_response_code(422);
    echo json_encode(['error' => 'Неизвестный проект.'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $client = new JiraClient($config['jira']);
    $projects = $project === 'all' ? ['ods', 'ssis'] : [$project];
    $issues = [];
    $totals = [];

    foreach ($projects as $projectKey) {
        $result = $client->search($config['jira']['jql'][$projectKey]);
        $issues = [...$issues, ...$result['issues']];
        $totals[$projectKey] = $result['total'];
    }

    usort($issues, static fn (array $a, array $b): int => strcmp($b['updated'], $a['updated']));
    echo json_encode([
        'issues' => $issues,
        'totals' => $totals,
        'syncedAt' => gmdate(DATE_ATOM),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    $status = in_array($exception->getCode(), [401, 403], true) ? $exception->getCode() : 502;
    http_response_code($status);
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
