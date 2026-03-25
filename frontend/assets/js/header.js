// ============================================
//  header.js — Inietta header e splash
// ============================================

var NAV_LINKS = [
  { href: 'index.html',       label: 'Dashboard',   key: 'dashboard'   },
  { href: 'sessioni.html',    label: 'Sessioni',     key: 'sessioni'    },
  { href: 'statistiche.html', label: 'Statistiche',  key: 'statistiche' },
  { href: 'giocatori.html',   label: 'Giocatori',    key: 'giocatori'   },
];

// ── HAMBURGER ────────────────────────────────
function toggleHamburgerMenu() {
  var loggedIn = window.isLoggedIn || false;

  if (!loggedIn) {
    // Non loggato → mostra toast errore
    if (typeof showToast === 'function') {
      showToast('Devi prima accedere come amministratore', 'error');
    } else {
      alert('Devi prima accedere come amministratore');
    }
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

function updateHamburgerSections() {
  // Nulla da fare — il menu è solo per admin, la visibilità è gestita da toggleHamburgerMenu
}

(function () {

  function buildHTML(active, extraBtn) {

    var navHtml = NAV_LINKS.map(function(l) {
      return '<a href="' + l.href + '"' + (active === l.key ? ' class="active"' : '') + '>' + l.label + '</a>';
    }).join('');

    var newGiocatoreBtn = extraBtn === 'giocatori'
      ? '<button class="hamburger-item" onclick="openAddModal();closeHamburgerMenu()">➕ Nuovo Giocatore</button>'
      : '';

    var splashHtml = '<div id="splashScreen" style="position:fixed;inset:0;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:99999;transition:opacity 0.5s ease,visibility 0.5s ease"><div style="display:flex;flex-direction:column;align-items:center;gap:1.5rem"><div style="font-size:4rem;animation:splashWobble 0.6s ease-in-out infinite">🎳</div><div style="text-align:center"><div style="font-family:\'Black Han Sans\',sans-serif;font-size:2.5rem;color:var(--neon);text-shadow:0 0 30px rgba(232,255,0,0.6);letter-spacing:0.1em">STRIKE ZONE</div><div style="font-family:\'Share Tech Mono\',monospace;font-size:0.65rem;color:var(--text-muted);letter-spacing:0.3em;text-transform:uppercase;margin-top:0.3rem">Bowling Tracker v1.0</div></div><div style="width:200px;height:3px;background:var(--border);border-radius:2px;overflow:hidden"><div id="splashBar" style="height:100%;width:0%;background:var(--neon);box-shadow:0 0 8px var(--neon);border-radius:2px;transition:width 1.2s ease"></div></div><div style="font-family:\'Share Tech Mono\',monospace;font-size:0.65rem;color:var(--text-muted);letter-spacing:0.2em" id="splashText">CARICAMENTO...</div></div></div><style>@keyframes splashWobble{0%,100%{transform:rotate(-8deg) scale(1)}50%{transform:rotate(8deg) scale(1.1)}}</style>';

    var menuHtml =
      '<div class="hamburger-wrap">' +
        '<button class="btn-hamburger" onclick="toggleHamburgerMenu()" title="Menu">☰</button>' +
        '<div class="hamburger-menu" id="hamburgerMenu">' +
          '<div class="hamburger-label">Azioni Admin</div>' +
          '<button class="hamburger-item" onclick="openModal();closeHamburgerMenu()">🎳 Nuova Partita</button>' +
          newGiocatoreBtn +
          '<button class="hamburger-item" onclick="exportData();closeHamburgerMenu()">💾 Backup Database</button>' +
          '<div class="hamburger-divider"></div>' +
          '<button class="hamburger-item hamburger-logout" onclick="logout();closeHamburgerMenu()">🚪 Esci</button>' +
        '</div>' +
      '</div>';

    var headerHtml =
      '<header>' +
        '<div class="header-glow"></div>' +
        '<div class="header-inner">' +
          '<a href="index.html" class="logo" style="text-decoration:none">' +
            '<div class="logo-pin">🎳</div>' +
            '<div class="logo-text">' +
              '<span class="logo-title">STRIKE ZONE</span>' +
              '<span class="logo-sub">Bowling Tracker v1.0</span>' +
            '</div>' +
          '</a>' +
          '<nav>' + navHtml + '</nav>' +
          '<div class="header-actions">' +
            '<button id="themeToggle" class="theme-toggle" onclick="toggleTheme()" title="Cambia tema">☀️</button>' +
            '<button class="btn-share" onclick="shareLink()">🔗 Condividi</button>' +
            '<button class="btn-login auth-hidden" id="btnLogin" onclick="openLoginModal()">🔐 Accedi</button>' +
            menuHtml +
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

    // Chiudi menu cliccando fuori
    document.addEventListener('click', function(e) {
      var wrap = document.querySelector('.hamburger-wrap');
      if (wrap && !wrap.contains(e.target)) closeHamburgerMenu();
    });
  }

  inject();

})();