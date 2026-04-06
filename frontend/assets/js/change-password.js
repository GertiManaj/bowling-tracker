// ══════════════════════════════════════════
// CAMBIO PASSWORD MODAL
// ══════════════════════════════════════════

function openChangePasswordModal() {
  console.log('openChangePasswordModal chiamata');
  const overlay = document.getElementById('changePasswordOverlay');
  if (!overlay) {
    console.error('changePasswordOverlay non trovato nel DOM');
    return;
  }
  
  overlay.classList.add('show');
  
  // Reset form
  document.getElementById('currentPassword').value = '';
  document.getElementById('newPassword').value = '';
  document.getElementById('confirmNewPassword').value = '';
  document.getElementById('changePasswordError').style.display = 'none';
  document.getElementById('changePasswordSuccess').style.display = 'none';
  
  setTimeout(() => document.getElementById('currentPassword').focus(), 100);
}

function closeChangePasswordModal() {
  const overlay = document.getElementById('changePasswordOverlay');
  if (overlay) {
    overlay.classList.remove('show');
  }
}

function handleChangePasswordOverlayClick(event) {
  if (event.target.id === 'changePasswordOverlay') {
    closeChangePasswordModal();
  }
}

async function submitChangePassword() {
  const currentPassword = document.getElementById('currentPassword').value;
  const newPassword = document.getElementById('newPassword').value;
  const confirmPassword = document.getElementById('confirmNewPassword').value;
  
  const errorEl = document.getElementById('changePasswordError');
  const successEl = document.getElementById('changePasswordSuccess');
  const btn = document.getElementById('btnChangePassword');
  
  // Reset messages
  errorEl.style.display = 'none';
  successEl.style.display = 'none';
  
  // Validazione
  if (!currentPassword || !newPassword || !confirmPassword) {
    errorEl.textContent = 'Compila tutti i campi';
    errorEl.style.display = 'block';
    return;
  }
  
  if (newPassword.length < 8) {
    errorEl.textContent = 'La nuova password deve essere di almeno 8 caratteri';
    errorEl.style.display = 'block';
    return;
  }
  
  if (newPassword !== confirmPassword) {
    errorEl.textContent = 'Le password non corrispondono';
    errorEl.style.display = 'block';
    return;
  }
  
  btn.disabled = true;
  btn.textContent = 'Aggiornamento...';
  
  try {
    const token = localStorage.getItem('sz_auth_token');
    
    if (!token) {
      errorEl.textContent = 'Non autenticato';
      errorEl.style.display = 'block';
      btn.disabled = false;
      btn.textContent = 'Cambia Password';
      return;
    }
    
    const res = await fetch('/api/auth.php?action=change-password', {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${token}`
      },
      body: JSON.stringify({
        current_password: currentPassword,
        new_password: newPassword
      })
    });
    
    const data = await res.json();
    
    if (data.success) {
      successEl.textContent = '✓ Password aggiornata con successo!';
      successEl.style.display = 'block';
      
      // Reset form
      document.getElementById('currentPassword').value = '';
      document.getElementById('newPassword').value = '';
      document.getElementById('confirmNewPassword').value = '';
      
      // Chiudi modal dopo 2 secondi
      setTimeout(() => {
        closeChangePasswordModal();
      }, 2000);
      
    } else {
      errorEl.textContent = data.error || 'Errore durante il cambio password';
      errorEl.style.display = 'block';
    }
    
  } catch (err) {
    errorEl.textContent = 'Errore di connessione';
    errorEl.style.display = 'block';
  }
  
  btn.disabled = false;
  btn.textContent = 'Cambia Password';
}

// Enter per submit nel modal cambio password
document.addEventListener('keydown', function(e) {
  const overlay = document.getElementById('changePasswordOverlay');
  if (overlay && overlay.classList.contains('show') && e.key === 'Enter') {
    submitChangePassword();
  }
});

console.log('✅ change-password.js caricato - openChangePasswordModal disponibile:', typeof openChangePasswordModal);
