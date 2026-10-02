<?php
/** Manifesto do app instalável (nome e cores vêm de config/empresa.php). */
declare(strict_types=1);
require __DIR__ . '/../api/bootstrap.php';
header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
$fundo = empresa('cores')['fundo'] ?? '#0b0a0d';
$icone = function (string $src, string $tam, string $uso = 'any') { return ['src' => $src, 'sizes' => $tam, 'type' => 'image/png', 'purpose' => $uso]; };
echo json_encode([
    'name' => empresa('nome') . ' · Vendas',
    'short_name' => empresa('app_nome'),
    'description' => 'Painel de leads e vendas da ' . empresa('nome'),
    'lang' => 'pt-BR',
    'id' => '/clientes/crmteste/painel/',
    'start_url' => '/clientes/crmteste/painel/',
    'scope' => '/clientes/crmteste/painel/',
    'display' => 'standalone',
    'orientation' => 'portrait',
    'background_color' => $fundo,
    'theme_color' => $fundo,
    'icons' => [$icone('/clientes/crmteste/assets/app-192.png', '192x192'), $icone('/clientes/crmteste/assets/app-512.png', '512x512'), $icone('/clientes/crmteste/assets/app-maskable-512.png', '512x512', 'maskable')],
    'shortcuts' => [
        ['name' => 'Follow-ups de hoje', 'short_name' => 'Follow-ups', 'url' => '/clientes/crmteste/painel/followups.php', 'icons' => [['src' => '/clientes/crmteste/assets/app-192.png', 'sizes' => '192x192']]],
        ['name' => 'Andamento dos leads', 'short_name' => 'Andamento', 'url' => '/clientes/crmteste/painel/', 'icons' => [['src' => '/clientes/crmteste/assets/app-192.png', 'sizes' => '192x192']]],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
