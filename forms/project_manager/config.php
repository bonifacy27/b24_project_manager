<?php

declare(strict_types=1);

use Bitrix\Main\Application;

/**
 * Bitrix may store a scalar global constant as a plain string, a serialized
 * scalar/array, or a JSON value depending on the module version.
 */
$decodeStoredConstant = static function (mixed $storedValue): string {
    if (!is_string($storedValue) || $storedValue === '') {
        return '';
    }

    if (function_exists('fwDiagDecodeConstant')) {
        return (string) fwDiagDecodeConstant($storedValue);
    }

    $unserialized = @unserialize($storedValue, ['allowed_classes' => false]);
    if ($unserialized !== false || $storedValue === 'b:0;') {
        if (is_scalar($unserialized)) {
            return (string) $unserialized;
        }
        if (is_array($unserialized)) {
            return (string) ($unserialized['VALUE'] ?? $unserialized['value'] ?? '');
        }
    }

    $json = json_decode($storedValue, true);
    if (json_last_error() === JSON_ERROR_NONE) {
        if (is_scalar($json)) {
            return (string) $json;
        }
        if (is_array($json)) {
            return (string) ($json['VALUE'] ?? $json['value'] ?? '');
        }
    }

    return html_entity_decode($storedValue, ENT_QUOTES | ENT_HTML5, 'UTF-8');
};

/** @return array{value: string, error: string} */
$getGlobalConstant = static function (string $id) use ($decodeStoredConstant): array {
    try {
        if (!class_exists(Application::class)) {
            throw new RuntimeException('Ядро Bitrix не загружено.');
        }

        $connection = Application::getConnection();
        $safeId = $connection->getSqlHelper()->forSql($id);
        $row = $connection
            ->query("SELECT PROPERTY_VALUE FROM b_bp_global_const WHERE ID = '" . $safeId . "'")
            ->fetch();
        $value = $decodeStoredConstant($row['PROPERTY_VALUE'] ?? '');

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
