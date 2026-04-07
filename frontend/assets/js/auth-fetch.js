// ============================================
//  auth-fetch.js
//  Helper per fetch con autenticazione automatica
//  Usa: authFetch(url, options) invece di fetch()
// ============================================

const TOKEN_KEY = 'sz_auth_token';

/**
 * Fetch con autenticazione automatica JWT
 * Aggiunge automaticamente l'header Authorization se il token esiste
 * 
 * @param {string} url - URL della richiesta
 * @param {object} options - Opzioni fetch (method, body, headers, etc.)
 * @returns {Promise<Response>}
 */
async function authFetch(url, options = {}) {
  const token = localStorage.getItem(TOKEN_KEY);
  
  // Aggiungi headers di default
  const headers = {
    'Content-Type': 'application/json',
    ...options.headers
  };
  
  // Aggiungi token se esiste e se è una richiesta che lo richiede
  if (token && ['POST', 'PUT', 'DELETE'].includes(options.method?.toUpperCase())) {
    headers['Authorization'] = `Bearer ${token}`;
  }
  
  // Esegui fetch con headers aggiornati
  return fetch(url, {
    ...options,
    headers
  });
}

/**
 * Verifica se l'utente è autenticato
 * @returns {boolean}
 */
function isAuthenticated() {
  const token = localStorage.getItem(TOKEN_KEY);
  if (!token) return false;
  
  // Verifica se il token è scaduto
  try {
    const payload = JSON.parse(atob(token.split('.')[1]));
    return payload.exp > Math.floor(Date.now() / 1000);
  } catch (e) {
    return false;
  }
}

/**
 * Ottieni il token corrente
 * @returns {string|null}
 */
function getAuthToken() {
  return localStorage.getItem(TOKEN_KEY);
}
