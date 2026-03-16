// ============================================
//  screenshot.js — Salva e condividi foto
//  Usa html2canvas + Web Share API
// ============================================

// Carica html2canvas dinamicamente
function loadHtml2Canvas() {
  return new Promise((resolve, reject) => {
    if (window.html2canvas) { resolve(); return; }
    const s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/html2canvas/1.4.1/html2canvas.min.js';
    s.onload  = resolve;
    s.onerror = reject;
    document.head.appendChild(s);
  });
}

// Cattura un elemento e restituisce un blob
async function captureElement(el) {
  await loadHtml2Canvas();

  // Aggiungi padding e sfondo per foto più belle
  const canvas = await html2canvas(el, {
    backgroundColor: '#0a0a0f',
    scale: 2,
    useCORS: true,
    logging: false,
    onclone: (doc, clone) => {
      clone.style.padding = '20px';
    }
  });

  return new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
}

// Scarica o condividi l'immagine
async function shareImage(blob, filename, title) {
  const file = new File([blob], filename, { type: 'image/png' });

  // Prova Web Share API (funziona su iPhone e Android)
  if (navigator.share && navigator.canShare && navigator.canShare({ files: [file] })) {
    try {
      await navigator.share({
        files: [file],
        title: title,
        text: `🎳 ${title} — Strike Zone Bowling Tracker`
      });
      return;
    } catch (e) {
      if (e.name === 'AbortError') return; // Utente ha annullato
    }
  }

  // Fallback: scarica direttamente
  const url = URL.createObjectURL(blob);
  const a   = document.createElement('a');
  a.href     = url;
  a.download = filename;
  a.click();
  URL.revokeObjectURL(url);
}

// ── FUNZIONI PUBBLICHE ────────────────────────

async function saveClassifica() {
  const btn = document.getElementById('btnSaveClassifica');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Generando...'; }

  try {
    await loadHtml2Canvas();

    const el = document.querySelector('.leaderboard-table');
    if (!el) throw new Error('Elemento non trovato');

    // Forza colori espliciti prima della cattura
    const canvas = await html2canvas(el, {
      backgroundColor: '#11111a',
      scale: 2,
      useCORS: true,
      logging: false,
      width: el.scrollWidth,
      height: el.scrollHeight,
      windowWidth: el.scrollWidth,
      windowHeight: el.scrollHeight,
      onclone: (doc, clone) => {
        // Sostituisci CSS variables con valori reali
        clone.querySelectorAll('*').forEach(node => {
          const style = node.style;
          const computed = window.getComputedStyle(node);
          ['color','background-color','border-color'].forEach(prop => {
            const val = computed.getPropertyValue(prop);
            if (val) node.style[prop] = val;
          });
        });
      }
    });

    const blob = await new Promise(resolve => canvas.toBlob(resolve, 'image/png'));
    const date = new Date().toLocaleDateString('it-IT').replace(/\//g,'-');
    await shareImage(blob, `classifica-${date}.png`, 'Classifica Strike Zone');

    showToast('Foto classifica pronta!');
  } catch (e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }

  if (btn) { btn.disabled = false; btn.textContent = '📸 Salva classifica'; }
}

async function saveUltimaSerata() {
  const btn = document.getElementById('btnSaveSerata');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Generando...'; }

  try {
    const el = document.getElementById('last-session-card');
    if (!el) throw new Error('Elemento non trovato');

    const blob = await captureElement(el);
    const date = new Date().toLocaleDateString('it-IT').replace(/\//g,'-');
    await shareImage(blob, `serata-${date}.png`, 'Risultati Ultima Serata');

    showToast('Foto serata pronta!');
  } catch (e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }

  if (btn) { btn.disabled = false; btn.textContent = '📸 Salva serata'; }
}

async function saveProfilo() {
  const btn = document.getElementById('btnSaveProfilo');
  if (btn) { btn.disabled = true; btn.textContent = '⏳ Generando...'; }

  try {
    const el = document.getElementById('profileHero');
    if (!el) throw new Error('Elemento non trovato');

    // Cattura hero + stats grid insieme
    const wrap = document.createElement('div');
    wrap.style.cssText = 'background:#0a0a0f;padding:20px;display:flex;flex-direction:column;gap:16px';
    wrap.appendChild(el.cloneNode(true));
    const stats = document.getElementById('statsGrid');
    if (stats) wrap.appendChild(stats.cloneNode(true));
    document.body.appendChild(wrap);

    const blob = await captureElement(wrap);
    document.body.removeChild(wrap);

    const name = document.querySelector('.profile-name')?.textContent || 'giocatore';
    await shareImage(blob, `profilo-${name}.png`, `Profilo ${name}`);

    showToast('Foto profilo pronta!');
  } catch (e) {
    console.error(e);
    showToast('Errore nella generazione', 'error');
  }

  if (btn) { btn.disabled = false; btn.textContent = '📸 Salva profilo'; }
}
