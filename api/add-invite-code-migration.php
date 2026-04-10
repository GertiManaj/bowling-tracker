<?php
// ============================================
//  MIGRATION TEMPORANEA — da eliminare dopo uso
//  Aggiunge invite_code e group_type a groups
// ============================================
require_once __DIR__ . '/config.php';

header('Content-Type: text/plain');

try {
    $pdo = getPDO();

    // 1. Aggiungi colonne (IF NOT EXISTS non standard su MySQL < 8, usiamo try/catch per colonna)
    $cols = $pdo->query("SHOW COLUMNS FROM `groups`")->fetchAll(PDO::FETCH_COLUMN);
    $msgs = [];

    if (!in_array('invite_code', $cols)) {
        $pdo->exec("ALTER TABLE `groups` ADD COLUMN `invite_code` VARCHAR(16) UNIQUE AFTER `description`");
        $msgs[] = "✓ Colonna invite_code aggiunta";
    } else {
        $msgs[] = "· invite_code già presente";
    }

    if (!in_array('group_type', $cols)) {
        $pdo->exec("ALTER TABLE `groups` ADD COLUMN `group_type` ENUM('challenge','casual') NOT NULL DEFAULT 'challenge' AFTER `invite_code`");
        $msgs[] = "✓ Colonna group_type aggiunta";
    } else {
        $msgs[] = "· group_type già presente";
    }

    // 2. Genera invite_code per gruppi esistenti senza codice
    $stmt   = $pdo->query("SELECT id FROM `groups` WHERE invite_code IS NULL OR invite_code = ''");
    $groups = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($groups as $g) {
        do {
            $code = strtoupper(substr(md5(uniqid($g['id'] . mt_rand(), true)), 0, 8));
            $chk  = $pdo->prepare("SELECT id FROM `groups` WHERE invite_code = ?");
            $chk->execute([$code]);
        } while ($chk->fetch()); // rigenera se collision

        $pdo->prepare("UPDATE `groups` SET invite_code = ? WHERE id = ?")->execute([$code, $g['id']]);
    }

    $msgs[] = "✓ Codici invito generati per " . count($groups) . " gruppi";
    echo implode("\n", $msgs) . "\n\nMigration completata!\nElimina questo file dopo averlo eseguito.\n";

} catch (Exception $e) {
    http_response_code(500);
    echo "ERRORE: " . $e->getMessage() . "\n";
}
