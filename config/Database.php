<?php
class Database {
    private static $instance = null;
    private $conn;

    private function __construct() {
        $config = parse_ini_file('config.ini');
        $dbHost = $config['dbHost'];
        $dbUser = $config['dbUser'];
        $dbPass = $config['dbPass'];
        $dbName = $config['dbName'];

        $this->conn = new mysqli($dbHost, $dbUser, $dbPass, $dbName);
        if ($this->conn->connect_error) {
            die("连接数据库失败：" . $this->conn->connect_error);
        }
    }

    public static function getInstance() {
        if (self::$instance == null) {
            self::$instance = new Database();
        }
        return self::$instance;
    }

    public function getConnection() {
        return $this->conn;
    }
}
?>
