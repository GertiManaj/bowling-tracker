// ============================================
//  modal-partita.js
//  Modal "Nuova Partita" identico alla dashboard
//  Incluso in: sessioni.html, statistiche.html, giocatori.html
// ============================================

const API_MODAL = 'api';

// Apre il modal nuova partita
async function openModal() {
  if (typeof window.isLoggedIn !== 'undefined' && !window.isLoggedIn) {
    openLoginModal();
    return;
  }
  try {
    // Ricarica sempre i giocatori freschi
    window.allPlayers = await fetch(API_MODAL + '/players.php').then(r => r.json());
  } catch (e) {
    window.allPlayers = window.allPlayers || [];
  }

  // Reset campi
  document.getElementById('sessionDate').value     = new Date().toISOString().split('T')[0];
  document.getElementById('sessionLocation').value = '';
  document.getElementById('sessionNotes').value    = '';
  document.getElementById('teamAName').value       = '';
  document.getElementById('teamBName').value       = '';
  document.getElementById('numGames').value        = '2';
  document.getElementById('totalA').textContent    = 'Totale: 0';
  document.getElementById('totalB').textContent    = 'Totale: 0';
  document.getElementById('soloRows').innerHTML    = '';
  document.getElementById('btnSave').textContent   = 'Salva Partita';

  buildGameRows();
  document.getElementById('modalOverlay').classList.add('open');
}

// Ricostruisce le righe giocatori quando cambia N° Game
function buildGameRows() {
  const numGames = parseInt(document.getElementById('numGames').value) || 1;
  ['A', 'B'].forEach(function(team) {
    var container = document.getElementById('team' + team + 'Rows');
    var selected = [];
    container.querySelectorAll('.player-row').forEach(function(row) {
      selected.push(row.querySelector('select') ? row.querySelector('select').value : '');
    });
    container.innerHTML = '';
    var count = Math.max(selected.length, 3);
    for (var i = 0; i < count; i++) {
      addPlayerRow(team, selected[i] || null, numGames);
    }
  });
  calcTotals();
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
}

function handleOverlayClick(e) {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
}

function addPlayerRow(team, selectedId, numGames) {
  var ng = numGames || parseInt(document.getElementById('numGames') ? document.getElementById('numGames').value : '1') || 1;
  var players = window.allPlayers || [];
  var opts = players.map(function(p) {
    return '<option value="' + p.id + '" ' + (parseInt(p.id) === parseInt(selectedId) ? 'selected' : '') + '>' + (p.emoji || '🎳') + ' ' + p.name + '</option>';
  }).join('');

  var gameInputs = '';
  for (var i = 1; i <= ng; i++) {
    gameInputs += '<input type="number" class="form-input score-input" placeholder="G' + i + '" min="0" max="300" data-game="' + i + '" oninput="calcTotals();validateScoreInput(this)"/>';
  }

  var row = document.createElement('div');
  row.className = 'player-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr ' + Array(ng).fill('70px').join(' ') + ' 32px;gap:0.4rem;align-items:center;margin-bottom:0.4rem';
  row.innerHTML = '<select class="form-input" onchange="calcTotals()"><option value="">— Giocatore —</option>' + opts + '</select>' + gameInputs + '<button class="btn-remove" onclick="this.parentElement.remove();calcTotals()" title="Rimuovi">✕</button>';

  document.getElementById('team' + team + 'Rows').appendChild(row);
}

function addSoloRow(selectedId, numGames) {
  var ng = numGames || parseInt(document.getElementById('numGames') ? document.getElementById('numGames').value : '1') || 1;
  var players = window.allPlayers || [];
  var opts = players.map(function(p) {
    return '<option value="' + p.id + '" ' + (parseInt(p.id) === parseInt(selectedId) ? 'selected' : '') + '>' + (p.emoji || '🎳') + ' ' + p.name + '</option>';
  }).join('');

  var gameInputs = '';
  for (var i = 1; i <= ng; i++) {
    gameInputs += '<input type="number" class="form-input score-input" placeholder="G' + i + '" min="0" max="300" data-game="' + i + '"/>';
  }

  var row = document.createElement('div');
  row.className = 'player-row solo-row';
  row.style.cssText = 'display:grid;grid-template-columns:1fr ' + Array(ng).fill('70px').join(' ') + ' 32px;gap:0.4rem;align-items:center;margin-bottom:0.4rem';
  row.innerHTML = '<select class="form-input"><option value="">— Giocatore —</option>' + opts + '</select>' + gameInputs + '<button class="btn-remove" onclick="this.parentElement.remove()" title="Rimuovi">✕</button>';
  document.getElementById('soloRows').appendChild(row);
}

function getSoloPlayers() {
  var solo = [];
  document.querySelectorAll('#soloRows .solo-row').forEach(function(row) {
    var pid = row.querySelector('select') ? row.querySelector('select').value : '';
    if (!pid) return;
    row.querySelectorAll('.score-input').forEach(function(input) {
      var gameNum = parseInt(input.dataset.game);
      var score = input.value;
      if (score) solo.push({ player_id: parseInt(pid), score: parseInt(score), game_number: gameNum });
    });
  });
  return solo;
}

function calcTotals() {
  ['A', 'B'].forEach(function(t) {
    var tot = 0;
    document.querySelectorAll('#team' + t + 'Rows .score-input').forEach(function(i) {
      if (i.value) tot += parseInt(i.value) || 0;
    });
    document.getElementById('total' + t).textContent = 'Totale: ' + tot;
  });
}

function validateScoreInput(input) {
  var val = parseInt(input.value);
  if (input.value === '') { input.style.borderColor = ''; return; }
  if (isNaN(val) || val < 0 || val > 300) {
    input.style.borderColor = 'var(--neon2)';
    input.title = 'Punteggio non valido (0-300)';
  } else {
    input.style.borderColor = 'var(--neon)';
    input.title = '';
    setTimeout(function() { if (input.style.borderColor === 'var(--neon)') input.style.borderColor = ''; }, 1000);
  }
}

async function saveSessionModal() {
  var btn = document.getElementById('btnSave');
  btn.disabled = true;
  btn.textContent = 'Salvataggio...';

  var date = document.getElementById('sessionDate').value;
  if (!date) {
    showToast('Inserisci la data', 'error');
    btn.disabled = false; btn.textContent = 'Salva Partita';
    return;
  }

  var teams = [];
  ['A', 'B'].forEach(function(t) {
    var name = document.getElementById('team' + t + 'Name').value || ('Squadra ' + t);
    var players = [];
    document.querySelectorAll('#team' + t + 'Rows .player-row').forEach(function(row) {
      var pid = row.querySelector('select') ? row.querySelector('select').value : '';
      if (!pid) return;
      row.querySelectorAll('.score-input').forEach(function(input) {
        var gameNum = parseInt(input.dataset.game);
        var score = input.value;
        if (score) players.push({ player_id: parseInt(pid), score: parseInt(score), game_number: gameNum });
      });
    });
    if (players.length > 0) teams.push({ name: name, players: players });
  });

  var soloPlayers = getSoloPlayers();

  // Validazione punteggi 0-300
  var allInputs = document.querySelectorAll('#teamARows .score-input, #teamBRows .score-input, #soloRows .score-input');
  for (var i = 0; i < allInputs.length; i++) {
    var input = allInputs[i];
    if (!input.value) continue;
    var val = parseInt(input.value);
    if (isNaN(val) || val < 0 || val > 300) {
      showToast('Il punteggio deve essere tra 0 e 300', 'error');
      btn.disabled = false; btn.textContent = 'Salva Partita';
      input.focus();
      input.style.borderColor = 'var(--neon2)';
      setTimeout(function() { input.style.borderColor = ''; }, 2000);
      return;
    }
  }

  // Validazione giocatore duplicato
  var playersA = new Set();
  var playersB = new Set();
  var playerNames = {};
  document.querySelectorAll('#teamARows .player-row select').forEach(function(sel) {
    if (sel.value) { playersA.add(sel.value); playerNames[sel.value] = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : sel.value; }
  });
  document.querySelectorAll('#teamBRows .player-row select').forEach(function(sel) {
    if (sel.value) { playersB.add(sel.value); playerNames[sel.value] = sel.options[sel.selectedIndex] ? sel.options[sel.selectedIndex].text : sel.value; }
  });
  var duplicates = Array.from(playersA).filter(function(id) { return playersB.has(id); });
  if (duplicates.length > 0) {
    var names = duplicates.map(function(id) { return playerNames[id]; }).join(', ');
    var ok = confirm('⚠ Attenzione: ' + names + ' è presente in entrambe le squadre.\n\nVuoi salvare comunque?');
    if (!ok) { btn.disabled = false; btn.textContent = 'Salva Partita'; return; }
  }

  if (!teams.length && !soloPlayers.length) {
    showToast('Inserisci almeno un punteggio', 'error');
    btn.disabled = false; btn.textContent = 'Salva Partita';
    return;
  }

  try {
    var res = await fetch(API_MODAL + '/sessions.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({
        date: date,
        location: document.getElementById('sessionLocation').value || 'Bowling',
        notes: document.getElementById('sessionNotes').value,
        teams: teams,
        solo_players: soloPlayers
      })
    });
    var data = await res.json();

    if (data.success || data.session_id) {
      closeModal();
      showToast('Partita salvata!');
      // Ricarica la pagina per aggiornare i dati
      setTimeout(function() { window.location.reload(); }, 500);
    } else {
      showToast(data.error || 'Errore nel salvataggio', 'error');
    }
  } catch(e) {
    showToast('Errore di connessione', 'error');
    console.error(e);
  }

  btn.disabled = false;
  btn.textContent = 'Salva Partita';
}