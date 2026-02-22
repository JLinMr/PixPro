<?php
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        // 安装页面不需要数据库连接
        if (strpos($_SERVER['REQUEST_URI'], '/install') === 0) return;
        
        $envFile = dirname(__DIR__) . '/.env';
        
        // 未安装则跳转
        if (!file_exists($envFile)) {
            header('Location: /install');
            exit;
        }
        
        $this->loadEnv($envFile);
        $this->connect();
    }

    private function loadEnv($envFile) {
        foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
            $line = trim($line);
            if (empty($line) || $line[0] === '#' || strpos($line, '=') === false) continue;
            
            [$name, $value] = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }

    private function connect() {
        $dbPath = dirname(__DIR__) . '/database.db';
        $dbDir = dirname($dbPath);
        
        if (!is_dir($dbDir)) mkdir($dbDir, 0777, true);
        
        // 如果数据库文件不存在，检查是否需要迁移
        if (!file_exists($dbPath)) {
            // 检测到 MySQL 配置，引导用户迁移
            if (isset($_ENV['DB_HOST']) && strpos($_SERVER['REQUEST_URI'], 'migrate.php') === false) {
                header('Location: /migrate.php');
                exit;
            }
        }
        
        try {
            $this->connection = new PDO('sqlite:' . $dbPath);
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->connection->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            throw new Exception('数据库连接失败: ' . $e->getMessage());
        }
    }

    public static function getInstance() {
        return self::$instance ?? (self::$instance = new self());
    }

    public function getConnection() {
        return strpos($_SERVER['REQUEST_URI'], '/install') === 0 ? null : $this->connection;
    }

    public static function getConfig($pdo, $key = null) {
        if (!$pdo) return null;
        
        $stmt = $pdo->prepare("SELECT `key`, value FROM configs" . ($key ? " WHERE `key` = ?" : ""));
        $stmt->execute($key ? [$key] : []);
        
        $configs = array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'value', 'key');
        return $key ? ($configs[$key] ?? null) : $configs;
    }

    public static function getStorageConfig($type = null) {
        $configs = json_decode(file_get_contents(__DIR__ . '/configs.json'), true)['storage_types'];
        return $type ? ($configs[$type] ?? null) : $configs;
    }

    public function __clone() {}
    public function __wakeup() {}
}
