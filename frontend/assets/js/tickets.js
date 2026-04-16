// ============================================
//  tickets.js — Sistema Ticket Professionale
// ============================================

const TICKETS_API   = '/api/tickets.php';
const TEMPLATES_API = '/api/ticket-templates.php';

let allTickets  = [];
let replyingId  = null;
let _templates  = [];   // cache template per il modal

// ── Labels & colori ───────────────────────────

const STATUS_LABEL = {
  nuovo:          '🟡 Nuovo',
  in_lavorazione: '🔵 In Lavorazione',
  risolto:        '🟢 Risolto',
  chiuso:         '⚪ Chiuso',
};
const STATUS_COLOR = {
  nuovo:          '#f59e0b',
  in_lavorazione: '#00e5ff',
  risolto:        '#e8ff00',
  chiuso:         '#666680',
};
const CATEGORY_LABEL = {
  bug:          '🐛 Bug',
  suggerimento: '💡 Suggerimento',
  domanda:      '❓ Domanda',
  funzionalita: '⚙️ Funzionalità',
  feature:      '✨ Feature',
  correction:   '✏️ Correzione',
  altro:        '💬 Altro',
};
const PRIORITY_LABEL = { alta: '🔴 Alta', media: '🟡 Media', bassa: '🟢 Bassa' };
const PRIORITY_COLOR = { alta: '#ff3cac', media: '#f59e0b', bassa: '#4ade80' };

// ── INIT ─────────────────────────────────────

document.addEventListener('DOMContentLoaded', function () {
  setTimeout(initTicketsPage, 400);
});

function initTicketsPage() {
  document.getElementById('publicView').style.display = 'block';
  if (window.isLoggedIn) {
    document.getElementById('adminView').style.display = 'block';
    loadTicketStats();
    loadAllTickets();
    loadTemplatesForModal();
  }
}

// ── ALLEGATO PREVIEW ─────────────────────────

function handleAttachmentPreview(input) {
  var preview = document.getElementById('attachmentPreview');
  if (!preview) return;
  if (input.files && input.files[0]) {
    var f    = input.files[0];
    var size = (f.size / 1024 / 1024).toFixed(2);
    preview.innerHTML =
      '📎 <strong>' + escHtml(f.name) + '</strong> (' + size + ' MB) ' +
      '<span style="cursor:pointer;color:var(--neon2)" onclick="clearAttachment()">✕</span>';
  } else {
    preview.innerHTML = '';
  }
}

function clearAttachment() {
  var inp = document.getElementById('newAttachment');
  if (inp) inp.value = '';
  var pr = document.getElementById('attachmentPreview');
  if (pr) pr.innerHTML = '';
}

// ── INVIA TICKET (pubblico) ──────────────────

async function submitNewTicket() {
  var title    = (document.getElementById('newTitle')?.value    || '').trim();
  var desc     = (document.getElementById('newDesc')?.value     || '').trim();
  var category = document.getElementById('newCategory')?.value  || 'altro';
  var name     = (document.getElementById('newName')?.value     || '').trim();
  var email    = (document.getElementById('newEmail')?.value    || '').trim();
  var fileInp  = document.getElementById('newAttachment');

  if (!title) { showToast('Il titolo è obbligatorio', 'error'); document.getElementById('newTitle').focus(); return; }
  if (!desc)  { showToast('La descrizione è obbligatoria', 'error'); document.getElementById('newDesc').focus(); return; }

  var btn = document.getElementById('btnSubmitTicket');
  btn.disabled = true; btn.textContent = 'Invio…';

  try {
    var formData = new FormData();
    formData.append('title',       title);
    formData.append('description', desc);
    formData.append('category',    category);
    if (name)  formData.append('name',  name);
    if (email) formData.append('email', email);
    if (fileInp && fileInp.files[0]) formData.append('attachment', fileInp.files[0]);

    var res  = await fetch(TICKETS_API, { method: 'POST', body: formData });
    var data = await res.json();

    if (data.success) {
      document.getElementById('newTitle').value    = '';
      document.getElementById('newDesc').value     = '';
      document.getElementById('newName').value     = '';
      document.getElementById('newEmail').value    = '';
      document.getElementById('newCategory').value = 'bug';
      clearAttachment();
      showTicketConfirm(data.ticket_number || data.id);
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch (e) {
    showToast('Errore di connessione', 'error');
  }

  btn.disabled = false; btn.textContent = '🎫 Invia';
}

function showTicketConfirm(num) {
  var box = document.getElementById('ticketConfirmBox');
  var el  = document.getElementById('ticketConfirmNumber');
  el.textContent = '#' + num;
  box.style.display = 'block';
  box.scrollIntoView({ behavior: 'smooth', block: 'center' });
}
function hideTicketConfirm() {
  document.getElementById('ticketConfirmBox').style.display = 'none';
}

// ── CERCA TICKET (pubblico) ──────────────────

async function searchTicket() {
  var val = document.getElementById('searchTicketId').value.trim().replace('#', '');
  var id  = parseInt(val);
  if (!id) { showToast('Inserisci un numero valido', 'error'); return; }
  try {
    var data = await fetch(TICKETS_API + '?id=' + id).then(r => r.json());
    if (data.error) { showToast('Ticket #' + id + ' non trovato', 'error'); return; }
    renderSingleTicket(data);
  } catch (e) { showToast('Errore di connessione', 'error'); }
}

function renderSingleTicket(t) {
  var sc   = STATUS_COLOR[t.status]  || '#666680';
  var date = new Date(t.created_at).toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' });
  var reply = (t.reply || t.admin_reply) ? `
    <div style="margin-top:0.7rem;padding:0.6rem 0.8rem;background:rgba(232,255,0,0.05);border-left:3px solid #e8ff00;border-radius:0 4px 4px 0">
      <div style="font-family:'Share Tech Mono',monospace;font-size:0.58rem;color:#e8ff00;letter-spacing:0.1em;margin-bottom:0.2rem">RISPOSTA ADMIN</div>
      <div style="font-size:0.85rem;line-height:1.5">${escHtml(t.reply || t.admin_reply)}</div>
    </div>` : `
    <div style="margin-top:0.6rem;font-family:'Share Tech Mono',monospace;font-size:0.7rem;color:var(--text-muted)">
      Nessuna risposta ancora — ricontrolla più tardi.
    </div>`;

  var cat = CATEGORY_LABEL[t.category || t.type] || (t.category || t.type || '');
  var pri = t.priority ? `<span style="font-size:0.62rem;color:${PRIORITY_COLOR[t.priority] || '#888'}">${PRIORITY_LABEL[t.priority] || t.priority}</span>` : '';

  document.getElementById('searchResult').innerHTML = `
    <div style="background:var(--surface);border:1px solid var(--border);border-left:3px solid ${sc};border-radius:8px;padding:1rem 1.2rem;margin-top:1rem">
      <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap;margin-bottom:0.4rem">
        <span style="font-family:'Share Tech Mono',monospace;font-size:0.7rem;color:var(--text-muted)">#${t.ticket_number || t.id}</span>
        ${cat ? `<span style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;background:var(--surface2);padding:0.15rem 0.4rem;border-radius:3px">${escHtml(cat)}</span>` : ''}
        <span style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:${sc}">${STATUS_LABEL[t.status] || t.status}</span>
        ${pri}
        <span style="font-family:'Share Tech Mono',monospace;font-size:0.6rem;color:var(--text-muted);margin-left:auto">${date}</span>
      </div>
      ${t.title ? `<div style="font-weight:600;margin-bottom:0.3rem">${escHtml(t.title)}</div>` : ''}
      <div style="font-size:0.88rem;line-height:1.5">${escHtml(t.description)}</div>
      ${reply}
    </div>`;
}

// ── DASHBOARD STATS (admin) ──────────────────

async function loadTicketStats() {
  try {
    var data = await authFetch(TICKETS_API + '?stats').then(r => r.json());
    var el   = document.getElementById('ticketDashboard');
    if (!el) return;

    el.innerHTML = `
      <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:0.8rem;margin-bottom:1.2rem">
        <div style="background:var(--surface);border:1px solid var(--neon2);border-radius:10px;padding:1.2rem;text-align:center">
          <div style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:var(--text-muted);letter-spacing:0.1em">TOTALE</div>
          <div style="font-size:2rem;font-weight:700;color:var(--neon2)">${data.total || 0}</div>
        </div>
        <div style="background:var(--surface);border:1px solid #ff3cac;border-radius:10px;padding:1.2rem;text-align:center">
          <div style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:var(--text-muted);letter-spacing:0.1em">APERTI</div>
          <div style="font-size:2rem;font-weight:700;color:#ff3cac">${data.open || 0}</div>
        </div>
        <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:1.2rem;text-align:center">
          <div style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:var(--text-muted);letter-spacing:0.1em">OGGI</div>
          <div style="font-size:2rem;font-weight:700">${data.today || 0}</div>
        </div>
      </div>
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:0.8rem">
        ${_miniStatCard('Per Stato',    data.by_status,   STATUS_LABEL,   STATUS_COLOR)}
        ${_miniStatCard('Per Categoria', data.by_category, CATEGORY_LABEL, {})}
        ${_miniStatCard('Per Priorità', data.by_priority, PRIORITY_LABEL, PRIORITY_COLOR)}
      </div>`;
  } catch (e) {
    console.error('Errore stats ticket:', e);
  }
}

function _miniStatCard(title, data, labels, colors) {
  if (!data || !Object.keys(data).length) return '';
  var rows = Object.entries(data).map(([k, v]) => {
    var c = colors[k] ? `color:${colors[k]}` : '';
    return `<div style="display:flex;justify-content:space-between;align-items:center;padding:0.2rem 0;font-size:0.82rem">
      <span style="${c}">${labels[k] || k}</span>
      <strong>${v}</strong>
    </div>`;
  }).join('');
  return `<div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:1rem">
    <div style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:var(--text-muted);letter-spacing:0.1em;margin-bottom:0.6rem">${title.toUpperCase()}</div>
    ${rows}
  </div>`;
}

// ── CARICA TUTTI I TICKET (admin) ─────────────

async function loadAllTickets() {
  try {
    var data   = await authFetch(TICKETS_API).then(r => r.json());
    allTickets = data.tickets || [];
    renderAdminTickets(allTickets);
  } catch (e) {
    var el = document.getElementById('adminTicketsList');
    if (el) el.innerHTML = '<div style="padding:2rem;text-align:center;color:var(--neon2)">Errore nel caricamento</div>';
  }
}

// ── FILTRI ────────────────────────────────────

async function applyTicketFilters() {
  var status   = document.getElementById('filterStatus')?.value   || 'all';
  var category = document.getElementById('filterCategory')?.value || 'all';
  var priority = document.getElementById('filterPriority')?.value || 'all';
  var search   = (document.getElementById('filterSearch')?.value  || '').trim();

  var url = TICKETS_API + '?_=1';
  if (status   !== 'all') url += '&status='   + encodeURIComponent(status);
  if (category !== 'all') url += '&category=' + encodeURIComponent(category);
  if (priority !== 'all') url += '&priority=' + encodeURIComponent(priority);
  if (search)              url += '&search='  + encodeURIComponent(search);

  try {
    var data   = await authFetch(url).then(r => r.json());
    allTickets = data.tickets || [];
    renderAdminTickets(allTickets);
  } catch (e) { console.error('Errore filtri:', e); }
}

// ── RENDER LISTA ADMIN ────────────────────────

function renderAdminTickets(tickets) {
  var container = document.getElementById('adminTicketsList');
  if (!container) return;

  if (!tickets.length) {
    container.innerHTML = '<div style="padding:2rem;text-align:center;color:var(--text-muted);font-family:\'Share Tech Mono\',monospace;font-size:0.8rem">Nessun ticket trovato</div>';
    return;
  }

  container.innerHTML = tickets.map(function (t) {
    var sc   = STATUS_COLOR[t.status]   || '#666680';
    var pc   = PRIORITY_COLOR[t.priority] || '#888';
    var cat  = CATEGORY_LABEL[t.category || t.type] || (t.category || t.type || '');
    var date = new Date(t.created_at).toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' });
    var replyHtml = (t.reply || t.admin_reply) ? `
      <div style="margin-top:0.5rem;padding:0.5rem 0.7rem;background:rgba(232,255,0,0.05);border-left:3px solid #e8ff00;border-radius:0 4px 4px 0;font-size:0.82rem">
        <span style="font-family:'Share Tech Mono',monospace;font-size:0.58rem;color:#e8ff00">RISPOSTA: </span>
        ${escHtml((t.reply || t.admin_reply).substring(0, 120))}${(t.reply || t.admin_reply).length > 120 ? '…' : ''}
      </div>` : '';

    return `
      <div style="background:var(--surface);border:1px solid var(--border);border-left:3px solid ${sc};border-radius:8px;padding:0.9rem 1.1rem;margin-bottom:0.5rem">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:0.6rem;flex-wrap:wrap">
          <div style="display:flex;align-items:center;gap:0.4rem;flex-wrap:wrap">
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:var(--text-muted)">#${t.ticket_number || t.id}</span>
            ${cat ? `<span style="font-family:'Share Tech Mono',monospace;font-size:0.6rem;background:var(--surface2);padding:0.1rem 0.4rem;border-radius:3px">${escHtml(cat)}</span>` : ''}
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.62rem;color:${sc}">${STATUS_LABEL[t.status] || t.status}</span>
            ${t.priority ? `<span style="font-size:0.62rem;color:${pc}">${PRIORITY_LABEL[t.priority] || t.priority}</span>` : ''}
            ${t.name ? `<span style="font-size:0.78rem;color:var(--text-muted)">da <strong style="color:var(--text)">${escHtml(t.name)}</strong></span>` : '<span style="font-size:0.75rem;color:var(--text-muted)">Anonimo</span>'}
            ${t.user_email ? `<span style="font-size:0.72rem;color:var(--text-muted)">(${escHtml(t.user_email)})</span>` : ''}
          </div>
          <div style="display:flex;align-items:center;gap:0.5rem">
            ${t.attachment_url ? `<a href="${t.attachment_url}" target="_blank" style="font-size:0.75rem;color:var(--neon2)">📎</a>` : ''}
            <span style="font-family:'Share Tech Mono',monospace;font-size:0.6rem;color:var(--text-muted)">${date}</span>
            <button onclick="openReplyModal(${t.id})" class="btn-secondary" style="padding:0.25rem 0.6rem;font-size:0.7rem">✏️ Gestisci</button>
          </div>
        </div>
        ${t.title ? `<div style="font-weight:600;font-size:0.9rem;margin-top:0.4rem">${escHtml(t.title)}</div>` : ''}
        <div style="margin-top:0.3rem;font-size:0.85rem;line-height:1.5;color:var(--text-muted)">${escHtml(t.description.substring(0, 200))}${t.description.length > 200 ? '…' : ''}</div>
        ${replyHtml}
      </div>`;
  }).join('');
}

// ── TEMPLATE MODAL ────────────────────────────

async function loadTemplatesForModal() {
  try {
    var data = await authFetch(TEMPLATES_API).then(r => r.json());
    _templates = data.templates || [];

    var sel = document.getElementById('replyTemplate');
    if (!sel) return;

    // Svuota e ripopola
    sel.innerHTML = '<option value="">— Seleziona template —</option>';
    _templates.forEach(function (t) {
      var opt = document.createElement('option');
      opt.value           = t.id;
      opt.textContent     = t.title;
      opt.dataset.content = t.content;
      sel.appendChild(opt);
    });
  } catch (e) { /* template opzionali */ }
}

function applyReplyTemplate() {
  var sel = document.getElementById('replyTemplate');
  if (!sel) return;
  var opt = sel.options[sel.selectedIndex];
  if (opt && opt.dataset.content) {
    document.getElementById('replyText').value = opt.dataset.content;
  }
}

// ── MODAL RISPOSTA ────────────────────────────

function openReplyModal(id) {
  var t = allTickets.find(function (x) { return x.id === id; });
  if (!t) return;
  replyingId = id;

  var cat = CATEGORY_LABEL[t.category || t.type] || (t.category || t.type || '');
  document.getElementById('replyModalTitle').textContent =
    '📝 Ticket #' + (t.ticket_number || id) + (cat ? ' — ' + cat : '');

  var info = '';
  if (t.name)       info += `<div style="font-size:0.72rem;color:var(--text-muted)">Da: <strong>${escHtml(t.name)}</strong></div>`;
  if (t.user_email) info += `<div style="font-size:0.72rem;color:var(--text-muted)">Email: ${escHtml(t.user_email)}</div>`;
  if (t.title)      info += `<div style="font-weight:600;margin:0.3rem 0">${escHtml(t.title)}</div>`;
  info += `<div style="font-size:0.85rem;line-height:1.5">${escHtml(t.description)}</div>`;
  if (t.attachment_url) {
    info += `<div style="margin-top:0.4rem;font-size:0.8rem">📎 <a href="${t.attachment_url}" target="_blank" style="color:var(--neon2)">${escHtml(t.attachment_name || 'Visualizza allegato')}</a></div>`;
  }
  document.getElementById('replyTicketInfo').innerHTML = info;

  document.getElementById('replyStatus').value   = t.status   || 'nuovo';
  document.getElementById('replyPriority').value = t.priority || 'media';
  document.getElementById('replyText').value     = t.reply || t.admin_reply || '';

  // Reset template select
  var sel = document.getElementById('replyTemplate');
  if (sel) sel.value = '';

  document.getElementById('replyOverlay').classList.add('open');
}

function closeReplyModal() {
  document.getElementById('replyOverlay').classList.remove('open');
  replyingId = null;
}

async function submitReply() {
  if (!replyingId) return;
  var status   = document.getElementById('replyStatus').value;
  var priority = document.getElementById('replyPriority').value;
  var reply    = document.getElementById('replyText').value.trim();

  try {
    var res = await authFetch(TICKETS_API, {
      method: 'PUT',
      body: JSON.stringify({ id: replyingId, status, priority, admin_reply: reply })
    });
    var data = await res.json();
    if (data.success) {
      closeReplyModal();
      showToast('Ticket aggiornato!');
      loadAllTickets();
      loadTicketStats();
      if (typeof loadTicketBadge === 'function') loadTicketBadge();
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch (e) { showToast('Errore di connessione', 'error'); }
}

// ── TEMPLATE MANAGER ─────────────────────────

function openTemplateManager() {
  var overlay = document.createElement('div');
  overlay.id  = 'tmplManagerOverlay';
  overlay.className = 'modal-overlay open';
  overlay.onclick = function (e) { if (e.target === overlay) closeTemplateManager(); };

  overlay.innerHTML = `
    <div class="modal" style="max-width:520px">
      <div class="modal-header">
        <div class="modal-title">📝 Template Risposte</div>
        <button class="modal-close" onclick="closeTemplateManager()">✕</button>
      </div>
      <div class="modal-body">
        <div id="tmplList"></div>
        <div id="tmplForm" style="display:none;margin-top:1rem">
          <div class="form-group">
            <label class="form-label">Titolo</label>
            <input type="text" class="form-input" id="tmplTitle" maxlength="100" placeholder="es. Reset Password">
          </div>
          <div class="form-group">
            <label class="form-label">Contenuto</label>
            <textarea class="form-input" id="tmplContent" rows="5" style="resize:vertical"></textarea>
          </div>
          <div style="display:flex;gap:0.5rem">
            <button class="btn-secondary" onclick="hideTmplForm()" style="flex:1">Annulla</button>
            <button class="btn-primary" onclick="saveTemplate()" style="flex:1">Salva</button>
          </div>
        </div>
      </div>
      <div class="modal-footer">
        <button class="btn-secondary" onclick="closeTemplateManager()">Chiudi</button>
        <button class="btn-primary" onclick="showTmplForm()">➕ Nuovo Template</button>
      </div>
    </div>`;

  document.body.appendChild(overlay);
  renderTemplateList();
}

function closeTemplateManager() {
  var el = document.getElementById('tmplManagerOverlay');
  if (el) el.remove();
  loadTemplatesForModal(); // Ricarica nel modal risposta
}

function renderTemplateList() {
  var el = document.getElementById('tmplList');
  if (!el) return;
  if (!_templates.length) {
    el.innerHTML = '<p style="color:var(--text-muted);text-align:center;font-size:0.85rem">Nessun template salvato</p>';
    return;
  }
  el.innerHTML = _templates.map(function (t) {
    var preview = t.content.length > 100 ? t.content.substring(0, 100) + '…' : t.content;
    return `
      <div style="background:var(--surface);border:1px solid var(--border);border-radius:8px;padding:0.8rem;margin-bottom:0.5rem">
        <div style="display:flex;justify-content:space-between;align-items:center">
          <strong style="font-size:0.9rem">${escHtml(t.title)}</strong>
          <button onclick="deleteTemplate(${t.id})" style="background:none;border:none;color:var(--neon2);cursor:pointer;font-size:1rem">🗑️</button>
        </div>
        <div style="color:var(--text-muted);font-size:0.78rem;margin-top:0.3rem;white-space:pre-wrap">${escHtml(preview)}</div>
      </div>`;
  }).join('');
}

function showTmplForm() {
  document.getElementById('tmplForm').style.display = 'block';
  document.getElementById('tmplTitle').focus();
}
function hideTmplForm() {
  document.getElementById('tmplForm').style.display = 'none';
  document.getElementById('tmplTitle').value   = '';
  document.getElementById('tmplContent').value = '';
}

async function saveTemplate() {
  var title   = (document.getElementById('tmplTitle')?.value   || '').trim();
  var content = (document.getElementById('tmplContent')?.value || '').trim();
  if (!title || !content) { showToast('Titolo e contenuto obbligatori', 'error'); return; }

  try {
    var res  = await authFetch(TEMPLATES_API, {
      method: 'POST',
      body: JSON.stringify({ title, content })
    });
    var data = await res.json();
    if (data.success) {
      hideTmplForm();
      showToast('Template salvato!');
      // Aggiorna cache locale
      _templates.push({ id: data.id, title, content });
      renderTemplateList();
    } else {
      showToast(data.error || 'Errore', 'error');
    }
  } catch (e) { showToast('Errore di connessione', 'error'); }
}

async function deleteTemplate(id) {
  if (!confirm('Eliminare questo template?')) return;
  try {
    await authFetch(TEMPLATES_API + '?id=' + id, { method: 'DELETE' });
    _templates = _templates.filter(function (t) { return t.id !== id; });
    renderTemplateList();
    showToast('Template eliminato');
  } catch (e) { showToast('Errore eliminazione', 'error'); }
}

// ── HELPERS ───────────────────────────────────

function escHtml(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

// Enter per cercare ticket (pubblico)
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && document.activeElement?.id === 'searchTicketId') searchTicket();
});
