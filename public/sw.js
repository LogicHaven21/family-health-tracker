'use strict';
const CACHE='family-health-shell-v1';
const FILES=['./','./index.html','./style.css','./stats.js','./app.js','./icon.svg','./icon-192.png','./icon-512.png','./manifest.webmanifest'];
self.addEventListener('install',e=>e.waitUntil(caches.open(CACHE).then(c=>c.addAll(FILES))));
self.addEventListener('activate',e=>e.waitUntil(caches.keys().then(keys=>Promise.all(keys.filter(k=>k!==CACHE).map(k=>caches.delete(k)))).then(()=>self.clients.claim())));
self.addEventListener('fetch',e=>{const u=new URL(e.request.url);if(e.request.method!=='GET'||u.origin!==self.location.origin||u.pathname.endsWith('/api.php'))return;const allowed=FILES.map(f=>new URL(f,self.location.href).pathname);if(!allowed.includes(u.pathname))return;e.respondWith(fetch(e.request).catch(()=>caches.match(e.request).then(r=>r||Response.error())));});
