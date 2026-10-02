<?php
/**
 * Notificações push (Web Push: RFC 8030, 8291 e 8292) sem bibliotecas externas — só openssl e curl.
 * Funciona no iPhone (iOS 16.4+ com o app na tela de início), Android e computador.
 *
 * As chaves VAPID são geradas sozinhas no primeiro uso e guardadas na tabela `ajustes`.
 */
declare(strict_types=1);

// prefixo DER de uma chave pública EC P-256 (SubjectPublicKeyInfo) — basta anexar o ponto de 65 bytes
const P256_SPKI = '3059301306072a8648ce3d020106082a8648ce3d030107034200';

function b64u(string $s): string
{
    return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
}

function b64u_dec(string $s): string
{
    return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
}

function p256_novo()
{
    $k = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
    if (!$k) throw new RuntimeException('openssl sem suporte a EC');
    return $k;
}

/** Ponto público "cru" (0x04 || X || Y, 65 bytes) de uma chave EC. */
function p256_ponto($k): string
{
    $ec = openssl_pkey_get_details($k)['ec'];
    return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
}

function p256_publica(string $ponto)
{
    $pem = "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode(hex2bin(P256_SPKI) . $ponto), 64, "\n") . "-----END PUBLIC KEY-----\n";
    $k = openssl_pkey_get_public($pem);
    if (!$k) throw new RuntimeException('chave do aparelho inválida');
    return $k;
}

/** Chaves VAPID do servidor: ['publica' => base64url do ponto, 'privada' => PEM]. */
function vapid(): array
{
    static $v = null;
    if ($v) return $v;
    $st = db()->query("SELECT chave, valor FROM ajustes WHERE chave IN ('vapid_publica','vapid_privada')");
    $a = $st->fetchAll(PDO::FETCH_KEY_PAIR);
    if (empty($a['vapid_publica']) || empty($a['vapid_privada'])) {
        $k = p256_novo();
        openssl_pkey_export($k, $pem);
        $a = ['vapid_publica' => b64u(p256_ponto($k)), 'vapid_privada' => $pem];
        // INSERT IGNORE: se duas requisições gerarem ao mesmo tempo, vale a primeira
        $ins = db()->prepare('INSERT IGNORE INTO ajustes (chave, valor) VALUES (?, ?)');
        foreach ($a as $c => $val) $ins->execute([$c, $val]);
        $a = db()->query("SELECT chave, valor FROM ajustes WHERE chave IN ('vapid_publica','vapid_privada')")->fetchAll(PDO::FETCH_KEY_PAIR);
    }
    return $v = ['publica' => $a['vapid_publica'], 'privada' => $a['vapid_privada']];
}

/** Assinatura ES256 no formato do JWT (r || s, 64 bytes) a partir da assinatura DER do openssl. */
function es256(string $dados, string $pemPrivada): string
{
    if (!openssl_sign($dados, $der, $pemPrivada, OPENSSL_ALGO_SHA256)) throw new RuntimeException('falha ao assinar');
    $pos = 2 + (ord($der[1]) & 0x80 ? ord($der[1]) & 0x7f : 0); // pula SEQUENCE + tamanho
    $int = function () use ($der, &$pos): string {
        $len = ord($der[$pos + 1]);
        $v = substr($der, $pos + 2, $len);
        $pos += 2 + $len;
        return str_pad(ltrim($v, "\0"), 32, "\0", STR_PAD_LEFT);
    };
    return $int() . $int();
}

function vapid_cabecalho(string $endpoint): string
{
    $p = parse_url($endpoint);
    $v = vapid();
    $jwt = b64u('{"typ":"JWT","alg":"ES256"}') . '.' . b64u(json_encode([
        'aud' => $p['scheme'] . '://' . $p['host'],
        'exp' => time() + 12 * 3600,
        'sub' => 'https://' . empresa('dominio'),
    ]));
    return 'vapid t=' . $jwt . '.' . b64u(es256($jwt, $v['privada'])) . ', k=' . $v['publica'];
}

/** Criptografa a mensagem para um aparelho (aes128gcm, RFC 8291). */
function push_cifrar(string $texto, string $p256dh, string $auth): string
{
    $ua = b64u_dec($p256dh);
    $segredo = b64u_dec($auth);
    if (strlen($ua) !== 65 || strlen($segredo) !== 16) throw new RuntimeException('inscrição inválida');

    $local = p256_novo();
    $localPub = p256_ponto($local);
    $ecdh = openssl_pkey_derive(p256_publica($ua), $local, 32);
    if ($ecdh === false) throw new RuntimeException('falha no ECDH');

    $sal = random_bytes(16);
    $ikm = hash_hkdf('sha256', $ecdh, 32, "WebPush: info\0" . $ua . $localPub, $segredo);
    $cek = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $sal);
    $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $sal);

    $cifrado = openssl_encrypt($texto . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag);
    return $sal . pack('N', 4096) . chr(65) . $localPub . $cifrado . $tag;
}

/**
 * Envia a mesma mensagem para várias inscrições em paralelo.
 * Inscrições que o serviço de push diz não existirem mais (404/410) são apagadas.
 * Retorna quantas foram entregues.
 */
function push_enviar(array $inscricoes, array $msg, ?array &$detalhes = null): int
{
    if (!$inscricoes) return 0;
    $json = json_encode($msg, JSON_UNESCAPED_UNICODE);
    $multi = curl_multi_init();
    $handles = [];
    foreach ($inscricoes as $s) {
        try {
            $corpo = push_cifrar($json, $s['p256dh'], $s['auth']);
            $ch = curl_init($s['endpoint']);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $corpo,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
                CURLOPT_CONNECTTIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'Authorization: ' . vapid_cabecalho($s['endpoint']),
                    'Content-Type: application/octet-stream',
                    'Content-Encoding: aes128gcm',
                    'TTL: 86400',
                    'Urgency: high',
                ],
            ]);
            curl_multi_add_handle($multi, $ch);
            $handles[] = [$ch, $s];
        } catch (Throwable $e) {
            error_log('[push] ' . $e->getMessage());
        }
    }
    do {
        $st = curl_multi_exec($multi, $ativos);
        if ($ativos) curl_multi_select($multi, 1.0);
    } while ($ativos && $st === CURLM_OK);

    $ok = 0;
    $detalhes = [];
    $apagar = db()->prepare('DELETE FROM push_inscricoes WHERE id = ?');
    $usar = db()->prepare('UPDATE push_inscricoes SET ultimo_envio = NOW() WHERE id = ?');
    foreach ($handles as [$ch, $s]) {
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $detalhes[] = [
            "id" => (int) $s["id"], "servico" => parse_url($s["endpoint"], PHP_URL_HOST), "http" => $code,
            "resposta" => substr((string) curl_multi_getcontent($ch), 0, 300), "erro_rede" => curl_error($ch),
        ];
        if ($code >= 200 && $code < 300) {
            $ok++;
            $usar->execute([(int) $s['id']]);
        } elseif ($code === 404 || $code === 410) {
            $apagar->execute([(int) $s['id']]); // aparelho desinstalou o app ou tirou a permissão
        } else {
            error_log('[push] HTTP ' . $code . ' ' . parse_url($s['endpoint'], PHP_URL_HOST) . ' ' . substr((string) curl_multi_getcontent($ch), 0, 200));
        }
        curl_multi_remove_handle($multi, $ch);
        curl_close($ch);
    }
    curl_multi_close($multi);
    return $ok;
}

/** Avisa o vendedor escolhido e os gestores que chegou um lead. */
function push_novo_lead(int $id, string $nome, string $produto, string $vendedor): void
{
    $st = db()->prepare("SELECT p.* FROM push_inscricoes p JOIN usuarios u ON u.id = p.usuario_id
                          WHERE u.ativo = 1 AND (u.papel = 'gestor' OR " . sql_vendedor($vendedor, 'u.vendedor') . "))");
    $st->execute();
    $subs = $st->fetchAll();
    $primeiro = strtok($nome, ' ') ?: $nome;
    push_enviar($subs, [
        'title' => 'Novo lead: ' . $primeiro,
        'body' => $produto . ' · atendimento: ' . (VENDEDORES[$vendedor][0] ?? $vendedor),
        'url' => '/clientes/crmteste/painel/?lead=' . $id,
        'tag' => 'lead-' . $id,
    ], $det);
    // guarda o resultado do último aviso de lead (aparece no diagnóstico do painel)
    db()->prepare('REPLACE INTO ajustes (chave, valor) VALUES (?, ?)')->execute(['push_ultimo_lead', json_encode([
        'lead' => $id, 'em' => date('Y-m-d H:i:s'), 'destinos' => count($subs), 'envios' => $det ?? [],
    ], JSON_UNESCAPED_UNICODE)]);
}
