<?php

declare(strict_types=1);

use Bitrix\Main\Application;

/** @return array{value: string, error: string} */
$getGlobalConstant = static function (string $id): array {
    try {
        if (!class_exists(Application::class)) {
            throw new RuntimeException('Ядро Bitrix не загружено.');
        }
        if (!function_exists('fwDiagDecodeConstant')) {
            throw new RuntimeException('Функция fwDiagDecodeConstant() не найдена.');
        }

        $connection = Application::getConnection();
        $safeId = $connection->getSqlHelper()->forSql($id);
        $row = $connection
            ->query("SELECT PROPERTY_VALUE FROM b_bp_global_const WHERE ID = '" . $safeId . "'")
            ->fetch();
        $value = trim((string) fwDiagDecodeConstant($row['PROPERTY_VALUE'] ?? ''));

        return [
            'value' => $value,
            'error' => $value === '' ? 'Глобальная константа ' . $id . ' пуста или не найдена.' : '',
        ];
    } catch (Throwable $exception) {
        return ['value' => '', 'error' => $exception->getMessage()];
    }
};

$jiraLogin = $getGlobalConstant('Constant1737353139590');
$jiraPassword = $getGlobalConstant('Constant1790002602578');
$credentialErrors = array_unique(array_filter([$jiraLogin['error'], $jiraPassword['error']]));

return [
    'jira' => [
        'base_url' => 'https://jira.tricolor.tv',
        'username' => $jiraLogin['value'],
        'password' => $jiraPassword['value'],
        'credentials_error' => implode(' ', $credentialErrors),
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
