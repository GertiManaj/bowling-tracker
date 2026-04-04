// ============================================
//  auth.js — Autenticazione con OTP a 2 Step
//  Step 1: Email + Password → Invia OTP
//  Step 2: Codice OTP → Ottieni JWT Token
// ============================================

const AUTH_API = '/api/auth.php';
const TOKEN_KEY = 'sz_auth_token';

window.isLoggedIn = false;
let otpEmail = null; // Salva email per step 2

// ══════════════════════════════════════════
// TOKEN MANAGEMENT
// ══════════════════════════════════════════

function getToken() {
  return localStorage.getItem(TOKEN_KEY);
}

function saveToken(token) {
  localStorage.setItem(TOKEN_KEY, token);
}

function removeToken() {
  localStorage.removeItem(TOKEN_KEY);
}

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

// ══════════════════════════════════════════
// CHECK AUTH
// ══════════════════════════════════════════

async function checkAuth() {
  if (!isTokenValid()) {
    removeToken();
    window.isLoggedIn = false;
    applyAuthUI();
    return false;
  }

  try {
    const res = await fetch(`${AUTH_API}?action=check`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ token: getToken() })
    });
    const data = await res.json();
    window.isLoggedIn = data.logged_in;
  } catch(e) {
    window.isLoggedIn = isTokenValid();
  }

  applyAuthUI();
  return window.isLoggedIn;
}

// ══════════════════════════════════════════
// UI UPDATE
// ══════════════════════════════════════════

function applyAuthUI() {
  document.querySelectorAll('.auth-required').forEach(el => {
    el.style.display = window.isLoggedIn ? '' : 'none';
  });

  document.querySelectorAll('.auth-hidden').forEach(el => {
    el.style.display = window.isLoggedIn ? 'none' : '';
  });

  const btnLogin = document.getElementById('btnLogin');
  if (btnLogin) btnLogin.style.display = window.isLoggedIn ? 'none' : '';

  document.querySelectorAll('.action-btn-wrap').forEach(el => {
    el.style.display = window.isLoggedIn ? '' : 'none';
  });
}

// ══════════════════════════════════════════
// STEP 1: REQUEST OTP (Email + Password)
// ══════════════════════════════════════════

async function submitLogin() {
  const email = document.getElementById('loginEmail').value.trim();
  const password = document.getElementById('loginPassword').value;
  const btnSubmit = document.getElementById('btnLoginSubmit');
  const errEl = document.getElementById('loginError');

  if (!email || !password) {
    errEl.textContent = 'Inserisci email e password';
    errEl.style.display = 'block';
    return;
  }

  btnSubmit.disabled = true;
  btnSubmit.textContent = 'Verifica...';
  errEl.style.display = 'none';

  try {
    const res = await fetch(`${AUTH_API}?action=request-otp`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email, password })
    });

    const data = await res.json();

    if (data.success) {
      // Salva email per step 2
      otpEmail = email;
      
      // Mostra step 2 (OTP input)
      showOTPStep(data.expires_at);
      
    } else {
      errEl.textContent = data.error || 'Credenziali non valide';
      errEl.style.display = 'block';
    }

  } catch(e) {
    errEl.textContent = 'Errore di connessione';
    errEl.style.display = 'block';
  }

  btnSubmit.disabled = false;
  btnSubmit.textContent = 'Continua';
}

// ══════════════════════════════════════════
// STEP 2: VERIFY OTP
// ══════════════════════════════════════════

function showOTPStep(expiresAt) {
  document.getElementById('loginStep1').style.display = 'none';
  document.getElementById('loginStep2').style.display = 'block';
  
  // Focus sul primo input
  setTimeout(() => {
    document.getElementById('otp1').focus();
  }, 100);
  
  // Avvia countdown
  startOTPTimer(expiresAt);
}

function startOTPTimer(expiresAt) {
  const timerEl = document.getElementById('otpTimer');
  const resendBtn = document.getElementById('btnResendOTP');
  
  const expiryTime = new Date(expiresAt).getTime();
  
  const interval = setInterval(() => {
    const now = Date.now();
    const remaining = Math.max(0, expiryTime - now);
    
    if (remaining === 0) {
      clearInterval(interval);
      timerEl.textContent = 'Codice scaduto';
      timerEl.style.color = '#ff3cac';
      resendBtn.style.display = 'inline-block';
      return;
    }
    
    const minutes = Math.floor(remaining / 60000);
    const seconds = Math.floor((remaining % 60000) / 1000);
    timerEl.textContent = `${minutes}:${seconds.toString().padStart(2, '0')}`;
    
  }, 1000);
}

async function submitOTP() {
  const code = 
    document.getElementById('otp1').value +
    document.getElementById('otp2').value +
    document.getElementById('otp3').value +
    document.getElementById('otp4').value +
    document.getElementById('otp5').value +
    document.getElementById('otp6').value;
  
  const btnSubmit = document.getElementById('btnOTPSubmit');
  const errEl = document.getElementById('otpError');
  
  if (code.length !== 6) {
    errEl.textContent = 'Inserisci il codice completo';
    errEl.style.display = 'block';
    return;
  }
  
  btnSubmit.disabled = true;
  btnSubmit.textContent = 'Verifica...';
  errEl.style.display = 'none';
  
  try {
    const res = await fetch(`${AUTH_API}?action=verify-otp`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: otpEmail, code })
    });
    
    const data = await res.json();
    
    if (data.success && data.token) {
      saveToken(data.token);
      window.isLoggedIn = true;
      closeLoginModal();
      applyAuthUI();
      
      // Ricarica dati
      if (typeof loadStats === 'function') loadStats();
      if (typeof loadLeaderboard === 'function') loadLeaderboard();
      if (typeof loadSessions === 'function') loadSessions();
      if (typeof loadPlayers === 'function') loadPlayers();
      if (typeof loadAll === 'function') loadAll();
      if (typeof loadProfile === 'function') loadProfile();
      
      showToast('Accesso effettuato!', 'success');
      
    } else {
      errEl.textContent = data.error || 'Codice non valido';
      errEl.style.display = 'block';
      
      // Pulisci input
      for (let i = 1; i <= 6; i++) {
        document.getElementById(`otp${i}`).value = '';
      }
      document.getElementById('otp1').focus();
    }
    
  } catch(e) {
    errEl.textContent = 'Errore di connessione';
    errEl.style.display = 'block';
  }
  
  btnSubmit.disabled = false;
  btnSubmit.textContent = 'Verifica';
}

async function resendOTP() {
  const password = document.getElementById('loginPassword').value;
  const btnResend = document.getElementById('btnResendOTP');
  
  btnResend.disabled = true;
  btnResend.textContent = 'Invio...';
  
  try {
    const res = await fetch(`${AUTH_API}?action=request-otp`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ email: otpEmail, password })
    });
    
    const data = await res.json();
    
    if (data.success) {
      showToast('Nuovo codice inviato!', 'success');
      btnResend.style.display = 'none';
      startOTPTimer(data.expires_at);
      
      // Pulisci input
      for (let i = 1; i <= 6; i++) {
        document.getElementById(`otp${i}`).value = '';
      }
      document.getElementById('otp1').focus();
    }
    
  } catch(e) {
    showToast('Errore invio codice', 'error');
  }
  
  btnResend.disabled = false;
  btnResend.textContent = 'Rinvia codice';
}

// ══════════════════════════════════════════
// OTP INPUT AUTO-FOCUS
// ══════════════════════════════════════════

function setupOTPInputs() {
  for (let i = 1; i <= 6; i++) {
    const input = document.getElementById(`otp${i}`);
    if (!input) continue;
    
    input.addEventListener('input', (e) => {
      const value = e.target.value;
      
      // Solo numeri
      e.target.value = value.replace(/[^0-9]/g, '').slice(0, 1);
      
      // Auto-focus su prossimo input
      if (e.target.value && i < 6) {
        document.getElementById(`otp${i + 1}`).focus();
      }
    });
    
    input.addEventListener('keydown', (e) => {
      // Backspace: torna indietro
      if (e.key === 'Backspace' && !e.target.value && i > 1) {
        document.getElementById(`otp${i - 1}`).focus();
      }
      
      // Enter: submit
      if (e.key === 'Enter') {
        submitOTP();
      }
    });
    
    // Paste: distribuisci codice su tutti gli input
    input.addEventListener('paste', (e) => {
      e.preventDefault();
      const paste = (e.clipboardData || window.clipboardData).getData('text');
      const digits = paste.replace(/[^0-9]/g, '').slice(0, 6);
      
      for (let j = 0; j < digits.length && j < 6; j++) {
        document.getElementById(`otp${j + 1}`).value = digits[j];
      }
      
      if (digits.length === 6) {
        document.getElementById('otp6').focus();
      }
    });
  }
}

// ══════════════════════════════════════════
// LOGOUT
// ══════════════════════════════════════════

function logout() {
  removeToken();
  window.isLoggedIn = false;
  applyAuthUI();
  showToast('Disconnesso', 'success');
}

// ══════════════════════════════════════════
// MODAL MANAGEMENT
// ══════════════════════════════════════════

function openLoginModal() {
  // Reset form
  document.getElementById('loginEmail').value = '';
  document.getElementById('loginPassword').value = '';
  document.getElementById('loginError').style.display = 'none';
  
  // Reset OTP inputs
  for (let i = 1; i <= 6; i++) {
    document.getElementById(`otp${i}`).value = '';
  }
  document.getElementById('otpError').style.display = 'none';
  
  // Mostra step 1
  document.getElementById('loginStep1').style.display = 'block';
  document.getElementById('loginStep2').style.display = 'none';
  
  // Apri modal
  document.getElementById('loginModalOverlay').classList.add('open');
  
  setTimeout(() => document.getElementById('loginEmail').focus(), 100);
}

function closeLoginModal() {
  document.getElementById('loginModalOverlay').classList.remove('open');
  otpEmail = null;
}

function handleLoginOverlayClick(e) {
  if (e.target === document.getElementById('loginModalOverlay')) {
    closeLoginModal();
  }
}

function goBackToStep1() {
  document.getElementById('loginStep1').style.display = 'block';
  document.getElementById('loginStep2').style.display = 'none';
  otpEmail = null;
}

// ══════════════════════════════════════════
// KEYBOARD SHORTCUTS
// ══════════════════════════════════════════

document.addEventListener('keydown', e => {
  const modal = document.getElementById('loginModalOverlay');
  if (!modal || !modal.classList.contains('open')) return;
  
  // Enter su step 1
  if (e.key === 'Enter' && document.getElementById('loginStep1').style.display !== 'none') {
    submitLogin();
  }
});

// ══════════════════════════════════════════
// INIT
// ══════════════════════════════════════════

window.isLoggedIn = isTokenValid();
applyAuthUI();

document.addEventListener('DOMContentLoaded', () => {
  checkAuth();
  setupOTPInputs();
});
