// Follow-ups: envia pelo WhatsApp do vendedor (mensagem pronta), registra o contato e agenda a próxima etapa.
(() => {
  const CSRF = document.querySelector('meta[name=csrf]').content;
  const toastEl = document.getElementById('toast');
  const toast = (msg, erro) => {
    toastEl.textContent = msg;
    toastEl.classList.toggle('err', !!erro);
    toastEl.classList.add('show');
    clearTimeout(toast.t);
    toast.t = setTimeout(() => toastEl.classList.remove('show'), 3000);
  };
  const api = async (acao, dados) => {
    const r = await fetch('/clientes/crmteste/painel/api.php', {method: 'POST', headers: {'X-CSRF': CSRF}, body: new URLSearchParams({acao, ...dados})});
    const j = await r.json().catch(() => ({}));
    if (!r.ok || !j.ok) throw new Error(j.error || 'falha');
    return j.lead;
  };
  const somar = (id, d) => { const n = document.getElementById(id); if (n) n.textContent = Math.max(0, +n.textContent + d); };

  // tira o item da lista e atualiza placar/badge
  const concluir = (item, texto) => {
    const grupo = item.closest('.fu-group');
    const pendente = grupo.classList.contains('fu-atrasado') || grupo.classList.contains('fu-hoje');
    item.classList.add('done');
    item.querySelector('.fu-actions').innerHTML = `<span class="fu-ok">${texto}</span>`;
    setTimeout(() => {
      item.style.height = item.offsetHeight + 'px';
      requestAnimationFrame(() => item.classList.add('out'));
      setTimeout(() => {
        item.remove();
        const cnt = grupo.querySelector('h2 .count');
        cnt.textContent = grupo.querySelectorAll('.fu-item').length;
        if (!grupo.querySelector('.fu-item')) grupo.remove();
      }, 400);
    }, 1400);
    if (pendente) {
      somar('fuPend', -1);
      if (grupo.classList.contains('fu-atrasado')) somar('fuAtras', -1);
      const b = document.getElementById('fuBadge');
      if (b) { b.textContent = Math.max(0, +b.textContent - 1); if (b.textContent === '0') b.remove(); }
    }
  };

  document.addEventListener('click', async e => {
    const btn = e.target.closest('.fu-item [data-acao]');
    if (!btn) return;
    const item = btn.closest('.fu-item');
    const id = item.dataset.id;
    const acao = btn.dataset.acao;
    item.querySelectorAll('button').forEach(b => b.disabled = true);

    try {
      if (acao === 'enviar') {
        // abre o WhatsApp já no clique (evita bloqueio de pop-up) e registra em seguida
        const texto = item.querySelector('textarea').value.trim();
        window.open(`https://wa.me/${item.dataset.tel}?text=${encodeURIComponent(texto)}`, '_blank', 'noopener');
        const l = await api('contato', {id});
        somar('fuFeitos', +1);
        concluir(item, l.proximo ? `✓ Enviado · próximo contato ${l.proximoTexto}` : '✓ Enviado · cadência concluída');
      } else if (acao === 'adiar') {
        const l = await api('adiar', {id, horas: btn.dataset.horas});
        concluir(item, `⏰ Adiado para ${l.proximoTexto}`);
      } else {
        await api('mover', {id, status: acao});
        concluir(item, acao === 'vendido' ? '🎉 Marcado como vendido' : 'Marcado como perdido');
      }
    } catch (x) {
      item.querySelectorAll('button').forEach(b => b.disabled = false);
      toast('Não foi possível salvar. Tente de novo.', true);
    }
  });
})();
