<?php
/** Sitemap para o Google (endereço vem de config/empresa.php). Informe /sitemap.xml no Search Console. */
declare(strict_types=1);
require __DIR__ . '/clientes/crmteste/api/bootstrap.php';
header('Content-Type: application/xml; charset=utf-8');
$url = 'https://' . empresa('dominio') . '/clientes/crmteste/';
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc><?= htmlspecialchars($url) ?></loc><lastmod><?= date('Y-m-d', filemtime(__DIR__ . '/config/empresa.php')) ?></lastmod><changefreq>weekly</changefreq><priority>1.0</priority></url>
</urlset>
