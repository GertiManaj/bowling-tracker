<?php
// ============================================
//  api/mailer.php
//  Helper email via Resend API
//  Funzioni: sendEmail(), sendWelcomeAdmin(),
//            sendWelcomePlayer(), sendNewPlayerNotify()
// ============================================

/**
 * Invia un'email via Resend API.
 * Ritorna true se inviata, false altrimenti (non blocca il flusso).
 */
function sendEmail(string $to, string $subject, string $html): bool {
    // Skip in modalità test: logga invece di inviare
    if (getenv('SKIP_OTP_FOR_TESTING') === 'true') {
        error_log("[MAIL-SKIP] Email non inviata (SKIP_OTP_FOR_TESTING=true)");
        error_log("[MAIL-SKIP] To: $to | Subject: $subject");
        return true;
    }

    $apiKey = getenv('RESEND_API_KEY');
    if (!$apiKey) {
        error_log('[MAIL] RESEND_API_KEY non configurata');
        return false;
    }

    $fromEmail = getenv('EMAIL_FROM')      ?: 'noreply@resend.dev';
    $fromName  = getenv('EMAIL_FROM_NAME') ?: 'Strike Zone';

    $ch = curl_init('https://api.resend.com/emails');
    if (!$ch) {
        error_log('[MAIL] curl_init fallito');
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'from'    => "$fromName <$fromEmail>",
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $html,
        ]),
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
        ],
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_CONNECTTIMEOUT => 5,
    ]);

    $response  = curl_exec($ch);
    $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $curlError) {
        error_log("[MAIL] Curl error → $to: $curlError");
        return false;
    }
    if ($httpCode < 200 || $httpCode >= 300) {
        error_log("[MAIL] HTTP $httpCode → $to: $response");
        return false;
    }
    return true;
}

// ── Template base ─────────────────────────────
function mailWrap(string $body): string {
    $app = htmlspecialchars(getenv('APP_NAME') ?: 'Strike Zone');
    return "
<!DOCTYPE html><html><head><meta charset='utf-8'>
<style>
  body{font-family:Arial,sans-serif;background:#0a0a0f;margin:0;padding:20px}
  .card{max-width:560px;margin:0 auto;background:#13131f;border-radius:12px;overflow:hidden;border:1px solid #2a2a44}
  .hdr{background:linear-gradient(135deg,#e8ff00 0%,#00f5ff 100%);padding:28px 30px;text-align:center}
  .hdr h1{margin:0;color:#0a0a0f;font-size:26px;letter-spacing:0.05em}
  .bdy{padding:36px 30px;color:#d0d0e0;font-size:15px;line-height:1.7}
  .bdy strong{color:#fff}
  .code-box{background:#0a0a0f;border:2px solid #e8ff00;border-radius:8px;padding:16px 24px;
             font-size:28px;font-weight:700;letter-spacing:10px;color:#e8ff00;
             text-align:center;margin:24px 0;font-family:'Courier New',monospace}
  .btn{display:inline-block;background:#e8ff00;color:#0a0a0f;padding:12px 28px;
       border-radius:8px;text-decoration:none;font-weight:700;font-size:15px;
       letter-spacing:0.05em;margin:20px 0}
  .info-box{background:#1a1a2e;border-left:3px solid #00e5ff;padding:14px 18px;
            border-radius:4px;margin:20px 0;font-size:13px;color:#9090b0}
  .ftr{background:#0d0d1a;padding:18px;text-align:center;font-size:12px;color:#555570}
</style></head><body>
<div class='card'>
  <div class='hdr'><h1>🎳 $app</h1></div>
  <div class='bdy'>$body</div>
  <div class='ftr'>$app &nbsp;·&nbsp; Email automatica, non rispondere.</div>
</div>
</body></html>";
}

// ── 1. Benvenuto Group Admin ──────────────────
function sendWelcomeAdmin(string $email, string $name, string $groupName, string $inviteCode): bool {
    $appUrl    = rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/');
    $loginUrl  = $appUrl . '/frontend/pages/welcome.html';
    $regUrl    = $appUrl . '/frontend/pages/player-register.html?code=' . urlencode($inviteCode);
    $eName     = htmlspecialchars($name);
    $eGroup    = htmlspecialchars($groupName);
    $eCode     = htmlspecialchars($inviteCode);

    $body = "
<p>Ciao <strong>$eName</strong>,</p>
<p>Il tuo gruppo bowling <strong>«$eGroup»</strong> è stato creato con successo su Strike Zone! 🎳</p>

<div class='info-box'>
  <strong>📋 Codice Invito del gruppo:</strong><br>
  <div class='code-box'>$eCode</div>
  Condividi questo codice con i tuoi giocatori per farli registrare.
</div>

<p><strong>Link di invito diretto:</strong><br>
<a href='$regUrl' style='color:#00e5ff'>$regUrl</a></p>

<p>Accedi come amministratore per gestire il tuo gruppo:</p>
<a href='$loginUrl' class='btn'>🔐 Accedi ora</a>

<p style='font-size:13px;color:#555570;margin-top:24px'>
  Come amministratore puoi aggiungere sessioni, gestire giocatori e visualizzare le statistiche complete del gruppo.
</p>";

    $subject = "🎳 Il tuo gruppo «$groupName» è pronto su Strike Zone!";
    return sendEmail($email, $subject, mailWrap($body));
}

// ── 2. Benvenuto Player ───────────────────────
function sendWelcomePlayer(string $email, string $playerName, string $groupName, string $appUrl = ''): bool {
    if (!$appUrl) $appUrl = rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/');
    $loginUrl = $appUrl . '/frontend/pages/welcome.html';
    $eName    = htmlspecialchars($playerName);
    $eGroup   = htmlspecialchars($groupName);

    $body = "
<p>Ciao <strong>$eName</strong>,</p>
<p>Benvenuto su <strong>Strike Zone</strong>! 🎳</p>
<p>Sei stato registrato nel gruppo <strong>«$eGroup»</strong>.</p>
<p>Puoi accedere in qualsiasi momento per vedere le classifiche, le tue statistiche e i risultati delle sessioni:</p>
<a href='$loginUrl' class='btn'>🎳 Vai su Strike Zone</a>
<div class='info-box'>
  Usa l'email e la password che hai scelto durante la registrazione per accedere.
</div>";

    $subject = "🎳 Benvenuto in «$groupName» su Strike Zone!";
    return sendEmail($email, $subject, mailWrap($body));
}

// ── 3. Attivazione Player — con credenziali ───
function sendPlayerActivation(string $email, string $playerName, string $groupName, string $password): bool {
    $appUrl   = rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/');
    $loginUrl = $appUrl . '/frontend/pages/welcome.html';
    $eName    = htmlspecialchars($playerName);
    $eGroup   = htmlspecialchars($groupName);
    $eEmail   = htmlspecialchars($email);
    $ePass    = htmlspecialchars($password);

    $body = "
<p>Ciao <strong>$eName</strong>! 🎳</p>
<p>Il tuo account per il gruppo <strong>«$eGroup»</strong> è pronto.</p>

<div class='info-box' style='border-color:#e8ff00'>
  <strong style='color:#e8ff00'>🔑 Le tue credenziali:</strong><br><br>
  <div style='margin-bottom:0.4em'>Email: <strong style='color:#fff'>$eEmail</strong></div>
  <div class='code-box' style='font-size:1.3rem;letter-spacing:4px'>$ePass</div>
  <span style='font-size:12px'>Cambia la password dopo il primo accesso.</span>
</div>

<a href='$loginUrl' class='btn'>🎳 Accedi ora</a>

<p style='font-size:13px;color:#555570;margin-top:24px'>
  Entra nella sezione <em>Player Login</em> dalla pagina di benvenuto e usa l'email e la password sopra.
</p>";

    $subject = "🎳 Il tuo account «$groupName» su Strike Zone è pronto!";
    return sendEmail($email, $subject, mailWrap($body));
}

// ── 4. Notifica admin: nuovo giocatore ────────
function sendNewPlayerNotify(string $adminEmail, string $adminName, string $playerName, string $groupName): bool {
    $appUrl     = rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/');
    $dashUrl    = $appUrl . '/frontend/pages/index.html';
    $eAdmin     = htmlspecialchars($adminName);
    $ePlayer    = htmlspecialchars($playerName);
    $eGroup     = htmlspecialchars($groupName);

    $body = "
<p>Ciao <strong>$eAdmin</strong>,</p>
<p>Un nuovo giocatore si è appena registrato nel tuo gruppo <strong>«$eGroup»</strong>:</p>
<div class='info-box' style='border-color:#e8ff00'>
  🎳 <strong style='color:#e8ff00'>$ePlayer</strong> si è unito al gruppo
</div>
<p>Accedi alla dashboard per visualizzare il profilo e gestire i giocatori:</p>
<a href='$dashUrl' class='btn'>📊 Vai alla Dashboard</a>";

    $subject = "🎳 Nuovo giocatore in «$groupName»: $playerName";
    return sendEmail($adminEmail, $subject, mailWrap($body));
}
