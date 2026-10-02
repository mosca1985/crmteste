// Kanban de leads: arrastar entre colunas, detalhes, observações e aviso de novos leads.
(() => {
  const board = document.getElementById('board');
  if (!board) return;
  const CSRF = document.querySelector('meta[name=csrf]').content;
  const gestor = board.dataset.gestor === '1';
  const dlg = document.getElementById('det');
  const toastEl = document.getElementById('toast');
  let atual = null;       // cartão aberto no detalhe
  let arrastando = null;  // cartão sendo arrastado

  const toast = (msg, erro) => {
    toastEl.textContent = msg;
    toastEl.classList.toggle('err', !!erro);
    toastEl.classList.add('show');
    clearTimeout(toast.t);
    toast.t = setTimeout(() => toastEl.classList.remove('show'), 2600);
  };

  const api = async (acao, dados) => {
    const body = new URLSearchParams({acao, ...dados});
    const r = await fetch('/clientes/crmteste/painel/api.php', {method: 'POST', headers: {'X-CSRF': CSRF}, body});
    const j = await r.json().catch(() => ({}));
    if (!r.ok || !j.ok) throw new Error(j.error || 'falha');
    return j.lead;
  };

  // celular: abas mostram uma coluna por vez (lembra a última aba aberta)
  const abas = document.querySelectorAll('.col-tabs [role=tab]');
  const mostrarAba = s => {
    board.dataset.show = s;
    abas.forEach(b => b.setAttribute('aria-selected', b.dataset.status === s ? 'true' : 'false'));
    try { sessionStorage.setItem('ts_aba', s); } catch (e) {}
  };
  abas.forEach(b => b.addEventListener('click', () => mostrarAba(b.dataset.status)));
  try { const s = sessionStorage.getItem('ts_aba'); if (s) mostrarAba(s); } catch (e) {}

  const dados = card => JSON.parse(card.dataset.lead);
  const coluna = s => board.querySelector(`.col[data-status="${s}"]`);
  // ajusta contadores (+1 no destino, -1 na origem) e o aviso de "novos"
  const contar = (status, delta) => {
    const col = coluna(status);
    const cnt = col.querySelector('.count');
    const n = Math.max(0, +cnt.textContent + delta);
    cnt.textContent = n;
    const aba = document.querySelector(`.tab-count[data-count-for="${status}"]`);
    if (aba) aba.textContent = n;
    let vazio = col.querySelector('.col-body > div.empty');
    const temCartao = !!col.querySelector('.lead');
    if (!temCartao && !vazio) col.querySelector('.col-body').insertAdjacentHTML('afterbegin', '<div class="empty">Arraste leads para cá</div>');
    if (vazio) vazio.hidden = temCartao;
    if (status === 'novo') {
      document.getElementById('novosTotal').textContent = n;
      document.getElementById('novidade').hidden = n === 0;
    }
  };
  const recontar = (de, para) => { contar(de, -1); contar(para, +1); };

  // move o cartão na tela e grava no banco (desfaz se der erro)
  const mover = async (card, status) => {
    const d = dados(card);
    if (d.status === status) return;
    const origem = card.parentElement, proximo = card.nextSibling, antes = d.status;
    const destino = coluna(status).querySelector('.col-body');
    destino.prepend(card);
    card.classList.remove('is-new');
    d.status = status; card.dataset.lead = JSON.stringify(d);
    recontar(antes, status);
    try {
      const l = await api('mover', {id: d.id, status});
      const d2 = dados(card);
      Object.assign(d2, {fu: l.fu, fuTexto: l.fuTexto, proximo: l.proximoInput});
      redesenhar(card, d2);
      toast(`${d.nome} → ${coluna(status).querySelector('h2').textContent}`);
    } catch (e) {
      origem.insertBefore(card, proximo);
      d.status = antes; card.dataset.lead = JSON.stringify(d);
      recontar(status, antes);
      toast('Não foi possível salvar. Tente de novo.', true);
    }
    if (atual === card) { pintarStatus(status); carregarHistorico(d.id); }
  };

  // ---------- arrastar e soltar ----------
  board.addEventListener('dragstart', e => {
    const card = e.target.closest('.lead');
    if (!card) return;
    arrastando = card;
    card.classList.add('dragging');
    e.dataTransfer.effectAllowed = 'move';
    e.dataTransfer.setData('text/plain', card.dataset.id);
  });
  board.addEventListener('dragend', () => {
    if (arrastando) arrastando.classList.remove('dragging');
    arrastando = null;
    board.querySelectorAll('.col.drop').forEach(c => c.classList.remove('drop'));
  });
  board.querySelectorAll('.col').forEach(col => {
    col.addEventListener('dragover', e => { if (arrastando) { e.preventDefault(); col.classList.add('drop'); } });
    col.addEventListener('dragleave', e => { if (!col.contains(e.relatedTarget)) col.classList.remove('drop'); });
    col.addEventListener('drop', e => {
      e.preventDefault();
      col.classList.remove('drop');
      if (arrastando) mover(arrastando, col.dataset.status);
    });
  });

  // ---------- detalhes ----------
  const pintarStatus = s => dlg.querySelectorAll('.status-pick button').forEach(b => b.classList.toggle('on', b.dataset.s === s));

  // ---------- detalhes editáveis ----------
  const $ = id => document.getElementById(id);
  const campos = {nome: $('fNome'), telefone: $('fTel'), produto: $('fProd'), origem: $('fOrig'), proximo: $('fFu'), observacao: $('dObs')};
  if (gestor) campos.vendedor = $('fVend');
  Object.assign(campos, {troca_modelo: $('fTrocaModelo'), troca_capacidade: $('fTrocaCap'), troca_estado: $('fTrocaEstado'),
    troca_bateria: $('fTrocaBat'), troca_obs: $('fTrocaObs')});
  let originais = {};
  const btnSalvar = $('dSalvar');
  const mascara = v => {
    let d = v.replace(/\D/g, '');
    if (d.length > 11 && d.startsWith('55')) d = d.slice(2);
    d = d.slice(0, 11);
    return d.length > 6 ? `(${d.slice(0, 2)}) ${d.slice(2, d.length - 4)}-${d.slice(-4)}` : d.length > 2 ? `(${d.slice(0, 2)}) ${d.slice(2)}` : d;
  };
  const alterados = () => {
    const out = {};
    for (const k in campos) if (campos[k].value.trim() !== originais[k]) out[k] = campos[k].value.trim();
    return out;
  };
  const atualizarBotao = () => {
    const n = Object.keys(alterados()).length;
    btnSalvar.disabled = n === 0;
    btnSalvar.textContent = n ? `Salvar alterações (${n})` : 'Salvar alterações';
  };
  campos.telefone.addEventListener('input', () => { campos.telefone.value = mascara(campos.telefone.value); });
  Object.values(campos).forEach(c => { c.addEventListener('input', atualizarBotao); c.addEventListener('change', atualizarBotao); });

  const preencher = d => {
    campos.nome.value = d.nome;
    campos.telefone.value = mascara(d.tel);
    campos.produto.value = d.produto;
    campos.origem.value = d.origemKey || '';
    campos.proximo.value = d.proximo || '';
    campos.observacao.value = d.obs || '';
    if (gestor) campos.vendedor.value = d.vendedor; else $('dVendNome').textContent = d.vendedorNome;
    $('dEtapa').textContent = d.etapaNome;
    $('dSub').textContent = `Chegou em ${d.criado} (${d.ha})` + (d.campanha ? ` · campanha ${d.campanha}` : '') + (d.fuTexto ? ` · próximo contato ${d.fuTexto}` : '');
    $('dWa').href = `https://wa.me/${d.tel}?text=${encodeURIComponent(d.msg)}`;
    const t = d.troca || {};
    campos.troca_modelo.value = t.modelo || '';
    campos.troca_capacidade.value = t.capacidade || '';
    campos.troca_estado.value = t.estado || '';
    campos.troca_bateria.value = t.bateria || '';
    campos.troca_obs.value = t.obs || '';
    $('trocaResumo').textContent = t.tem ? t.resumo : 'Não informado';
    originais = {};
    for (const k in campos) originais[k] = campos[k].value.trim();
    pintarStatus(d.status);
    atualizarBotao();
  };

  // ---------- histórico ----------
  const ICONES = {site: '🌐', wa: '💬', status: '🔀', adiar: '⏰', edit: '✏️', troca: '🔄', vend: '👤'};
  const carregarHistorico = async id => {
    const lista = $('dHist');
    try {
      const r = await fetch(`/clientes/crmteste/painel/api.php?acao=historico&id=${encodeURIComponent(id)}`, {cache: 'no-store'});
      const j = await r.json();
      if (!j.ok || !atual || dados(atual).id !== id) return;
      lista.replaceChildren(...j.itens.map(it => {
        const li = document.createElement('li');
        const ic = document.createElement('span'); ic.className = 'tl-ic'; ic.textContent = ICONES[it.ic] || '•';
        const box = document.createElement('div');
        const txt = document.createElement('b'); txt.textContent = it.texto;
        const meta = document.createElement('small'); meta.textContent = `${it.quem} · ${it.quando}`;
        box.append(txt, meta);
        li.append(ic, box);
        return li;
      }));
      $('histResumo').textContent = j.itens.length === 1 ? '1 registro' : `${j.itens.length} registros`;
    } catch (e) {
      lista.replaceChildren(Object.assign(document.createElement('li'), {className: 'dim', textContent: 'Não foi possível carregar o histórico.'}));
    }
  };

  // redesenha o cartão com os dados atuais
  const redesenhar = (card, d) => {
    card.dataset.lead = JSON.stringify(d);
    card.querySelector('.lead-name').textContent = d.nome;
    const chips = card.querySelectorAll('.lead-meta .chip');
    chips[0].textContent = d.produto;
    if (chips[1]) chips[1].textContent = d.origem;
    const meta = card.querySelector('.lead-meta');
    let chipTroca = meta.querySelector('.chip.troca');
    if (d.troca && d.troca.tem) {
      if (!chipTroca) { chipTroca = document.createElement('span'); chipTroca.className = 'chip troca'; chipTroca.textContent = '🔄 Troca'; meta.appendChild(chipTroca); }
      chipTroca.title = d.troca.resumo;
    } else if (chipTroca) chipTroca.remove();
    const foot = card.querySelector('.lead-foot');
    const img = foot.querySelector('img');
    if (img) { img.src = d.foto || '/clientes/crmteste/assets/vendedor-padrao.svg'; img.nextElementSibling.textContent = d.vendedorNome; }
    foot.querySelector('.fu-tag')?.remove();
    // atraso só aparece nos leads que acabaram de chegar (coluna Novo)
    if (d.fu && d.fu !== 'futuro' && d.status === 'novo') foot.insertAdjacentHTML('afterbegin', `<span class="fu-tag ${d.fu}" title="Próximo follow-up">⏰ ${d.fuTexto}</span>`);
    const ic = foot.querySelector('.obs');
    if (d.obs && !ic) foot.querySelector('.when').insertAdjacentHTML('beforebegin', '<span class="obs" title="Tem observação">📝</span>');
    if (!d.obs && ic) ic.remove();
  };

  // aplica a resposta do servidor ao cartão e ao formulário
  const aplicar = (card, l) => {
    const d = dados(card);
    Object.assign(d, {
      nome: l.nome, tel: l.tel, telFmt: l.telFmt, produto: l.produto, vendedor: l.vendedor, vendedorNome: l.vendedorNome, foto: l.foto,
      origem: l.origem, origemKey: l.origemKey, obs: l.observacao || '', status: l.status, msg: l.mensagem,
      fu: l.fu, fuTexto: l.fuTexto, proximo: l.proximoInput, etapaNome: l.etapaNome, troca: l.troca,
    });
    redesenhar(card, d);
    if (atual === card) { preencher(d); carregarHistorico(d.id); }
    return d;
  };

  board.addEventListener('click', e => {
    const card = e.target.closest('.lead');
    if (!card) return;
    atual = card;
    preencher(dados(card));
    $('secHist').open = false;
    carregarHistorico(dados(card).id);
    dlg.showModal();
  });

  dlg.addEventListener('click', e => { if (e.target === dlg || e.target.closest('[data-close]')) fechar(); });
  dlg.addEventListener('cancel', e => { if (Object.keys(alterados()).length && !confirm('Descartar as alterações não salvas?')) e.preventDefault(); });
  const fechar = () => {
    if (Object.keys(alterados()).length && !confirm('Descartar as alterações não salvas?')) return;
    dlg.close();
  };
  dlg.querySelectorAll('.status-pick button').forEach(b => b.addEventListener('click', () => atual && mover(atual, b.dataset.s)));

  // ---------- cadastro manual (aba Cadastro) ----------
  const cad = $('cad');
  if (cad) {
    $('btnNovoCadastro').addEventListener('click', () => { cad.showModal(); cad.querySelector('[name=nome]').focus(); });
    cad.addEventListener('click', e => { if (e.target === cad || e.target.closest('[data-fechar-cad]')) cad.close(); });
    const telCad = cad.querySelector('[data-mascara-tel]');
    telCad.addEventListener('input', () => { telCad.value = mascara(telCad.value); });
    $('cadForm').addEventListener('submit', () => { cad.querySelector('[type=submit]').disabled = true; });
  }

  // apagar lead (botão só existe para o gestor): 1º toque arma, 2º toque confirma
  // (confirmação na própria tela: alguns navegadores bloqueiam a janela confirm())
  const btnApagar = $('dApagar');
  let armadoPara = null, desarmar = null;
  const resetApagar = () => {
    clearTimeout(desarmar);
    armadoPara = null;
    if (btnApagar) { btnApagar.classList.remove('armado'); btnApagar.textContent = 'Apagar lead'; }
  };
  if (btnApagar) {
    board.addEventListener('click', resetApagar, true); // abriu outro lead: volta ao normal
    btnApagar.addEventListener('click', async () => {
      if (!atual) return;
      const card = atual, d = dados(card);
      if (armadoPara !== card) {
        armadoPara = card;
        btnApagar.classList.add('armado');
        btnApagar.textContent = `Toque de novo para apagar ${d.nome} de vez`;
        clearTimeout(desarmar);
        desarmar = setTimeout(resetApagar, 5000);
        return;
      }
      resetApagar();
      btnApagar.disabled = true;
      try {
        await api('apagar', {id: d.id});
        card.remove();
        contar(d.status, -1);
        atual = null;
        dlg.close();
        toast('Lead apagado');
      } catch (x) {
        toast(x.message && x.message !== 'falha' ? x.message : 'Não foi possível apagar.', true);
      } finally {
        btnApagar.disabled = false;
      }
    });
  }
  // veio pela notificação (/clientes/crmteste/painel/?lead=ID): abre o lead direto
  const pedido = new URLSearchParams(location.search).get('lead');
  if (pedido) {
    history.replaceState(null, '', location.pathname);
    const card = [...board.querySelectorAll('.lead')].find(c => String(dados(c).id) === pedido);
    if (card) { mostrarAba(dados(card).status); card.click(); }
  }

  btnSalvar.addEventListener('click', async () => {
    if (!atual) return;
    const mud = alterados();
    if (!Object.keys(mud).length) return;
    btnSalvar.disabled = true;
    try {
      const l = await api('editar', {id: dados(atual).id, ...mud});
      aplicar(atual, l);
      toast('Alterações salvas');
    } catch (x) {
      toast(x.message && x.message !== 'falha' ? x.message : 'Não foi possível salvar.', true);
      atualizarBotao();
    }
  });

  // chamar no WhatsApp registra o contato da etapa atual (agenda o próximo follow-up);
  // um lead "Novo" passa automaticamente para "Em atendimento"
  $('dWa').addEventListener('click', async () => {
    if (!atual) return;
    const card = atual, antes = dados(card).status;
    try {
      const l = await api('contato', {id: dados(card).id});
      if (antes === 'novo') {
        coluna('atendimento').querySelector('.col-body').prepend(card);
        card.classList.remove('is-new');
        recontar('novo', 'atendimento');
      }
      aplicar(card, l);
      toast(l.proximo ? `Contato registrado · próximo ${l.proximoTexto}` : 'Contato registrado · cadência concluída');
    } catch (e) { toast('WhatsApp aberto, mas não foi possível registrar o contato.', true); }
  });

  // ---------- aviso de novos leads (a cada 30 s) ----------
  const titulo = document.title;
  const btnRec = document.getElementById('recarregar');
  btnRec.addEventListener('click', () => location.reload());
  setInterval(async () => {
    if (document.hidden || dlg.open || board.dataset.canal === 'manual') return;
    try {
      const r = await fetch(`/clientes/crmteste/painel/api.php?acao=novos&desde=${board.dataset.ultimo}`, {cache: 'no-store'});
      const j = await r.json();
      if (j.novos > 0) {
        document.title = `(${j.novos}) ${titulo}`;
        const box = document.getElementById('novidade');
        box.hidden = false;
        btnRec.hidden = false;
        btnRec.textContent = j.novos === 1 ? '1 lead novo chegou · atualizar' : `${j.novos} leads novos chegaram · atualizar`;
      }
    } catch (e) {}
  }, 30000);
})();
