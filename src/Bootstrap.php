<?php
declare(strict_types=1);

namespace MIS;

/**
 * Loaded by every entry point. Wires up autoload, config, DB, and session.
 */
final class Bootstrap
{
    public static array $config = [];

    public static function init(?string $dbProfile = 'db'): void
    {
        $base = dirname(__DIR__);
        spl_autoload_register(static function (string $class) use ($base) {
            if (!str_starts_with($class, 'MIS\\')) {
                return;
            }
            $rel = substr($class, 4);
            $path = $base . '/src/' . str_replace('\\', '/', $rel) . '.php';
            if (is_file($path)) {
                require_once $path;
            }
        });
        $cfgFile = $base . '/config/config.php';
        if (!is_file($cfgFile)) {
            $cfgFile = $base . '/config/config.example.php';
        }
        self::$config = require $cfgFile;
        Db::configure(self::$config[$dbProfile] ?? self::$config['db']);
        Auth::start(self::$config['auth'] ?? []);
    }
}
