<?php
/**
 * Landing page. Todo o conteúdo vem de config/empresa.php — não precisa editar este arquivo
 * para um cliente novo (só se quiser mudar o layout).
 */
declare(strict_types=1);
require __DIR__ . '/clientes/crmteste/api/bootstrap.php';

$E = empresa();
$h = function ($v): string { return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8'); };
// textos que aceitam <em> e <span class="grad"> (vindos da configuração, não do visitante)
$rico = function ($v): string { return strip_tags((string) $v, '<em><span><b><br>'); };
$url = 'https://' . $E['dominio'] . '/clientes/crmteste/';
$abs = function (string $p) use ($E): string { return $p === '' ? '' : (preg_match('#^https?://#', $p) ? $p : 'https://' . $E['dominio'] . $p); };
$fmtTel = function (string $t): string {
    $d = preg_replace('/\D+/', '', $t);
    if (strlen($d) >= 12 && strpos($d, '55') === 0) $d = substr($d, 2);
    return strlen($d) >= 10 ? '(' . substr($d, 0, 2) . ') ' . substr($d, 2, -4) . '-' . substr($d, -4) : $t;
};
$end = $E['endereco'];
$enderecoLinha = trim($end['rua'] . ', ' . $end['bairro'] . ', ' . $E['cidade'] . ' – ' . $E['uf'], ', ');
$avaliacoes = $E['avaliacoes'];
$fotosHero = array_values(array_filter($E['hero']['imagens'] ?? [], function ($i) { return !empty($i['src']); }));
$waMsg = function (string $apelido) use ($E): string { return rawurlencode(strtr($E['mensagem_whatsapp'], ['{vendedor}' => $apelido])); };
$icones = [
    'pagamento' => '<rect x="2" y="5" width="20" height="14" rx="3"/><path d="M2 10h20M6 15h4"/>',
    'relogio'   => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    'troca'     => '<path d="M17 1l4 4-4 4"/><path d="M3 11V9a4 4 0 0 1 4-4h14M7 23l-4-4 4-4"/><path d="M21 13v2a4 4 0 0 1-4 4H3"/>',
    'local'     => '<path d="M12 22s7-6.2 7-12a7 7 0 1 0-14 0c0 5.8 7 12 7 12z"/><circle cx="12" cy="10" r="2.5"/>',
    'entrega'   => '<path d="M1 3h15v13H1zM16 8h4l3 3v5h-7"/><circle cx="5.5" cy="18.5" r="2.5"/><circle cx="18.5" cy="18.5" r="2.5"/>',
    'escudo'    => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/><path d="m9 12 2 2 4-4"/>',
    'estrela'   => '<path d="m12 3 2.8 5.7 6.2.9-4.5 4.4 1 6.2-5.5-2.9-5.5 2.9 1-6.2L3 9.6l6.2-.9z"/>',
    'conversa'  => '<path d="M21 12a8 8 0 0 1-11.6 7.1L3 21l1.9-6.4A8 8 0 1 1 21 12z"/>',
];
$seta = '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$wa = '<svg viewBox="0 0 24 24" fill="currentColor"><use href="#i-wa"/></svg>';
$logo = function (string $classe = '') use ($E, $h): string {
    return $E['logo'] !== '' ? '<img class="' . $h($classe) . '" src="' . $h($E['logo']) . '" alt="' . $h($E['nome']) . '">' : '<b class="logo-txt">' . $h($E['nome']) . '</b>';
};

// dados estruturados para o Google (empresa local + perguntas frequentes)
$schema = ['@context' => 'https://schema.org', '@graph' => [
    array_filter([
        '@type' => $E['seo']['tipo'] ?: 'LocalBusiness', '@id' => $url . '#empresa', 'name' => $E['nome'],
        'description' => $E['seo']['descricao'], 'url' => $url, 'logo' => $abs((string) $E['logo']),
        'image' => $abs((string) $E['seo']['imagem']), 'telephone' => '+' . preg_replace('/\D+/', '', $E['telefone']),
        'address' => ['@type' => 'PostalAddress', 'streetAddress' => trim($end['nome_local'] . ' ' . $end['rua']), 'addressLocality' => $E['cidade'],
            'addressRegion' => $E['uf'], 'postalCode' => $end['cep'], 'addressCountry' => 'BR'],
        'sameAs' => $E['instagram'] ? ['https://www.instagram.com/' . $E['instagram'] . '/'] : null,
    ]),
    ['@type' => 'WebSite', '@id' => $url . '#site', 'url' => $url, 'name' => $E['nome'], 'inLanguage' => 'pt-BR'],
    ['@type' => 'FAQPage', '@id' => $url . '#faq', 'mainEntity' => array_map(function ($q) {
        return ['@type' => 'Question', 'name' => $q[0], 'acceptedAnswer' => ['@type' => 'Answer', 'text' => $q[1]]];
    }, $E['faq'])],
]];
header('X-Content-Type-Options: nosniff');
?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $h($E['seo']['titulo']) ?></title>
<meta name="description" content="<?= $h($E['seo']['descricao']) ?>">
<link rel="canonical" href="<?= $h($url) ?>">
<meta name="robots" content="index,follow,max-image-preview:large">
<meta name="geo.region" content="BR-<?= $h($E['uf']) ?>">
<meta name="geo.placename" content="<?= $h($E['cidade']) ?>">
<meta property="og:type" content="website">
<meta property="og:locale" content="pt_BR">
<meta property="og:site_name" content="<?= $h($E['nome']) ?>">
<meta property="og:url" content="<?= $h($url) ?>">
<meta property="og:title" content="<?= $h($E['seo']['titulo']) ?>">
<meta property="og:description" content="<?= $h($E['seo']['descricao']) ?>">
<?php if ($E['seo']['imagem']): ?><meta property="og:image" content="<?= $h($abs($E['seo']['imagem'])) ?>">
<meta name="twitter:card" content="summary_large_image">
<?php endif; ?>
<meta name="theme-color" content="<?= $h($E['cores']['fundo']) ?>">
<link rel="icon" href="/clientes/crmteste/favicon.ico" sizes="any">
<link rel="icon" type="image/png" sizes="32x32" href="/clientes/crmteste/assets/favicon-32.png">
<link rel="icon" type="image/png" sizes="192x192" href="/clientes/crmteste/assets/icon-192.png">
<link rel="apple-touch-icon" href="/clientes/crmteste/assets/apple-touch-icon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Sora:wght@500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/clientes/crmteste/assets/site.css?v=1">
<style><?= tema_css() ?></style>
<script type="application/ld+json"><?= json_encode($schema, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) ?></script>
<?php if ($E['google_tag']): ?>
<script async src="https://www.googletagmanager.com/gtag/js?id=<?= $h($E['google_tag']) ?>"></script>
<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}gtag('js',new Date());gtag('config',<?= json_encode($E['google_tag']) ?>);</script>
<?php endif; ?>
<?php if ($E['pixel_meta']): ?>
<script>!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,document,'script','https://connect.facebook.net/en_US/fbevents.js');fbq('init',<?= json_encode($E['pixel_meta']) ?>);fbq('track','PageView');</script>
<?php endif; ?>
</head>
<body data-generico="<?= $h($E['produto_generico']) ?>">
<?php if ($E['pixel_meta']): ?><noscript><img height="1" width="1" style="display:none" alt="" src="https://www.facebook.com/tr?id=<?= $h($E['pixel_meta']) ?>&amp;ev=PageView&amp;noscript=1"></noscript><?php endif; ?>

<header class="nav" id="nav">
  <div class="wrap">
    <a href="#topo" class="logo" aria-label="<?= $h($E['nome']) ?> — início"><?= $logo() ?></a>
    <nav class="nav-links" aria-label="Principal">
      <a href="#vendedores">Vendedores</a>
      <?php if ($avaliacoes['lista']): ?><a href="#avaliacoes">Avaliações</a><?php endif; ?>
      <a href="#produtos">Produtos</a>
      <a href="#local">Onde estamos</a>
    </nav>
    <a class="btn btn-primary" href="#vendedores" aria-label="Falar no WhatsApp"><?= $wa ?><span>Falar agora</span></a>
  </div>
</header>

<main id="topo">

<section class="hero<?= count($fotosHero) ? '' : ' hero-plain' ?>" id="hero">
  <div class="hero-sticky">
    <?php foreach ($fotosHero as $i => $f): ?>
    <img class="hero-frame" src="<?= $h($f['src']) ?>" alt="<?= $h($f['alt'] ?? '') ?>" <?= $i === 0 ? 'fetchpriority="high"' : 'decoding="async"' ?>>
    <?php endforeach; ?>
    <div class="hero-copy">
      <h1 class="hero-kicker reveal"><?= $h($E['hero']['chamada']) ?></h1>
      <p class="hero-title reveal"><?= $h($E['hero']['titulo']) ?></p>
      <p class="tagline reveal d1"><?= $rico($E['hero']['subtitulo']) ?></p>
      <div class="hero-actions reveal d2">
        <a class="btn btn-primary btn-cta" href="#vendedores"><?= $wa ?><?= $h($E['hero']['botao']) ?></a>
      </div>
      <?php if ((int) $avaliacoes['total'] > 0): ?>
      <a class="hero-rating reveal d3" href="#avaliacoes" aria-label="Mais de <?= (int) $avaliacoes['total'] ?> avaliações no Google"><svg class="hr-g" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-google"/></svg><strong>+<em data-count="<?= (int) $avaliacoes['total'] ?>"><?= number_format((int) $avaliacoes['total'], 0, ',', '.') ?></em></strong><span><i class="hr-stars"><b>★</b><b>★</b><b>★</b><b>★</b><b>★</b></i><small><b>avaliações</b> no Google</small></span></a>
      <?php endif; ?>
    </div>
    <?php if (count($fotosHero) > 1): ?>
    <div class="hero-foot" aria-hidden="true">
      <div class="hero-cap"><?php foreach ($fotosHero as $i => $f): ?><span<?= $i === 0 ? ' class="on"' : '' ?>><?= $h($f['legenda'] ?? '') ?></span><?php endforeach; ?></div>
      <div class="hero-progress"><i></i></div>
      <span class="hero-hint"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 5v14M6 13l6 6 6-6"/></svg>role para ver</span>
    </div>
    <?php endif; ?>
  </div>
</section>

<section id="vendedores" class="team-top">
  <div class="wrap">
    <div class="team-head">
      <div><span class="eyebrow reveal">Fale com a gente</span><h2 class="reveal d1"><?= $rico($E['titulo_vendedores']) ?></h2></div>
    </div>
    <div class="team" style="--n:<?= min(5, count(VENDEDORES)) ?>">
      <?php $i = 0; foreach (VENDEDORES as $slug => [$nome, $whats]): $apelido = explode(' ', $nome)[0]; ?>
      <a class="member reveal<?= $i % 5 ? ' d' . ($i % 5) : '' ?>" data-vendedor="<?= $h($slug) ?>" href="https://wa.me/<?= $h($whats) ?>?text=<?= $waMsg($apelido) ?>" target="_blank" rel="noopener">
        <img src="<?= $h(VENDEDORES[$slug][2] ?: '/clientes/crmteste/assets/vendedor-padrao.svg') ?>" alt="<?= $h($nome . ', ' . $E['nome']) ?>" width="600" height="600" loading="lazy">
        <div class="info"><div><h3><?= $h($nome) ?></h3></div><span class="wa"><?= $wa ?><em>Chamar no WhatsApp</em></span></div>
      </a>
      <?php $i++; endforeach; ?>
    </div>
  </div>
</section>

<?php if ($avaliacoes['lista']): ?>
<section id="avaliacoes" class="reviews">
  <div class="wrap">
    <div class="rv-head">
      <div>
        <span class="eyebrow reveal">Avaliações reais no Google</span>
        <h2 class="reveal d1">Quem já comprou <span class="grad">fala por nós.</span></h2>
      </div>
      <?php if ((int) $avaliacoes['total'] > 0): ?>
      <div class="rv-score reveal d2"><strong>+<?= number_format((int) $avaliacoes['total'], 0, ',', '.') ?></strong><div><span class="rv-stars">★★★★★</span><small>avaliações no Google</small></div></div>
      <?php endif; ?>
    </div>
  </div>
  <div class="rv-track reveal" id="rvTrack" tabindex="0" aria-label="Avaliações de clientes">
    <?php foreach ($avaliacoes['lista'] as $a):
        $ini = implode('', array_map(function ($p) { return mb_strtoupper(mb_substr($p, 0, 1)); }, array_slice(preg_split('/\s+/', trim($a['nome'])), 0, 2))); ?>
    <figure class="rv-card">
      <div class="rv-top"><span class="rv-stars" aria-label="5 estrelas">★★★★★</span><svg class="rv-g" viewBox="0 0 48 48" aria-hidden="true"><use href="#i-google"/></svg></div>
      <blockquote><?= $h($a['texto']) ?></blockquote>
      <figcaption><span class="rv-av" aria-hidden="true"><?= $h($ini) ?></span><span><b><?= $h($a['nome']) ?></b><small>Avaliação no Google<?= !empty($a['data']) ? ' · ' . $h($a['data']) : '' ?></small></span></figcaption>
    </figure>
    <?php endforeach; ?>
  </div>
  <div class="wrap rv-controls">
    <div class="rv-dots" id="rvDots" aria-hidden="true"></div>
    <div class="rv-btns">
      <?php if ($avaliacoes['link_google']): ?><a class="rv-all" href="<?= $h($avaliacoes['link_google']) ?>" target="_blank" rel="noopener">Ver todas no Google</a><?php endif; ?>
      <button class="rv-btn" id="rvPrev" aria-label="Avaliação anterior"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round"><path d="M19 12H5M11 18l-6-6 6-6"/></svg></button>
      <button class="rv-btn" id="rvNext" aria-label="Próxima avaliação"><?= $seta ?></button>
    </div>
  </div>
  <?php if ($avaliacoes['link_avaliar']): ?>
  <div class="wrap">
    <a class="rv-write reveal" href="<?= $h($avaliacoes['link_avaliar']) ?>" target="_blank" rel="noopener">
      <svg viewBox="0 0 48 48" aria-hidden="true"><use href="#i-google"/></svg>
      <span><b>Já comprou com a gente?</b><small>Deixe sua avaliação no Google</small></span>
      <i aria-hidden="true">★★★★★</i>
    </a>
  </div>
  <?php endif; ?>
</section>
<?php endif; ?>

<?php if ($E['faixa']): ?>
<div class="marquee" aria-hidden="true">
  <div class="track">
    <?php for ($k = 0; $k < 2; $k++) foreach ($E['faixa'] as $t) echo '<span>' . $h($t) . '</span>'; ?>
  </div>
</div>
<?php endif; ?>

<section id="beneficios">
  <div class="wrap">
    <div class="head">
      <div><span class="eyebrow reveal">O melhor para você</span><h2 class="reveal d1"><?= $rico($E['titulo_beneficios']) ?></h2></div>
    </div>
    <div class="benefits">
      <?php foreach ($E['beneficios'] as $i => $b): ?>
      <article class="benefit reveal<?= $i ? ' d' . min(4, $i) : '' ?>">
        <div class="ico"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icones[$b['icone']] ?? $icones['estrela'] ?></svg></div>
        <h3><?= $h($b['titulo']) ?></h3>
        <p><?= $h($b['texto']) ?></p>
      </article>
      <?php endforeach; ?>
    </div>
    <?php if (!empty($E['destaque']['numero'])): ?>
    <div class="decade<?= empty($E['destaque']['imagem']) ? ' sem-foto' : '' ?>">
      <div class="big reveal"><b><?= $h($E['destaque']['numero']) ?></b><p><?= $h($E['destaque']['texto']) ?></p></div>
      <?php if (!empty($E['destaque']['imagem'])): ?><div class="photo reveal d1"><img src="<?= $h($E['destaque']['imagem']) ?>" alt="" loading="lazy"></div><?php endif; ?>
    </div>
    <?php endif; ?>
  </div>
</section>

<section id="produtos" style="padding-top:40px">
  <div class="wrap">
    <div class="head">
      <div><span class="eyebrow reveal">Nossos produtos</span><h2 class="reveal d1"><?= $rico($E['titulo_produtos']) ?></h2></div>
    </div>
    <div class="products" style="--n:<?= count($E['produtos']) ?>">
      <?php $nP = count($E['produtos']); $sobra = $nP > 3 ? ($nP - 3) % 3 : 0; // completa a última linha da grade
      foreach ($E['produtos'] as $i => $p): $largo = $i === $nP - 1 && $sobra ? ' style="grid-column:span ' . ($sobra === 1 ? 3 : 2) . '"' : ''; ?>
      <a class="prod reveal<?= $i % 4 ? ' d' . ($i % 4) : '' ?>"<?= $largo ?> href="#vendedores" data-produto="<?= $h($p['nome']) ?>">
        <?php if (!empty($p['imagem'])): ?><img class="bg" src="<?= $h($p['imagem']) ?>" alt="" loading="lazy"><?php endif; ?>
        <?php if (!empty($p['selo'])): ?><span class="tag"><?= $h($p['selo']) ?></span><?php endif; ?>
        <h3><?= $h($p['nome']) ?></h3>
        <span class="go"><?= $h($p['chamada'] ?? 'Ver opções') ?> <?= $seta ?></span>
      </a>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<?php if ($E['faq']): ?>
<section id="duvidas" class="faq" style="padding-top:40px">
  <div class="wrap">
    <div class="head">
      <div><span class="eyebrow reveal">Dúvidas frequentes</span><h2 class="reveal d1">Comprar com a gente é simples.</h2></div>
    </div>
    <div class="faq-list">
      <?php foreach ($E['faq'] as [$q, $r]): ?>
      <details class="faq-item reveal">
        <summary><?= $h($q) ?><span class="faq-ic" aria-hidden="true"></span></summary>
        <p><?= $h($r) ?></p>
      </details>
      <?php endforeach; ?>
    </div>
  </div>
</section>
<?php endif; ?>

<section id="local" style="padding-top:40px">
  <div class="wrap loc"<?= $E['mostrar_mapa'] ? '' : ' style="grid-template-columns:1fr"' ?>>
    <div class="loc-info reveal">
      <span class="eyebrow">Onde estamos</span>
      <h2><?= $h($E['local']['titulo']) ?></h2>
      <div class="loc-row"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icones['local'] ?></svg><div><small>Endereço</small><?= $end['nome_local'] ? $h($end['nome_local']) . '<br>' : '' ?><?= $h($end['rua']) ?><br><?= $h($end['bairro']) ?>, <?= $h($E['cidade']) ?> – <?= $h($E['uf']) ?><?= $end['cep'] ? ', ' . $h($end['cep']) : '' ?></div></div>
      <?php if (!empty($E['local']['entrega'])): ?><div class="loc-row"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icones['entrega'] ?></svg><div><small>Entrega</small><?= $h($E['local']['entrega']) ?></div></div><?php endif; ?>
      <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:auto">
        <a class="btn btn-primary" href="https://www.google.com/maps/search/?api=1&amp;query=<?= rawurlencode($enderecoLinha) ?>" target="_blank" rel="noopener">Como chegar</a>
        <a class="btn btn-ghost" href="#vendedores">Agendar visita</a>
      </div>
    </div>
    <?php if ($E['mostrar_mapa']): ?>
    <div class="map reveal d1">
      <iframe title="Mapa: <?= $h($E['nome']) ?>" loading="lazy" referrerpolicy="no-referrer-when-downgrade" src="https://maps.google.com/maps?q=<?= rawurlencode($enderecoLinha) ?>&amp;z=16&amp;output=embed"></iframe>
    </div>
    <?php endif; ?>
  </div>
</section>

<section class="final">
  <div class="wrap">
    <div class="final-card reveal">
      <span class="eyebrow" style="color:#fff"><?= $h($E['cta_final']['chamada']) ?></span>
      <h2><?= $h($E['cta_final']['titulo']) ?></h2>
      <div class="ctas"><a class="btn btn-primary" href="#vendedores"><?= $wa ?><?= $h($E['cta_final']['botao']) ?></a></div>
    </div>
  </div>
</section>

</main>

<footer class="footer">
  <div class="wrap">
    <div class="ft-top">
      <div class="ft-brand">
        <?= $logo() ?>
        <p><?= $h($E['rodape']['frase']) ?> <span><?= $h($E['rodape']['frase_destaque']) ?></span></p>
        <?php if ($E['instagram']): ?>
        <a class="ft-insta" href="https://www.instagram.com/<?= $h($E['instagram']) ?>/" target="_blank" rel="noopener" aria-label="Instagram da <?= $h($E['nome']) ?> (@<?= $h($E['instagram']) ?>)">
          <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="18" height="18" rx="5"/><circle cx="12" cy="12" r="4"/><circle cx="17.5" cy="6.5" r="1" fill="currentColor"/></svg>
          <span>Siga <b>@<?= $h($E['instagram']) ?></b></span>
        </a>
        <?php endif; ?>
      </div>
      <div class="ft-info">
        <div class="ft-item">
          <span class="ft-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icones['local'] ?></svg></span>
          <div><small>Endereço</small><p><?= $end['nome_local'] ? '<b>' . $h($end['nome_local']) . '</b><br>' : '' ?><?= $h($end['rua']) ?><br><?= $h($end['bairro']) ?>, <?= $h($E['cidade']) ?> – <?= $h($E['uf']) ?></p></div>
        </div>
        <div class="ft-item">
          <span class="ft-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icones['entrega'] ?></svg></span>
          <div><small>Atendimento</small><p><b><?= $h($E['regiao']) ?></b><br><?= $h($E['local']['entrega'] ?? '') ?></p></div>
        </div>
        <div class="ft-item">
          <?php if ((int) $avaliacoes['total'] > 0): ?>
          <span class="ft-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icones['estrela'] ?></svg></span>
          <div><small>Avaliação</small><p><span class="ft-stars">★★★★★</span><br><b>+<?= number_format((int) $avaliacoes['total'], 0, ',', '.') ?> avaliações</b><br>no Google</p></div>
          <?php else: ?>
          <span class="ft-ic" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><?= $icones['conversa'] ?></svg></span>
          <div><small>Contato</small><p><b><?= $h($fmtTel($E['telefone'])) ?></b><br>WhatsApp</p></div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <div class="ft-bottom">
      <span>© <span id="y"><?= date('Y') ?></span> <?= $h($E['nome']) ?>. Todos os direitos reservados.</span>
      <span><?= $h($E['cidade']) ?>/<?= $h($E['uf']) ?> · <a href="/clientes/crmteste/privacidade.php">Privacidade</a></span>
    </div>
  </div>
</footer>

<!-- formulário de lead (aparece só quando o banco está ativo: /api/lead.php?ping) -->
<dialog class="lead-modal" id="leadModal" aria-labelledby="lmTitle">
  <form class="lm-card" id="leadForm" novalidate>
    <button type="button" class="lm-close" aria-label="Fechar">&times;</button>
    <div class="lm-head">
      <img id="lmFoto" src="" alt="" width="56" height="56">
      <div><small>Você vai falar com</small><strong id="lmTitle">vendedor</strong></div>
    </div>
    <label class="lm-field">Seu nome<input name="nome" autocomplete="name" maxlength="80" required></label>
    <label class="lm-field">Seu WhatsApp<input name="telefone" type="tel" inputmode="tel" autocomplete="tel" placeholder="(11) 90000-0000" required></label>
    <fieldset class="lm-prod"><legend>O que você procura?</legend><div class="lm-chips"><?php foreach (PRODUTOS as $p): ?><label class="lm-chip"><input type="radio" name="produto" value="<?= $h($p) ?>" required><span><?= $h($p) ?></span></label><?php endforeach; ?></div></fieldset>
    <input name="site" class="lm-hp" tabindex="-1" autocomplete="off" aria-hidden="true">
    <p class="lm-err" id="lmErr" role="alert" hidden></p>
    <button class="btn btn-primary lm-submit" type="submit"><?= $wa ?>Continuar no WhatsApp</button>
    <p class="lm-fine">Seus dados são usados só para o seu atendimento. <a href="/clientes/crmteste/privacidade.php" target="_blank" rel="noopener">Privacidade</a></p>
  </form>
</dialog>

<a class="fab" href="#vendedores" aria-label="Falar no WhatsApp"><?= $wa ?><span><?= $h($E['botao_flutuante']) ?></span></a>

<svg width="0" height="0" style="position:absolute" aria-hidden="true">
  <symbol id="i-google" viewBox="0 0 48 48"><path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.7 32.7 29.2 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.4-.4-3.5z"/><path fill="#FF3D00" d="m6.3 14.7 6.6 4.8C14.7 15.1 19 12 24 12c3.1 0 5.8 1.2 7.9 3.1l5.7-5.7C34 6.1 29.3 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/><path fill="#4CAF50" d="M24 44c5.2 0 9.9-2 13.4-5.2l-6.2-5.2C29.2 35.1 26.7 36 24 36c-5.2 0-9.6-3.3-11.3-8l-6.5 5C9.5 39.6 16.2 44 24 44z"/><path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.8 2.2-2.2 4.2-4.1 5.6l6.2 5.2C36.9 39.2 44 34 44 24c0-1.3-.1-2.4-.4-3.5z"/></symbol>
  <symbol id="i-wa" viewBox="0 0 24 24"><path d="M3.516 3.516c4.686-4.686 12.284-4.686 16.97 0s4.686 12.283 0 16.97a12 12 0 0 1-13.754 2.299l-5.814.735a.392.392 0 0 1-.438-.44l.748-5.788A12 12 0 0 1 3.517 3.517zm3.61 17.043.3.158a9.85 9.85 0 0 0 11.534-1.758c3.843-3.843 3.843-10.074 0-13.918s-10.075-3.843-13.918 0a9.85 9.85 0 0 0-1.747 11.554l.16.303-.51 3.942a.196.196 0 0 0 .219.22zm6.534-7.003-.933 1.164a9.84 9.84 0 0 1-3.497-3.495l1.166-.933a.79.79 0 0 0 .23-.94L9.561 6.96a.79.79 0 0 0-.924-.445l-2.023.524a.797.797 0 0 0-.588.88 11.754 11.754 0 0 0 10.005 10.005.797.797 0 0 0 .88-.587l.525-2.023a.79.79 0 0 0-.445-.923L14.6 13.327a.79.79 0 0 0-.94.23z"/></symbol>
</svg>

<script src="/clientes/crmteste/assets/site.js?v=1"></script>
</body>
</html>
