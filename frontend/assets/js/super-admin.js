// ============================================
//  super-admin.js — Pannello Super Amministratore
// ============================================

const SA_API = '/api';

// ── STATE ────────────────────────────────────
var saGroups  = [];
var saAdmins  = [];
var editingGroupId = null;
var editingAdminId = null;
var editingRoleId  = null;

// ── INIT ─────────────────────────────────────

document.addEventListener('DOMContentLoaded', function() {
  if (!isSuperAdmin()) {
    document.getElementById('saAccessDenied').style.display = 'block';
    document.getElementById('saContent').style.display = 'none';
    return;
  }
  document.getElementById('saAccessDenied').style.display = 'none';
  document.getElementById('saContent').style.display = 'block';

  loadGroups().then(function() {
    loadAdmins();
    loadAnalytics();
  });
  openTab('groups');
});

// ── TABS ─────────────────────────────────────

function openTab(tab) {
  document.querySelectorAll('.sz-tab-btn').forEach(function(b) { b.classList.remove('active'); });
  document.querySelectorAll('.sz-tab-content').forEach(function(c) { c.classList.remove('active'); });
  var btn     = document.getElementById('tab-btn-' + tab);
  var content = document.getElementById('tab-' + tab);
  if (btn)     btn.classList.add('active');
  if (content) content.classList.add('active');
}

// ── GRUPPI ───────────────────────────────────

async function loadGroups() {
  try {
    const res  = await authFetch(SA_API + '/groups.php');
    const data = await res.json();
    saGroups = data.groups || [];
    renderGroups();
  } catch(e) {
    showToast('Errore caricamento gruppi', 'error');
  }
}

function renderGroups() {
  var container = document.getElementById('groupsGrid');
  if (!container) return;

  if (!saGroups.length) {
    container.innerHTML = '<div style="color:var(--text-muted);font-family:\'Share Tech Mono\',monospace;font-size:0.75rem">Nessun gruppo trovato.</div>';
    return;
  }

  container.innerHTML = saGroups.map(function(g) {
    var inviteHtml = g.invite_code
      ? '<div class="group-card-invite">' +
          '<span style="color:var(--text-muted);font-size:0.6rem;letter-spacing:0.1em;text-transform:uppercase">Codice invito: </span>' +
          '<span style="font-family:\'Share Tech Mono\',monospace;color:var(--neon);letter-spacing:0.15em">' + escHtml(g.invite_code) + '</span>' +
          ' <button class="btn-small" style="padding:0.1rem 0.4rem;font-size:0.65rem" onclick="copyCode(\'' + escHtml(g.invite_code) + '\')">📋</button>' +
        '</div>'
      : '';
    var typeLabel = g.group_type === 'casual' ? '🎳 Casual' : '🏆 Sfide';
    return (
      '<div class="group-card">' +
        '<div class="group-card-name">' + escHtml(g.name) +
          ' <span style="font-size:0.65rem;color:var(--text-muted);font-family:\'Share Tech Mono\',monospace">' + typeLabel + '</span>' +
        '</div>' +
        (g.description ? '<div class="group-card-desc">' + escHtml(g.description) + '</div>' : '') +
        inviteHtml +
        '<div class="group-card-stats">' +
          '<span>' + (g.players_count || 0) + ' giocatori</span>' +
          '<span>' + (g.sessions_count || 0) + ' sessioni</span>' +
          '<span>' + (g.admins_count || 0) + ' admin</span>' +
        '</div>' +
        '<div class="group-card-actions">' +
          '<button class="btn-small" onclick="openEditGroup(' + g.id + ')">✏️ Modifica</button>' +
          '<button class="btn-small danger" onclick="deleteGroup(' + g.id + ')">🗑 Elimina</button>' +
        '</div>' +
      '</div>'
    );
  }).join('');
}

function openAddGroup() {
  editingGroupId = null;
  document.getElementById('groupFormTitle').textContent = 'Nuovo Gruppo';
  document.getElementById('groupName').value = '';
  document.getElementById('groupDesc').value = '';
  document.getElementById('groupType').value = 'challenge';
  document.getElementById('groupFormSection').style.display = 'block';
  document.getElementById('groupName').focus();
}

function openEditGroup(id) {
  var g = saGroups.find(function(x) { return x.id == id; });
  if (!g) return;
  editingGroupId = id;
  document.getElementById('groupFormTitle').textContent = 'Modifica Gruppo — ' + escHtml(g.name);
  document.getElementById('groupName').value = g.name;
  document.getElementById('groupDesc').value = g.description || '';
  document.getElementById('groupType').value = g.group_type || 'challenge';
  document.getElementById('groupFormSection').style.display = 'block';
  document.getElementById('groupName').focus();
}

function cancelGroupForm() {
  document.getElementById('groupFormSection').style.display = 'none';
  editingGroupId = null;
}

async function saveGroup() {
  var name      = document.getElementById('groupName').value.trim();
  var desc      = document.getElementById('groupDesc').value.trim();
  var groupType = document.getElementById('groupType').value;
  if (!name) { showToast('Il nome è obbligatorio', 'error'); return; }

  try {
    var method = editingGroupId ? 'PUT' : 'POST';
    var body   = editingGroupId
      ? JSON.stringify({ id: editingGroupId, name: name, description: desc, group_type: groupType })
      : JSON.stringify({ name: name, description: desc, group_type: groupType });

    const res  = await authFetch(SA_API + '/groups.php', { method: method, body: body });
    const data = await res.json();

    if (data.error) { showToast(data.error, 'error'); return; }
    showToast(editingGroupId ? 'Gruppo aggiornato' : 'Gruppo creato');
    cancelGroupForm();
    await loadGroups();
    loadAnalytics();
  } catch(e) {
    showToast('Errore salvataggio gruppo', 'error');
  }
}

async function deleteGroup(id) {
  var g = saGroups.find(function(x) { return x.id == id; });
  if (!g) return;

  // Step 1: prima conferma semplice
  if (!confirm('Eliminare il gruppo "' + g.name + '"?')) return;

  try {
    // Primo tentativo senza confirm → il server restituisce i conteggi se ci sono dati
    const res1  = await authFetch(SA_API + '/groups.php', { method: 'DELETE', body: JSON.stringify({ id: id }) });
    const data1 = await res1.json();

    if (data1.error) { showToast(data1.error, 'error'); return; }

    // Gruppo vuoto → eliminato direttamente
    if (data1.success) {
      showToast('Gruppo eliminato');
      await loadGroups();
      loadAnalytics();
      return;
    }

    // Il server chiede conferma perché ci sono dati
    if (data1.requires_confirmation) {
      const confirmed = await showDeleteConfirmModal(data1);
      if (!confirmed) return;

      // Step 2: eliminazione confermata
      const res2  = await authFetch(SA_API + '/groups.php', { method: 'DELETE', body: JSON.stringify({ id: id, confirm: true }) });
      const data2 = await res2.json();

      if (data2.error) { showToast(data2.error, 'error'); return; }
      showToast('Gruppo "' + escHtml(data1.group_name) + '" eliminato con tutti i dati');
      await loadGroups();
      loadAnalytics();
    }
  } catch(e) {
    showToast('Errore eliminazione gruppo', 'error');
  }
}

// Mostra modal di conferma con conteggi dati; restituisce Promise<boolean>
function showDeleteConfirmModal(info) {
  return new Promise(function(resolve) {
    // Rimuovi eventuale modal precedente
    var existing = document.getElementById('deleteGroupModal');
    if (existing) existing.remove();

    var overlay = document.createElement('div');
    overlay.id = 'deleteGroupModal';
    overlay.style.cssText = [
      'position:fixed', 'inset:0', 'background:rgba(0,0,0,0.75)',
      'display:flex', 'align-items:center', 'justify-content:center',
      'z-index:99999', 'padding:1rem'
    ].join(';');

    overlay.innerHTML =
      '<div style="background:var(--surface);border:1px solid var(--neon2);border-radius:16px;' +
      'padding:2rem 2rem 1.5rem;max-width:420px;width:100%;box-shadow:0 0 60px rgba(255,60,172,0.3)">' +
        '<div style="font-size:2rem;text-align:center;margin-bottom:0.75rem">⚠️</div>' +
        '<div style="font-family:\'Black Han Sans\',sans-serif;font-size:1.3rem;color:var(--neon2);' +
        'text-align:center;margin-bottom:1rem">ATTENZIONE</div>' +
        '<p style="font-family:\'Barlow Condensed\',sans-serif;font-size:0.95rem;' +
        'color:var(--text);margin-bottom:1rem;text-align:center">' +
          'Il gruppo <strong style="color:var(--neon)">' + escHtml(info.group_name) + '</strong> contiene dati:' +
        '</p>' +
        '<div style="background:var(--surface2);border-radius:8px;padding:1rem;' +
        'margin-bottom:1.25rem;font-family:\'Share Tech Mono\',monospace;font-size:0.8rem">' +
          '<div style="display:flex;justify-content:space-between;margin-bottom:0.4rem">' +
            '<span style="color:var(--text-muted)">Giocatori</span>' +
            '<span style="color:var(--neon2)">' + info.players + '</span>' +
          '</div>' +
          '<div style="display:flex;justify-content:space-between;margin-bottom:0.4rem">' +
            '<span style="color:var(--text-muted)">Sessioni</span>' +
            '<span style="color:var(--neon2)">' + info.sessions + '</span>' +
          '</div>' +
          '<div style="display:flex;justify-content:space-between">' +
            '<span style="color:var(--text-muted)">Punteggi</span>' +
            '<span style="color:var(--neon2)">' + info.scores + '</span>' +
          '</div>' +
        '</div>' +
        '<p style="font-family:\'Barlow Condensed\',sans-serif;font-size:0.85rem;' +
        'color:var(--neon2);text-align:center;margin-bottom:1.5rem;font-weight:700">' +
          'Questa operazione è IRREVERSIBILE. Tutti i dati verranno eliminati definitivamente.' +
        '</p>' +
        '<div style="display:flex;gap:0.75rem">' +
          '<button id="deleteGroupCancel" style="flex:1;padding:0.75rem;background:transparent;' +
          'border:1px solid var(--border);border-radius:8px;color:var(--text-muted);' +
          'font-family:\'Barlow Condensed\',sans-serif;font-size:0.9rem;cursor:pointer;' +
          'letter-spacing:0.1em;text-transform:uppercase">Annulla</button>' +
          '<button id="deleteGroupConfirm" style="flex:1;padding:0.75rem;background:var(--neon2);' +
          'border:none;border-radius:8px;color:#fff;font-family:\'Barlow Condensed\',sans-serif;' +
          'font-weight:700;font-size:0.9rem;cursor:pointer;letter-spacing:0.1em;' +
          'text-transform:uppercase">Elimina Tutto</button>' +
        '</div>' +
      '</div>';

    document.body.appendChild(overlay);

    function close(result) {
      overlay.remove();
      resolve(result);
    }

    document.getElementById('deleteGroupConfirm').addEventListener('click', function() { close(true); });
    document.getElementById('deleteGroupCancel').addEventListener('click', function() { close(false); });
    overlay.addEventListener('click', function(e) { if (e.target === overlay) close(false); });
  });
}

// ── AMMINISTRATORI ────────────────────────────

async function loadAdmins() {
  try {
    const res  = await authFetch(SA_API + '/admin-management.php');
    const data = await res.json();
    saAdmins = data.admins || [];
    renderAdmins();
  } catch(e) {
    showToast('Errore caricamento admin', 'error');
  }
}

function renderAdmins() {
  var tbody = document.getElementById('adminsTableBody');
  if (!tbody) return;

  if (!saAdmins.length) {
    tbody.innerHTML = '<tr><td colspan="5" style="color:var(--text-muted);text-align:center;font-size:0.75rem">Nessun amministratore trovato.</td></tr>';
    return;
  }

  tbody.innerHTML = saAdmins.map(function(a) {
    var roleLabel = '';
    if (a.role === 'super_admin') {
      roleLabel = '<span class="sz-badge sz-badge-super">Super Admin</span>';
    } else {
      roleLabel = a.group_name
        ? escHtml(a.group_name) + ' <span class="sz-badge sz-badge-group">Admin</span>'
        : (a.group_id ? 'Gruppo #' + a.group_id : '<span style="color:var(--text-muted)">—</span>');
    }

    var perms = a.role === 'super_admin'
      ? '<span style="color:var(--text-muted);font-size:0.7rem">Tutti</span>'
      : formatPerms(a);

    return (
      '<tr>' +
        '<td>' + escHtml(a.email) + '</td>' +
        '<td>' + roleLabel + '</td>' +
        '<td>' + formatDate(a.last_login) + '</td>' +
        '<td>' + perms + '</td>' +
        '<td>' +
          '<button class="btn-small" onclick="openEditAdmin(' + a.id + ')">✏️</button>' +
          ' <button class="btn-small danger" onclick="deleteAdmin(' + a.id + ')">🗑</button>' +
        '</td>' +
      '</tr>'
    );
  }).join('');
}

function formatPerms(a) {
  var map = {
    can_add_sessions: 'Agg.Sess', can_edit_sessions: 'Mod.Sess', can_delete_sessions: 'Del.Sess',
    can_add_players: 'Agg.Gioc', can_edit_players: 'Mod.Gioc', can_delete_players: 'Del.Gioc',
  };
  var active = Object.entries(map).filter(function(e) { return a[e[0]]; });
  if (!active.length) return '<span style="color:var(--text-muted);font-size:0.7rem">—</span>';
  return active.map(function(e) {
    return '<span class="sz-badge" style="background:rgba(0,229,255,0.1);color:var(--cyan);border-color:rgba(0,229,255,0.2)">' + e[1] + '</span>';
  }).join(' ');
}

function formatDate(d) {
  if (!d) return '—';
  return new Date(d).toLocaleDateString('it-IT', { day: '2-digit', month: 'short', year: 'numeric' }).toUpperCase();
}

function openAddAdmin() {
  editingAdminId = null;
  editingRoleId  = null;
  document.getElementById('adminFormTitle').textContent = 'Nuovo Amministratore';
  document.getElementById('adminEmail').value = '';
  document.getElementById('adminPassword').value = '';
  document.getElementById('adminPasswordNote').textContent = 'Minimo 8 caratteri';
  document.getElementById('adminGroupId').value = '';
  document.getElementById('adminUserType').value = 'group_admin';
  resetPermissionsForm(true);
  toggleAdminTypeFields();
  document.getElementById('adminFormSection').style.display = 'block';
  document.getElementById('adminEmail').focus();
}

function openEditAdmin(id) {
  var a = saAdmins.find(function(x) { return x.id == id; });
  if (!a) return;
  editingAdminId = a.id;
  editingRoleId  = a.role_id || null;
  document.getElementById('adminFormTitle').textContent = 'Modifica Amministratore';
  document.getElementById('adminEmail').value = a.email;
  document.getElementById('adminPassword').value = '';
  document.getElementById('adminPasswordNote').textContent = 'Lascia vuoto per non cambiare';
  document.getElementById('adminGroupId').value = a.group_id || '';
  document.getElementById('adminUserType').value = a.role || 'group_admin';
  setPermissionsForm(a);
  toggleAdminTypeFields();
  document.getElementById('adminFormSection').style.display = 'block';
}

function cancelAdminForm() {
  document.getElementById('adminFormSection').style.display = 'none';
  editingAdminId = null;
  editingRoleId  = null;
}

function toggleAdminTypeFields() {
  var type     = document.getElementById('adminUserType').value;
  var groupRow = document.getElementById('adminGroupRow');
  var permsRow = document.getElementById('adminPermsRow');
  if (groupRow) groupRow.style.display = type === 'super_admin' ? 'none' : 'block';
  if (permsRow) permsRow.style.display = type === 'super_admin' ? 'none' : 'block';

  // Popola select gruppi
  if (type !== 'super_admin') {
    var sel = document.getElementById('adminGroupId');
    var cur = sel.value;
    sel.innerHTML = '<option value="">— Seleziona gruppo —</option>' +
      saGroups.map(function(g) {
        return '<option value="' + g.id + '"' + (String(g.id) === String(cur) ? ' selected' : '') + '>' + escHtml(g.name) + '</option>';
      }).join('');
    if (cur) sel.value = cur;
  }
}

var PERMS_LIST = ['can_add_sessions','can_edit_sessions','can_delete_sessions','can_add_players','can_edit_players','can_delete_players'];

function resetPermissionsForm(defaults) {
  PERMS_LIST.forEach(function(p) {
    var el = document.getElementById('perm_' + p);
    if (el) el.checked = defaults
      ? (p === 'can_add_sessions' || p === 'can_edit_sessions' || p === 'can_add_players' || p === 'can_edit_players')
      : false;
  });
}

function setPermissionsForm(a) {
  PERMS_LIST.forEach(function(p) {
    var el = document.getElementById('perm_' + p);
    if (el) el.checked = !!a[p];
  });
}

function getPermissionsFromForm() {
  var perms = {};
  PERMS_LIST.forEach(function(p) {
    var el = document.getElementById('perm_' + p);
    perms[p] = el ? (el.checked ? 1 : 0) : 0;
  });
  return perms;
}

async function saveAdmin() {
  var email    = document.getElementById('adminEmail').value.trim();
  var password = document.getElementById('adminPassword').value;
  var groupId  = parseInt(document.getElementById('adminGroupId').value) || null;
  var role     = document.getElementById('adminUserType').value;
  var perms    = getPermissionsFromForm();

  if (!email) { showToast('Email obbligatoria', 'error'); return; }
  if (!editingAdminId && !password) { showToast('Password obbligatoria', 'error'); return; }
  if (!editingAdminId && password.length < 8) { showToast('Password minimo 8 caratteri', 'error'); return; }
  if (role !== 'super_admin' && !groupId) { showToast('Seleziona un gruppo', 'error'); return; }

  try {
    if (editingAdminId) {
      // PUT: aggiorna permessi/ruolo via role_id
      var body = Object.assign({ role_id: editingRoleId, group_id: groupId, role: role }, perms);
      const res  = await authFetch(SA_API + '/admin-management.php', { method: 'PUT', body: JSON.stringify(body) });
      const data = await res.json();
      if (data.error) { showToast(data.error, 'error'); return; }
    } else {
      // POST: crea nuovo admin
      var body = Object.assign({ email: email, password: password, role: role, group_id: groupId }, perms);
      const res  = await authFetch(SA_API + '/admin-management.php', { method: 'POST', body: JSON.stringify(body) });
      const data = await res.json();
      if (data.error) { showToast(data.error, 'error'); return; }
    }
    showToast(editingAdminId ? 'Admin aggiornato' : 'Admin creato');
    cancelAdminForm();
    loadAdmins();
  } catch(e) {
    showToast('Errore salvataggio admin', 'error');
  }
}

async function deleteAdmin(id) {
  var a = saAdmins.find(function(x) { return x.id == id; });
  if (!a) return;
  if (!confirm('Eliminare l\'amministratore "' + a.email + '"?')) return;

  try {
    const res  = await authFetch(SA_API + '/admin-management.php', { method: 'DELETE', body: JSON.stringify({ admin_id: id }) });
    const data = await res.json();
    if (data.error) { showToast(data.error, 'error'); return; }
    showToast('Amministratore eliminato');
    loadAdmins();
  } catch(e) {
    showToast('Errore eliminazione admin', 'error');
  }
}

// ── ANALYTICS ────────────────────────────────

async function loadAnalytics() {
  try {
    const [statsRes, playersRes, sessionsRes] = await Promise.all([
      fetch(SA_API + '/stats.php'),
      fetch(SA_API + '/players.php'),
      fetch(SA_API + '/sessions.php'),
    ]);
    const stats    = await statsRes.json();
    const players  = await playersRes.json();
    const sessions = await sessionsRes.json();

    document.getElementById('ana-groups').textContent   = saGroups.length;
    document.getElementById('ana-players').textContent  = Array.isArray(players)  ? players.length  : '—';
    document.getElementById('ana-sessions').textContent = Array.isArray(sessions) ? sessions.length : '—';
    document.getElementById('ana-record').textContent   = stats.record_assoluto || '—';
  } catch(e) {}
}

// ── UTILITY ──────────────────────────────────

function escHtml(str) {
  return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function copyCode(code) {
  navigator.clipboard.writeText(code).then(function() {
    showToast('Codice copiato: ' + code);
  }).catch(function() {
    prompt('Codice invito:', code);
  });
}
