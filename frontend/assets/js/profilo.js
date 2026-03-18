// ============================================
//  profilo.js — Pagina Profilo Giocatore
// ============================================

const API = '../../api';

const PLAYER_COLORS = [
  '#e8ff00','#00f5ff','#ff6b35','#ff3cac',
  '#ffd700','#a78bfa','#34d399','#fb923c','#60a5fa'
];

Chart.defaults.color       = '#666680';
Chart.defaults.borderColor = '#2a2a44';
Chart.defaults.font.family = "'Barlow Condensed', sans-serif";

let trendChart = null;

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('it-IT', { day:'2-digit', month:'short', year:'numeric' }).toUpperCase();
}

function formatDay(d)   { return new Date(d).toLocaleDateString('it-IT', { day:'2-digit' }); }
function formatMonth(d) { return new Date(d).toLocaleDateString('it-IT', { month:'short', year:'numeric' }).toUpperCase(); }

// Leggi ?id= dall'URL
function getPlayerId() {
  return new URLSearchParams(window.location.search).get('id');
}

async function loadProfile() {
  const id = getPlayerId();
  if (!id) {
    document.querySelector('.main-profilo').innerHTML =
      '<div style="padding:3rem;text-align:center;color:var(--text-muted);font-family:\'Share Tech Mono\',monospace">Nessun giocatore selezionato</div>';
    return;
  }

  try {
    const data = await fetch(`${API}/profile.php?id=${id}`).then(r => r.json());
    if (data.error) throw new Error(data.error);

    // Colore accent basato sull'ID
    const color = PLAYER_COLORS[(parseInt(id) - 1) % PLAYER_COLORS.length];
    document.documentElement.style.setProperty('--accent-color', color);

    renderHero(data, color);
    renderStatsGrid(data, color);
    renderTrend(data, color);
    renderTeammates(data);
    renderHistory(data, color);

    // Aggiorna titolo pagina
    document.title = `🎳 ${data.player.emoji} ${data.player.name} — Strike Zone`;

  } catch (e) {
    console.error(e);
    document.getElementById('profileHero').innerHTML =
      '<div style="padding:3rem;text-align:center;color:var(--neon2);font-family:\'Share Tech Mono\',monospace">Errore nel caricamento profilo</div>';
  }
}

// ── HERO ─────────────────────────────────────
function renderHero(data, color) {
  const p     = data.player;
  const s     = data.stats;
  const media = parseFloat(s.media_serata) || 0;
  const gruppo = parseFloat(data.media_gruppo) || 0;
  const diff   = (media - gruppo).toFixed(1);
  const diffClass = diff > 0 ? 'pos' : diff < 0 ? 'neg' : 'eq';
  const diffText  = diff > 0 ? `▲ +${diff} vs gruppo` : diff < 0 ? `▼ ${diff} vs gruppo` : '→ Nella media';
  const since     = p.created_at ? new Date(p.created_at).toLocaleDateString('it-IT', { month:'long', year:'numeric' }) : '';

  document.getElementById('profileHero').innerHTML = `
    <div class="profile-hero-stripe" style="background:${color};box-shadow:0 0 20px ${color}"></div>
    <div class="profile-hero-body">
      <div class="profile-avatar-big" style="border-color:${color};box-shadow:0 0 30px ${color}44">${p.emoji || '🎳'}</div>
      <div>
        <div class="profile-name">${p.name}</div>
        ${p.nickname ? `<div class="profile-nickname">${p.nickname.toUpperCase()}</div>` : ''}
        ${since ? `<div class="profile-since">Nel gruppo dal ${since}</div>` : ''}
      </div>
      <div class="profile-vs-group">
        <div class="vs-label">Media serata</div>
        <div class="vs-value" style="color:${color};text-shadow:0 0 20px ${color}66">${s.media_serata ?? '—'}</div>
        <div class="vs-label" style="margin-top:0.3rem">Media gruppo: ${data.media_gruppo}</div>
        <div class="vs-diff ${diffClass}">${diffText}</div>
      </div>
    </div>`;
}

// ── STATS GRID ───────────────────────────────
function renderStatsGrid(data, color) {
  const s    = data.stats;
  const serate  = parseInt(s.serate) || 0;
  const vSq     = parseInt(s.vittorie_squadra) || 0;
  const pctVSq  = serate > 0 ? Math.round(vSq / serate * 100) : 0;
  const topScore = parseInt(s.volte_top_scorer) || 0;

  document.getElementById('statsGrid').innerHTML = `
    <div class="stat-card" style="--accent-color:${color}">
      <div class="stat-card-label">Serate giocate</div>
      <div class="stat-card-value">${serate}</div>
      <div class="stat-card-sub">${s.game_totali || 0} game totali</div>
    </div>
    <div class="stat-card" style="--accent-color:var(--neon2)">
      <div class="stat-card-label">Vittorie squadra</div>
      <div class="stat-card-value" style="color:var(--neon2);text-shadow:0 0 15px var(--neon2)">${vSq}</div>
      <div class="stat-card-sub">${pctVSq}% delle serate</div>
    </div>
    <div class="stat-card" style="--accent-color:var(--gold)">
      <div class="stat-card-label">Record serata</div>
      <div class="stat-card-value" style="color:var(--gold);text-shadow:0 0 15px rgba(255,215,0,0.4)">${s.record_serata ?? '—'}</div>
      <div class="stat-card-sub">Media game: ${s.media_game ?? '—'}</div>
    </div>
    <div class="stat-card" style="--accent-color:var(--neon3)">
      <div class="stat-card-label">Top scorer</div>
      <div class="stat-card-value" style="color:var(--neon3);text-shadow:0 0 15px var(--neon3)">${topScore}</div>
      <div class="stat-card-sub">volte miglior serata</div>
    </div>`;
}

// ── GRAFICO TREND ────────────────────────────
function renderTrend(data, color) {
  const trend = data.trend || [];
  if (!trend.length) {
    document.getElementById('trendChart').parentElement.innerHTML =
      '<div style="display:flex;align-items:center;justify-content:center;height:260px;color:var(--text-muted);font-family:\'Share Tech Mono\',monospace;font-size:0.8rem">Nessun dato ancora</div>';
    return;
  }

  // Media gruppo come linea di riferimento
  const mediaGruppo = parseFloat(data.media_gruppo) || 0;

  if (trendChart) trendChart.destroy();

  trendChart = new Chart(document.getElementById('trendChart'), {
    type: 'line',
    data: {
      labels: trend.map(t => new Date(t.date).toLocaleDateString('it-IT', { day:'2-digit', month:'short' })),
      datasets: [
        {
          label: data.player.name,
          data:  trend.map(t => t.totale),
          borderColor: color,
          backgroundColor: color + '22',
          pointBackgroundColor: color,
          pointRadius: 6,
          pointHoverRadius: 8,
          borderWidth: 2.5,
          tension: 0.3,
          fill: true,
        },
        {
          label: 'Media gruppo',
          data:  trend.map(() => mediaGruppo),
          borderColor: '#666680',
          borderWidth: 1.5,
          borderDash: [6, 4],
          pointRadius: 0,
          tension: 0,
          fill: false,
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      interaction: { mode: 'index', intersect: false },
      plugins: {
        legend: {
          display: true,
          labels: { boxWidth: 12, font: { size: 11 } }
        },
        tooltip: {
          backgroundColor: '#18182a',
          borderColor: '#2a2a44',
          borderWidth: 1,
        }
      },
      scales: {
        x: { grid: { color: '#2a2a4444' } },
        y: { grid: { color: '#2a2a4444' }, beginAtZero: false }
      }
    }
  });
}

// ── COMPAGNI ─────────────────────────────────
function renderTeammates(data) {
  const teammates = data.teammates || [];
  if (!teammates.length) {
    document.getElementById('teammatesCard').innerHTML =
      '<div style="padding:1.5rem;text-align:center;color:var(--text-muted);font-size:0.8rem">Nessuna sessione di squadra ancora</div>';
    return;
  }

  document.getElementById('teammatesCard').innerHTML = teammates.map(t => {
    const pct    = t.volte_insieme > 0 ? Math.round(t.vittorie_insieme / t.volte_insieme * 100) : 0;
    const cls    = pct >= 60 ? 'high' : pct >= 35 ? 'medium' : 'low';
    return `
      <div class="teammate-row">
        <div class="teammate-info">
          <span style="font-size:1.2rem">${t.emoji || '🎳'}</span>
          <span>${t.name}</span>
        </div>
        <div class="teammate-stats">
          <div class="teammate-together">${t.volte_insieme} serate insieme</div>
          <div style="display:flex;align-items:center;gap:0.4rem">
            <span class="teammate-wins">${t.vittorie_insieme} vinte</span>
            <span class="win-pct ${cls}">${pct}%</span>
          </div>
        </div>
      </div>`;
  }).join('');
}

// ── STORICO ──────────────────────────────────
function renderHistory(data, color) {
  const history = data.history || [];
  if (!history.length) {
    document.getElementById('historyList').innerHTML =
      '<div style="padding:2rem;text-align:center;color:var(--text-muted);font-family:\'Share Tech Mono\',monospace;font-size:0.8rem">Nessuna sessione ancora 🎳</div>';
    return;
  }

  document.getElementById('historyList').innerHTML = history.map((h, i) => {
    const games    = h.games || [];
    const gamesStr = games.length > 1 ? games.map((g,i) => `G${i+1}:${g}`).join(' ') : '';
    const delay    = (i * 0.04).toFixed(2);

    const badges = [];
    if (h.vittoria)   badges.push(`<span class="team-tag win">VITTORIA</span>`);
    else              badges.push(`<span class="team-tag lose">SCONFITTA</span>`);
    if (h.top_scorer) badges.push(`<span style="font-size:0.8rem" title="Miglior score della serata">🏆</span>`);

    return `
      <div class="history-item" style="animation-delay:${delay}s">
        <div class="history-header">
          <div>
            <div class="history-date-day" style="color:${color}">${formatDay(h.date)}</div>
            <div class="history-date-month">${formatMonth(h.date)}</div>
          </div>
          <div>
            <div class="history-location">${h.location}</div>
            ${h.team_name ? `<div class="history-team">🏷 ${h.team_name}</div>` : ''}
          </div>
          <div style="display:flex;align-items:center;gap:0.4rem">${badges.join('')}</div>
          <div>
            <div class="history-total" style="color:${color}">${h.totale}</div>
            ${gamesStr ? `<div class="history-games">${gamesStr}</div>` : ''}
          </div>
        </div>
      </div>`;
  }).join('');
}

// ── INIT ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', loadProfile);