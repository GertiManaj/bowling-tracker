// ============================================
//  modal-giocatore.js
//  Modal "Nuovo/Modifica Giocatore" — funziona
//  su tutte le pagine che includono questo file
// ============================================

const EMOJIS_G = [
  '🎳', '🐺', '🦊', '🐻', '🦁', '🐯', '🦋', '🐸',
  '🦅', '🐉', '🦈', '🐆', '🦎', '🐬', '🦄', '🐼',
  '🐙', '🐝', '🦉', '🦚', '🦀', '⚡', '🔥', '💎',
  '🏆', '👑', '🎯', '🚀', '💥', '🌟', '🎪', '🎭'
];

const EMOJIS_TOP = ['🎳', '🎯', '🏆', '🔥', '💎', '⚡', '👑', '🎲'];

let _gEditingId = null;
let _emailCheckTimeout = null;
let _nameCheckTimeout = null;

// ── EMOJI GRID ────────────────────────────────

function buildEmojiGrid(selected) {
  selected = selected || '🎳';
  var grid = document.getElementById('emojiGrid');
  if (!grid) return;

  // Override outer grid display so inner section divs stack vertically
  grid.style.display = 'block';

  var topHtml = '<div style="margin-bottom:0.8rem;padding-bottom:0.8rem;border-bottom:1px solid rgba(255,255,255,0.08)">';
  topHtml += '<div style="color:var(--text-muted);font-size:0.68rem;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0.4rem">⭐ Più usati</div>';
  topHtml += '<div style="display:grid;grid-template-columns:repeat(8,1fr);gap:0.3rem">';
  EMOJIS_TOP.forEach(function (e) {
    topHtml += '<button type="button" class="emoji-btn' + (e === selected ? ' selected' : '') +
      '" onclick="selectEmoji(\'' + e + '\', this)">' + e + '</button>';
  });
  topHtml += '</div></div>';

  var allHtml = '<div style="color:var(--text-muted);font-size:0.68rem;letter-spacing:0.12em;text-transform:uppercase;margin-bottom:0.4rem">Tutti</div>';
  allHtml += '<div style="display:grid;grid-template-columns:repeat(8,1fr);gap:0.3rem">';
  EMOJIS_G.forEach(function (e) {
    allHtml += '<button type="button" class="emoji-btn' + (e === selected ? ' selected' : '') +
      '" onclick="selectEmoji(\'' + e + '\', this)">' + e + '</button>';
  });
  allHtml += '</div>';

  grid.innerHTML = topHtml + allHtml;
  document.getElementById('selectedEmoji').value = selected;
}

function selectEmoji(emoji, btn) {
  document.querySelectorAll('.emoji-btn').forEach(function (b) { b.classList.remove('selected'); });
  btn.classList.add('selected');
  document.getElementById('selectedEmoji').value = emoji;
}

// ── OPEN / CLOSE ──────────────────────────────

function openAddModal() {
  if (!window.isLoggedIn) { openLoginModal(); return; }
  _gEditingId = null;
  document.getElementById('gModalTitle').textContent = '➕ Nuovo Giocatore';
  document.getElementById('playerName').value = '';
  document.getElementById('playerNickname').value = '';
  document.getElementById('playerNickname').style.opacity = '1';
  document.getElementById('playerEmail').value = '';
  document.getElementById('btnSavePlayer').textContent = 'Salva';

  // Salva e Crea Altro: visible in create mode
  var btnSAN = document.getElementById('btnSaveAndNew');
  if (btnSAN) btnSAN.style.display = 'inline-block';

  // Welcome email toggle: visible in create mode
  var wes = document.getElementById('welcomeEmailSection');
  if (wes) wes.style.display = 'block';
  var chk = document.getElementById('sendWelcomeEmail');
  if (chk) chk.checked = true;

  buildEmojiGrid('🎳');
  _clearModalWarnings();
  document.getElementById('gModalOverlay').classList.add('open');
  setTimeout(function () { document.getElementById('playerName').focus(); }, 100);
}

function openEditModal(id) {
  if (!window.isLoggedIn) { openLoginModal(); return; }
  var players = (typeof allPlayers !== 'undefined') ? allPlayers : [];
  var p = players.find(function (x) { return x.id === id; });
  if (!p) return;

  _gEditingId = id;
  document.getElementById('gModalTitle').textContent = '✏ Modifica Giocatore';
  document.getElementById('playerName').value = p.name;
  document.getElementById('playerNickname').value = p.nickname || '';
  document.getElementById('playerNickname').style.opacity = '1';
  document.getElementById('playerEmail').value = p.email || p.account_email || '';
  document.getElementById('btnSavePlayer').textContent = 'Aggiorna';

  // Salva e Crea Altro: hidden in edit mode
  var btnSAN = document.getElementById('btnSaveAndNew');
  if (btnSAN) btnSAN.style.display = 'none';

  // Welcome email toggle: hidden in edit mode
  var wes = document.getElementById('welcomeEmailSection');
  if (wes) wes.style.display = 'none';

  buildEmojiGrid(p.emoji || '🎳');
  _clearModalWarnings();
  document.getElementById('gModalOverlay').classList.add('open');
  setTimeout(function () { document.getElementById('playerName').focus(); }, 100);
}

function closeGModal() {
  document.getElementById('gModalOverlay').classList.remove('open');
  _gEditingId = null;
  clearTimeout(_emailCheckTimeout);
  clearTimeout(_nameCheckTimeout);
  _clearModalWarnings();
}

function handleGModalOverlayClick(e) {
  if (e.target === document.getElementById('gModalOverlay')) closeGModal();
}

// ── WARNINGS CLEANUP ──────────────────────────

function _clearModalWarnings() {
  var emailInput = document.getElementById('playerEmail');
  if (emailInput) {
    emailInput.style.borderColor = '';
    // Remove positioned indicator
    var ind = emailInput.parentNode.querySelector('.email-indicator');
    if (ind) ind.remove();
    // Remove email-error
    var err = emailInput.nextElementSibling;
    if (err && err.classList.contains('email-error')) err.remove();
  }
  // Clear duplicate warnings (leave divs in place, just empty them)
  var emailWarn = document.getElementById('emailDuplicateWarning');
  if (emailWarn) emailWarn.innerHTML = '';
  var nameWarn = document.getElementById('nameDuplicateWarning');
  if (nameWarn) nameWarn.innerHTML = '';

  var nameInput = document.getElementById('playerName');
  if (nameInput) nameInput.style.borderColor = '';
}

// ── EMAIL VALIDATION REAL-TIME ────────────────

function _getOrCreateEmailIndicator() {
  var emailInput = document.getElementById('playerEmail');
  if (!emailInput) return null;
  var indicator = emailInput.parentNode.querySelector('.email-indicator');
  if (!indicator) {
    indicator = document.createElement('span');
    indicator.className = 'email-indicator';
    indicator.style.cssText = 'position:absolute;right:10px;top:50%;transform:translateY(-50%);font-size:1.1rem;pointer-events:none';
    emailInput.parentNode.style.position = 'relative';
    emailInput.style.paddingRight = '2.5rem';
    emailInput.parentNode.appendChild(indicator);
  }
  return indicator;
}

function validateEmailRealtime(input) {
  var email = input.value.trim();
  var indicator = _getOrCreateEmailIndicator();

  // Remove existing inline error when typing
  var err = input.nextElementSibling;
  if (err && err.classList.contains('email-error')) err.remove();

  if (!email) {
    if (indicator) indicator.textContent = '';
    input.style.borderColor = '';
    var w = document.getElementById('emailDuplicateWarning');
    if (w) w.innerHTML = '';
    return;
  }

  if (typeof isValidEmail === 'function' && isValidEmail(email)) {
    if (indicator) indicator.textContent = '✅';
    input.style.borderColor = 'var(--neon, #e8ff00)';
  } else {
    if (indicator) indicator.textContent = '❌';
    input.style.borderColor = 'var(--neon2, #ff3cac)';
    var w = document.getElementById('emailDuplicateWarning');
    if (w) w.innerHTML = '';
  }
}

// ── EMAIL DUPLICATE CHECK ─────────────────────

async function checkEmailDuplicate(email) {
  var excludeId = _gEditingId || 0;
  // Build group_id param for super_admin
  var gp = '';
  if (typeof isSuperAdmin === 'function' && isSuperAdmin()) {
    var sel = localStorage.getItem('sz_selected_group');
    if (sel && sel !== 'all') gp = '&group_id=' + parseInt(sel);
  }

  try {
    var res = await authFetch(
      '/api/players.php?check_email=' + encodeURIComponent(email) + '&exclude_id=' + excludeId + gp
    );
    var data = await res.json();

    var emailInput = document.getElementById('playerEmail');
    var warning = document.getElementById('emailDuplicateWarning');
    if (!warning) {
      warning = document.createElement('div');
      warning.id = 'emailDuplicateWarning';
      warning.style.cssText = 'font-size:0.8rem;margin-top:0.3rem;font-family:\'Share Tech Mono\',monospace';
      emailInput.parentNode.appendChild(warning);
    }

    if (data.exists) {
      warning.innerHTML = '⚠️ Email già usata da <strong>' + escHtml(data.player_name) + '</strong>';
      warning.style.color = 'var(--neon2, #ff3cac)';
      emailInput.style.borderColor = 'var(--neon2, #ff3cac)';
      var indicator = emailInput.parentNode.querySelector('.email-indicator');
      if (indicator) indicator.textContent = '⚠️';
      return false;
    } else {
      warning.innerHTML = '';
      return true;
    }
  } catch (e) {
    console.error('Errore check email:', e);
    return true;
  }
}

// ── NAME DUPLICATE CHECK (soft warning) ───────

async function checkNameDuplicate(name) {
  var excludeId = _gEditingId || 0;
  var gp = '';
  if (typeof isSuperAdmin === 'function' && isSuperAdmin()) {
    var sel = localStorage.getItem('sz_selected_group');
    if (sel && sel !== 'all') gp = '&group_id=' + parseInt(sel);
  }

  try {
    var res = await authFetch(
      '/api/players.php?check_name=' + encodeURIComponent(name) + '&exclude_id=' + excludeId + gp
    );
    var data = await res.json();

    var nameInput = document.getElementById('playerName');
    var warning = document.getElementById('nameDuplicateWarning');
    if (!warning) {
      warning = document.createElement('div');
      warning.id = 'nameDuplicateWarning';
      warning.style.cssText = 'font-size:0.8rem;margin-top:0.3rem;font-family:\'Share Tech Mono\',monospace';
      nameInput.parentNode.appendChild(warning);
    }

    if (data.exists) {
      warning.innerHTML = 'ℹ️ Esiste già un giocatore con nome simile';
      warning.style.color = 'var(--text-muted, #888)';
      // Soft warning: no red border
    } else {
      warning.innerHTML = '';
    }
  } catch (e) {
    console.error('Errore check nome:', e);
  }
}

// ── SAVE ──────────────────────────────────────

async function savePlayer(createAnother) {
  createAnother = createAnother === true;

  var btn = document.getElementById('btnSavePlayer');
  var name = document.getElementById('playerName').value.trim();
  var nickname = document.getElementById('playerNickname').value.trim();
  var email = document.getElementById('playerEmail').value.trim();
  var emoji = document.getElementById('selectedEmoji').value;

  if (!name) {
    if (typeof showToast === 'function') showToast('Il nome è obbligatorio', 'error');
    document.getElementById('playerName').focus();
    return;
  }

  var emailInput = document.getElementById('playerEmail');
  if (email && typeof validateEmailInput === 'function' && !validateEmailInput(emailInput)) {
    if (typeof showToast === 'function') showToast('Email non valida (es: nome@email.com)', 'error');
    emailInput.focus();
    return;
  }

  var wasEditing = !!_gEditingId;
  btn.disabled = true;
  btn.textContent = 'Salvataggio...';

  try {
    var method = _gEditingId ? 'PUT' : 'POST';
    var sendEmail = !_gEditingId && (document.getElementById('sendWelcomeEmail')?.checked !== false);
    var payload = _gEditingId
      ? { id: _gEditingId, name: name, nickname: nickname, emoji: emoji, email: email || null }
      : { name: name, nickname: nickname, emoji: emoji, email: email || null, send_email: sendEmail };

    var res = await authFetch('/api/players.php', {
      method: method,
      body: JSON.stringify(payload)
    });
    var data = await res.json();

    if (data.success) {
      if (typeof loadPlayers === 'function') loadPlayers();
      if (typeof loadLeaderboard === 'function') loadLeaderboard();
      if (typeof loadAll === 'function') loadAll();
      if (typeof loadStats === 'function') loadStats();

      if (createAnother) {
        // Reset form but keep modal open for quick multi-add
        document.getElementById('playerName').value = '';
        document.getElementById('playerNickname').value = '';
        document.getElementById('playerNickname').style.opacity = '1';
        document.getElementById('playerEmail').value = '';
        buildEmojiGrid('🎳');
        _clearModalWarnings();
        if (typeof showToast === 'function') showToast(name + ' aggiunto! Puoi aggiungerne un altro');
        setTimeout(function () { document.getElementById('playerName').focus(); }, 50);
      } else {
        closeGModal();
        if (typeof showToast === 'function')
          showToast(wasEditing ? name + ' aggiornato!' : name + ' aggiunto al gruppo!');
      }
    } else if (data.code === 'EMAIL_ACCOUNT_EXISTS') {
      var emailInput = document.getElementById('playerEmail');
      if (emailInput) emailInput.style.borderColor = '#ff4444';
      var w = document.getElementById('emailDuplicateWarning');
      if (w) { w.innerHTML = '❌ Email già associata a un account esistente'; w.style.color = '#ff4444'; }
      if (typeof showToast === 'function')
        showToast('Email già usata da un account esistente — disattiva "Invia email" per salvare solo l\'indirizzo', 'error');
    } else {
      if (typeof showToast === 'function') showToast(data.error || 'Errore nel salvataggio', 'error');
    }
  } catch (e) {
    if (typeof showToast === 'function') showToast('Errore di connessione', 'error');
    console.error(e);
  }

  btn.disabled = false;
  btn.textContent = wasEditing ? 'Aggiorna' : 'Salva';
}

// ── DOM LISTENERS ─────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
  var emailInput = document.getElementById('playerEmail');
  var nameInput = document.getElementById('playerName');
  var nicknameInput = document.getElementById('playerNickname');

  if (emailInput) {
    // Real-time indicator while typing
    emailInput.addEventListener('input', function () {
      validateEmailRealtime(this);

      // Debounced duplicate check
      clearTimeout(_emailCheckTimeout);
      var val = this.value.trim();
      if (val && typeof isValidEmail === 'function' && isValidEmail(val)) {
        _emailCheckTimeout = setTimeout(function () {
          checkEmailDuplicate(val);
        }, 500);
      } else {
        var w = document.getElementById('emailDuplicateWarning');
        if (w) w.innerHTML = '';
      }
    });

    // On blur: run full validation (shows inline error text if invalid)
    emailInput.addEventListener('blur', function () {
      if (typeof validateEmailInput === 'function') validateEmailInput(this);
    });
  }

  if (nameInput) {
    // Clear soft warning while typing
    nameInput.addEventListener('input', function () {
      var w = document.getElementById('nameDuplicateWarning');
      if (w) w.innerHTML = '';
    });

    // On blur: check duplicate name + auto-generate nickname
    nameInput.addEventListener('blur', function () {
      var name = this.value.trim();
      if (name) {
        // Debounced name check
        clearTimeout(_nameCheckTimeout);
        _nameCheckTimeout = setTimeout(function () {
          checkNameDuplicate(name);
        }, 300);
      }

      // Auto-generate nickname if empty
      if (nicknameInput && nicknameInput.value.trim() === '' && name) {
        var generated = name.split(' ')[0].toUpperCase();
        nicknameInput.value = generated;
        nicknameInput.style.opacity = '0.65';
        nicknameInput.addEventListener('input', function () {
          this.style.opacity = '1';
        }, { once: true });
      }
    });
  }
});

// Enter per salvare
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && document.getElementById('gModalOverlay')?.classList.contains('open')) {
    savePlayer(false);
  }
  if (e.key === 'Escape') closeGModal();
});
