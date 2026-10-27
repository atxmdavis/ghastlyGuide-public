<?php

declare(strict_types=1);

function ghastly_config(): array
{
    static $config;
    if (is_array($config)) {
        return $config;
    }
    $path = getenv('GHASTLY_GUIDE_CONFIG') ?: dirname(__DIR__) . '/config.php';
    if (!is_file($path)) {
        throw new RuntimeException('Ghastly Guide configuration is missing.');
    }
    $config = require $path;
    foreach (['db_host', 'db_name', 'db_user', 'db_password', 'user_agent'] as $key) {
        if (!isset($config[$key]) || !is_string($config[$key]) || $config[$key] === '') {
            throw new RuntimeException("Configuration value {$key} is missing.");
        }
    }
    return $config;
}

function ghastly_platform_is_vr(mixed $platform): bool
{
    if (!is_string($platform) || trim($platform) === '') {
        return false;
    }
    $vrPlatforms = array_fill_keys([
        'vr',
        'virtual reality',
        'pc vr',
        'steamvr',
        'playstation vr',
        'playstation vr2',
        'oculus vr',
        'oculus rift',
        'oculus go',
        'oculus quest',
        'oculus quest 2',
        'meta quest',
        'meta quest 2',
        'meta quest 3',
        'meta quest pro',
        'windows mixed reality',
        'htc vive',
        'viveport',
        'google daydream',
        'samsung gear vr',
        'gear vr',
        'pico 4',
        'pico neo 3',
        'apple vision pro',
        'webxr',
    ], true);
    foreach (explode(',', mb_strtolower($platform)) as $name) {
        if (isset($vrPlatforms[trim($name)])) {
            return true;
        }
    }
    return false;
}

function ghastly_db(): PDO
{
    static $database;
    if ($database instanceof PDO) {
        return $database;
    }
    $config = ghastly_config();
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $config['db_host'], $config['db_name']);
    $database = new PDO($dsn, $config['db_user'], $config['db_password'], [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $database;
}
