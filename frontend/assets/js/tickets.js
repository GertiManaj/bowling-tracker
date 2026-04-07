// ============================================
//  tickets.js — Feedback & Ticket
// ============================================

const TICKETS_API = '/api/tickets.php';
let allTickets = [];
let currentFilter = 'all';
let replyingId = null;

const STATUS_LABEL = {
  open: '🔴 Aperto',
  in_progress: '🟡 In lavorazione',
  resolved: '🟢 Risolto',
  rejected: '⚫ Rifiutato',
};
const STATUS_COLOR = {
  open: '#ff3cac',
  in_progress: '#f59e0b',
  resolved: '#e8ff00',
  rejected: '#666680',
};
const TYPE_LABEL = {
  bug: '🐛 Bug',
  feature: '✨ Feature',
  correction: '✏️ Correzione',
};

// ── INIT ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {
  setTimeout(initTicketsPage, 400);
});

function initTicketsPage() {
  const isAdmin = window.isLoggedIn || false;
  // Vista pubblica sempre visibile (tutti possono aprire ticket e cercarlo)
  document.getElementById('publicView').style.display = 'block';
  // Vista admin visibile solo se loggato
  if (isAdmin) {
    document.getElementById('adminView').style.display = 'block';
    loadAllTickets();
  } else {
    document.getElementById('adminView').style.display = 'none';
  }
}

// ── INVIA TICKET (pubblico) ───────────────────
async function submitNewTicket() {
  const type = document.getElementById('newType').value;
  const name = document.getElementById('newName').value.trim();
  const desc = document.getElementById('newDesc').value.trim();
  if (!desc) { showToast('La descrizione è obbligatoria', 'error'); return; }

  const btn = document.getElementById('btnSubmitTicket');
  btn.disabled = true; btn.textContent = 'Invio...';

  try {
    const res = await fetch(TICKETS_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type, name, description: desc })
    });
    const data = await res.json();
    if (data.success) {
      // Mostra numero ticket in modo evidente
      document.getElementById('newDesc').value = '';
      document.getElementById('newName').value = '';
      document.getElementById('newType').value = 'bug';
      showTicketConfirm(data.id);
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch (e) { showToast('Errore di connessione', 'error'); }

  btn.disabled = false; btn.textContent = '🎫 Invia';
}

function showTicketConfirm(id) {
  const box = document.getElementById('ticketConfirmBox');
  const num = document.getElementById('ticketConfirmNumber');
  num.textContent = '#' + id;
  box.style.display = 'block';
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}

function hideTicketConfirm() {
  document.getElementById('ticketConfirmBox').style.display = 'none';
}

// ── CERCA TICKET PER NUMERO ───────────────────
async function searchTicket() {
  const val = document.getElementById('searchTicketId').value.trim().replace('#', '');
  const id = parseInt(val);
  if (!id) { showToast('Inserisci un numero valido', 'error'); return; }

  try {
    const data = await fetch(`${TICKETS_API}?id=${id}`).then(r => r.json());
    if (data.error) { showToast('Ticket #' + id + ' non trovato', 'error'); return; }
    renderSingleTicket(data);
  } catch (e) { showToast('Errore di connessione', 'error'); }
}

function renderSingleTicket(t) {
  const sc = STATUS_COLOR[t.status] || '#666680';
  const date = new Date(t.created_at).toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' });
  const replyHtml = t.reply ? `
    <div style="margin-top:0.7rem;padding:0.6rem 0.8rem;background:rgba(232,255,0,0.05);border-left:3px solid #e8ff00;border-radius:0 4px 4px 0">
      <div style="font-family:'Share Tech Mono',monospace;font-size:0.58rem;color:#e8ff00;letter-spacing:0.1em;margin-bottom:0.2rem">RISPOSTA ADMIN</div>
      <div style="font-size:0.85rem;line-height:1.5">${t.reply}</div>
    </div>` : `
    <div style="margin-top:0.6rem;font-family:'Share Tech Mono',monospace;font-size:0.7rem;color:var(--text-muted)">
      Nessuna risposta ancora — ricontrolla più tardi.
    </div>`;

  document.getElementById('searchResult').innerHTML = `
    <div style="background:var(--surface);border:1px solid var(--border);border-left:3px solid ${sc};border-radius:8px;padding:1rem 1.2rem;margin-top:1rem">
      <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.5rem">
        <span style="font-family:'Share Tech Mono',monospace;font-size:0.7rem;color:var(--text-muted)">#${t.id}</span>
        <span style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;background:var(--surface2);padding:0.15rem 0.4rem;border-radius:3px">${TYPE_LABEL[t.type] || t.type}</span>
        <span style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:${sc}">${STATUS_LABEL[t.status] || t.status}</span>
        ${t.name ? `<span style="font-size:0.8rem;color:var(--text-muted)">da <strong style="color:var(--text)">${t.name}</strong></span>` : ''}
        <span style="font-family:'Share Tech Mono',monospace;font-size:0.6rem;color:var(--text-muted);margin-left:auto">${date}</span>
      </div>
      <div style="font-size:0.88rem;line-height:1.5">${t.description}</div>
      ${replyHtml}
    </div>`;
}

// ── VISTA ADMIN ───────────────────────────────
async function loadAllTickets() {
  try {
    const data = await fetch(TICKETS_API).then(r => r.json());
    allTickets = data.tickets || [];
    renderAdminTickets();
  } catch (e) {
    document.getElementById('adminTicketsList').innerHTML =
      '<div style="padding:2rem;text-align:center;color:var(--neon2)">Errore nel caricamento</div>';
  }
}

function filterTickets(status, btn) {
  currentFilter = status;
  document.querySelectorAll('#filterBtns .period-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderAdminTickets();
}

function renderAdminTickets() {
  const filtered = currentFilter === 'all'
    ? allTickets
    : allTickets.filter(t => t.status === currentFilter);
  const container = document.getElementById('adminTicketsList');

  if (!filtered.length) {
    container.innerHTML = '<div style="padding:2rem;text-align:center;color:var(--text-muted);font-family:\'Share Tech Mono\',monospace;font-size:0.8rem">Nessun ticket trovato</div>';
    return;
  }

  container.innerHTML = filtered.map(t => {
    const sc = STATUS_COLOR[t.status] || '#666680';
    const date = new Date(t.created_at).toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' });
    const replyHtml = t.reply ? `
      <div style="margin-top:0.6rem;padding:0.6rem 0.8rem;background:rgba(232,255,0,0.05);border-left:3px solid #e8ff00;border-radius:0 4px 4px 0">
        <div style="font-family:'Share Tech Mono',monospace;font-size:0.58rem;color:#e8ff00;letter-spacing:0.1em;margin-bottom:0.2rem">TUA RISPOSTA</div>
        <div style="font-size:0.83rem">${t.reply}</div>
      </div>` : '';
    return `
      <div style="background:var(--surface);border:1px solid var(--border);border-left:3px solid ${sc};border-radius:8px;padding:1rem 1.2rem;margin-bottom:0.6rem">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.8rem;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap">
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.6rem;color:var(--text-muted)">#${t.id}</span>
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;background:var(--surface2);padding:0.15rem 0.4rem;border-radius:3px">${TYPE_LABEL[t.type] || t.type}</span>
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:${sc}">${STATUS_LABEL[t.status] || t.status}</span>
            ${t.name ? `<span style="font-size:0.8rem;color:var(--text-muted)">da <strong style="color:var(--text)">${t.name}</strong></span>` : '<span style="font-size:0.75rem;color:var(--text-muted)">Anonimo</span>'}
          </div>
          <div style="display:flex;align-items:center;gap:0.6rem">
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.6rem;color:var(--text-muted)">${date}</span>
            <button onclick="openReplyModal(${t.id})" class="btn-secondary" style="padding:0.3rem 0.7rem;font-size:0.72rem">✏️ Gestisci</button>
          </div>
        </div>
        <div style="margin-top:0.5rem;font-size:0.88rem;line-height:1.5">${t.description}</div>
        ${replyHtml}
      </div>`;
  }).join('');
}

// ── MODAL RISPOSTA ────────────────────────────
function openReplyModal(id) {
  const t = allTickets.find(x => x.id === id);
  if (!t) return;
  replyingId = id;
  document.getElementById('replyModalTitle').textContent = `📝 Ticket #${id} — ${TYPE_LABEL[t.type] || t.type}`;
  document.getElementById('replyTicketInfo').innerHTML =
    `<div style="font-size:0.72rem;color:var(--text-muted);margin-bottom:0.3rem">${t.name ? 'Da: ' + t.name : 'Anonimo'}</div>` +
    `<div style="font-size:0.88rem;line-height:1.5">${t.description}</div>`;
  document.getElementById('replyStatus').value = t.status;
  document.getElementById('replyText').value = t.reply || '';
  document.getElementById('replyOverlay').classList.add('open');
}

function closeReplyModal() {
  document.getElementById('replyOverlay').classList.remove('open');
  replyingId = null;
}

async function submitReply() {
  if (!replyingId) return;
  const status = document.getElementById('replyStatus').value;
  const reply = document.getElementById('replyText').value.trim();
  try {
    const res = await authFetch(TICKETS_API, {
      method: 'PUT',
      body: JSON.stringify({ id: replyingId, status, reply })
    });
    const data = await res.json();
    if (data.success) {
      closeReplyModal();
      showToast('Ticket aggiornato!');
      loadAllTickets();
      if (typeof loadTicketBadge === 'function') loadTicketBadge();
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch (e) { showToast('Errore di connessione', 'error'); }
}

// Enter per cercare
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && document.activeElement?.id === 'searchTicketId') searchTicket();
});
