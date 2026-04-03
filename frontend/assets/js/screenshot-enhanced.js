// ============================================
//  screenshot-enhanced.js — Sistema Screenshot Avanzato
//  Features: Template multipli, Design premium, WhatsApp share
// ============================================

// NOTA: COLORS e MEDALS sono già definiti in app.js, non le ridichiariamo

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

// ══════════════════════════════════════════
// MODAL SELEZIONE TEMPLATE
// ══════════════════════════════════════════

function showTemplateModal(callback) {
  // Crea overlay
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

  // Crea modal
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
      
      <label class="template-option" data-template="minimal" style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
        <input type="radio" name="template" value="minimal" style="width: 20px; height: 20px; accent-color: var(--neon);">
        <div style="flex: 1;">
          <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.2rem;">🏆 Podio (Minimal)</div>
          <div style="font-size: 0.75rem; color: var(--text-muted);">Solo top 3 · 1080x1080px (IG Post)</div>
        </div>
      </label>

      <label class="template-option" data-template="complete" style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
        <input type="radio" name="template" value="complete" checked style="width: 20px; height: 20px; accent-color: var(--neon);">
        <div style="flex: 1;">
          <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.2rem;">📊 Completo</div>
          <div style="font-size: 0.75rem; color: var(--text-muted);">Top 10 giocatori · 1080x1350px (IG Post)</div>
        </div>
      </label>

      <label class="template-option" data-template="story" style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
        <input type="radio" name="template" value="story" style="width: 20px; height: 20px; accent-color: var(--neon);">
        <div style="flex: 1;">
          <div style="font-weight: 600; font-size: 0.95rem; margin-bottom: 0.2rem;">📱 Storia IG</div>
          <div style="font-size: 0.75rem; color: var(--text-muted);">Verticale · 1080x1920px (IG/WhatsApp Story)</div>
        </div>
      </label>

      <label class="template-option" data-template="whatsapp" style="display: flex; align-items: center; gap: 1rem; padding: 1rem; border: 2px solid var(--border); border-radius: 8px; cursor: pointer; transition: all 0.2s;">
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

  // Highlight selected option
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

  // Auto-select checked
  var checked = overlay.querySelector('input[type="radio"]:checked');
  if (checked) {
    checked.closest('.template-option').click();
  }

  // Confirm button
  overlay.querySelector('#template-confirm').addEventListener('click', function() {
    var selected = overlay.querySelector('input[type="radio"]:checked');
    if (selected) {
      callback(selected.value);
      document.body.removeChild(overlay);
    }
  });

  // Cancel button
  overlay.querySelector('#template-cancel').addEventListener('click', function() {
    document.body.removeChild(overlay);
  });

  // Click outside to close
  overlay.addEventListener('click', function(e) {
    if (e.target === overlay) {
      document.body.removeChild(overlay);
    }
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

  // Background
  var bgGrad = ctx.createLinearGradient(0, 0, 0, dim.h);
  bgGrad.addColorStop(0, '#0a0a0f');
  bgGrad.addColorStop(1, '#050508');
  ctx.fillStyle = bgGrad;
  ctx.fillRect(0, 0, dim.w, dim.h);

  // Accent bar top
  var accentGrad = ctx.createLinearGradient(0, 0, dim.w, 0);
  accentGrad.addColorStop(0, '#e8ff00');
  accentGrad.addColorStop(0.5, '#00f5ff');
  accentGrad.addColorStop(1, '#ff3cac');
  ctx.fillStyle = accentGrad;
  ctx.fillRect(0, 0, dim.w, 8);

  // Header
  ctx.fillStyle = '#e8ff00';
  ctx.font = 'bold 72px sans-serif';
  ctx.textAlign = 'center';
  ctx.fillText('🎳 STRIKE ZONE', dim.w / 2, 100);

  ctx.fillStyle = '#666680';
  ctx.font = '28px monospace';
  var dateStr = new Date().toLocaleDateString('it-IT', { day: '2-digit', month: 'long', year: 'numeric' }).toUpperCase();
  ctx.fillText('CLASSIFICA · ' + dateStr, dim.w / 2, 145);

  // Separator line
  ctx.strokeStyle = '#2a2a44';
  ctx.lineWidth = 2;
  ctx.beginPath();
  ctx.moveTo(80, 180);
  ctx.lineTo(dim.w - 80, 180);
  ctx.stroke();

  // Players data
  var startY = 220;
  var rowHeight = template === 'minimal' ? 150 : (template === 'story' ? 140 : 100);
  var maxPlayers = template === 'minimal' ? 3 : (template === 'story' ? 10 : 10);

  data.players.slice(0, maxPlayers).forEach(function(player, i) {
    var y = startY + i * rowHeight;
    var isTopThree = i < 3;
    var color = isTopThree ? (i === 0 ? '#ffd700' : i === 1 ? '#c0c0d0' : '#cd7f32') : COLORS[i % COLORS.length];

    // Medal/Rank
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

    // Avatar circle
    var avatarX = template === 'minimal' ? 220 : 200;
    ctx.beginPath();
    ctx.arc(avatarX, y + 35, 45, 0, Math.PI * 2);
    ctx.fillStyle = color + '22';
    ctx.fill();
    ctx.strokeStyle = color;
    ctx.lineWidth = 4;
    ctx.stroke();

    // Emoji
    ctx.font = '50px serif';
    ctx.textAlign = 'center';
    ctx.fillText(player.emoji || '🎳', avatarX, y + 50);

    // Name
    ctx.fillStyle = '#e8e8f0';
    ctx.font = 'bold 44px sans-serif';
    ctx.textAlign = 'left';
    ctx.fillText(player.name, avatarX + 80, y + 30);

    // Stats subtitle
    ctx.fillStyle = '#666680';
    ctx.font = '24px monospace';
    ctx.fillText(player.partite + ' serate · ' + (player.game_totali || 0) + ' game', avatarX + 80, y + 65);

    // Media (right side)
    ctx.fillStyle = color;
    ctx.font = 'bold 64px sans-serif';
    ctx.textAlign = 'right';
    ctx.fillText(player.media || '—', dim.w - 90, y + 50);

    // Lightning bolt
    if (parseFloat(player.media) >= 140) {
      ctx.font = '40px serif';
      ctx.fillText('⚡', dim.w - 200, y + 50);
    }

    // Mini separator
    if (i < maxPlayers - 1) {
      ctx.strokeStyle = '#1a1a2a';
      ctx.lineWidth = 1;
      ctx.beginPath();
      ctx.moveTo(90, y + rowHeight - 20);
      ctx.lineTo(dim.w - 90, y + rowHeight - 20);
      ctx.stroke();
    }
  });

  // Footer stats
  var footerY = dim.h - 180;
  ctx.fillStyle = '#11111a';
  ctx.fillRect(0, footerY, dim.w, 180);

  ctx.fillStyle = '#666680';
  ctx.font = '28px monospace';
  ctx.textAlign = 'center';
  ctx.fillText('📊 ' + data.totalSessions + ' SERATE · ' + data.totalGames + ' PARTITE', dim.w / 2, footerY + 50);
  ctx.fillText('🏆 RECORD: ' + data.record + ' (' + data.recordHolder + ')', dim.w / 2, footerY + 90);
  ctx.fillText('⚡ MEDIA GRUPPO: ' + data.avgGroup, dim.w / 2, footerY + 130);

  // URL footer
  ctx.fillStyle = '#444460';
  ctx.font = '22px monospace';
  ctx.fillText('web-production-e43fd.up.railway.app', dim.w / 2, dim.h - 30);

  return blobFromCanvas(c);
}

// ══════════════════════════════════════════
// CONDIVISIONE WHATSAPP DIRETTA
// ══════════════════════════════════════════

async function shareToWhatsApp(blob, message) {
  var file = new File([blob], 'classifica-strike-zone.png', { type: 'image/png' });
  
  // Try Web Share API first (mobile)
  if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
    try {
      await navigator.share({
        files: [file],
        title: 'Strike Zone - Classifica',
        text: message
      });
      return true;
    } catch(e) {
      if (e.name === 'AbortError') return false;
    }
  }

  // Fallback: WhatsApp Web link (desktop)
  var url = URL.createObjectURL(blob);
  var a = document.createElement('a');
  a.href = url;
  a.download = 'classifica-strike-zone.png';
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  
  setTimeout(function() {
    URL.revokeObjectURL(url);
    // Open WhatsApp Web with pre-filled message
    var encodedMsg = encodeURIComponent(message + '\n\n📸 Immagine scaricata - allegala manualmente');
    window.open('https://wa.me/?text=' + encodedMsg, '_blank');
  }, 500);
  
  return true;
}

// ══════════════════════════════════════════
// FUNZIONE PRINCIPALE
// ══════════════════════════════════════════

async function saveFotoClassifica() {
  showTemplateModal(async function(template) {
    var btn = document.getElementById('btnSaveClassifica');
    if (btn) { 
      btn.disabled = true; 
      btn.textContent = '⏳ Generando...'; 
    }

    try {
      // Raccolta dati dalla classifica
      var players = window.cachedPlayers || [];
      var playersWithGames = players.filter(p => parseInt(p.partite) > 0);
      
      // Ordina per media
      playersWithGames.sort((a, b) => {
        return parseFloat(b.media || 0) - parseFloat(a.media || 0);
      });

      // Calcola stats globali
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

      // Genera immagine custom
      var blob = await generateCustomImage(template, data);

      // Messaggio WhatsApp
      var topPlayer = playersWithGames[0];
      var whatsappMsg = `🎳 *Classifica Strike Zone Aggiornata!*\n\n` +
        `🥇 ${topPlayer.name} in testa con ${topPlayer.media}!\n` +
        `🏆 Record: ${record} (${recordPlayer.name})\n` +
        `📊 ${totalSessions} serate giocate\n\n` +
        `Vedi la classifica completa su:\nweb-production-e43fd.up.railway.app`;

      // Condividi
      if (template === 'whatsapp') {
        var shared = await shareToWhatsApp(blob, whatsappMsg);
        if (shared) {
          showToast('Immagine pronta per WhatsApp!');
        }
      } else {
        // Share normale o download
        var file = new File([blob], 'strike-zone-' + template + '.png', { type: 'image/png' });
        if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
          try {
            await navigator.share({
              files: [file],
              title: 'Strike Zone - Classifica',
              text: whatsappMsg
            });
          } catch(e) {
            if (e.name !== 'AbortError') {
              // Download fallback
              var url = URL.createObjectURL(blob);
              var a = document.createElement('a');
              a.href = url;
              a.download = 'strike-zone-' + template + '.png';
              document.body.appendChild(a);
              a.click();
              document.body.removeChild(a);
              setTimeout(() => URL.revokeObjectURL(url), 1000);
            }
          }
        } else {
          // Download
          var url = URL.createObjectURL(blob);
          var a = document.createElement('a');
          a.href = url;
          a.download = 'strike-zone-' + template + '.png';
          document.body.appendChild(a);
          a.click();
          document.body.removeChild(a);
          setTimeout(() => URL.revokeObjectURL(url), 1000);
        }
        showToast('Immagine pronta!');
      }

    } catch(e) {
      console.error(e);
      showToast('Errore nella generazione', 'error');
    }

    if (btn) { 
      btn.disabled = false; 
      btn.textContent = '📸 Salva foto'; 
    }
  });
}

// ══════════════════════════════════════════
// MANTIENI LE ALTRE FUNZIONI ESISTENTI
// ══════════════════════════════════════════

// Funzioni vecchie per compatibilità
async function saveUltimaSerata() {
  showToast('Feature in sviluppo!', 'error');
}

async function saveClassificaStatistiche() {
  showToast('Feature in sviluppo!', 'error');
}

async function saveProfilo() {
  showToast('Feature in sviluppo!', 'error');
}