// service-worker.js — Strike Zone PWA
// Versione minimal: solo per abilitare l'installazione, niente cache aggressiva

const CACHE_NAME = 'strikezone-v2';

self.addEventListener('install', function(e) {
  self.skipWaiting();
});

self.addEventListener('activate', function(e) {
  // Pulisci cache vecchie
  e.waitUntil(
    caches.keys().then(function(keys) {
      return Promise.all(
        keys.map(function(key) { return caches.delete(key); })
      );
    })
  );
  self.clients.claim();
});

// Fetch: lascia passare tutto senza intercettare
// Questo evita il problema dei redirect di router.php
self.addEventListener('fetch', function(e) {
  // Non intercettare nulla — lascia tutto al browser normale
  return;
});