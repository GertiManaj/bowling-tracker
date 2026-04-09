// ══════════════════════════════════════════
//  security-logs.js — Dashboard Security Logs
// ══════════════════════════════════════════

const SL_API = '/api/security-logs.php';

// ── INJECT MODAL & STYLES ──
document.addEventListener('DOMContentLoaded', function () {

  // Stili iniettati inline — nessun file CSS extra necessario
  const style = document.createElement('style');
  style.textContent = `
    #securityLogsOverlay .sl-toolbar {
      display: flex; gap: 0.5rem; flex-wrap: wrap;
      margin-bottom: 1rem;
    }
    #securityLogsOverlay .sl-select {
      background: var(--card); color: var(--text);
      border: 1px solid var(--border); border-radius: 6px;
      padding: 0.35rem 0.6rem; font-family: 'Share Tech Mono', monospace;
      font-size: 0.65rem; cursor: pointer;
    }
    #securityLogsOverlay .sl-select:focus { outline: 1px solid var(--neon); }
    #securityLogsOverlay .sl-btn-refresh {
      background: transparent; color: var(--neon);
      border: 1px solid var(--neon); border-radius: 6px;
      padding: 0.35rem 0.7rem; font-family: 'Share Tech Mono', monospace;
      font-size: 0.65rem; cursor: pointer; margin-left: auto;
    }
    #securityLogsOverlay .sl-btn-refresh:hover { background: var(--neon); color: #000; }
    #securityLogsOverlay .sl-stats {
      display: flex; gap: 0.5rem; margin-bottom: 1rem; flex-wrap: wrap;
    }
    #securityLogsOverlay .sl-stat-chip {
      display: flex; align-items: center; gap: 0.35rem;
      padding: 0.3rem 0.65rem; border-radius: 20px;
      font-family: 'Share Tech Mono', monospace; font-size: 0.65rem;
      font-weight: 700; letter-spacing: 0.05em;
    }
    .sl-chip-info     { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
    .sl-chip-warning  { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
    .sl-chip-critical { background: rgba(239,68,68,0.15);  color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
    .sl-chip-meta     { background: rgba(255,255,255,0.05); color: var(--text-muted); border: 1px solid var(--border); }
    #securityLogsOverlay .sl-table-wrap {
      max-height: 380px; overflow-y: auto;
      border: 1px solid var(--border); border-radius: 8px;
    }
    #securityLogsOverlay .sl-table {
      width: 100%; border-collapse: collapse;
      font-family: 'Share Tech Mono', monospace; font-size: 0.6rem;
    }
    #securityLogsOverlay .sl-table th {
      position: sticky; top: 0;
      background: var(--card); color: var(--text-muted);
      padding: 0.5rem 0.7rem; text-align: left;
      border-bottom: 1px solid var(--border);
      font-size: 0.58rem; letter-spacing: 0.08em; text-transform: uppercase;
    }
    #securityLogsOverlay .sl-table td {
      padding: 0.45rem 0.7rem;
      border-bottom: 1px solid rgba(255,255,255,0.04);
      vertical-align: top; color: var(--text);
    }
    #securityLogsOverlay .sl-table tr:last-child td { border-bottom: none; }
    #securityLogsOverlay .sl-table tr.sl-row-critical { background: rgba(239,68,68,0.06); }
    #securityLogsOverlay .sl-table tr.sl-row-warning  { background: rgba(245,158,11,0.04); }
    #securityLogsOverlay .sl-badge {
      display: inline-block; padding: 0.15rem 0.45rem;
      border-radius: 4px; font-size: 0.55rem; font-weight: 700;
      letter-spacing: 0.06em; white-space: nowrap;
    }
    .sl-badge-INFO     { background: #1d4ed8; color: #bfdbfe; }
    .sl-badge-WARNING  { background: #92400e; color: #fde68a; }
    .sl-badge-CRITICAL { background: #991b1b; color: #fecaca; }
    #securityLogsOverlay .sl-event-tag {
      display: inline-block; padding: 0.1rem 0.4rem;
      border-radius: 4px; background: rgba(255,255,255,0.07);
      color: var(--text-muted); font-size: 0.57rem;
    }
    #securityLogsOverlay .sl-details-btn {
      background: none; border: none; color: var(--neon);
      cursor: pointer; font-size: 0.6rem; padding: 0; font-family: inherit;
    }
    #securityLogsOverlay .sl-details-row td {
      background: var(--card-hover, rgba(255,255,255,0.04));
      color: var(--text-muted); font-size: 0.58rem;
      padding: 0.35rem 0.7rem; white-space: pre-wrap; word-break: break-all;
    }
    #securityLogsOverlay .sl-empty {
      text-align: center; padding: 2rem;
      font-family: 'Share Tech Mono', monospace;
      font-size: 0.65rem; color: var(--text-muted);
    }
    #securityLogsOverlay .sl-auto-badge {
      font-family: 'Share Tech Mono', monospace; font-size: 0.55rem;
      color: var(--text-muted); margin-left: 0.5rem;
    }
  `;
  document.head.appendChild(style);

  // Modal
  const modal = document.createElement('div');
  modal.id = 'securityLogsOverlay';
  modal.className = 'login-modal-overlay';
  modal.setAttribute('onclick', 'handleSLOverlayClick(event)');
  modal.innerHTML =
    '<div class="login-modal" style="width:820px;max-width:97vw">' +
      '<div class="login-modal-header">' +
        '<div>' +
          '<div class="login-modal-title" style="font-size:1.3rem">🔒 SECURITY LOGS</div>' +
          '<div class="login-modal-subtitle">Attività di sicurezza e accessi<span id="slAutoLabel" class="sl-auto-badge"></span></div>' +
        '</div>' +
        '<button class="modal-close" onclick="closeSecurityLogsModal()">✕</button>' +
      '</div>' +

      '<div id="slStats" class="sl-stats"></div>' +

      '<div class="sl-toolbar">' +
        '<select id="slSeverity" class="sl-select" onchange="loadSecurityLogs()">' +
          '<option value="">Tutte le severity</option>' +
          '<option value="CRITICAL">CRITICAL</option>' +
          '<option value="WARNING">WARNING</option>' +
          '<option value="INFO">INFO</option>' +
        '</select>' +
        '<select id="slEventType" class="sl-select" onchange="loadSecurityLogs()">' +
          '<option value="">Tutti gli eventi</option>' +
        '</select>' +
        '<select id="slDays" class="sl-select" onchange="loadSecurityLogs()">' +
          '<option value="1">Ultime 24h</option>' +
          '<option value="7" selected>Ultimi 7 giorni</option>' +
          '<option value="30">Ultimi 30 giorni</option>' +
          '<option value="90">Ultimi 90 giorni</option>' +
        '</select>' +
        '<button class="sl-btn-refresh" onclick="loadSecurityLogs()">⟳ Aggiorna</button>' +
      '</div>' +

      '<div id="slError" class="login-error" style="display:none"></div>' +
      '<div id="slLoading" style="text-align:center;padding:1.5rem;font-family:\'Share Tech Mono\',monospace;font-size:0.65rem;color:var(--text-muted)">Caricamento...</div>' +
      '<div id="slContent"></div>' +

      '<button class="login-btn-secondary" onclick="closeSecurityLogsModal()" style="margin-top:1rem">Chiudi</button>' +
    '</div>';
  document.body.appendChild(modal);
});

// ── OPEN / CLOSE ──
function openSecurityLogsModal() {
  const overlay = document.getElementById('securityLogsOverlay');
  if (!overlay) return;
  overlay.classList.add('open');
  loadSecurityLogs();
  startSLAutoRefresh();
}

function closeSecurityLogsModal() {
  const overlay = document.getElementById('securityLogsOverlay');
  if (overlay) overlay.classList.remove('open');
  stopSLAutoRefresh();
}

function handleSLOverlayClick(e) {
  if (e.target.id === 'securityLogsOverlay') closeSecurityLogsModal();
}

// ── AUTO-REFRESH ──
let _slRefreshTimer = null;

function startSLAutoRefresh() {
  stopSLAutoRefresh();
  _slRefreshTimer = setInterval(function () {
    const overlay = document.getElementById('securityLogsOverlay');
    if (!overlay || !overlay.classList.contains('open')) { stopSLAutoRefresh(); return; }
    loadSecurityLogs(true); // silent = true
  }, 30000);
  updateSLAutoLabel(30);
}

function stopSLAutoRefresh() {
  if (_slRefreshTimer) { clearInterval(_slRefreshTimer); _slRefreshTimer = null; }
  const lbl = document.getElementById('slAutoLabel');
  if (lbl) lbl.textContent = '';
}

function updateSLAutoLabel(seconds) {
  const lbl = document.getElementById('slAutoLabel');
  if (lbl) lbl.textContent = '  · aggiorn. ogni ' + seconds + 's';
}

// ── LOAD LOGS ──
async function loadSecurityLogs(silent = false) {
  const loadingEl = document.getElementById('slLoading');
  const errorEl   = document.getElementById('slError');
  const contentEl = document.getElementById('slContent');
  const statsEl   = document.getElementById('slStats');

  if (!silent) {
    if (loadingEl) loadingEl.style.display = 'block';
    if (contentEl) contentEl.innerHTML = '';
    if (errorEl)   errorEl.style.display = 'none';
  }

  const token = localStorage.getItem('sz_auth_token');
  if (!token) {
    if (loadingEl) loadingEl.style.display = 'none';
    if (errorEl)   { errorEl.textContent = 'Devi essere autenticato'; errorEl.style.display = 'block'; }
    return;
  }

  const severity  = document.getElementById('slSeverity')?.value  || '';
  const eventType = document.getElementById('slEventType')?.value  || '';
  const days      = document.getElementById('slDays')?.value       || 7;

  let url = SL_API + '?days=' + days + '&limit=200';
  if (severity)  url += '&severity='   + encodeURIComponent(severity);
  if (eventType) url += '&event_type=' + encodeURIComponent(eventType);

  try {
    const res  = await fetch(url, { headers: { 'Authorization': 'Bearer ' + token } });
    const data = await res.json();

    if (loadingEl) loadingEl.style.display = 'none';

    if (!data.success) {
      if (errorEl) { errorEl.textContent = data.error || 'Errore caricamento'; errorEl.style.display = 'block'; }
      return;
    }

    // Aggiorna event_type select
    updateEventTypeSelect(data.event_types || [], eventType);

    // Statistiche
    if (statsEl) statsEl.innerHTML = renderSLStats(data);

    // Tabella log
    if (contentEl) contentEl.innerHTML = renderSLTable(data.logs || []);

  } catch (err) {
    if (loadingEl) loadingEl.style.display = 'none';
    if (errorEl)   { errorEl.textContent = 'Errore di connessione'; errorEl.style.display = 'block'; }
  }
}

function updateEventTypeSelect(types, currentVal) {
  const sel = document.getElementById('slEventType');
  if (!sel) return;
  const prev = currentVal || sel.value;
  sel.innerHTML = '<option value="">Tutti gli eventi</option>';
  types.forEach(function (t) {
    const opt = document.createElement('option');
    opt.value = t;
    opt.textContent = t;
    if (t === prev) opt.selected = true;
    sel.appendChild(opt);
  });
}

// ── RENDER STATS ──
function renderSLStats(data) {
  const s = data.stats || {};
  const days = document.getElementById('slDays')?.value || 7;
  return (
    '<div class="sl-stat-chip sl-chip-critical">🔴 CRITICAL: ' + (s.CRITICAL || 0) + '</div>' +
    '<div class="sl-stat-chip sl-chip-warning">🟡 WARNING: '  + (s.WARNING  || 0) + '</div>' +
    '<div class="sl-stat-chip sl-chip-info">🔵 INFO: '        + (s.INFO     || 0) + '</div>' +
    '<div class="sl-stat-chip sl-chip-meta">24h CRITICAL: '   + (data.critical_last24h || 0) + '</div>' +
    '<div class="sl-stat-chip sl-chip-meta">IP univoci: '     + (data.unique_ips || 0) + '</div>'
  );
}

// ── RENDER TABLE ──
function renderSLTable(logs) {
  if (!logs.length) {
    return '<div class="sl-empty">Nessun evento trovato per il periodo selezionato.</div>';
  }

  const rows = logs.map(function (log, idx) {
    const sev      = escSL(log.severity);
    const rowClass = sev === 'CRITICAL' ? 'sl-row-critical' : sev === 'WARNING' ? 'sl-row-warning' : '';
    const dt       = log.created_at ? new Date(log.created_at).toLocaleString('it-IT', { day:'2-digit', month:'2-digit', hour:'2-digit', minute:'2-digit', second:'2-digit' }) : '—';
    const admin    = log.admin_name ? escSL(log.admin_name) : (log.admin_email ? escSL(log.admin_email) : '—');
    const ip       = log.ip_address ? escSL(log.ip_address) : '—';
    const detailsRaw = log.details || '{}';

    return (
      '<tr class="' + rowClass + '">' +
        '<td><span class="sl-badge sl-badge-' + sev + '">' + sev + '</span></td>' +
        '<td><span class="sl-event-tag">' + escSL(log.event_type) + '</span></td>' +
        '<td>' + dt + '</td>' +
        '<td>' + admin + '</td>' +
        '<td>' + ip + '</td>' +
        '<td><button class="sl-details-btn" onclick="toggleSLDetails(' + idx + ')">+ dettagli</button></td>' +
      '</tr>' +
      '<tr id="sl-det-' + idx + '" class="sl-details-row" style="display:none">' +
        '<td colspan="6">' + formatSLDetails(detailsRaw) + '</td>' +
      '</tr>'
    );
  }).join('');

  return (
    '<div class="sl-table-wrap">' +
      '<table class="sl-table">' +
        '<thead><tr>' +
          '<th>Severity</th><th>Evento</th><th>Data/Ora</th><th>Admin</th><th>IP</th><th></th>' +
        '</tr></thead>' +
        '<tbody>' + rows + '</tbody>' +
      '</table>' +
    '</div>'
  );
}

function toggleSLDetails(idx) {
  const row = document.getElementById('sl-det-' + idx);
  if (!row) return;
  const isHidden = row.style.display === 'none';
  row.style.display = isHidden ? 'table-row' : 'none';
  const btn = row.previousElementSibling?.querySelector('.sl-details-btn');
  if (btn) btn.textContent = isHidden ? '− dettagli' : '+ dettagli';
}

function formatSLDetails(raw) {
  try {
    const obj = typeof raw === 'string' ? JSON.parse(raw) : raw;
    if (!obj || !Object.keys(obj).length) return '<em>nessun dettaglio</em>';
    return Object.entries(obj).map(function ([k, v]) {
      return '<strong>' + escSL(k) + ':</strong> ' + escSL(String(v));
    }).join('  |  ');
  } catch (e) {
    return escSL(String(raw));
  }
}

// ── UTILITY ──
function escSL(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

console.log('✅ security-logs.js caricato');
