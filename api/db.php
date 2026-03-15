<?php
// ============================================
//  api/db.php — Connessione al database
//  Funziona sia in locale (XAMPP) che online (Railway)
//  Le credenziali vengono lette dalle variabili
//  d'ambiente se disponibili, altrimenti usa i
//  valori di default per XAMPP locale.
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

// Legge dalla variabile d'ambiente oppure usa default XAMPP
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_NAME', getenv('DB_NAME') ?: 'bowling_tracker');
define('DB_PORT', getenv('DB_PORT') ?: '3306');

function getDB(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $dsn = 'mysql:host=' . DB_HOST
                 . ';port='     . DB_PORT
                 . ';dbname='   . DB_NAME
                 . ';charset=utf8mb4';

            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ]);
        } catch (PDOException $e) {
            http_response_code(500);
            echo json_encode(['error' => 'Connessione DB fallita: ' . $e->getMessage()]);
            exit;
        }
    }
    return $pdo;
}

// Header JSON per tutte le API
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

// Gestisce le preflight OPTIONS request (CORS)
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}
