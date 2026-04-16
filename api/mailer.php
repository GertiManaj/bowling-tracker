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

    $fromEmail = getenv('EMAIL_FROM')      ?: 'support@strikezone.xyz';
    $fromName  = getenv('EMAIL_FROM_NAME') ?: 'Strike Zone Support';

    $ch = curl_init('https://api.resend.com/emails');
    if (!$ch) {
        error_log('[MAIL] curl_init fallito');
        return false;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            'from'     => "$fromName <$fromEmail>",
            'to'       => [$to],
            'subject'  => $subject,
            'html'     => $html,
            'reply_to' => ['manajgerti2002@gmail.com'],
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

// ── 3. Attivazione Player — password temporanea ───
function sendPlayerActivation(string $email, string $playerName, string $groupName, string $tempPassword): bool {
    $appUrl   = rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/');
    $loginUrl = $appUrl . '/frontend/pages/welcome.html';
    $eName    = htmlspecialchars($playerName);
    $eGroup   = htmlspecialchars($groupName);
    $eEmail   = htmlspecialchars($email);
    $ePass    = htmlspecialchars($tempPassword);

    $body = "
<p>Ciao <strong>$eName</strong>! 🎳</p>
<p>Il tuo account per il gruppo <strong>«$eGroup»</strong> è stato creato.</p>

<div class='info-box' style='border-left-color:#ff3cac;background:#1a0d15'>
  <strong style='color:#ff3cac'>⚠️ PASSWORD TEMPORANEA — CAMBIO OBBLIGATORIO</strong><br>
  <span style='font-size:13px'>Al primo accesso ti verrà chiesto di scegliere una nuova password personale.
  Non potrai usare l'applicazione finché non la cambi.</span>
</div>

<div class='info-box' style='border-color:#e8ff00;margin-top:16px'>
  <strong style='color:#e8ff00'>🔑 Credenziali temporanee:</strong><br><br>
  <div style='margin-bottom:0.4em'>Email: <strong style='color:#fff'>$eEmail</strong></div>
  <div class='code-box' style='font-size:1.2rem;letter-spacing:3px;border-color:#ff3cac;color:#ff3cac'>$ePass</div>
</div>

<a href='$loginUrl' class='btn'>🎳 Accedi e cambia password</a>

<p style='font-size:13px;color:#555570;margin-top:24px'>
  Usa l'email e la password temporanea sopra per il primo accesso, poi scegli una password che solo tu conosci.
</p>";

    $subject = "🎳 Account creato in «$groupName» — Cambia password richiesto";
    return sendEmail($email, $subject, mailWrap($body));
}

// ── 4. Notifica cambio email giocatore ───────
/**
 * $type = 'old' → avvisa che l'email NON è più associata
 * $type = 'new' → avvisa che l'email È ora associata
 */
function sendEmailChangeNotification(string $email, string $playerName, string $type): bool {
    $appUrl   = rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/');
    $loginUrl = $appUrl . '/frontend/pages/welcome.html';
    $eName    = htmlspecialchars($playerName);
    $eEmail   = htmlspecialchars($email);

    if ($type === 'old') {
        $subject = '⚠️ Email account Strike Zone modificata';
        $body = "
<p>Ciao <strong>$eName</strong>,</p>
<p>L'amministratore ha modificato l'email associata al tuo account Strike Zone.</p>
<div class='info-box' style='border-left-color:#ff3cac;background:#1a0d15'>
  <strong style='color:#ff3cac'>⚠️ Email account modificata</strong><br>
  La tua email (<strong>$eEmail</strong>) non è più associata al tuo account.
</div>
<p style='font-size:13px;color:#555570;margin-top:24px'>
  Se non hai autorizzato questa modifica, contatta l'amministratore del tuo gruppo.
</p>";
    } else {
        $subject = '✅ Email account Strike Zone aggiornata';
        $body = "
<p>Ciao <strong>$eName</strong>,</p>
<p>L'amministratore ha associato questa email al tuo account Strike Zone.</p>
<div class='info-box' style='border-color:#00e5ff'>
  <strong style='color:#00e5ff'>✅ Email account aggiornata</strong><br>
  Da ora puoi accedere con questa email: <strong>$eEmail</strong>
</div>
<p>Accedi a Strike Zone:</p>
<a href='$loginUrl' class='btn'>🎳 Accedi a Strike Zone</a>";
    }

    return sendEmail($email, $subject, mailWrap($body));
}

// ── 5. Conferma creazione ticket (a utente) ───
function notifyUserTicketCreated(string $userEmail, string $ticketNumber, string $title): bool {
    if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) return false;

    $appUrl    = rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/');
    $ticketUrl = $appUrl . '/frontend/pages/tickets.html?ticket=' . urlencode($ticketNumber);
    $eTitle    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');

    $html = "<!DOCTYPE html><html><head><meta charset='utf-8'></head>
<body style='margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif'>
<div style='max-width:600px;margin:0 auto;background:#fff'>

  <div style='background:linear-gradient(135deg,#1a1a1a 0%,#2a2a2a 100%);padding:2rem;text-align:center'>
    <h1 style='color:#ffd700;margin:0;font-size:2rem'>🎳 STRIKE ZONE</h1>
    <p style='color:#999;margin:0.5rem 0 0;font-size:0.9rem'>Support Team</p>
  </div>

  <div style='padding:2rem'>
    <div style='text-align:center;margin-bottom:1.5rem'>
      <span style='background:#00ff88;color:#000;padding:0.5rem 1.5rem;border-radius:20px;font-size:0.85rem;font-weight:700'>TICKET #$ticketNumber RICEVUTO</span>
    </div>
    <h2 style='color:#00ff88;margin:0 0 1.5rem'>✅ Ticket ricevuto!</h2>
    <p style='color:#666;line-height:1.7'>Il tuo ticket è stato ricevuto correttamente. Il team lo esaminerà al più presto e riceverai un'email appena ci sarà una risposta.</p>
    <div style='background:#f5f5f5;border-left:4px solid #00ff88;padding:1.25rem;margin:1.5rem 0'>
      <p style='margin:0 0 0.4rem;color:#888;font-size:0.82rem;font-weight:600'>OGGETTO:</p>
      <p style='margin:0;color:#333;font-size:1rem'>$eTitle</p>
    </div>
    <div style='text-align:center;margin:2rem 0'>
      <a href='$ticketUrl' style='display:inline-block;background:#00ff88;color:#000;padding:14px 32px;text-decoration:none;border-radius:8px;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,255,136,0.25)'>📋 Visualizza Ticket</a>
    </div>
    <div style='background:#fff9e6;border:2px solid #ffd700;border-radius:8px;padding:1.25rem;margin:2rem 0'>
      <p style='margin:0 0 0.5rem;color:#333;font-weight:600'>⚠️ IMPORTANTE</p>
      <p style='margin:0;color:#666;line-height:1.6;font-size:0.9rem'><strong>Non rispondere a questa email.</strong><br>Per aggiornamenti usa il link sopra per accedere al sistema ticket di Strike Zone.</p>
    </div>
  </div>

  <div style='background:#f5f5f5;padding:1.5rem;border-top:1px solid #e0e0e0;text-align:center'>
    <p style='margin:0 0 0.4rem;color:#666;font-size:0.9rem'>Strike Zone Support</p>
    <p style='margin:0;font-size:0.85rem'><a href='https://strikezone.xyz' style='color:#00ff88;text-decoration:none'>strikezone.xyz</a></p>
    <p style='margin:0.8rem 0 0;color:#aaa;font-size:0.75rem'>Ticket ID: #$ticketNumber</p>
  </div>

</div>
</body></html>";

    return sendEmail($userEmail, "✅ Ticket #$ticketNumber ricevuto — Strike Zone", $html);
}

// ── 6. Notifica admin: nuovo ticket ──────────
function notifyAdminNewTicket(string $ticketNumber, string $title, string $category, string $userName, string $userEmail, string $description = ''): bool {
    $adminEmail = getenv('ADMIN_EMAIL') ?: 'manajgerti2002@gmail.com';
    $appUrl     = rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/');
    $ticketUrl  = $appUrl . '/frontend/pages/tickets.html?ticket=' . urlencode($ticketNumber);

    $catEmoji   = ['bug' => '🐛', 'suggerimento' => '💡', 'domanda' => '❓',
                   'funzionalita' => '⚙️', 'feature' => '✨', 'altro' => '💬'];
    $emoji      = $catEmoji[$category] ?? '💬';
    $eTitle     = htmlspecialchars($title,    ENT_QUOTES, 'UTF-8');
    $eCat       = htmlspecialchars(ucfirst($category), ENT_QUOTES, 'UTF-8');
    $eUser      = htmlspecialchars($userName, ENT_QUOTES, 'UTF-8');
    $eEmail     = $userEmail ? htmlspecialchars($userEmail, ENT_QUOTES, 'UTF-8') : '';
    $descPrev   = $description ? htmlspecialchars(mb_strimwidth($description, 0, 150, '…'), ENT_QUOTES, 'UTF-8') : '';

    $html = "<!DOCTYPE html><html><head><meta charset='utf-8'></head>
<body style='font-family:-apple-system,Arial,sans-serif;color:#333;background:#f5f5f5;padding:1rem'>
<div style='max-width:600px;margin:0 auto'>

  <div style='background:#1a1a1a;padding:1.5rem;border-radius:8px 8px 0 0'>
    <h2 style='color:#ffd700;margin:0;font-size:1.4rem'>$emoji Nuovo Ticket #$ticketNumber</h2>
  </div>

  <div style='background:#fff;padding:2rem;border:1px solid #e0e0e0;border-top:none;border-radius:0 0 8px 8px'>
    <h3 style='margin:0 0 1.5rem;color:#333'>$eTitle</h3>
    <table style='width:100%;border-collapse:collapse'>
      <tr><td style='padding:0.6rem 0;border-bottom:1px solid #f0f0f0;color:#888'><strong>Categoria</strong></td>
          <td style='padding:0.6rem 0;border-bottom:1px solid #f0f0f0;text-align:right'>$emoji $eCat</td></tr>
      <tr><td style='padding:0.6rem 0;color:#888'><strong>Da</strong></td>
          <td style='padding:0.6rem 0;text-align:right'>$eUser" . ($eEmail ? "<br><small style='color:#888'>$eEmail</small>" : '') . "</td></tr>
    </table>
    " . ($descPrev ? "<div style='background:#f9f9f9;padding:1.25rem;margin:1.5rem 0;border-radius:6px;font-size:0.9rem;color:#555;white-space:pre-wrap'>$descPrev</div>" : '') . "
    <div style='text-align:center;margin:2rem 0'>
      <a href='$ticketUrl' style='display:inline-block;background:#00ff88;color:#000;padding:12px 28px;text-decoration:none;border-radius:6px;font-weight:600'>Apri Ticket →</a>
    </div>
    <p style='text-align:center;color:#aaa;font-size:0.75rem;margin:0'>Ticket ID: #$ticketNumber</p>
  </div>

</div>
</body></html>";

    return sendEmail($adminEmail, "[Strike Zone] $emoji Ticket #$ticketNumber — $title", $html);
}

// ── 7. Risposta admin → utente ────────────────
function notifyUserTicketReply(string $userEmail, string $ticketNumber, string $title, string $reply): bool {
    if (!filter_var($userEmail, FILTER_VALIDATE_EMAIL)) return false;

    $appUrl    = rtrim(getenv('APP_URL') ?: 'https://web-production-e43fd.up.railway.app', '/');
    $ticketUrl = $appUrl . '/frontend/pages/tickets.html?ticket=' . urlencode($ticketNumber);
    $eTitle    = htmlspecialchars($title, ENT_QUOTES, 'UTF-8');
    $eReply    = nl2br(htmlspecialchars($reply, ENT_QUOTES, 'UTF-8'));
    $date      = date('d/m/Y H:i');

    $html = "<!DOCTYPE html><html><head><meta charset='utf-8'></head>
<body style='margin:0;padding:0;background:#f5f5f5;font-family:-apple-system,BlinkMacSystemFont,Arial,sans-serif'>
<div style='max-width:600px;margin:0 auto;background:#fff'>

  <div style='background:linear-gradient(135deg,#1a1a1a 0%,#2a2a2a 100%);padding:2rem;text-align:center'>
    <h1 style='color:#ffd700;margin:0;font-size:2rem'>🎳 STRIKE ZONE</h1>
    <p style='color:#999;margin:0.5rem 0 0;font-size:0.9rem'>Support Team</p>
  </div>

  <div style='padding:2rem'>
    <div style='text-align:center;margin-bottom:1.5rem'>
      <span style='background:#00ff88;color:#000;padding:0.5rem 1.5rem;border-radius:20px;font-size:0.85rem;font-weight:700'>TICKET #$ticketNumber</span>
    </div>
    <h2 style='color:#00ff88;margin:0 0 1.5rem'>💬 Nuova risposta dal team</h2>
    <div style='background:#f5f5f5;border-left:4px solid #00ff88;padding:1.25rem;margin:1.5rem 0'>
      <p style='margin:0 0 0.4rem;color:#888;font-size:0.82rem;font-weight:600'>OGGETTO:</p>
      <p style='margin:0;color:#333'>$eTitle</p>
    </div>
    <div style='background:#f9f9f9;border:1px solid #e0e0e0;border-radius:8px;padding:1.5rem;margin:2rem 0'>
      <div style='display:flex;align-items:center;gap:0.75rem;margin-bottom:1rem;padding-bottom:0.75rem;border-bottom:1px solid #e0e0e0'>
        <div style='width:36px;height:36px;background:#00ff88;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0'>👤</div>
        <div>
          <div style='font-weight:600;color:#333;font-size:0.9rem'>Strike Zone Support</div>
          <div style='color:#888;font-size:0.78rem'>$date</div>
        </div>
      </div>
      <div style='color:#333;line-height:1.7;white-space:pre-wrap'>$eReply</div>
    </div>
    <div style='text-align:center;margin:2.5rem 0'>
      <a href='$ticketUrl' style='display:inline-block;background:#00ff88;color:#000;padding:16px 40px;text-decoration:none;border-radius:8px;font-weight:700;font-size:1rem;box-shadow:0 4px 12px rgba(0,255,136,0.3)'>📋 Visualizza e rispondi al ticket</a>
    </div>
    <div style='background:#fff9e6;border:2px solid #ffd700;border-radius:8px;padding:1.5rem;margin:2rem 0'>
      <p style='margin:0 0 0.6rem;color:#333;font-weight:600'>⚠️ IMPORTANTE</p>
      <p style='margin:0;color:#666;line-height:1.6;font-size:0.92rem'><strong>Non rispondere direttamente a questa email.</strong><br>Per continuare la conversazione clicca il pulsante sopra e usa il sistema ticket integrato in Strike Zone.</p>
    </div>
  </div>

  <div style='background:#f5f5f5;padding:2rem;border-top:1px solid #e0e0e0;text-align:center'>
    <p style='margin:0 0 0.4rem;color:#666;font-size:0.9rem;font-weight:600'>Strike Zone Support</p>
    <p style='margin:0;font-size:0.85rem'><a href='https://strikezone.xyz' style='color:#00ff88;text-decoration:none'>strikezone.xyz</a></p>
    <p style='margin:0.8rem 0 0;color:#aaa;font-size:0.75rem'>Ticket ID: #$ticketNumber</p>
  </div>

</div>
</body></html>";

    return sendEmail($userEmail, "💬 Risposta al Ticket #$ticketNumber — Strike Zone", $html);
}

// ── 7. Notifica admin: nuovo giocatore ────────
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
