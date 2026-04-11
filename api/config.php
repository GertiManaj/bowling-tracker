<?php
// ============================================
//  api/config.php — Database Configuration
//  Funziona sia in locale (XAMPP) che su Railway
//  Unifica db.php e config.php precedenti
// ============================================

// Carica .env se esiste (solo in locale)
$envFile = __DIR__ . '/../.env';
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        [$key, $value] = explode('=', $line, 2);
        $key   = trim($key);
        $value = trim($value);
        if (!empty($key) && !isset($_ENV[$key])) {
            $_ENV[$key]  = $value;
            putenv("$key=$value");
        }
    }
}

function getPDO() {
    static $pdo = null;
    
    if ($pdo !== null) {
        return $pdo;
    }
    
    // Railway usa MYSQL* variables, locale usa DB_*
    $host = getenv('MYSQLHOST') ?: getenv('DB_HOST') ?: 'localhost';
    $port = getenv('MYSQLPORT') ?: getenv('DB_PORT') ?: '3306';
    $dbname = getenv('MYSQLDATABASE') ?: getenv('DB_NAME') ?: 'bowling_tracker';
    $user = getenv('MYSQLUSER') ?: getenv('DB_USER') ?: 'root';
    $pass = getenv('MYSQLPASSWORD') ?: getenv('DB_PASSWORD') ?: '';
    
    $dsn = "mysql:host=$host;port=$port;dbname=$dbname;charset=utf8mb4";
    
    try {
        $pdo = new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
        
        // Migration automatica
        require_once __DIR__ . '/migration.php';
        runMigrations($pdo);
        
        return $pdo;
        
    } catch (PDOException $e) {
        error_log("Database connection error: " . $e->getMessage());
        error_log("Host: $host, Port: $port, DB: $dbname, User: $user");
        http_response_code(500);
        die(json_encode([
            'error' => 'Database connection failed'
        ]));
    }
}

// Alias per compatibilità con vecchio codice che usa getDB()
function getDB() {
    return getPDO();
}

// Headers JSON per tutte le API
header('Content-Type: application/json');
// CORS strict whitelist
$_sz_allowedOrigins = [
    'https://web-production-e43fd.up.railway.app',
    'https://mystrikezone.xyz',
    'https://www.mystrikezone.xyz',
];
$_sz_origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($_sz_origin, $_sz_allowedOrigins, true)) {
    header("Access-Control-Allow-Origin: $_sz_origin");
    header('Access-Control-Allow-Credentials: true');
} elseif (!empty($_sz_origin)) {
    // Origin presente ma non in whitelist → blocca e logga
    error_log("[CORS] Origin bloccato: $_sz_origin");
}
// Nessun header CORS per richieste senza Origin (server-to-server, curl)
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');

// Gestisce le preflight OPTIONS request (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
