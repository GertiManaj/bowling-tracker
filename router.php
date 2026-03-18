<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);

// ── Redirect root → welcome.html ─────────────────────────────
if ($path === '/' || $path === '') {
    header('Location: /frontend/pages/welcome.html');
    exit;
}

// ── Redirect URL "corte" senza prefisso (compatibilità link vecchi) ──
// es. /giocatori.html  →  /frontend/pages/giocatori.html
$pages = ['index.html','sessioni.html','statistiche.html','giocatori.html','profilo.html','welcome.html'];
$basename = basename($path);
if (in_array($basename, $pages) && $path === '/' . $basename) {
    header('Location: /frontend/pages/' . $basename);
    exit;
}

// ── Service Worker (deve stare alla root per il suo scope) ────
if ($path === '/service-worker.js') {
    $file = __DIR__ . '/service-worker.js';
    if (file_exists($file)) {
        header('Service-Worker-Allowed: /');
        header('Content-Type: application/javascript');
        readfile($file);
        exit;
    }
}

// ── Serve il file fisico se esiste ───────────────────────────
$file = __DIR__ . $path;
if (file_exists($file) && !is_dir($file)) {
    return false; // lascia al built-in server di PHP
}

// ── Fallback 404 ─────────────────────────────────────────────
http_response_code(404);
echo '404 Not Found';