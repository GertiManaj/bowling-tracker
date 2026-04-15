// ============================================
//  header.js — Inietta header e splash
// ============================================

var NAV_LINKS = [
  { href: 'index.html',       label: 'Dashboard',   key: 'dashboard'   },
  { href: 'sessioni.html',    label: 'Sessioni',     key: 'sessioni'    },
  { href: 'statistiche.html', label: 'Statistiche',  key: 'statistiche' },
  { href: 'giocatori.html',   label: 'Giocatori',    key: 'giocatori'   },
  { href: 'tickets.html',     label: '🎫 Feedback',  key: 'tickets'     },
];

// ── HAMBURGER ────────────────────────────────
function toggleHamburgerMenu() {
  // Usa isTokenValid() come fallback per evitare race condition con checkAuth() async
  var loggedIn = window.isLoggedIn ||
    (typeof isTokenValid === 'function' && isTokenValid() && !window.isPlayerLoggedIn);
  if (!loggedIn) {
    if (typeof showToast === 'function') showToast('Devi prima accedere come amministratore', 'error');
    else alert('Devi prima accedere come amministratore');
    return;
  }
  var menu = document.getElementById('hamburgerMenu');
  if (!menu) return;
  menu.classList.toggle('open');
}

function closeHamburgerMenu() {
  var menu = document.getElementById('hamburgerMenu');
  if (menu) menu.classList.remove('open');
}

// ── PLAYER DROPDOWN ──────────────────────────
function togglePlayerMenu() {
  var dd = document.getElementById('playerDropdown');
  if (dd) dd.style.display = dd.style.display === 'none' ? 'block' : 'none';
}

function closePlayerMenu() {
  var dd = document.getElementById('playerDropdown');
  if (dd) dd.style.display = 'none';
}

function updateHamburgerSections() {
  var menu = document.getElementById('hamburgerMenu');
  if (!menu) return;
  var superAdmin = typeof isSuperAdmin === 'function' && isSuperAdmin();

  // Backup e Ticket: solo super_admin
  var backupBtn = document.getElementById('backupMenuBtn');
  var ticketBtn = document.getElementById('ticketMenuBtn');
  if (backupBtn) backupBtn.style.display = superAdmin ? '' : 'none';
  if (ticketBtn) ticketBtn.style.display = superAdmin ? '' : 'none';

  // Bottone Super Admin panel
  var existing = document.getElementById('superAdminMenuBtn');
  if (superAdmin) {
    if (!existing) {
      var dividers = menu.querySelectorAll('.hamburger-divider');
      var targetDivider = dividers.length >= 2 ? dividers[dividers.length - 1] : null;
      var btn = document.createElement('button');
      btn.id        = 'superAdminMenuBtn';
      btn.className = 'hamburger-item';
      btn.textContent = '🌐 Super Admin';
      btn.onclick = function() { window.location.href = 'super-admin.html'; closeHamburgerMenu(); };
      if (targetDivider) {
        menu.insertBefore(btn, targetDivider);
      } else {
        var logoutBtn = menu.querySelector('.hamburger-logout');
        if (logoutBtn) menu.insertBefore(btn, logoutBtn);
        else menu.appendChild(btn);
      }
    }
  } else {
    if (existing) existing.remove();
  }
}

// ── BADGE NOTIFICA TICKET ────────────────────
function loadTicketBadge() {
  fetch('/api/tickets.php')
    .then(function(r) { return r.json(); })
    .then(function(data) {
      var unread = data.unread || 0;
      var badge  = document.getElementById('ticketBadge');
      var btnBadge = document.getElementById('ticketBtnBadge');
      if (badge) {
        badge.textContent = unread > 0 ? unread : '';
        badge.style.display = unread > 0 ? 'flex' : 'none';
      }
      if (btnBadge) {
        btnBadge.textContent = unread > 0 ? ' ('+unread+')' : '';
      }
    })
    .catch(function() {});
}

(function () {

  function buildHTML(active, extraBtn) {
    var isGuest  = window.isGuestMode || false;
    var isPlayer = window.isPlayerLoggedIn || false;
    var guestSuffix = (isGuest && !isPlayer) ? '?guest=1' : '';

    var navHtml = NAV_LINKS.map(function(l) {
      var href = l.href + guestSuffix;
      return '<a href="' + href + '"' + (active === l.key ? ' class="active"' : '') + '>' + l.label + '</a>';
    }).join('');

    // Badge ospite (solo guest URL, non player)
    var guestBadgeHtml = (isGuest && !isPlayer)
      ? '<div style="font-family:\'Share Tech Mono\',monospace;font-size:0.58rem;letter-spacing:0.12em;text-transform:uppercase;background:rgba(255,255,255,0.06);border:1px solid var(--border);border-radius:20px;padding:0.2rem 0.7rem;color:var(--text-muted)">👁 Ospite</div>'
      : '';

    // Badge player (visibile quando loggato come giocatore)
    var playerBadgeHtml = isPlayer
      ? '<div class="player-badge-wrap" style="position:relative">' +
          '<button id="playerBadge" onclick="togglePlayerMenu()" style="' +
            'display:flex;align-items:center;gap:0.4rem;' +
            'background:rgba(0,229,255,0.08);border:1px solid var(--neon2);' +
            'border-radius:20px;padding:0.35rem 0.85rem;cursor:pointer;' +
            'font-family:\'Barlow Condensed\',sans-serif;font-weight:600;font-size:0.88rem;' +
            'color:var(--neon2);letter-spacing:0.05em;transition:all 0.2s">' +
            '<span id="playerBadgeEmoji">' + (window.currentPlayerEmoji || '🎳') + '</span>' +
            '<span id="playerBadgeName">'  + (window.currentPlayerName  || 'Giocatore') + '</span>' +
            '<span style="font-size:0.55rem;opacity:0.7;margin-left:2px">▾</span>' +
          '</button>' +
          '<div id="playerDropdown" style="display:none;position:absolute;right:0;top:calc(100% + 6px);' +
            'background:var(--surface);border:1px solid var(--border);border-radius:8px;' +
            'min-width:170px;z-index:10000;overflow:hidden;box-shadow:0 8px 24px rgba(0,0,0,0.5)">' +
            '<a href="player-profile.html" onclick="closePlayerMenu()" style="display:block;padding:0.75rem 1rem;' +
              'color:var(--text);text-decoration:none;font-family:\'Barlow Condensed\',sans-serif;font-weight:600;' +
              'font-size:0.9rem;letter-spacing:0.05em;border-bottom:1px solid var(--border);transition:background 0.15s"' +
              'onmouseover="this.style.background=\'rgba(255,255,255,0.04)\'" onmouseout="this.style.background=\'\'">' +
              '👤 Il mio profilo</a>' +
            '<button onclick="logout();closePlayerMenu()" style="width:100%;background:none;border:none;' +
              'padding:0.75rem 1rem;text-align:left;cursor:pointer;color:var(--neon2);' +
              'font-family:\'Barlow Condensed\',sans-serif;font-weight:600;font-size:0.9rem;' +
              'letter-spacing:0.05em;transition:background 0.15s"' +
              'onmouseover="this.style.background=\'rgba(255,60,172,0.06)\'" onmouseout="this.style.background=\'\'">' +
              '🚪 Esci</button>' +
          '</div>' +
        '</div>'
      : '';

    var splashHtml = '<div id="splashScreen" style="position:fixed;inset:0;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:99999;transition:opacity 0.5s ease,visibility 0.5s ease"><div style="display:flex;flex-direction:column;align-items:center;gap:1.5rem"><div style="font-size:4rem;animation:splashWobble 0.6s ease-in-out infinite">🎳</div><div style="text-align:center"><div style="font-family:\'Black Han Sans\',sans-serif;font-size:2.5rem;color:var(--neon);text-shadow:0 0 30px rgba(232,255,0,0.6);letter-spacing:0.1em">STRIKE ZONE</div><div style="font-family:\'Share Tech Mono\',monospace;font-size:0.65rem;color:var(--text-muted);letter-spacing:0.3em;text-transform:uppercase;margin-top:0.3rem">Bowling Tracker v1.0</div></div><div style="width:200px;height:3px;background:var(--border);border-radius:2px;overflow:hidden"><div id="splashBar" style="height:100%;width:0%;background:var(--neon);box-shadow:0 0 8px var(--neon);border-radius:2px;transition:width 1.2s ease"></div></div><div style="font-family:\'Share Tech Mono\',monospace;font-size:0.65rem;color:var(--text-muted);letter-spacing:0.2em" id="splashText">CARICAMENTO...</div></div></div><style>@keyframes splashWobble{0%,100%{transform:rotate(-8deg) scale(1)}50%{transform:rotate(8deg) scale(1.1)}}</style>';

    // Badge ticket (visibile solo admin, caricato dopo)
    var ticketBadgeHtml =
      '<div id="ticketBadge" style="' +
        'display:none;position:absolute;top:-6px;right:-6px;' +
        'background:var(--neon2);color:#fff;' +
        'border-radius:50%;width:18px;height:18px;' +
        'font-size:0.6rem;font-weight:700;font-family:\'Share Tech Mono\',monospace;' +
        'align-items:center;justify-content:center;' +
        'box-shadow:0 0 8px rgba(255,60,172,0.6);' +
        'pointer-events:none;z-index:10' +
      '"></div>';

    var menuHtml =
      '<div class="hamburger-wrap" style="position:relative">' +
        '<button class="btn-hamburger" onclick="toggleHamburgerMenu()" title="Menu" style="position:relative">☰' +
          ticketBadgeHtml +
        '</button>' +
        '<div class="hamburger-menu" id="hamburgerMenu">' +
          '<div class="hamburger-label">Azioni Admin</div>' +
          '<button class="hamburger-item" onclick="openModal();closeHamburgerMenu()">🎳 Nuova Partita</button>' +
          '<button class="hamburger-item" onclick="openAddModal();closeHamburgerMenu()">➕ Nuovo Giocatore</button>' +
          '<button id="backupMenuBtn" class="hamburger-item" onclick="exportData();closeHamburgerMenu()" style="display:none">💾 Backup Database</button>' +
          '<div class="hamburger-divider"></div>' +
          '<button id="ticketMenuBtn" class="hamburger-item" onclick="window.location.href=\'tickets.html\';closeHamburgerMenu()" style="display:none">🎫 Ticket<span id="ticketBtnBadge" style="color:var(--neon2);font-weight:700"></span></button>' +
          '<div class="hamburger-divider"></div>' +
          (typeof isSuperAdmin === 'function' && isSuperAdmin()
            ? '<button id="superAdminMenuBtn" class="hamburger-item" onclick="window.location.href=\'super-admin.html\';closeHamburgerMenu()">🌐 Super Admin</button>'
            : '') +
          '<button class="hamburger-item" onclick="openChangePasswordModal();closeHamburgerMenu()">🔐 Cambia Password</button>' +
          '<button class="hamburger-item" onclick="openTrustedDevicesModal();closeHamburgerMenu()">🛡 Dispositivi Fidati</button>' +
          '<button class="hamburger-item" onclick="openSecurityLogsModal();closeHamburgerMenu()">🔒 Security Logs</button>' +
          '<button class="hamburger-item hamburger-logout" onclick="logout();closeHamburgerMenu()">🚪 Esci</button>' +
        '</div>' +
      '</div>';

    var headerHtml =
      '<header>' +
        '<div class="header-glow"></div>' +
        '<div class="header-inner">' +
          '<a href="index.html' + guestSuffix + '" class="logo" style="text-decoration:none">' +
            '<div class="logo-pin">🎳</div>' +
            '<div class="logo-text">' +
              '<span class="logo-title">STRIKE ZONE</span>' +
              '<span class="logo-sub">Bowling Tracker v1.0</span>' +
            '</div>' +
          '</a>' +
          '<nav>' + navHtml + '</nav>' +
          '<div class="header-actions">' +
            guestBadgeHtml +
            '<button id="themeToggle" class="theme-toggle" onclick="toggleTheme()" title="Cambia tema">☀️</button>' +
            '<button class="btn-share" onclick="shareLink()">🔗 Condividi</button>' +
            (isPlayer
              ? playerBadgeHtml
              : (isGuest
                  ? '<a href="welcome.html" style="font-family:\'Barlow Condensed\',sans-serif;font-weight:600;font-size:0.85rem;letter-spacing:0.1em;text-transform:uppercase;color:var(--text-muted);text-decoration:none;padding:0.5rem 0.8rem;border:1px solid var(--border);border-radius:6px;transition:all 0.2s" onmouseover="this.style.color=\'var(--neon)\';this.style.borderColor=\'var(--neon)\'" onmouseout="this.style.color=\'\';this.style.borderColor=\'\'">🔐 Accedi</a>'
                  : '<button class="btn-login auth-hidden" id="btnLogin" onclick="openLoginModal()">🔐 Accedi</button>' + menuHtml)) +
          '</div>' +
        '</div>' +
      '</header>';

    return splashHtml + headerHtml;
  }

  function inject() {
    var placeholder = document.getElementById('app-header');
    if (!placeholder) return;
    var active   = placeholder.getAttribute('data-active')   || '';
    var extraBtn = placeholder.getAttribute('data-extra-btn') || '';
    placeholder.insertAdjacentHTML('beforebegin', buildHTML(active, extraBtn));
    placeholder.parentNode.removeChild(placeholder);
    if (typeof initTheme === 'function') initTheme();
    if (typeof applyAuthUI === 'function') applyAuthUI();
    document.addEventListener('click', function(e) {
      var wrap = document.querySelector('.hamburger-wrap');
      if (wrap && !wrap.contains(e.target)) closeHamburgerMenu();
      var pbWrap = document.querySelector('.player-badge-wrap');
      if (pbWrap && !pbWrap.contains(e.target)) closePlayerMenu();
    });
    // Carica badge ticket dopo auth
    setTimeout(function() {
      if (window.isLoggedIn) loadTicketBadge();
    }, 800);
  }

  inject();

})();
