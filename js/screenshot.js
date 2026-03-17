// ============================================
//  screenshot.js — Genera immagini con html2canvas
// ============================================

function showToast(msg, type) {
  type = type || 'success';
  var t = document.getElementById('toast');
  if (!t) return;
  t.textContent = (type === 'success' ? '✓ ' : '✕ ') + msg;
  t.className = 'toast ' + type + ' show';
  setTimeout(function() { t.className = 'toast'; }, 3500);
}

const COLORS = ['#e8ff00','#00f5ff','#ff6b35','#ff3cac','#ffd700','#a78bfa','#34d399','#fb923c','#60a5fa'];
const MEDALS = ['🥇','🥈','🥉'];

function makeCanvas(w, h) {
  var c = document.createElement('canvas');
  c.width = w * 2; c.height = h * 2;
  var ctx = c.getContext('2d');
  ctx.scale(2, 2);
  return { c: c, ctx: ctx };
}

async function blobFromCanvas(canvas) {
  return new Promise(function(resolve) { canvas.toBlob(resolve, 'image/png'); });
}

async function shareOrDownload(blob, filename, title) {
  var file = new File([blob], filename, { type: 'image/png' });
  if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
    try {
      await navigator.share({ files: [file], title: title, text: '🎳 ' + title + ' — Strike Zone' });
      return;
    } catch(e) {
      if (e.name === 'AbortError') return;
    }
  }
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a');
  a.href = url; a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(function() { URL.revokeObjectURL(url); }, 1000);
}

// ── HELPER: carica html2canvas ─────────────────
async function loadHtml2Canvas() {
  if (typeof html2canvas !== 'undefined') return;
  await new Promise(function(resolve, reject) {
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
    s.onload = resolve;
    s.onerror = reject;
    document.head.appendChild(s);
  });
}

// ── HELPER: cattura elemento DOM → blob ────────
async function captureElementToBlob(el) {
  // Inietta stile che azzera animazioni
  var styleTag = document.createElement('style');
  styleTag.id = 'sz-no-anim';
  styleTag.textContent = '#sz-capture-wrap, #sz-capture-wrap * { animation: none !important; transition: none !important; opacity: 1 !important; }';
  document.head.appendChild(styleTag);

  // Wrapper esterno con sfondo e padding
  var wrapper = document.createElement('div');
  wrapper.id = 'sz-capture-wrap';
  wrapper.style.position = 'fixed';
  wrapper.style.top = '-9999px';
  wrapper.style.left = '-9999px';
  wrapper.style.background = '#0a0a0f';
  wrapper.style.padding = '20px';
  wrapper.style.borderRadius = '12px';
  wrapper.style.width = (el.offsetWidth + 40) + 'px';

  // Titolo header
  var titleEl = document.createElement('div');
  titleEl.style.display = 'flex';
  titleEl.style.alignItems = 'center';
  titleEl.style.justifyContent = 'space-between';
  titleEl.style.marginBottom = '12px';
  titleEl.style.padding = '0 4px';

  var titleLeft = document.createElement('div');

  var titleName = document.createElement('div');
  titleName.style.fontFamily = 'Black Han Sans, sans-serif';
  titleName.style.fontSize = '22px';
  titleName.style.color = '#e8ff00';
  titleName.style.letterSpacing = '0.05em';
  titleName.textContent = '🎳 STRIKE ZONE';

  var titleDate = document.createElement('div');
  titleDate.style.fontFamily = 'Share Tech Mono, monospace';
  titleDate.style.fontSize = '10px';
  titleDate.style.color = '#666680';
  titleDate.style.letterSpacing = '0.2em';
  titleDate.style.textTransform = 'uppercase';
  titleDate.style.marginTop = '2px';
  titleDate.textContent = 'Classifica · ' + new Date().toLocaleDateString('it-IT', { day: '2-digit', month: 'long', year: 'numeric' }).toUpperCase();

  titleLeft.appendChild(titleName);
  titleLeft.appendChild(titleDate);

  var titleIcon = document.createElement('div');
  titleIcon.style.fontSize = '28px';
  titleIcon.textContent = '🏆';

  titleEl.appendChild(titleLeft);
  titleEl.appendChild(titleIcon);

  // Clona l'elemento originale
  var clone = el.cloneNode(true);
  clone.style.margin = '0';

  // Forza le mini-bar al valore corretto
  clone.querySelectorAll('.mini-bar-fill').forEach(function(bar) {
    if (bar.dataset.w) bar.style.width = bar.dataset.w;
  });

  wrapper.appendChild(titleEl);
  wrapper.appendChild(clone);
  document.body.appendChild(wrapper);

  await new Promise(function(r) { setTimeout(r, 200); });

  var canvas = await html2canvas(wrapper, {
    backgroundColor: '#0a0a0f',
    scale: 2,
    useCORS: true,
    logging: false,
    allowTaint: true,
  });

  document.body.removeChild(wrapper);
  var toRemove = document.getElementById('sz-no-anim');
  if (toRemove) toRemove.remove();

  return blobFromCanvas(canvas);
}

// ── CLASSIFICA DI SEMPRE ───────────────────────
async function saveClassifica() {
  var btn = document.getElementById('btnSaveClassifica');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Generando...'; }
  try {
    await loadHtml2Canvas();
    var table = document.querySelector('.leaderboard-table');
    if (!table) { showToast('Classifica non trovata', 'error'); return; }
    var blob = await captureElementToBlob(table);
    var date = new Date().toLocaleDateString('it-IT').replace(/\//g, '-');
    await shareOrDownload(blob, 'classifica-' + date + '.png', 'Classifica Strike Zone');
    showToast('Foto classifica pronta!');
  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }
  if (btn) { btn.disabled = false; btn.textContent = '📸 Salva foto'; }
}

// ── CLASSIFICA ULTIMA SERATA ──────────────────
async function saveClassificaUltimaSerata() {
  var btn = document.getElementById('btnSaveClassifica');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Generando...'; }
  try {
    await loadHtml2Canvas();
    var table = document.querySelector('.leaderboard-table');
    if (!table) { showToast('Classifica non trovata', 'error'); return; }
    var blob = await captureElementToBlob(table);
    var date = new Date().toLocaleDateString('it-IT').replace(/\//g, '-');
    await shareOrDownload(blob, 'classifica-serata-' + date + '.png', 'Classifica Ultima Serata');
    showToast('Foto classifica serata pronta!');
  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }
  if (btn) { btn.disabled = false; btn.textContent = '📸 Salva foto'; }
}

// ── WRAPPER: sceglie classifica giusta ─────────
function saveFotoClassifica() {
  if (window.leaderboardMode === 'last') {
    saveClassificaUltimaSerata();
  } else {
    saveClassifica();
  }
}

// ── ULTIMA SERATA (card sidebar) ──────────────
async function saveUltimaSerata() {
  var btn = document.getElementById('btnSaveSerata');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Generando...'; }
  try {
    await loadHtml2Canvas();
    var card = document.getElementById('last-session-card');
    if (!card) { showToast('Card serata non trovata', 'error'); return; }
    var blob = await captureElementToBlob(card);
    var sessions = window.cachedSessions || [];
    var s = sessions[0];
    var date = s
      ? new Date(s.date + 'T12:00:00').toLocaleDateString('it-IT').replace(/\//g, '-')
      : new Date().toLocaleDateString('it-IT').replace(/\//g, '-');
    await shareOrDownload(blob, 'serata-' + date + '.png', 'Risultati Ultima Serata');
    showToast('Foto serata pronta!');
  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }
  if (btn) { btn.disabled = false; btn.textContent = '📸 Salva serata'; }
}

// ── PROFILO ───────────────────────────────────
async function saveProfilo() {
  var btn = document.getElementById('btnSaveProfilo');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Generando...'; }

  try {
    var nameEl  = document.querySelector('.profile-name');
    var emojiEl = document.querySelector('.profile-avatar-big');
    var mediaEl = document.querySelector('.vs-value');
    var diffEl  = document.querySelector('.vs-diff');
    var name  = nameEl  ? nameEl.textContent.trim()  : 'Giocatore';
    var emoji = emojiEl ? emojiEl.textContent.trim() : '🎳';
    var media = mediaEl ? mediaEl.textContent.trim() : '—';
    var diff  = diffEl  ? diffEl.textContent.trim()  : '';
    var cards = document.querySelectorAll('.stat-card');
    var id    = parseInt(new URLSearchParams(window.location.search).get('id')) || 1;
    var color = COLORS[(id - 1) % COLORS.length];

    var W = 620, H = 300;
    var mc = makeCanvas(W, H);
    var c = mc.c, ctx = mc.ctx;

    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    var grad = ctx.createLinearGradient(0, 0, W, 0);
    grad.addColorStop(0, color);
    grad.addColorStop(1, color + '44');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, W, 5);

    ctx.beginPath();
    ctx.arc(70, 78, 46, 0, Math.PI * 2);
    ctx.fillStyle = color + '18';
    ctx.fill();
    ctx.strokeStyle = color;
    ctx.lineWidth = 2.5;
    ctx.stroke();
    ctx.font = '42px serif';
    ctx.textAlign = 'center';
    ctx.fillText(emoji, 70, 93);

    ctx.textAlign = 'left';
    ctx.fillStyle = '#e8e8f0';
    ctx.font = 'bold 26px sans-serif';
    ctx.fillText(name, 130, 58);

    ctx.fillStyle = color;
    ctx.font = 'bold 20px monospace';
    ctx.fillText('Media serata: ' + media, 130, 88);

    var diffColor = diff.indexOf('▲') >= 0 ? '#e8ff00' : diff.indexOf('▼') >= 0 ? '#ff3cac' : '#666680';
    ctx.fillStyle = diffColor;
    ctx.font = '12px monospace';
    ctx.fillText(diff, 130, 110);

    ctx.strokeStyle = '#2a2a44';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(20, 130); ctx.lineTo(W - 20, 130); ctx.stroke();

    var statLabels = [], statValues = [], statSubs = [];
    cards.forEach(function(card) {
      var lbl = card.querySelector('.stat-card-label');
      var val = card.querySelector('.stat-card-value');
      var sub = card.querySelector('.stat-card-sub');
      statLabels.push(lbl ? lbl.textContent.trim() : '');
      statValues.push(val ? val.textContent.trim() : '—');
      statSubs.push(sub ? sub.textContent.trim() : '');
    });

    var cardW = (W - 40) / Math.max(statLabels.length, 1);
    statLabels.forEach(function(label, i) {
      var cx = 20 + i * cardW;
      ctx.fillStyle = '#11111a';
      ctx.fillRect(cx, 142, cardW - 8, 90);
      ctx.strokeStyle = color + '33';
      ctx.lineWidth = 1;
      ctx.strokeRect(cx, 142, cardW - 8, 90);

      ctx.fillStyle = '#666680';
      ctx.font = '9px monospace';
      ctx.textAlign = 'center';
      ctx.fillText(label.toUpperCase(), cx + (cardW - 8) / 2, 162);

      ctx.fillStyle = color;
      ctx.font = 'bold 24px monospace';
      ctx.fillText(statValues[i] || '—', cx + (cardW - 8) / 2, 200);

      ctx.fillStyle = '#555570';
      ctx.font = '10px sans-serif';
      ctx.fillText(statSubs[i] || '', cx + (cardW - 8) / 2, 220);

      ctx.textAlign = 'left';
    });

    ctx.fillStyle = '#111120';
    ctx.fillRect(0, H - 24, W, 24);
    ctx.fillStyle = '#444460';
    ctx.font = '10px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('web-production-e43fd.up.railway.app', W / 2, H - 8);

    var blob = await blobFromCanvas(c);
    await shareOrDownload(blob, 'profilo-' + name + '.png', 'Profilo ' + name);
    showToast('Foto profilo pronta!');

  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }
  if (btn) { btn.disabled = false; btn.textContent = '📸 Salva profilo'; }
}