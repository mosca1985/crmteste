<?php
/**
 * Notificações push do painel (JSON). Exige login.
 *   GET  acao=chave                              chave pública do servidor (para o aparelho se inscrever)
 *   POST acao=inscrever  endpoint, p256dh, auth  liga os avisos neste aparelho e manda um aviso de teste
 *   POST acao=cancelar   endpoint                desliga os avisos neste aparelho
 */
declare(strict_types=1);
require __DIR__ . '/_core.php';
require dirname(__DIR__) . '/clientes/crmteste/api/webpush.php';

$u = usuario();
if (!$u) json_out(['error' => 'login'], 401);
$acao = (string) ($_REQUEST['acao'] ?? '');

try {
    if (!function_exists('openssl_pkey_derive') || !function_exists('curl_multi_init')) {
        json_out(['error' => 'servidor sem suporte'], 501);
    }

    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($acao === 'chave') json_out(['ok' => true, 'chave' => vapid()['publica']]);
        json_out(['error' => 'ação'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'método'], 405);
    confere_csrf();

    // diagnóstico: manda um aviso de teste para todos os aparelhos deste usuário e mostra a resposta de cada serviço
    if ($acao === 'diagnostico') {
        $st = db()->prepare('SELECT * FROM push_inscricoes WHERE usuario_id = ?');
        $st->execute([(int) $u['id']]);
        $subs = $st->fetchAll();
        push_enviar($subs, ['title' => 'Teste de aviso 🔔', 'body' => 'Se você está vendo isto, os avisos funcionam.', 'url' => '/clientes/crmteste/painel/', 'tag' => 'teste'], $det);
        json_out([
            'ok' => true, 'php' => PHP_VERSION, 'aparelhos' => count($subs),
            'inscricoes' => array_map(function ($s) {
                return ['id' => (int) $s['id'], 'aparelho' => $s['aparelho'], 'criado_em' => $s['criado_em'], 'ultimo_envio' => $s['ultimo_envio']];
            }, $subs),
            'envios' => $det ?? [],
            'ultimo_lead' => json_decode((string) db()->query("SELECT valor FROM ajustes WHERE chave = 'push_ultimo_lead'")->fetchColumn(), true),
        ]);
    }

    $endpoint = trim((string) ($_POST['endpoint'] ?? ''));
    // só serviços de push conhecidos (Apple, Google, Mozilla, Microsoft): evita usar o servidor para chamar endereços arbitrários
    $host = (string) parse_url($endpoint, PHP_URL_HOST);
    if (strlen($endpoint) > 600 || strpos($endpoint, 'https://') !== 0
        || !preg_match('/(^|\.)(push\.apple\.com|fcm\.googleapis\.com|push\.services\.mozilla\.com|notify\.windows\.com)$/i', $host)) {
        json_out(['error' => 'endereço inválido'], 422);
    }
    $hash = hash('sha256', $endpoint);

    if ($acao === 'cancelar') {
        db()->prepare('DELETE FROM push_inscricoes WHERE endpoint_hash = ? AND usuario_id = ?')->execute([$hash, (int) $u['id']]);
        json_out(['ok' => true]);
    }

    if ($acao === 'inscrever') {
        $p256dh = (string) ($_POST['p256dh'] ?? '');
        $auth = (string) ($_POST['auth'] ?? '');
        if (strlen(b64u_dec($p256dh)) !== 65 || strlen(b64u_dec($auth)) !== 16) json_out(['error' => 'chaves inválidas'], 422);
        $aparelho = mb_substr(trim((string) ($_POST['aparelho'] ?? '')), 0, 80) ?: null;

        // o mesmo aparelho pode trocar de usuário (quem logou por último recebe)
        db()->prepare('INSERT INTO push_inscricoes (usuario_id, endpoint, endpoint_hash, p256dh, auth, aparelho) VALUES (?, ?, ?, ?, ?, ?)
                       ON DUPLICATE KEY UPDATE usuario_id = VALUES(usuario_id), p256dh = VALUES(p256dh), auth = VALUES(auth), aparelho = VALUES(aparelho)')
            ->execute([(int) $u['id'], $endpoint, $hash, $p256dh, $auth, $aparelho]);

        $entregue = 0;
        if (!empty($_POST['teste'])) {
            $st = db()->prepare('SELECT * FROM push_inscricoes WHERE endpoint_hash = ?');
            $st->execute([$hash]);
            $entregue = push_enviar($st->fetchAll(), [
                'title' => 'Notificações ativadas ✅',
                'body' => 'Você vai receber um aviso aqui a cada novo lead.',
                'url' => '/clientes/crmteste/painel/',
                'tag' => 'teste',
            ]);
        }
        json_out(['ok' => true, 'teste' => $entregue]);
    }

    json_out(['error' => 'ação'], 400);
} catch (Throwable $e) {
    error_log('[push.php] ' . $e->getMessage());
    json_out(['error' => 'indisponível'], 503);
}
