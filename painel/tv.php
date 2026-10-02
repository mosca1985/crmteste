<?php
/**
 * Placar de vendas para a TV do escritório.
 *   /painel/tv.php?k=<chave>          página (sem login: a chave fica salva na TV)
 *   /painel/tv.php?k=<chave>&dados=1  números em JSON (a página atualiza sozinha)
 * O gestor logado também abre sem chave. Só mostra números agregados, nomes dos vendedores e produtos —
 * nenhum dado de cliente (nome, telefone) aparece na TV.
 */
declare(strict_types=1);
require __DIR__ . '/_core.php';

const META_PADRAO = 100;

$u = usuario();
$chave = (string) ($_GET['k'] ?? '');
$certa = '';
try {
    $certa = (string) ajuste('tv_chave', '');
} catch (Throwable $e) {
    // tabela de ajustes ainda não criada: só o gestor logado entra (e o login cria a tabela)
}
$liberado = ($u && eh_gestor($u)) || ($certa !== '' && $chave !== '' && hash_equals($certa, $chave));
if (!$liberado) {
    if (isset($_GET['dados'])) json_out(['error' => 'chave'], 403);
    http_response_code(403);
    echo '<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Placar</title>'
        . '<body style="background:#0b0a0d;color:#cfc8d8;font:18px system-ui;display:grid;place-items:center;height:100vh;margin:0;text-align:center">'
        . '<p>Link da TV inválido ou desatualizado.<br><small style="opacity:.6">O gestor gera o link certo em Painel → Usuários → TV do escritório.</small></p>';
    exit;
}

/** Pessoas do ranking: os cartões do mesmo vendedor (ex.: os dois números do Ângelo) somam juntos. */
function tv_pessoas(): array
{
    $p = [];
    foreach (VENDEDORES as $slug => [$nome]) {
        $base = slugs_do_vendedor($slug)[0];
        if (!isset($p[$base])) {
            $p[$base] = ['slug' => $base, 'nome' => preg_replace('/\s+\d+$/', '', VENDEDORES[$base][0] ?? $nome), 'foto' => foto_vendedor($base),
                'vendas' => 0, 'leads' => 0, 'hoje' => 0];
        }
    }
    return $p;
}

function tv_dados(): array
{
    $pdo = db();
    $agora = time();
    $ini = date('Y-m-01 00:00:00', $agora);
    $hoje = date('Y-m-d 00:00:00', $agora);
    $diasMes = (int) date('t', $agora);
    $dia = (int) date('j', $agora);
    $meta = max(1, (int) ajuste('meta_vendas_mes', (string) (empresa('meta_vendas_padrao') ?: META_PADRAO)));

    $pessoas = tv_pessoas();
    $dono = function (string $slug) { return slugs_do_vendedor($slug)[0]; };

    // vendas do mês por vendedor e por dia (pela data em que a venda foi fechada)
    $st = $pdo->prepare("SELECT vendedor, DATE(vendido_em) d, COUNT(*) n FROM leads WHERE status = 'vendido' AND vendido_em >= ? GROUP BY vendedor, DATE(vendido_em)");
    $st->execute([$ini]);
    $porDia = array_fill(1, $diasMes, 0);
    $vendasMes = 0;
    $vendasHoje = 0;
    foreach ($st->fetchAll() as $r) {
        $n = (int) $r['n'];
        $d = (int) substr($r['d'], 8, 2);
        $porDia[$d] += $n;
        $vendasMes += $n;
        $b = $dono($r['vendedor']);
        if (isset($pessoas[$b])) $pessoas[$b]['vendas'] += $n;
        if ($r['d'] === substr($hoje, 0, 10)) {
            $vendasHoje += $n;
            if (isset($pessoas[$b])) $pessoas[$b]['hoje'] += $n;
        }
    }

    // leads que chegaram no mês / hoje
    $st = $pdo->prepare('SELECT vendedor, COUNT(*) n, COALESCE(SUM(criado_em >= ?),0) h FROM leads WHERE criado_em >= ? GROUP BY vendedor');
    $st->execute([$hoje, $ini]);
    $leadsMes = 0;
    $leadsHoje = 0;
    foreach ($st->fetchAll() as $r) {
        $leadsMes += (int) $r['n'];
        $leadsHoje += (int) $r['h'];
        $b = $dono($r['vendedor']);
        if (isset($pessoas[$b])) $pessoas[$b]['leads'] += (int) $r['n'];
    }

    $ranking = array_values($pessoas);
    foreach ($ranking as &$p) $p['conv'] = $p['leads'] ? round($p['vendas'] / $p['leads'] * 100) : 0;
    unset($p);
    usort($ranking, function ($a, $b) {
        return [$b['vendas'], $b['hoje'], $b['conv']] <=> [$a['vendas'], $a['hoje'], $a['conv']];
    });

    // últimas vendas (sem dados do cliente)
    $ultimas = [];
    $st = $pdo->query("SELECT id, vendedor, produto, vendido_em FROM leads WHERE status = 'vendido' AND vendido_em IS NOT NULL ORDER BY vendido_em DESC, id DESC LIMIT 6");
    foreach ($st->fetchAll() as $r) {
        $b = $dono($r['vendedor']);
        $ultimas[] = ['id' => (int) $r['id'], 'vendedor' => $pessoas[$b]['nome'] ?? $r['vendedor'], 'foto' => $pessoas[$b]['foto'] ?? foto_vendedor(''),
            'produto' => $r['produto'], 'em' => $r['vendido_em'], 'quando' => ha_quanto($r['vendido_em'])];
    }

    $acumulado = [];
    $soma = 0;
    for ($d = 1; $d <= $dia; $d++) { $soma += $porDia[$d]; $acumulado[] = $soma; }
    $restantes = $diasMes - $dia + 1; // conta hoje
    $faltam = max(0, $meta - $vendasMes);
    $meses = [1 => 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];

    return [
        'ok' => true,
        'mes' => $meses[(int) date('n', $agora)] . ' ' . date('Y', $agora),
        'dia' => $dia, 'diasMes' => $diasMes,
        'meta' => $meta, 'vendasMes' => $vendasMes, 'faltam' => $faltam,
        'porDiaNecessario' => $faltam ? (int) ceil($faltam / $restantes) : 0,
        'projecao' => $dia ? (int) round($vendasMes / $dia * $diasMes) : 0,
        'vendasHoje' => $vendasHoje, 'leadsHoje' => $leadsHoje, 'leadsMes' => $leadsMes,
        'conversao' => $leadsMes ? round($vendasMes / $leadsMes * 100) : 0,
        'acumulado' => $acumulado,
        'ranking' => $ranking,
        'ultimas' => $ultimas,
        'atualizado' => date('H:i'),
        'empresa' => (string) empresa('nome'),
    ];
}

if (isset($_GET['dados'])) {
    try {
        json_out(tv_dados());
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S22') throw $e;
        migrar(); // banco ainda sem a coluna vendido_em
        json_out(tv_dados());
    }
}

$k = $liberado && $chave !== '' ? $chave : '';
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Placar de vendas · <?= e(empresa('nome')) ?></title>
<link rel="icon" href="/clientes/crmteste/favicon.ico">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@500;600;700;800&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/clientes/crmteste/painel/tv.css?v=1">
<style><?= tema_css() ?></style>
</head>
<body data-k="<?= e($k) ?>">
<div class="tv" id="tv">
  <header class="tv-top">
    <?= logo_html('tv-logo') ?>
    <div class="tv-title"><span>Placar de vendas</span><b id="mes">—</b></div>
    <div class="tv-clock"><b id="hora">--:--</b><small id="data"></small></div>
  </header>

  <section class="panel meta" aria-label="Meta do mês">
    <h2>Meta do mês</h2>
    <div class="thermo">
      <div class="thermo-scale" id="escala"></div>
      <div class="thermo-tube"><div class="thermo-fill" id="fill"><span class="thermo-shine"></span></div></div>
      <div class="thermo-bulb"><span id="pct">0%</span></div>
    </div>
    <div class="meta-num"><b id="vendasMes">0</b><span>de <i id="meta">250</i> vendas</span></div>
    <p class="meta-sub" id="metaSub">&nbsp;</p>
  </section>

  <section class="panel rank" aria-label="Ranking de vendas do mês">
    <h2>Ranking do mês <small>vendas fechadas</small></h2>
    <ol class="rank-list" id="ranking"></ol>
  </section>

  <section class="panel kpis" aria-label="Números de hoje e do mês">
    <div class="kpi"><small>Vendas hoje</small><b id="vendasHoje">0</b></div>
    <div class="kpi"><small>Leads hoje</small><b id="leadsHoje">0</b></div>
    <div class="kpi"><small>Leads no mês</small><b id="leadsMes">0</b></div>
    <div class="kpi"><small>Conversão do mês</small><b id="conversao">0%</b></div>
  </section>

  <section class="panel ritmo" aria-label="Ritmo de vendas do mês">
    <h2>Ritmo do mês <small>vendas acumuladas × ritmo para bater a meta</small></h2>
    <div class="chart" id="chart"></div>
  </section>

  <section class="panel feed" aria-label="Últimas vendas">
    <h2>Últimas vendas</h2>
    <ul id="ultimas"></ul>
  </section>

  <footer class="tv-foot"><span id="status">Carregando…</span><button type="button" id="cheia">⛶ Tela cheia</button></footer>
</div>

<div class="party" id="party" hidden>
  <canvas id="confete"></canvas>
  <div class="party-card">
    <span class="party-tag" id="partyTag">Nova venda!</span>
    <img id="partyFoto" src="" alt="">
    <b id="partyNome"></b>
    <small id="partyProd"></small>
  </div>
</div>
<script src="/clientes/crmteste/painel/tv.js?v=1"></script>
</body>
</html>
