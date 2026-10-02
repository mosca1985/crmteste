<?php
/**
 * Kanban de leads, usado por index.php (leads do site) e cadastro.php (cadastro manual: pessoal e Direct).
 * Espera $canal = 'site' | 'manual'. Os dois canais somam juntos no dashboard e na TV.
 */
declare(strict_types=1);
if (!isset($canal) || !in_array($canal, ['site', 'manual'], true)) { http_response_code(404); exit; }
$u = exige_login();
$gestor = eh_gestor($u);

// em aberto (Novo, Em atendimento, Proposta): sempre todos; "Vendido"/"Perdido": pelo período escolhido
$_GET['canal'] = $canal;
[$f, $wAbertos, $pAbertos] = filtros_leads($u, '30', 'tudo');
[, $wFechados, $pFechados] = filtros_leads($u, '30');
unset($f['status']);

$colunas = [];
foreach (STATUS as $s => $rotulo) {
    $aberto = in_array($s, STATUS_ABERTOS, true);
    $w = $aberto ? $wAbertos : $wFechados;
    $p = $aberto ? $pAbertos : $pFechados;
    $w .= ($w ? ' AND ' : 'WHERE ') . 'status = ?';
    $p[] = $s;
    $st = db()->prepare("SELECT COUNT(*) FROM leads $w");
    $st->execute($p);
    $total = (int) $st->fetchColumn();
    $st = db()->prepare("SELECT * FROM leads $w ORDER BY " . ($s === 'novo' ? 'criado_em DESC' : 'COALESCE(atualizado_em, criado_em) DESC') . ' LIMIT 100');
    $st->execute($p);
    $colunas[$s] = ['rotulo' => $rotulo, 'total' => $total, 'leads' => $st->fetchAll()];
}
$novos = $colunas['novo']['total'];
$novosHoje = 0;
foreach ($colunas['novo']['leads'] as $l) if (date('Y-m-d', strtotime($l['criado_em'])) === date('Y-m-d')) $novosHoje++;
$st = db()->prepare('SELECT COALESCE(MAX(id),0) FROM leads' . ($gestor ? '' : ' WHERE ' . sql_vendedor((string) $u['vendedor'])));
$st->execute();
$ultimoId = (int) $st->fetchColumn();

cabecalho($canal === 'manual' ? 'Cadastro' : 'Andamento', $u, $canal === 'manual' ? 'cadastro' : 'kanban');
?>
<div class="page-head">
  <div>
    <?php if ($canal === 'manual'): ?>
    <h1>Cadastro manual</h1>
    <p>Clientes atendidos pessoalmente ou pelo Direct. Somam no dashboard e na TV junto com os leads do site.</p>
    <?php else: ?>
    <h1><?= $gestor ? 'Andamento dos leads' : 'Meus leads' ?></h1>
    <p>Leads do site. Arraste os cartões entre as colunas ou toque para ver os detalhes.</p>
    <?php endif; ?>
  </div>
  <?php if ($canal === 'manual'): ?><button type="button" class="btn" id="btnNovoCadastro">+ Novo cadastro</button><?php endif; ?>
  <form class="toolbar" method="get">
    <input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Buscar nome ou telefone">
    <select name="produto" data-autosubmit><option value="">Todos os produtos</option>
      <?php foreach (PRODUTOS as $pr): ?><option <?= $f['produto'] === $pr ? 'selected' : '' ?>><?= e($pr) ?></option><?php endforeach; ?>
    </select>
    <?php if ($gestor): ?>
    <select name="vendedor" data-autosubmit><option value="">Todos os vendedores</option>
      <?php foreach (VENDEDORES as $slug => [$nv]): ?><option value="<?= e($slug) ?>" <?= $f['vendedor'] === $slug ? 'selected' : '' ?>><?= e($nv) ?></option><?php endforeach; ?>
    </select>
    <?php endif; ?>
    <input type="hidden" name="periodo" value="<?= e($f['periodo']) ?>">
    <div class="seg" title="Período de Vendido e Perdido">
      <?php foreach (['7' => '7d', '30' => '30d', '90' => '90d', 'tudo' => 'Tudo'] as $k => $v): ?>
        <a href="<?= $canal === 'manual' ? '/clientes/crmteste/painel/cadastro.php' : '/clientes/crmteste/painel/' ?>?<?= e(qs($f, ['periodo' => $k, 'canal' => ''])) ?>" class="<?= $f['periodo'] === (string) $k ? 'on' : '' ?>"><?= $v ?></a>
      <?php endforeach; ?>
    </div>
  </form>
</div>

<div id="novidade" class="alert-new" <?= $novos && $canal === 'site' ? '' : 'hidden' ?>>
  <span class="dot"></span>
  <div><b id="novosTotal"><?= $novos ?></b> <?= $novos === 1 ? 'lead novo aguardando' : 'leads novos aguardando' ?> atendimento
    <span class="muted small"><?= $novosHoje ? '· ' . $novosHoje . ' chegaram hoje' : '' ?></span></div>
  <button class="btn sm" id="recarregar" type="button" hidden style="margin-left:auto">Atualizar</button>
</div>

<!-- celular: uma coluna por vez, escolhida nas abas -->
<div class="col-tabs" role="tablist" aria-label="Andamento">
  <?php foreach ($colunas as $s => $c): ?>
    <button type="button" role="tab" data-status="<?= e($s) ?>" aria-selected="<?= $s === 'novo' ? 'true' : 'false' ?>"><?= e(['atendimento' => 'Atendimento', 'proposta' => 'Proposta'][$s] ?? $c['rotulo']) ?> <span class="tab-count" data-count-for="<?= e($s) ?>"><?= $c['total'] ?></span></button>
  <?php endforeach; ?>
</div>
<div class="board" id="board" data-canal="<?= e($canal) ?>" data-show="novo" data-ultimo="<?= $ultimoId ?>" data-gestor="<?= $gestor ? 1 : 0 ?>">
<?php foreach ($colunas as $s => $c): ?>
  <section class="col" data-status="<?= e($s) ?>">
    <div class="col-head"><h2><?= e($c['rotulo']) ?></h2><span class="count"><?= $c['total'] ?></span></div>
    <div class="col-body">
      <?php if (!$c['leads']): ?><div class="empty"><?= $s === 'novo' ? ($canal === 'manual' ? 'Nenhum cadastro novo.' : 'Nenhum lead novo. 🎉') : 'Arraste leads para cá' ?></div><?php endif; ?>
      <?php foreach ($c['leads'] as $l):
        $vn = VENDEDORES[$l['vendedor']][0] ?? $l['vendedor'];
        $dados = [
            'id' => (int) $l['id'], 'nome' => $l['nome'], 'tel' => $l['telefone'], 'telFmt' => formata_telefone($l['telefone']),
            'produto' => $l['produto'], 'vendedor' => $l['vendedor'], 'vendedorNome' => $vn, 'foto' => foto_vendedor($l['vendedor']), 'status' => $l['status'],
            'obs' => (string) $l['observacao'], 'origem' => origem_legivel($l), 'campanha' => (string) $l['campanha'],
            'criado' => date('d/m/Y H:i', strtotime($l['criado_em'])), 'ha' => ha_quanto($l['criado_em']),
            'msg' => followup_texto((int) $l['followup_etapa'], $l),
            'etapaNome' => FOLLOWUP[min((int) $l['followup_etapa'], count(FOLLOWUP) - 1)]['nome'],
            'fu' => followup_situacao($l), 'fuTexto' => $l['proximo_followup'] ? followup_quando($l) : '',
            'origemKey' => origem_chave($l),
            'proximo' => $l['proximo_followup'] ? date('Y-m-d\TH:i', strtotime($l['proximo_followup'])) : '',
            'troca' => troca_json($l),
        ];
        $fuSit = followup_situacao($l);
        $novoRecente = $l['status'] === 'novo' && (time() - strtotime($l['criado_em'])) < 86400; ?>
      <button type="button" class="lead<?= $novoRecente ? ' is-new' : '' ?>" draggable="true" data-id="<?= (int) $l['id'] ?>" data-lead="<?= e(json_encode($dados, JSON_UNESCAPED_UNICODE)) ?>">
        <div class="lead-name"><?= e($l['nome']) ?></div>
        <div class="lead-meta">
          <span class="chip prod"><?= e($l['produto']) ?></span>
          <span class="chip"><?= e(origem_legivel($l)) ?></span>
          <?php if (TROCA_ATIVA && !empty($l['troca_modelo'])): ?><span class="chip troca" title="<?= e(troca_json($l)['resumo']) ?>">🔄 Troca</span><?php endif; ?>
        </div>
        <div class="lead-foot">
          <?php if ($gestor): ?><img src="<?= e(foto_vendedor($l['vendedor'])) ?>" alt=""><span><?= e($vn) ?></span><?php endif; ?>
          <?php if ($fuSit && $fuSit !== 'futuro' && $l['status'] === 'novo'): // atraso só nos que acabaram de chegar ?><span class="fu-tag <?= $fuSit ?>" title="Próximo follow-up">⏰ <?= e(followup_quando($l)) ?></span><?php endif; ?>
          <?php if ($l['observacao']): ?><span class="obs" title="Tem observação">📝</span><?php endif; ?>
          <span class="when"><?= e(ha_quanto($l['criado_em'])) ?></span>
        </div>
      </button>
      <?php endforeach; ?>
      <?php if ($c['total'] > count($c['leads'])): ?><a class="empty" href="/clientes/crmteste/painel/lista.php?<?= e(qs($f, ['status' => $s])) ?>">Ver todos os <?= $c['total'] ?> na lista →</a><?php endif; ?>
    </div>
  </section>
<?php endforeach; ?>
</div>

<!-- detalhes do lead -->
<dialog class="det" id="det">
  <div class="det-card">
    <button class="det-x" type="button" data-close aria-label="Fechar">&times;</button>
    <div class="det-head">
      <input class="det-name" id="fNome" maxlength="80" aria-label="Nome do cliente" autocomplete="off">
      <p id="dSub"></p>
    </div>
    <div class="det-grid det-edit">
      <label><small>WhatsApp</small><input id="fTel" type="tel" inputmode="tel" autocomplete="off"></label>
      <label><small>Produto</small><select id="fProd"><?php foreach (PRODUTOS as $p): ?><option><?= e($p) ?></option><?php endforeach; ?></select></label>
      <label><small>Origem</small><select id="fOrig"><option value="">Não identificada</option><?php foreach (ORIGENS as $ok => $ov): ?><option value="<?= e($ok) ?>"><?= e($ov) ?></option><?php endforeach; ?></select></label>
      <label><small>Próximo contato</small><input id="fFu" type="datetime-local"></label>
      <div class="ro"><small>Próxima etapa</small><b id="dEtapa"></b></div>
      <?php if ($gestor): ?>
      <label><small>Vendedor</small><select id="fVend"><?php foreach (VENDEDORES as $slug => [$nv]): ?><option value="<?= e($slug) ?>"><?= e($nv) ?></option><?php endforeach; ?></select></label>
      <?php else: ?>
      <div class="ro"><small>Vendedor</small><b id="dVendNome"></b></div>
      <?php endif; ?>
    </div>
    <div>
      <label style="margin-bottom:8px">Andamento</label>
      <div class="status-pick"><?php foreach (STATUS as $k => $v): ?><button type="button" data-s="<?= e($k) ?>"><?= e($v) ?></button><?php endforeach; ?></div>
    </div>
    <label>Observações<textarea id="dObs" placeholder="Ex.: quer o modelo X na cor azul, retorna amanhã"></textarea></label>

    <details class="det-sec" id="secTroca"<?= TROCA_ATIVA ? '' : ' hidden' ?>>
      <summary><span>🔄 Aparelho na troca</span><em id="trocaResumo">Não informado</em></summary>
      <div class="det-grid det-edit">
        <label><small>Modelo</small><input id="fTrocaModelo" list="modelosTroca" maxlength="40" placeholder="Ex.: modelo e ano" autocomplete="off"></label>
        <label><small>Capacidade</small><select id="fTrocaCap"><option value="">—</option><?php foreach (TROCA_CAPACIDADES as $c): ?><option><?= e($c) ?></option><?php endforeach; ?></select></label>
        <label><small>Estado</small><select id="fTrocaEstado"><option value="">—</option><?php foreach (TROCA_ESTADOS as $k => $v): ?><option value="<?= e($k) ?>"><?= e($v) ?></option><?php endforeach; ?></select></label>
        <label><small>Saúde da bateria (%)</small><input id="fTrocaBat" type="number" min="1" max="100" inputmode="numeric" placeholder="Ex.: 86"></label>
      </div>
      <label style="margin-top:10px">Detalhes do usado<input id="fTrocaObs" maxlength="255" placeholder="Ex.: tela trincada no canto, tem caixa e carregador"></label>
      <datalist id="modelosTroca"><?php foreach (TROCA_MODELOS as $m): ?><option value="<?= e($m) ?>"><?php endforeach; ?></datalist>
    </details>

    <details class="det-sec" id="secHist">
      <summary><span>🕓 Histórico</span><em id="histResumo">Ver tudo o que aconteceu</em></summary>
      <ol class="timeline" id="dHist"><li class="dim">Carregando…</li></ol>
    </details>
    <div class="det-actions">
      <a class="btn wa" id="dWa" target="_blank" rel="noopener"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.2c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.3-.3.6-.3.8-.3h.6c.2 0 .4 0 .6.5l.9 2.1c.1.2.1.4 0 .5l-.4.6-.4.4c-.1.2-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.4 1.5.3.1.5.1.6-.1l.9-1.1c.2-.3.4-.2.7-.1l2 1c.3.1.5.2.5.3.1.2.1.7-.1 1.3z"/></svg>Chamar no WhatsApp</a>
      <button class="btn ghost" type="button" id="dSalvar" disabled>Salvar alterações</button>
      <?php if ($gestor): ?><button class="btn-del" type="button" id="dApagar">Apagar lead</button><?php endif; ?>
    </div>
  </div>
</dialog>
<div class="toast" id="toast" role="status"></div>
<?php if ($canal === 'manual'): ?>
<!-- novo cadastro manual -->
<dialog class="det" id="cad">
  <form class="det-card" method="post" action="/clientes/crmteste/painel/cadastro.php" id="cadForm">
    <button class="det-x" type="button" data-fechar-cad aria-label="Fechar">&times;</button>
    <div class="det-head"><h2 class="det-name" style="border:0">Novo cadastro</h2><p>Cliente atendido pessoalmente ou pelo Direct.</p></div>
    <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
    <div class="origem-pick" role="radiogroup" aria-label="Origem">
      <?php $i = 0; foreach (ORIGENS_MANUAIS as $ok => $ov): ?>
        <label><input type="radio" name="origem" value="<?= e($ok) ?>" <?= $i++ === 0 ? 'checked' : '' ?>><span><?= $ok === 'pessoal' ? '🏬' : '💬' ?> <?= e($ov) ?></span></label>
      <?php endforeach; ?>
    </div>
    <div class="det-grid det-edit">
      <label><small>Nome</small><input name="nome" maxlength="80" required autocomplete="off"></label>
      <label><small>WhatsApp</small><input name="telefone" type="tel" inputmode="tel" placeholder="(11) 99999-9999" required autocomplete="off" data-mascara-tel></label>
      <label><small>Produto</small><select name="produto"><?php foreach (PRODUTOS as $p): ?><option><?= e($p) ?></option><?php endforeach; ?></select></label>
      <?php if ($gestor): ?>
      <label><small>Vendedor</small><select name="vendedor" required><?php foreach (VENDEDORES as $slug => [$nv]): ?><option value="<?= e($slug) ?>"><?= e($nv) ?></option><?php endforeach; ?></select></label>
      <?php else: ?>
      <div class="ro"><small>Vendedor</small><b><?= e(VENDEDORES[$u['vendedor']][0] ?? $u['nome']) ?></b></div>
      <?php endif; ?>
      <label><small>Andamento</small><select name="status"><?php foreach (STATUS as $sk => $sv): if ($sk === 'perdido') continue; ?><option value="<?= e($sk) ?>" <?= $sk === 'atendimento' ? 'selected' : '' ?>><?= e($sv) ?></option><?php endforeach; ?></select></label>
    </div>
    <label>Observações<textarea name="observacao" maxlength="2000" placeholder="Ex.: quer o modelo X, volta sábado"></textarea></label>
    <div class="det-actions"><button class="btn" type="submit">Cadastrar</button><button class="btn ghost" type="button" data-fechar-cad>Cancelar</button></div>
  </form>
</dialog>
<?php endif; ?>
<?php rodape(['/clientes/crmteste/painel/painel.js?v=10']);
