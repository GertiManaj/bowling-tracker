// ============================================
//  app.js — Strike Zone Bowling Tracker
//  Tutta la logica JS separata dall'HTML
// ============================================

const API = '/api'; // percorso assoluto

// ── GROUP FILTER (super_admin) ───────────────
let currentGroupId = null;
let _groupSelectorReady = false; // evita doppia inizializzazione

async function initGroupSelector() {
  // Già inizializzato (es. checkAuth async chiama applyAuthUI dopo DOMContentLoaded)
  if (_groupSelectorReady) return;

  const bar = document.getElementById('groupSelectorBar');
  if (!bar) return;
  if (typeof isSuperAdmin !== 'function' || !isSuperAdmin()) return;

  bar.style.display = 'flex';
  _groupSelectorReady = true; // blocca invocazioni successive

  try {
    const res = await authFetch(`${API}/groups.php`);
    const data = await res.json();
    const groups = data.groups || [];
    const sel = document.getElementById('groupSelector');

    sel.innerHTML = '<option value="all">Tutti i gruppi</option>';
    groups.forEach(function (g) {
      const opt = document.createElement('option');
      opt.value = g.id;
      opt.textContent = g.name;
      sel.appendChild(opt);
    });

    // DEFAULT: SEMPRE primo gruppo (Strike Zone Original)
    if (groups.length > 0) {
      currentGroupId = groups[0].id;
      sel.value = groups[0].id;
      localStorage.setItem('sz_selected_group', groups[0].id);
    }
  } catch (e) { }
}

function onGroupChange(value) {
  currentGroupId = parseInt(value);
  localStorage.setItem('sz_selected_group', value);

  console.log('[onGroupChange] Nuovo gruppo:', currentGroupId);

  // Ricarica TUTTI i dati
  loadStats();
  loadLeaderboard();
  loadSessions();
  loadHof();
}

function groupParam() {
  if (currentGroupId && currentGroupId !== null) {
    return `?group_id=${currentGroupId}`;
  }
  return '';
}

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
    const data = await authFetch(`${API}/stats.php${groupParam()}`).then(r => r.json());

    document.getElementById('stat-sessioni').textContent = data.totale_sessioni ?? '—';
    document.getElementById('stat-sessioni-sub').textContent = "dall'inizio";
    document.getElementById('stat-record').textContent = data.record_assoluto ?? '—';
    document.getElementById('stat-media').textContent = data.media_gruppo ?? '—';

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
let cachedPlayers = [];
let cachedSessions = [];

function setLeaderboardMode(mode, btn) {
  leaderboardMode = mode;
  window.leaderboardMode = mode;
  // Reset tutti i toggle
  document.querySelectorAll('.lb-toggle-btn').forEach(b => {
    b.classList.remove('active');
    b.style.background = 'none';
    b.style.borderColor = 'var(--border)';
    b.style.color = 'var(--text-muted)';
  });
  // Attiva quello cliccato
  btn.classList.add('active');
  btn.style.background = 'rgba(232,255,0,0.08)';
  btn.style.borderColor = 'rgba(232,255,0,0.4)';
  btn.style.color = 'var(--neon)';
  renderLeaderboard();

  // Aggiorna testo bottone salva foto
  const saveBtn = document.getElementById('btnSaveClassifica');
  if (saveBtn) saveBtn.textContent = mode === 'last' ? '📸 Salva ultima serata' : '📸 Salva foto';
}

async function loadLeaderboard() {
  try {
    cachedPlayers = await authFetch(`${API}/leaderboard.php${groupParam()}`).then(r => r.json());
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
let lbSortDir = 'desc';

function sortLeaderboard(field) {
  if (lbSortField === field) {
    lbSortDir = lbSortDir === 'desc' ? 'asc' : 'desc';
  } else {
    lbSortField = field;
    lbSortDir = 'desc';
  }
  renderAllTimeLeaderboard();
}

function getLbValue(p, field) {
  if (field === 'media') return parseFloat(p.media) || 0;
  if (field === 'record') return parseInt(p.record) || 0;
  if (field === 'partite') return parseInt(p.partite) || 0;
  if (field === 'win_pct') {
    const scs = parseInt(p.serate_con_squadra) || 0;
    const vitt = parseInt(p.vittorie_squadra) || 0;
    return scs > 0 ? Math.round(vitt / scs * 100) : 0;
  }
  if (field === 'top_scorer') return parseInt(p.volte_top_scorer) || 0;
  if (field === 'forma') return (p.ultimi_risultati || []).filter(r => r === 'V').length;
  if (field === 'saldo') return p.saldo_pagamenti != null ? parseFloat(p.saldo_pagamenti) : -1;
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

  const cols = window.isGuestMode
    ? '48px 1fr 90px 80px 80px 80px 70px 80px'    // senza saldo
    : '48px 1fr 90px 80px 80px 80px 70px 80px 72px';

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
    <div ${hStyle('partite')} class="col-partite">Sfide ${arrow('partite')}</div>
    <div ${hStyle('win_pct')}>% Vitt ${arrow('win_pct')}</div>
    <div ${hStyle('top_scorer')}>Top 🏆 ${arrow('top_scorer')}</div>
    <div ${hStyle('forma')}>Forma ${arrow('forma')}</div>
    ${window.isGuestMode ? '' : `<div ${hStyle('saldo')}>💶 ${arrow('saldo')}</div>`}`;
  document.getElementById('lb-header').style.gridTemplateColumns = cols;

  const maxM = sorted.length ? Math.max(...sorted.map(p => parseFloat(p.media) || 0)) : 1;
  const medals = ['🥇', '🥈', '🥉'];
  const mColors = ['var(--gold)', 'var(--silver)', 'var(--bronze)'];
  const bColors = ['var(--neon)', 'var(--neon3)', 'var(--neon4)', 'var(--neon2)'];

  let html = '';
  sorted.forEach((p, i) => {
    const bc = bColors[i % bColors.length];
    const pct = maxM > 0 ? Math.round(parseFloat(p.media) / maxM * 100) : 0;
    const delay = (i * 0.07).toFixed(2);
    const rankEl = i < 3
      ? `<div class="rank" style="color:${mColors[i]};text-shadow:0 0 10px ${mColors[i]}88">${medals[i]}</div>`
      : `<div class="rank-other">${i + 1}</div>`;

    const nc = i === 0 ? 'var(--gold)' : i === 1 ? 'var(--silver)' : i === 2 ? 'var(--bronze)' : 'var(--text)';

    // Calcola valori colonne
    const serate = parseInt(p.partite) || 0;
    const sfide = parseInt(p.sfide) || 0;
    const vittorie = parseInt(p.vittorie_squadra) || 0;
    const pareggi = parseInt(p.pareggi_squadra) || 0;
    const serateConSquadra = parseInt(p.serate_con_squadra) || 0;
    const sconfitte = serateConSquadra - vittorie - pareggi;
    const totaleRisultati = vittorie + pareggi + sconfitte;
    const hasSfide = totaleRisultati > 0;
    const winPct = hasSfide ? parseFloat((vittorie / totaleRisultati * 100).toFixed(1)) : null;
    const topScore = parseInt(p.volte_top_scorer) || 0;
    // forma calcolata tramite ultimi_risultati
    const mediaVal = parseFloat(p.media) || 0;

    // Badge forma — ultimi 5 risultati come pallini V/P/N
    const risultati = p.ultimi_risultati || [];
    let formaBadge = '';
    if (!risultati.length) {
      const emptyOnly2 = '<span style="display:inline-flex;align-items:center;justify-content:center;width:18px;height:18px;border-radius:50%;background:#2a2a44;border:1px solid #3a3a5a"></span>';
      formaBadge = `<div style="display:flex;gap:2px;justify-content:center">${Array(5).fill(emptyOnly2).join('')}</div>`;
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

    const isSelected = suggestSelected.has(p.id);
    const isMyRow = !!(window.isPlayerLoggedIn && window.currentPlayerId && p.id === window.currentPlayerId);
    const rowSelStyle = isSelected
      ? 'background:rgba(232,255,0,0.07);border-left:2px solid rgba(232,255,0,0.5);'
      : isMyRow
        ? 'background:rgba(0,229,255,0.05);border-left:3px solid var(--neon2);box-shadow:inset 0 0 24px rgba(0,229,255,0.03);'
        : 'border-left:2px solid transparent;';

    html += `
      <div class="leaderboard-row" data-player-id="${p.id}"
           onclick="toggleSuggestPlayer(${p.id}, this)"
           title="Clicca per selezionare nella Suggeritore Squadre"
           style="animation-delay:${delay}s;grid-template-columns:${cols};cursor:pointer;${rowSelStyle}transition:background 0.15s,border-color 0.15s">
        ${rankEl}
        <div class="player-info">
          <div class="avatar" style="background:${bc}18;border-color:${isMyRow ? 'var(--neon2)' : bc + '44'}">${p.emoji || '🎳'}</div>
          <div>
            <div class="player-name">${escHtml(p.name)}${isMyRow ? ' <span style="font-size:0.52rem;color:var(--neon2);letter-spacing:0.12em;font-family:\'Share Tech Mono\',monospace;vertical-align:middle;opacity:0.9">● TU</span>' : ''}</div>
            <div class="player-tag">${p.partite} serate · ${p.game_totali || 0} game</div>
          </div>
        </div>
        <div class="stat-cell" style="${cellStyle('media') || 'color:' + nc}">
          <strong>${p.media ?? '—'}</strong>
          <div class="mini-bar-bg">
            <div class="mini-bar-fill" style="width:0%;background:${bc};box-shadow:0 0 6px ${bc}" data-w="${pct}%"></div>
          </div>
        </div>
        <div class="stat-cell best" style="${cellStyle('record')}">${p.record ?? '—'}</div>
        <div class="stat-cell col-partite" style="${cellStyle('partite')}">${sfide > 0 ? sfide : '—'}</div>
        <div class="stat-cell" style="${cellStyle('win_pct')}">${winPct !== null ? winPct.toFixed(1) + '%' : '—'}</div>
        <div class="stat-cell" style="${cellStyle('top_scorer')}">${topScore || '—'}</div>
        <div class="stat-cell" style="${cellStyle('forma')}">${formaBadge}</div>
        ${window.isGuestMode ? '' : `
        <div class="stat-cell" style="${cellStyle('saldo') || (p.saldo_pagamenti === 0 ? 'color:var(--neon)' : p.saldo_pagamenti > 0 ? 'color:var(--neon2)' : '')}">
          ${p.saldo_pagamenti != null ? '€' + p.saldo_pagamenti.toFixed(2) : '—'}
        </div>`}
      </div>`;
  });

  // Giocatori senza partite in fondo
  noGames.forEach(p => {
    html += `
      <div class="leaderboard-row" style="grid-template-columns:${cols};opacity:0.4">
        <div class="rank-other">—</div>
        <div class="player-info">
          <div class="avatar" style="border-color:var(--border)">${p.emoji || '🎳'}</div>
          <div>
            <div class="player-name">${escHtml(p.name)}</div>
            <div class="player-tag">0 serate · 0 game</div>
          </div>
        </div>
        <div class="stat-cell">—</div>
        <div class="stat-cell">—</div>
        <div class="stat-cell col-partite">0</div>
        <div class="stat-cell">—</div>
        <div class="stat-cell">—</div>
        <div class="stat-cell">—</div>
        ${window.isGuestMode ? '' : '<div class="stat-cell">—</div>'}
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
  const sessions = cachedSessions;
  if (!sessions.length) {
    document.getElementById('leaderboard-body').innerHTML =
      '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessuna sessione</div>';
    return;
  }

  const lastSession = sessions[0];
  const scores = lastSession.scores || [];
  const teams = lastSession.teams || [];
  const isFFA = lastSession.mode === 'ffa';
  const numGames = scores.length ? Math.max(...scores.map(s => s.game_number || 1)) : 1;

  // Raggruppa per giocatore
  const byPlayer = {};
  scores.forEach(sc => {
    const pname = sc.player_name;
    if (!byPlayer[pname]) byPlayer[pname] = {
      name: pname, emoji: sc.emoji, team: sc.team_name, total: 0, games: 0, isFFA: sc.team_name === '__FFA__', isSolo: !sc.team_name
    };
    byPlayer[pname].total += parseInt(sc.score) || 0;
    byPlayer[pname].games++;
  });

  const sorted = Object.values(byPlayer).sort((a, b) => b.total - a.total);
  const medals = ['🥇', '🥈', '🥉'];
  const bColors = ['var(--neon)', 'var(--neon3)', 'var(--neon4)', 'var(--neon2)'];

  let winningTeam = null;
  let maxFFAScore = 0;
  let ffaWinnersN = 0;

  if (isFFA) {
    // FFA: vincitore = giocatore FFA con punteggio più alto
    const ffaPlayers = sorted.filter(p => p.isFFA);
    maxFFAScore = ffaPlayers.length ? ffaPlayers[0].total : 0;
    ffaWinnersN = ffaPlayers.filter(p => p.total === maxFFAScore).length;
  } else {
    // Teams: trova team vincitore
    const teamTotals = {};
    teams.filter(t => t.name !== '__FFA__').forEach(t => { teamTotals[t.name] = 0; });
    scores.forEach(sc => {
      if (sc.team_name && sc.team_name !== '__FFA__' && teamTotals[sc.team_name] !== undefined)
        teamTotals[sc.team_name] += parseInt(sc.score) || 0;
    });
    if (Object.keys(teamTotals).length) {
      const maxT = Math.max(...Object.values(teamTotals));
      const winners = Object.keys(teamTotals).filter(t => teamTotals[t] === maxT);
      winningTeam = winners.length === 1 ? winners[0] : null; // null = pareggio
    }
  }

  // Header
  if (isFFA) {
    document.getElementById('lb-header').innerHTML = `
      <div>#</div><div>Giocatore</div>
      <div style="text-align:center">Totale</div>
      <div style="text-align:center">Media</div>
      <div style="text-align:center" class="col-partite">Tipo</div>
      <div style="text-align:center">Risultato</div>`;
  } else {
    document.getElementById('lb-header').innerHTML = `
      <div>#</div><div>Giocatore</div>
      <div style="text-align:center">Totale</div>
      <div style="text-align:center">Media</div>
      <div style="text-align:center" class="col-partite">Squadra</div>
      <div style="text-align:center">Risultato</div>`;
  }
  document.getElementById('lb-header').style.gridTemplateColumns = '48px 1fr 90px 90px 100px 90px';

  let html = '';
  sorted.forEach((p, i) => {
    const bc = bColors[i % bColors.length];
    const delay = (i * 0.07).toFixed(2);
    const rankEl = i < 3
      ? `<div class="rank" style="color:${['var(--gold)', 'var(--silver)', 'var(--bronze)'][i]}">${medals[i]}</div>`
      : `<div class="rank-other">${i + 1}</div>`;
    const mediaGame = p.games > 0 ? (p.total / p.games).toFixed(1) : '—';

    let teamCell = '', resultCell = '';

    if (p.isSolo) {
      teamCell = `<div class="stat-cell col-partite" style="font-size:0.75rem;color:var(--neon3)">Singolo</div>`;
      resultCell = `<div class="stat-cell"><span style="font-family:'Share Tech Mono',monospace;font-size:0.65rem;color:var(--text-muted)">fuori sfida</span></div>`;
    } else if (p.isFFA) {
      const isWinner = ffaWinnersN === 1 && p.total === maxFFAScore;
      teamCell = `<div class="stat-cell col-partite" style="font-size:0.75rem;color:var(--neon)">🏆 FFA</div>`;
      resultCell = `<div class="stat-cell"><span class="team-tag ${isWinner ? 'win' : 'lose'}" style="font-size:0.65rem">${isWinner ? '1° 🏆' : 'PAGA'}</span></div>`;
    } else {
      const isWin = winningTeam !== null && p.team === winningTeam;
      const isDraw = winningTeam === null && p.team;
      const tag = isWin ? 'WIN' : isDraw ? 'PARI' : 'LOSE';
      const cls = isWin ? 'win' : isDraw ? 'draw' : 'lose';
      teamCell = `<div class="stat-cell col-partite" style="font-size:0.75rem;color:${isWin ? 'var(--neon)' : 'var(--neon2)'}">${p.team || '—'}</div>`;
      resultCell = `<div class="stat-cell"><span class="team-tag ${cls}" style="font-size:0.65rem">${tag}</span></div>`;
    }

    html += `
      <div class="leaderboard-row" style="animation-delay:${delay}s;grid-template-columns:48px 1fr 90px 90px 100px 90px">
        ${rankEl}
        <div class="player-info">
          <div class="avatar" style="background:${bc}18;border-color:${bc}44">${p.emoji || '🎳'}</div>
          <div>
            <div class="player-name">${escHtml(p.name)}</div>
            <div class="player-tag">${numGames} game</div>
          </div>
        </div>
        <div class="stat-cell" style="color:${i === 0 ? 'var(--gold)' : 'var(--text)'}"><strong>${p.total}</strong></div>
        <div class="stat-cell" style="color:var(--neon3)">${mediaGame}</div>
        ${teamCell}
        ${resultCell}
      </div>`;
  });

  document.getElementById('leaderboard-body').innerHTML = html ||
    '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessun dato</div>';
}

// ── SESSIONI RECENTI ─────────────────────────

async function loadSessions() {
  try {
    const sessions = await authFetch(`${API}/sessions.php${groupParam()}`).then(r => r.json());

    // Lista ultime 5
    let html = '';
    sessions.slice(0, 5).forEach(s => {
      const sc = s.scores || [];
      const names = [...new Set(sc.map(x => x.player_name))].join(' · ');

      // Calcola totale per giocatore (somma tutti i game)
      const totals = {};
      sc.forEach(x => {
        totals[x.player_name] = totals[x.player_name] || { name: x.player_name, emoji: x.emoji, total: 0 };
        totals[x.player_name].total += parseInt(x.score) || 0;
      });
      const best = Object.values(totals).reduce((a, b) => b.total > a.total ? b : a, { total: 0 });

      html += `
        <div class="session-item" onclick="window.location.href='sessioni.html'" style="cursor:pointer" onmouseover="this.style.background='rgba(255,255,255,0.03)'" onmouseout="this.style.background=''">
          <div class="session-item-left">
            <div class="session-item-date">${formatDate(s.date)} · ${escHtml(s.location)}</div>
            <div class="session-item-players">${names || '—'}</div>
          </div>
          <div class="session-winner">
            <div class="session-winner-label">Miglior serata</div>
            <div class="session-winner-name">${escHtml(best.name) || '—'}</div>
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
  const teams = s.teams || [];
  const scores = s.scores || [];
  const isFFA = (s.mode === 'ffa');
  const numGames = scores.length > 0 ? Math.max(...scores.map(sc => sc.game_number || 1)) : 1;
  const tColors = ['var(--neon)', 'var(--neon2)', 'var(--neon3)', 'var(--neon4)'];

  const makeGamesHtml = (games) => numGames > 1
    ? games.sort((a, b) => a.game - b.game).map(g =>
      `<span style="font-size:0.68rem;color:var(--text-muted)">G${g.game}:${g.score}</span>`).join(' ')
    : '';

  const makePlayerRow = (p, isTop, topColor) => `
    <div class="team-player-row">
      <span>${p.emoji || '🎳'} ${escHtml(p.name)}</span>
      <div style="display:flex;align-items:center;gap:0.5rem">
        ${makeGamesHtml(p.games) ? `<span style="font-family:'Share Tech Mono',monospace">${makeGamesHtml(p.games)}</span>` : ''}
        <span class="team-player-score" style="${isTop ? 'color:' + topColor : ''}">${p.total}</span>
      </div>
    </div>`;

  let mainHtml = '';

  if (isFFA) {
    // ── FFA ──
    const ffaByPlayer = {};
    scores.forEach(sc => {
      if (sc.team_name !== '__FFA__') return;
      const pname = sc.player_name;
      if (!ffaByPlayer[pname]) ffaByPlayer[pname] = { name: pname, emoji: sc.emoji, total: 0, games: [] };
      ffaByPlayer[pname].total += parseInt(sc.score) || 0;
      ffaByPlayer[pname].games.push({ game: sc.game_number || 1, score: sc.score });
    });
    const ffaList = Object.values(ffaByPlayer).sort((a, b) => b.total - a.total);
    const maxFFA = ffaList.length ? ffaList[0].total : 0;
    const winnersN = ffaList.filter(p => p.total === maxFFA).length;

    const ffaRows = ffaList.map(p => {
      const isWin = winnersN === 1 && p.total === maxFFA;
      return makePlayerRow(
        { ...p, name: p.name + (isWin ? ' 🏆' : '') },
        isWin, 'var(--gold)'
      );
    }).join('');

    mainHtml = `
      <div class="team-block" style="border-color:var(--neon)44">
        <div class="team-header" style="background:rgba(232,255,0,0.05)">
          <span class="team-name-lbl" style="color:var(--neon)">🏆 Tutti contro tutti</span>
          <span style="font-family:'Share Tech Mono',monospace;font-size:0.65rem;color:var(--text-muted)">Il primo non paga</span>
        </div>
        <div class="team-players">${ffaRows}</div>
      </div>`;
  } else {
    // ── Teams normali (escludi __FFA__) ──
    const byTeam = {};
    teams.filter(t => t.name !== '__FFA__').forEach(t => {
      byTeam[t.name] = { name: t.name, total: 0, players: {} };
    });
    scores.forEach(sc => {
      if (!sc.team_name || sc.team_name === '__FFA__' || !byTeam[sc.team_name]) return;
      const team = byTeam[sc.team_name];
      const pname = sc.player_name;
      if (!team.players[pname]) team.players[pname] = { name: pname, emoji: sc.emoji, total: 0, games: [] };
      team.players[pname].total += parseInt(sc.score) || 0;
      team.players[pname].games.push({ game: sc.game_number || 1, score: sc.score });
    });
    Object.values(byTeam).forEach(t => {
      t.total = Object.values(t.players).reduce((s, p) => s + p.total, 0);
    });
    const teamList = Object.values(byTeam);
    const maxT = teamList.length ? Math.max(...teamList.map(t => t.total)) : 0;
    const drawCount = teamList.filter(t => t.total === maxT).length;

    teamList.forEach((t, i) => {
      const win = t.total === maxT && maxT > 0 && drawCount === 1;
      const draw = t.total === maxT && drawCount > 1;
      const c = tColors[i % tColors.length];
      const maxScore = Object.values(t.players).length
        ? Math.max(...Object.values(t.players).map(p => p.total)) : 0;
      const plHtml = Object.values(t.players).map(p =>
        makePlayerRow(p, p.total === maxScore, 'var(--gold)')
      ).join('');
      const tag = win ? 'VITTORIA' : draw ? 'PAREGGIO' : 'SCONFITTA';
      const cls = win ? 'win' : draw ? 'draw' : 'lose';
      mainHtml += `
        <div class="team-block">
          <div class="team-header">
            <span class="team-name-lbl" style="color:${c}">${t.name}</span>
            <div style="display:flex;align-items:center;gap:0.5rem">
              <span class="team-tag ${cls}">${tag}</span>
              <span style="font-family:'Share Tech Mono',monospace;font-size:0.75rem;color:${win ? c : 'var(--text-muted)'}">${t.total}</span>
            </div>
          </div>
          <div class="team-players">${plHtml}</div>
        </div>`;
    });
  }

  // ── Singoli (team_id NULL) ──
  const soloByPlayer = {};
  scores.forEach(sc => {
    if (sc.team_name) return;
    const pname = sc.player_name;
    if (!soloByPlayer[pname]) soloByPlayer[pname] = { name: pname, emoji: sc.emoji, total: 0, games: [] };
    soloByPlayer[pname].total += parseInt(sc.score) || 0;
    soloByPlayer[pname].games.push({ game: sc.game_number || 1, score: sc.score });
  });
  let soloHtml = '';
  const soloList = Object.values(soloByPlayer);
  if (soloList.length) {
    const soloRows = soloList.map(p => makePlayerRow(p, false, '')).join('');
    soloHtml = `
      <div class="team-block" style="border-color:var(--neon3)44">
        <div class="team-header" style="background:rgba(0,245,255,0.05)">
          <span class="team-name-lbl" style="color:var(--neon3)">👤 Singoli</span>
          <span style="font-family:'Share Tech Mono',monospace;font-size:0.65rem;color:var(--text-muted)">Fuori sfida</span>
        </div>
        <div class="team-players">${soloRows.replace(/color:/g, 'color:var(--neon3)')}</div>
      </div>`;
  }

  document.getElementById('last-session-card').innerHTML = `
    <div class="card-header">
      <div class="card-title">${formatDate(s.date)}</div>
      <div class="card-date">${escHtml(s.location)}${numGames > 1 ? ` · ${numGames} game` : ''}</div>
    </div>
    <div class="session-teams">
      ${mainHtml}${soloHtml}
      ${!mainHtml && !soloHtml ? '<div style="padding:1rem;color:var(--text-muted);font-size:0.8rem">Nessun punteggio</div>' : ''}
    </div>`;
}

// ── HALL OF FAME ─────────────────────────────

async function loadHof() {
  try {
    const d = await authFetch(`${API}/stats.php${groupParam()}`).then(r => r.json());

    // Riga uniforme HoF
    const hofRow = (label, icon, nameStr, sub, valStr, valColor) => `
      <div class="hof-row">
        <div style="display:flex;align-items:center;gap:0.8rem">
          <div style="font-size:1.6rem;width:2rem;text-align:center">${icon}</div>
          <div>
            <div class="hof-label">${label}</div>
            <div style="font-family:'Barlow Condensed',sans-serif;font-weight:700;font-size:1rem;color:var(--text);margin:0.2rem 0">${nameStr}</div>
            <div class="hof-sub">${sub}</div>
          </div>
        </div>
        <div style="font-family:'Black Han Sans',sans-serif;font-size:1.4rem;color:${valColor};text-shadow:0 0 12px ${valColor}66;text-align:right;min-width:2.5rem">${valStr}</div>
      </div>`;

    document.getElementById('hof-card').innerHTML =
      hofRow(
        'Record singolo game',
        '🏆',
        d.record_holder ? d.record_holder.emoji + ' ' + d.record_holder.name : '—',
        d.record_holder ? formatDate(d.record_holder.date) : '—',
        d.record_assoluto ?? '—',
        'var(--gold)'
      ) +
      hofRow(
        'Più vittorie squadra',
        '🥇',
        d.most_wins ? d.most_wins.emoji + ' ' + d.most_wins.name : '—',
        d.most_wins ? d.most_wins.vittorie + ' sfide vinte' : 'nessun dato',
        d.most_wins?.vittorie ?? '—',
        'var(--neon)'
      ) +
      hofRow(
        'Più migliorato',
        '📈',
        d.most_improved ? d.most_improved.emoji + ' ' + d.most_improved.name : '—',
        d.most_improved ? '+' + d.most_improved.miglioramento + ' pts di media' : 'min. 2 serate',
        d.most_improved ? '+' + d.most_improved.miglioramento : '—',
        'var(--neon2)'
      );
  } catch (e) {
    console.error('Errore HoF:', e);
  }
}

// ── MODAL: NUOVA PARTITA ─────────────────────

let allPlayers = [];

async function openModal() {
  if (!window.isLoggedIn) { openLoginModal(); return; }
  try {
    allPlayers = await authFetch(`${API}/players.php${groupParam()}`).then(r => r.json());
  } catch (e) {
    allPlayers = [];
  }

  // Reset campi
  document.getElementById('sessionDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('sessionLocation').value = '';
  document.getElementById('sessionNotes').value = '';
  document.getElementById('teamAName').value = '';
  document.getElementById('teamBName').value = '';
  document.getElementById('numGames').value = '2';
  document.getElementById('totalA').textContent = 'Totale: 0';
  document.getElementById('totalB').textContent = 'Totale: 0';
  document.getElementById('soloRows').innerHTML = '';

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
  const ng = numGames || parseInt(document.getElementById('numGames')?.value) || 1;
  const opts = allPlayers.map(p =>
    `<option value="${p.id}" ${parseInt(p.id) === parseInt(selectedId) ? 'selected' : ''}>${p.emoji || '🎳'} ${escHtml(p.name)}</option>`
  ).join('');

  // Crea input score per ogni game
  const gameInputs = Array.from({ length: ng }, (_, i) =>
    `<input type="number" class="form-input score-input" placeholder="G${i + 1}" min="0" max="300" data-game="${i + 1}" oninput="calcTotals();validateScoreInput(this)"/>`
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
  const ng = numGames || parseInt(document.getElementById('numGames')?.value) || 1;
  const opts = allPlayers.map(p =>
    `<option value="${p.id}" ${parseInt(p.id) === parseInt(selectedId) ? 'selected' : ''}>${p.emoji || '🎳'} ${p.name}</option>`
  ).join('');
  const gameInputs = Array.from({ length: ng }, (_, i) =>
    `<input type="number" class="form-input score-input" placeholder="G${i + 1}" min="0" max="300" data-game="${i + 1}"/>`
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
      const score = input.value;
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
    const name = document.getElementById(`team${t}Name`).value || `Squadra ${t}`;
    const players = [];
    document.querySelectorAll(`#team${t}Rows .player-row`).forEach(row => {
      const pid = row.querySelector('select')?.value;
      if (!pid) return;
      // Un record per ogni game
      row.querySelectorAll('.score-input').forEach(input => {
        const gameNum = parseInt(input.dataset.game);
        const score = input.value;
        if (score) players.push({ player_id: parseInt(pid), score: parseInt(score), game_number: gameNum });
      });
    });
    if (players.length > 0) teams.push({ name, players });
  });

  const soloPlayers = getSoloPlayers();

  // ── VALIDAZIONE 1: punteggi fuori range 0-300 ──
  const allScoreInputs = document.querySelectorAll('#teamARows .score-input, #teamBRows .score-input, #soloRows input[type="number"]');
  for (const input of allScoreInputs) {
    if (!input.value) continue;
    const val = parseInt(input.value);
    if (isNaN(val) || val < 0 || val > 300) {
      showToast('Il punteggio deve essere tra 0 e 300', 'error');
      btn.disabled = false; btn.textContent = 'Salva Partita';
      input.focus();
      input.style.borderColor = 'var(--neon2)';
      setTimeout(() => input.style.borderColor = '', 2000);
      return;
    }
  }

  // ── VALIDAZIONE 2: stesso giocatore in entrambe le squadre ──
  const playersA = new Set();
  const playersB = new Set();
  const playerNames = {};
  document.querySelectorAll('#teamARows .player-row select').forEach(sel => {
    if (sel.value) { playersA.add(sel.value); playerNames[sel.value] = sel.options[sel.selectedIndex]?.text || sel.value; }
  });
  document.querySelectorAll('#teamBRows .player-row select').forEach(sel => {
    if (sel.value) { playersB.add(sel.value); playerNames[sel.value] = sel.options[sel.selectedIndex]?.text || sel.value; }
  });
  const duplicates = [...playersA].filter(id => playersB.has(id));
  if (duplicates.length > 0) {
    const names = duplicates.map(id => playerNames[id]).join(', ');
    const ok = confirm(`⚠ Attenzione: ${names} è presente in entrambe le squadre.\n\nVuoi salvare comunque?`);
    if (!ok) { btn.disabled = false; btn.textContent = 'Salva Partita'; return; }
  }

  if (!teams.length && !soloPlayers.length) {
    showToast('Inserisci almeno un punteggio', 'error');
    btn.disabled = false; btn.textContent = 'Salva Partita';
    return;
  }

  try {
    const res = await authFetch(`${API}/sessions.php`, {
      method: 'POST',
      body: JSON.stringify({
        date,
        location: document.getElementById('sessionLocation').value || 'Bowling',
        notes: document.getElementById('sessionNotes').value,
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

  const sessions = cachedSessions || [];
  const sessionDates = {};
  sessions.forEach(s => { sessionDates[s.date] = s; });

  const year = calendarDate.getFullYear();
  const month = calendarDate.getMonth();
  const monthName = new Date(year, month, 1).toLocaleDateString('it-IT', { month: 'long', year: 'numeric' });
  const firstDay = new Date(year, month, 1).getDay();
  const daysInMonth = new Date(year, month + 1, 0).getDate();
  const today = new Date().toISOString().split('T')[0];
  const startOffset = firstDay === 0 ? 6 : firstDay - 1;
  const dayHeaders = ['L', 'M', 'M', 'G', 'V', 'S', 'D'];

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
    const pad = String(d).padStart(2, '0');
    const mPad = String(month + 1).padStart(2, '0');
    const dateStr = `${year}-${mPad}-${pad}`;
    const hasSess = sessionDates[dateStr];
    const isToday = dateStr === today;

    let cls = 'calendar-day';
    if (hasSess) cls += ' has-session';
    if (isToday) cls += ' today';

    const onclick = hasSess ? `onclick="scrollToSession('${dateStr}')"` : '';
    const title = hasSess ? `title="${hasSess.location}"` : '';

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
let suggestLivelli = {}; // { player_id: score_stimato } per nuovi giocatori

function clearSuggestSelection() {
  suggestSelected.clear();
  suggestLivelli = {};
  document.querySelectorAll('.leaderboard-row[data-player-id]').forEach(row => {
    row.style.background = '';
    row.style.borderLeft = '2px solid transparent';
  });
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
    const nPartite = parseInt(p.partite) || 0;
    const isNew = nPartite === 0;
    const badge = nPartite > 0
      ? `<span style="font-size:0.6rem;opacity:0.6">(${nPartite})</span>`
      : `<span style="font-size:0.6rem;background:var(--neon3);color:#000;border-radius:3px;padding:0 3px">🆕</span>`;

    return `
      <div style="display:flex;flex-direction:column;gap:0.2rem">
        <button onclick="toggleSuggestPlayer(${p.id}, this)" style="
          font-family:'Barlow Condensed',sans-serif;font-size:0.8rem;font-weight:600;
          letter-spacing:0.05em;
          background:${isSelected ? 'rgba(232,255,0,0.12)' : 'none'};
          border:1px solid ${isSelected ? 'rgba(232,255,0,0.4)' : 'var(--border)'};
          color:${isSelected ? 'var(--neon)' : 'var(--text-muted)'};
          padding:0.3rem 0.6rem;border-radius:20px;cursor:pointer;
          transition:all 0.15s;display:flex;align-items:center;gap:0.3rem
        ">${p.emoji || '🎳'} ${escHtml(p.name)} ${badge}</button>
        ${isNew && isSelected ? `
          <select onchange="setLivello(${p.id}, this.value)" style="
            font-family:'Share Tech Mono',monospace;font-size:0.65rem;
            background:var(--surface2);border:1px solid var(--neon3);
            color:var(--neon3);border-radius:4px;padding:0.2rem 0.4rem;
            cursor:pointer;letter-spacing:0.05em;
          ">
            <option value="">— Livello —</option>
            <option value="80"  ${suggestLivelli[p.id] == 80 ? 'selected' : ''}>🟢 Principiante (~80)</option>
            <option value="130" ${suggestLivelli[p.id] == 130 ? 'selected' : ''}>🟡 Medio (~130)</option>
            <option value="180" ${suggestLivelli[p.id] == 180 ? 'selected' : ''}>🔴 Esperto (~180)</option>
          </select>` : ''}
      </div>`;
  }).join('');
}

function setLivello(id, val) {
  if (val) suggestLivelli[id] = parseInt(val);
  else delete suggestLivelli[id];
}


function toggleSuggestPlayer(id, btn) {
  if (suggestSelected.has(id)) {
    suggestSelected.delete(id);
    delete suggestLivelli[id];
  } else {
    suggestSelected.add(id);
  }
  // Aggiorna visivamente tutte le righe classifica con questo giocatore
  const sel = suggestSelected.has(id);
  document.querySelectorAll(`.leaderboard-row[data-player-id="${id}"]`).forEach(row => {
    row.style.background = sel ? 'rgba(232,255,0,0.07)' : '';
    row.style.borderLeft = sel ? '2px solid rgba(232,255,0,0.5)' : '2px solid transparent';
  });
  buildSuggestPlayers();
  const btn2 = document.getElementById('btnSuggest');
  if (btn2) btn2.textContent = suggestSelected.size >= 2
    ? `🎯 Suggerisci squadre (${suggestSelected.size} giocatori)`
    : '🎯 Suggerisci squadre';
}


function suggestTeams() {
  if (suggestSelected.size < 2) {
    showToast('Seleziona almeno 2 giocatori', 'error');
    return;
  }

  // Costruisci lista giocatori selezionati con score effettivo
  const players = cachedPlayers
    .filter(p => suggestSelected.has(p.id))
    .map(p => ({
      id: p.id,
      name: p.name,
      emoji: p.emoji || '🎳',
      media_storica: parseFloat(p.media) || 0,
      livello_manuale: suggestLivelli[p.id] || null,
      _score: suggestLivelli[p.id] || parseFloat(p.media) || 0
    }));

  // Ordina per score decrescente (più forte → più debole)
  players.sort((a, b) => b._score - a._score);

  function makeProposal(teamA, teamB, method) {
    const avgA = teamA.length ? teamA.reduce((s, p) => s + p._score, 0) / teamA.length : 0;
    const avgB = teamB.length ? teamB.reduce((s, p) => s + p._score, 0) / teamB.length : 0;
    return {
      teamA, teamB, method,
      scoreA: avgA.toFixed(1),
      scoreB: avgB.toFixed(1),
      diff: Math.abs(avgA - avgB).toFixed(1)
    };
  }

  function snakeDraft(sorted) {
    const teamA = [], teamB = [];
    for (let i = 0; i < sorted.length; i++) {
      const pair = Math.floor(i / 2);
      const posInPair = i % 2;
      const goToA = (pair % 2 === 0) ? (posInPair === 0) : (posInPair === 1);
      if (goToA) teamA.push(sorted[i]); else teamB.push(sorted[i]);
    }
    return { teamA, teamB };
  }

  function greedyBalance(sorted) {
    const teamA = [], teamB = [];
    let sumA = 0, sumB = 0;
    for (const p of sorted) {
      if (sumA <= sumB) { teamA.push(p); sumA += p._score; }
      else { teamB.push(p); sumB += p._score; }
    }
    return { teamA, teamB };
  }

  // ── PROPOSTA 1: CLUSTER TRIPLO ─────────────────────────────────
  function clusterTriple(sorted) {
    const n = sorted.length;
    const third = Math.ceil(n / 3);
    const top = sorted.slice(0, third);
    const mid = sorted.slice(third, third * 2);
    const low = sorted.slice(third * 2);
    const teamA = [], teamB = [];
    top.forEach((p, i) => (i % 2 === 0 ? teamA : teamB).push(p));
    mid.forEach((p, i) => (i % 2 === 0 ? teamB : teamA).push(p));
    low.forEach((p, i) => (i % 2 === 0 ? teamA : teamB).push(p));
    return { teamA, teamB };
  }

  const { teamA: a1, teamB: b1 } = clusterTriple(players);
  const proposal1 = makeProposal(a1, b1, 'CLUSTER TRIPLO');

  // ── PROPOSTA 2: MINI-MAX OTTIMIZZATO ───────────────────────────
  function miniMaxOptimal(sorted) {
    const n = sorted.length;
    if (n > 10) return { ...greedyBalance(sorted), _method: 'GREEDY' };

    const half = Math.floor(n / 2);
    let bestDiff = Infinity, bestA = [], bestB = [];

    function combinations(arr, k) {
      if (k === 0) return [[]];
      if (arr.length < k) return [];
      const [first, ...rest] = arr;
      return [
        ...combinations(rest, k - 1).map(c => [first, ...c]),
        ...combinations(rest, k)
      ];
    }

    for (const teamA of combinations(sorted, half)) {
      const teamB = sorted.filter(p => !teamA.includes(p));
      const avgA = teamA.reduce((s, p) => s + p._score, 0) / teamA.length;
      const avgB = teamB.reduce((s, p) => s + p._score, 0) / teamB.length;
      const diff = Math.abs(avgA - avgB);
      if (diff < bestDiff) { bestDiff = diff; bestA = teamA; bestB = teamB; }
    }

    return { teamA: bestA, teamB: bestB, _method: 'MINI-MAX' };
  }

  const mm = miniMaxOptimal(players);
  const proposal2 = makeProposal(mm.teamA, mm.teamB, mm._method || 'MINI-MAX');

  // ── PROPOSTA 3: FORMA RECENTE (snake su score pesato) ──────────
  const playersRecent = players.map(p => {
    const cached = cachedPlayers.find(cp => cp.id === p.id);
    const risultati = cached?.ultimi_risultati || [];
    let formaMod = 0;
    risultati.slice(-3).forEach(r => {
      if (r === 'V') formaMod += 0.05;
      else if (r === 'P') formaMod -= 0.05;
    });
    return { ...p, _score: p._score * (1 + formaMod) };
  });
  playersRecent.sort((a, b) => b._score - a._score);

  const { teamA: a3, teamB: b3 } = snakeDraft(playersRecent);
  const proposal3 = makeProposal(a3, b3, 'FORMA RECENTE');

  window._lastSuggestData = proposal1;
  window._lastSuggestData2 = proposal2;
  window._lastSuggestData3 = proposal3;
  renderSuggestResult(proposal1, proposal2, proposal3);
}

function renderSuggestResult(data1, data2, data3) {
  const dot = r => {
    if (r === 'V') return '<span style="display:inline-flex;align-items:center;justify-content:center;width:11px;height:11px;border-radius:50%;background:#22c55e;color:#000;font-size:0.38rem;font-weight:700">V</span>';
    if (r === 'P') return '<span style="display:inline-flex;align-items:center;justify-content:center;width:11px;height:11px;border-radius:50%;background:#ef4444;color:#fff;font-size:0.38rem;font-weight:700">P</span>';
    return '<span style="display:inline-flex;align-items:center;justify-content:center;width:11px;height:11px;border-radius:50%;background:#555570;color:#fff;font-size:0.38rem;font-weight:700">N</span>';
  };
  const emptyDot = '<span style="display:inline-flex;width:11px;height:11px;border-radius:50%;background:#2a2a44;border:1px solid #3a3a5a"></span>';

  function playerLine(p) {
    const cached = (window.cachedPlayers || []).find(cp => cp.id === p.id);
    const risultati = cached?.ultimi_risultati || [];
    const dots = Array(Math.max(0, 5 - risultati.length)).fill(emptyDot).join('') + risultati.map(dot).join('');
    const media = p.livello_manuale ? `~${p.livello_manuale}` : (p.media_storica > 0 ? p.media_storica : '—');
    return `<div style="display:flex;align-items:center;justify-content:space-between;padding:0.2rem 0;border-bottom:1px solid var(--border)22;gap:0.3rem;min-width:0">
      <div style="font-size:0.8rem;font-family:'Barlow Condensed',sans-serif;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;min-width:0">${p.emoji || '🎳'} ${escHtml(p.name)}</div>
      <div style="display:flex;align-items:center;gap:0.3rem;flex-shrink:0">
        <div style="display:flex;gap:1px">${dots}</div>
      </div>
    </div>`;
  }

  function proposalHtml(data, idx, label) {
    return `
    <div class="suggest-proposal" id="proposal-${idx}" style="border:2px solid ${idx === 1 ? 'var(--neon)' : 'var(--border)'};border-radius:8px;cursor:pointer;transition:border-color 0.2s" onclick="selectProposal(${idx})">
      <div style="background:var(--surface2);padding:0.35rem 0.6rem;display:flex;justify-content:space-between;align-items:center;border-bottom:1px solid var(--border)">
        <div style="display:flex;flex-direction:column;gap:0.1rem">
          <span style="font-family:'Share Tech Mono',monospace;font-size:0.6rem;color:var(--text-muted);letter-spacing:0.1em">${label}</span>
          ${data.method ? `<span style="font-family:'Share Tech Mono',monospace;font-size:0.55rem;color:var(--neon3);letter-spacing:0.08em">METODO: ${data.method}</span>` : ''}
        </div>
        <span style="font-family:'Share Tech Mono',monospace;font-size:0.58rem;color:${data.diff < 20 ? 'var(--neon)' : 'var(--neon4)'}">Δ ${data.diff}</span>
      </div>
      <div style="display:grid;grid-template-columns:1fr 1fr;gap:0">
        <div style="padding:0.35rem 0.5rem;border-right:1px solid var(--border)">
          <div style="font-family:'Black Han Sans',sans-serif;font-size:0.68rem;color:var(--neon);margin-bottom:0.2rem">⚡ A <span style="font-size:0.58rem;color:var(--text-muted)">${data.scoreA}</span></div>
          ${data.teamA.map(playerLine).join('')}
        </div>
        <div style="padding:0.35rem 0.5rem">
          <div style="font-family:'Black Han Sans',sans-serif;font-size:0.68rem;color:var(--neon2);margin-bottom:0.2rem">🔥 B <span style="font-size:0.58rem;color:var(--text-muted)">${data.scoreB}</span></div>
          ${data.teamB.map(playerLine).join('')}
        </div>
      </div>
    </div>`;
  }

  window._selectedProposal = 1;

  document.getElementById('suggestResult').innerHTML = `
    <div style="display:flex;flex-direction:column;gap:0.4rem;margin-top:0.4rem">
      ${proposalHtml(data1, 1, 'PROPOSTA 1')}
      ${proposalHtml(data2, 2, 'PROPOSTA 2')}
      ${data3 ? proposalHtml(data3, 3, 'PROPOSTA 3') : ''}
    </div>
    <button onclick="useSuggestedTeams()" class="btn-primary" style="width:100%;margin-top:0.5rem;font-size:0.78rem;padding:0.45rem">
      ✓ Usa la proposta selezionata
    </button>`;
}

function selectProposal(idx) {
  window._selectedProposal = idx;
  document.querySelectorAll('.suggest-proposal').forEach((el, i) => {
    el.style.borderColor = (i + 1) === idx ? 'var(--neon)' : 'var(--border)';
  });
}

function useSuggestedTeams() {
  const idx = window._selectedProposal || 1;
  const resultData = idx === 3 ? window._lastSuggestData3
    : idx === 2 ? window._lastSuggestData2
      : window._lastSuggestData;

  openModal().then(() => {
    document.getElementById('teamARows').innerHTML = '';
    document.getElementById('teamBRows').innerHTML = '';
    if (resultData) {
      resultData.teamA.forEach(p => addPlayerRow('A', p.id));
      resultData.teamB.forEach(p => addPlayerRow('B', p.id));
    } else {
      addPlayerRow('A'); addPlayerRow('A'); addPlayerRow('A');
      addPlayerRow('B'); addPlayerRow('B'); addPlayerRow('B');
    }
  });
}

// ── INIT ─────────────────────────────────────

document.addEventListener('DOMContentLoaded', async () => {
  await initGroupSelector();
  loadStats();
  loadLeaderboard();
  loadSessions();
  loadHof();
  loadInviteCode();

  const params = new URLSearchParams(window.location.search);

  // Apri modal nuova partita se richiesto da altra pagina
  if (params.get('nuova') === '1') {
    setTimeout(openModal, 1800);
  }

  // Apri modal login se arrivato da welcome.html
  if (params.get('login') === '1') {
    setTimeout(() => {
      if (typeof openLoginModal === 'function' && !window.isLoggedIn) {
        openLoginModal();
      }
    }, 400);
  }
});
