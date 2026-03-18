// ============================================
//  header.js — Inietta l'header identico in tutte le pagine
//
//  Uso: aggiungi <div id="app-header"></div> subito dopo <body>
//  e includi questo script prima di auth.js
//
//  Opzioni (attributi sul tag <div id="app-header">):
//    data-active="dashboard|sessioni|statistiche|giocatori"
//    data-extra-btn="giocatori"   → mostra il bottone + Giocatore
// ============================================

(function () {

  const SPLASH_HTML = `
<div id="splashScreen" style="position:fixed;inset:0;background:var(--bg);display:flex;flex-direction:column;align-items:center;justify-content:center;z-index:99999;transition:opacity 0.5s ease,visibility 0.5s ease">
  <div style="display:flex;flex-direction:column;align-items:center;gap:1.5rem">
    <div style="font-size:4rem;animation:splashWobble 0.6s ease-in-out infinite">🎳</div>
    <div style="text-align:center">
      <div style="font-family:'Black Han Sans',sans-serif;font-size:2.5rem;color:var(--neon);text-shadow:0 0 30px rgba(232,255,0,0.6);letter-spacing:0.1em">STRIKE ZONE</div>
      <div style="font-family:'Share Tech Mono',monospace;font-size:0.65rem;color:var(--text-muted);letter-spacing:0.3em;text-transform:uppercase;margin-top:0.3rem">Bowling Tracker v1.0</div>
    </div>
    <div style="width:200px;height:3px;background:var(--border);border-radius:2px;overflow:hidden">
      <div id="splashBar" style="height:100%;width:0%;background:var(--neon);box-shadow:0 0 8px var(--neon);border-radius:2px;transition:width 1.2s ease"></div>
    </div>
    <div style="font-family:'Share Tech Mono',monospace;font-size:0.65rem;color:var(--text-muted);letter-spacing:0.2em" id="splashText">CARICAMENTO...</div>
  </div>
</div>
<style>@keyframes splashWobble{0%,100%{transform:rotate(-8deg) scale(1)}50%{transform:rotate(8deg) scale(1.1)}}</style>`;

  const NAV_LINKS = [
    { href: 'index.html',       label: 'Dashboard',   key: 'dashboard'   },
    { href: 'sessioni.html',    label: 'Sessioni',     key: 'sessioni'    },
    { href: 'statistiche.html', label: 'Statistiche',  key: 'statistiche' },
    { href: 'giocatori.html',   label: 'Giocatori',    key: 'giocatori'   },
  ];

  function buildHeader(target) {
    const active   = target.dataset.active   || '';
    const extraBtn = target.dataset.extraBtn || '';

    const navHtml = NAV_LINKS.map(l =>
      `<a href="${l.href}"${active === l.key ? ' class="active"' : ''}>${l.label}</a>`
    ).join('');

    // Bottone extra solo su giocatori.html
    const extraBtnHtml = extraBtn === 'giocatori'
      ? `<button class="btn-header-extra auth-required" onclick="openAddModal()">+ Giocatore</button>`
      : '';

    target.outerHTML = `
${SPLASH_HTML}
<header>
  <div class="header-glow"></div>
  <div class="header-inner">
    <a href="index.html" class="logo" style="text-decoration:none">
      <div class="logo-pin">🎳</div>
      <div class="logo-text">
        <span class="logo-title">STRIKE ZONE</span>
        <span class="logo-sub">Bowling Tracker v1.0</span>
      </div>
    </a>
    <nav>${navHtml}</nav>
    <div class="header-actions">
      <button class="btn-new-session auth-required" onclick="openModal()">+ Nuova Partita</button>
      ${extraBtnHtml}
      <button id="themeToggle" class="theme-toggle" onclick="toggleTheme()" title="Cambia tema">☀️</button>
      <button class="btn-share" onclick="shareLink()">🔗 Condividi</button>
      <button class="btn-login auth-hidden" id="btnLogin" onclick="openLoginModal()">🔐 Accedi</button>
      <button class="btn-logout btn-secondary auth-required" id="btnLogout" onclick="logout()">Esci</button>
    </div>
  </div>
</header>`;
  }

  // Inietta subito se il DOM è pronto, altrimenti aspetta
  function inject() {
    const target = document.getElementById('app-header');
    if (target) buildHeader(target);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', inject);
  } else {
    inject();
  }

})();
