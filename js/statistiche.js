// ============================================
//  statistiche.js — Pagina Statistiche
// ============================================

const API = 'api';

// Palette colori giocatori (ciclica)
const PLAYER_COLORS = [
  '#e8ff00','#00f5ff','#ff6b35','#ff3cac',
  '#ffd700','#a78bfa','#34d399','#fb923c',
  '#60a5fa','#f472b6'
];

// Stato
let statsData      = null;
let trendChart     = null;
let compareChart   = null;
let distChart      = null;
let activeTrend    = new Set();   // player names attivi nel grafico trend
let currentMetric  = 'media';
let currentFrom    = null;
let currentTo      = null;
let currentRankMetric = 'media';

// ── CLASSIFICA ────────────────────────────────

const RANK_METRICS = {
  media:         { label: 'Media',      fmt: v => v ?? '—',                       unit: '' },
  win_pct:       { label: '% Vittorie', fmt: v => v != null ? v + '%' : '—',      unit: '%' },
  record:        { label: 'Top Score',  fmt: v => v ?? '—',                       unit: '' },
  partite:       { label: 'Presenze',   fmt: v => v ?? '—',                       unit: '' },
  vitt:          { label: 'Vittorie',   fmt: v => v ?? '—',                       unit: '' },
  media_recente: { label: 'Forma',      fmt: v => v ?? '—',                       unit: '' },
};

const MEDAL_COLORS = ['#ffd700', '#c0c0d0', '#cd7f32'];
const MEDAL_EMOJIS = ['🥇', '🥈', '🥉'];

function computeRankValue(p, metric) {
  if (metric === 'win_pct') {
    const scs  = parseInt(p.serate_con_squadra) || 0;
    const wins = parseInt(p.vittorie_squadra) || 0;
    return scs > 0 ? Math.round(wins / scs * 100) : null;
  }
  if (metric === 'vitt')  return parseInt(p.vittorie_squadra) || 0;
  if (metric === 'media_recente') return p.media_recente ? parseFloat(p.media_recente) : null;
  if (metric === 'media')   return parseFloat(p.media)   || null;
  if (metric === 'record')  return parseInt(p.record)    || null;
  if (metric === 'partite') return parseInt(p.serate_con_squadra) || null;
  return null;
}

function setRankMetric(btn) {
  document.querySelectorAll('.rank-metric').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  currentRankMetric = btn.dataset.metric;
  renderRanking();
}

function renderRanking() {
  const lb = (statsData.leaderboard || []).filter(p => parseInt(p.partite) > 0);
  if (!lb.length) {
    document.getElementById('podiumWrap').innerHTML =
      `<div style="color:var(--text-muted);font-family:'Share Tech Mono',monospace;font-size:0.8rem;padding:2rem">Nessun dato nel periodo selezionato</div>`;
    document.getElementById('rankTableBody').innerHTML = '';
    return;
  }

  // Ordina per metrica corrente
  const sorted = [...lb].sort((a, b) => {
    const va = computeRankValue(a, currentRankMetric) ?? -Infinity;
    const vb = computeRankValue(b, currentRankMetric) ?? -Infinity;
    return vb - va;
  });

  // ── PODIO ──
  const top3 = sorted.slice(0, 3);
  // Ordine visivo podio: 2° | 1° | 3°
  const podiumOrder = [top3[1], top3[0], top3[2]].filter(Boolean);
  const posClass    = ['pos-2', 'pos-1', 'pos-3'];
  const realPos     = [1, 0, 2]; // indice nell'array sorted

  const metric = RANK_METRICS[currentRankMetric];

  document.getElementById('podiumWrap').innerHTML = podiumOrder.map((p, vi) => {
    const pos   = realPos[vi];
    const color = MEDAL_COLORS[pos];
    const medal = MEDAL_EMOJIS[pos];
    const val   = computeRankValue(p, currentRankMetric);
    const ci    = lb.findIndex(x => x.id === p.id);
    const pcolor = PLAYER_COLORS[ci % PLAYER_COLORS.length];

    return `
      <div class="podium-slot ${posClass[vi]}" style="--medal-color:${color}">
        <div class="podium-avatar" style="border-color:${color};box-shadow:0 0 20px ${color}44">
          ${p.emoji || '🎳'}
          <span class="podium-medal">${medal}</span>
        </div>
        <div class="podium-name" style="color:${color}">${p.name}</div>
        <div class="podium-value" style="color:${color};text-shadow:0 0 20px ${color}66">
          ${currentRankMetric === 'media_recente'
            ? (() => {
                const ris = p.ultimi_risultati || [];
                if (!ris.length) return '<span style="font-size:0.8rem">—</span>';
                const dot = r => {
                  if (r==='V') return '<span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:#22c55e;color:#000;font-size:0.45rem;font-weight:700">V</span>';
                  if (r==='P') return '<span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:#ef4444;color:#fff;font-size:0.45rem;font-weight:700">P</span>';
                  return '<span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:#555570;color:#fff;font-size:0.45rem;font-weight:700">N</span>';
                };
                const empty = '<span style="display:inline-flex;align-items:center;justify-content:center;width:14px;height:14px;border-radius:50%;background:#2a2a44;border:1px solid #3a3a5a"></span>';
                const emptyDots = Array(5-ris.length).fill(empty).join('');
                return '<div style="display:flex;gap:2px;justify-content:center;margin:4px 0">' + emptyDots + ris.map(dot).join('') + '</div>';
              })()
            : val != null ? metric.fmt(val) : '—'}
        </div>
        <div class="podium-value-label">${metric.label}</div>
        <div class="podium-block" style="border-color:${color}55;box-shadow:0 0 16px ${color}22"></div>
      </div>`;
  }).join('');

  // Aggiorna header colonna attiva
  const colIds = { media:'thMedia', win_pct:'thWin', record:'thRecord', partite:'thPartite', vitt:'thVitt', media_recente:'thForma' };
  Object.values(colIds).forEach(id => {
    const el = document.getElementById(id);
    if (el) el.classList.remove('active-col');
  });
  const activeEl = document.getElementById(colIds[currentRankMetric]);
  if (activeEl) activeEl.classList.add('active-col');

  // ── TABELLA ──
  document.getElementById('rankTableBody').innerHTML = sorted.map((p, i) => {
    const ci      = lb.findIndex(x => x.id === p.id);
    const pcolor  = PLAYER_COLORS[ci % PLAYER_COLORS.length];
    const medals  = ['🥇','🥈','🥉'];
    const rankEl  = i < 3
      ? `<div class="rank-table-rank">${medals[i]}</div>`
      : `<div class="rank-table-rank" style="color:var(--text-muted);font-size:0.85rem">${i+1}</div>`;

    const vittorie         = parseInt(p.vittorie_squadra) || 0;
    const serateConSquadra = parseInt(p.serate_con_squadra) || 0;
    const hasSfide         = serateConSquadra > 0;
    const winPct           = hasSfide ? Math.round(vittorie / serateConSquadra * 100) : null;
    const sconfitte        = hasSfide ? Math.max(0, serateConSquadra - vittorie) : null;

    // Colonna V/N/P
    const vnpBadge = hasSfide
      ? '<span style="color:#22c55e;font-weight:700">' + vittorie + 'V</span> <span style="color:#666680">0N</span> <span style="color:#ef4444">' + sconfitte + 'P</span>'
      : '—';

    // Badge forma — pallini V/P/N
    const risultati = p.ultimi_risultati || [];
    const dot = r => {
      if (r==='V') return '<span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#22c55e;color:#000;font-size:0.5rem;font-weight:700">V</span>';
      if (r==='P') return '<span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#ef4444;color:#fff;font-size:0.5rem;font-weight:700">P</span>';
      return '<span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#555570;color:#fff;font-size:0.5rem;font-weight:700">N</span>';
    };
    const emptyDot = '<span style="display:inline-flex;align-items:center;justify-content:center;width:16px;height:16px;border-radius:50%;background:#2a2a44;border:1px solid #3a3a5a"></span>';
    const formaBadge = '<div style="display:flex;gap:2px;justify-content:center">' +
      Array(Math.max(0, 5 - risultati.length)).fill(emptyDot).join('') +
      risultati.map(dot).join('') + '</div>';

    const isActive = m => currentRankMetric === m;

    return `
      <div class="rank-table-row" style="animation-delay:${(i*0.05).toFixed(2)}s">
        ${rankEl}
        <div class="rank-table-player">
          <div class="rank-table-avatar" style="border-color:${pcolor}44;background:${pcolor}12">${p.emoji||'🎳'}</div>
          <div>
            <div class="rank-table-name">${p.name}</div>
            ${p.nickname ? '<div class="rank-table-nick">' + p.nickname.toUpperCase() + '</div>' : ''}
          </div>
        </div>
        <div class="rank-table-val ${isActive('media') ? 'active-val' : ''}" style="${isActive('media')?'':'color:var(--neon)'}">${p.media ?? '—'}</div>
        <div class="rank-table-val ${isActive('win_pct') ? 'active-val' : ''}">${winPct != null ? winPct+'%' : '—'}</div>
        <div class="rank-table-val ${isActive('record') ? 'active-val' : ''}" style="${isActive('record')?'':'color:var(--neon3)'}">${p.record ?? '—'}</div>
        <div class="rank-table-val ${isActive('partite') ? 'active-val' : ''}">${parseInt(p.serate_con_squadra) > 0 ? p.serate_con_squadra : '—'}</div>
        <div class="rank-table-val ${isActive('vitt') ? 'active-val' : ''}" style="font-family:'Share Tech Mono',monospace;font-size:0.75rem">${vnpBadge}</div>
        <div class="rank-table-val ${isActive('media_recente') ? 'active-val' : ''}">${formaBadge}</div>
      </div>`;
  }).join('');
}

// ── UTILITY ──────────────────────────────────

function showToast(msg, type = 'success') {
  const t = document.getElementById('toast');
  t.textContent = (type === 'success' ? '✓ ' : '✕ ') + msg;
  t.className   = `toast ${type} show`;
  setTimeout(() => t.className = 'toast', 3500);
}

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('it-IT', { day:'2-digit', month:'short', year:'numeric' }).toUpperCase();
}

function pctClass(pct) {
  if (pct >= 60) return 'high';
  if (pct >= 35) return 'medium';
  return 'low';
}

// Defaults Chart.js globali
Chart.defaults.color           = '#666680';
Chart.defaults.borderColor     = '#2a2a44';
Chart.defaults.font.family     = "'Barlow Condensed', sans-serif";
Chart.defaults.font.size       = 12;

// ── FILTRO PERIODO ───────────────────────────

function setPeriod(range) {
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  event.target.classList.add('active');

  const now  = new Date();
  const pad  = n => String(n).padStart(2,'0');
  const fmt  = d => `${d.getFullYear()}-${pad(d.getMonth()+1)}-${pad(d.getDate())}`;

  if (range === 'all') {
    currentFrom = null; currentTo = null;
    document.getElementById('periodFrom').value = '';
    document.getElementById('periodTo').value   = '';
  } else {
    currentTo = fmt(now);
    const from = new Date(now);
    if (range === 'month')   from.setMonth(from.getMonth() - 1);
    if (range === '3months') from.setMonth(from.getMonth() - 3);
    if (range === 'year')    from.setFullYear(from.getFullYear() - 1);
    currentFrom = fmt(from);
    document.getElementById('periodFrom').value = currentFrom;
    document.getElementById('periodTo').value   = currentTo;
  }
  loadStats();
}

function applyCustomPeriod() {
  currentFrom = document.getElementById('periodFrom').value || null;
  currentTo   = document.getElementById('periodTo').value   || null;
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  loadStats();
}

// ── CARICA DATI ──────────────────────────────

async function loadStats() {
  try {
    const params = new URLSearchParams();
    if (currentFrom) params.set('from', currentFrom);
    if (currentTo)   params.set('to',   currentTo);
    const url = `${API}/stats.php${params.toString() ? '?' + params : ''}`;

    statsData = await fetch(url).then(r => r.json());

    updateHeroBar();
    renderRanking();
    buildTrendControls();
    renderTrend();
    renderCompare();
    buildDistSelect();
    renderDistribution();
    renderWins();
    buildH2HSelects();
    renderH2H();
    buildChemSelect();
    renderChemistry();

  } catch (e) {
    console.error('Errore caricamento stats:', e);
    showToast('Errore nel caricamento', 'error');
  }
}

// ── HERO BAR ─────────────────────────────────

function updateHeroBar() {
  document.getElementById('stat-sessioni').textContent = statsData.totale_sessioni || '—';
  document.getElementById('stat-record').textContent   = statsData.record_assoluto || '—';
  document.getElementById('stat-media').textContent    = statsData.media_gruppo    || '—';

  if (statsData.record_holder) {
    document.getElementById('stat-record-sub').textContent =
      `${statsData.record_holder.emoji} ${statsData.record_holder.name} · ${formatDate(statsData.record_holder.date)}`;
  }
  if (statsData.ultima_sessione) {
    const d = new Date(statsData.ultima_sessione.date);
    document.getElementById('stat-ultima').textContent =
      d.toLocaleDateString('it-IT', { day:'numeric', month:'short' }).toUpperCase();
    document.getElementById('stat-ultima-sub').textContent = statsData.ultima_sessione.location;
  }
}

// ── GRAFICO TREND ────────────────────────────

function buildTrendControls() {
  const trend = statsData.trend || [];

  // Prima volta: attiva tutti i giocatori con dati
  if (activeTrend.size === 0) {
    trend.forEach(p => activeTrend.add(p.name));
  }

  const container = document.getElementById('trendPlayerBtns');
  container.innerHTML = trend.map((p, i) => {
    const color   = PLAYER_COLORS[i % PLAYER_COLORS.length];
    const isActive = activeTrend.has(p.name);
    return `
      <button class="player-filter-btn${isActive ? ' active' : ''}"
        style="${isActive ? `background:${color};border-color:${color};` : `border-color:${color}44;color:${color};`}"
        onclick="toggleTrendPlayer('${p.name}', this, '${color}')">
        ${p.emoji || '🎳'} ${p.name}
      </button>`;
  }).join('');
}

function toggleTrendPlayer(name, btn, color) {
  if (activeTrend.has(name)) {
    if (activeTrend.size === 1) return; // almeno uno sempre attivo
    activeTrend.delete(name);
    btn.classList.remove('active');
    btn.style.background   = 'none';
    btn.style.borderColor  = color + '44';
    btn.style.color        = color;
  } else {
    activeTrend.add(name);
    btn.classList.add('active');
    btn.style.background   = color;
    btn.style.borderColor  = color;
    btn.style.color        = '#0a0a0f';
  }
  renderTrend();
}

function renderTrend() {
  const trend = (statsData.trend || []).filter(p => activeTrend.has(p.name));
  if (!trend.length) return;

  // Raccoglie tutte le date uniche ordinate
  const allDates = [...new Set(
    trend.flatMap(p => p.data.map(d => d.date))
  )].sort();

  const datasets = trend.map((p, i) => {
    const colorIdx = (statsData.trend || []).findIndex(x => x.name === p.name);
    const color    = PLAYER_COLORS[colorIdx % PLAYER_COLORS.length];
    // Mappa score sulle date (null se non ha giocato quel giorno)
    const dataMap  = Object.fromEntries(p.data.map(d => [d.date, d.score]));
    return {
      label: `${p.emoji || ''} ${p.name}`,
      data:  allDates.map(d => dataMap[d] ?? null),
      borderColor: color,
      backgroundColor: color + '22',
      pointBackgroundColor: color,
      pointRadius: 5,
      pointHoverRadius: 7,
      borderWidth: 2,
      spanGaps: true,
      tension: 0.3,
    };
  });

  if (trendChart) trendChart.destroy();

  trendChart = new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: allDates.map(d => new Date(d).toLocaleDateString('it-IT', { day:'2-digit', month:'short' })),
      datasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#18182a',
          borderColor: '#2a2a44',
          borderWidth: 1,
          callbacks: {
            label: ctx => ` ${ctx.dataset.label}: ${ctx.parsed.y ?? '—'}`
          }
        }
      },
      scales: {
        x: { grid: { color: '#2a2a4444' }, ticks: { maxRotation: 45 } },
        y: { grid: { color: '#2a2a4444' }, min: 0, max: 300,
             ticks: { stepSize: 50 } }
      }
    }
  });
}

// ── GRAFICO CONFRONTO ─────────────────────────

function setMetric(metric, btn) {
  currentMetric = metric;
  document.querySelectorAll('.metric-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderCompare();
}

function renderCompare() {
  const lb = (statsData.leaderboard || []).filter(p => parseInt(p.partite) > 0);
  if (!lb.length) return;

  const labels = lb.map(p => `${p.emoji || ''} ${p.name}`);
  const values = lb.map(p => parseFloat(p[currentMetric]) || 0);
  const colors = lb.map((_, i) => PLAYER_COLORS[i % PLAYER_COLORS.length]);

  const metricLabels = { media: 'Media', record: 'Record', partite: 'Partite' };

  if (compareChart) compareChart.destroy();

  compareChart = new Chart(document.getElementById('compareChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: metricLabels[currentMetric],
        data: values,
        backgroundColor: colors.map(c => c + 'bb'),
        borderColor: colors,
        borderWidth: 2,
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: '#18182a',
          borderColor: '#2a2a44',
          borderWidth: 1,
        }
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: '#2a2a4444' }, beginAtZero: true }
      }
    }
  });
}

// ── DISTRIBUZIONE ────────────────────────────

function buildDistSelect() {
  const sel = document.getElementById('distPlayer');
  const cur = sel.value;
  sel.innerHTML = '<option value="all">Tutti i giocatori</option>' +
    (statsData.leaderboard || [])
      .filter(p => parseInt(p.partite) > 0)
      .map(p => `<option value="${p.id}" ${p.id == cur ? 'selected':''}>${p.emoji || ''} ${p.name}</option>`)
      .join('');
}

function renderDistribution() {
  const pid  = document.getElementById('distPlayer').value;
  const dist = statsData.distribution || [];
  const labels = ['< 100', '100–149', '150–199', '200–249', '≥ 250'];

  let values;
  if (pid === 'all') {
    values = [
      dist.reduce((s,p) => s + parseInt(p.r0  ||0),0),
      dist.reduce((s,p) => s + parseInt(p.r100||0),0),
      dist.reduce((s,p) => s + parseInt(p.r150||0),0),
      dist.reduce((s,p) => s + parseInt(p.r200||0),0),
      dist.reduce((s,p) => s + parseInt(p.r250||0),0),
    ];
  } else {
    const p = dist.find(x => x.id == pid);
    values = p ? [p.r0,p.r100,p.r150,p.r200,p.r250].map(v => parseInt(v)||0) : [0,0,0,0,0];
  }

  const bgColors = ['#666680bb','#ff6b35bb','#e8ff00bb','#00f5ffbb','#ff3cacbb'];
  const brColors = ['#666680',  '#ff6b35',  '#e8ff00',  '#00f5ff',  '#ff3cac'];

  if (distChart) distChart.destroy();

  distChart = new Chart(document.getElementById('distChart'), {
    type: 'bar',
    data: {
      labels,
      datasets: [{
        label: 'Punteggi',
        data: values,
        backgroundColor: bgColors,
        borderColor: brColors,
        borderWidth: 2,
        borderRadius: 4,
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: { legend: { display: false },
        tooltip: { backgroundColor:'#18182a', borderColor:'#2a2a44', borderWidth:1 }
      },
      scales: {
        x: { grid: { display: false } },
        y: { grid: { color: '#2a2a4444' }, beginAtZero: true, ticks: { stepSize: 1 } }
      }
    }
  });
}

// ── VITTORIE ─────────────────────────────────

function renderWins() {
  const wins = statsData.wins_breakdown || [];
  if (!wins.length) {
    document.getElementById('winsList').innerHTML =
      '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessun dato</div>';
    return;
  }

  const maxSq  = Math.max(...wins.map(w => parseInt(w.vittorie_squadra)  || 0));
  const maxInd = Math.max(...wins.map(w => parseInt(w.vittorie_individuali) || 0));

  document.getElementById('winsList').innerHTML = wins.map((w, i) => {
    const sq  = parseInt(w.vittorie_squadra)      || 0;
    const ind = parseInt(w.vittorie_individuali)  || 0;
    const tot = parseInt(w.sessioni_totali)        || 0;
    const pSq  = maxSq  > 0 ? Math.round(sq  / maxSq  * 100) : 0;
    const pInd = maxInd > 0 ? Math.round(ind / maxInd * 100) : 0;
    const color = PLAYER_COLORS[i % PLAYER_COLORS.length];

    return `
      <div class="wins-row">
        <div style="font-size:1.3rem">${w.emoji || '🎳'}</div>
        <div class="wins-player" style="color:${color}">${w.name}</div>
        <div>
          <div class="wins-bar-row">
            <span class="wins-label">SQ</span>
            <div class="wins-bar-bg">
              <div class="wins-bar-fill" style="width:${pSq}%;background:var(--neon);box-shadow:0 0 4px var(--neon)"></div>
            </div>
            <span class="wins-val" style="color:var(--neon)">${sq}</span>
          </div>
          <div class="wins-bar-row">
            <span class="wins-label">TOP</span>
            <div class="wins-bar-bg">
              <div class="wins-bar-fill" style="width:${pInd}%;background:var(--neon4);box-shadow:0 0 4px var(--neon4)"></div>
            </div>
            <span class="wins-val" style="color:var(--neon4)">${ind}</span>
          </div>
        </div>
        <div class="wins-val" style="color:var(--text-muted)">${tot} gare</div>
      </div>`;
  }).join('');
}

// ── TESTA A TESTA ────────────────────────────

function buildH2HSelects() {
  const players = statsData.leaderboard || [];
  const opts    = players.map(p =>
    `<option value="${p.id}">${p.emoji || '🎳'} ${p.name}</option>`
  ).join('');

  const sel1 = document.getElementById('h2hP1');
  const sel2 = document.getElementById('h2hP2');
  const v1   = sel1.value;
  const v2   = sel2.value;
  sel1.innerHTML = '<option value="">— Giocatore 1 —</option>' + opts;
  sel2.innerHTML = '<option value="">— Giocatore 2 —</option>' + opts;
  if (v1) sel1.value = v1;
  if (v2) sel2.value = v2;
}

function renderH2H() {
  const p1id = parseInt(document.getElementById('h2hP1').value);
  const p2id = parseInt(document.getElementById('h2hP2').value);
  const container = document.getElementById('h2hResult');

  if (!p1id || !p2id || p1id === p2id) {
    container.innerHTML = '<div class="h2h-placeholder">Seleziona due giocatori diversi per vedere lo scontro diretto</div>';
    return;
  }

  // Trova la coppia (l'API li ordina con p1_id < p2_id)
  const key1 = Math.min(p1id,p2id) + '_' + Math.max(p1id,p2id);
  const match = (statsData.h2h || []).find(h =>
    (h.p1_id == Math.min(p1id,p2id) && h.p2_id == Math.max(p1id,p2id))
  );

  if (!match || match.total === 0) {
    container.innerHTML = '<div class="h2h-placeholder">Questi due giocatori non hanno ancora giocato nella stessa sessione</div>';
    return;
  }

  // Assegna P1/P2 in base alla selezione
  const isSwapped = p1id > p2id;
  const p1wins  = isSwapped ? match.p2_wins : match.p1_wins;
  const p2wins  = isSwapped ? match.p1_wins : match.p2_wins;
  const p1name  = isSwapped ? match.p2_name : match.p1_name;
  const p2name  = isSwapped ? match.p1_name : match.p2_name;
  const p1emoji = isSwapped ? match.p2_emoji : match.p1_emoji;
  const p2emoji = isSwapped ? match.p1_emoji : match.p2_emoji;
  const total   = match.total;
  const draws   = match.draws;

  const p1pct  = total > 0 ? Math.round(p1wins / total * 100) : 50;
  const p1color = '#e8ff00';
  const p2color = '#ff3cac';

  container.innerHTML = `
    <div class="h2h-display">
      <div class="h2h-player">
        <div class="h2h-avatar">${p1emoji || '🎳'}</div>
        <div class="h2h-name" style="color:${p1color}">${p1name}</div>
        <div class="h2h-wins-big" style="color:${p1color};text-shadow:0 0 20px ${p1color}88">${p1wins}</div>
        <div class="h2h-wins-label">vittorie</div>
      </div>

      <div class="h2h-vs">
        <span class="h2h-vs-text">VS</span>
        <span class="h2h-total">${total} sfide<br>${draws > 0 ? draws + ' pari' : ''}</span>
      </div>

      <div class="h2h-player">
        <div class="h2h-avatar">${p2emoji || '🎳'}</div>
        <div class="h2h-name" style="color:${p2color}">${p2name}</div>
        <div class="h2h-wins-big" style="color:${p2color};text-shadow:0 0 20px ${p2color}88">${p2wins}</div>
        <div class="h2h-wins-label">vittorie</div>
      </div>

      <div class="h2h-bar">
        <div class="h2h-bar-fill" style="width:${p1pct}%;background:linear-gradient(90deg,${p1color},${p2color})"></div>
      </div>
    </div>`;
}

// ── CHIMICA DI SQUADRA ────────────────────────

function buildChemSelect() {
  const players = statsData.leaderboard || [];
  const sel     = document.getElementById('chemPlayer');
  const cur     = sel.value;
  sel.innerHTML = '<option value="">Tutti</option>' +
    players.map(p => `<option value="${p.id}" ${p.id == cur ? 'selected':''}>${p.emoji || ''} ${p.name}</option>`).join('');
}

function renderChemistry() {
  const pid  = parseInt(document.getElementById('chemPlayer').value) || null;
  let chem   = (statsData.chemistry || []).filter(c => c.total > 0);

  if (pid) chem = chem.filter(c => c.p1_id == pid || c.p2_id == pid);

  // Ordina per % vittorie decrescente
  chem.sort((a, b) => (b.wins / b.total) - (a.wins / a.total));

  const container = document.getElementById('chemistryTable');

  if (!chem.length) {
    container.innerHTML = '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessun dato disponibile</div>';
    return;
  }

  const header = `
    <div class="chem-row chem-header">
      <div>Coppia</div>
      <div style="text-align:center">Gare</div>
      <div style="text-align:center">Vinte</div>
      <div style="text-align:center">% Win</div>
    </div>`;

  const rows = chem.map(c => {
    const pct    = c.total > 0 ? Math.round(c.wins / c.total * 100) : 0;
    const cls    = pctClass(pct);
    return `
      <div class="chem-row">
        <div class="chem-players">
          <span>${c.p1_emoji || '🎳'} ${c.p1_name}</span>
          <span class="chem-sep">+</span>
          <span>${c.p2_emoji || '🎳'} ${c.p2_name}</span>
        </div>
        <div class="chem-val">${c.total}</div>
        <div class="chem-val" style="color:var(--neon)">${c.wins}</div>
        <div><span class="chem-pct ${cls}">${pct}%</span></div>
      </div>`;
  }).join('');

  container.innerHTML = header + rows;
}

// ── INIT ─────────────────────────────────────

document.addEventListener('DOMContentLoaded', loadStats);
function setRankMetricById(metric) {
  currentRankMetric = metric;
  document.querySelectorAll('.rank-metric').forEach(b => {
    b.classList.toggle('active', b.dataset.metric === metric);
  });
  renderRanking();
}