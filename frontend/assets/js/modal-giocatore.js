// ============================================
//  modal-giocatore.js
//  Modal "Nuovo/Modifica Giocatore" — funziona
//  su tutte le pagine che includono questo file
// ============================================

const EMOJIS_G = [
  '🎳', '🐺', '🦊', '🐻', '🦁', '🐯', '🦋', '🐸',
  '🦅', '🐉', '🦈', '🐆', '🦎', '🐬', '🦄', '🐼',
  '🐙', '🐝', '🦉', '🦚', '🦀', '⚡', '🔥', '💎',
  '🏆', '👑', '🎯', '🚀', '💥', '🌟', '🎪', '🎭'
];

let _gEditingId = null;

function buildEmojiGrid(selected) {
  selected = selected || '🎳';
  var grid = document.getElementById('emojiGrid');
  if (!grid) return;
  grid.innerHTML = EMOJIS_G.map(function (e) {
    return '<button type="button" class="emoji-btn' + (e === selected ? ' selected' : '') +
      '" onclick="selectEmoji(\'' + e + '\', this)">' + e + '</button>';
  }).join('');
  document.getElementById('selectedEmoji').value = selected;
}

function selectEmoji(emoji, btn) {
  document.querySelectorAll('.emoji-btn').forEach(function (b) { b.classList.remove('selected'); });
  btn.classList.add('selected');
  document.getElementById('selectedEmoji').value = emoji;
}

function openAddModal() {
  if (!window.isLoggedIn) { openLoginModal(); return; }
  _gEditingId = null;
  document.getElementById('gModalTitle').textContent = '➕ Nuovo Giocatore';
  document.getElementById('playerName').value = '';
  document.getElementById('playerNickname').value = '';
  document.getElementById('btnSavePlayer').textContent = 'Salva';
  buildEmojiGrid('🎳');
  document.getElementById('gModalOverlay').classList.add('open');
  setTimeout(function () { document.getElementById('playerName').focus(); }, 100);
}

function openEditModal(id) {
  if (!window.isLoggedIn) { openLoginModal(); return; }
  // allPlayers potrebbe essere definito in giocatori.js o dobbiamo fetcharlo
  var players = (typeof allPlayers !== 'undefined') ? allPlayers : [];
  var p = players.find(function (x) { return x.id === id; });
  if (!p) return;

  _gEditingId = id;
  document.getElementById('gModalTitle').textContent = '✏ Modifica Giocatore';
  document.getElementById('playerName').value = p.name;
  document.getElementById('playerNickname').value = p.nickname || '';
  document.getElementById('btnSavePlayer').textContent = 'Aggiorna';
  buildEmojiGrid(p.emoji || '🎳');
  document.getElementById('gModalOverlay').classList.add('open');
  setTimeout(function () { document.getElementById('playerName').focus(); }, 100);
}

function closeGModal() {
  document.getElementById('gModalOverlay').classList.remove('open');
  _gEditingId = null;
}

function handleGModalOverlayClick(e) {
  if (e.target === document.getElementById('gModalOverlay')) closeGModal();
}

async function savePlayer() {
  var btn = document.getElementById('btnSavePlayer');
  var name = document.getElementById('playerName').value.trim();
  var nickname = document.getElementById('playerNickname').value.trim();
  var emoji = document.getElementById('selectedEmoji').value;

  if (!name) {
    if (typeof showToast === 'function') showToast('Il nome è obbligatorio', 'error');
    document.getElementById('playerName').focus();
    return;
  }

  btn.disabled = true;
  btn.textContent = 'Salvataggio...';

  try {
    var method = _gEditingId ? 'PUT' : 'POST';
    var payload = _gEditingId
      ? { id: _gEditingId, name: name, nickname: nickname, emoji: emoji }
      : { name: name, nickname: nickname, emoji: emoji };

    var res = await authFetch('/api/players.php', {
      method: method,
      body: JSON.stringify(payload)
    })
    var data = await res.json();

    if (data.success) {
      closeGModal();
      if (typeof showToast === 'function')
        showToast(_gEditingId ? name + ' aggiornato!' : name + ' aggiunto al gruppo!');
      // Ricarica se la funzione esiste nella pagina
      if (typeof loadPlayers === 'function') loadPlayers();
      if (typeof loadLeaderboard === 'function') loadLeaderboard();
      if (typeof loadAll === 'function') loadAll();
      if (typeof loadStats === 'function') loadStats();
    } else {
      if (typeof showToast === 'function') showToast(data.error || 'Errore nel salvataggio', 'error');
    }
  } catch (e) {
    if (typeof showToast === 'function') showToast('Errore di connessione', 'error');
    console.error(e);
  }

  btn.disabled = false;
  btn.textContent = _gEditingId ? 'Aggiorna' : 'Salva';
}

// Enter per salvare
document.addEventListener('keydown', function (e) {
  if (e.key === 'Enter' && document.getElementById('gModalOverlay')?.classList.contains('open')) {
    savePlayer();
  }
  if (e.key === 'Escape') closeGModal();
});
