<?php
// ============================================
//  api/config.php — Database Configuration
//  Compatibile con Railway MySQL Reference Variables
// ============================================

function getPDO() {
    static $pdo = null;
    
    if ($pdo !== null) {
        return $pdo;
    }
    
    // Railway inietta automaticamente MYSQL* variables quando connetti il database
    // Usa quelle se disponibili, altrimenti fallback su DB_*
    $host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
    $port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';
    $dbname = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'railway';
    $user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
    $pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
    
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        return $pdo;
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        error_log("Host: $host, Port: $port, DB: $dbname, User: $user");
        http_response_code(500);
        die(json_encode([
            'error' => 'Database connection failed',
            'details' => $e->getMessage(),
            'host' => $host,
            'port' => $port,
            'db' => $dbname
        ]));
    }
}
