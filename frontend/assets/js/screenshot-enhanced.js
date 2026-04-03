// ============================================
//  screenshot-enhanced.js — Sistema Screenshot Completo
//  Vecchie funzioni + Template Modal + Design Premium
// ============================================

// NOTA: COLORS e MEDALS sono definiti in app.js

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
  var styleTag = document.createElement('style');
  styleTag.id = 'sz-no-anim';
  styleTag.textContent = '#sz-capture-wrap, #sz-capture-wrap * { animation: none !important; transition: none !important; opacity: 1 !important; }';
  document.head.appendChild(styleTag);

  var wrapper = document.createElement('div');
  wrapper.id = 'sz-capture-wrap';
  wrapper.style.position = 'fixed';
  wrapper.style.top = '-9999px';
  wrapper.style.left = '-9999px';
  wrapper.style.background = '#0a0a0f';
  wrapper.style.padding = '20px';
  wrapper.style.borderRadius = '12px';
  wrapper.style.width = (el.offsetWidth + 40) + 'px';

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

  var clone = el.cloneNode(true);
  clone.style.margin = '0';

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

// ══════════════════════════════════════════
// MODAL SELEZIONE TEMPLATE
// ══════════════════════════════════════════

function showTemplateModal(callback) {
  var overlay = document.createElement('div');
  overlay.id = 'template-modal-overlay';
  overlay.style.cssText = `
    position: fixed;
    top: 0; left: 0; right: 0; bottom: 0;
    background: rgba(0,0,0,0.8);
    display: flex;
    align-items: center;
    justify-content: center;
    z-index: 10000;
    animation: fadeIn 0.2s ease;
  `;

  var modal = document.createElement('div');
  modal.style.cssText = `
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 2rem;
    max-width: 500px;
    width: 90%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.5);
  `;

  modal.innerHTML = `
    <div style="margin-bottom: 1.5rem;">
      <div style="font-family: 'Black Han Sans', sans-serif; font-size: 1.4rem; color: var(--neon); margin-bottom: 0.5rem;">
        📸 Scegli Formato Immagine
      </div>
      <div style="font-family: 'Share Tech Mono', monospace; font-size: 0.65rem; color: var(--text-muted); letter-spacing: 0.1em;">
        Seleziona il formato ottimale per la condivisione
      </div>
    </div>

    <div style="display: flex; flex-direction: column; gap: 0.8rem; margin-bottom: 1.5rem;">
      
      <label class="template-option" style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
        <input type="radio" name="template" value="screenshot" checked style="width: 20px; height: 20px; accent-color: var(--neon);">
        <div style="flex: 1;">
          <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.2rem;">📋 Screenshot Classico</div>
          <div style="font-size: 0.75rem; color: var(--text-muted);">Screenshot della tabella (metodo attuale)</div>
        </div>
      </label>

      <label class="template-option" style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
        <input type="radio" name="template" value="minimal" style="width: 20px; height: 20px; accent-color: var(--neon);">
        <div style="flex: 1;">
          <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.2rem;">🏆 Podio (Minimal)</div>
          <div style="font-size: 0.75rem; color: var(--text-muted);">Solo top 3 · 1080x1080px (IG Post)</div>
        </div>
      </label>

      <label class="template-option" style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
        <input type="radio" name="template" value="complete" style="width: 20px; height: 20px; accent-color: var(--neon);">
        <div style="flex: 1;">
          <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.2rem;">📊 Completo</div>
          <div style="font-size: 0.75rem; color: var(--text-muted);">Top 10 giocatori · 1080x1350px (IG Post)</div>
        </div>
      </label>

      <label class="template-option" style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
        <input type="radio" name="template" value="story" style="width: 20px; height: 20px; accent-color: var(--neon);">
        <div style="flex: 1;">
          <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.2rem;">📱 Storia IG</div>
          <div style="font-size: 0.75rem; color: var(--text-muted);">Verticale · 1080x1920px (IG/WhatsApp Story)</div>
        </div>
      </label>

      <label class="template-option" style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
        <input type="radio" name="template" value="whatsapp" style="width: 20px; height: 20px; accent-color: var(--neon);">
        <div style="flex: 1;">
          <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.2rem;">💬 WhatsApp</div>
          <div style="font-size: 0.75rem; color: var(--text-muted);">Quadrato · 1080x1080px + Condivisione rapida</div>
        </div>
      </label>

    </div>

    <div style="display: flex; gap: 0.8rem;">
      <button id="template-cancel" style="flex: 1; padding: 0.8rem; background: var(--surface2); border: 1px solid var(--border); color: var(--text); border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 600; transition: all 0.2s;">
        Annulla
      </button>
      <button id="template-confirm" style="flex: 2; padding: 0.8rem; background: var(--neon); border: none; color: var(--bg); border-radius: 6px; cursor: pointer; font-size: 0.9rem; font-weight: 700; transition: all 0.2s;">
        Genera Immagine
      </button>
    </div>
  `;

  overlay.appendChild(modal);
  document.body.appendChild(overlay);

  overlay.querySelectorAll('.template-option').forEach(opt => {
    opt.addEventListener('click', function() {
      overlay.querySelectorAll('.template-option').forEach(o => {
        o.style.borderColor = 'var(--border)';
        o.style.background = 'transparent';
      });
      this.style.borderColor = 'var(--neon)';
      this.style.background = 'rgba(232,255,0,0.05)';
      this.querySelector('input').checked = true;
    });
  });

  var checked = overlay.querySelector('input[type="radio"]:checked');
  if (checked) checked.closest('.template-option').click();

  overlay.querySelector('#template-confirm').addEventListener('click', function() {
    var selected = overlay.querySelector('input[type="radio"]:checked');
    if (selected) {
      callback(selected.value);
      document.body.removeChild(overlay);
    }
  });

  overlay.querySelector('#template-cancel').addEventListener('click', function() {
    document.body.removeChild(overlay);
  });

  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) document.body.removeChild(overlay);
  });
}

// ══════════════════════════════════════════
// GENERA IMMAGINE CUSTOM CON CANVAS
// ══════════════════════════════════════════

async function generateCustomImage(template, data) {
  var dimensions = {
    minimal: { w: 1080, h: 1080 },
    complete: { w: 1080, h: 1350 },
    story: { w: 1080, h: 1920 },
    whatsapp: { w: 1080, h: 1080 }
  };

  var dim = dimensions[template];
  var mc = makeCanvas(dim.w, dim.h);
  var c = mc.c, ctx = mc.ctx;

  var bgGrad = ctx.createLinearGradient(0, 0, 0, dim.h);
  bgGrad.addColorStop(0, '#0a0a0f');
  bgGrad.addColorStop(1, '#050508');
  ctx.fillStyle = bgGrad;
  ctx.fillRect(0, 0, dim.w, dim.h);

  var accentGrad = ctx.createLinearGradient(0, 0, dim.w, 0);
  accentGrad.addColorStop(0, '#e8ff00');
  accentGrad.addColorStop(0.5, '#00f5ff');
  accentGrad.addColorStop(1, '#ff3cac');
  ctx.fillStyle = accentGrad;
  ctx.fillRect(0, 0, dim.w, 8);

  ctx.fillStyle = '#e8ff00';
  ctx.font = 'bold 72px sans-serif';
  ctx.textAlign = 'center';
  ctx.fillText('🎳 STRIKE ZONE', dim.w / 2, 100);

  ctx.fillStyle = '#666680';
  ctx.font = '28px monospace';
  var dateStr = new Date().toLocaleDateString('it-IT', { day: '2-digit', month: 'long', year: 'numeric' }).toUpperCase();
  ctx.fillText('CLASSIFICA · ' + dateStr, dim.w / 2, 145);

  ctx.strokeStyle = '#2a2a44';
  ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.moveTo(80, 180);
  ctx.lineTo(dim.w - 80, 180);
  ctx.stroke();

  var startY = 220;
  var rowHeight = template === 'minimal' ? 150 : (template === 'story' ? 140 : 100);
  var maxPlayers = template === 'minimal' ? 3 : (template === 'story' ? 10 : 10);

  data.players.slice(0, maxPlayers).forEach(function(player, i) {
    var y = startY + i * rowHeight;
    var isTopThree = i < 3;
    var color = isTopThree ? (i === 0 ? '#ffd700' : i === 1 ? '#c0c0d0' : '#cd7f32') : COLORS[i % COLORS.length];

    if (isTopThree) {
      ctx.font = '80px serif';
      ctx.textAlign = 'left';
      ctx.fillText(MEDALS[i], 90, y + 60);
    } else {
      ctx.fillStyle = color;
      ctx.font = 'bold 48px monospace';
      ctx.textAlign = 'left';
      ctx.fillText((i + 1).toString(), 100, y + 50);
    }

    var avatarX = template === 'minimal' ? 220 : 200;
    ctx.beginPath();
    ctx.arc(avatarX, y + 35, 45, 0, Math.PI * 2);
    ctx.fillStyle = color + '22';
    ctx.fill();
    ctx.strokeStyle = color;
    ctx.lineWidth = 4;
    ctx.stroke();

    ctx.font = '50px serif';
    ctx.textAlign = 'center';
    ctx.fillText(player.emoji || '🎳', avatarX, y + 50);

    ctx.fillStyle = '#e8e8f0';
    ctx.font = 'bold 44px sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText(player.name, avatarX + 80, y + 30);

    ctx.fillStyle = '#666680';
    ctx.font = '24px monospace';
    ctx.fillText(player.partite + ' serate · ' + (player.game_totali || 0) + ' game', avatarX + 80, y + 65);

    ctx.fillStyle = color;
    ctx.font = 'bold 64px sans-serif';
    ctx.textAlign = 'right';
    ctx.fillText(player.media || '—', dim.w - 90, y + 50);

    if (parseFloat(player.media) >= 140) {
      ctx.font = '40px serif';
      ctx.fillText('⚡', dim.w - 200, y + 50);
    }

    if (i < maxPlayers - 1) {
      ctx.strokeStyle = '#1a1a2a';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(90, y + rowHeight - 20);
      ctx.lineTo(dim.w - 90, y + rowHeight - 20);
      ctx.stroke();
    }
  });

  var footerY = dim.h - 180;
  ctx.fillStyle = '#11111a';
  ctx.fillRect(0, footerY, dim.w, 180);

  ctx.fillStyle = '#666680';
  ctx.font = '28px monospace';
  ctx.textAlign = 'center';
  ctx.fillText('📊 ' + data.totalSessions + ' SERATE · ' + data.totalGames + ' PARTITE', dim.w / 2, footerY + 50);
  ctx.fillText('🏆 RECORD: ' + data.record + ' (' + data.recordHolder + ')', dim.w / 2, footerY + 90);
  ctx.fillText('⚡ MEDIA GRUPPO: ' + data.avgGroup, dim.w / 2, footerY + 130);

  ctx.fillStyle = '#444460';
  ctx.font = '22px monospace';
  ctx.fillText('web-production-e43fd.up.railway.app', dim.w / 2, dim.h - 30);

  return blobFromCanvas(c);
}

// ══════════════════════════════════════════
// FUNZIONI CLASSIFICA (vecchie + nuove)
// ══════════════════════════════════════════

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

function saveFotoClassifica() {
  showTemplateModal(async function(template) {
    if (template === 'screenshot') {
      // Usa il metodo vecchio
      if (window.leaderboardMode === 'last') {
        saveClassificaUltimaSerata();
      } else {
        saveClassifica();
      }
      return;
    }

    // Usa il metodo nuovo con canvas
    var btn = document.getElementById('btnSaveClassifica');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Generando...'; }

    try {
      var players = window.cachedPlayers || [];
      var playersWithGames = players.filter(p => parseInt(p.partite) > 0);
      
      playersWithGames.sort((a, b) => parseFloat(b.media || 0) - parseFloat(a.media || 0));

      var totalSessions = playersWithGames.length ? playersWithGames[0].partite : 0;
      var totalGames = playersWithGames.reduce((sum, p) => sum + parseInt(p.game_totali || 0), 0);
      var record = Math.max(...playersWithGames.map(p => parseInt(p.record || 0)));
      var recordPlayer = playersWithGames.find(p => parseInt(p.record) === record);
      var avgGroup = playersWithGames.length 
        ? (playersWithGames.reduce((sum, p) => sum + parseFloat(p.media || 0), 0) / playersWithGames.length).toFixed(1)
        : '0';

      var data = {
        players: playersWithGames,
        totalSessions: totalSessions,
        totalGames: totalGames,
        record: record,
        recordHolder: recordPlayer ? recordPlayer.name : '—',
        avgGroup: avgGroup
      };

      var blob = await generateCustomImage(template, data);

      var topPlayer = playersWithGames[0];
      var whatsappMsg = `🎳 *Classifica Strike Zone Aggiornata!*\n\n` +
        `🥇 ${topPlayer.name} in testa con ${topPlayer.media}!\n` +
        `🏆 Record: ${record} (${recordPlayer.name})\n` +
        `📊 ${totalSessions} serate giocate\n\n` +
        `Vedi la classifica completa su:\nweb-production-e43fd.up.railway.app`;

      if (template === 'whatsapp') {
        var file = new File([blob], 'classifica-strike-zone.png', { type: 'image/png' });
        if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
          try {
            await navigator.share({ files: [file], title: 'Strike Zone', text: whatsappMsg });
            showToast('Immagine condivisa!');
          } catch(e) {
            if (e.name !== 'AbortError') {
              await shareOrDownload(blob, 'strike-zone-' + template + '.png', 'Strike Zone');
              showToast('Immagine pronta!');
            }
          }
        } else {
          await shareOrDownload(blob, 'classifica-strike-zone.png', 'Strike Zone');
          setTimeout(() => {
            var encodedMsg = encodeURIComponent(whatsappMsg + '\n\n📸 Immagine scaricata');
            window.open('https://wa.me/?text=' + encodedMsg, '_blank');
          }, 500);
        }
      } else {
        await shareOrDownload(blob, 'strike-zone-' + template + '.png', 'Strike Zone');
        showToast('Immagine pronta!');
      }

    } catch(e) {
      console.error(e);
      showToast('Errore nella generazione', 'error');
    }

    if (btn) { btn.disabled = false; btn.textContent = '📸 Salva foto'; }
  });
}

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

async function saveClassificaStatistiche() {
  var btn = document.getElementById('btnSaveClassificaStats');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Generando...'; }
  try {
    await loadHtml2Canvas();
    var table = document.querySelector('.rank-table-wrap');
    if (!table) { showToast('Classifica non trovata', 'error'); return; }
    var blob = await captureElementToBlob(table);
    var date = new Date().toLocaleDateString('it-IT').replace(/\//g, '-');
    await shareOrDownload(blob, 'classifica-statistiche-' + date + '.png', 'Classifica Statistiche');
    showToast('Foto classifica pronta!');
  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }
  if (btn) { btn.disabled = false; btn.textContent = '📸 Salva classifica'; }
}

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