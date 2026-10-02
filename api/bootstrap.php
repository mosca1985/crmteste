<?php
/**
 * Núcleo compartilhado entre a API de leads e o painel.
 * Compatível com PHP 7.4+.
 */
declare(strict_types=1);

date_default_timezone_set('America/Sao_Paulo');

// Nunca mostrar erros do PHP na tela (vazariam caminhos e dados do banco); ficam só no log do servidor.
$ehLocal = in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true) && PHP_SAPI === 'cli-server';
ini_set('display_errors', $ehLocal ? '1' : '0');
ini_set('log_errors', '1');
header_remove('X-Powered-By');

/** Dados da empresa (config/empresa.php): nome, cores, contatos, vendedores, produtos, textos. */
function empresa(?string $chave = null)
{
    static $e = null;
    if ($e === null) $e = require dirname(__DIR__) . '/config/empresa.php';
    return $chave === null ? $e : ($e[$chave] ?? null);
}

/** Vendedores: slug => [nome exibido, WhatsApp, foto] */
define('VENDEDORES', array_map(function (array $v) {
    return [(string) $v['nome'], preg_replace('/\D+/', '', (string) $v['whatsapp']), (string) ($v['foto'] ?? '')];
}, empresa('vendedores')));

/** Cartões do site que são da mesma pessoa: o login do vendedor vê os leads de todos eles. */
define('MESMA_PESSOA', empresa('mesma_pessoa') ?: []);

function slugs_do_vendedor(string $slug): array
{
    return MESMA_PESSOA[$slug] ?? [$slug];
}

/** Condição SQL "leads deste vendedor" (inclui os outros números da mesma pessoa). */
function sql_vendedor(string $slug, string $coluna = 'vendedor'): string
{
    return $coluna . ' IN (' . implode(',', array_map(function ($s) { return db()->quote($s); }, slugs_do_vendedor($slug))) . ')';
}

define('PRODUTOS', array_merge(array_column(empresa('produtos'), 'nome'), ['Outro']));

const STATUS = [
    'novo'        => 'Novo',
    'atendimento' => 'Em atendimento',
    'proposta'    => 'Proposta enviada',
    'vendido'     => 'Vendido',
    'perdido'     => 'Perdido',
];

/** Produto/aparelho na troca (opcional, ligado em config: 'troca' => true). */
define('TROCA_ATIVA', (bool) empresa('troca'));
define('TROCA_MODELOS', empresa('troca_modelos') ?: []);
const TROCA_CAPACIDADES = ['64GB', '128GB', '256GB', '512GB', '1TB', '2TB'];
const TROCA_ESTADOS = [
    'excelente' => 'Excelente (sem marcas)',
    'bom'       => 'Bom (marcas leves)',
    'marcas'    => 'Com marcas de uso',
    'defeito'   => 'Com defeito',
];

/** Status em aberto (ainda em negociação). */
const STATUS_ABERTOS = ['novo', 'atendimento', 'proposta'];
const SQL_ABERTOS = "('novo','atendimento','proposta')";

/** Origens do cadastro manual (aba Cadastro). */
const ORIGENS_MANUAIS = ['pessoal' => 'Pessoal', 'direct' => 'Direct'];

/** Origens selecionáveis no painel (chave gravada em leads.origem => rótulo). */
const ORIGENS = [
    'pessoal'   => 'Pessoal',
    'direct'    => 'Direct',
    'instagram' => 'Instagram',
    'google'    => 'Google',
    'facebook'  => 'Facebook',
    'whatsapp'  => 'WhatsApp',
    'indicacao' => 'Indicação',
    'loja'      => 'Loja física',
    'direto'    => 'Direto',
    'outro'     => 'Outro',
];

/** Cadência de follow-up (config/empresa.php → 'followup'). Índice = quantos contatos já foram feitos. */
define('FOLLOWUP', array_values(empresa('followup')));

/** Texto da etapa com os dados do lead. */
function followup_texto(int $etapa, array $lead): string
{
    $t = FOLLOWUP[min($etapa, count(FOLLOWUP) - 1)]['texto'];
    $prod = $lead['produto'] === 'Outro' ? (string) empresa('produto_generico') : $lead['produto'];
    return strtr($t, [
        '{cliente}'  => explode(' ', trim($lead['nome']))[0],
        '{vendedor}' => explode(' ', VENDEDORES[$lead['vendedor']][0] ?? 'a equipe')[0],
        '{produto}'  => $prod,
        '{empresa}'  => (string) empresa('nome'),
    ]);
}
/** Cores da empresa como variáveis CSS (usado no site, no painel e na TV). */
function tema_css(): string
{
    $cor = function (string $hex, string $padrao): string {
        return preg_match('/^#[0-9a-f]{6}$/i', $hex) ? strtolower($hex) : $padrao;
    };
    $c = empresa('cores') ?: [];
    $p = $cor((string) ($c['primaria'] ?? ''), '#d417ff');
    $s = $cor((string) ($c['secundaria'] ?? ''), '#872ab8');
    $d = $cor((string) ($c['escura'] ?? ''), '#55005f');
    $b = $cor((string) ($c['fundo'] ?? ''), '#0b0a0d');
    $rgb = function (string $h): array { return [hexdec(substr($h, 1, 2)), hexdec(substr($h, 3, 2)), hexdec(substr($h, 5, 2))]; };
    // tom claro da cor principal (textos de destaque sobre o fundo escuro)
    $clara = vsprintf('#%02x%02x%02x', array_map(function ($v) { return (int) round($v + (255 - $v) * .68); }, $rgb($p)));
    return ':root{--violet:' . $p . ';--purple:' . $s . ';--deep:' . $d . ';--purple-deep:' . $d . ';--bg:' . $b
        . ';--clara:' . $clara . ';--rgb-primaria:' . implode(',', $rgb($p)) . ';--rgb-secundaria:' . implode(',', $rgb($s)) . '}';
}

/** Próximo vencimento depois de registrar um contato ($feitos = contatos já feitos, contando este). */
function followup_proximo(int $feitos, string $primeiroContato): ?string
{
    if ($feitos >= count(FOLLOWUP)) return null; // cadência concluída
    return date('Y-m-d H:i:s', strtotime($primeiroContato . ' +' . FOLLOWUP[$feitos]['dias'] . ' days'));
}

function cfg(): array
{
    static $c = null;
    if ($c === null) {
        // Preferência: arquivo FORA da pasta pública (ex.: /home/usuario/vendas-config.php, ao lado de /www).
        // Alternativa: api/config.php (protegido pelo .htaccess e fora do Git).
        $raiz = rtrim((string) ($_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $candidatos = array_unique(array_filter([
            dirname(__DIR__, 2) . '/vendas-config.php',            // acima da pasta do site
            $raiz !== '' ? dirname($raiz) . '/vendas-config.php' : '', // acima da raiz pública informada pelo servidor
            dirname(__DIR__) . '/vendas-config.php',               // raiz do site (bloqueado no .htaccess)
            __DIR__ . '/config.php',                                    // api/config.php (bloqueado no .htaccess)
            dirname(__DIR__) . '/clientes/crmteste/api/vendas-config.php',           // api/ com o nome do modelo
        ]));
        $f = null;
        foreach ($candidatos as $cand) {
            if (is_file($cand)) { $f = $cand; break; }
        }
        if ($f === null) {
            throw new RuntimeException('config ausente');
        }
        $c = require $f;
    }
    return $c;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $c = cfg();
        $pdo = new PDO(
            'mysql:host=' . $c['db_host'] . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
            $c['db_user'],
            $c['db_pass'],
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]
        );
        $pdo->exec("SET time_zone = '-03:00'");
    }
    return $pdo;
}

function json_out($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * IP real do visitante. O cabeçalho X-Forwarded-For pode ser forjado por qualquer um,
 * então só é considerado quando a conexão vem de um proxy declarado em config['proxies'].
 */
function ip_real(): string
{
    $remoto = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
    $proxies = cfg()['proxies'] ?? [];
    if ($proxies && in_array($remoto, $proxies, true) && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $lista = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
        $ultimo = end($lista); // o último endereço é o que o nosso proxy viu
        if (filter_var($ultimo, FILTER_VALIDATE_IP)) return $ultimo;
    }
    return $remoto;
}

/** IP do visitante transformado em hash (não guardamos o IP em si). */
function ip_hash(): string
{
    return hash('sha256', ip_real() . '|' . (cfg()['secret'] ?? 'vendas'));
}

/** Só dígitos; garante DDI 55 para números brasileiros. */
function normaliza_telefone(string $t): string
{
    $d = preg_replace('/\D+/', '', $t);
    if (strlen($d) === 10 || strlen($d) === 11) {
        $d = '55' . $d;
    }
    return $d;
}

function formata_telefone(string $d): string
{
    if (strpos($d, '55') === 0 && (strlen($d) === 12 || strlen($d) === 13)) {
        $l = substr($d, 2);
        $ddd = substr($l, 0, 2);
        $n = substr($l, 2);
        return '(' . $ddd . ') ' . substr($n, 0, strlen($n) - 4) . '-' . substr($n, -4);
    }
    return $d;
}

/** Estrutura do banco (usada pelo painel/setup.php). */
const SCHEMA = [
    "CREATE TABLE IF NOT EXISTS leads (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        atualizado_em DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
        nome VARCHAR(80) NOT NULL,
        telefone VARCHAR(20) NOT NULL,
        produto VARCHAR(30) NOT NULL,
        vendedor VARCHAR(20) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'novo',
        observacao TEXT NULL,
        origem VARCHAR(80) NULL,
        midia VARCHAR(80) NULL,
        campanha VARCHAR(120) NULL,
        referrer VARCHAR(255) NULL,
        ip_hash CHAR(64) NULL,
        INDEX idx_vendedor_data (vendedor, criado_em),
        INDEX idx_status (status),
        INDEX idx_data (criado_em),
        INDEX idx_ip (ip_hash, criado_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS usuarios (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(80) NOT NULL,
        login VARCHAR(40) NOT NULL UNIQUE,
        senha_hash VARCHAR(255) NOT NULL,
        papel VARCHAR(10) NOT NULL DEFAULT 'vendedor',
        vendedor VARCHAR(20) NULL,
        ativo TINYINT(1) NOT NULL DEFAULT 1,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ultimo_acesso DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS login_tentativas (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        ip_hash CHAR(64) NOT NULL,
        em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_ip_em (ip_hash, em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
];

/** Ajustes de estrutura aplicados automaticamente (idempotentes: podem rodar várias vezes). */
const MIGRACOES = [
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS followup_etapa TINYINT UNSIGNED NOT NULL DEFAULT 0",
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS proximo_followup DATETIME NULL",
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS primeiro_contato DATETIME NULL",
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS ultimo_contato DATETIME NULL",
    "ALTER TABLE leads ADD INDEX IF NOT EXISTS idx_followup (proximo_followup)",
    // leads antigos em aberto que ainda não têm agenda entram na cadência
    "UPDATE leads SET proximo_followup = criado_em
       WHERE proximo_followup IS NULL AND followup_etapa = 0 AND primeiro_contato IS NULL AND status IN ('novo','atendimento')",
    "CREATE TABLE IF NOT EXISTS lead_eventos (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        lead_id INT UNSIGNED NOT NULL,
        usuario_id INT UNSIGNED NULL,
        tipo VARCHAR(20) NOT NULL,
        detalhe VARCHAR(255) NULL,
        em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_lead (lead_id, em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    // aparelho usado que o cliente vai dar na troca
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS troca_modelo VARCHAR(40) NULL",
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS troca_capacidade VARCHAR(10) NULL",
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS troca_estado VARCHAR(20) NULL",
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS troca_bateria TINYINT UNSIGNED NULL",
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS troca_obs VARCHAR(255) NULL",
    // notificações push: aparelhos inscritos e chaves do servidor
    "CREATE TABLE IF NOT EXISTS push_inscricoes (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        usuario_id INT UNSIGNED NOT NULL,
        endpoint VARCHAR(600) NOT NULL,
        endpoint_hash CHAR(64) NOT NULL UNIQUE,
        p256dh VARCHAR(120) NOT NULL,
        auth VARCHAR(40) NOT NULL,
        aparelho VARCHAR(80) NULL,
        criado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        ultimo_envio DATETIME NULL,
        INDEX idx_usuario (usuario_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    "CREATE TABLE IF NOT EXISTS ajustes (
        chave VARCHAR(40) PRIMARY KEY,
        valor TEXT NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    // data em que a venda foi fechada (meta do mês e placar da TV contam por ela)
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS vendido_em DATETIME NULL",
    "ALTER TABLE leads ADD INDEX IF NOT EXISTS idx_vendido_em (vendido_em)",
    "UPDATE leads l SET vendido_em = COALESCE(
        (SELECT MAX(e.em) FROM lead_eventos e WHERE e.lead_id = l.id AND e.tipo = 'status' AND e.detalhe LIKE '%vendido'),
        l.atualizado_em, l.criado_em)
      WHERE l.status = 'vendido' AND l.vendido_em IS NULL",
    // canal: 'site' (formulário da landing) ou 'manual' (aba Cadastro: atendimento pessoal e Direct)
    "ALTER TABLE leads ADD COLUMN IF NOT EXISTS canal VARCHAR(10) NOT NULL DEFAULT 'site'",
    "ALTER TABLE leads ADD INDEX IF NOT EXISTS idx_canal (canal, status)",
];
const SCHEMA_VERSAO = 6;

/** Ajustes guardados no banco (tabela ajustes). */
function ajuste(string $chave, ?string $padrao = null): ?string
{
    $st = db()->prepare('SELECT valor FROM ajustes WHERE chave = ?');
    $st->execute([$chave]);
    $v = $st->fetchColumn();
    return $v === false ? $padrao : (string) $v;
}

function salvar_ajuste(string $chave, string $valor): void
{
    db()->prepare('REPLACE INTO ajustes (chave, valor) VALUES (?, ?)')->execute([$chave, $valor]);
}

function migrar(): void
{
    foreach (SCHEMA as $sql) db()->exec($sql);
    foreach (MIGRACOES as $sql) db()->exec($sql);
}
