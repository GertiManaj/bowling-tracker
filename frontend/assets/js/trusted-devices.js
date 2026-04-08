// ══════════════════════════════════════════
//  trusted-devices.js — Gestione Dispositivi Fidati
// ══════════════════════════════════════════

const TD_API = '/api/trusted-devices.php';

// ── INJECT MODAL AL CARICAMENTO ──
document.addEventListener('DOMContentLoaded', function () {
  const modal = document.createElement('div');
  modal.id = 'trustedDevicesOverlay';
  modal.className = 'login-modal-overlay';
  modal.setAttribute('onclick', 'handleTDOverlayClick(event)');
  modal.innerHTML =
    '<div class="login-modal" style="width:500px;max-width:95vw">' +
      '<div class="login-modal-header">' +
        '<div class="login-modal-title" style="font-size:1.4rem">🔒 DISPOSITIVI FIDATI</div>' +
        '<div class="login-modal-subtitle">Gestisci accessi senza OTP</div>' +
        '<button class="modal-close" onclick="closeTrustedDevicesModal()">✕</button>' +
      '</div>' +

      '<div id="tdLoading" style="text-align:center;padding:1.5rem;font-family:\'Share Tech Mono\',monospace;font-size:0.7rem;color:var(--text-muted)">Caricamento...</div>' +
      '<div id="tdError" class="login-error" style="display:none"></div>' +
      '<div id="tdList"></div>' +

      '<div id="tdActions" style="display:none;margin-top:1.2rem">' +
        '<button class="login-btn-secondary" onclick="revokeAllDevices()" style="color:var(--neon2);border-color:var(--neon2)">' +
          '🗑 Revoca tutti i dispositivi' +
        '</button>' +
      '</div>' +

      '<button class="login-btn-secondary" onclick="closeTrustedDevicesModal()" style="margin-top:0.6rem">Chiudi</button>' +
    '</div>';
  document.body.appendChild(modal);
});

// ── OPEN / CLOSE ──
function openTrustedDevicesModal() {
  const overlay = document.getElementById('trustedDevicesOverlay');
  if (!overlay) return;
  overlay.classList.add('open');
  loadTrustedDevices();
}

function closeTrustedDevicesModal() {
  const overlay = document.getElementById('trustedDevicesOverlay');
  if (overlay) overlay.classList.remove('open');
}

function handleTDOverlayClick(e) {
  if (e.target.id === 'trustedDevicesOverlay') closeTrustedDevicesModal();
}

// ── LOAD LIST ──
async function loadTrustedDevices() {
  const listEl    = document.getElementById('tdList');
  const loadingEl = document.getElementById('tdLoading');
  const errorEl   = document.getElementById('tdError');
  const actionsEl = document.getElementById('tdActions');

  listEl.innerHTML = '';
  errorEl.style.display = 'none';
  actionsEl.style.display = 'none';
  loadingEl.style.display = 'block';

  const token = localStorage.getItem('sz_auth_token');
  if (!token) {
    loadingEl.style.display = 'none';
    errorEl.textContent = 'Devi essere autenticato';
    errorEl.style.display = 'block';
    return;
  }

  try {
    const res  = await fetch(`${TD_API}?action=list`, {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    const data = await res.json();

    loadingEl.style.display = 'none';

    if (!data.success) {
      errorEl.textContent = data.error || 'Errore caricamento';
      errorEl.style.display = 'block';
      return;
    }

    if (data.devices.length === 0) {
      listEl.innerHTML =
        '<div style="text-align:center;padding:1.5rem;font-family:\'Share Tech Mono\',monospace;font-size:0.65rem;color:var(--text-muted)">' +
          'Nessun dispositivo fidato registrato.' +
        '</div>';
      return;
    }

    actionsEl.style.display = 'block';
    listEl.innerHTML = data.devices.map(d => renderDevice(d)).join('');

  } catch (err) {
    loadingEl.style.display = 'none';
    errorEl.textContent = 'Errore di connessione';
    errorEl.style.display = 'block';
  }
}

function renderDevice(d) {
  const now     = Date.now();
  const expires = new Date(d.expires_at).getTime();
  const created = new Date(d.created_at).toLocaleDateString('it-IT', { day:'2-digit', month:'2-digit', year:'numeric' });
  const expDate = new Date(d.expires_at).toLocaleDateString('it-IT', { day:'2-digit', month:'2-digit', year:'numeric' });
  const lastUsed = d.last_used
    ? new Date(d.last_used).toLocaleDateString('it-IT', { day:'2-digit', month:'2-digit', year:'numeric' })
    : 'Mai';
  const isExpired  = expires < now;
  const isCurrent  = d.is_current;

  return (
    '<div class="td-device-item' + (isCurrent ? ' td-current' : '') + (isExpired ? ' td-expired' : '') + '">' +
      '<div class="td-device-info">' +
        '<div class="td-device-name">' +
          (isCurrent ? '<span class="td-badge-current">QUESTO</span> ' : '') +
          (isExpired ? '<span class="td-badge-expired">SCADUTO</span> ' : '') +
          escHtml(d.name) +
        '</div>' +
        '<div class="td-device-meta">' +
          '<span>📅 ' + created + '</span>' +
          '<span>⏳ Scade ' + expDate + '</span>' +
          '<span>🕐 Usato ' + lastUsed + '</span>' +
          (d.ip ? '<span>🌐 ' + escHtml(d.ip) + '</span>' : '') +
        '</div>' +
      '</div>' +
      '<button class="td-revoke-btn" onclick="revokeDevice(' + d.id + ')" title="Revoca dispositivo">✕</button>' +
    '</div>'
  );
}

// ── REVOKE SINGLE ──
async function revokeDevice(id) {
  const token = localStorage.getItem('sz_auth_token');
  if (!token) return;

  const btn = event.target;
  btn.disabled = true;
  btn.textContent = '...';

  try {
    const res  = await fetch(`${TD_API}?action=revoke`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({ id })
    });
    const data = await res.json();

    if (data.success) {
      showToast('Dispositivo revocato', 'success');
      await loadTrustedDevices();
    } else {
      showToast(data.error || 'Errore', 'error');
      btn.disabled = false;
      btn.textContent = '✕';
    }
  } catch (err) {
    showToast('Errore di connessione', 'error');
    btn.disabled = false;
    btn.textContent = '✕';
  }
}

// ── REVOKE ALL ──
async function revokeAllDevices() {
  if (!confirm('Revocare TUTTI i dispositivi fidati? Dovrai reinserire OTP al prossimo login.')) return;

  const token = localStorage.getItem('sz_auth_token');
  if (!token) return;

  try {
    const res  = await fetch(`${TD_API}?action=revoke-all`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'Authorization': `Bearer ${token}` },
      body: JSON.stringify({})
    });
    const data = await res.json();

    if (data.success) {
      showToast('Tutti i dispositivi revocati', 'success');
      await loadTrustedDevices();
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch (err) {
    showToast('Errore di connessione', 'error');
  }
}

// ── UTILITY ──
function escHtml(str) {
  return String(str).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

console.log('✅ trusted-devices.js caricato');
