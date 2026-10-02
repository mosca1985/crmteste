<?php
/** Política de privacidade (dados da empresa vêm de config/empresa.php). */
declare(strict_types=1);
require __DIR__ . '/clientes/crmteste/api/bootstrap.php';
$E = empresa();
$h = function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
$end = $E['endereco'];
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Privacidade | <?= $h($E['nome']) ?></title>
<meta name="description" content="Como a <?= $h($E['nome']) ?> usa os dados informados no site.">
<meta name="robots" content="noindex,follow">
<link rel="icon" href="/clientes/crmteste/favicon.ico">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
<style>
  <?= tema_css() ?>
  body{margin:0;background:var(--bg);color:#f4f1f7;font:400 16px/1.7 Manrope,system-ui,sans-serif}
  main{width:min(760px,100% - 32px);margin:0 auto;padding:40px 0 80px}
  a{color:var(--clara)}
  .logo img{height:40px}.logo b{font:800 24px Sora;letter-spacing:-.03em;color:#fff}
  h1{font:700 32px/1.2 Sora;letter-spacing:-.02em;margin:32px 0 8px}
  h2{font:600 19px/1.3 Sora;margin:32px 0 8px}
  p,li{color:#d8d0df}
</style>
</head>
<body>
<main>
  <a class="logo" href="/"><?= $E['logo'] ? '<img src="' . $h($E['logo']) . '" alt="' . $h($E['nome']) . '">' : '<b>' . $h($E['nome']) . '</b>' ?></a>
  <h1>Privacidade</h1>

  <h2>Quais dados coletamos</h2>
  <p>Quando você escolhe um vendedor no site, pedimos <b>nome</b>, <b>número de WhatsApp</b> e o <b>produto de interesse</b>. Também registramos de onde você chegou ao site (por exemplo, Instagram ou Google) e a data do contato.</p>

  <h2>Para que usamos</h2>
  <p>Somente para o atendimento comercial: o vendedor escolhido entra em contato pelo WhatsApp para enviar opções, preços e condições, e para acompanhar o seu atendimento. Não vendemos nem compartilhamos seus dados com terceiros.</p>

  <?php if ($E['pixel_meta'] || $E['google_tag']): ?>
  <h2>Cookies e medição</h2>
  <p>O site utiliza ferramentas de medição de anúncios<?= $E['pixel_meta'] ? ' da Meta (Facebook/Instagram)' : '' ?><?= $E['pixel_meta'] && $E['google_tag'] ? ' e' : '' ?><?= $E['google_tag'] ? ' do Google' : '' ?>, que registram visitas e cliques de forma estatística para medir o resultado das nossas campanhas.</p>
  <?php endif; ?>

  <h2>Seus direitos</h2>
  <p>Você pode pedir a qualquer momento para consultar, corrigir ou excluir seus dados, conforme a Lei Geral de Proteção de Dados (LGPD). Basta pedir ao vendedor que te atendeu pelo WhatsApp ou falar com a empresa.</p>

  <h2>Quem somos</h2>
  <p><?= $h($E['nome']) ?> · <?= $end['nome_local'] ? $h($end['nome_local']) . ', ' : '' ?><?= $h($end['rua']) ?>, <?= $h($end['bairro']) ?>, <?= $h($E['cidade']) ?> – <?= $h($E['uf']) ?>.</p>

  <p style="margin-top:40px"><a href="/">← Voltar para o site</a></p>
</main>
</body>
</html>
