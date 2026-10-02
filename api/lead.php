<?php
/**
 * Recebe o lead do formulário do site e grava no MySQL.
 *
 *  GET  /api/lead.php?ping   -> {"ok":true} quando o banco está configurado (o site só mostra o formulário nesse caso)
 *  POST /api/lead.php        -> JSON {nome, telefone, produto, vendedor, origem, midia, campanha, referrer, site, t}
 */
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if (isset($_GET['ping'])) {
            db()->query('SELECT 1 FROM leads LIMIT 1');
            json_out(['ok' => true]);
        }
        json_out(['error' => 'método não permitido'], 405);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        json_out(['error' => 'método não permitido'], 405);
    }

    // só aceita envios do próprio site
    $origin = $_SERVER['HTTP_ORIGIN'] ?? '';
    $dominio = preg_quote((string) empresa('dominio'), '#');
    if ($origin !== '' && !preg_match('#^https?://((www\.)?' . $dominio . '|localhost(:\d+)?|127\.0\.0\.1(:\d+)?)$#i', $origin)) {
        json_out(['error' => 'origem inválida'], 403);
    }

    // um lead legítimo tem menos de 1 KB; recusa envios grandes
    $corpo = (string) file_get_contents('php://input', false, null, 0, 8192);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 8192) {
        json_out(['error' => 'dados inválidos'], 413);
    }
    $in = json_decode($corpo, true);
    if (!is_array($in)) {
        json_out(['error' => 'dados inválidos'], 400);
    }

    // anti-spam: campo invisível preenchido ou envio rápido demais = robô
    if (!empty($in['site']) || (int) ($in['t'] ?? 0) < 1200) {
        json_out(['ok' => true]); // responde "ok" para não dar pistas ao robô
    }

    $nome = preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($in['nome'] ?? '')); // tira caracteres de controle invisíveis
    $nome = trim(preg_replace('/\s+/u', ' ', (string) $nome));
    $tel = normaliza_telefone((string) ($in['telefone'] ?? ''));
    $produto = (string) ($in['produto'] ?? '');
    $vendedor = (string) ($in['vendedor'] ?? '');

    $erros = [];
    if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) $erros[] = 'nome';
    if (strlen($tel) < 12 || strlen($tel) > 13) $erros[] = 'telefone';
    if (!in_array($produto, PRODUTOS, true)) $erros[] = 'produto';
    if (!isset(VENDEDORES[$vendedor])) $erros[] = 'vendedor';
    if ($erros) {
        json_out(['error' => 'campos inválidos', 'campos' => $erros], 422);
    }

    $ip = ip_hash();
    $pdo = db();

    // limite: no máximo 6 cadastros a cada 10 minutos por visitante
    $st = $pdo->prepare('SELECT COUNT(*) FROM leads WHERE ip_hash = ? AND criado_em > (NOW() - INTERVAL 10 MINUTE)');
    $st->execute([$ip]);
    if ((int) $st->fetchColumn() >= 6) {
        json_out(['error' => 'muitas tentativas'], 429);
    }
    // teto geral contra ataque distribuído (muitos IPs): bem acima do volume real de uma campanha
    $total = (int) $pdo->query('SELECT COUNT(*) FROM leads WHERE criado_em > (NOW() - INTERVAL 1 HOUR)')->fetchColumn();
    if ($total >= (int) (cfg()['limite_leads_hora'] ?? 150)) {
        error_log('[lead.php] teto de leads por hora atingido');
        json_out(['error' => 'muitas tentativas'], 429);
    }

    $cut = function ($v, int $n) {
        $v = trim((string) ($v ?? ''));
        return $v === '' ? null : mb_substr($v, 0, $n);
    };

    $valores = [
        $nome, $tel, $produto, $vendedor,
        $cut($in['origem'] ?? null, 80),
        $cut($in['midia'] ?? null, 80),
        $cut($in['campanha'] ?? null, 120),
        $cut($in['referrer'] ?? null, 255),
        $ip,
    ];
    // proximo_followup = agora: o 1º contato já entra na agenda do vendedor
    $sql = 'INSERT INTO leads (nome, telefone, produto, vendedor, origem, midia, campanha, referrer, ip_hash, proximo_followup)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
    try {
        $pdo->prepare($sql)->execute($valores);
    } catch (PDOException $e) {
        if ($e->getCode() !== '42S22') throw $e; // coluna ausente: banco ainda sem a migração
        migrar();
        $pdo->prepare($sql)->execute($valores);
    }

    $id = (int) $pdo->lastInsertId();

    // responde já ao visitante (segue para o WhatsApp) e só depois avisa a equipe no celular
    ignore_user_abort(true);
    $resposta = json_encode(['ok' => true, 'id' => $id]);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    header('Content-Length: ' . strlen($resposta));
    header('Connection: close');
    echo $resposta;
    if (function_exists('fastcgi_finish_request')) {
        fastcgi_finish_request();
    } else {
        while (ob_get_level()) ob_end_flush();
        flush();
    }
    try {
        require __DIR__ . '/webpush.php';
        push_novo_lead($id, $nome, $produto, $vendedor);
    } catch (Throwable $e) {
        error_log('[lead.php push] ' . $e->getMessage()); // aviso é extra: nunca atrapalha o cadastro
    }
    exit;
} catch (Throwable $e) {
    error_log('[lead.php] ' . $e->getMessage());
    json_out(['ok' => false, 'error' => 'indisponível'], 503);
}
