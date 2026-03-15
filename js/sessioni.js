// ============================================
//  sessioni.js — Gestione sessioni
// ============================================

const API = 'api';

let allSessions = [];
let allPlayers  = [];
let editingId   = null;
let deletingId  = null;

// ── UTILITY ──────────────────────────────────

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = (type === 'success' ? '✓ ' : '✕ ') + msg;
  t.className   = `toast ${type} show`;
  setTimeout(() => t.className = 'toast', 3500);
}

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('it-IT', {
    day: '2-digit', month: 'short', year: 'numeric'
  }).toUpperCase();
}

function formatDay(d)   {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('it-IT', { day: '2-digit' });
}

function formatMonth(d) {
  if (!d) return '';
  return new Date(d).toLocaleDateString('it-IT', { month: 'short', year: 'numeric' }).toUpperCase();
}

// ── CARICA DATI ──────────────────────────────

async function loadAll() {
  try {
    [allSessions, allPlayers] = await Promise.all([
      fetch(`${API}/sessions.php`).then(r => r.json()),
      fetch(`${API}/players.php`).then(r => r.json())
    ]);
    updateHeroBar();
    renderSessions();
  } catch (e) {
    console.error(e);
    document.getElementById('sessions-list').innerHTML =
      '<div class="empty-state"><span class="empty-state-icon">⚠️</span>Errore nel caricamento</div>';
  }
}

function updateHeroBar() {
  // Stats dai dati già caricati (evita una chiamata extra)
  const allScores = allSessions.flatMap(s => s.scores || []);
  const total     = allSessions.length;
  const media     = allScores.length
    ? (allScores.reduce((s, x) => s + parseInt(x.score), 0) / allScores.length).toFixed(1)
    : '—';
  const best      = allScores.reduce((a, b) => b.score > a.score ? b : a, { score: 0 });
  const ultima    = allSessions[0];

  document.getElementById('stat-totali').textContent = total || '—';
  document.getElementById('stat-media').textContent  = media;
  document.getElementById('stat-record').textContent = best.score || '—';

  if (best.score) {
    document.getElementById('stat-record-sub').textContent =
      `${best.emoji || '🎳'} ${best.player_name} · ${formatDate(best.date)}`;
  }
  if (ultima) {
    const d = new Date(ultima.date);
    document.getElementById('stat-ultima').textContent =
      d.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' }).toUpperCase();
    document.getElementById('stat-ultima-sub').textContent = ultima.location;
  }
}

// ── RENDER LISTA ─────────────────────────────

function renderSessions() {
  const query      = document.getElementById('searchInput').value.toLowerCase();
  const filterFrom = document.getElementById('filterFrom').value;
  const filterTo   = document.getElementById('filterTo').value;

  const filtered = allSessions.filter(s => {
    const scores  = s.scores || [];
    const players = [...new Set(scores.map(x => x.player_name))].join(' ').toLowerCase();
    const matchQ  = !query || s.location.toLowerCase().includes(query) || players.includes(query);
    const matchF  = !filterFrom || s.date >= filterFrom;
    const matchT  = !filterTo   || s.date <= filterTo;
    return matchQ && matchF && matchT;
  });

  // Contatore
  document.getElementById('resultsCount').textContent =
    filtered.length === allSessions.length
      ? `${allSessions.length} sessioni`
      : `${filtered.length} di ${allSessions.length} sessioni`;

  if (!filtered.length) {
    document.getElementById('sessions-list').innerHTML = `
      <div class="empty-state">
        <span class="empty-state-icon">🎳</span>
        ${query || filterFrom || filterTo ? 'Nessuna sessione trovata' : 'Nessuna sessione ancora — inizia a giocare!'}
      </div>`;
    return;
  }

  document.getElementById('sessions-list').innerHTML = filtered.map((s, i) => {
    const scores  = s.scores  || [];
    const teams   = s.teams   || [];
    const players = [...new Set(scores.map(x => x.player_name))];
    const best    = scores.reduce((a, b) => b.score > a.score ? b : a, { score: 0 });
    const delay   = (i * 0.04).toFixed(2);

    // Raggruppa per squadra
    const byTeam = {};
    teams.forEach(t => { byTeam[t.name] = { name: t.name, total: 0, players: [] }; });
    scores.forEach(sc => {
      if (byTeam[sc.team_name]) {
        byTeam[sc.team_name].total += parseInt(sc.score) || 0;
        byTeam[sc.team_name].players.push(sc);
      }
    });

    const teamList = Object.values(byTeam);
    const maxTotal = Math.max(...teamList.map(t => t.total));
    const tColors  = ['var(--neon)', 'var(--neon2)', 'var(--neon3)', 'var(--neon4)'];
    const maxScore = Math.max(...scores.map(x => parseInt(x.score) || 0));

    const teamsHtml = teamList.map((t, ti) => {
      const win   = t.total === maxTotal && maxTotal > 0;
      const color = tColors[ti % tColors.length];
      const rows  = t.players.map(p => `
        <div class="detail-player-row">
          <span>${p.emoji || '🎳'} ${p.player_name}</span>
          <span class="detail-player-score${parseInt(p.score) === maxScore ? ' top' : ''}">${p.score}</span>
        </div>`).join('');

      return `
        <div class="detail-team">
          <div class="detail-team-header">
            <span class="detail-team-name" style="color:${color}">${t.name}</span>
            <div style="display:flex;align-items:center;gap:0.5rem">
              <span class="team-tag ${win ? 'win' : 'lose'}">${win ? 'WIN' : 'LOSE'}</span>
              <span class="detail-team-total" style="color:${win ? color : 'var(--text-muted)'}">${t.total}</span>
            </div>
          </div>
          <div class="detail-team-players">${rows}</div>
        </div>`;
    }).join('');

    const notesHtml = s.notes
      ? `<div class="session-notes">📝 ${s.notes}</div>`
      : '';

    return `
      <div class="session-card" id="card-${s.id}" style="animation-delay:${delay}s">
        <div class="session-card-header" onclick="toggleCard(${s.id})">

          <div class="session-card-date">
            <div class="session-date-day">${formatDay(s.date)}</div>
            <div class="session-date-month">${formatMonth(s.date)}</div>
          </div>

          <div class="session-card-info">
            <div class="session-location">${s.location}</div>
            <div class="session-players">${players.join(' · ') || '—'}</div>
          </div>

          <div class="session-card-winner">
            <div class="winner-label">Top score</div>
            <div class="winner-name">${best.player_name || '—'}</div>
            <div class="winner-score">${best.score || '—'} pts</div>
          </div>

          <div class="session-card-toggle">▼</div>
        </div>

        <div class="session-card-detail">
          ${teamList.length ? `<div class="detail-teams">${teamsHtml}</div>` : '<div style="color:var(--text-muted);font-size:0.8rem">Nessun punteggio registrato</div>'}
          ${notesHtml}
          <div class="detail-actions">
            <button class="detail-action-btn edit"   onclick="openEditModal(${s.id})">✏ Modifica</button>
            <button class="detail-action-btn delete" onclick="openDeleteModal(${s.id}, '${formatDate(s.date)}')">✕ Elimina</button>
          </div>
        </div>
      </div>`;
  }).join('');
}

function toggleCard(id) {
  const card = document.getElementById(`card-${id}`);
  card.classList.toggle('open');
}

function filterSessions() { renderSessions(); }

function resetFilters() {
  document.getElementById('searchInput').value = '';
  document.getElementById('filterFrom').value  = '';
  document.getElementById('filterTo').value    = '';
  renderSessions();
}

// ── MODAL AGGIUNGI ───────────────────────────

function openAddModal() {
  editingId = null;
  document.getElementById('modalTitle').textContent    = '🎳 Nuova Partita';
  document.getElementById('sessionDate').value         = new Date().toISOString().split('T')[0];
  document.getElementById('sessionLocation').value     = '';
  document.getElementById('sessionNotes').value        = '';
  document.getElementById('teamAName').value           = '';
  document.getElementById('teamBName').value           = '';
  document.getElementById('teamARows').innerHTML       = '';
  document.getElementById('teamBRows').innerHTML       = '';
  document.getElementById('totalA').textContent        = 'Totale: 0';
  document.getElementById('totalB').textContent        = 'Totale: 0';
  document.getElementById('btnSave').textContent       = 'Salva';

  addPlayerRow('A'); addPlayerRow('A'); addPlayerRow('A');
  addPlayerRow('B'); addPlayerRow('B'); addPlayerRow('B');

  document.getElementById('modalOverlay').classList.add('open');
}

// ── MODAL MODIFICA ───────────────────────────

function openEditModal(id) {
  const s = allSessions.find(x => x.id === id);
  if (!s) return;

  editingId = id;
  document.getElementById('modalTitle').textContent = '✏ Modifica Sessione';
  document.getElementById('sessionDate').value      = s.date;
  document.getElementById('sessionLocation').value  = s.location || '';
  document.getElementById('sessionNotes').value     = s.notes    || '';
  document.getElementById('btnSave').textContent    = 'Aggiorna';

  // Svuota righe
  document.getElementById('teamARows').innerHTML = '';
  document.getElementById('teamBRows').innerHTML = '';
  document.getElementById('totalA').textContent  = 'Totale: 0';
  document.getElementById('totalB').textContent  = 'Totale: 0';

  const teams = s.teams || [];
  const scores = s.scores || [];

  // Raggruppa scores per squadra
  const byTeam = {};
  teams.forEach(t => { byTeam[t.name] = []; });
  scores.forEach(sc => { if (byTeam[sc.team_name]) byTeam[sc.team_name].push(sc); });

  const teamNames = Object.keys(byTeam);

  // Squadra A
  const nameA = teamNames[0] || '';
  document.getElementById('teamAName').value = nameA;
  const playersA = byTeam[nameA] || [];
  if (playersA.length) {
    playersA.forEach(p => addPlayerRow('A', p.player_id || null, p.score));
  } else {
    addPlayerRow('A'); addPlayerRow('A'); addPlayerRow('A');
  }

  // Squadra B
  const nameB = teamNames[1] || '';
  document.getElementById('teamBName').value = nameB;
  const playersB = byTeam[nameB] || [];
  if (playersB.length) {
    playersB.forEach(p => addPlayerRow('B', p.player_id || null, p.score));
  } else {
    addPlayerRow('B'); addPlayerRow('B'); addPlayerRow('B');
  }

  calcTotals();
  document.getElementById('modalOverlay').classList.add('open');

  // Chiudi il dettaglio espanso se era aperto
  document.getElementById(`card-${id}`)?.classList.remove('open');
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
}

function handleOverlayClick(e) {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
}

// ── RIGHE GIOCATORI NEL MODAL ────────────────

function addPlayerRow(team, selectedId = null, score = '') {
  const opts = allPlayers.map(p =>
    `<option value="${p.id}" ${parseInt(p.id) === parseInt(selectedId) ? 'selected' : ''}>${p.emoji || '🎳'} ${p.name}</option>`
  ).join('');

  const row = document.createElement('div');
  row.className = 'score-row';
  row.innerHTML = `
    <select class="form-input" onchange="calcTotals()">
      <option value="">— Giocatore —</option>${opts}
    </select>
    <input type="number" class="form-input" placeholder="Score" min="0" max="300"
      value="${score}" oninput="calcTotals()"/>
    <button class="btn-remove" onclick="this.parentElement.remove();calcTotals()" title="Rimuovi">✕</button>`;
  document.getElementById(`team${team}Rows`).appendChild(row);
}

function calcTotals() {
  ['A', 'B'].forEach(t => {
    let tot = 0;
    document.querySelectorAll(`#team${t}Rows input[type="number"]`).forEach(i => {
      if (i.value) tot += parseInt(i.value) || 0;
    });
    document.getElementById(`total${t}`).textContent = `Totale: ${tot}`;
  });
}

// ── SALVA / AGGIORNA ─────────────────────────

async function saveSession() {
  const btn  = document.getElementById('btnSave');
  const date = document.getElementById('sessionDate').value;

  if (!date) { showToast('Inserisci la data', 'error'); return; }

  const teams = [];
  ['A', 'B'].forEach(t => {
    const name    = document.getElementById(`team${t}Name`).value || `Squadra ${t}`;
    const players = [];
    document.querySelectorAll(`#team${t}Rows .score-row`).forEach(row => {
      const pid   = row.querySelector('select')?.value;
      const score = row.querySelector('input[type="number"]')?.value;
      if (pid && score) players.push({ player_id: parseInt(pid), score: parseInt(score) });
    });
    if (players.length > 0) teams.push({ name, players });
  });

  if (!teams.length) { showToast('Inserisci almeno un punteggio', 'error'); return; }

  btn.disabled    = true;
  btn.textContent = 'Salvataggio...';

  try {
    const method  = editingId ? 'PUT' : 'POST';
    const payload = {
      date,
      location: document.getElementById('sessionLocation').value || 'Bowling',
      notes:    document.getElementById('sessionNotes').value,
      teams
    };
    if (editingId) payload.id = editingId;

    const res  = await fetch(`${API}/sessions.php`, {
      method,
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.success || data.session_id) {
      closeModal();
      showToast(editingId ? 'Sessione aggiornata!' : 'Partita salvata!');
      await loadAll();
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

// ── ELIMINA ──────────────────────────────────

function openDeleteModal(id, dateStr) {
  deletingId = id;
  document.getElementById('deleteSessionDate').textContent = dateStr;
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
    const res  = await fetch(`${API}/sessions.php`, {
      method: 'DELETE',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: deletingId })
    });
    const data = await res.json();

    if (data.success) {
      closeDeleteModal();
      showToast('Sessione eliminata');
      await loadAll();
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch (e) {
    showToast('Errore di connessione', 'error');
  }

  btn.disabled    = false;
  btn.textContent = 'Elimina';
}

// ── TASTIERA ─────────────────────────────────

document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeModal(); closeDeleteModal(); }
});

// ── INIT ─────────────────────────────────────

document.addEventListener('DOMContentLoaded', loadAll);