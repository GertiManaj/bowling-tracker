// ============================================
//  giocatori.js — Gestione giocatori
// ============================================



// Colori accent ciclici per le card
const CARD_COLORS = [
  'var(--neon)',  'var(--neon3)', 'var(--neon4)',
  'var(--neon2)', 'var(--gold)',  '#a78bfa',
  '#34d399',     '#fb923c',      '#60a5fa'
];

// Lista emoji disponibili nel selettore
const EMOJIS = [
  '🎳','🐺','🦊','🐻','🦁','🐯','🦋','🐸',
  '🦅','🐉','🦈','🐆','🦎','🐬','🦄','🐼',
  '🦊','🐙','🦁','🐝','🦉','🦚','🐺','🦀',
  '⚡','🔥','💎','🏆','👑','🎯','🚀','💥'
];

// Stato locale

let currentSort = 'name';
let editingId   = null;
let deletingId  = null;

// ── UTILITY ──────────────────────────────────

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = (type === 'success' ? '✓ ' : '✕ ') + msg;
  t.className   = `toast ${type} show`;
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
  const best      = withGames.reduce((a, b) => parseFloat(b.media) > parseFloat(a.media) ? b : a, withGames[0] || {});
  const recordP   = withGames.reduce((a, b) => (b.record > a.record ? b : a), withGames[0] || {});
  const totPartite = allPlayers.reduce((s, p) => s + parseInt(p.partite || 0), 0);

  document.getElementById('stat-totali').textContent  = allPlayers.length;
  document.getElementById('stat-partite').textContent = totPartite;

  if (best?.media) {
    document.getElementById('stat-best-avg').textContent      = best.media;
    document.getElementById('stat-best-avg-name').textContent = `${best.emoji || '🎳'} ${best.name}`;
  } else {
    document.getElementById('stat-best-avg').textContent      = '—';
    document.getElementById('stat-best-avg-name').textContent = 'nessuna partita';
  }

  if (recordP?.record) {
    document.getElementById('stat-record').textContent      = recordP.record;
    document.getElementById('stat-record-name').textContent = `${recordP.emoji || '🎳'} ${recordP.name}`;
  } else {
    document.getElementById('stat-record').textContent      = '—';
    document.getElementById('stat-record-name').textContent = 'nessuna partita';
  }
}

// ── RENDER GRIGLIA ───────────────────────────

function renderPlayers() {
  const query    = document.getElementById('searchInput').value.toLowerCase();
  let   filtered = allPlayers.filter(p =>
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
    const color   = CARD_COLORS[i % CARD_COLORS.length];
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
        <div class="player-card-actions action-btn-wrap">
          <button class="player-action-btn edit" onclick="openEditModal(${p.id})">✏ Modifica</button>
          <button class="player-action-btn delete" onclick="openDeleteModal(${p.id}, '${p.name.replace(/'/g, "\\'")}')">✕ Elimina</button>
        </div>
      </div>`;
  }).join('');
}

// ── ORDINAMENTO ──────────────────────────────

function sortPlayers(list, by) {
  return [...list].sort((a, b) => {
    if (by === 'name')    return a.name.localeCompare(b.name);
    if (by === 'media')   return (parseFloat(b.media) || 0) - (parseFloat(a.media) || 0);
    if (by === 'record')  return (parseInt(b.record)  || 0) - (parseInt(a.record)  || 0);
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

// ── SELETTORE EMOJI ──────────────────────────

function buildEmojiGrid(selected = '🎳') {
  document.getElementById('emojiGrid').innerHTML = EMOJIS.map(e => `
    <button
      type="button"
      class="emoji-btn${e === selected ? ' selected' : ''}"
      onclick="selectEmoji('${e}', this)"
    >${e}</button>
  `).join('');
  document.getElementById('selectedEmoji').value = selected;
}

function selectEmoji(emoji, btn) {
  document.querySelectorAll('.emoji-btn').forEach(b => b.classList.remove('selected'));
  btn.classList.add('selected');
  document.getElementById('selectedEmoji').value = emoji;
}

// ── MODAL AGGIUNGI ───────────────────────────

function openAddModal() {
  editingId = null;
  document.getElementById('modalTitle').textContent    = '➕ Nuovo Giocatore';
  document.getElementById('playerName').value          = '';
  document.getElementById('playerNickname').value      = '';
  document.getElementById('btnSave').textContent       = 'Salva';
  buildEmojiGrid('🎳');
  document.getElementById('modalOverlay').classList.add('open');
  setTimeout(() => document.getElementById('playerName').focus(), 100);
}

// ── MODAL MODIFICA ───────────────────────────

function openEditModal(id) {
  const p = allPlayers.find(x => x.id === id);
  if (!p) return;

  editingId = id;
  document.getElementById('modalTitle').textContent    = '✏ Modifica Giocatore';
  document.getElementById('playerName').value          = p.name;
  document.getElementById('playerNickname').value      = p.nickname || '';
  document.getElementById('btnSave').textContent       = 'Aggiorna';
  buildEmojiGrid(p.emoji || '🎳');
  document.getElementById('modalOverlay').classList.add('open');
  setTimeout(() => document.getElementById('playerName').focus(), 100);
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
}

function handleOverlayClick(e) {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
}

// ── SALVA (aggiungi o modifica) ──────────────

async function savePlayer() {
  const btn      = document.getElementById('btnSave');
  const name     = document.getElementById('playerName').value.trim();
  const nickname = document.getElementById('playerNickname').value.trim();
  const emoji    = document.getElementById('selectedEmoji').value;

  if (!name) {
    showToast('Il nome è obbligatorio', 'error');
    document.getElementById('playerName').focus();
    return;
  }

  btn.disabled    = true;
  btn.textContent = 'Salvataggio...';

  try {
    const method  = editingId ? 'PUT' : 'POST';
    const payload = editingId
      ? { id: editingId, name, nickname, emoji }
      : { name, nickname, emoji };

    const res  = await fetch(`${API}/players.php`, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.success) {
      closeModal();
      showToast(editingId ? `${name} aggiornato!` : `${name} aggiunto al gruppo!`);
      await loadPlayers();
    } else {
      showToast(data.error || 'Errore nel salvataggio', 'error');
    }
  } catch (e) {
    showToast('Errore di connessione', 'error');
    console.error(e);
  }

  btn.disabled    = false;
  btn.textContent = editingId ? 'Aggiorna' : 'Salva';
}

// ── MODAL ELIMINA ────────────────────────────

function openDeleteModal(id, name) {
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
  btn.disabled    = true;
  btn.textContent = 'Eliminazione...';

  try {
    const res  = await fetch(`${API}/players.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
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

  btn.disabled    = false;
  btn.textContent = 'Elimina';
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