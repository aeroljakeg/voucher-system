<?php

/**
 * Shared hosting applications cannot always set server environment variables.
 * Environment variables take precedence; config/app-config.php is the fallback
 * for InfinityFree and other PHP hosting control panels.
 */
function app_config_value(string $key, $default = null)
{
    static $fileConfig = null;

    $environmentValue = getenv($key);
    if (is_string($environmentValue) && $environmentValue !== '') {
        return $environmentValue;
    }

    if ($fileConfig === null) {
        $configFile = __DIR__ . '/app-config.php';
        $fileConfig = is_file($configFile) ? require $configFile : [];
        if (!is_array($fileConfig)) {
            $fileConfig = [];
        }
    }

    return array_key_exists($key, $fileConfig) ? $fileConfig[$key] : $default;
}
