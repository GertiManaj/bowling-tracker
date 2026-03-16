// ============================================
//  app.js — Strike Zone Bowling Tracker
//  Tutta la logica JS separata dall'HTML
// ============================================

const API = 'api'; // percorso relativo alla cartella api/

// ── UTILITY ─────────────────────────────────

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('it-IT', {
    day: '2-digit', month: 'short', year: 'numeric'
  }).toUpperCase();
}

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = (type === 'success' ? '✓ ' : '✕ ') + msg;
  t.className = `toast ${type} show`;
  setTimeout(() => t.className = 'toast', 3500);
}

// ── HERO BAR: STATISTICHE ────────────────────

async function loadStats() {
  try {
    const data = await fetch(`${API}/stats.php`).then(r => r.json());

    document.getElementById('stat-sessioni').textContent     = data.totale_sessioni ?? '—';
    document.getElementById('stat-sessioni-sub').textContent = "dall'inizio";
    document.getElementById('stat-record').textContent       = data.record_assoluto ?? '—';
    document.getElementById('stat-media').textContent        = data.media_gruppo ?? '—';

    if (data.record_holder) {
      document.getElementById('stat-record-sub').textContent =
        `${data.record_holder.emoji} ${data.record_holder.name} · ${formatDate(data.record_holder.date)}`;
    }

    if (data.ultima_sessione) {
      const d = new Date(data.ultima_sessione.date);
      document.getElementById('stat-ultima').textContent =
        d.toLocaleDateString('it-IT', { day: 'numeric', month: 'short' }).toUpperCase();
      document.getElementById('stat-ultima-sub').textContent = data.ultima_sessione.location;
    }
  } catch (e) {
    console.error('Errore stats:', e);
  }
}

// ── CLASSIFICA GENERALE ──────────────────────

let leaderboardMode = 'all'; // 'all' | 'last'
let cachedPlayers   = [];
let cachedSessions  = [];

function setLeaderboardMode(mode, btn) {
  leaderboardMode = mode;
  document.querySelectorAll('.lb-toggle-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderLeaderboard();
}

async function loadLeaderboard() {
  try {
    cachedPlayers = await fetch(`${API}/leaderboard.php`).then(r => r.json());
    window.cachedPlayers = cachedPlayers;
    renderLeaderboard();
  } catch (e) {
    document.getElementById('leaderboard-body').innerHTML =
      '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.75rem">Errore nel caricamento</div>';
  }
}

function renderLeaderboard() {
  if (leaderboardMode === 'last') {
    renderLastSessionLeaderboard();
  } else {
    renderAllTimeLeaderboard();
  }
}

function renderAllTimeLeaderboard() {
  // Separa chi ha giocato da chi non ha ancora giocato
  const players    = cachedPlayers.filter(p => parseInt(p.partite) > 0);
  const noGames    = cachedPlayers.filter(p => parseInt(p.partite) === 0);
  if (!players.length && !noGames.length) {
    document.getElementById('leaderboard-body').innerHTML =
      '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessuna partita ancora 🎳</div>';
    return;
  }

  // Aggiorna header
  document.getElementById('lb-header').innerHTML = `
    <div>#</div>
    <div>Giocatore</div>
    <div style="text-align:center">Media</div>
    <div style="text-align:center" class="col-partite">Partite</div>
    <div style="text-align:center">Record</div>
    <div style="text-align:center">Trend</div>`;
  document.getElementById('lb-header').style.gridTemplateColumns = '48px 1fr 100px 80px 80px 80px';

  const maxM    = players.length ? Math.max(...players.map(p => parseFloat(p.media) || 0)) : 1;
  const medals  = ['🥇','🥈','🥉'];
  const mColors = ['var(--gold)','var(--silver)','var(--bronze)'];
  const bColors = ['var(--neon)','var(--neon3)','var(--neon4)','var(--neon2)'];

  let html = '';
  players.forEach((p, i) => {
    const bc    = bColors[i % bColors.length];
    const pct   = maxM > 0 ? Math.round(parseFloat(p.media) / maxM * 100) : 0;
    const delay = (i * 0.07).toFixed(2);
    const rankEl = i < 3
      ? `<div class="rank" style="color:${mColors[i]};text-shadow:0 0 10px ${mColors[i]}88">${medals[i]}</div>`
      : `<div class="rank-other">${i+1}</div>`;

    const trend = p.trend || [];
    let spark = '';
    if (trend.length) {
      const mx = Math.max(...trend);
      trend.forEach((v, ti) => {
        const h = mx > 0 ? Math.round(v/mx*100) : 50;
        const last = ti === trend.length-1;
        spark += `<div class="sparkline-bar${last?' last':''}" style="height:${h}%;background:${bc};${last?'opacity:1;box-shadow:0 0 6px '+bc:''}"></div>`;
      });
    } else spark = '<span style="color:var(--text-muted);font-size:0.65rem">—</span>';

    const nc = i===0?'var(--gold)':i===1?'var(--silver)':i===2?'var(--bronze)':'var(--text)';

    html += `
      <div class="leaderboard-row" style="animation-delay:${delay}s;grid-template-columns:48px 1fr 100px 80px 80px 80px">
        ${rankEl}
        <div class="player-info">
          <div class="avatar" style="background:${bc}18;border-color:${bc}44">${p.emoji||'🎳'}</div>
          <div>
            <div class="player-name">${p.name}</div>
            <div class="player-tag">${p.partite} serate · ${p.game_totali||0} game</div>
          </div>
        </div>
        <div class="stat-cell" style="color:${nc}">
          <strong>${p.media??'—'}</strong>
          <div class="mini-bar-bg">
            <div class="mini-bar-fill" style="width:0%;background:${bc};box-shadow:0 0 6px ${bc}" data-w="${pct}%"></div>
          </div>
        </div>
        <div class="stat-cell col-partite">${p.partite}</div>
        <div class="stat-cell best">${p.record??'—'}</div>
        <div class="stat-cell"><div class="sparkline">${spark}</div></div>
      </div>`;
  });

  // Aggiungi giocatori senza partite in fondo
  noGames.forEach(p => {
    html += `
      <div class="leaderboard-row" style="grid-template-columns:48px 1fr 100px 80px 80px 80px;opacity:0.4">
        <div class="rank-other">—</div>
        <div class="player-info">
          <div class="avatar" style="border-color:var(--border)">${p.emoji||'🎳'}</div>
          <div>
            <div class="player-name">${p.name}</div>
            <div class="player-tag">0 serate · 0 game</div>
          </div>
        </div>
        <div class="stat-cell">—</div>
        <div class="stat-cell col-partite">0</div>
        <div class="stat-cell">—</div>
        <div class="stat-cell">—</div>
      </div>`;
  });

  document.getElementById('leaderboard-body').innerHTML = html ||
    '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessuna partita ancora 🎳</div>';
  setTimeout(() => {
    document.querySelectorAll('.mini-bar-fill').forEach(el => { el.style.width = el.dataset.w; });
  }, 100);
}

function renderLastSessionLeaderboard() {
  // Usa i dati dell'ultima sessione già caricata
  const sessions = cachedSessions;
  if (!sessions.length) {
    document.getElementById('leaderboard-body').innerHTML =
      '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessuna sessione</div>';
    return;
  }

  const lastSession = sessions[0];
  const scores      = lastSession.scores || [];
  const teams       = lastSession.teams  || [];
  const numGames    = scores.length ? Math.max(...scores.map(s => s.game_number||1)) : 1;

  // Raggruppa per giocatore e somma i game
  const byPlayer = {};
  scores.forEach(sc => {
    if (!byPlayer[sc.player_name]) {
      // Trova la squadra
      byPlayer[sc.player_name] = {
        name: sc.player_name, emoji: sc.emoji,
        team: sc.team_name, total: 0, games: 0
      };
    }
    byPlayer[sc.player_name].total += parseInt(sc.score)||0;
    byPlayer[sc.player_name].games++;
  });

  // Trova il team vincitore
  const teamTotals = {};
  teams.forEach(t => { teamTotals[t.name] = 0; });
  scores.forEach(sc => { if (teamTotals[sc.team_name] !== undefined) teamTotals[sc.team_name] += parseInt(sc.score)||0; });
  const maxTeamTotal = Math.max(...Object.values(teamTotals));
  const winningTeam  = Object.keys(teamTotals).find(t => teamTotals[t] === maxTeamTotal);

  // Ordina per totale decrescente
  const sorted = Object.values(byPlayer).sort((a,b) => b.total - a.total);
  const maxTotal = sorted.length ? sorted[0].total : 0;

  // Aggiorna header
  document.getElementById('lb-header').innerHTML = `
    <div>#</div>
    <div>Giocatore</div>
    <div style="text-align:center">Totale</div>
    <div style="text-align:center">Media game</div>
    <div style="text-align:center" class="col-partite">Squadra</div>
    <div style="text-align:center">Risultato</div>`;
  document.getElementById('lb-header').style.gridTemplateColumns = '48px 1fr 90px 90px 100px 90px';

  const bColors = ['var(--neon)','var(--neon3)','var(--neon4)','var(--neon2)'];
  const medals  = ['🥇','🥈','🥉'];

  let html = '';
  sorted.forEach((p, i) => {
    const bc      = bColors[i % bColors.length];
    const delay   = (i * 0.07).toFixed(2);
    const rankEl  = i < 3
      ? `<div class="rank" style="color:${['var(--gold)','var(--silver)','var(--bronze)'][i]}">${medals[i]}</div>`
      : `<div class="rank-other">${i+1}</div>`;
    const mediaGame = p.games > 0 ? (p.total / p.games).toFixed(1) : '—';
    const isWin     = p.team === winningTeam;
    const teamColor = isWin ? 'var(--neon)' : 'var(--neon2)';

    html += `
      <div class="leaderboard-row" style="animation-delay:${delay}s;grid-template-columns:48px 1fr 90px 90px 100px 90px">
        ${rankEl}
        <div class="player-info">
          <div class="avatar" style="background:${bc}18;border-color:${bc}44">${p.emoji||'🎳'}</div>
          <div>
            <div class="player-name">${p.name}</div>
            <div class="player-tag">${numGames} game giocati</div>
          </div>
        </div>
        <div class="stat-cell" style="color:${i===0?'var(--gold)':'var(--text)'}">
          <strong>${p.total}</strong>
        </div>
        <div class="stat-cell" style="color:var(--neon3)">${mediaGame}</div>
        <div class="stat-cell col-partite" style="font-size:0.75rem;color:${teamColor}">${p.team||'—'}</div>
        <div class="stat-cell">
          <span class="team-tag ${isWin?'win':'lose'}" style="font-size:0.65rem">${isWin?'WIN':'LOSE'}</span>
        </div>
      </div>`;
  });

  document.getElementById('leaderboard-body').innerHTML = html ||
    '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessun dato</div>';
}

// ── SESSIONI RECENTI ─────────────────────────

async function loadSessions() {
  try {
    const sessions = await fetch(`${API}/sessions.php`).then(r => r.json());

    // Lista ultime 5
    let html = '';
    sessions.slice(0, 5).forEach(s => {
      const sc    = s.scores || [];
      const names = [...new Set(sc.map(x => x.player_name))].join(' · ');

      // Calcola totale per giocatore (somma tutti i game)
      const totals = {};
      sc.forEach(x => {
        totals[x.player_name] = totals[x.player_name] || { name: x.player_name, emoji: x.emoji, total: 0 };
        totals[x.player_name].total += parseInt(x.score) || 0;
      });
      const best = Object.values(totals).reduce((a, b) => b.total > a.total ? b : a, { total: 0 });

      html += `
        <div class="session-item">
          <div class="session-item-left">
            <div class="session-item-date">${formatDate(s.date)} · ${s.location}</div>
            <div class="session-item-players">${names || '—'}</div>
          </div>
          <div class="session-winner">
            <div class="session-winner-label">Miglior serata</div>
            <div class="session-winner-name">${best.name || '—'}</div>
            <div class="session-winner-score">${best.total || '—'} pts</div>
          </div>
        </div>`;
    });

    document.getElementById('sessions-body').innerHTML = html ||
      '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessuna sessione ancora — inizia a giocare! 🎳</div>';

    // Sidebar: ultima sessione
    cachedSessions = sessions;
    window.cachedSessions = cachedSessions;
    if (sessions.length > 0) renderLastSession(sessions[0]);

  } catch (e) {
    console.error('Errore sessioni:', e);
  }
}

function renderLastSession(s) {
  const teams  = s.teams  || [];
  const scores = s.scores || [];

  // Raggruppa punteggi per squadra, poi per giocatore (somma game multipli)
  const byTeam = {};
  teams.forEach(t => { byTeam[t.name] = { name: t.name, total: 0, players: {} }; });

  scores.forEach(sc => {
    if (!byTeam[sc.team_name]) return;
    const team   = byTeam[sc.team_name];
    const pname  = sc.player_name;
    // Accumula per giocatore (somma tutti i game)
    if (!team.players[pname]) {
      team.players[pname] = { name: pname, emoji: sc.emoji, total: 0, games: [] };
    }
    team.players[pname].total += parseInt(sc.score) || 0;
    team.players[pname].games.push({ game: sc.game_number || 1, score: sc.score });
    team.total += parseInt(sc.score) || 0;
  });

  // Deduplicazione totale squadra: somma totali giocatori (evita doppio conteggio)
  Object.values(byTeam).forEach(team => {
    team.total = Object.values(team.players).reduce((s, p) => s + p.total, 0);
  });

  const teamList = Object.values(byTeam);
  const maxT     = Math.max(...teamList.map(t => t.total));
  const tColors  = ['var(--neon)', 'var(--neon2)', 'var(--neon3)', 'var(--neon4)'];
  const numGames = scores.length > 0 ? Math.max(...scores.map(sc => sc.game_number || 1)) : 1;

  let teamsHtml = '';
  teamList.forEach((t, i) => {
    const win      = t.total === maxT && maxT > 0;
    const c        = tColors[i % tColors.length];
    const maxScore = Math.max(...Object.values(t.players).map(p => p.total));

    const plHtml = Object.values(t.players).map(p => {
      const gamesHtml = numGames > 1
        ? p.games.sort((a,b) => a.game - b.game)
            .map(g => `<span style="font-size:0.68rem;color:var(--text-muted)">G${g.game}:${g.score}</span>`)
            .join(' ')
        : '';
      const isTop = p.total === maxScore;
      return `
        <div class="team-player-row">
          <span>${p.emoji || '🎳'} ${p.name}</span>
          <div style="display:flex;align-items:center;gap:0.5rem">
            ${gamesHtml ? `<span style="font-family:'Share Tech Mono',monospace">${gamesHtml}</span>` : ''}
            <span class="team-player-score" style="${isTop ? 'color:var(--gold)' : ''}">${p.total}</span>
          </div>
        </div>`;
    }).join('');

    teamsHtml += `
      <div class="team-block">
        <div class="team-header">
          <span class="team-name-lbl" style="color:${c}">${t.name}</span>
          <div style="display:flex;align-items:center;gap:0.5rem">
            <span class="team-tag ${win ? 'win' : 'lose'}">${win ? 'VITTORIA' : 'SCONFITTA'}</span>
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.75rem;color:${win ? c : 'var(--text-muted)'}">${t.total}</span>
          </div>
        </div>
        <div class="team-players">${plHtml}</div>
      </div>`;
  });

  document.getElementById('last-session-card').innerHTML = `
    <div class="card-header">
      <div class="card-title">${formatDate(s.date)}</div>
      <div class="card-date">${s.location}${numGames > 1 ? ` · ${numGames} game` : ''}</div>
    </div>
    <div class="session-teams">
      ${teamsHtml || '<div style="padding:1rem;color:var(--text-muted);font-size:0.8rem">Nessun punteggio</div>'}
    </div>`;
}

// ── HALL OF FAME ─────────────────────────────

async function loadHof() {
  try {
    const d = await fetch(`${API}/stats.php`).then(r => r.json());

    document.getElementById('hof-card').innerHTML = `
      <div class="hof-row" style="padding:1rem 1.2rem">
        <div>
          <div class="hof-label">Record assoluto</div>
          <div style="font-family:'Black Han Sans',sans-serif;font-size:1.4rem;color:var(--gold);text-shadow:0 0 15px rgba(255,215,0,0.4)">
            ${d.record_assoluto ?? '—'}
          </div>
          <div class="hof-sub">
            ${d.record_holder ? d.record_holder.emoji + ' ' + d.record_holder.name + ' · ' + formatDate(d.record_holder.date) : '—'}
          </div>
        </div>
        <div style="font-size:2.5rem">🏆</div>
      </div>
      <div class="hof-row">
        <div>
          <div class="hof-label">Più vittorie</div>
          <div class="hof-name">${d.most_wins ? d.most_wins.emoji + ' ' + d.most_wins.name : '—'}</div>
          <div class="hof-sub">${d.most_wins ? d.most_wins.vittorie + ' sessioni vinte' : ''}</div>
        </div>
        <div class="hof-val" style="color:var(--neon)">${d.most_wins?.vittorie ?? '—'}</div>
      </div>
      <div class="hof-row">
        <div>
          <div class="hof-label">Più migliorato</div>
          <div class="hof-name">${d.most_improved ? d.most_improved.emoji + ' ' + d.most_improved.name : '—'}</div>
          <div class="hof-sub">${d.most_improved ? '+' + d.most_improved.miglioramento + ' pts di media' : 'min. 4 partite'}</div>
        </div>
        <div class="hof-val" style="color:var(--neon2)">📈</div>
      </div>`;
  } catch (e) {
    console.error('Errore HoF:', e);
  }
}

// ── MODAL: NUOVA PARTITA ─────────────────────

let allPlayers = [];

async function openModal() {
  if (!window.isLoggedIn) { openLoginModal(); return; }
  try {
    allPlayers = await fetch(`${API}/players.php`).then(r => r.json());
  } catch (e) {
    allPlayers = [];
  }

  // Reset campi
  document.getElementById('sessionDate').value     = new Date().toISOString().split('T')[0];
  document.getElementById('sessionLocation').value = '';
  document.getElementById('sessionNotes').value    = '';
  document.getElementById('teamAName').value       = '';
  document.getElementById('teamBName').value       = '';
  document.getElementById('numGames').value        = '2';
  document.getElementById('totalA').textContent    = 'Totale: 0';
  document.getElementById('totalB').textContent    = 'Totale: 0';

  buildGameRows();
  document.getElementById('modalOverlay').classList.add('open');
}

// Costruisce le righe giocatori con una colonna per ogni game
function buildGameRows() {
  const numGames = parseInt(document.getElementById('numGames').value) || 1;
  ['A', 'B'].forEach(team => {
    const container = document.getElementById(`team${team}Rows`);
    // Salva giocatori già selezionati
    const selected = [];
    container.querySelectorAll('.player-row').forEach(row => {
      selected.push(row.querySelector('select')?.value || '');
    });
    container.innerHTML = '';
    // Ricrea 3 righe (o quante erano)
    const count = Math.max(selected.length, 3);
    for (let i = 0; i < count; i++) {
      addPlayerRow(team, selected[i] || null, numGames);
    }
  });
  calcTotals();
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
}

function handleOverlayClick(e) {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
}

function addPlayerRow(team, selectedId = null, numGames = null) {
  const ng   = numGames || parseInt(document.getElementById('numGames')?.value) || 1;
  const opts = allPlayers.map(p =>
    `<option value="${p.id}" ${parseInt(p.id) === parseInt(selectedId) ? 'selected' : ''}>${p.emoji || '🎳'} ${p.name}</option>`
  ).join('');

  // Crea input score per ogni game
  const gameInputs = Array.from({length: ng}, (_, i) =>
    `<input type="number" class="form-input score-input" placeholder="G${i+1}" min="0" max="300" data-game="${i+1}" oninput="calcTotals()"/>`
  ).join('');

  const row = document.createElement('div');
  row.className = 'player-row';
  row.style.cssText = `display:grid;grid-template-columns:1fr ${Array(ng).fill('70px').join(' ')} 32px;gap:0.4rem;align-items:center;margin-bottom:0.4rem`;
  row.innerHTML = `
    <select class="form-input" onchange="calcTotals()">
      <option value="">— Giocatore —</option>${opts}
    </select>
    ${gameInputs}
    <button class="btn-remove" onclick="this.parentElement.remove();calcTotals()" title="Rimuovi">✕</button>`;

  document.getElementById(`team${team}Rows`).appendChild(row);
}

function calcTotals() {
  ['A', 'B'].forEach(t => {
    let tot = 0;
    document.querySelectorAll(`#team${t}Rows .score-input`).forEach(i => {
      if (i.value) tot += parseInt(i.value) || 0;
    });
    document.getElementById(`total${t}`).textContent = `Totale: ${tot}`;
  });
}

async function saveSession() {
  const btn = document.getElementById('btnSave');
  btn.disabled = true;
  btn.textContent = 'Salvataggio...';

  const date = document.getElementById('sessionDate').value;
  if (!date) {
    showToast('Inserisci la data', 'error');
    btn.disabled = false; btn.textContent = 'Salva Partita';
    return;
  }

  // Costruisci payload squadre con game multipli
  const numGames = parseInt(document.getElementById('numGames').value) || 1;
  const teams = [];
  ['A', 'B'].forEach(t => {
    const name    = document.getElementById(`team${t}Name`).value || `Squadra ${t}`;
    const players = [];
    document.querySelectorAll(`#team${t}Rows .player-row`).forEach(row => {
      const pid = row.querySelector('select')?.value;
      if (!pid) return;
      // Un record per ogni game
      row.querySelectorAll('.score-input').forEach(input => {
        const gameNum = parseInt(input.dataset.game);
        const score   = input.value;
        if (score) players.push({ player_id: parseInt(pid), score: parseInt(score), game_number: gameNum });
      });
    });
    if (players.length > 0) teams.push({ name, players });
  });

  if (!teams.length) {
    showToast('Inserisci almeno un punteggio', 'error');
    btn.disabled = false; btn.textContent = 'Salva Partita';
    return;
  }

  try {
    const res  = await fetch(`${API}/sessions.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        date,
        location: document.getElementById('sessionLocation').value || 'Bowling',
        notes:    document.getElementById('sessionNotes').value,
        teams
      })
    });
    const data = await res.json();

    if (data.success) {
      closeModal();
      showToast('Partita salvata!');
      // Ricarica tutto
      loadStats();
      loadLeaderboard();
      loadSessions();
      loadHof();
    } else {
      showToast(data.error || 'Errore nel salvataggio', 'error');
    }
  } catch (e) {
    showToast('Errore di connessione', 'error');
    console.error(e);
  }

  btn.disabled = false;
  btn.textContent = 'Salva Partita';
}

// ── INIT ─────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
  loadStats();
  loadLeaderboard();
  loadSessions();
  loadHof();
});