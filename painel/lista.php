<?php
declare(strict_types=1);
require __DIR__ . '/_core.php';
$u = exige_login();
$gestor = eh_gestor($u);

[$f, $where, $par] = filtros_leads($u, '30');
$pagina = max(1, (int) ($_GET['p'] ?? 1));
$porPagina = 50;

$st = db()->prepare("SELECT COUNT(*) FROM leads $where");
$st->execute($par);
$total = (int) $st->fetchColumn();
$st = db()->prepare("SELECT * FROM leads $where ORDER BY criado_em DESC LIMIT " . (($pagina - 1) * $porPagina) . ", $porPagina");
$st->execute($par);
$leads = $st->fetchAll();

cabecalho('Lista de leads', $u, 'lista');
?>
<div class="page-head">
  <div><h1>Lista de leads</h1><p><?= $total ?> <?= $total === 1 ? 'lead encontrado' : 'leads encontrados' ?></p></div>
  <a class="btn sm ghost" href="/clientes/crmteste/painel/export.php?<?= e(qs($f)) ?>">⬇ Exportar Excel</a>
</div>

<form class="card filters" method="get">
  <?php if ($gestor): ?>
  <label>Vendedor<select name="vendedor"><option value="">Todos</option>
    <?php foreach (VENDEDORES as $slug => [$nv]): ?><option value="<?= e($slug) ?>" <?= $f['vendedor'] === $slug ? 'selected' : '' ?>><?= e($nv) ?></option><?php endforeach; ?>
  </select></label>
  <?php endif; ?>
  <label>Andamento<select name="status"><option value="">Todos</option>
    <?php foreach (STATUS as $k => $v): ?><option value="<?= e($k) ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select></label>
  <label>Canal<select name="canal"><option value="">Site + cadastro manual</option>
    <option value="site" <?= $f['canal'] === 'site' ? 'selected' : '' ?>>Só site</option>
    <option value="manual" <?= $f['canal'] === 'manual' ? 'selected' : '' ?>>Só cadastro manual</option>
  </select></label>
  <label>Produto<select name="produto"><option value="">Todos</option>
    <?php foreach (PRODUTOS as $p): ?><option <?= $f['produto'] === $p ? 'selected' : '' ?>><?= e($p) ?></option><?php endforeach; ?>
  </select></label>
  <label>Período<select name="periodo">
    <?php foreach (PERIODOS as $k => $v): ?><option value="<?= e($k) ?>" <?= $f['periodo'] === (string) $k ? 'selected' : '' ?>><?= e($v) ?></option><?php endforeach; ?>
  </select></label>
  <label>Buscar<input type="search" name="q" value="<?= e($f['q']) ?>" placeholder="Nome ou telefone"></label>
  <div><button class="btn sm" type="submit">Filtrar</button></div>
</form>

<div class="card" style="padding:6px 8px;overflow-x:auto">
<table class="leads">
  <thead><tr><th>Chegou</th><th>Cliente</th><th>WhatsApp</th><th>Produto</th><?php if ($gestor): ?><th>Vendedor</th><?php endif; ?><th>Origem</th><th>Andamento</th><th>Observação</th></tr></thead>
  <tbody>
  <?php if (!$leads): ?><tr><td colspan="8" class="muted">Nenhum lead encontrado.</td></tr><?php endif; ?>
  <?php foreach ($leads as $l): ?>
    <tr>
      <td data-l="Chegou"><?= e(date('d/m H:i', strtotime($l['criado_em']))) ?><br><span class="dim small"><?= e(ha_quanto($l['criado_em'])) ?></span></td>
      <td data-l="Cliente"><b><?= e($l['nome']) ?></b></td>
      <td data-l="WhatsApp"><a class="wa-link" href="https://wa.me/<?= e($l['telefone']) ?>" target="_blank" rel="noopener"><?= e(formata_telefone($l['telefone'])) ?></a></td>
      <td data-l="Produto"><?= e($l['produto']) ?></td>
      <?php if ($gestor): ?><td data-l="Vendedor"><span class="seller"><img src="<?= e(foto_vendedor($l['vendedor'])) ?>" alt="" style="width:26px;height:26px"><?= e(VENDEDORES[$l['vendedor']][0] ?? $l['vendedor']) ?></span></td><?php endif; ?>
      <td data-l="Origem" class="small"><?= e(origem_legivel($l)) ?></td>
      <td data-l="Andamento"><span class="pill s-<?= e($l['status']) ?>"><?= e(STATUS[$l['status']] ?? $l['status']) ?></span></td>
      <td data-l="Observação" class="small muted"><?= e(mb_strimwidth((string) $l['observacao'], 0, 80, '…')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php $paginas = (int) ceil($total / $porPagina); if ($paginas > 1): ?>
<div class="pager">
  <?php if ($pagina > 1): ?><a class="btn sm ghost" href="?<?= e(qs($f, ['p' => $pagina - 1])) ?>">← Anterior</a><?php endif; ?>
  <span class="muted" style="align-self:center">Página <?= $pagina ?> de <?= $paginas ?></span>
  <?php if ($pagina < $paginas): ?><a class="btn sm ghost" href="?<?= e(qs($f, ['p' => $pagina + 1])) ?>">Próxima →</a><?php endif; ?>
</div>
<?php endif; ?>
<?php rodape();
