// ============================================
//  auth.js — Autenticazione JWT con localStorage
//  Il token viene salvato nel browser e persiste
//  tra le pagine per 24 ore senza popup
// ============================================

const AUTH_API    = '/api/auth.php';
const TOKEN_KEY   = 'sz_auth_token';

window.isLoggedIn = false;

// ── UTILITY TOKEN ────────────────────────────

function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

function saveToken(token) {
  localStorage.setItem(TOKEN_KEY, token);
}

function removeToken() {
  localStorage.removeItem(TOKEN_KEY);
}

// ── VERIFICA TOKEN (lato client, veloce) ─────
function isTokenValid() {
  const token = getToken();
  if (!token) return false;
  try {
    const payload = JSON.parse(atob(token.split('.')[1].replace(/-/g,'+').replace(/_/g,'/')));
    return payload.exp > Math.floor(Date.now() / 1000);
  } catch(e) {
    return false;
  }
}

// ── CHECK AUTH ───────────────────────────────
async function checkAuth() {
  // Controlla prima lato client (veloce, nessuna chiamata API)
  if (!isTokenValid()) {
    removeToken();
    window.isLoggedIn = false;
    applyAuthUI();
    return false;
  }

  // Verifica anche lato server (sicuro)
  try {
    const res  = await fetch(`${AUTH_API}?action=check`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: getToken() })
    });
    const data = await res.json();
    window.isLoggedIn = data.logged_in;
  } catch(e) {
    // Se il server non risponde, usa la verifica client-side
    window.isLoggedIn = isTokenValid();
  }

  applyAuthUI();
  return window.isLoggedIn;
}

// ── APPLICA UI ───────────────────────────────
function applyAuthUI() {
  // Elementi visibili solo da loggati
  document.querySelectorAll('.auth-required').forEach(el => {
    el.style.display = window.isLoggedIn ? '' : 'none';
  });

  // Elementi visibili solo da non loggati
  document.querySelectorAll('.auth-hidden').forEach(el => {
    el.style.display = window.isLoggedIn ? 'none' : '';
  });

  // Bottoni header Accedi / Esci
  const btnLogin  = document.getElementById('btnLogin');
  if (btnLogin)  btnLogin.style.display = window.isLoggedIn ? 'none' : '';

  // Bottoni modifica/elimina sessioni — solo admin
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
    errEl.textContent   = 'Inserisci la password';
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

    if (data.success && data.token) {
      saveToken(data.token);
      window.isLoggedIn = true;
      closeLoginModal();
      applyAuthUI();

      // Ricarica i dati della pagina
      if (typeof loadStats       === 'function') loadStats();
      if (typeof loadLeaderboard === 'function') loadLeaderboard();
      if (typeof loadSessions    === 'function') loadSessions();
      if (typeof loadPlayers     === 'function') loadPlayers();
      if (typeof loadAll         === 'function') loadAll();
      if (typeof loadProfile     === 'function') loadProfile();

    } else {
      errEl.textContent   = data.error || 'Password errata';
      errEl.style.display = 'block';
      document.getElementById('loginPassword').value = '';
      document.getElementById('loginPassword').focus();
    }
  } catch(e) {
    errEl.textContent   = 'Errore di connessione';
    errEl.style.display = 'block';
  }

  btn.disabled    = false;
  btn.textContent = 'Accedi';
}

// ── LOGOUT ───────────────────────────────────
function logout() {
  removeToken();
  window.isLoggedIn = false;
  applyAuthUI();
}

// ── MODAL LOGIN ──────────────────────────────
function openLoginModal() {
  document.getElementById('loginPassword').value      = '';
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

// Enter per confermare
document.addEventListener('keydown', e => {
  if (e.key === 'Enter' && document.getElementById('loginModalOverlay')?.classList.contains('open')) {
    submitLogin();
  }
});

// ── INIT ─────────────────────────────────────
// Controlla subito il token dal localStorage (nessuna chiamata API, istantaneo)
window.isLoggedIn = isTokenValid();
applyAuthUI();

// Poi verifica anche lato server in background
document.addEventListener('DOMContentLoaded', checkAuth);