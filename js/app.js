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
window.leaderboardMode = 'all'; // sincronizza subito su window
let cachedPlayers   = [];
let cachedSessions  = [];

function setLeaderboardMode(mode, btn) {
  leaderboardMode = mode;
  window.leaderboardMode = mode;
  // Reset tutti i toggle
  document.querySelectorAll('.lb-toggle-btn').forEach(b => {
    b.classList.remove('active');
    b.style.background   = 'none';
    b.style.borderColor  = 'var(--border)';
    b.style.color        = 'var(--text-muted)';
  });
  // Attiva quello cliccato
  btn.classList.add('active');
  btn.style.background  = 'rgba(232,255,0,0.08)';
  btn.style.borderColor = 'rgba(232,255,0,0.4)';
  btn.style.color       = 'var(--neon)';
  renderLeaderboard();

  // Aggiorna testo bottone salva foto
  const saveBtn = document.getElementById('btnSaveClassifica');
  if (saveBtn) saveBtn.textContent = mode === 'last' ? '📸 Salva ultima serata' : '📸 Salva foto';
}

async function loadLeaderboard() {
  try {
    cachedPlayers = await fetch(`${API}/leaderboard.php`).then(r => r.json());
    window.cachedPlayers = cachedPlayers;
    renderLeaderboard();
    buildSuggestPlayers();
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

// ── ORDINAMENTO CLASSIFICA ───────────────────
let lbSortField = 'media';
let lbSortDir   = 'desc';

function sortLeaderboard(field) {
  if (lbSortField === field) {
    lbSortDir = lbSortDir === 'desc' ? 'asc' : 'desc';
  } else {
    lbSortField = field;
    lbSortDir   = 'desc';
  }
  renderAllTimeLeaderboard();
}

function getLbValue(p, field) {
  if (field === 'media')      return parseFloat(p.media)      || 0;
  if (field === 'record')     return parseInt(p.record)       || 0;
  if (field === 'partite')    return parseInt(p.partite)      || 0;
  if (field === 'win_pct') {
    const serate = parseInt(p.partite) || 0;
    const vitt   = parseInt(p.vittorie_squadra) || 0;
    return serate > 0 ? Math.round(vitt / serate * 100) : 0;
  }
  if (field === 'top_scorer') return parseInt(p.volte_top_scorer) || 0;
  if (field === 'forma')      return (p.ultimi_risultati || []).filter(r => r === 'V').length;
  return 0;
}

function renderAllTimeLeaderboard() {
  const players = cachedPlayers.filter(p => parseInt(p.partite) > 0);
  const noGames = cachedPlayers.filter(p => parseInt(p.partite) === 0);
  if (!players.length && !noGames.length) {
    document.getElementById('leaderboard-body').innerHTML =
      '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessuna partita ancora 🎳</div>';
    return;
  }

  // Ordina per campo selezionato
  const sorted = [...players].sort((a, b) => {
    const va = getLbValue(a, lbSortField);
    const vb = getLbValue(b, lbSortField);
    return lbSortDir === 'desc' ? vb - va : va - vb;
  });

  const cols = '48px 1fr 90px 80px 80px 80px 80px 80px';

  // Freccia indicatore ordinamento
  const arrow = (field) => {
    if (lbSortField !== field) return '<span style="opacity:0.3">↕</span>';
    return lbSortDir === 'desc' ? '↓' : '↑';
  };

  // Stile header cliccabile
  const hStyle = (field) => {
    const active = lbSortField === field;
    return `style="text-align:center;cursor:pointer;user-select:none;${active ? 'color:var(--neon);' : ''}"
      onclick="sortLeaderboard('${field}')"
      title="Ordina per questo campo"`;
  };

  document.getElementById('lb-header').innerHTML = `
    <div>#</div>
    <div>Giocatore</div>
    <div ${hStyle('media')}>Media ${arrow('media')}</div>
    <div ${hStyle('record')}>Record ${arrow('record')}</div>
    <div ${hStyle('partite')} class="col-partite">Serate ${arrow('partite')}</div>
    <div ${hStyle('win_pct')}>% Vitt ${arrow('win_pct')}</div>
    <div ${hStyle('top_scorer')}>Top 🏆 ${arrow('top_scorer')}</div>
    <div ${hStyle('forma')}>Forma ${arrow('forma')}</div>`;
  document.getElementById('lb-header').style.gridTemplateColumns = cols;

  const maxM    = sorted.length ? Math.max(...sorted.map(p => parseFloat(p.media) || 0)) : 1;
  const medals  = ['🥇','🥈','🥉'];
  const mColors = ['var(--gold)','var(--silver)','var(--bronze)'];
  const bColors = ['var(--neon)','var(--neon3)','var(--neon4)','var(--neon2)'];

  let html = '';
  sorted.forEach((p, i) => {
    const bc    = bColors[i % bColors.length];
    const pct   = maxM > 0 ? Math.round(parseFloat(p.media) / maxM * 100) : 0;
    const delay = (i * 0.07).toFixed(2);
    const rankEl = i < 3
      ? `<div class="rank" style="color:${mColors[i]};text-shadow:0 0 10px ${mColors[i]}88">${medals[i]}</div>`
      : `<div class="rank-other">${i+1}</div>`;

    const nc = i===0?'var(--gold)':i===1?'var(--silver)':i===2?'var(--bronze)':'var(--text)';

    // Calcola valori colonne
    const serate  = parseInt(p.partite) || 0;
    const vittorie = parseInt(p.vittorie_squadra) || 0;
    const winPct  = serate > 0 ? Math.round(vittorie / serate * 100) : null;
    const topScore = parseInt(p.volte_top_scorer) || 0;
    // forma calcolata tramite ultimi_risultati
    const mediaVal = parseFloat(p.media) || 0;

    // Badge forma — ultimi 5 risultati come pallini V/P/N
    const risultati = p.ultimi_risultati || [];
    let formaBadge = '';
    if (!risultati.length) {
      formaBadge = '<span style="color:var(--text-muted);font-size:0.7rem;font-family:\'Share Tech Mono\',monospace">—</span>';
    } else {
      const dots = risultati.map(r => {
        if (r === 'V') return '<span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#22c55e;color:#000;font-size:0.55rem;font-weight:700;font-family:\'Share Tech Mono\',monospace">V</span>';
        if (r === 'P') return '<span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#ef4444;color:#fff;font-size:0.55rem;font-weight:700;font-family:\'Share Tech Mono\',monospace">P</span>';
        return '<span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#555570;color:#fff;font-size:0.55rem;font-weight:700;font-family:\'Share Tech Mono\',monospace">N</span>';
      }).join('');
      // Pallini grigi per le partite mancanti
      const missing = 5 - risultati.length;
      const emptyDots = Array(missing).fill('<span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#2a2a44;border:1px solid #3a3a5a"></span>').join('');
      formaBadge = `<div style="display:flex;gap:2px;justify-content:center">${emptyDots}${dots}</div>`;
    }

    // Highlight colonna attiva
    const cellStyle = (field) => lbSortField === field
      ? 'color:var(--neon);font-weight:700'
      : '';

    html += `
      <div class="leaderboard-row" style="animation-delay:${delay}s;grid-template-columns:${cols}">
        ${rankEl}
        <div class="player-info">
          <div class="avatar" style="background:${bc}18;border-color:${bc}44">${p.emoji||'🎳'}</div>
          <div>
            <div class="player-name">${p.name}</div>
            <div class="player-tag">${p.partite} serate · ${p.game_totali||0} game</div>
          </div>
        </div>
        <div class="stat-cell" style="${cellStyle('media') || 'color:'+nc}">
          <strong>${p.media??'—'}</strong>
          <div class="mini-bar-bg">
            <div class="mini-bar-fill" style="width:0%;background:${bc};box-shadow:0 0 6px ${bc}" data-w="${pct}%"></div>
          </div>
        </div>
        <div class="stat-cell best" style="${cellStyle('record')}">${p.record??'—'}</div>
        <div class="stat-cell col-partite" style="${cellStyle('partite')}">${p.partite}</div>
        <div class="stat-cell" style="${cellStyle('win_pct')}">${winPct !== null ? winPct+'%' : '—'}</div>
        <div class="stat-cell" style="${cellStyle('top_scorer')}">${topScore || '—'}</div>
        <div class="stat-cell" style="${cellStyle('forma')}">${formaBadge}</div>
      </div>`;
  });

  // Giocatori senza partite in fondo
  noGames.forEach(p => {
    html += `
      <div class="leaderboard-row" style="grid-template-columns:${cols};opacity:0.4">
        <div class="rank-other">—</div>
        <div class="player-info">
          <div class="avatar" style="border-color:var(--border)">${p.emoji||'🎳'}</div>
          <div>
            <div class="player-name">${p.name}</div>
            <div class="player-tag">0 serate · 0 game</div>
          </div>
        </div>
        <div class="stat-cell">—</div>
        <div class="stat-cell">—</div>
        <div class="stat-cell col-partite">0</div>
        <div class="stat-cell">—</div>
        <div class="stat-cell">—</div>
        <div class="stat-cell">—</div>
      </div>`;
  });

  // Aggiungi leggenda forma
  html += `
    <div style="padding:0.6rem 1.2rem;border-top:1px solid var(--border);display:flex;align-items:center;gap:0.8rem;flex-wrap:wrap">
      <span style="font-family:'Share Tech Mono',monospace;font-size:0.58rem;letter-spacing:0.15em;color:var(--text-muted);text-transform:uppercase">Forma (ultimi 5):</span>
      <span style="display:flex;align-items:center;gap:0.3rem;font-size:0.68rem;color:var(--text-muted)">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#22c55e;color:#000;font-size:0.5rem;font-weight:700">V</span> Vittoria
      </span>
      <span style="display:flex;align-items:center;gap:0.3rem;font-size:0.68rem;color:var(--text-muted)">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#ef4444;color:#fff;font-size:0.5rem;font-weight:700">P</span> Sconfitta
      </span>
      <span style="display:flex;align-items:center;gap:0.3rem;font-size:0.68rem;color:var(--text-muted)">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#555570;color:#fff;font-size:0.5rem;font-weight:700">N</span> Pareggio
      </span>
      <span style="display:flex;align-items:center;gap:0.3rem;font-size:0.68rem;color:var(--text-muted)">
        <span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#2a2a44;border:1px solid #3a3a5a"></span> Nessun dato
      </span>
    </div>`;

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
    renderCalendar();
    buildSuggestPlayers();

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

  // Giocatori singoli (senza squadra)
  const soloPlayers2 = {};
  scores.forEach(sc => {
    if (sc.team_name) return;
    const pname = sc.player_name;
    if (!soloPlayers2[pname]) soloPlayers2[pname] = { name: pname, emoji: sc.emoji, total: 0, games: [] };
    soloPlayers2[pname].total += parseInt(sc.score) || 0;
    soloPlayers2[pname].games.push({ game: sc.game_number || 1, score: sc.score });
  });

  let soloHtml = '';
  const soloList = Object.values(soloPlayers2);
  if (soloList.length) {
    const soloRows = soloList.map(p => {
      const gamesHtml = numGames > 1
        ? p.games.sort((a,b) => a.game - b.game).map(g => `<span style="font-size:0.68rem;color:var(--text-muted)">G${g.game}:${g.score}</span>`).join(' ')
        : '';
      return `
        <div class="team-player-row">
          <span>${p.emoji || '🎳'} ${p.name}</span>
          <div style="display:flex;align-items:center;gap:0.5rem">
            ${gamesHtml ? `<span style="font-family:'Share Tech Mono',monospace">${gamesHtml}</span>` : ''}
            <span class="team-player-score" style="color:var(--neon3)">${p.total}</span>
          </div>
        </div>`;
    }).join('');

    soloHtml = `
      <div class="team-block" style="border-color:var(--neon3)44">
        <div class="team-header" style="background:rgba(0,245,255,0.05)">
          <span class="team-name-lbl" style="color:var(--neon3)">👤 Singoli</span>
          <span style="font-family:'Share Tech Mono',monospace;font-size:0.65rem;color:var(--text-muted)">Fuori sfida</span>
        </div>
        <div class="team-players">${soloRows}</div>
      </div>`;
  }

  document.getElementById('last-session-card').innerHTML = `
    <div class="card-header">
      <div class="card-title">${formatDate(s.date)}</div>
      <div class="card-date">${s.location}${numGames > 1 ? ` · ${numGames} game` : ''}</div>
    </div>
    <div class="session-teams">
      ${teamsHtml}${soloHtml}
      ${!teamsHtml && !soloHtml ? '<div style="padding:1rem;color:var(--text-muted);font-size:0.8rem">Nessun punteggio</div>' : ''}
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
  document.getElementById('soloRows').innerHTML    = '';

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

function addSoloRow(selectedId = null, numGames = null) {
  const ng   = numGames || parseInt(document.getElementById('numGames')?.value) || 1;
  const opts = allPlayers.map(p =>
    `<option value="${p.id}" ${parseInt(p.id) === parseInt(selectedId) ? 'selected' : ''}>${p.emoji || '🎳'} ${p.name}</option>`
  ).join('');
  const gameInputs = Array.from({length: ng}, (_, i) =>
    `<input type="number" class="form-input score-input" placeholder="G${i+1}" min="0" max="300" data-game="${i+1}"/>`
  ).join('');

  const row = document.createElement('div');
  row.className = 'player-row solo-row';
  row.style.cssText = `display:grid;grid-template-columns:1fr ${Array(ng).fill('70px').join(' ')} 32px;gap:0.4rem;align-items:center;margin-bottom:0.4rem`;
  row.innerHTML = `
    <select class="form-input">
      <option value="">— Giocatore —</option>${opts}
    </select>
    ${gameInputs}
    <button class="btn-remove" onclick="this.parentElement.remove()" title="Rimuovi">✕</button>`;
  document.getElementById('soloRows').appendChild(row);
}

function getSoloPlayers() {
  const solo = [];
  document.querySelectorAll('#soloRows .solo-row').forEach(row => {
    const pid = row.querySelector('select')?.value;
    if (!pid) return;
    row.querySelectorAll('.score-input').forEach(input => {
      const gameNum = parseInt(input.dataset.game);
      const score   = input.value;
      if (score) solo.push({ player_id: parseInt(pid), score: parseInt(score), game_number: gameNum });
    });
  });
  return solo;
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

  const soloPlayers = getSoloPlayers();

  if (!teams.length && !soloPlayers.length) {
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
        teams,
        solo_players: getSoloPlayers()
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



// ── CALENDARIO ──────────────────────────────

let calendarDate = new Date();

function renderCalendar() {
  const widget = document.getElementById('calendarWidget');
  if (!widget) return;

  const sessions     = cachedSessions || [];
  const sessionDates = {};
  sessions.forEach(s => { sessionDates[s.date] = s; });

  const year        = calendarDate.getFullYear();
  const month       = calendarDate.getMonth();
  const monthName   = new Date(year, month, 1).toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });
  const firstDay    = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const today       = new Date().toISOString().split('T')[0];
  const startOffset = firstDay === 0 ? 6 : firstDay - 1;
  const dayHeaders  = ['L','M','M','G','V','S','D'];

  let html = `
    <div class="calendar-nav">
      <button class="calendar-nav-btn" onclick="changeCalendarMonth(-1)">◀</button>
      <span class="calendar-month-label">${monthName.toUpperCase()}</span>
      <button class="calendar-nav-btn" onclick="changeCalendarMonth(1)">▶</button>
    </div>
    <div class="calendar-grid">
      ${dayHeaders.map(d => `<div class="calendar-day-header">${d}</div>`).join('')}
      ${Array(startOffset).fill('<div class="calendar-day empty"></div>').join('')}`;

  for (let d = 1; d <= daysInMonth; d++) {
    const pad      = String(d).padStart(2,'0');
    const mPad     = String(month+1).padStart(2,'0');
    const dateStr  = `${year}-${mPad}-${pad}`;
    const hasSess  = sessionDates[dateStr];
    const isToday  = dateStr === today;

    let cls = 'calendar-day';
    if (hasSess) cls += ' has-session';
    if (isToday) cls += ' today';

    const onclick = hasSess ? `onclick="scrollToSession('${dateStr}')"` : '';
    const title   = hasSess ? `title="${hasSess.location}"` : '';

    html += `<div class="${cls}" ${onclick} ${title}>${d}</div>`;
  }

  html += '</div>';
  widget.innerHTML = html;
}

function changeCalendarMonth(dir) {
  calendarDate = new Date(calendarDate.getFullYear(), calendarDate.getMonth() + dir, 1);
  renderCalendar();
}

function scrollToSession(dateStr) {
  window.location.href = `sessioni.html?date=${dateStr}`;
}

// ── SUGGERITORE SQUADRE ──────────────────────

let suggestSelected = new Set();

function clearSuggestSelection() {
  suggestSelected.clear();
  buildSuggestPlayers();
  document.getElementById('suggestResult').innerHTML = '';
  const btn = document.getElementById('btnSuggest');
  if (btn) btn.textContent = '🎯 Suggerisci squadre';
}

function buildSuggestPlayers() {
  const container = document.getElementById('suggestPlayers');
  if (!container || !cachedPlayers.length) return;

  container.innerHTML = cachedPlayers.map(p => {
    const isSelected = suggestSelected.has(p.id);
    return `
      <button
        onclick="toggleSuggestPlayer(${p.id}, this)"
        style="
          font-family:'Barlow Condensed',sans-serif;
          font-size:0.8rem;font-weight:600;
          letter-spacing:0.05em;
          background:${isSelected ? 'rgba(232,255,0,0.12)' : 'none'};
          border:1px solid ${isSelected ? 'rgba(232,255,0,0.4)' : 'var(--border)'};
          color:${isSelected ? 'var(--neon)' : 'var(--text-muted)'};
          padding:0.3rem 0.6rem;border-radius:20px;cursor:pointer;
          transition:all 0.15s;display:flex;align-items:center;gap:0.3rem
        "
      >${p.emoji || '🎳'} ${p.name}</button>`;
  }).join('');
}

function toggleSuggestPlayer(id, btn) {
  if (suggestSelected.has(id)) {
    suggestSelected.delete(id);
    btn.style.background   = 'none';
    btn.style.borderColor  = 'var(--border)';
    btn.style.color        = 'var(--text-muted)';
  } else {
    suggestSelected.add(id);
    btn.style.background   = 'rgba(232,255,0,0.12)';
    btn.style.borderColor  = 'rgba(232,255,0,0.4)';
    btn.style.color        = 'var(--neon)';
  }
  // Aggiorna contatore
  const btn2 = document.getElementById('btnSuggest');
  if (btn2) btn2.textContent = suggestSelected.size >= 2
    ? `🎯 Suggerisci squadre (${suggestSelected.size} giocatori)`
    : '🎯 Suggerisci squadre';
}

async function suggestTeams() {
  if (suggestSelected.size < 2) {
    showToast('Seleziona almeno 2 giocatori', 'error');
    return;
  }

  const btn = document.getElementById('btnSuggest');
  btn.disabled    = true;
  btn.textContent = '⏳ Calcolo in corso...';

  try {
    const res  = await fetch(`${API}/suggest.php`, {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ player_ids: [...suggestSelected] })
    });
    const data = await res.json();

    if (data.error) { showToast(data.error, 'error'); return; }

    window._lastSuggestData = data;
    renderSuggestResult(data);
  } catch(e) {
    showToast('Errore nel calcolo', 'error');
  }

  btn.disabled    = false;
  btn.textContent = `🎯 Suggerisci squadre (${suggestSelected.size} giocatori)`;
}

function renderSuggestResult(data) {
  const tColors = ['var(--neon)', 'var(--neon2)'];
  const balanced = data.diff < 20;

  function teamHtml(team, players, score, color, chem) {
    const chemHtml = chem.length
      ? chem.map(c => `
          <div style="font-size:0.68rem;font-family:'Share Tech Mono',monospace;color:var(--text-muted);margin-top:0.2rem">
            ${c.p1} + ${c.p2}:
            <span style="color:${c.pct >= 60 ? 'var(--neon)' : c.pct >= 40 ? 'var(--neon3)' : 'var(--neon4)'}">
              ${c.pct}% win
            </span>
          </div>`).join('')
      : '';

    return `
      <div style="border:1px solid ${color}44;border-radius:6px;overflow:hidden;flex:1">
        <div style="background:${color}12;padding:0.5rem 0.8rem;display:flex;justify-content:space-between;align-items:center">
          <span style="font-family:'Black Han Sans',sans-serif;font-size:0.8rem;color:${color};letter-spacing:0.1em">${team}</span>
          <span style="font-family:'Share Tech Mono',monospace;font-size:0.75rem;color:${color}">${score}</span>
        </div>
        <div style="padding:0.5rem 0.8rem">
          ${players.map(p => `
            <div style="font-size:0.85rem;font-weight:600;margin-bottom:0.2rem">
              ${p.emoji||'🎳'} ${p.name}
              <span style="font-size:0.65rem;color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-weight:normal">
                ${p.media_storica > 0 ? p.media_storica : '—'}
                ${p.media_recente ? `· forma ${p.media_recente}` : ''}
              </span>
            </div>`).join('')}
          ${chemHtml}
        </div>
      </div>`;
  }

  document.getElementById('suggestResult').innerHTML = `
    <div style="background:${balanced ? 'rgba(232,255,0,0.05)' : 'rgba(255,107,53,0.05)'};border:1px solid ${balanced ? 'rgba(232,255,0,0.2)' : 'rgba(255,107,53,0.2)'};border-radius:6px;padding:0.5rem 0.8rem;margin-bottom:0.8rem;font-family:'Share Tech Mono',monospace;font-size:0.68rem;color:${balanced ? 'var(--neon)' : 'var(--neon4)'}">
      ${balanced ? '✅ Squadre equilibrate' : '⚠ Leggero squilibrio'} — differenza media: ${data.diff}
    </div>
    <div style="display:flex;gap:0.6rem">
      ${teamHtml('⚡ Squadra A', data.teamA, data.scoreA, tColors[0], data.teamA_chemistry)}
      ${teamHtml('🔥 Squadra B', data.teamB, data.scoreB, tColors[1], data.teamB_chemistry)}
    </div>
    <button onclick="useSuggestedTeams()" class="btn-primary" style="width:100%;margin-top:0.8rem;font-size:0.78rem;padding:0.5rem">
      ✓ Usa queste squadre per la nuova partita
    </button>`;
}

function useSuggestedTeams() {
  // Apre il modal nuova partita con le squadre già preimpostate
  const result = document.getElementById('suggestResult');
  if (!result) return;

  openModal().then(() => {
    document.getElementById('teamARows').innerHTML = '';
    document.getElementById('teamBRows').innerHTML = '';
    const resultData = window._lastSuggestData;
    if (resultData) {
      resultData.teamA.forEach(p => addPlayerRow('A', p.id));
      resultData.teamB.forEach(p => addPlayerRow('B', p.id));
    } else {
      addPlayerRow('A'); addPlayerRow('A'); addPlayerRow('A');
      addPlayerRow('B'); addPlayerRow('B'); addPlayerRow('B');
    }
  });
}

// ── SALVA FOTO CLASSIFICA (dinamico) ────────────
function saveFotoClassifica() {
  if (window.leaderboardMode === 'last') {
    saveClassificaUltimaSerata();
  } else {
    saveClassifica();
  }
}

// ── INIT ─────────────────────────────────────

document.addEventListener('DOMContentLoaded', () => {
  loadStats();
  loadLeaderboard();
  loadSessions();
  loadHof();
});