// ============================================
//  service-worker.js — Strike Zone PWA
// ============================================

const CACHE_NAME = 'strikezone-v1';
const CACHE_ASSETS = [
  '/',
  '/index.html',
  '/sessioni.html',
  '/statistiche.html',
  '/giocatori.html',
  '/profilo.html',
  '/welcome.html',
  '/css/style.css',
  '/css/sessioni.css',
  '/css/statistiche.css',
  '/css/giocatori.css',
  '/css/profilo.css',
  '/js/app.js',
  '/js/auth.js',
  '/js/sessioni.js',
  '/js/statistiche.js',
  '/js/giocatori.js',
  '/js/profilo.js',
  '/js/screenshot.js',
  '/icons/icon-192.png',
  '/icons/icon-512.png'
];

// Installazione: metti in cache gli asset statici
self.addEventListener('install', function(event) {
  event.waitUntil(
    caches.open(CACHE_NAME).then(function(cache) {
      return cache.addAll(CACHE_ASSETS);
    }).catch(function(err) {
      console.log('Cache install error:', err);
    })
  );
  self.skipWaiting();
});

// Attivazione: rimuovi cache vecchie
self.addEventListener('activate', function(event) {
  event.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.filter(function(key) { return key !== CACHE_NAME; })
            .map(function(key) { return caches.delete(key); })
      );
    })
  );
  self.clients.claim();
});

// Fetch: network first per le API, cache first per gli asset statici
self.addEventListener('fetch', function(event) {
  var url = new URL(event.request.url);

  // Le chiamate API vanno sempre in rete
  if (url.pathname.startsWith('/api/')) {
    event.respondWith(
      fetch(event.request).catch(function() {
        return new Response(JSON.stringify({ error: 'Offline' }), {
          headers: { 'Content-Type': 'application/json' }
        });
      })
    );
    return;
  }

  // Per tutto il resto: cache first, poi rete
  event.respondWith(
    caches.match(event.request).then(function(cached) {
      if (cached) return cached;
      return fetch(event.request).then(function(response) {
        // Aggiorna la cache con la risposta
        if (response && response.status === 200 && response.type === 'basic') {
          var toCache = response.clone();
          caches.open(CACHE_NAME).then(function(cache) {
            cache.put(event.request, toCache);
          });
        }
        return response;
      }).catch(function() {
        // Offline fallback per le pagine HTML
        if (event.request.destination === 'document') {
          return caches.match('/index.html');
        }
      });
    })
  );
});
