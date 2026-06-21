<?php

if (!defined('PIXPRO_ROOT')) {
    define('PIXPRO_ROOT', dirname(__DIR__));
}

function loadEnv($envFile) {
    if (!file_exists($envFile)) {
        return [];
    }

    $env = [];
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#' || strpos($line, '=') === false) {
            continue;
        }

        [$name, $value] = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value);
        $env[$name] = $value;
        $_ENV[$name] = $value;
    }

    return $env;
}

class Database {
    private static $instance = null;
    private static $configCache = null;
    private $connection;

    private function __construct() {
        if (strpos($_SERVER['REQUEST_URI'] ?? '', '/install') === 0) {
            return;
        }

        $envFile = PIXPRO_ROOT . '/.env';

        if (!file_exists($envFile)) {
            header('Location: /install');
            exit;
        }

        loadEnv($envFile);
        $this->connect();
    }

    private function connect() {
        $dbPath = PIXPRO_ROOT . '/database.db';

        if (!file_exists($dbPath)) {
            if (!empty($_ENV['DB_HOST']) && strpos($_SERVER['REQUEST_URI'] ?? '', 'migrate.php') === false) {
                header('Location: /migrate.php');
                exit;
            }

            header('Location: /install');
            exit;
        }

        try {
            $this->connection = new PDO('sqlite:' . $dbPath);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->exec('PRAGMA foreign_keys = ON');
            $this->connection->exec('PRAGMA journal_mode = WAL');
            $this->connection->exec('PRAGMA synchronous = NORMAL');
        } catch (PDOException $e) {
            throw new Exception('数据库连接失败: ' . $e->getMessage());
        }
    }

    public static function getInstance() {
        return self::$instance ?? (self::$instance = new self());
    }

    public function getConnection() {
        return strpos($_SERVER['REQUEST_URI'] ?? '', '/install') === 0 ? null : $this->connection;
    }

    public static function getConfig($pdo, $key = null) {
        if (!$pdo) {
            return null;
        }

        if (self::$configCache === null) {
            $stmt = $pdo->query('SELECT `key`, value FROM configs');
            self::$configCache = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value', 'key');
        }

        return $key ? (self::$configCache[$key] ?? null) : self::$configCache;
    }

    public static function clearConfigCache() {
        self::$configCache = null;
    }

    public static function getImageCount($pdo) {
        return (int)(self::getConfig($pdo, 'image_count') ?? 0);
    }

    public static function adjustImageCount($pdo, $delta) {
        $delta = (int)$delta;
        if ($delta === 0 || !$pdo) {
            return;
        }

        $stmt = $pdo->prepare(
            "UPDATE configs SET value = CAST(COALESCE(value, '0') AS INTEGER) + ?
             WHERE `key` = 'image_count'"
        );
        $stmt->execute([$delta]);

        if (self::$configCache !== null) {
            $current = (int)(self::$configCache['image_count'] ?? 0);
            self::$configCache['image_count'] = (string)max(0, $current + $delta);
        }
    }

    public static function optimize($pdo) {
        $dbPath = PIXPRO_ROOT . '/database.db';
        $sizeBefore = file_exists($dbPath) ? filesize($dbPath) : 0;

        $pdo->exec('VACUUM');
        $pdo->exec('ANALYZE');

        try {
            $pdo->exec('PRAGMA optimize');
        } catch (PDOException $e) {
        }

        $imageCount = (int)$pdo->query('SELECT COUNT(id) FROM images')->fetchColumn();
        $pdo->prepare("UPDATE configs SET value = ? WHERE `key` = 'image_count'")->execute([(string)$imageCount]);

        if (self::$configCache !== null) {
            self::$configCache['image_count'] = (string)$imageCount;
        }

        clearstatcache(true, $dbPath);
        $sizeAfter = file_exists($dbPath) ? filesize($dbPath) : 0;

        return [
            'saved' => round(($sizeBefore - $sizeAfter) / 1024 / 1024, 2),
            'image_count' => $imageCount,
        ];
    }

    public static function getStorageTypes() {
        static $cache = null;
        if ($cache === null) {
            $cache = json_decode(file_get_contents(__DIR__ . '/configs.json'), true)['storage_types'];
        }

        return $cache;
    }

    public static function getStorageConfig($type = null) {
        $configs = self::getStorageTypes();
        return $type ? ($configs[$type] ?? null) : $configs;
    }

    public function __clone() {}
    public function __wakeup() {}
}
