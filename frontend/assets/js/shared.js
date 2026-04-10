// ============================================
//  shared.js — Funzioni comuni a tutte le pagine
//  Incluso PRIMA di auth.js in ogni pagina
// ============================================

// ── TEMA ─────────────────────────────────────

function applyTheme(theme) {
  const btn = document.getElementById('themeToggle');
  if (theme === 'light') {
    document.body.classList.add('light');
    if (btn) btn.textContent = '🌙';
  } else {
    document.body.classList.remove('light');
    if (btn) btn.textContent = '☀️';
  }
  localStorage.setItem('theme', theme);
}

function toggleTheme() {
  applyTheme(document.body.classList.contains('light') ? 'dark' : 'light');
}

function initTheme() {
  applyTheme(localStorage.getItem('theme') || 'dark');
}

// Applica subito, prima del DOMContentLoaded, per evitare il flash
(function () {
  const s = localStorage.getItem('theme') || 'dark';
  if (s === 'light') document.body.classList.add('light');
})();

// ── SPLASH SCREEN ────────────────────────────

function initSplash() {
  const splash = document.getElementById('splashScreen');
  if (!splash) return;
  const bar = document.getElementById('splashBar');
  const txt = document.getElementById('splashText');
  if (bar) {
    setTimeout(() => { bar.style.width = '60%'; }, 50);
    setTimeout(() => { bar.style.width = '85%'; if (txt) txt.textContent = 'QUASI PRONTO...'; }, 600);
    setTimeout(() => { bar.style.width = '100%'; if (txt) txt.textContent = 'PRONTO! 🎳'; }, 1000);
  }
  setTimeout(() => {
    splash.style.opacity = '0';
    splash.style.visibility = 'hidden';
    setTimeout(() => splash.remove(), 500);
  }, 1500);
}

window.addEventListener('DOMContentLoaded', initSplash);

// ── GUEST MODE ───────────────────────────────

window.isGuestMode = new URLSearchParams(window.location.search).get('guest') === '1';

if (window.isGuestMode) {
  // Inietta CSS: nasconde elementi sensibili
  (function() {
    const style = document.createElement('style');
    style.textContent =
      '.payment-column{display:none!important}' +
      '.admin-only{display:none!important}' +
      '.action-btn-wrap{display:none!important}' +
      // statistiche.html: griglia classifica senza 3 colonne € (11→8 colonne dati)
      '.rank-table-header,.rank-table-row{grid-template-columns:36px 130px repeat(8,minmax(70px,1fr))!important}';
    document.head.appendChild(style);
  })();
}

// ── SHARE LINK ───────────────────────────────

function shareLink() {
  const url = 'https://web-production-e43fd.up.railway.app';
  const copy = (text) => {
    if (navigator.clipboard) {
      navigator.clipboard.writeText(text);
    } else {
      const el = document.createElement('textarea');
      el.value = text;
      document.body.appendChild(el);
      el.select();
      document.execCommand('copy');
      document.body.removeChild(el);
    }
    showToast('Link copiato negli appunti!');
  };
  copy(url);
}

// ── TOAST ────────────────────────────────────
// Definita qui come fallback globale; le pagine con logica propria
// possono sovrascriverla localmente (es. giocatori.js lo fa già).

if (typeof showToast === 'undefined') {
  window.showToast = function showToast(msg, type = 'success') {
    const t = document.getElementById('toast');
    if (!t) return;
    t.textContent = (type === 'success' ? '✓ ' : '✕ ') + msg;
    t.className = `toast ${type} show`;
    setTimeout(() => { t.className = 'toast'; }, 3500);
  };
}

// ── SERVICE WORKER ───────────────────────────

if ('serviceWorker' in navigator) {
  window.addEventListener('load', () => {
    navigator.serviceWorker
      .register('/service-worker.js', { scope: '/' })
      .then(reg => console.log('SW registrato:', reg.scope))
      .catch(err => console.log('SW errore:', err));
  });
}

// ── JWT HELPERS ──────────────────────────────

function getJWTPayload() {
  const token = localStorage.getItem('sz_auth_token');
  if (!token) return null;
  try {
    const parts = token.split('.');
    if (parts.length !== 3) return null;
    return JSON.parse(atob(parts[1].replace(/-/g, '+').replace(/_/g, '/')));
  } catch(e) { return null; }
}

function isSuperAdmin() {
  const p = getJWTPayload();
  return p && p.user_type === 'super_admin';
}

function getGroupId() {
  const p = getJWTPayload();
  return p ? (p.group_id || null) : null;
}

function hasPermission(permission) {
  const p = getJWTPayload();
  if (!p) return false;
  if (p.user_type === 'super_admin') return true;
  return p.permissions && p.permissions[permission] === true;
}

// ── EXPORT DATI (solo admin) ──────────────────

async function exportData() {
  const token = localStorage.getItem('sz_auth_token');
  if (!token) { showToast('Devi essere admin per esportare', 'error'); return; }

  const btn = document.querySelector('.btn-export');
  if (btn) { btn.disabled = true; btn.textContent = '⏳'; }

  try {
    const res = await fetch('/api/export.php', {
      headers: { 'Authorization': `Bearer ${token}` }
    });

    if (res.status === 401) {
      showToast('Non autorizzato', 'error');
      return;
    }

    const blob = await res.blob();
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `strikezone-backup-${new Date().toISOString().split('T')[0]}.json`;
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
    setTimeout(() => URL.revokeObjectURL(url), 1000);
    showToast('Backup scaricato!');
  } catch(e) {
    showToast('Errore durante l\'esportazione', 'error');
    console.error(e);
  }

  if (btn) { btn.disabled = false; btn.textContent = '💾'; }
}
