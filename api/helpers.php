<?php
// ============================================
//  api/helpers.php — Funzioni di utilità condivise
// ============================================

/**
 * Valida un indirizzo email con regex.
 * Richiede: almeno 1 char + @ + almeno 1 char + . + almeno 1 char
 * Es: test@example.com ✅  |  test@  ❌  |  test.com  ❌
 */
function isValidEmail(string $email): bool {
    $email = trim($email);
    if ($email === '') return false;
    return preg_match('/^[^\s@]+@[^\s@]+\.[^\s@]+$/', $email) === 1;
}
