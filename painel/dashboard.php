<?php
declare(strict_types=1);
require __DIR__ . '/_core.php';
$u = exige_login();
if (!eh_gestor($u)) {
    header('Location: /clientes/crmteste/painel/');
    exit;
}
$pdo = db();

// ---------- período ----------
// padrão: o mês atual. Também aceita um intervalo personalizado (?de=AAAA-MM-DD&ate=AAAA-MM-DD).
const PERIODOS_DASH = ['mes' => 'Este mês', 'mes_ant' => 'Mês passado', 'hoje' => 'Hoje', '7' => '7 dias', '30' => '30 dias', '90' => '90 dias', 'tudo' => 'Tudo'];
$data_ok = function (string $d): bool {
    $t = DateTime::createFromFormat('!Y-m-d', $d);
    return $t && $t->format('Y-m-d') === $d;
};
$per = (string) ($_GET['periodo'] ?? 'mes');
$de = (string) ($_GET['de'] ?? '');
$ate = (string) ($_GET['ate'] ?? '');
$hojeD = date('Y-m-d');
if ($data_ok($de) && $data_ok($ate)) {
    $per = 'custom';
    if ($de > $ate) [$de, $ate] = [$ate, $de];
    if (strtotime($ate) - strtotime($de) > 3 * 366 * 86400) $de = date('Y-m-d', strtotime($ate . ' -3 years'));
    $iniD = $de; $fimD = $ate;
} else {
    if (!isset(PERIODOS_DASH[$per])) $per = 'mes';
    switch ($per) {
        case 'mes':     $iniD = date('Y-m-01'); $fimD = $hojeD; break;
        case 'mes_ant': $iniD = date('Y-m-01', strtotime('first day of last month')); $fimD = date('Y-m-t', strtotime('first day of last month')); break;
        case 'hoje':    $iniD = $fimD = $hojeD; break;
        case 'tudo':
            $primeiro = $pdo->query('SELECT MIN(criado_em) FROM leads')->fetchColumn();
            $iniD = $primeiro ? date('Y-m-d', strtotime((string) $primeiro)) : date('Y-m-d', strtotime('-13 days'));
            $fimD = $hojeD;
            break;
        default:        $iniD = date('Y-m-d', strtotime('-' . ((int) $per - 1) . ' days')); $fimD = $hojeD;
    }
    $de = $iniD; $ate = $fimD;
}
$ini = $iniD . ' 00:00:00';
$fim = date('Y-m-d 00:00:00', strtotime($fimD . ' +1 day')); // exclusivo
$dias = (int) round((strtotime($fimD) - strtotime($iniD)) / 86400) + 1;

// comparação: mês atual × mesmo trecho do mês passado; demais × o mesmo número de dias logo antes
$comparar = $per !== 'tudo';
if ($per === 'mes') {
    $pIni = date('Y-m-01 00:00:00', strtotime('first day of last month'));
    $pFim = min(date('Y-m-d 00:00:00', strtotime($pIni . ' +' . $dias . ' days')), $ini);
    $textoComparacao = 'comparado ao mesmo período do mês passado';
} elseif ($per === 'mes_ant') {
    $pIni = date('Y-m-01 00:00:00', strtotime($iniD . ' -1 month'));
    $pFim = $ini;
    $textoComparacao = 'comparado ao mês anterior';
} else {
    $pIni = date('Y-m-d 00:00:00', strtotime($iniD . ' -' . $dias . ' days'));
    $pFim = $ini;
    $textoComparacao = 'comparado aos ' . $dias . ' dias anteriores';
}
$meses = [1 => 'janeiro', 'fevereiro', 'março', 'abril', 'maio', 'junho', 'julho', 'agosto', 'setembro', 'outubro', 'novembro', 'dezembro'];
$nomeMes = function (string $d) use ($meses) { return $meses[(int) date('n', strtotime($d))] . ' de ' . date('Y', strtotime($d)); };
$textoPeriodo = [
    'mes' => $nomeMes($iniD) . ' (até hoje)', 'mes_ant' => $nomeMes($iniD), 'hoje' => 'hoje', 'tudo' => 'todo o período',
    'custom' => date('d/m/Y', strtotime($iniD)) . ' a ' . date('d/m/Y', strtotime($fimD)),
][$per] ?? 'últimos ' . $dias . ' dias';

// leads que chegaram no intervalo (e o andamento atual deles) + vendas FECHADAS no intervalo
$resumo = function (string $a, string $b) use ($pdo) {
    $st = $pdo->prepare("SELECT COUNT(*) leads, COALESCE(SUM(status='novo'),0) novo, COALESCE(SUM(status IN ('atendimento','proposta')),0) atend,
        COALESCE(SUM(status='vendido'),0) vendC, COALESCE(SUM(status='perdido'),0) perd FROM leads WHERE criado_em >= ? AND criado_em < ?");
    $st->execute([$a, $b]);
    $r = array_map('intval', $st->fetch());
    $st = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE status = 'vendido' AND vendido_em >= ? AND vendido_em < ?");
    $st->execute([$a, $b]);
    $r['vend'] = (int) $st->fetchColumn();
    return $r;
};
$k = $resumo($ini, $fim);
$kp = $comparar ? $resumo($pIni, $pFim) : null;
$conv = $k['leads'] ? $k['vend'] / $k['leads'] * 100 : 0;
$convAnt = ($kp && $kp['leads']) ? $kp['vend'] / $kp['leads'] * 100 : null;

// pipeline em aberto (independe do período)
$aberto = array_map('intval', $pdo->query("SELECT COALESCE(SUM(status='novo'),0) novo, COALESCE(SUM(status IN ('atendimento','proposta')),0) atend,
    COALESCE(SUM(status='novo' AND criado_em < NOW() - INTERVAL 2 HOUR),0) parados FROM leads")->fetch());

// ---------- séries por dia (leads pela chegada, vendas pelo fechamento) ----------
$serie_dias = function (string $a, string $b) use ($pdo): array {
    $s = [];
    for ($t = strtotime($a); $t <= strtotime($b); $t += 86400) $s[date('Y-m-d', $t)] = ['l' => 0, 'v' => 0];
    $ate = date('Y-m-d 00:00:00', strtotime($b . ' +1 day'));
    $st = $pdo->prepare('SELECT DATE(criado_em) d, COUNT(*) n FROM leads WHERE criado_em >= ? AND criado_em < ? GROUP BY DATE(criado_em)');
    $st->execute([$a . ' 00:00:00', $ate]);
    foreach ($st->fetchAll() as $r) if (isset($s[$r['d']])) $s[$r['d']]['l'] = (int) $r['n'];
    $st = $pdo->prepare("SELECT DATE(vendido_em) d, COUNT(*) n FROM leads WHERE status = 'vendido' AND vendido_em >= ? AND vendido_em < ? GROUP BY DATE(vendido_em)");
    $st->execute([$a . ' 00:00:00', $ate]);
    foreach ($st->fetchAll() as $r) if (isset($s[$r['d']])) $s[$r['d']]['v'] = (int) $r['n'];
    return $s;
};
// gráfico: o período escolhido (no "hoje", mostra os últimos 14 dias para ter contexto)
$serie = $per === 'hoje' ? $serie_dias(date('Y-m-d', strtotime('-13 days')), $hojeD) : $serie_dias($iniD, min($fimD, $hojeD) >= $iniD ? min($fimD, $hojeD) : $fimD);
// mini gráficos dos cartões: sempre os últimos 14 dias do período
$spark = count($serie) >= 2 ? array_slice($serie, -14, 14, true) : $serie_dias(date('Y-m-d', strtotime('-13 days')), $hojeD);
// ---------- ranking de vendas ----------
$st = $pdo->prepare("SELECT vendedor, COUNT(*) leads, COALESCE(SUM(status='vendido'),0) vend, COALESCE(SUM(status='perdido'),0) perd,
    COALESCE(SUM(status IN ('novo','atendimento','proposta')),0) abertos FROM leads WHERE criado_em >= ? AND criado_em < ? GROUP BY vendedor");
$st->execute([$ini, $fim]);
$porV = [];
foreach ($st->fetchAll() as $r) { $porV[$r['vendedor']] = array_map('intval', $r); $porV[$r['vendedor']]['vend'] = 0; }
$st = $pdo->prepare("SELECT vendedor, COUNT(*) n FROM leads WHERE status = 'vendido' AND vendido_em >= ? AND vendido_em < ? GROUP BY vendedor");
$st->execute([$ini, $fim]);
foreach ($st->fetchAll() as $r) {
    $porV[$r['vendedor']] = ($porV[$r['vendedor']] ?? ['leads' => 0, 'perd' => 0, 'abertos' => 0]);
    $porV[$r['vendedor']]['vend'] = (int) $r['n'];
}
// follow-ups atrasados por vendedor (agora, independente do período)
$atrasadosV = [];
foreach ($pdo->query("SELECT vendedor, COUNT(*) n FROM leads WHERE status IN ('novo','atendimento','proposta') AND proximo_followup IS NOT NULL AND " . FU_ATRASADO . ' GROUP BY vendedor') as $r) {
    $atrasadosV[$r['vendedor']] = (int) $r['n'];
}
$ranking = [];
foreach (VENDEDORES as $slug => [$nome]) {
    $r = $porV[$slug] ?? ['leads' => 0, 'vend' => 0, 'perd' => 0, 'abertos' => 0];
    $ranking[] = ['slug' => $slug, 'nome' => $nome, 'leads' => $r['leads'], 'vend' => $r['vend'], 'perd' => $r['perd'],
        'abertos' => $r['abertos'], 'conv' => $r['leads'] ? $r['vend'] / $r['leads'] * 100 : 0];
}
usort($ranking, function ($a, $b) {
    return [$b['vend'], $b['conv'], $b['leads']] <=> [$a['vend'], $a['conv'], $a['leads']];
});
$maxVend = max(1, $ranking[0]['vend']);

// ---------- produtos e origens ----------
$st = $pdo->prepare("SELECT produto, COUNT(*) n, COALESCE(SUM(status='vendido'),0) v FROM leads WHERE criado_em >= ? AND criado_em < ? GROUP BY produto ORDER BY n DESC, v DESC");
$st->execute([$ini, $fim]);
$produtos = $st->fetchAll();
$maxProd = max(1, $produtos ? (int) $produtos[0]['n'] : 1);

$st = $pdo->prepare('SELECT origem, referrer FROM leads WHERE criado_em >= ? AND criado_em < ?');
$st->execute([$ini, $fim]);
$orig = ['Google' => 0, 'Outros' => 0, 'Facebook' => 0, 'Direto' => 0, 'Instagram' => 0, 'Pessoal' => 0]; // ordem fixa = cores validadas (inclusive Pessoal↔Google na volta do anel)
foreach ($st->fetchAll() as $r) {
    $o = origem_legivel($r);
    if ($o === 'Direct') $o = 'Instagram'; // mensagem no Direct = Instagram
    $orig[isset($orig[$o]) ? $o : 'Outros']++;
}

// ---------- helpers de exibição ----------
function variacao(?float $atual, ?float $antes, string $sufixo = '%', bool $pontos = false): string
{
    if ($antes === null) return '';
    if ($pontos) {
        $d = $atual - $antes;
        if (abs($d) < 0.05) return '<span class="delta eq">= igual ao período anterior</span>';
        return '<span class="delta ' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '▲' : '▼') . ' ' . number_format(abs($d), 1, ',', '.') . ' p.p. vs anterior</span>';
    }
    if ($antes == 0) return $atual > 0 ? '<span class="delta up">▲ novo no período</span>' : '<span class="delta eq">sem variação</span>';
    $d = ($atual - $antes) / $antes * 100;
    if (abs($d) < 0.5) return '<span class="delta eq">= igual ao período anterior</span>';
    return '<span class="delta ' . ($d > 0 ? 'up' : 'down') . '">' . ($d > 0 ? '▲' : '▼') . ' ' . number_format(abs($d), 0, ',', '.') . $sufixo . ' vs anterior</span>';
}
$fmtPct = function (float $v): string { return number_format($v, $v >= 10 || $v == 0 ? 0 : 1, ',', '.') . '%'; };
$medalhas = ['🥇', '🥈', '🥉'];
$funil = [
    ['Recebidos', $k['leads']],
    ['Atendidos', $k['leads'] - $k['novo']],
    ['Vendidos', $k['vendC']],
];

cabecalho('Dashboard', $u, 'dashboard');
?>
<div class="page-head">
  <div><h1>Dashboard</h1><p>Visão geral de <b><?= e($textoPeriodo) ?></b><?= $comparar ? ', ' . e($textoComparacao) : '' ?>. <a href="/clientes/crmteste/painel/usuarios.php#tv">📺 Placar da TV</a></p></div>
</div>
<div class="dash-filtros">
  <div class="seg">
    <?php foreach (PERIODOS_DASH as $pk => $pv): ?><a href="?periodo=<?= e($pk) ?>" class="<?= $per === (string) $pk ? 'on' : '' ?>"><?= e($pv) ?></a><?php endforeach; ?>
  </div>
  <form class="periodo-custom<?= $per === 'custom' ? ' on' : '' ?>" method="get" action="/clientes/crmteste/painel/dashboard.php">
    <label>De <input type="date" name="de" value="<?= e($de) ?>" max="<?= e($hojeD) ?>" required></label>
    <label>Até <input type="date" name="ate" value="<?= e($ate) ?>" required></label>
    <button class="btn sm" type="submit">Aplicar</button>
  </form>
</div>

<?php if ($aberto['parados']): ?>
<a class="alert-new" href="/clientes/crmteste/painel/?periodo=tudo" style="color:var(--text)">
  <span class="dot"></span>
  <div><b><?= $aberto['parados'] ?></b> <?= $aberto['parados'] === 1 ? 'lead novo está' : 'leads novos estão' ?> há mais de 2 horas sem atendimento</div>
  <span class="btn sm" style="margin-left:auto">Ver no andamento →</span>
</a>
<?php endif; ?>

<!-- cartões principais -->
<section class="kpis2">
  <article class="card kpi2 hl">
    <small>Leads recebidos</small>
    <b><?= $k['leads'] ?></b>
    <?= variacao((float) $k['leads'], $kp ? (float) $kp['leads'] : null) ?>
    <svg class="spark" data-spark="<?= e(json_encode(array_column(array_values($spark), 'l'))) ?>" data-cor="#d95926" aria-hidden="true"></svg>
  </article>
  <article class="card kpi2">
    <small>Vendas</small>
    <b class="good"><?= $k['vend'] ?></b>
    <?= variacao((float) $k['vend'], $kp ? (float) $kp['vend'] : null) ?>
    <svg class="spark" data-spark="<?= e(json_encode(array_column(array_values($spark), 'v'))) ?>" data-cor="#199e70" aria-hidden="true"></svg>
  </article>
  <article class="card kpi2">
    <small>Conversão</small>
    <b><?= $fmtPct($conv) ?></b>
    <?= variacao($conv, $convAnt, '%', true) ?>
    <div class="ring" style="--p:<?= round(min(100, $conv), 1) ?>"><span>de cada 10 leads, <?= number_format($conv / 10, 1, ',', '.') ?> compram</span></div>
  </article>
  <article class="card kpi2">
    <small>Aguardando contato</small>
    <b class="<?= $aberto['novo'] ? 'warn' : '' ?>"><?= $aberto['novo'] ?></b>
    <span class="delta eq"><?= $aberto['parados'] ? $aberto['parados'] . ' há mais de 2 h' : 'nenhum parado' ?></span>
    <a class="kpi-link" href="/clientes/crmteste/painel/">Abrir andamento →</a>
  </article>
  <article class="card kpi2">
    <small>Em negociação</small>
    <b><?= $aberto['atend'] ?></b>
    <span class="delta eq">negociações abertas</span>
    <a class="kpi-link" href="/clientes/crmteste/painel/">Abrir andamento →</a>
  </article>
</section>

<!-- ranking + gráfico -->
<section class="dash-grid">
  <article class="card rank-card">
    <div class="card-head"><h2>🏆 Ranking de vendas</h2><span class="dim small">por vendas · desempate: conversão</span></div>
    <div class="podium">
      <?php foreach ([1, 0, 2] as $pos): $r = $ranking[$pos] ?? null; if (!$r) continue; ?>
      <div class="pod pod-<?= $pos + 1 ?>">
        <div class="pod-photo"><img src="<?= e(foto_vendedor($r['slug'])) ?>" alt=""><span class="medal"><?= $medalhas[$pos] ?></span></div>
        <strong><?= e(explode(' ', $r['nome'])[0]) ?></strong>
        <div class="pod-vend"><b><?= $r['vend'] ?></b> <?= $r['vend'] === 1 ? 'venda' : 'vendas' ?></div>
        <div class="pod-sub"><?= $fmtPct($r['conv']) ?> conv. · <?= $r['leads'] ?> leads</div>
        <div class="pod-base"><span><?= $pos + 1 ?>º</span></div>
      </div>
      <?php endforeach; ?>
    </div>
    <ol class="rank-list">
      <?php foreach ($ranking as $i => $r): ?>
      <li>
        <span class="pos"><?= $i + 1 ?>º</span>
        <img src="<?= e(foto_vendedor($r['slug'])) ?>" alt="">
        <div class="rk-main">
          <div class="rk-top"><b><?= e($r['nome']) ?></b><span><b><?= $r['vend'] ?></b> <?= $r['vend'] === 1 ? 'venda' : 'vendas' ?></span></div>
          <div class="rk-bar" title="<?= $r['vend'] ?> vendas"><i style="width:<?= $r['vend'] ? max(3, round($r['vend'] / $maxVend * 100)) : 0 ?>%"></i></div>
          <div class="rk-sub"><?= $r['leads'] ?> leads · <?= $fmtPct($r['conv']) ?> conversão · <?= $r['abertos'] ?> em aberto<?php $at = $atrasadosV[$r['slug']] ?? 0; if ($at): ?> · <span style="color:#ff9db0;font-weight:700">⏰ <?= $at ?> follow-up<?= $at > 1 ? 's' : '' ?> atrasado<?= $at > 1 ? 's' : '' ?></span><?php endif; ?></div>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
  </article>

  <article class="card">
    <div class="card-head">
      <h2>Leads e vendas por dia</h2>
      <div class="legend">
        <span><i style="background:#d95926"></i>Leads <b><?= array_sum(array_column($serie, 'l')) ?></b></span>
        <span><i style="background:#199e70"></i>Vendas <b><?= array_sum(array_column($serie, 'v')) ?></b></span>
      </div>
    </div>
    <div class="viz-line" data-serie="<?= e(json_encode(array_map(function ($d, $x) { return [$d, $x['l'], $x['v']]; }, array_keys($serie), $serie))) ?>" role="img" aria-label="Gráfico de leads e vendas por dia"></div>
    <details class="table-view"><summary>Ver dados em tabela</summary>
      <table><thead><tr><th>Dia</th><th>Leads</th><th>Vendas</th></tr></thead><tbody>
      <?php foreach (array_reverse($serie, true) as $d => $x): if (!$x['l'] && !$x['v']) continue; ?><tr><td><?= e(date('d/m', strtotime($d))) ?></td><td><?= $x['l'] ?></td><td><?= $x['v'] ?></td></tr><?php endforeach; ?>
      </tbody></table>
    </details>
  </article>
</section>

<!-- funil, origem, produtos -->
<section class="dash-grid3">
  <article class="card">
    <div class="card-head"><h2>Funil de vendas</h2></div>
    <div class="funnel">
      <?php $base = max(1, $funil[0][1]); $cores = ['#f5b190', '#e2703d', '#b8441a']; // sequencial: um tom, do claro ao escuro
      foreach ($funil as $i => [$rot, $n]): $w = $funil[0][1] ? max(8, round($n / $base * 100)) : 8; ?>
      <div class="f-row">
        <div class="f-bar" style="width:<?= $w ?>%;background:<?= $cores[$i] ?>"></div>
        <div class="f-lbl"><b><?= $n ?></b> <?= e($rot) ?><?php if ($i): ?> <span class="dim">· <?= $fmtPct($funil[0][1] ? $n / $funil[0][1] * 100 : 0) ?></span><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <p class="dim small" style="margin-top:12px"><?= $k['perd'] ?> <?= $k['perd'] === 1 ? 'lead perdido' : 'leads perdidos' ?> e <?= $k['novo'] + $k['atend'] ?> ainda em aberto neste período.</p>
  </article>

  <article class="card">
    <div class="card-head"><h2>Origem dos leads</h2></div>
    <?php $totOrig = array_sum($orig); $coresOrig = ['Google' => '#3987e5', 'Outros' => '#d95926', 'Facebook' => '#199e70', 'Direto' => '#c98500', 'Instagram' => '#d55181', 'Pessoal' => '#008300']; ?>
    <div class="donut-wrap">
      <div class="viz-donut" data-seg="<?= e(json_encode(array_map(function ($nome, $n) use ($coresOrig) { return [$nome, $n, $coresOrig[$nome]]; }, array_keys($orig), $orig))) ?>" data-total="<?= $totOrig ?>" role="img" aria-label="Origem dos leads"></div>
      <ul class="donut-legend">
        <?php foreach ($orig as $nome => $n): if (!$n) continue; ?>
          <li><i style="background:<?= $coresOrig[$nome] ?>"></i><span><?= e($nome) ?></span><b><?= $n ?></b><em><?= $fmtPct($totOrig ? $n / $totOrig * 100 : 0) ?></em></li>
        <?php endforeach; ?>
        <?php if (!$totOrig): ?><li class="dim">Sem leads no período</li><?php endif; ?>
      </ul>
    </div>
  </article>

  <article class="card">
    <div class="card-head"><h2>Produtos mais procurados</h2></div>
    <?php if (!$produtos): ?><p class="dim small">Sem leads no período.</p><?php endif; ?>
    <div class="prod-bars">
      <?php foreach ($produtos as $p): $n = (int) $p['n']; $v = (int) $p['v']; ?>
      <div class="pb" title="<?= e($p['produto'] . ': ' . $n . ' leads, ' . $v . ' vendas') ?>">
        <div class="pb-top"><span><?= e($p['produto']) ?></span><span><b><?= $n ?></b> leads<?= $v ? ' · <b class="good">' . $v . '</b> ' . ($v === 1 ? 'venda' : 'vendas') : '' ?></span></div>
        <div class="pb-bar"><i style="width:<?= max(3, round($n / $maxProd * 100)) ?>%"></i><?php if ($v): ?><i class="v" style="width:<?= max(2, round($v / $maxProd * 100)) ?>%"></i><?php endif; ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php if ($produtos): ?><div class="legend" style="margin-top:12px"><span><i style="background:#d95926"></i>Leads</span><span><i style="background:#199e70"></i>Vendas</span></div><?php endif; ?>
  </article>
</section>
<div class="viz-tip" id="vizTip" role="tooltip" hidden></div>
<?php rodape(['/clientes/crmteste/painel/dashboard.js?v=1']);
