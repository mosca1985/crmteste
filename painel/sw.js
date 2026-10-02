// Service worker do painel (app no celular).
// Privacidade: só guarda arquivos visuais (CSS, JS, ícones, fotos). Páginas e dados de leads
// NUNCA são armazenados no aparelho — sempre vêm do servidor.
const VERSAO = 'painel-vendas-v1';
const OFFLINE = '/clientes/crmteste/painel/offline.html';

self.addEventListener('install', e => {
  e.waitUntil(caches.open(VERSAO).then(c => c.addAll([OFFLINE, '/clientes/crmteste/assets/app-192.png', '/clientes/crmteste/assets/vendedor-padrao.svg'])));
  self.skipWaiting();
});

self.addEventListener('activate', e => {
  e.waitUntil(caches.keys().then(ks => Promise.all(ks.filter(k => k !== VERSAO).map(k => caches.delete(k)))));
  self.clients.claim();
});

const ehEstatico = url =>
  url.origin === location.origin &&
  (/^\/painel\/.+\.(css|js)$/.test(url.pathname) || /^\/assets\//.test(url.pathname) || url.pathname === '/clientes/crmteste/favicon.ico') &&
  !url.pathname.endsWith('/sw.js');

self.addEventListener('fetch', e => {
  const req = e.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);

  // telas do painel: sempre do servidor; sem internet, mostra o aviso de "sem conexão"
  if (req.mode === 'navigate') {
    e.respondWith(fetch(req).catch(() => caches.match(OFFLINE)));
    return;
  }

  // arquivos visuais: responde do aparelho (rápido) e atualiza em segundo plano
  if (ehEstatico(url)) {
    e.respondWith(caches.open(VERSAO).then(async cache => {
      const salvo = await cache.match(req);
      const rede = fetch(req).then(r => { if (r.ok) cache.put(req, r.clone()); return r; }).catch(() => salvo);
      return salvo || rede;
    }));
  }
  // o resto (API, exportação) segue direto para a rede, sem cache
});

// ---------- notificações de novo lead ----------
self.addEventListener('push', e => {
  let d = {};
  try { d = e.data ? e.data.json() : {}; } catch (x) {}
  e.waitUntil(self.registration.showNotification(d.title || 'Painel de vendas', {
    body: d.body || 'Chegou um novo lead.',
    icon: '/clientes/crmteste/assets/app-192.png',
    badge: '/clientes/crmteste/assets/app-192.png',
    tag: d.tag || undefined,
    data: {url: d.url || '/clientes/crmteste/painel/'},
  }));
});

self.addEventListener('notificationclick', e => {
  e.notification.close();
  let url = new URL((e.notification.data && e.notification.data.url) || '/clientes/crmteste/painel/', location.origin);
  if (url.origin !== location.origin || !url.pathname.startsWith('/clientes/crmteste/painel/')) url = new URL('/clientes/crmteste/painel/', location.origin);
  e.waitUntil(self.clients.matchAll({type: 'window', includeUncontrolled: true}).then(abertas => {
    const janela = abertas.find(c => c.url.startsWith(location.origin + '/clientes/crmteste/painel/'));
    if (janela) return janela.focus().then(c => (c || janela).navigate(url.href)).catch(() => self.clients.openWindow(url.href));
    return self.clients.openWindow(url.href);
  }));
});