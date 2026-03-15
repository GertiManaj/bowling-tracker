// ============================================
//  auth.js — Modulo autenticazione condiviso
//  Includi questo script in tutte le pagine
// ============================================

const AUTH_API = 'api/auth.php';

// Stato globale autenticazione
window.isLoggedIn = false;

// ── VERIFICA SESSIONE AL CARICAMENTO ─────────
async function checkAuth() {
  try {
    const res  = await fetch(`${AUTH_API}?action=check`);
    const data = await res.json();
    window.isLoggedIn = data.logged_in;
    applyAuthUI();
    return data.logged_in;
  } catch (e) {
    window.isLoggedIn = false;
    applyAuthUI();
    return false;
  }
}

// ── APPLICA UI IN BASE ALLO STATO ────────────
function applyAuthUI() {
  // Mostra/nascondi elementi con classe auth-required (solo per loggati)
  document.querySelectorAll('.auth-required').forEach(el => {
    el.style.display = window.isLoggedIn ? '' : 'none';
  });

  // Mostra/nascondi elementi con classe auth-hidden (solo per non loggati)
  document.querySelectorAll('.auth-hidden').forEach(el => {
    el.style.display = window.isLoggedIn ? 'none' : '';
  });

  // Aggiorna bottone header
  const btnLogin  = document.getElementById('btnLogin');
  const btnLogout = document.getElementById('btnLogout');
  if (btnLogin)  btnLogin.style.display  = window.isLoggedIn ? 'none' : '';
  if (btnLogout) btnLogout.style.display = window.isLoggedIn ? '' : 'none';

  // Mostra/nascondi bottoni azione nelle tabelle/card
  document.querySelectorAll('.action-btn-wrap').forEach(el => {
    el.style.display = window.isLoggedIn ? '' : 'none';
  });
}

// ── LOGIN ────────────────────────────────────
async function submitLogin() {
  const btn      = document.getElementById('btnLoginSubmit');
  const password = document.getElementById('loginPassword').value;
  const errEl    = document.getElementById('loginError');

  if (!password) {
    errEl.textContent = 'Inserisci la password';
    errEl.style.display = 'block';
    return;
  }

  btn.disabled    = true;
  btn.textContent = 'Accesso...';
  errEl.style.display = 'none';

  try {
    const res  = await fetch(`${AUTH_API}?action=login`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ password })
    });
    const data = await res.json();

    if (data.success) {
      window.isLoggedIn = true;
      closeLoginModal();
      applyAuthUI();
      // Ricarica i dati della pagina per mostrare i controlli
      if (typeof loadStats       === 'function') loadStats();
      if (typeof loadLeaderboard === 'function') loadLeaderboard();
      if (typeof loadSessions    === 'function') loadSessions();
      if (typeof loadPlayers     === 'function') loadPlayers();
      if (typeof loadAll         === 'function') loadAll();
    } else {
      errEl.textContent   = data.error || 'Password errata';
      errEl.style.display = 'block';
      document.getElementById('loginPassword').value = '';
      document.getElementById('loginPassword').focus();
    }
  } catch (e) {
    errEl.textContent   = 'Errore di connessione';
    errEl.style.display = 'block';
  }

  btn.disabled    = false;
  btn.textContent = 'Accedi';
}

// ── LOGOUT ───────────────────────────────────
async function logout() {
  await fetch(`${AUTH_API}?action=logout`, { method: 'POST' });
  window.isLoggedIn = false;
  applyAuthUI();
}

// ── MODAL LOGIN ──────────────────────────────
function openLoginModal() {
  document.getElementById('loginPassword').value  = '';
  document.getElementById('loginError').style.display = 'none';
  document.getElementById('loginModalOverlay').classList.add('open');
  setTimeout(() => document.getElementById('loginPassword').focus(), 100);
}

function closeLoginModal() {
  document.getElementById('loginModalOverlay').classList.remove('open');
}

function handleLoginOverlayClick(e) {
  if (e.target === document.getElementById('loginModalOverlay')) closeLoginModal();
}

// Enter per confermare login
document.addEventListener('keydown', e => {
  if (e.key === 'Enter' && document.getElementById('loginModalOverlay')?.classList.contains('open')) {
    submitLogin();
  }
});

// Avvia controllo auth al caricamento
document.addEventListener('DOMContentLoaded', checkAuth);