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
    return (
      '<div class="group-card">' +
        '<div class="group-card-name">' + escHtml(g.name) + '</div>' +
        (g.description ? '<div class="group-card-desc">' + escHtml(g.description) + '</div>' : '') +
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
  document.getElementById('groupFormSection').style.display = 'block';
  document.getElementById('groupName').focus();
}

function openEditGroup(id) {
  var g = saGroups.find(function(x) { return x.id == id; });
  if (!g) return;
  editingGroupId = id;
  document.getElementById('groupFormTitle').textContent = 'Modifica Gruppo';
  document.getElementById('groupName').value = g.name;
  document.getElementById('groupDesc').value = g.description || '';
  document.getElementById('groupFormSection').style.display = 'block';
  document.getElementById('groupName').focus();
}

function cancelGroupForm() {
  document.getElementById('groupFormSection').style.display = 'none';
  editingGroupId = null;
}

async function saveGroup() {
  var name = document.getElementById('groupName').value.trim();
  var desc = document.getElementById('groupDesc').value.trim();
  if (!name) { showToast('Il nome è obbligatorio', 'error'); return; }

  try {
    var method = editingGroupId ? 'PUT' : 'POST';
    var body   = editingGroupId
      ? JSON.stringify({ id: editingGroupId, name: name, description: desc })
      : JSON.stringify({ name: name, description: desc });

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
  if (!confirm('Eliminare il gruppo "' + g.name + '"?\nIl gruppo deve essere vuoto (nessun giocatore o sessione).')) return;

  try {
    const res  = await authFetch(SA_API + '/groups.php', { method: 'DELETE', body: JSON.stringify({ id: id }) });
    const data = await res.json();
    if (data.error) { showToast(data.error, 'error'); return; }
    showToast('Gruppo eliminato');
    await loadGroups();
    loadAnalytics();
  } catch(e) {
    showToast('Errore eliminazione gruppo', 'error');
  }
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
