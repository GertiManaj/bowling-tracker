// ============================================
//  sessioni.js — Gestione sessioni
// ============================================



let allSessions = [];

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

function formatDay(d) {
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
  const allScores = allSessions.flatMap(s => s.scores || []);
  const total     = allSessions.length;
  const media     = allScores.length
    ? (allScores.reduce((s, x) => s + parseInt(x.score), 0) / allScores.length).toFixed(1)
    : '—';
  const best   = allScores.reduce((a, b) => b.score > a.score ? b : a, { score: 0 });
  const ultima = allSessions[0];

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
      if (sc.team_name && byTeam[sc.team_name]) {
        byTeam[sc.team_name].total += parseInt(sc.score) || 0;
        byTeam[sc.team_name].players.push(sc);
      }
    });

    // Giocatori singoli
    const soloScores = scores.filter(sc => !sc.team_name);

    const teamList = Object.values(byTeam);
    const maxTotal = teamList.length ? Math.max(...teamList.map(t => t.total)) : 0;
    const tColors  = ['var(--neon)', 'var(--neon2)', 'var(--neon3)', 'var(--neon4)'];

    const teamsHtml = teamList.map((t, ti) => {
      const win   = t.total === maxTotal && maxTotal > 0;
      const color = tColors[ti % tColors.length];
      const byPlayer = {};
      t.players.forEach(p => {
        if (!byPlayer[p.player_name]) byPlayer[p.player_name] = { ...p, games: [] };
        byPlayer[p.player_name].games.push({ game: p.game_number || 1, score: p.score });
      });
      Object.values(byPlayer).forEach(p => {
        p.total = p.games.reduce((s, g) => s + parseInt(g.score), 0);
      });
      const maxPlayerScore = Object.values(byPlayer).length
        ? Math.max(...Object.values(byPlayer).map(p => p.total)) : 0;

      const rows = Object.values(byPlayer).map(p => {
        const gamesHtml = p.games.length > 1
          ? p.games.sort((a,b) => a.game - b.game).map(g =>
              `<span style="font-size:0.7rem;color:var(--text-muted)">G${g.game}:</span><span style="color:var(--neon3)">${g.score}</span>`
            ).join(' ')
          : '';
        const isTop = p.total === maxPlayerScore;
        return `
          <div class="detail-player-row">
            <span>${p.emoji || '🎳'} ${p.player_name}</span>
            <div style="display:flex;align-items:center;gap:0.4rem;font-family:'Share Tech Mono',monospace;font-size:0.8rem">
              ${gamesHtml ? `<span style="font-size:0.7rem;color:var(--text-muted)">${gamesHtml}</span>` : ''}
              <span class="detail-player-score${isTop ? ' top' : ''}">${p.total}</span>
            </div>
          </div>`;
      }).join('');

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

    // Sezione giocatori singoli
    let soloHtml = '';
    if (soloScores.length) {
      const byPlayer = {};
      soloScores.forEach(sc => {
        if (!byPlayer[sc.player_name]) byPlayer[sc.player_name] = { ...sc, games: [], total: 0 };
        byPlayer[sc.player_name].games.push({ game: sc.game_number || 1, score: sc.score });
        byPlayer[sc.player_name].total += parseInt(sc.score) || 0;
      });

      const soloRows = Object.values(byPlayer).map(p => {
        const gamesHtml = p.games.length > 1
          ? p.games.sort((a,b) => a.game - b.game).map(g =>
              `<span style="font-size:0.7rem;color:var(--text-muted)">G${g.game}:</span><span style="color:var(--neon3)">${g.score}</span>`
            ).join(' ')
          : '';
        return `
          <div class="detail-player-row">
            <span>${p.emoji || '🎳'} ${p.player_name}</span>
            <div style="display:flex;align-items:center;gap:0.4rem;font-family:'Share Tech Mono',monospace;font-size:0.8rem">
              ${gamesHtml ? `<span style="font-size:0.7rem;color:var(--text-muted)">${gamesHtml}</span>` : ''}
              <span class="detail-player-score" style="color:var(--neon3)">${p.total}</span>
            </div>
          </div>`;
      }).join('');

      soloHtml = `
        <div class="detail-team" style="border-color:var(--neon3)44">
          <div class="detail-team-header" style="background:rgba(0,245,255,0.05)">
            <span class="detail-team-name" style="color:var(--neon3)">👤 Singoli</span>
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.65rem;color:var(--text-muted)">Fuori sfida</span>
          </div>
          <div class="detail-team-players">${soloRows}</div>
        </div>`;
    }

    const notesHtml = s.notes ? `<div class="session-notes">📝 ${s.notes}</div>` : '';

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
          ${teamList.length || soloScores.length
            ? `<div class="detail-teams">${teamsHtml}${soloHtml}</div>`
            : '<div style="color:var(--text-muted);font-size:0.8rem">Nessun punteggio registrato</div>'}
          ${notesHtml}
          <div class="detail-actions action-btn-wrap">
            <button class="detail-action-btn edit"   onclick="openEditModal(${s.id})">✏ Modifica</button>
            <button class="detail-action-btn delete" onclick="openDeleteModal(${s.id}, '${formatDate(s.date)}')">✕ Elimina</button>
          </div>
        </div>
      </div>`;
  }).join('');
}

function toggleCard(id) {
  document.getElementById(`card-${id}`).classList.toggle('open');
}

function filterSessions() { renderSessions(); }

function resetFilters() {
  document.getElementById('searchInput').value = '';
  document.getElementById('filterFrom').value  = '';
  document.getElementById('filterTo').value    = '';
  renderSessions();
}

// ── MODAL AGGIUNGI ───────────────────────────

function openEditModal(id) {
  const s = allSessions.find(x => x.id === id);
  if (!s) return;

  editingId = id;
  document.getElementById('modalTitle').textContent = '✏ Modifica Sessione';
  document.getElementById('sessionDate').value      = s.date;
  document.getElementById('sessionLocation').value  = s.location || '';
  document.getElementById('sessionNotes').value     = s.notes    || '';
  document.getElementById('btnSave').textContent    = 'Aggiorna';

  // Determina numGames dalla sessione (massimo game_number trovato)
  const allGameNums = (s.scores || []).map(sc => sc.game_number || 1);
  const detectedGames = allGameNums.length ? Math.max(...allGameNums) : 1;
  document.getElementById('numGames').value = detectedGames;

  document.getElementById('teamARows').innerHTML = '';
  document.getElementById('teamBRows').innerHTML = '';
  document.getElementById('soloRows').innerHTML  = '';
  document.getElementById('totalA').textContent  = 'Totale: 0';
  document.getElementById('totalB').textContent  = 'Totale: 0';

  const teams  = s.teams  || [];
  const scores = s.scores || [];

  // Raggruppa scores per team_name, poi per player_id
  const byTeam = {};
  teams.forEach(t => { byTeam[t.name] = {}; });
  scores.forEach(sc => {
    if (!sc.team_name || !byTeam[sc.team_name]) return;
    const pid = sc.player_id;
    if (!byTeam[sc.team_name][pid]) {
      byTeam[sc.team_name][pid] = { player_id: pid, games: [] };
    }
    byTeam[sc.team_name][pid].games.push({ game_number: sc.game_number || 1, score: sc.score });
  });

  // Determina il numero di game dalla sessione
  const numGames = parseInt(document.getElementById('numGames').value) || 1;

  const teamNames = Object.keys(byTeam);

  // Squadra A
  const nameA = teamNames[0] || '';
  document.getElementById('teamAName').value = nameA;
  const playersA = Object.values(byTeam[nameA] || {});
  if (playersA.length) {
    playersA.forEach(p => {
      addPlayerRow('A', p.player_id, numGames);
      // Precompila i punteggi nei campi appena creati
      const rows = document.querySelectorAll('#teamARows .player-row');
      const lastRow = rows[rows.length - 1];
      p.games.sort((a,b) => a.game_number - b.game_number).forEach(g => {
        const input = lastRow.querySelector(`.score-input[data-game="${g.game_number}"]`);
        if (input) input.value = g.score;
      });
    });
  } else {
    addPlayerRow('A'); addPlayerRow('A'); addPlayerRow('A');
  }

  // Squadra B
  const nameB = teamNames[1] || '';
  document.getElementById('teamBName').value = nameB;
  const playersB = Object.values(byTeam[nameB] || {});
  if (playersB.length) {
    playersB.forEach(p => {
      addPlayerRow('B', p.player_id, numGames);
      const rows = document.querySelectorAll('#teamBRows .player-row');
      const lastRow = rows[rows.length - 1];
      p.games.sort((a,b) => a.game_number - b.game_number).forEach(g => {
        const input = lastRow.querySelector(`.score-input[data-game="${g.game_number}"]`);
        if (input) input.value = g.score;
      });
    });
  } else {
    addPlayerRow('B'); addPlayerRow('B'); addPlayerRow('B');
  }

  // Giocatori singoli
  const soloScores = scores.filter(sc => !sc.team_name);
  const soloByPlayer = {};
  soloScores.forEach(sc => {
    if (!soloByPlayer[sc.player_id]) soloByPlayer[sc.player_id] = { player_id: sc.player_id, games: [] };
    soloByPlayer[sc.player_id].games.push({ game_number: sc.game_number || 1, score: sc.score });
  });
  Object.values(soloByPlayer).forEach(p => {
    addSoloRow(p.player_id, numGames);
    const rows = document.querySelectorAll('#soloRows .solo-row');
    const lastRow = rows[rows.length - 1];
    p.games.sort((a,b) => a.game_number - b.game_number).forEach(g => {
      const input = lastRow.querySelector(`.score-input[data-game="${g.game_number}"]`);
      if (input) input.value = g.score;
    });
  });

  calcTotals();
  document.getElementById('modalOverlay').classList.add('open');
  document.getElementById(`card-${id}`)?.classList.remove('open');
}

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
// openAddModal usa openModal da modal-nuova-partita.js
function openAddModal() { openModal(); }