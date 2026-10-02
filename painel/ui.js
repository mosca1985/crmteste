// Comportamentos comuns do painel (sem scripts embutidos no HTML, por causa da política de segurança).

// <select data-autosubmit> envia o formulário ao trocar a opção.
document.addEventListener('change', e => {
  if (e.target.matches('[data-autosubmit]') && e.target.form) e.target.form.submit();
});

// ---------- app no celular (PWA) ----------
if ('serviceWorker' in navigator) {
  navigator.serviceWorker.register('/clientes/crmteste/painel/sw.js', {scope: '/clientes/crmteste/painel/'}).catch(() => {});
}

(() => {
  const btn = document.getElementById('btnInstalar');
  if (!btn) return;
  const instalado = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  if (instalado) return;

  // Android / Chrome / Edge: o navegador oferece a instalação
  let pedido = null;
  addEventListener('beforeinstallprompt', e => {
    e.preventDefault();
    pedido = e;
    btn.hidden = false;
  });
  btn.addEventListener('click', async () => {
    if (pedido) {
      pedido.prompt();
      await pedido.userChoice;
      pedido = null;
      btn.hidden = true;
    } else {
      alert('No iPhone: toque em Compartilhar (quadrado com a seta para cima) e depois em "Adicionar à Tela de Início".');
    }
  });
  addEventListener('appinstalled', () => { btn.hidden = true; });

  // iPhone/iPad (Safari não avisa): mostra o botão com a instrução
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  if (ios) btn.hidden = false;
})();

// ---------- avisos de novo lead (notificações push) ----------
(() => {
  const btn = document.getElementById('btnNotif');
  if (!btn) return;
  const CSRF = (document.querySelector('meta[name=csrf]') || {}).content || '';
  const ios = /iphone|ipad|ipod/i.test(navigator.userAgent) || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const instalado = matchMedia('(display-mode: standalone)').matches || navigator.standalone;
  const suporta = 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;

  if (!suporta) {
    // iPhone no Safari: os avisos só existem com o app na tela de início (iOS 16.4 ou mais novo)
    if (ios && !instalado) {
      btn.hidden = false;
      btn.addEventListener('click', () => alert('Para receber avisos de novo lead no iPhone, primeiro instale o app:\n\nCompartilhar (quadrado com a seta) → "Adicionar à Tela de Início".\n\nDepois abra o painel pelo ícone do app e toque no sininho.'));
    }
    return;
  }

  const b64 = s => Uint8Array.from(atob((s + '==='.slice((s.length + 3) % 4)).replace(/-/g, '+').replace(/_/g, '/')), c => c.charCodeAt(0));
  const post = (acao, dados) => fetch('/clientes/crmteste/painel/push.php', {method: 'POST', headers: {'X-CSRF': CSRF}, body: new URLSearchParams({acao, ...dados})})
    .then(r => r.json().catch(() => ({})).then(j => { if (!r.ok || !j.ok) throw new Error(j.error || 'falha'); return j; }));
  const enviar = (sub, teste) => {
    const j = sub.toJSON();
    return post('inscrever', {endpoint: j.endpoint, p256dh: j.keys.p256dh, auth: j.keys.auth, aparelho: ios ? 'iPhone' : (navigator.userAgentData?.platform || navigator.platform || ''), teste: teste ? '1' : ''});
  };
  const marcar = on => {
    btn.classList.toggle('on', on);
    btn.title = on ? 'Avisos ligados neste aparelho (toque para desligar)' : 'Ligar avisos de novo lead';
    btn.setAttribute('aria-pressed', on ? 'true' : 'false');
  };

  let reg = null;
  navigator.serviceWorker.ready.then(async r => {
    reg = r;
    const sub = await reg.pushManager.getSubscription();
    const on = !!sub && Notification.permission === 'granted';
    marcar(on);
    btn.hidden = false;
    if (on) enviar(sub, false).catch(() => {}); // mantém o aparelho ligado ao usuário logado
  });

  btn.addEventListener('click', async () => {
    if (!reg) return;
    const atual = await reg.pushManager.getSubscription();
    if (atual && Notification.permission === 'granted') {
      if (!confirm('Desligar os avisos de novo lead neste aparelho?')) return;
      await post('cancelar', {endpoint: atual.endpoint}).catch(() => {});
      await atual.unsubscribe().catch(() => {});
      marcar(false);
      return;
    }
    const perm = await Notification.requestPermission();
    if (perm !== 'granted') {
      alert(ios
        ? 'Os avisos estão bloqueados. Libere em Ajustes → Notificações → ' + ((document.querySelector('meta[name=apple-mobile-web-app-title]') || {}).content || 'o app') + '.'
        : 'Os avisos estão bloqueados. Libere as notificações deste site nas configurações do navegador.');
      return;
    }
    btn.disabled = true;
    try {
      const {chave} = await fetch('/clientes/crmteste/painel/push.php?acao=chave', {cache: 'no-store'}).then(r => r.json());
      if (!chave) throw new Error('chave');
      const sub = atual || await reg.pushManager.subscribe({userVisibleOnly: true, applicationServerKey: b64(chave)});
      await enviar(sub, true);
      marcar(true);
    } catch (e) {
      alert('Não foi possível ligar os avisos agora. Tente de novo em instantes.');
    } finally {
      btn.disabled = false;
    }
  });
})();
// botão "Copiar" (data-copiar = id do campo com o texto)
document.addEventListener('click', async e => {
  const b = e.target.closest('[data-copiar]');
  if (!b) return;
  const campo = document.getElementById(b.dataset.copiar);
  if (!campo) return;
  try { await navigator.clipboard.writeText(campo.value); } catch (x) { campo.select(); document.execCommand('copy'); }
  const t = b.textContent;
  b.textContent = 'Copiado ✓';
  setTimeout(() => { b.textContent = t; }, 1800);
});