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
      return; // Se share riesce, esci subito
    } catch(e) {
      if (e.name === 'AbortError') return; // Utente ha annullato
      // Se share fallisce, fai il download come fallback
    }
  }
  // Fallback: scarica direttamente
  const url = URL.createObjectURL(blob);
  const a = document.createElement('a');
  a.href = url; a.download = filename;
  document.body.appendChild(a);
  a.click();
  document.body.removeChild(a);
  setTimeout(() => URL.revokeObjectURL(url), 1000);
}

// ── CLASSIFICA ───────────────────────────────
async function saveClassifica() {
  const btn = document.getElementById('btnSaveClassifica');
  if (btn) { btn.disabled=true; btn.textContent='⏳ Generando...'; }

  try {
    // Carica html2canvas se non ancora caricato
    if (typeof html2canvas === 'undefined') {
      await new Promise((resolve, reject) => {
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
        s.onload = resolve; s.onerror = reject;
        document.head.appendChild(s);
      });
    }

    const table = document.querySelector('.leaderboard-table');
    if (!table) { showToast('Classifica non trovata', 'error'); return; }

    // Crea un wrapper temporaneo con sfondo e padding
    const wrapper = document.createElement('div');
    wrapper.style.cssText = `
      position:fixed; top:-9999px; left:-9999px;
      background:#0a0a0f;
      padding:20px;
      border-radius:12px;
      width:${table.offsetWidth + 40}px;
      font-family:'Barlow Condensed',sans-serif;
    `;

    // Titolo
    const title = document.createElement('div');
    title.style.cssText = 'display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;padding:0 4px';
    title.innerHTML = `
      <div>
        <div style="font-family:'Black Han Sans',sans-serif;font-size:22px;color:#e8ff00;text-shadow:0 0 20px rgba(232,255,0,0.5);letter-spacing:0.05em">🎳 STRIKE ZONE</div>
        <div style="font-family:'Share Tech Mono',monospace;font-size:10px;color:#666680;letter-spacing:0.2em;text-transform:uppercase;margin-top:2px">Classifica · ${new Date().toLocaleDateString('it-IT', {day:'2-digit',month:'long',year:'numeric'}).toUpperCase()}</div>
      </div>
      <div style="font-size:28px">🏆</div>`;

    // Clona la tabella
    const clone = table.cloneNode(true);
    clone.style.margin = '0';

    wrapper.appendChild(title);
    wrapper.appendChild(clone);
    document.body.appendChild(wrapper);

    // Cattura con html2canvas
    const canvas = await html2canvas(wrapper, {
      backgroundColor: '#0a0a0f',
      scale: 2,
      useCORS: true,
      logging: false,
    });

    document.body.removeChild(wrapper);

    const blob = await blobFromCanvas(canvas);
    const date = new Date().toLocaleDateString('it-IT').replace(/\//g,'-');
    await shareOrDownload(blob, `classifica-${date}.png`, 'Classifica Strike Zone');
    showToast('Foto classifica pronta!');

  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }
  if (btn) { btn.disabled=false; btn.textContent='📸 Salva foto'; }
}
// ── CLASSIFICA ULTIMA SERATA ────────────────────
async function saveClassificaUltimaSerata() {
  const btn = document.getElementById('btnSaveClassifica');
  if (btn) { btn.disabled=true; btn.textContent='⏳ Generando...'; }

  try {
    const sessions = window.cachedSessions || [];
    if (!sessions.length) { showToast('Nessuna sessione', 'error'); return; }

    const s      = sessions[0];
    const scores = s.scores || [];

    // Raggruppa per giocatore e somma i game
    const byPlayer = {};
    scores.forEach(sc => {
      if (!byPlayer[sc.player_name]) {
        byPlayer[sc.player_name] = { name: sc.player_name, emoji: sc.emoji, total: 0, team: sc.team_name };
      }
      byPlayer[sc.player_name].total += parseInt(sc.score)||0;
    });

    // Ordina per totale decrescente
    const players = Object.values(byPlayer).sort((a,b) => b.total - a.total);
    const noGames = (window.cachedPlayers || []).filter(p =>
      !players.find(x => x.name === p.name)
    );

    const W = 700, ROW = 52, PAD = 20;
    const H = PAD + 75 + players.length * ROW + PAD;
    const { c, ctx } = makeCanvas(W, H);

    // Sfondo
    ctx.fillStyle = '#0a0a0f';
    ctx.fillRect(0, 0, W, H);

    // Titolo
    ctx.fillStyle = '#e8ff00';
    ctx.font = 'bold 18px monospace';
    ctx.fillText('🎳 STRIKE ZONE — ULTIMA SERATA', PAD, PAD + 24);
    ctx.fillStyle = '#555570';
    ctx.font = '11px monospace';
    const dateStr = new Date(s.date + 'T12:00:00').toLocaleDateString('it-IT', {day:'2-digit',month:'long',year:'numeric'}).toUpperCase();
    ctx.fillText(`${dateStr} · ${s.location}`, PAD, PAD + 42);

    // Header
    ctx.fillStyle = '#18182a';
    ctx.fillRect(0, PAD + 50, W, 26);
    ctx.fillStyle = '#555570';
    ctx.font = 'bold 9px monospace';
    ctx.fillText('#', 28, PAD + 67);
    ctx.fillText('GIOCATORE', 100, PAD + 67);
    ctx.textAlign = 'right';
    ctx.fillText('SQUADRA', 480, PAD + 67);
    ctx.fillText('TOTALE', 620, PAD + 67);
    ctx.textAlign = 'left';

    // Righe giocatori
    players.forEach((p, i) => {
      const y      = PAD + 78 + i * ROW;
      const yCenter = y + ROW / 2;
      const color  = COLORS[i % COLORS.length];

      ctx.fillStyle = i % 2 === 0 ? '#11111a' : '#0d0d16';
      ctx.fillRect(0, y, W, ROW);

      // Rank
      if (i < 3) {
        ctx.font = '18px serif';
        ctx.textAlign = 'center';
        ctx.fillText(MEDALS[i], 32, yCenter + 6);
      } else {
        ctx.fillStyle = '#444460';
        ctx.font = 'bold 11px monospace';
        ctx.textAlign = 'center';
        ctx.fillText(String(i+1), 32, yCenter + 4);
      }
      ctx.textAlign = 'left';

      // Avatar
      ctx.beginPath();
      ctx.arc(76, yCenter, 17, 0, Math.PI*2);
      ctx.fillStyle = color + '18';
      ctx.fill();
      ctx.strokeStyle = color + '66';
      ctx.lineWidth = 1.5;
      ctx.stroke();
      ctx.font = '16px serif';
      ctx.textAlign = 'center';
      ctx.fillText(p.emoji || '🎳', 76, yCenter + 5);
      ctx.textAlign = 'left';

      // Nome
      ctx.fillStyle = i === 0 ? '#ffd700' : '#e8e8f0';
      ctx.font = i === 0 ? 'bold 14px sans-serif' : '13px sans-serif';
      ctx.fillText(p.name, 100, yCenter + 4);

      // Squadra
      ctx.fillStyle = '#555570';
      ctx.font = '10px monospace';
      ctx.textAlign = 'right';
      ctx.fillText(p.team || '—', 480, yCenter + 4);

      // Totale
      ctx.fillStyle = i === 0 ? '#ffd700' : color;
      ctx.font = 'bold 15px monospace';
      ctx.fillText(String(p.total), 620, yCenter + 4);
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
    const date = new Date(s.date + 'T12:00:00').toLocaleDateString('it-IT').replace(/\//g,'-');
    await shareOrDownload(blob, `classifica-serata-${date}.png`, 'Classifica Ultima Serata');
    showToast('Foto classifica serata pronta!');

  } catch(e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }

  if (btn) { btn.disabled=false; btn.textContent='📸 Salva foto'; }
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