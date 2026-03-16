// ============================================
//  screenshot.js — Genera immagini con Canvas
//  Approccio diretto senza html2canvas
// ============================================

function showToast(msg, type='success') {
  const t = document.getElementById('toast');
  if (!t) return;
  t.textContent = (type==='success'?'✓ ':'✕ ') + msg;
  t.className = `toast ${type} show`;
  setTimeout(() => t.className='toast', 3500);
}

const COLORS = ['#e8ff00','#00f5ff','#ff6b35','#ff3cac','#ffd700','#a78bfa','#34d399','#fb923c','#60a5fa'];
const MEDALS = ['🥇','🥈','🥉'];

function makeCanvas(w, h) {
  const c = document.createElement('canvas');
  c.width = w * 2; c.height = h * 2;
  const ctx = c.getContext('2d');
  ctx.scale(2, 2);
  return { c, ctx };
}

async function blobFromCanvas(canvas) {
  return new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
}

async function shareOrDownload(blob, filename, title) {
  const file = new File([blob], filename, { type: 'image/png' });
  if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
    try {
      await navigator.share({ files: [file], title, text: `🎳 ${title} — Strike Zone` });
      return;
    } catch(e) { if (e.name === 'AbortError') return; }
  }
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename; a.click();
  URL.revokeObjectURL(url);
}

// ── CLASSIFICA ───────────────────────────────
async function saveClassifica() {
  const btn = document.getElementById('btnSaveClassifica');
  if (btn) { btn.disabled=true; btn.textContent='⏳ Generando...'; }

  try {
    const players = (window.cachedPlayers || []).filter(p => parseInt(p.partite) > 0);
    const noGames = (window.cachedPlayers || []).filter(p => parseInt(p.partite) === 0);
    const all     = [...players, ...noGames];

    // Layout colonne
    const COL_RANK   = 44;
    const COL_AVATAR = 86;
    const COL_NAME   = 100;
    const COL_BAR    = 300;  // barra progresso
    const COL_MEDIA  = 490;
    const COL_RECORD = 580;
    const COL_TREND  = 660;
    const W = 720, ROW = 58, PAD = 20;
    const H = PAD + 75 + all.length * ROW + PAD;
    const { c, ctx } = makeCanvas(W, H);

    // Sfondo
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    // Titolo
    ctx.fillStyle = '#e8ff00';
    ctx.font = 'bold 18px monospace';
    ctx.fillText('🎳 STRIKE ZONE — CLASSIFICA', PAD, PAD + 24);
    ctx.fillStyle = '#555570';
    ctx.font = '11px monospace';
    ctx.fillText(new Date().toLocaleDateString('it-IT', {day:'2-digit',month:'long',year:'numeric'}).toUpperCase(), PAD, PAD + 42);

    // Header colonne
    ctx.fillStyle = '#18182a';
    ctx.fillRect(0, PAD + 50, W, 26);
    ctx.strokeStyle = '#2a2a44';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(0, PAD+76); ctx.lineTo(W, PAD+76); ctx.stroke();

    ctx.fillStyle = '#555570';
    ctx.font = 'bold 9px monospace';
    ctx.fillText('#', COL_RANK - 16, PAD + 67);
    ctx.fillText('GIOCATORE', COL_NAME, PAD + 67);
    ctx.textAlign = 'right';
    ctx.fillText('MEDIA', COL_MEDIA, PAD + 67);
    ctx.fillText('RECORD', COL_RECORD, PAD + 67);
    ctx.fillText('TREND', COL_TREND + 10, PAD + 67);
    ctx.textAlign = 'left';

    // Righe giocatori
    all.forEach((p, i) => {
      const y       = PAD + 78 + i * ROW;
      const yCenter = y + ROW / 2;
      const color   = COLORS[i % COLORS.length];
      const hasData = parseInt(p.partite) > 0;

      // Sfondo riga alternato
      ctx.fillStyle = i % 2 === 0 ? '#11111a' : '#0d0d16';
      ctx.fillRect(0, y, W, ROW);

      // Separatore
      ctx.strokeStyle = '#1e1e30';
      ctx.lineWidth = 0.5;
      ctx.beginPath(); ctx.moveTo(0, y + ROW); ctx.lineTo(W, y + ROW); ctx.stroke();

      // Rank
      if (i < 3 && hasData) {
        ctx.font = '18px serif';
        ctx.textAlign = 'center';
        ctx.fillText(MEDALS[i], COL_RANK - 12, yCenter + 6);
      } else {
        ctx.fillStyle = '#444460';
        ctx.font = 'bold 11px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(hasData ? String(i+1) : '—', COL_RANK - 12, yCenter + 4);
      }
      ctx.textAlign = 'left';

      // Avatar circle
      ctx.beginPath();
      ctx.arc(COL_AVATAR - 10, yCenter, 17, 0, Math.PI * 2);
      ctx.fillStyle = hasData ? color + '18' : '#1a1a2a';
      ctx.fill();
      ctx.strokeStyle = hasData ? color + '66' : '#2a2a44';
      ctx.lineWidth = 1.5;
      ctx.stroke();
      ctx.font = '16px serif';
      ctx.textAlign = 'center';
      ctx.fillText(p.emoji || '🎳', COL_AVATAR - 10, yCenter + 5);
      ctx.textAlign = 'left';

      // Nome + sottotitolo
      ctx.fillStyle = hasData ? '#e8e8f0' : '#444460';
      ctx.font = hasData ? 'bold 13px sans-serif' : '13px sans-serif';
      ctx.fillText(p.name, COL_NAME, yCenter - 4);
      ctx.fillStyle = '#444460';
      ctx.font = '10px monospace';
      ctx.fillText(`${p.partite} serate · ${p.game_totali||0} game`, COL_NAME, yCenter + 10);

      // Barra media — nella colonna BAR a destra del nome
      if (hasData) {
        const maxM = Math.max(...players.map(x => parseFloat(x.media)||0));
        const pct  = maxM > 0 ? (parseFloat(p.media)||0) / maxM : 0;
        const barW = 130;
        ctx.fillStyle = '#252535';
        ctx.fillRect(COL_BAR, yCenter - 2, barW, 5);
        ctx.fillStyle = color;
        ctx.fillRect(COL_BAR, yCenter - 2, barW * pct, 5);
        // Glow
        ctx.shadowColor = color;
        ctx.shadowBlur = 4;
        ctx.fillRect(COL_BAR, yCenter - 2, barW * pct, 5);
        ctx.shadowBlur = 0;
      }

      // Media — colonna destra
      ctx.fillStyle = hasData ? color : '#2a2a3a';
      ctx.font = hasData ? 'bold 14px monospace' : '13px monospace';
      ctx.textAlign = 'right';
      ctx.fillText(hasData ? String(p.media) : '—', COL_MEDIA, yCenter + 5);

      // Record
      ctx.fillStyle = hasData ? '#00f5ff' : '#2a2a3a';
      ctx.font = '12px monospace';
      ctx.fillText(hasData ? String(p.record) : '—', COL_RECORD, yCenter + 5);

      // Trend sparkline
      if (hasData && p.trend && p.trend.length > 0) {
        const trend  = p.trend;
        const maxT   = Math.max(...trend);
        const minT   = Math.min(...trend);
        const range  = maxT - minT || 1;
        const sparkW = 50;
        const sparkH = 20;
        const barSparkW = sparkW / trend.length - 1;
        const startX = COL_TREND - sparkW + 10;
        const startY = yCenter - sparkH/2;

        trend.forEach((v, ti) => {
          const h    = Math.max(2, ((v - minT) / range) * sparkH);
          const bx   = startX + ti * (barSparkW + 1);
          const by   = startY + sparkH - h;
          const isLast = ti === trend.length - 1;
          ctx.fillStyle = isLast ? color : color + '55';
          if (isLast) {
            ctx.shadowColor = color;
            ctx.shadowBlur  = 4;
          }
          ctx.fillRect(bx, by, barSparkW, h);
          ctx.shadowBlur = 0;
        });
      } else if (hasData) {
        ctx.fillStyle = '#444460';
        ctx.font = '11px monospace';
        ctx.textAlign = 'right';
        ctx.fillText('—', COL_TREND + 10, yCenter + 4);
      }

      ctx.textAlign = 'left';
    });

    // Footer
    ctx.fillStyle = '#111120';
    ctx.fillRect(0, H - 24, W, 24);
    ctx.fillStyle = '#444460';
    ctx.font = '10px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('web-production-e43fd.up.railway.app', W/2, H - 8);

    const blob = await blobFromCanvas(c);
    const date = new Date().toLocaleDateString('it-IT').replace(/\//g,'-');
    await shareOrDownload(blob, `classifica-${date}.png`, 'Classifica Strike Zone');
    showToast('Foto classifica pronta!');

  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }
  if (btn) { btn.disabled=false; btn.textContent='📸 Salva classifica'; }
}

// ── ULTIMA SERATA ─────────────────────────────
async function saveUltimaSerata() {
  const btn = document.getElementById('btnSaveSerata');
  if (btn) { btn.disabled=true; btn.textContent='⏳ Generando...'; }

  try {
    const sessions = window.cachedSessions || [];
    if (!sessions.length) { showToast('Nessuna sessione', 'error'); return; }

    const s      = sessions[0];
    const scores = s.scores || [];
    const teams  = s.teams  || [];

    // Raggruppa per squadra e giocatore
    const byTeam = {};
    teams.forEach(t => { byTeam[t.name] = { name: t.name, total: 0, players: {} }; });
    scores.forEach(sc => {
      if (!byTeam[sc.team_name]) return;
      const team = byTeam[sc.team_name];
      if (!team.players[sc.player_name]) {
        team.players[sc.player_name] = { name: sc.player_name, emoji: sc.emoji, total: 0, games: [] };
      }
      team.players[sc.player_name].total += parseInt(sc.score)||0;
      team.players[sc.player_name].games.push(sc.score);
    });
    Object.values(byTeam).forEach(t => {
      t.total = Object.values(t.players).reduce((s,p) => s+p.total, 0);
    });

    const teamList = Object.values(byTeam);
    const maxTotal = Math.max(...teamList.map(t => t.total));
    const tColors  = ['#e8ff00','#ff3cac','#00f5ff','#ff6b35'];
    const numGames = scores.length ? Math.max(...scores.map(sc => sc.game_number||1)) : 1;

    const W   = 680;
    const ROW = numGames > 1 ? 42 : 36;
    const teamH = teamList.reduce((sum, t) => sum + 44 + Object.keys(t.players).length * ROW + 12, 0);
    const H   = 90 + teamH + 30;
    const { c, ctx } = makeCanvas(W, H);

    // Sfondo
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    // Titolo
    ctx.fillStyle = '#e8ff00';
    ctx.font = 'bold 18px monospace';
    ctx.fillText('🎳 STRIKE ZONE — ULTIMA SERATA', 20, 30);
    ctx.fillStyle = '#666680';
    ctx.font = '11px monospace';
    const dateStr = new Date(s.date + 'T12:00:00').toLocaleDateString('it-IT', {day:'2-digit',month:'long',year:'numeric'}).toUpperCase();
    ctx.fillText(`${dateStr} · ${s.location} · ${numGames} game`, 20, 50);

    // Separator
    ctx.strokeStyle = '#2a2a44';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(20, 62); ctx.lineTo(W-20, 62); ctx.stroke();

    let y = 76;
    teamList.forEach((team, ti) => {
      const color   = tColors[ti % tColors.length];
      const isWin   = team.total === maxTotal && maxTotal > 0;
      const players = Object.values(team.players).sort((a,b) => b.total - a.total);
      const maxPS   = Math.max(...players.map(p => p.total));

      // Team header
      ctx.fillStyle = '#18182a';
      ctx.fillRect(16, y, W-32, 34);
      ctx.strokeStyle = color + '44';
      ctx.lineWidth = 1;
      ctx.strokeRect(16, y, W-32, 34);

      ctx.fillStyle = color;
      ctx.font = 'bold 14px monospace';
      ctx.fillText(team.name.toUpperCase(), 28, y+22);

      // Badge
      ctx.fillStyle = isWin ? 'rgba(232,255,0,0.15)' : 'rgba(255,60,172,0.1)';
      ctx.fillRect(W-130, y+7, 88, 20);
      ctx.fillStyle = isWin ? '#e8ff00' : '#ff3cac';
      ctx.font = 'bold 11px monospace';
      ctx.textAlign = 'center';
      ctx.fillText(isWin ? '🏆 VITTORIA' : 'SCONFITTA', W-86, y+21);

      // Totale squadra
      ctx.fillStyle = isWin ? color : '#666680';
      ctx.font = 'bold 15px monospace';
      ctx.textAlign = 'right';
      ctx.fillText(String(team.total), W-140, y+22);
      ctx.textAlign = 'left';

      y += 40;

      players.forEach(p => {
        const isTop = p.total === maxPS;

        ctx.font = '14px serif';
        ctx.fillText(p.emoji||'🎳', 28, y + 14);

        ctx.fillStyle = isTop ? '#ffd700' : '#e8e8f0';
        ctx.font = isTop ? 'bold 13px sans-serif' : '13px sans-serif';
        ctx.fillText(p.name, 52, y + 14);

        if (numGames > 1) {
          ctx.fillStyle = '#555570';
          ctx.font = '10px monospace';
          const gStr = p.games.map((g,i) => `G${i+1}:${g}`).join('  ');
          ctx.fillText(gStr, 52, y + 28);
        }

        ctx.fillStyle = isTop ? '#ffd700' : color;
        ctx.font = 'bold 15px monospace';
        ctx.textAlign = 'right';
        ctx.fillText(String(p.total), W-32, y + 14);
        ctx.textAlign = 'left';

        ctx.strokeStyle = '#1a1a2a';
        ctx.lineWidth = 0.5;
        ctx.beginPath();
        ctx.moveTo(28, y + ROW - 2);
        ctx.lineTo(W-28, y + ROW - 2);
        ctx.stroke();

        y += ROW;
      });
      y += 14;
    });

    // Footer
    ctx.fillStyle = '#111120';
    ctx.fillRect(0, H-24, W, 24);
    ctx.fillStyle = '#444460';
    ctx.font = '10px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('web-production-e43fd.up.railway.app', W/2, H-8);

    const blob = await blobFromCanvas(c);
    const date = new Date(s.date + 'T12:00:00').toLocaleDateString('it-IT').replace(/\//g,'-');
    await shareOrDownload(blob, `serata-${date}.png`, 'Risultati Ultima Serata');
    showToast('Foto serata pronta!');

  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }
  if (btn) { btn.disabled=false; btn.textContent='📸 Salva serata'; }
}

// ── PROFILO ───────────────────────────────────
async function saveProfilo() {
  const btn = document.getElementById('btnSaveProfilo');
  if (btn) { btn.disabled=true; btn.textContent='⏳ Generando...'; }

  try {
    const name   = document.querySelector('.profile-name')?.textContent?.trim() || 'Giocatore';
    const emoji  = document.querySelector('.profile-avatar-big')?.textContent?.trim() || '🎳';
    const media  = document.querySelector('.vs-value')?.textContent?.trim() || '—';
    const diff   = document.querySelector('.vs-diff')?.textContent?.trim() || '';
    const cards  = document.querySelectorAll('.stat-card');
    const id     = parseInt(new URLSearchParams(window.location.search).get('id')) || 1;
    const color  = COLORS[(id-1) % COLORS.length];

    const W = 620, H = 300;
    const { c, ctx } = makeCanvas(W, H);

    // Sfondo
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    // Stripe colorata
    const grad = ctx.createLinearGradient(0, 0, W, 0);
    grad.addColorStop(0, color);
    grad.addColorStop(1, color + '44');
    ctx.fillStyle = grad;
    ctx.fillRect(0, 0, W, 5);

    // Avatar
    ctx.beginPath();
    ctx.arc(70, 78, 46, 0, Math.PI*2);
    ctx.fillStyle = color + '18';
    ctx.fill();
    ctx.strokeStyle = color;
    ctx.lineWidth = 2.5;
    ctx.stroke();
    ctx.font = '42px serif';
    ctx.textAlign = 'center';
    ctx.fillText(emoji, 70, 93);

    // Nome
    ctx.textAlign = 'left';
    ctx.fillStyle = '#e8e8f0';
    ctx.font = 'bold 26px sans-serif';
    ctx.fillText(name, 130, 58);

    // Media
    ctx.fillStyle = color;
    ctx.font = 'bold 20px monospace';
    ctx.fillText('Media serata: ' + media, 130, 88);

    // Diff vs gruppo
    const diffColor = diff.includes('▲') ? '#e8ff00' : diff.includes('▼') ? '#ff3cac' : '#666680';
    ctx.fillStyle = diffColor;
    ctx.font = '12px monospace';
    ctx.fillText(diff, 130, 110);

    // Separator
    ctx.strokeStyle = '#2a2a44';
    ctx.lineWidth = 1;
    ctx.beginPath(); ctx.moveTo(20, 130); ctx.lineTo(W-20, 130); ctx.stroke();

    // Stats cards
    const statLabels = [], statValues = [], statSubs = [];
    cards.forEach(card => {
      statLabels.push(card.querySelector('.stat-card-label')?.textContent?.trim() || '');
      statValues.push(card.querySelector('.stat-card-value')?.textContent?.trim() || '—');
      statSubs.push(card.querySelector('.stat-card-sub')?.textContent?.trim() || '');
    });

    const cardW = (W - 40) / Math.max(statLabels.length, 1);
    statLabels.forEach((label, i) => {
      const cx = 20 + i * cardW;
      ctx.fillStyle = '#11111a';
      ctx.fillRect(cx, 142, cardW - 8, 90);
      ctx.strokeStyle = color + '33';
      ctx.lineWidth = 1;
      ctx.strokeRect(cx, 142, cardW - 8, 90);

      ctx.fillStyle = '#666680';
      ctx.font = '9px monospace';
      ctx.textAlign = 'center';
      ctx.fillText(label.toUpperCase(), cx + (cardW-8)/2, 162);

      ctx.fillStyle = color;
      ctx.font = 'bold 24px monospace';
      ctx.fillText(statValues[i] || '—', cx + (cardW-8)/2, 200);

      ctx.fillStyle = '#555570';
      ctx.font = '10px sans-serif';
      ctx.fillText(statSubs[i] || '', cx + (cardW-8)/2, 220);

      ctx.textAlign = 'left';
    });

    // Footer
    ctx.fillStyle = '#111120';
    ctx.fillRect(0, H-24, W, 24);
    ctx.fillStyle = '#444460';
    ctx.font = '10px monospace';
    ctx.textAlign = 'center';
    ctx.fillText('web-production-e43fd.up.railway.app', W/2, H-8);

    const blob = await blobFromCanvas(c);
    await shareOrDownload(blob, `profilo-${name}.png`, `Profilo ${name}`);
    showToast('Foto profilo pronta!');

  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }
  if (btn) { btn.disabled=false; btn.textContent='📸 Salva profilo'; }
}
