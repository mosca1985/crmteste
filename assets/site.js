  // Comportamentos da landing. Textos e dados vêm do HTML (gerado por index.php a partir de config/empresa.php).
  const ano = document.getElementById('y'); if (ano) ano.textContent = new Date().getFullYear();

  // nav background on scroll
  const nav = document.getElementById('nav');
  const onScroll = () => nav.classList.toggle('scrolled', scrollY > 20);
  addEventListener('scroll', onScroll, {passive:true}); onScroll();

  // reveal on scroll
  const io = new IntersectionObserver(entries => entries.forEach(e => {
    if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); }
  }), {threshold:.12, rootMargin:'0px 0px -40px 0px'});
  document.querySelectorAll('.reveal').forEach(el => io.observe(el));

  // esconde o botão flutuante enquanto os vendedores ou as setas das avaliações estão na tela
  const fab = document.querySelector('.fab');
  const covering = new Set();
  const fabIO = new IntersectionObserver(entries => {
    entries.forEach(e => e.isIntersecting ? covering.add(e.target) : covering.delete(e.target));
    fab.classList.toggle('hide', covering.size > 0);
  }, {threshold:.15});
  ['#vendedores .team', '.rv-controls'].forEach(s => { const el = document.querySelector(s); if (el) fabIO.observe(el); });

  // hero: sequência de fotos que "roda" com o scroll (crossfade + zoom + leve deslize)
  (() => {
    const hero = document.getElementById('hero');
    const frames = [...hero.querySelectorAll('.hero-frame')];
    if (frames.length < 2) return; // sem sequência de fotos: topo fixo
    const caps = [...hero.querySelectorAll('.hero-cap span')];
    const bar = hero.querySelector('.hero-progress i');
    const hint = hero.querySelector('.hero-hint');
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const n = frames.length;
    let ticking = false, last = -1;
    const update = () => {
      ticking = false;
      const total = hero.offsetHeight - innerHeight;
      const p = Math.min(1, Math.max(0, -hero.getBoundingClientRect().top / total));
      const pos = p * (n - 1);
      frames.forEach((f, i) => {
        const d = pos - i;                                  // -1..0 entrando, 0..1 saindo
        const t = Math.min(1, Math.max(0, (d + 1) / 2));    // 0..1 ao longo da vida da cena
        // cada cena fica nítida e a próxima entra por cima numa fusão curta (sem "dupla exposição")
        f.style.opacity = i === 0 ? 1 : Math.min(1, Math.max(0, (d + .45) / .35));
        f.style.transform = `scale(${(1.14 - .14 * t).toFixed(4)}) translate3d(0,${(2 - 4 * t).toFixed(2)}%,0)`;
      });
      const idx = Math.min(n - 1, Math.floor(pos + .28));   // troca a legenda no meio da fusão
      if (idx !== last) { caps.forEach((c, i) => c.classList.toggle('on', i === idx)); last = idx; }
      bar.style.width = (p * 100).toFixed(1) + '%';
      hint.style.opacity = p > .05 ? 0 : 1;
    };
    addEventListener('scroll', () => { if (!ticking) { ticking = true; requestAnimationFrame(update); } }, {passive:true});
    addEventListener('resize', update);
    update();
  })();

  // links internos: rolagem suave com easing + "chegada" da seção + ondinha no clique
  (() => {
    const calm = matchMedia('(prefers-reduced-motion: reduce)').matches;
    const root = document.documentElement;
    const ease = t => t < .5 ? 4 * t * t * t : 1 - Math.pow(-2 * t + 2, 3) / 2;
    const arrive = el => {
      el.classList.remove('arrive');
      el.querySelectorAll('.member,.prod,.benefit,.decade > *,.rv-card,.loc-info,.map').forEach((c, i) => c.style.setProperty('--i', i));
      void el.offsetWidth;
      el.classList.add('arrive');
      clearTimeout(el._arriveT);
      el._arriveT = setTimeout(() => el.classList.remove('arrive'), 1800);
    };
    document.addEventListener('click', e => {
      const a = e.target.closest('a[href^="#"]');
      if (!a) return;
      const id = a.getAttribute('href');
      const target = id.length > 1 && document.querySelector(id);
      if (!target) return;
      e.preventDefault();
      const offset = parseFloat(getComputedStyle(target).scrollMarginTop) || 0;
      const to = Math.max(0, target.getBoundingClientRect().top + scrollY - offset);
      const done = () => {
        history.replaceState(null, '', id);
        if (!target.hasAttribute('tabindex')) target.setAttribute('tabindex', '-1');
        target.focus({preventScroll: true});
        if (!calm) arrive(target);
      };
      if (calm) { scrollTo(0, to); return done(); }
      const from = scrollY, dist = to - from;
      const dur = Math.min(1200, 450 + Math.abs(dist) * .22);
      const t0 = performance.now();
      root.style.scrollBehavior = 'auto';
      const step = now => {
        const k = Math.min(1, (now - t0) / dur);
        scrollTo(0, from + dist * ease(k));
        if (k < 1) requestAnimationFrame(step);
        else { root.style.scrollBehavior = ''; done(); }
      };
      requestAnimationFrame(step);
    });
    // ondinha de luz no ponto do clique
    if (!calm) document.addEventListener('pointerdown', e => {
      const el = e.target.closest('.btn,.prod,.member,.fab,.rv-btn');
      if (!el) return;
      const r = el.getBoundingClientRect(), size = Math.max(r.width, r.height) * 2.2;
      const dot = document.createElement('span');
      dot.className = 'ripple';
      dot.style.cssText = `width:${size}px;height:${size}px;left:${e.clientX - r.left}px;top:${e.clientY - r.top}px`;
      el.appendChild(dot);
      dot.addEventListener('animationend', () => dot.remove());
    });
  })();

  // leads: nome, WhatsApp e produto → grava no MySQL (api/lead.php) → abre o WhatsApp do vendedor escolhido.
  // Enquanto o banco não responder ao "ping", o site segue indo direto para o WhatsApp (comportamento anterior).
  (() => {
    const modal = document.getElementById('leadModal');
    const form = document.getElementById('leadForm');
    const err = document.getElementById('lmErr');
    const params = new URLSearchParams(location.search);
    const store = { get(k) { try { return localStorage.getItem(k); } catch (e) { return null; } },
                    set(k, v) { try { localStorage.setItem(k, v); } catch (e) {} } };
    let pronto = false, alvo = null, produtoSugerido = null, aberto = 0;

    if (window.HTMLDialogElement && modal.showModal) {
      fetch('/clientes/crmteste/api/lead.php?ping', {cache: 'no-store'}).then(r => r.ok ? r.json() : null)
        .then(d => { pronto = !!(d && d.ok); }).catch(() => {});
    }

    const track = (vendedor, produto, lead) => {
      if (typeof fbq === 'function') {
        if (lead) fbq('track', 'Lead', {content_name: vendedor, content_category: produto});
        fbq('track', 'Contact', {content_name: vendedor, content_category: 'WhatsApp vendedor'});
      }
      if (typeof gtag === 'function') {
        if (lead) gtag('event', 'generate_lead', {vendedor, produto});
        gtag('event', 'contact', {method: 'WhatsApp', vendedor});
      }
    };

    // produto escolhido num cartão de produto já vem marcado no formulário
    document.querySelectorAll('.prod[data-produto]').forEach(p => p.addEventListener('click', () => { produtoSugerido = p.dataset.produto; }));

    document.querySelectorAll('#vendedores .member').forEach(a => a.addEventListener('click', e => {
      const nome = a.querySelector('h3')?.textContent.trim() || 'vendedor';
      if (!pronto) { track(nome, '', false); return; }        // sem banco: vai direto para o WhatsApp
      e.preventDefault();
      alvo = {slug: a.dataset.vendedor, nome, href: a.href, foto: a.querySelector('img')?.src || ''};
      document.getElementById('lmTitle').textContent = nome;
      const foto = document.getElementById('lmFoto'); foto.src = alvo.foto; foto.alt = nome;
      form.nome.value = form.nome.value || store.get('lead_nome') || '';
      form.telefone.value = form.telefone.value || store.get('lead_tel') || '';
      if (produtoSugerido) { const r = form.querySelector(`input[name=produto][value="${produtoSugerido}"]`); if (r) r.checked = true; }
      err.hidden = true;
      aberto = Date.now();
      modal.showModal();
      setTimeout(() => (form.nome.value ? form.telefone : form.nome).focus(), 60);
    }));

    modal.querySelector('.lm-close').addEventListener('click', () => modal.close());
    modal.addEventListener('click', e => { if (e.target === modal) modal.close(); });

    // máscara de telefone
    form.telefone.addEventListener('input', () => {
      let d = form.telefone.value.replace(/\D/g, '').slice(0, 11);
      form.telefone.value = d.length > 6 ? `(${d.slice(0, 2)}) ${d.slice(2, d.length - 4)}-${d.slice(-4)}`
                         : d.length > 2 ? `(${d.slice(0, 2)}) ${d.slice(2)}` : d;
    });

    form.addEventListener('submit', async e => {
      e.preventDefault();
      if (!alvo) return;
      const nome = form.nome.value.trim().replace(/\s+/g, ' ');
      const tel = form.telefone.value.replace(/\D/g, '');
      const produto = form.querySelector('input[name=produto]:checked')?.value || '';
      form.nome.classList.toggle('bad', nome.length < 2);
      form.telefone.classList.toggle('bad', tel.length < 10);
      form.querySelector('.lm-prod').classList.toggle('bad', !produto);
      if (nome.length < 2 || tel.length < 10 || !produto) {
        err.textContent = 'Preencha seu nome, WhatsApp com DDD e o produto.';
        err.hidden = false;
        return;
      }
      err.hidden = true;
      const btn = form.querySelector('.lm-submit');
      btn.disabled = true;
      store.set('lead_nome', nome); store.set('lead_tel', form.telefone.value);

      // grava o lead (até 4s); com ou sem sucesso, o cliente segue para o WhatsApp
      const ctrl = new AbortController();
      const timer = setTimeout(() => ctrl.abort(), 4000);
      try {
        await fetch('/clientes/crmteste/api/lead.php', {
          method: 'POST', headers: {'Content-Type': 'application/json'}, signal: ctrl.signal, keepalive: true,
          body: JSON.stringify({
            nome, telefone: tel, produto, vendedor: alvo.slug, site: form.site.value, t: Date.now() - aberto,
            origem: params.get('utm_source') || '', midia: params.get('utm_medium') || '', campanha: params.get('utm_campaign') || '',
            referrer: document.referrer || ''
          })
        });
      } catch (x) {}
      clearTimeout(timer);
      track(alvo.nome, produto, true);

      const url = new URL(alvo.href);
      const apelido = (url.searchParams.get('text') || '').match(/^Olá ([^!]+)!/)?.[1] || alvo.nome;
      url.searchParams.set('text', `Olá ${apelido}! Sou ${nome.split(' ')[0]}, vim pelo site e tenho interesse em ${produto === 'Outro' ? (document.body.dataset.generico || 'seus produtos') : produto}.`);
      location.href = url.toString();
      setTimeout(() => { btn.disabled = false; modal.close(); }, 1500);
    });
  })();

  // selo do Google: total de avaliações conta do zero ao abrir a página
  (() => {
    if (matchMedia('(prefers-reduced-motion: reduce)').matches) return;
    const els = document.querySelectorAll('.hero-rating [data-count]');
    const fmt = (v, dec) => v.toLocaleString('pt-BR', {minimumFractionDigits: dec, maximumFractionDigits: dec});
    els.forEach(el => { el.textContent = fmt(0, +el.dataset.dec || 0); });
    setTimeout(() => {
      const t0 = performance.now(), dur = 1600;
      const tick = now => {
        const k = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - k, 3);
        els.forEach(el => { const dec = +el.dataset.dec || 0, to = +el.dataset.count; el.textContent = fmt(dec ? Math.round(to * e * 10) / 10 : Math.round(to * e), dec); });
        if (k < 1) requestAnimationFrame(tick);
      };
      requestAnimationFrame(tick);
    }, 700);
  })();

  // carrossel de avaliações
  (() => {
    const track = document.getElementById('rvTrack');
    if (!track) return; // sem avaliações cadastradas
    const cards = [...track.children];
    const dots = document.getElementById('rvDots');
    dots.innerHTML = cards.map(() => '<i></i>').join('');
    const step = () => cards[0].getBoundingClientRect().width + 16;
    const max = () => track.scrollWidth - track.clientWidth - 2;
    const paint = () => {
      const i = track.scrollLeft >= max() ? cards.length - 1 : Math.round(track.scrollLeft / step());
      [...dots.children].forEach((d, j) => d.classList.toggle('on', j === i));
    };
    const go = dir => {
      if (dir > 0 && track.scrollLeft >= max()) track.scrollTo({left: 0});
      else if (dir < 0 && track.scrollLeft <= 2) track.scrollTo({left: max()});
      else track.scrollBy({left: dir * step()});
    };
    document.getElementById('rvNext').onclick = () => go(1);
    document.getElementById('rvPrev').onclick = () => go(-1);
    track.addEventListener('scroll', paint, {passive:true}); paint();
    // avança sozinho a cada 5s; pausa com mouse/toque/foco e respeita "reduzir movimento"
    let paused = false;
    ['mouseenter','touchstart','focusin'].forEach(e => track.addEventListener(e, () => paused = true, {passive:true}));
    ['mouseleave','focusout'].forEach(e => track.addEventListener(e, () => paused = false));
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches)
      setInterval(() => { if (!paused && !document.hidden) go(1); }, 5000);
  })();

