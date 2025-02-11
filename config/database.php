<?php
class Database {
    private static $instance = null;
    private $connection;

    private function __construct() {
        $envFile = dirname(__DIR__) . '/.env';
        
        // 安装页面不需要数据库连接
        if (strpos($_SERVER['REQUEST_URI'], '/install') === 0) {
            return;
        }
        
        // 未安装则跳转
        if (!file_exists($envFile)) {
            header('Location: /install');
            exit;
        }
        
        // 加载配置并连接数据库
        $this->loadEnv($envFile);
        $this->connect();
    }

    private function loadEnv($envFile) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos($line, '#') === 0) continue;
            list($name, $value) = explode('=', $line, 2);
            $_ENV[trim($name)] = trim($value);
        }
    }

    private function connect() {
        $this->connection = @new mysqli(
            $_ENV['DB_HOST'] ?? '',
            $_ENV['DB_USER'] ?? '',
            $_ENV['DB_PASS'] ?? '',
            $_ENV['DB_NAME'] ?? ''
        );

        if ($this->connection->connect_error) {
            $error = strpos($this->connection->connect_error, 'Access denied') !== false ? '数据库账号或密码错误' :
                     (strpos($this->connection->connect_error, 'Unknown database') !== false ? '数据库不存在' :
                     (strpos($this->connection->connect_error, 'Connection refused') !== false ? '无法连接到数据库服务器' :
                     '数据库连接失败'));
            throw new Exception($error);
        }

        $this->connection->set_charset('utf8mb4');
    }

    public static function getInstance() {
        return self::$instance ?? (self::$instance = new self());
    }

    public function getConnection() {
        return strpos($_SERVER['REQUEST_URI'], '/install') === 0 ? null : $this->connection;
    }

    public static function getConfig($mysqli, $key = null) {
        if (!$mysqli) return null;
        
        $sql = "SELECT `key`, value FROM configs" . ($key ? " WHERE `key` = ?" : "");
        $stmt = $mysqli->prepare($sql);
        
        if ($key) {
            $stmt->bind_param("s", $key);
        }
        
        if (!$stmt || !$stmt->execute()) {
            return null;
        }
        
        $result = $stmt->get_result();
        $configs = [];
        
        while ($row = $result->fetch_assoc()) {
            $configs[$row['key']] = $row['value'];
        }
        
        return $key ? ($configs[$key] ?? null) : $configs;
    }

    public static function getStorageConfig($type = null) {
        $configs = json_decode(file_get_contents(__DIR__ . '/configs.json'), true)['storage_types'];
        return $type ? ($configs[$type] ?? null) : $configs;
    }

    public function __clone() {}
    public function __wakeup() {}
}
?>
