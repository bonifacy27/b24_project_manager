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

if (!in_array($_SERVER['REQUEST_METHOD'], ['GET', 'POST'], true)) {
    http_response_code(405);
    header('Allow: GET, POST');
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

$client = null;
try {
    $client = new JiraClient($config['jira']);
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
        if (function_exists('bitrix_sessid') && !hash_equals((string) bitrix_sessid(), (string) ($input['sessid'] ?? ''))) {
            throw new InvalidArgumentException('Сессия истекла. Обновите страницу.');
        }
        $project = strtolower(trim((string) ($input['project'] ?? '')));
        $summary = trim((string) ($input['title'] ?? ''));
        $description = trim((string) ($input['description'] ?? ''));
        $plan = strtolower((string) ($input['plan'] ?? 'later'));
        if (!isset($config['jira']['projects'][$project]) || !in_array($plan, ['week', 'next', 'later'], true)) {
            throw new InvalidArgumentException('Неверно указан проект или план.');
        }
        if ($summary === '' || mb_strlen($summary) > 255 || mb_strlen($description) > 10000) {
            throw new InvalidArgumentException('Название обязательно и должно быть короче 256 символов.');
        }
        $due = new DateTimeImmutable('today');
        $daysUntilSunday = 7 - (int) $due->format('N');
        $dueDate = match ($plan) {
            'week' => $due->modify('+' . $daysUntilSunday . ' days')->format('Y-m-d'),
            'next' => $due->modify('+' . ($daysUntilSunday + 7) . ' days')->format('Y-m-d'),
            default => null,
        };
        $settings = $config['jira']['projects'][$project];
        $issue = $client->createIssue($settings['key'], $settings['issue_type'], $summary, $description, $dueDate);
        http_response_code(201);
        echo json_encode(['issue' => $issue], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }
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
    $status = $exception instanceof InvalidArgumentException ? 422 : (in_array($exception->getCode(), [401, 403], true) ? $exception->getCode() : 502);
    http_response_code($status);
    echo json_encode([
        'error' => $exception->getMessage(),
        'diagnostics' => $client instanceof JiraClient ? $client->getDiagnostics() : null,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
