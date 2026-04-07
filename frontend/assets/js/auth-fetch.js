/**
 * authFetch - Helper per fetch con JWT automatico
 * 
 * Usa questo al posto di fetch() per chiamate protette.
 * Aggiunge automaticamente il token JWT dall'localStorage.
 * 
 * Esempio:
 *   authFetch('/api/players.php', {
 *     method: 'POST',
 *     body: JSON.stringify({name: 'Mana', emoji: '🎳'})
 *   })
 */

const AUTH_TOKEN_KEY = 'sz_auth_token';

async function authFetch(url, options = {}) {
  // Prendi il token da localStorage
  const token = localStorage.getItem(AUTH_TOKEN_KEY);
  
  // Prepara gli headers
  const headers = {
    'Content-Type': 'application/json',
    ...(options.headers || {})
  };
  
  // Aggiungi Authorization header se c'è il token
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  
  // Esegui la fetch con gli headers modificati
  const response = await fetch(url, {
    ...options,
    headers
  });
  
  // Se ricevi 401 (non autorizzato), pulisci il token e ricarica
  if (response.status === 401) {
    console.warn('Token non valido o scaduto. Richiesto nuovo login.');
    localStorage.removeItem(AUTH_TOKEN_KEY);
    // Opzionale: reindirizza a login
    // window.location.href = '/frontend/pages/welcome.html';
  }
  
  return response;
}
