// ============================================
//  giocatori.js — Gestione giocatori
// ============================================



// Colori accent ciclici per le card
const CARD_COLORS = [
  'var(--neon)', 'var(--neon3)', 'var(--neon4)',
  'var(--neon2)', 'var(--gold)', '#a78bfa',
  '#34d399', '#fb923c', '#60a5fa'
];

// Stato locale

let currentSort = 'name';
let editingId = null;
let deletingId = null;

// ── UTILITY ──────────────────────────────────

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = (type === 'success' ? '✓ ' : '✕ ') + msg;
  t.className = `toast ${type} show`;
  setTimeout(() => t.className = 'toast', 3500);
}

// ── CARICA GIOCATORI ─────────────────────────

async function loadPlayers() {
  try {
    allPlayers = await fetch(`${API}/players.php`).then(r => r.json());
    updateHeroBar();
    renderPlayers();
  } catch (e) {
    console.error('Errore caricamento giocatori:', e);
    document.getElementById('players-grid').innerHTML =
      '<div class="empty-state"><span class="empty-state-icon">⚠️</span>Errore nel caricamento</div>';
  }
}

function updateHeroBar() {
  const withGames = allPlayers.filter(p => p.partite > 0);
  const best = withGames.reduce((a, b) => parseFloat(b.media) > parseFloat(a.media) ? b : a, withGames[0] || {});
  const recordP = withGames.reduce((a, b) => (b.record > a.record ? b : a), withGames[0] || {});
  const totPartite = allPlayers.reduce((s, p) => s + parseInt(p.partite || 0), 0);

  document.getElementById('stat-totali').textContent = allPlayers.length;
  document.getElementById('stat-partite').textContent = totPartite;

  if (best?.media) {
    document.getElementById('stat-best-avg').textContent = best.media;
    document.getElementById('stat-best-avg-name').textContent = `${best.emoji || '🎳'} ${best.name}`;
  } else {
    document.getElementById('stat-best-avg').textContent = '—';
    document.getElementById('stat-best-avg-name').textContent = 'nessuna partita';
  }

  if (recordP?.record) {
    document.getElementById('stat-record').textContent = recordP.record;
    document.getElementById('stat-record-name').textContent = `${recordP.emoji || '🎳'} ${recordP.name}`;
  } else {
    document.getElementById('stat-record').textContent = '—';
    document.getElementById('stat-record-name').textContent = 'nessuna partita';
  }
}

// ── RENDER GRIGLIA ───────────────────────────

function renderPlayers() {
  const query = document.getElementById('searchInput').value.toLowerCase();
  let filtered = allPlayers.filter(p =>
    p.name.toLowerCase().includes(query) ||
    (p.nickname || '').toLowerCase().includes(query)
  );

  // Ordinamento
  filtered = sortPlayers(filtered, currentSort);

  const grid = document.getElementById('players-grid');

  if (!filtered.length) {
    grid.innerHTML = `
      <div class="empty-state">
        <span class="empty-state-icon">🎳</span>
        ${query ? 'Nessun giocatore trovato' : 'Nessun giocatore — aggiungine uno!'}
      </div>`;
    return;
  }

  grid.innerHTML = filtered.map((p, i) => {
    const color = CARD_COLORS[i % CARD_COLORS.length];
    const hasData = parseInt(p.partite) > 0;

    const statsHtml = hasData ? `
      <div class="player-card-stats">
        <div class="player-stat-item">
          <div class="player-stat-label">Media</div>
          <div class="player-stat-value highlight" style="--accent:${color}">${p.media ?? '—'}</div>
        </div>
        <div class="player-stat-item">
          <div class="player-stat-label">Record</div>
          <div class="player-stat-value">${p.record ?? '—'}</div>
        </div>
        <div class="player-stat-item">
          <div class="player-stat-label">Partite</div>
          <div class="player-stat-value">${p.partite}</div>
        </div>
      </div>` : `
      <div class="no-games-badge">Nessuna partita ancora</div>`;

    return `
      <div class="player-card" style="--accent:${color};animation-delay:${(i * 0.05).toFixed(2)}s">
        <div class="player-card-stripe"></div>
        <div class="player-card-header" onclick="window.location.href='profilo.html?id=${p.id}'" style="cursor:pointer" title="Vedi profilo">
          <div class="player-card-avatar" style="border-color:${color}">${p.emoji || '🎳'}</div>
          <div>
            <div class="player-card-name">${p.name}</div>
            <div class="player-card-nickname">${p.nickname || '&nbsp;'}</div>
          </div>
          <div style="margin-left:auto;font-size:0.65rem;font-family:'Share Tech Mono',monospace;color:var(--text-muted);letter-spacing:0.1em">PROFILO →</div>
        </div>
        ${statsHtml}
        <div class="player-card-actions action-btn-wrap" style="display:${window.isLoggedIn ? '' : 'none'}">
          <button class="player-action-btn edit" onclick="openEditModal(${p.id})">✏ Modifica</button>
          <button class="player-action-btn" style="color:var(--neon);border-color:rgba(232,255,0,0.3)" onclick="openPlayerLoginModal(${p.id}, '${p.name.replace(/'/g, "\\'")}')">🔑 Login</button>
          <button class="player-action-btn delete" onclick="openDeleteModal(${p.id}, '${p.name.replace(/'/g, "\\'")}')">✕ Elimina</button>
        </div>
      </div>`;
  }).join('');
}

// ── ORDINAMENTO ──────────────────────────────

function sortPlayers(list, by) {
  return [...list].sort((a, b) => {
    if (by === 'name') return a.name.localeCompare(b.name);
    if (by === 'media') return (parseFloat(b.media) || 0) - (parseFloat(a.media) || 0);
    if (by === 'record') return (parseInt(b.record) || 0) - (parseInt(a.record) || 0);
    if (by === 'partite') return (parseInt(b.partite) || 0) - (parseInt(a.partite) || 0);
    return 0;
  });
}

function sortBy(field, btn) {
  currentSort = field;
  document.querySelectorAll('.sort-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderPlayers();
}

function filterPlayers() {
  renderPlayers();
}

// ── MODAL ELIMINA ────────────────────────────

function openDeleteModal(id, name) {
  if (!window.isLoggedIn) { openLoginModal(); return; }
  deletingId = id;
  document.getElementById('deletePlayerName').textContent = name;
  document.getElementById('deleteOverlay').classList.add('open');
}

function closeDeleteModal() {
  document.getElementById('deleteOverlay').classList.remove('open');
  deletingId = null;
}

function handleDeleteOverlayClick(e) {
  if (e.target === document.getElementById('deleteOverlay')) closeDeleteModal();
}

async function confirmDelete() {
  if (!deletingId) return;

  const btn = document.getElementById('btnDelete');
  btn.disabled = true;
  btn.textContent = 'Eliminazione...';

  try {
    const res = await authFetch(`${API}/players.php`, {
      method: 'DELETE',
      body: JSON.stringify({ id: deletingId })
    });
    const data = await res.json();

    if (data.success) {
      closeDeleteModal();
      showToast('Giocatore eliminato');
      await loadPlayers();
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch (e) {
    showToast('Errore di connessione', 'error');
    console.error(e);
  }

  btn.disabled = false;
  btn.textContent = 'Elimina';
}

// ── MODAL LOGIN GIOCATORE ────────────────────

let playerLoginId = null;

function openPlayerLoginModal(id, name) {
  if (!window.isLoggedIn) { openLoginModal(); return; }
  playerLoginId = id;
  document.getElementById('playerLoginName').textContent = name;
  document.getElementById('playerLoginModalTitle').textContent = '🔑 Crea Login — ' + name;
  document.getElementById('playerLoginEmail').value = '';
  document.getElementById('playerLoginPassword').value = '';
  document.getElementById('playerLoginPasswordConfirm').value = '';
  document.getElementById('playerLoginError').style.display = 'none';
  document.getElementById('playerLoginOverlay').classList.add('open');
  setTimeout(() => document.getElementById('playerLoginEmail').focus(), 100);
}

function closePlayerLoginModal() {
  document.getElementById('playerLoginOverlay').classList.remove('open');
  playerLoginId = null;
}

function handlePlayerLoginOverlayClick(e) {
  if (e.target === document.getElementById('playerLoginOverlay')) closePlayerLoginModal();
}

function showPlayerLoginError(msg) {
  const el = document.getElementById('playerLoginError');
  el.textContent = msg;
  el.style.display = 'block';
}

async function savePlayerLogin() {
  const btn   = document.getElementById('btnSavePlayerLogin');
  const email = document.getElementById('playerLoginEmail').value.trim();
  const pass  = document.getElementById('playerLoginPassword').value;
  const conf  = document.getElementById('playerLoginPasswordConfirm').value;

  document.getElementById('playerLoginError').style.display = 'none';

  if (!email) { showPlayerLoginError('Email obbligatoria'); return; }
  if (pass.length < 8) { showPlayerLoginError('Password minimo 8 caratteri'); return; }
  if (pass !== conf) { showPlayerLoginError('Le password non corrispondono'); return; }

  btn.disabled = true;
  btn.textContent = 'Creazione...';

  try {
    const res  = await authFetch('/api/player-auth.php?action=register', {
      method: 'POST',
      body: JSON.stringify({ player_id: playerLoginId, email: email, password: pass })
    });
    const data = await res.json();

    if (data.success) {
      closePlayerLoginModal();
      showToast('Accesso creato!');
    } else {
      showPlayerLoginError(data.error || 'Errore creazione accesso');
    }
  } catch (e) {
    showPlayerLoginError('Errore di connessione');
    console.error(e);
  }

  btn.disabled = false;
  btn.textContent = '🔑 Crea Accesso';
}

// ── TASTO ENTER nel form ─────────────────────

document.addEventListener('keydown', e => {
  if (e.key === 'Enter') {
    if (document.getElementById('modalOverlay').classList.contains('open')) savePlayer();
    if (document.getElementById('deleteOverlay').classList.contains('open')) confirmDelete();
  }
  if (e.key === 'Escape') {
    closeModal();
    closeDeleteModal();
  }
});

// ── INIT ─────────────────────────────────────

document.addEventListener('DOMContentLoaded', loadPlayers);