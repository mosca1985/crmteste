<?php
declare(strict_types=1);
require __DIR__ . '/_core.php';
$u = exige_login();
$gestor = eh_gestor($u);

$vend = $gestor ? (string) ($_GET['vendedor'] ?? '') : (string) $u['vendedor'];
$w = "status IN ('novo','atendimento','proposta') AND proximo_followup IS NOT NULL AND proximo_followup < CURDATE() + INTERVAL 8 DAY";
$p = [];
if (isset(VENDEDORES[$vend])) $w .= ' AND ' . ($gestor ? 'vendedor = ' . db()->quote($vend) : sql_vendedor($vend));
$st = db()->prepare("SELECT * FROM leads WHERE $w ORDER BY proximo_followup ASC LIMIT 300");
$st->execute($p);

$grupos = ['atrasado' => [], 'hoje' => [], 'futuro' => []];
foreach ($st->fetchAll() as $l) {
    $s = followup_situacao($l);
    if ($s) $grupos[$s][] = $l;
}

// contatos feitos hoje (para o placar do dia)
$st = db()->prepare("SELECT COUNT(*) FROM lead_eventos e JOIN leads l ON l.id = e.lead_id
    WHERE e.tipo = 'contato' AND e.em >= CURDATE()" . (isset(VENDEDORES[$vend]) ? ' AND ' . ($gestor ? 'l.vendedor = ' . db()->quote($vend) : sql_vendedor($vend, 'l.vendedor')) : ''));
$st->execute();
$feitosHoje = (int) $st->fetchColumn();
$pendentes = count($grupos['atrasado']) + count($grupos['hoje']);

$titulos = [
    'atrasado' => ['Atrasados', 'Esses clientes estão esperando — comece por aqui.'],
    'hoje'     => ['Para hoje', 'Contatos programados para hoje.'],
    'futuro'   => ['Próximos dias', 'O que vem pela frente na semana.'],
];

cabecalho('Follow-ups', $u, 'followups');
?>
<div class="page-head">
  <div>
    <h1>Follow-ups</h1>
    <p>Toque em <b>Enviar no WhatsApp</b>: a mensagem abre pronta e o próximo contato é agendado sozinho.</p>
  </div>
  <?php if ($gestor): ?>
  <form class="toolbar" method="get">
    <select name="vendedor" data-autosubmit><option value="">Todos os vendedores</option>
      <?php foreach (VENDEDORES as $slug => [$nv]): ?><option value="<?= e($slug) ?>" <?= $vend === $slug ? 'selected' : '' ?>><?= e($nv) ?></option><?php endforeach; ?>
    </select>
  </form>
  <?php endif; ?>
</div>

<div class="fu-score">
  <div class="card"><small>Pendentes hoje</small><b id="fuPend"><?= $pendentes ?></b></div>
  <div class="card <?= $grupos['atrasado'] ? 'bad' : '' ?>"><small>Atrasados</small><b id="fuAtras"><?= count($grupos['atrasado']) ?></b></div>
  <div class="card good"><small>Feitos hoje</small><b id="fuFeitos"><?= $feitosHoje ?></b></div>
  <div class="card"><small>Próximos 7 dias</small><b><?= count($grupos['futuro']) ?></b></div>
</div>

<?php if (!$pendentes): ?>
  <div class="card fu-zero">🎉 <b>Tudo em dia!</b> Nenhum follow-up pendente para hoje.</div>
<?php endif; ?>

<?php foreach ($grupos as $g => $lista): if (!$lista) continue; ?>
<section class="fu-group fu-<?= $g ?>">
  <h2><?= e($titulos[$g][0]) ?> <span class="count"><?= count($lista) ?></span></h2>
  <p class="dim small" style="margin:-4px 0 12px"><?= e($titulos[$g][1]) ?></p>
  <div class="fu-list">
  <?php foreach ($lista as $l):
    $etapa = (int) $l['followup_etapa'];
    $vn = VENDEDORES[$l['vendedor']][0] ?? $l['vendedor']; ?>
    <article class="fu-item" data-id="<?= (int) $l['id'] ?>" data-tel="<?= e($l['telefone']) ?>">
      <div class="fu-who">
        <div class="fu-name"><?= e($l['nome']) ?></div>
        <div class="lead-meta">
          <span class="chip prod"><?= e($l['produto']) ?></span>
          <span class="chip"><?= e(STATUS[$l['status']]) ?></span>
          <?php if ($gestor): ?><span class="chip"><img src="<?= e(foto_vendedor($l['vendedor'])) ?>" alt="" class="mini"><?= e($vn) ?></span><?php endif; ?>
        </div>
        <div class="fu-step">
          <span class="dots"><?php for ($i = 0; $i < count(FOLLOWUP); $i++): ?><i class="<?= $i < $etapa ? 'done' : ($i === $etapa ? 'cur' : '') ?>"></i><?php endfor; ?></span>
          <b><?= e(FOLLOWUP[min($etapa, count(FOLLOWUP) - 1)]['nome']) ?></b>
          <span class="when <?= $g ?>"><?= e(followup_quando($l)) ?></span>
        </div>
        <?php if ($l['observacao']): ?><p class="fu-obs">📝 <?= e(mb_strimwidth((string) $l['observacao'], 0, 140, '…')) ?></p><?php endif; ?>
      </div>
      <div class="fu-msg">
        <textarea rows="4" aria-label="Mensagem para <?= e($l['nome']) ?>"><?= e(followup_texto($etapa, $l)) ?></textarea>
        <div class="fu-actions">
          <button type="button" class="btn wa" data-acao="enviar"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M12 2a10 10 0 0 0-8.6 15.1L2 22l5-1.3A10 10 0 1 0 12 2zm5.3 14.2c-.2.6-1.3 1.2-1.8 1.2-.5.1-1 .2-3.3-.7-2.8-1.1-4.6-4-4.7-4.2-.1-.2-1.1-1.5-1.1-2.9s.7-2 1-2.3c.3-.3.6-.3.8-.3h.6c.2 0 .4 0 .6.5l.9 2.1c.1.2.1.4 0 .5l-.4.6-.4.4c-.1.2-.3.3-.1.6.2.3.8 1.3 1.7 2.1 1.2 1 2.1 1.4 2.4 1.5.3.1.5.1.6-.1l.9-1.1c.2-.3.4-.2.7-.1l2 1c.3.1.5.2.5.3.1.2.1.7-.1 1.3z"/></svg>Enviar no WhatsApp</button>
          <button type="button" class="btn ghost sm" data-acao="adiar" data-horas="24">Adiar 1 dia</button>
          <button type="button" class="btn ghost sm" data-acao="vendido">✓ Vendido</button>
          <button type="button" class="btn ghost sm" data-acao="perdido">Perdido</button>
        </div>
      </div>
    </article>
  <?php endforeach; ?>
  </div>
</section>
<?php endforeach; ?>
<div class="toast" id="toast" role="status"></div>
<?php rodape(['/clientes/crmteste/painel/followups.js?v=1']);
