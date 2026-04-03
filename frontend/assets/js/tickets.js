// ============================================
//  tickets.js — Gestione ticket & feedback
// ============================================

const TICKETS_API = '/api/tickets.php';
let allTickets    = [];
let currentFilter = 'all';
let replyingId    = null;

const STATUS_LABEL = {
  open:        '🔴 Aperto',
  in_progress: '🟡 In lavorazione',
  resolved:    '🟢 Risolto',
  rejected:    '⚫ Rifiutato',
};
const TYPE_LABEL = {
  bug:        '🐛 Bug',
  feature:    '✨ Feature',
  correction: '✏️ Correzione',
};
const STATUS_COLOR = {
  open:        'var(--neon2)',
  in_progress: '#f59e0b',
  resolved:    'var(--neon)',
  rejected:    '#666680',
};

// ── CARICA ───────────────────────────────────
async function loadTickets() {
  try {
    const data = await fetch(TICKETS_API).then(r => r.json());
    allTickets = data.tickets || [];
    renderTickets();
  } catch(e) {
    document.getElementById('ticketsList').innerHTML =
      '<div style="padding:2rem;text-align:center;color:var(--neon2)">Errore nel caricamento</div>';
  }
}

// ── RENDER ───────────────────────────────────
function renderTickets() {
  const isAdmin = window.isLoggedIn || false;
  const filtered = currentFilter === 'all'
    ? allTickets
    : allTickets.filter(t => t.status === currentFilter);

  const container = document.getElementById('ticketsList');

  if (!filtered.length) {
    container.innerHTML = '<div style="padding:2rem;text-align:center;color:var(--text-muted);font-family:\'Share Tech Mono\',monospace;font-size:0.8rem">Nessun ticket trovato</div>';
    return;
  }

  container.innerHTML = filtered.map(t => {
    const statusColor = STATUS_COLOR[t.status] || '#666680';
    const date = new Date(t.created_at).toLocaleDateString('it-IT', { day:'2-digit', month:'short', year:'numeric' });
    const replySection = t.reply ? `
      <div style="margin-top:0.8rem;padding:0.7rem;background:rgba(232,255,0,0.05);border-left:3px solid var(--neon);border-radius:0 4px 4px 0">
        <div style="font-family:'Share Tech Mono',monospace;font-size:0.6rem;color:var(--neon);letter-spacing:0.1em;margin-bottom:0.3rem">RISPOSTA ADMIN</div>
        <div style="font-size:0.85rem">${t.reply}</div>
      </div>` : '';

    const adminBtn = isAdmin ? `
      <button onclick="openReplyModal(${t.id})" style="
        background:none;border:1px solid var(--border);color:var(--text-muted);
        padding:0.3rem 0.7rem;border-radius:4px;cursor:pointer;
        font-family:'Share Tech Mono',monospace;font-size:0.65rem;
        letter-spacing:0.08em;transition:all 0.2s
      " onmouseover="this.style.borderColor='var(--neon)';this.style.color='var(--neon)'"
         onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text-muted)'">
        ✏️ Gestisci
      </button>` : '';

    return `
      <div style="
        background:var(--surface);border:1px solid var(--border);
        border-radius:8px;padding:1rem 1.2rem;
        border-left:3px solid ${statusColor};
        transition:background 0.15s
      ">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:1rem;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap">
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.65rem;background:var(--surface2);padding:0.2rem 0.5rem;border-radius:4px">${TYPE_LABEL[t.type]||t.type}</span>
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.65rem;color:${statusColor}">${STATUS_LABEL[t.status]||t.status}</span>
            ${t.name ? `<span style="font-size:0.8rem;color:var(--text-muted)">da <strong style="color:var(--text)">${t.name}</strong></span>` : '<span style="font-size:0.75rem;color:var(--text-muted)">Anonimo</span>'}
          </div>
          <div style="display:flex;align-items:center;gap:0.8rem">
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:var(--text-muted)">${date}</span>
            ${adminBtn}
          </div>
        </div>
        <div style="margin-top:0.6rem;font-size:0.9rem;line-height:1.5">${t.description}</div>
        ${replySection}
      </div>`;
  }).join('');
}

// ── FILTRO ───────────────────────────────────
function filterTickets(status, btn) {
  currentFilter = status;
  document.querySelectorAll('#filterBtns .period-btn').forEach(b => b.classList.remove('active'));
  btn.classList.add('active');
  renderTickets();
}

// ── MODAL RISPOSTA ADMIN ─────────────────────
function openReplyModal(id) {
  if (!window.isLoggedIn) { showToast('Devi essere admin', 'error'); return; }
  const t = allTickets.find(x => x.id === id);
  if (!t) return;
  replyingId = id;
  document.getElementById('replyModalTitle').textContent = `📝 Ticket #${id} — ${TYPE_LABEL[t.type]||t.type}`;
  document.getElementById('replyTicketInfo').innerHTML = `
    <div style="font-size:0.75rem;color:var(--text-muted);margin-bottom:0.3rem">${t.name ? 'Da: '+t.name : 'Anonimo'}</div>
    <div>${t.description}</div>`;
  document.getElementById('replyStatus').value = t.status;
  document.getElementById('replyText').value   = t.reply || '';
  document.getElementById('replyOverlay').classList.add('open');
}

function closeReplyModal() {
  document.getElementById('replyOverlay').classList.remove('open');
  replyingId = null;
}

async function submitReply() {
  if (!replyingId) return;
  const status = document.getElementById('replyStatus').value;
  const reply  = document.getElementById('replyText').value.trim();
  try {
    const res  = await fetch(TICKETS_API, {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: replyingId, status, reply })
    });
    const data = await res.json();
    if (data.success) {
      closeReplyModal();
      showToast('Ticket aggiornato!');
      loadTickets();
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch(e) { showToast('Errore di connessione', 'error'); }
}

// ── MODAL NUOVO TICKET ───────────────────────
function openNewTicket() {
  document.getElementById('newTicketOverlay').classList.add('open');
  setTimeout(() => document.getElementById('newDesc').focus(), 100);
}

function closeNewTicket() {
  document.getElementById('newTicketOverlay').classList.remove('open');
  document.getElementById('newDesc').value = '';
  document.getElementById('newName').value = '';
  document.getElementById('newType').value = 'bug';
}

async function submitNewTicket() {
  const type = document.getElementById('newType').value;
  const name = document.getElementById('newName').value.trim();
  const desc = document.getElementById('newDesc').value.trim();
  if (!desc) { showToast('La descrizione è obbligatoria', 'error'); return; }
  try {
    const res  = await fetch(TICKETS_API, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ type, name, description: desc })
    });
    const data = await res.json();
    if (data.success) {
      closeNewTicket();
      showToast('Ticket inviato! Grazie 🎫');
      loadTickets();
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch(e) { showToast('Errore di connessione', 'error'); }
}

// ── INIT ─────────────────────────────────────
document.addEventListener('DOMContentLoaded', loadTickets);
