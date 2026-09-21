<?php

declare(strict_types=1);

$config = require __DIR__ . '/config.php';
$jiraConfigured = $config['jira']['base_url'] !== '' && $config['jira']['username'] !== '' && $config['jira']['password'] !== '';

require __DIR__ . '/index.html';
