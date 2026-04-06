// ============================================
//  statistiche.js — Pagina Statistiche (Versione Semplificata)
// ============================================

// Palette colori giocatori
const PLAYER_COLORS = [
  '#e8ff00','#00f5ff','#ff6b35','#ff3cac',
  '#ffd700','#a78bfa','#34d399','#fb923c',
  '#60a5fa','#f472b6'
];

// Stato
let statsData = null;
let trendChart = null;
let histChart = null;
let paymentChart = null;

// ── UTILITY ──────────────────────────────────

function formatDate(str) {
  if (!str) return '';
  const d = new Date(str);
  return d.toLocaleDateString('it-IT', {day:'numeric', month:'short', year:'numeric'});
}

function showToast(msg, type = 'info') {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = msg;
  t.className = 'toast show ' + type;
  setTimeout(() => t.classList.remove('show'), 3000);
}

// ── CARICAMENTO DATI ─────────────────────────

async function loadStats() {
  try {
    const res = await fetch('/api/stats.php');
    statsData = await res.json();

    updateHeroBar();
    buildRecords();
    buildTopPerformer();
    renderTrend();
    renderHistogram();
    renderPaymentTrend();

  } catch (e) {
    console.error('Errore caricamento stats:', e);
    showToast('Errore nel caricamento', 'error');
  }
}

// ── HERO BAR ─────────────────────────────────

function updateHeroBar() {
  const partite = document.getElementById('stat-partite');
  const record = document.getElementById('stat-record');
  const media = document.getElementById('stat-media-gruppo');
  const minimo = document.getElementById('stat-minimo');
  const recordSub = document.getElementById('stat-record-sub');
  const minimoSub = document.getElementById('stat-minimo-sub');

  if (partite) partite.textContent = statsData.totale_sessioni || '—';
  if (record) record.textContent = statsData.record_assoluto || '—';
  if (media) media.textContent = statsData.media_gruppo || '—';

  if (statsData.record_holder && recordSub) {
    recordSub.textContent = `${statsData.record_holder.emoji} ${statsData.record_holder.name} · ${formatDate(statsData.record_holder.date)}`;
  }

  if (statsData.punteggio_minimo !== undefined && minimo) {
    minimo.textContent = statsData.punteggio_minimo || '—';
  }
  if (statsData.minimo_holder && minimoSub) {
    minimoSub.textContent = `${statsData.minimo_holder.emoji} ${statsData.minimo_holder.name} · ${formatDate(statsData.minimo_holder.date)}`;
  }
}

// ── GRAFICO TREND ────────────────────────────

function renderTrend() {
  const canvas = document.getElementById('trendChart');
  if (!canvas) return;

  const trend = statsData.trend || [];
  if (trend.length === 0) return;

  // Prendi tutte le date uniche
  const allDates = [...new Set(
    trend.flatMap(p => p.data.map(d => d.date))
  )].sort();

  const datasets = trend.map((p, i) => ({
    label: `${p.emoji || '🎳'} ${p.name}`,
    data: allDates.map(date => {
      const entry = p.data.find(d => d.date === date);
      return entry ? entry.avg : null;
    }),
    borderColor: PLAYER_COLORS[i % PLAYER_COLORS.length],
    backgroundColor: PLAYER_COLORS[i % PLAYER_COLORS.length] + '22',
    borderWidth: 2,
    tension: 0.3,
    spanGaps: true
  }));

  if (trendChart) trendChart.destroy();
  
  trendChart = new Chart(canvas, {
    type: 'line',
    data: {
      labels: allDates.map(d => {
        const dt = new Date(d);
        return dt.toLocaleDateString('it-IT', {day:'numeric', month:'short'});
      }),
      datasets: datasets
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: 'top',
          labels: { color: '#e8e8f0', font: { size: 11 } }
        },
        tooltip: {
          backgroundColor: 'rgba(10,10,15,0.95)',
          titleColor: '#e8ff00',
          bodyColor: '#e8e8f0'
        }
      },
      scales: {
        y: {
          ticks: { color: '#666680' },
          grid: { color: '#2a2a44' }
        },
        x: {
          ticks: { color: '#666680' },
          grid: { color: '#2a2a44' }
        }
      }
    }
  });
}

// ── ISTOGRAMMA DISTRIBUZIONE ─────────────────

function renderHistogram() {
  const canvas = document.getElementById('histChart');
  if (!canvas) return;

  const dist = statsData.distribution || [];
  if (dist.length === 0) return;

  const labels = dist.map(d => d.range);
  const counts = dist.map(d => d.count);

  if (histChart) histChart.destroy();

  histChart = new Chart(canvas, {
    type: 'bar',
    data: {
      labels: labels,
      datasets: [{
        label: 'Punteggi',
        data: counts,
        backgroundColor: '#00f5ff44',
        borderColor: '#00f5ff',
        borderWidth: 1
      }]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: { display: false },
        tooltip: {
          backgroundColor: 'rgba(10,10,15,0.95)',
          titleColor: '#00f5ff',
          bodyColor: '#e8e8f0'
        }
      },
      scales: {
        y: {
          ticks: { color: '#666680' },
          grid: { color: '#2a2a44' }
        },
        x: {
          ticks: { color: '#666680' },
          grid: { color: '#2a2a44' }
        }
      }
    }
  });
}

// ── RECORD & CURIOSITÀ ───────────────────────

function buildRecords() {
  const container = document.getElementById('recordsGrid');
  if (!container) return;

  const records = statsData.records || {};
  const items = [];

  if (records.max_score) {
    items.push({
      icon: '🔥',
      label: 'Punteggio più alto',
      value: records.max_score.score,
      sub: `${records.max_score.emoji} ${records.max_score.name} · ${formatDate(records.max_score.date)}`
    });
  }

  if (records.min_score) {
    items.push({
      icon: '❄️',
      label: 'Punteggio più basso',
      value: records.min_score.score,
      sub: `${records.min_score.emoji} ${records.min_score.name} · ${formatDate(records.min_score.date)}`
    });
  }

  if (records.avg_per_session) {
    items.push({
      icon: '📊',
      label: 'Media per sessione',
      value: records.avg_per_session,
      sub: 'Punteggio medio complessivo'
    });
  }

  if (records.total_games) {
    items.push({
      icon: '🎳',
      label: 'Partite totali',
      value: records.total_games,
      sub: 'Game giocati da sempre'
    });
  }

  container.innerHTML = items.map(r => `
    <div class="record-card">
      <div class="record-icon">${r.icon}</div>
      <div class="record-label">${r.label}</div>
      <div class="record-value">${r.value}</div>
      <div class="record-sub">${r.sub}</div>
    </div>
  `).join('');
}

// ── TOP PERFORMER ────────────────────────────

function buildTopPerformer() {
  const container = document.getElementById('topPerformerCard');
  if (!container) return;

  const lb = (statsData.leaderboard || []).filter(p => parseInt(p.partite) > 0);
  if (lb.length === 0) {
    container.innerHTML = '<div style="padding:2rem;text-align:center;color:var(--text-muted)">Nessun dato disponibile</div>';
    return;
  }

  // Ordina per media
  const sorted = [...lb].sort((a, b) => {
    const avgA = parseFloat(a.media) || 0;
    const avgB = parseFloat(b.media) || 0;
    return avgB - avgA;
  });

  const top3 = sorted.slice(0, 3);

  container.innerHTML = `
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:1rem;padding:1.5rem">
      ${top3.map((p, i) => {
        const medal = ['🥇','🥈','🥉'][i];
        const color = PLAYER_COLORS[i];
        return `
          <div style="text-align:center;padding:1rem">
            <div style="font-size:2rem;margin-bottom:0.5rem">${medal}</div>
            <div style="font-size:2.5rem;margin-bottom:0.5rem">${p.emoji || '🎳'}</div>
            <div style="color:${color};font-weight:700;font-size:1.1rem;margin-bottom:0.3rem">${p.name}</div>
            <div style="color:var(--neon);font-size:1.5rem;font-weight:700">${p.media || '—'}</div>
            <div style="color:var(--text-muted);font-size:0.75rem;margin-top:0.3rem">${p.partite} partite</div>
          </div>
        `;
      }).join('')}
    </div>
  `;
}

// ── GRAFICO PAGAMENTI ────────────────────────

function renderPaymentTrend() {
  const canvas = document.getElementById('paymentChart');
  if (!canvas) return;

  const payments = statsData.payment_trend || [];
  if (payments.length === 0) return;

  const labels = payments.map(p => {
    const d = new Date(p.date);
    return d.toLocaleDateString('it-IT', {day:'numeric', month:'short'});
  });

  const vittorie = payments.map(p => parseFloat(p.vittorie) || 0);
  const pareggi = payments.map(p => parseFloat(p.pareggi) || 0);
  const sconfitte = payments.map(p => parseFloat(p.sconfitte) || 0);

  if (paymentChart) paymentChart.destroy();

  paymentChart = new Chart(canvas, {
    type: 'line',
    data: {
      labels: labels,
      datasets: [
        {
          label: 'Vittoria (€0)',
          data: vittorie,
          borderColor: '#e8ff00',
          backgroundColor: '#e8ff0022',
          borderWidth: 2,
          tension: 0.3
        },
        {
          label: 'Pareggio',
          data: pareggi,
          borderColor: '#666680',
          backgroundColor: '#66668022',
          borderWidth: 2,
          tension: 0.3
        },
        {
          label: 'Sconfitta',
          data: sconfitte,
          borderColor: '#ff3cac',
          backgroundColor: '#ff3cac22',
          borderWidth: 2,
          tension: 0.3
        }
      ]
    },
    options: {
      responsive: true,
      maintainAspectRatio: false,
      plugins: {
        legend: {
          display: true,
          position: 'top',
          labels: { color: '#e8e8f0', font: { size: 11 } }
        },
        tooltip: {
          backgroundColor: 'rgba(10,10,15,0.95)',
          titleColor: '#e8ff00',
          bodyColor: '#e8e8f0',
          callbacks: {
            label: function(context) {
              return context.dataset.label + ': €' + context.parsed.y.toFixed(2);
            }
          }
        }
      },
      scales: {
        y: {
          ticks: { 
            color: '#666680',
            callback: function(value) {
              return '€' + value;
            }
          },
          grid: { color: '#2a2a44' }
        },
        x: {
          ticks: { color: '#666680' },
          grid: { color: '#2a2a44' }
        }
      }
    }
  });
}

// ── FILTRI PERIODO (STUB) ────────────────────

function setPeriod(period, btn) {
  console.log('setPeriod:', period, '- funzione non implementata in questa versione');
  // Rimuovi active da tutti i bottoni
  document.querySelectorAll('.period-btn').forEach(b => b.classList.remove('active'));
  // Aggiungi active al bottone cliccato
  if (btn) btn.classList.add('active');
}

// ── INIT ─────────────────────────────────────

document.addEventListener('DOMContentLoaded', loadStats);
