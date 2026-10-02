// Gráficos do Dashboard em SVG puro (sem bibliotecas): tendência nos cartões, linha leads×vendas e rosca de origem.
(() => {
  const NS = 'http://www.w3.org/2000/svg';
  const el = (tag, attrs = {}, parent) => {
    const n = document.createElementNS(NS, tag);
    for (const k in attrs) n.setAttribute(k, attrs[k]);
    if (parent) parent.appendChild(n);
    return n;
  };
  const tip = document.getElementById('vizTip');
  const showTip = (html, x, y) => {
    tip.innerHTML = html;
    tip.hidden = false;
    const w = tip.offsetWidth, h = tip.offsetHeight;
    tip.style.left = Math.min(window.innerWidth - w - 8, Math.max(8, x + 14)) + 'px';
    tip.style.top = Math.max(8, y - h - 12) + 'px';
  };
  const hideTip = () => { tip.hidden = true; };
  const fmtDia = d => { const [a, m, dd] = d.split('-'); return `${dd}/${m}`; };
  const DIAS = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];

  // ---------- mini tendência nos cartões ----------
  document.querySelectorAll('svg.spark').forEach(svg => {
    const v = JSON.parse(svg.dataset.spark), cor = svg.dataset.cor;
    const W = 200, H = 46, max = Math.max(1, ...v);
    svg.setAttribute('viewBox', `0 0 ${W} ${H}`);
    svg.setAttribute('preserveAspectRatio', 'none');
    const pts = v.map((y, i) => [i / (v.length - 1) * W, H - 4 - y / max * (H - 12)]);
    const id = 'g' + Math.random().toString(36).slice(2);
    const g = el('linearGradient', {id, x1: 0, y1: 0, x2: 0, y2: 1}, el('defs', {}, svg));
    el('stop', {offset: '0', 'stop-color': cor, 'stop-opacity': '.35'}, g);
    el('stop', {offset: '1', 'stop-color': cor, 'stop-opacity': '0'}, g);
    const line = pts.map((p, i) => (i ? 'L' : 'M') + p[0].toFixed(1) + ' ' + p[1].toFixed(1)).join(' ');
    el('path', {d: `${line} L${W} ${H} L0 ${H} Z`, fill: `url(#${id})`}, svg);
    el('path', {d: line, fill: 'none', stroke: cor, 'stroke-width': 2, 'vector-effect': 'non-scaling-stroke', 'stroke-linejoin': 'round'}, svg);
  });

  // ---------- linha: leads e vendas por dia ----------
  const box = document.querySelector('.viz-line');
  const desenhaLinha = () => {
    if (!box) return;
    const dados = JSON.parse(box.dataset.serie); // [[dia, leads, vendas], ...]
    box.innerHTML = '';
    const W = box.clientWidth, H = box.clientHeight;
    const m = {t: 12, r: 12, b: 26, l: 30};
    const iw = W - m.l - m.r, ih = H - m.t - m.b;
    const maxV = Math.max(1, ...dados.map(d => d[1]));
    const passo = maxV <= 4 ? 1 : Math.ceil(maxV / 4);
    const topo = Math.ceil(maxV / passo) * passo;
    const x = i => m.l + (dados.length === 1 ? iw / 2 : i / (dados.length - 1) * iw);
    const y = v => m.t + ih - v / topo * ih;
    const svg = el('svg', {viewBox: `0 0 ${W} ${H}`}, box);

    // grade e eixos (linhas finas, sólidas, discretas)
    const grade = el('g', {class: 'grid'}, svg), eixo = el('g', {class: 'axis'}, svg);
    for (let v = 0; v <= topo; v += passo) {
      el('line', {x1: m.l, x2: W - m.r, y1: y(v), y2: y(v)}, grade);
      el('text', {x: m.l - 8, y: y(v) + 4, 'text-anchor': 'end'}, eixo).textContent = v;
    }
    const cada = Math.max(1, Math.ceil(dados.length / Math.max(2, Math.floor(iw / 56))));
    dados.forEach((d, i) => {
      if (i % cada && i !== dados.length - 1) return;
      el('text', {x: x(i), y: H - 6, 'text-anchor': i === 0 ? 'start' : i === dados.length - 1 ? 'end' : 'middle'}, eixo).textContent = fmtDia(d[0]);
    });

    // área + linha de leads, linha de vendas
    const caminho = k => dados.map((d, i) => (i ? 'L' : 'M') + x(i).toFixed(1) + ' ' + y(d[k]).toFixed(1)).join(' ');
    const grad = el('linearGradient', {id: 'areaLeads', x1: 0, y1: 0, x2: 0, y2: 1}, el('defs', {}, svg));
    el('stop', {offset: '0', 'stop-color': '#d95926', 'stop-opacity': '.32'}, grad);
    el('stop', {offset: '1', 'stop-color': '#d95926', 'stop-opacity': '0'}, grad);
    el('path', {d: `${caminho(1)} L${x(dados.length - 1)} ${y(0)} L${x(0)} ${y(0)} Z`, fill: 'url(#areaLeads)'}, svg);
    el('path', {d: caminho(1), fill: 'none', stroke: '#d95926', 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'}, svg);
    el('path', {d: caminho(2), fill: 'none', stroke: '#199e70', 'stroke-width': 2, 'stroke-linejoin': 'round', 'stroke-linecap': 'round'}, svg);

    // destaque do maior dia (rótulo seletivo)
    const iMax = dados.reduce((b, d, i) => d[1] > dados[b][1] ? i : b, 0);
    if (dados[iMax][1] > 0) {
      el('circle', {cx: x(iMax), cy: y(dados[iMax][1]), r: 4, fill: '#d95926', stroke: '#17141c', 'stroke-width': 2}, svg);
      el('text', {x: x(iMax), y: y(dados[iMax][1]) - 10, 'text-anchor': 'middle', fill: '#f4f1f7', 'font-size': 11, 'font-weight': 700, 'font-family': 'Manrope'}, svg).textContent = `pico: ${dados[iMax][1]}`;
    }

    // camada de interação: linha-guia + pontos + tooltip
    const guia = el('line', {y1: m.t, y2: m.t + ih, stroke: '#4a4254', 'stroke-width': 1, opacity: 0}, svg);
    const p1 = el('circle', {r: 4, fill: '#d95926', stroke: '#17141c', 'stroke-width': 2, opacity: 0}, svg);
    const p2 = el('circle', {r: 4, fill: '#199e70', stroke: '#17141c', 'stroke-width': 2, opacity: 0}, svg);
    const hit = el('rect', {x: m.l, y: 0, width: iw, height: H, fill: 'transparent'}, svg);
    const mover = ev => {
      const r = svg.getBoundingClientRect();
      const px = (ev.touches ? ev.touches[0].clientX : ev.clientX) - r.left;
      const i = Math.max(0, Math.min(dados.length - 1, Math.round((px - m.l) / (iw / Math.max(1, dados.length - 1)))));
      const d = dados[i], xx = x(i);
      guia.setAttribute('x1', xx); guia.setAttribute('x2', xx); guia.setAttribute('opacity', 1);
      p1.setAttribute('cx', xx); p1.setAttribute('cy', y(d[1])); p1.setAttribute('opacity', 1);
      p2.setAttribute('cx', xx); p2.setAttribute('cy', y(d[2])); p2.setAttribute('opacity', 1);
      const dt = new Date(d[0] + 'T12:00:00');
      showTip(`<b>${DIAS[dt.getDay()]}, ${fmtDia(d[0])}</b>
        <div><span><i style="background:#d95926"></i>Leads</span><strong>${d[1]}</strong></div>
        <div><span><i style="background:#199e70"></i>Vendas</span><strong>${d[2]}</strong></div>`, r.left + xx, r.top + y(d[1]));
    };
    const sair = () => { [guia, p1, p2].forEach(n => n.setAttribute('opacity', 0)); hideTip(); };
    hit.addEventListener('mousemove', mover);
    hit.addEventListener('touchstart', mover, {passive: true});
    hit.addEventListener('touchmove', mover, {passive: true});
    hit.addEventListener('mouseleave', sair);
    hit.addEventListener('touchend', sair);
  };
  desenhaLinha();
  let t;
  addEventListener('resize', () => { clearTimeout(t); t = setTimeout(desenhaLinha, 150); });

  // ---------- rosca: origem dos leads ----------
  document.querySelectorAll('.viz-donut').forEach(box => {
    const seg = JSON.parse(box.dataset.seg).filter(s => s[1] > 0); // [nome, n, cor]
    const total = +box.dataset.total;
    const R = 60, C = 2 * Math.PI * R, GAP = total > 0 && seg.length > 1 ? 2 : 0;
    const svg = el('svg', {viewBox: '0 0 150 150'}, box);
    el('circle', {cx: 75, cy: 75, r: R, fill: 'none', stroke: 'rgba(255,255,255,.06)', 'stroke-width': 18}, svg);
    const centro = document.createElement('div');
    centro.className = 'center';
    const padrao = `<b>${total}</b><span>${total === 1 ? 'lead' : 'leads'} no período</span>`;
    centro.innerHTML = padrao;
    box.appendChild(centro);
    let off = 0;
    seg.forEach(([nome, n, cor]) => {
      const len = n / total * C;
      const arc = el('circle', {cx: 75, cy: 75, r: R, fill: 'none', stroke: cor, 'stroke-width': 18,
        'stroke-dasharray': `${Math.max(0, len - GAP)} ${C}`, 'stroke-dashoffset': -off, style: 'transition:stroke-width .2s;cursor:default'}, svg);
      const pct = Math.round(n / total * 100);
      const on = () => { arc.setAttribute('stroke-width', 22); centro.innerHTML = `<b>${pct}%</b><span>${nome} · ${n} ${n === 1 ? 'lead' : 'leads'}</span>`; };
      const off2 = () => { arc.setAttribute('stroke-width', 18); centro.innerHTML = padrao; };
      arc.addEventListener('mouseenter', on); arc.addEventListener('mouseleave', off2);
      arc.addEventListener('touchstart', on, {passive: true});
      off += len;
    });
  });
})();
