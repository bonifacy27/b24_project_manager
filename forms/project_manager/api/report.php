<?php

declare(strict_types=1);

define('STOP_STATISTICS', true);
define('NO_KEEP_STATISTIC', true);
define('NO_AGENT_STATISTIC', true);
define('PUBLIC_AJAX_MODE', true);

ob_start();
$bitrixBootstrap = ($_SERVER['DOCUMENT_ROOT'] ?? '') . '/bitrix/modules/main/include/prolog_before.php';
if (is_file($bitrixBootstrap)) {
    require_once $bitrixBootstrap;
}
ob_end_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
if (isset($USER) && is_object($USER) && !$USER->IsAuthorized()) {
    http_response_code(401);
    exit('Требуется авторизация в Битрикс24.');
}

try {
    $input = json_decode((string) file_get_contents('php://input'), true, 512, JSON_THROW_ON_ERROR);
    if (function_exists('bitrix_sessid') && !hash_equals((string) bitrix_sessid(), (string) ($input['sessid'] ?? ''))) {
        throw new InvalidArgumentException('Сессия истекла. Обновите страницу.');
    }
    foreach (['completed', 'planned', 'other'] as $block) {
        if (!is_array($input[$block] ?? null) || count($input[$block]) > 1000) {
            throw new InvalidArgumentException('Некорректные данные отчёта.');
        }
    }
    $normaliseRows = static function (array $rows, array $fields): array {
        return array_map(static function (mixed $row) use ($fields): array {
            if (!is_array($row)) {
                throw new InvalidArgumentException('Некорректная строка отчёта.');
            }
            $normalised = [];
            foreach ($fields as $field) {
                $value = $row[$field] ?? '';
                if (!is_scalar($value) && $value !== null) {
                    throw new InvalidArgumentException('Некорректное значение в отчёте.');
                }
                $normalised[$field] = mb_substr((string) $value, 0, 30000);
            }
            return $normalised;
        }, array_values($rows));
    };
    $mainFields = ['task', 'created', 'system', 'description', 'actions', 'result'];
    $otherFields = ['task', 'created', 'status', 'description'];
    require '/home/bitrix/www/vendor2/autoload.php';
    require_once dirname(__DIR__) . '/src/ReportExporter.php';
    (new ProjectManager\ReportExporter())->export(
        $normaliseRows($input['completed'], $mainFields),
        $normaliseRows($input['planned'], $mainFields),
        $normaliseRows($input['other'], $otherFields),
        mb_substr((string) ($input['completedRange'] ?? ''), 0, 40),
        mb_substr((string) ($input['plannedRange'] ?? ''), 0, 40)
    );
} catch (Throwable $exception) {
    http_response_code($exception instanceof InvalidArgumentException ? 422 : 500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => $exception->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
