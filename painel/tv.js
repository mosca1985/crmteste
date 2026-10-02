// Placar de vendas na TV: atualiza sozinho, comemora cada venda nova e a meta batida.
(() => {
  const $ = id => document.getElementById(id);
  const K = document.body.dataset.k || '';
  const URL_DADOS = '/clientes/crmteste/painel/tv.php?dados=1' + (K ? '&k=' + encodeURIComponent(K) : '');
  const ATUALIZA_MS = 30000;
  const fmt = n => Number(n).toLocaleString('pt-BR');
  const el = (tag, cls, txt) => { const e = document.createElement(tag); if (cls) e.className = cls; if (txt != null) e.textContent = txt; return e; };

  // ---------- relógio ----------
  const relogio = () => {
    const d = new Date();
    $('hora').textContent = d.toLocaleTimeString('pt-BR', {hour: '2-digit', minute: '2-digit'});
    $('data').textContent = d.toLocaleDateString('pt-BR', {weekday: 'long', day: 'numeric', month: 'long'});
  };
  relogio();
  setInterval(relogio, 1000);

  // número que "corre" até o valor novo
  const contar = (node, para, sufixo = '') => {
    const de = Number(node.dataset.v || 0);
    node.dataset.v = para;
    if (de === para || matchMedia('(prefers-reduced-motion: reduce)').matches) { node.textContent = fmt(para) + sufixo; return; }
    const t0 = performance.now(), dur = 1400;
    const passo = t => {
      const p = Math.min(1, (t - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      node.textContent = fmt(Math.round(de + (para - de) * e)) + sufixo;
      if (p < 1) requestAnimationFrame(passo);
    };
    requestAnimationFrame(passo);
  };

  // ---------- termômetro ----------
  const termometro = d => {
    const pct = d.meta ? d.vendasMes / d.meta * 100 : 0;
    $('fill').style.setProperty('--p', Math.min(100, pct).toFixed(1));
    $('pct').textContent = Math.floor(pct) + '%';
    contar($('vendasMes'), d.vendasMes);
    $('meta').textContent = fmt(d.meta);
    document.querySelector('.meta').classList.toggle('batida', d.vendasMes >= d.meta);
    const esc = $('escala');
    esc.replaceChildren(...[0, .25, .5, .75, 1].map(f => {
      const s = el('span', '', fmt(Math.round(d.meta * f)));
      s.style.bottom = (f * 100) + '%';
      return s;
    }));
    const sub = $('metaSub');
    sub.replaceChildren();
    if (d.faltam > 0) {
      sub.append('Faltam ', el('b', '', fmt(d.faltam)), ' · ', el('b', '', fmt(d.porDiaNecessario)), ' por dia até o fim do mês');
    } else {
      sub.append('🏆 Meta batida! ', el('b', '', '+' + fmt(d.vendasMes - d.meta)), ' além da meta');
    }
  };

  // ---------- ranking ----------
  const ranking = d => {
    const max = Math.max(1, ...d.ranking.map(r => r.vendas));
    const lista = $('ranking');
    lista.replaceChildren(...d.ranking.map((r, i) => {
      const li = el('li', i === 0 && r.vendas > 0 ? 'lider' : i === 1 && r.vendas > 0 ? 'p2' : i === 2 && r.vendas > 0 ? 'p3' : '');
      li.append(el('span', 'pos', i + 1 + 'º'));
      const img = el('img'); img.src = r.foto; img.alt = ''; li.append(img);
      const who = el('div', 'who');
      const nome = el('b', '', r.nome);
      if (i === 0 && r.vendas > 0) nome.append(el('span', 'coroa', '👑'));
      const bar = el('div', 'bar'); const fill = el('i'); bar.append(fill);
      requestAnimationFrame(() => fill.style.setProperty('--w', (r.vendas / max * 100).toFixed(1)));
      who.append(nome, bar, el('small', '', `${fmt(r.leads)} leads no mês · ${r.conv}% de conversão`));
      li.append(who);
      const score = el('div', 'score');
      score.append(el('b', '', fmt(r.vendas)));
      const sm = el('small', '', r.vendas === 1 ? 'venda' : 'vendas');
      score.append(sm);
      if (r.hoje > 0) score.append(el('span', 'hoje', `+${r.hoje} hoje`));
      li.append(score);
      return li;
    }));
  };

  // ---------- gráfico: vendas acumuladas × ritmo da meta ----------
  const NS = 'http://www.w3.org/2000/svg';
  const svgEl = (tag, attrs, txt) => { const e = document.createElementNS(NS, tag); for (const k in attrs) e.setAttribute(k, attrs[k]); if (txt != null) e.textContent = txt; return e; };
  let ultimoDado = null;
  const grafico = d => {
    const box = $('chart');
    const W = box.clientWidth, H = box.clientHeight;
    if (!W || !H) return;
    const rem = parseFloat(getComputedStyle(document.documentElement).fontSize) || 16;
    const m = {t: rem * 1.4, r: rem * 5, b: rem * 1.8, l: rem * 3};
    const iw = W - m.l - m.r, ih = H - m.t - m.b;
    const topo = Math.max(d.meta, ...d.acumulado, 1) * 1.08;
    const x = dia => m.l + dia / d.diasMes * iw;
    const y = v => m.t + ih - v / topo * ih;
    const svg = svgEl('svg', {viewBox: `0 0 ${W} ${H}`, role: 'img', 'aria-label': `Vendas acumuladas no mês: ${d.vendasMes} de ${d.meta}`});
    const defs = svgEl('defs', {});
    const g = svgEl('linearGradient', {id: 'gArea', x1: 0, y1: 0, x2: 0, y2: 1});
    const principal = getComputedStyle(document.documentElement).getPropertyValue('--violet').trim() || '#d417ff';
    g.append(svgEl('stop', {offset: '0%', 'stop-color': principal, 'stop-opacity': '.35'}), svgEl('stop', {offset: '100%', 'stop-color': principal, 'stop-opacity': '0'}));
    defs.append(g); svg.append(defs);

    const grade = svgEl('g', {class: 'grid'}), eixo = svgEl('g', {class: 'eixo'});
    [0, Math.round(d.meta / 2), d.meta].forEach(v => {
      grade.append(svgEl('line', {x1: m.l, x2: m.l + iw, y1: y(v), y2: y(v)}));
      eixo.append(svgEl('text', {x: m.l - 8, y: y(v) + 4, 'text-anchor': 'end'}, fmt(v)));
    });
    const marcas = [1, 5, 10, 15, 20, 25, d.diasMes].filter((v, i, a) => v <= d.diasMes && a.indexOf(v) === i && !(v > 25 && v !== d.diasMes) && !(v !== d.diasMes && d.diasMes - v < 3));
    marcas.forEach(v => eixo.append(svgEl('text', {x: x(v), y: H - rem * .3, 'text-anchor': 'middle'}, 'dia ' + v)));
    svg.append(grade, eixo);

    // ritmo necessário (linha tracejada de 0 até a meta no último dia)
    svg.append(svgEl('line', {class: 'ideal', x1: x(0), y1: y(0), x2: x(d.diasMes), y2: y(d.meta)}));
    svg.append(svgEl('text', {class: 'rot-ideal', x: x(d.diasMes) + 8, y: y(d.meta) + 4}, 'meta ' + fmt(d.meta)));

    // vendas acumuladas até hoje
    const pts = [[0, 0], ...d.acumulado.map((v, i) => [i + 1, v])];
    const linha = pts.map(([a, b], i) => (i ? 'L' : 'M') + x(a).toFixed(1) + ' ' + y(b).toFixed(1)).join(' ');
    svg.append(svgEl('path', {class: 'area', d: `${linha} L${x(pts[pts.length - 1][0]).toFixed(1)} ${y(0)} L${x(0)} ${y(0)} Z`}));
    const path = svgEl('path', {class: 'linha', d: linha});
    svg.append(path);
    const [ux, uy] = pts[pts.length - 1];
    svg.append(svgEl('circle', {class: 'ponto', cx: x(ux), cy: y(uy), r: 7}));
    svg.append(svgEl('text', {class: 'rot', x: x(ux) + 12, y: y(uy) - 10}, fmt(uy)));
    box.replaceChildren(svg);
    // desenha a linha (só quando os números mudaram)
    if (!ultimoDado || ultimoDado !== JSON.stringify(d.acumulado)) {
      const len = path.getTotalLength();
      path.style.strokeDasharray = len; path.style.strokeDashoffset = len;
      path.getBoundingClientRect();
      path.style.transition = 'stroke-dashoffset 2s ease-out'; path.style.strokeDashoffset = 0;
      ultimoDado = JSON.stringify(d.acumulado);
    }
  };

  // ---------- últimas vendas ----------
  const feed = d => {
    const ul = $('ultimas');
    if (!d.ultimas.length) { ul.replaceChildren(el('li', 'vazio', 'As vendas fechadas aparecem aqui.')); return; }
    ul.replaceChildren(...d.ultimas.slice(0, 4).map(v => {
      const li = el('li');
      const img = el('img'); img.src = v.foto; img.alt = '';
      const t = el('div'); t.append(el('b', '', v.vendedor), el('small', '', v.produto));
      li.append(img, t, el('time', '', v.quando));
      return li;
    }));
  };

  // ---------- comemoração ----------
  const party = $('party'), cv = $('confete');
  let fimFesta = 0;
  const confete = () => {
    const ctx = cv.getContext('2d');
    cv.width = innerWidth; cv.height = innerHeight;
    const css = getComputedStyle(document.documentElement);
    const cores = [css.getPropertyValue('--violet').trim() || '#d417ff', '#ff6bd6', '#ffc94d', '#ffffff', css.getPropertyValue('--purple').trim() || '#872ab8', '#3ddc97'];
    const ps = Array.from({length: 220}, () => ({
      x: Math.random() * cv.width, y: -20 - Math.random() * cv.height * .6,
      vx: (Math.random() - .5) * 4, vy: 2 + Math.random() * 4, r: 5 + Math.random() * 7,
      a: Math.random() * 6.28, va: (Math.random() - .5) * .3, c: cores[Math.random() * cores.length | 0],
    }));
    const t0 = performance.now();
    const quadro = t => {
      ctx.clearRect(0, 0, cv.width, cv.height);
      ps.forEach(p => {
        p.x += p.vx; p.y += p.vy; p.vy += .05; p.a += p.va;
        ctx.save(); ctx.translate(p.x, p.y); ctx.rotate(p.a); ctx.fillStyle = p.c;
        ctx.fillRect(-p.r / 2, -p.r / 4, p.r, p.r / 2); ctx.restore();
      });
      if (t - t0 < 7000 && !party.hidden) requestAnimationFrame(quadro);
    };
    requestAnimationFrame(quadro);
  };
  const comemorar = (tag, foto, nome, sub) => {
    $('partyTag').textContent = tag;
    $('partyFoto').src = foto; $('partyFoto').hidden = !foto;
    $('partyNome').textContent = nome;
    $('partyProd').textContent = sub;
    party.hidden = false;
    if (!matchMedia('(prefers-reduced-motion: reduce)').matches) confete();
    fimFesta = Date.now() + 9000;
    setTimeout(() => { if (Date.now() >= fimFesta) party.hidden = true; }, 9000);
  };

  // ---------- atualização ----------
  let anterior = null;
  const carregar = async () => {
    try {
      const r = await fetch(URL_DADOS, {cache: 'no-store'});
      if (r.status === 403) { $('status').textContent = 'Link da TV inválido — gere um novo em Usuários → TV do escritório.'; $('status').className = 'off'; return; }
      const d = await r.json();
      if (!d.ok) throw new Error('dados');
      $('mes').textContent = d.mes;
      termometro(d); ranking(d); grafico(d); feed(d);
      contar($('vendasHoje'), d.vendasHoje); contar($('leadsHoje'), d.leadsHoje); contar($('leadsMes'), d.leadsMes);
      contar($('conversao'), d.conversao, '%');
      $('status').textContent = 'Atualizado às ' + d.atualizado + ' · atualiza sozinho a cada 30 s';
      $('status').className = '';

      if (anterior) {
        const nova = d.ultimas[0], velha = anterior.ultimas[0];
        if (anterior.vendasMes < anterior.meta && d.vendasMes >= d.meta) {
          comemorar('Meta batida!', '', fmt(d.vendasMes) + ' vendas', 'Parabéns, time ' + (d.empresa || '') + '!');
        } else if (nova && (!velha || nova.id !== velha.id || nova.em !== velha.em) && d.vendasMes > anterior.vendasMes) {
          comemorar('Nova venda!', nova.foto, nova.vendedor, nova.produto);
        }
      }
      anterior = d;
    } catch (e) {
      $('status').textContent = 'Sem conexão — tentando de novo…';
      $('status').className = 'off';
    }
  };
  carregar();
  setInterval(carregar, ATUALIZA_MS);
  document.addEventListener('visibilitychange', () => { if (!document.hidden) carregar(); });
  let rz; addEventListener('resize', () => { clearTimeout(rz); rz = setTimeout(() => anterior && grafico(anterior), 200); });
  party.addEventListener('click', () => { party.hidden = true; });

  // recarrega a página a cada 6 h (pega melhorias publicadas)
  setTimeout(() => location.reload(), 6 * 3600 * 1000);

  // ---------- TV: tela cheia, tela sempre acesa, cursor escondido ----------
  $('cheia').addEventListener('click', () => (document.fullscreenElement ? document.exitFullscreen() : document.documentElement.requestFullscreen()).catch(() => {}));
  const acordada = async () => { try { if ('wakeLock' in navigator) await navigator.wakeLock.request('screen'); } catch (e) {} };
  acordada();
  document.addEventListener('visibilitychange', () => { if (!document.hidden) acordada(); });
  let idle;
  addEventListener('mousemove', () => { document.body.classList.remove('idle'); clearTimeout(idle); idle = setTimeout(() => document.body.classList.add('idle'), 3000); });
})();
