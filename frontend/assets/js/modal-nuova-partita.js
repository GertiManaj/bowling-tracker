// ============================================
//  modal-nuova-partita.js
//  Funzioni modal "Nuova Partita" — identico alla dashboard
//  Incluso in: sessioni.html, statistiche.html, giocatori.html
//  NON includere in index.html (usa app.js direttamente)
// ============================================

// Variabili globali: API e allPlayers sono definite da app.js in index.html
// oppure dichiarate qui solo se non esistono (altre pagine)
if (typeof API === 'undefined') window.API = '/api';
if (typeof allPlayers === 'undefined') window.allPlayers = [];

// ── APRI MODAL ───────────────────────────────
async function openModal() {
  if (!window.isLoggedIn) { openLoginModal(); return; }
  try {
    allPlayers = await authFetch(`${API}/players.php`).then(r => r.json());
  } catch (e) {
    allPlayers = [];
  }

  document.getElementById('sessionDate').value = new Date().toISOString().split('T')[0];
  document.getElementById('sessionLocation').value = '';
  document.getElementById('sessionNotes').value = '';
  document.getElementById('teamAName').value = '';
  document.getElementById('teamBName').value = '';
  document.getElementById('numGames').value = '2';
  document.getElementById('totalA').textContent = 'Totale: 0';
  document.getElementById('totalB').textContent = 'Totale: 0';
  document.getElementById('soloRows').innerHTML = '';
  const costEl = document.getElementById('sessionCost');
  if (costEl) costEl.value = '';
  const ffaRows = document.getElementById('ffaRows');
  if (ffaRows) ffaRows.innerHTML = '';
  const ffaCheck = document.getElementById('ffaMode');
  if (ffaCheck) { ffaCheck.checked = false; setFFAMode(false); }

  buildGameRows();
  document.getElementById('modalOverlay').classList.add('open');
}

// ── COSTRUISCE RIGHE GIOCATORI ───────────────
function buildGameRows() {
  const numGames = parseInt(document.getElementById('numGames').value) || 1;
  ['A', 'B'].forEach(team => {
    const container = document.getElementById(`team${team}Rows`);
    const selected = [];
    container.querySelectorAll('.player-row').forEach(row => {
      selected.push(row.querySelector('select')?.value || '');
    });
    container.innerHTML = '';
    const count = Math.max(selected.length, 3);
    for (let i = 0; i < count; i++) {
      addPlayerRow(team, selected[i] || null, numGames);
    }
  });
  calcTotals();
}

function closeModal() {
  document.getElementById('modalOverlay').classList.remove('open');
  if (typeof editingId !== 'undefined') editingId = null;
  const ffaRows = document.getElementById('ffaRows');
  if (ffaRows) ffaRows.innerHTML = '';
  const ffaRowsC = document.getElementById('ffaRows');
  if (ffaRowsC) ffaRowsC.innerHTML = '';
  const ffaCheck = document.getElementById('ffaMode');
  if (ffaCheck) { ffaCheck.checked = false; setFFAMode(false); }
}

// ── MODALITÀ TUTTI CONTRO TUTTI ──────────────
function setFFAMode(active) {
  const teamsSection = document.getElementById('teamsSection');
  const ffaSection = document.getElementById('ffaSection');
  const soloSection = document.getElementById('soloSection');
  const ffaNote = document.getElementById('ffaNote');
  const soloNote = document.getElementById('soloSectionNote');

  if (teamsSection) teamsSection.style.display = active ? 'none' : 'block';
  if (ffaSection) ffaSection.style.display = active ? 'block' : 'none';
  if (soloSection) soloSection.style.display = 'block'; // sempre visibile
  if (ffaNote) ffaNote.style.display = active ? 'inline' : 'none';
  if (soloNote) soloNote.textContent = active
    ? 'Giocatori extra — non partecipano al FFA'
    : 'Giocatori extra — non partecipano alla sfida';

  // Reset righe FFA quando si disattiva
  if (!active) {
    const ffaRows = document.getElementById('ffaRows');
    if (ffaRows) ffaRows.innerHTML = '';
  }
}

// ── AGGIUNGI RIGA FFA ────────────────────────
function addFFARow(selectedId = null, numGames = null) {
  const ng = numGames || parseInt(document.getElementById('numGames')?.value) || 1;
  const opts = allPlayers.map(p =>
    `<option value="${p.id}" ${parseInt(p.id) === parseInt(selectedId) ? 'selected' : ''}>${p.emoji || '🎳'} ${p.name}</option>`
  ).join('');
  const gameInputs = Array.from({ length: ng }, (_, i) =>
    `<input type="number" class="form-input score-input" placeholder="G${i + 1}" min="0" max="300" data-game="${i + 1}" oninput="validateScoreInput(this)"/>`
  ).join('');

  const row = document.createElement('div');
  row.className = 'player-row ffa-row';
  row.style.cssText = `display:grid;grid-template-columns:1fr ${Array(ng).fill('70px').join(' ')} 32px;gap:0.4rem;align-items:center;margin-bottom:0.4rem`;
  row.innerHTML = `
    <select class="form-input">
      <option value="">— Giocatore —</option>${opts}
    </select>
    ${gameInputs}
    <button class="btn-remove" onclick="this.parentElement.remove()" title="Rimuovi">✕</button>`;
  document.getElementById('ffaRows').appendChild(row);
}

// ── RACCOGLIE GIOCATORI FFA ──────────────────
function getFFAPlayers() {
  const ffa = [];
  document.querySelectorAll('#ffaRows .ffa-row').forEach(row => {
    const pid = row.querySelector('select')?.value;
    if (!pid) return;
    row.querySelectorAll('.score-input').forEach(input => {
      const gameNum = parseInt(input.dataset.game);
      const score = input.value;
      if (score) ffa.push({ player_id: parseInt(pid), score: parseInt(score), game_number: gameNum });
    });
  });
  return ffa;
}

function handleOverlayClick(e) {
  if (e.target === document.getElementById('modalOverlay')) closeModal();
}

// ── AGGIUNGI RIGA GIOCATORE ──────────────────
function addPlayerRow(team, selectedId = null, numGames = null) {
  const ng = numGames || parseInt(document.getElementById('numGames')?.value) || 1;
  const opts = allPlayers.map(p =>
    `<option value="${p.id}" ${parseInt(p.id) === parseInt(selectedId) ? 'selected' : ''}>${p.emoji || '🎳'} ${p.name}</option>`
  ).join('');

  const gameInputs = Array.from({ length: ng }, (_, i) =>
    `<input type="number" class="form-input score-input" placeholder="G${i + 1}" min="0" max="300" data-game="${i + 1}" oninput="calcTotals();validateScoreInput(this)"/>`
  ).join('');

  const row = document.createElement('div');
  row.className = 'player-row';
  row.style.cssText = `display:grid;grid-template-columns:1fr ${Array(ng).fill('70px').join(' ')} 32px;gap:0.4rem;align-items:center;margin-bottom:0.4rem`;
  row.innerHTML = `
    <select class="form-input" onchange="calcTotals()">
      <option value="">— Giocatore —</option>${opts}
    </select>
    ${gameInputs}
    <button class="btn-remove" onclick="this.parentElement.remove();calcTotals()" title="Rimuovi">✕</button>`;

  document.getElementById(`team${team}Rows`).appendChild(row);
}

// ── AGGIUNGI RIGA SINGOLO ────────────────────
function addSoloRow(selectedId = null, numGames = null) {
  const ng = numGames || parseInt(document.getElementById('numGames')?.value) || 1;
  const opts = allPlayers.map(p =>
    `<option value="${p.id}" ${parseInt(p.id) === parseInt(selectedId) ? 'selected' : ''}>${p.emoji || '🎳'} ${p.name}</option>`
  ).join('');
  const gameInputs = Array.from({ length: ng }, (_, i) =>
    `<input type="number" class="form-input score-input" placeholder="G${i + 1}" min="0" max="300" data-game="${i + 1}"/>`
  ).join('');

  const row = document.createElement('div');
  row.className = 'player-row solo-row';
  row.style.cssText = `display:grid;grid-template-columns:1fr ${Array(ng).fill('70px').join(' ')} 32px;gap:0.4rem;align-items:center;margin-bottom:0.4rem`;
  row.innerHTML = `
    <select class="form-input">
      <option value="">— Giocatore —</option>${opts}
    </select>
    ${gameInputs}
    <button class="btn-remove" onclick="this.parentElement.remove()" title="Rimuovi">✕</button>`;
  document.getElementById('soloRows').appendChild(row);
}

// ── GIOCATORI SINGOLI ────────────────────────
function getSoloPlayers() {
  const solo = [];
  document.querySelectorAll('#soloRows .solo-row').forEach(row => {
    const pid = row.querySelector('select')?.value;
    if (!pid) return;
    row.querySelectorAll('.score-input').forEach(input => {
      const gameNum = parseInt(input.dataset.game);
      const score = input.value;
      if (score) solo.push({ player_id: parseInt(pid), score: parseInt(score), game_number: gameNum });
    });
  });
  return solo;
}

// ── CALCOLA TOTALI ───────────────────────────
function calcTotals() {
  ['A', 'B'].forEach(t => {
    let tot = 0;
    document.querySelectorAll(`#team${t}Rows .score-input`).forEach(i => {
      if (i.value) tot += parseInt(i.value) || 0;
    });
    document.getElementById(`total${t}`).textContent = `Totale: ${tot}`;
  });
}

// ── VALIDAZIONE SCORE LIVE ───────────────────
function validateScoreInput(input) {
  const val = parseInt(input.value);
  if (input.value === '') { input.style.borderColor = ''; return; }
  if (isNaN(val) || val < 0 || val > 300) {
    input.style.borderColor = 'var(--neon2)';
    input.title = 'Punteggio non valido (0-300)';
  } else {
    input.style.borderColor = 'var(--neon)';
    input.title = '';
    setTimeout(() => { if (input.style.borderColor === 'var(--neon)') input.style.borderColor = ''; }, 1000);
  }
}

// ── SALVA SESSIONE ───────────────────────────
async function saveSession() {
  const btn = document.getElementById('btnSave');
  btn.disabled = true;
  btn.textContent = 'Salvataggio...';

  const date = document.getElementById('sessionDate').value;
  if (!date) {
    showToast('Inserisci la data', 'error');
    btn.disabled = false; btn.textContent = 'Salva Partita';
    return;
  }

  const numGames = parseInt(document.getElementById('numGames').value) || 1;
  const isFFA = document.getElementById('ffaMode')?.checked;
  const teams = [];
  if (!isFFA) ['A', 'B'].forEach(t => {
    const name = document.getElementById(`team${t}Name`).value || `Squadra ${t}`;
    const players = [];
    document.querySelectorAll(`#team${t}Rows .player-row`).forEach(row => {
      const pid = row.querySelector('select')?.value;
      if (!pid) return;
      row.querySelectorAll('.score-input').forEach(input => {
        const gameNum = parseInt(input.dataset.game);
        const score = input.value;
        if (score) players.push({ player_id: parseInt(pid), score: parseInt(score), game_number: gameNum });
      });
    });
    if (players.length > 0) teams.push({ name, players });
  });

  const ffaPlayers = isFFA ? getFFAPlayers() : [];
  const soloPlayers = getSoloPlayers();

  // Validazione 0-300
  const allScoreInputs = document.querySelectorAll('#teamARows .score-input, #teamBRows .score-input, #ffaRows .score-input, #soloRows .score-input');
  for (const input of allScoreInputs) {
    if (!input.value) continue;
    const val = parseInt(input.value);
    if (isNaN(val) || val < 0 || val > 300) {
      showToast('Il punteggio deve essere tra 0 e 300', 'error');
      btn.disabled = false; btn.textContent = 'Salva Partita';
      input.focus();
      input.style.borderColor = 'var(--neon2)';
      setTimeout(() => input.style.borderColor = '', 2000);
      return;
    }
  }

  // Validazione giocatore duplicato
  const playersA = new Set();
  const playersB = new Set();
  const playerNames = {};
  document.querySelectorAll('#teamARows .player-row select').forEach(sel => {
    if (sel.value) { playersA.add(sel.value); playerNames[sel.value] = sel.options[sel.selectedIndex]?.text || sel.value; }
  });
  document.querySelectorAll('#teamBRows .player-row select').forEach(sel => {
    if (sel.value) { playersB.add(sel.value); playerNames[sel.value] = sel.options[sel.selectedIndex]?.text || sel.value; }
  });
  const duplicates = [...playersA].filter(id => playersB.has(id));
  if (duplicates.length > 0) {
    const names = duplicates.map(id => playerNames[id]).join(', ');
    const ok = confirm(`⚠ Attenzione: ${names} è presente in entrambe le squadre.\n\nVuoi salvare comunque?`);
    if (!ok) { btn.disabled = false; btn.textContent = 'Salva Partita'; return; }
  }

  if (!teams.length && !ffaPlayers.length && !soloPlayers.length) {
    showToast('Inserisci almeno un punteggio', 'error');
    btn.disabled = false; btn.textContent = 'Salva Partita';
    return;
  }

  // editingId può essere definito da sessioni.js quando si modifica
  const sessionEditId = (typeof editingId !== 'undefined' && editingId) ? editingId : null;

  try {
    const payload = {
      date,
      location: document.getElementById('sessionLocation').value || 'Bowling',
      notes: document.getElementById('sessionNotes').value,
      cost_per_game: (() => {
        const v = document.getElementById('sessionCost')?.value;
        return (v !== '' && v != null) ? parseFloat(v) : null;
      })(),
      mode: isFFA ? 'ffa' : 'teams',
      teams,
      ffa_players: ffaPlayers,
      solo_players: soloPlayers
    };

    if (sessionEditId) payload.id = sessionEditId;

    const res = await authFetch(`${API}/sessions.php`, {
      method: sessionEditId ? 'PUT' : 'POST',
      body: JSON.stringify(payload)
    });
    const data = await res.json();

    if (data.success || data.session_id) {
      closeModal();
      showToast(sessionEditId ? 'Sessione aggiornata!' : 'Partita salvata!');
      // Resetta editingId se era una modifica
      if (typeof editingId !== 'undefined') editingId = null;
      // Ricarica dati della pagina corrente
      if (typeof loadAll === 'function') loadAll();
      if (typeof loadSessions === 'function') loadSessions();
      if (typeof loadLeaderboard === 'function') loadLeaderboard();
      if (typeof loadStats === 'function') loadStats();
      if (typeof loadHof === 'function') loadHof();
      if (typeof loadPlayers === 'function') loadPlayers();
    } else {
      showToast(data.error || 'Errore nel salvataggio', 'error');
    }
  } catch (e) {
    showToast('Errore di connessione', 'error');
    console.error(e);
  }

  btn.disabled = false;
  btn.textContent = 'Salva Partita';
}
