<?php
/**
 * Base do painel: sessão, login, CSRF, filtros de leads e layout.
 */
declare(strict_types=1);
require __DIR__ . '/../api/bootstrap.php';

// ---------- cabeçalhos de segurança ----------
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()');
header('Cache-Control: no-store, private');
// só scripts do próprio painel; nada de script externo ou injetado
header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; "
    . "font-src https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self'; frame-ancestors 'none'; "
    . "form-action 'self'; base-uri 'none'; object-src 'none'");

// ---------- sessão ----------
const SESSAO_OCIOSA = 8 * 3600;       // desloga após 8 h sem uso
const SESSAO_MAXIMA = 7 * 24 * 3600;  // e no máximo 7 dias após o login

$https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_name('ts_painel');
session_set_cookie_params(['lifetime' => 0, 'path' => '/clientes/crmteste/painel', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);
session_start();

if (!empty($_SESSION['uid'])) {
    $agora = time();
    if ($agora - (int) ($_SESSION['visto'] ?? 0) > SESSAO_OCIOSA || $agora - (int) ($_SESSION['inicio'] ?? 0) > SESSAO_MAXIMA) {
        $_SESSION = [];
        session_regenerate_id(true);
        $_SESSION['flash'] = 'Sua sessão expirou. Entre novamente.';
    } else {
        $_SESSION['visto'] = $agora;
    }
}

/** Impressão digital da senha: se a senha mudar, as outras sessões daquele usuário caem. */
function digital_senha(array $u): string
{
    return substr(hash('sha256', $u['senha_hash'] . '|' . $u['id']), 0, 24);
}

/** Inicia a sessão de um usuário que acabou de se autenticar. */
function iniciar_sessao(array $u): void
{
    session_regenerate_id(true);
    $_SESSION['uid'] = (int) $u['id'];
    $_SESSION['fp'] = digital_senha($u);
    $_SESSION['inicio'] = $_SESSION['visto'] = time();
    unset($_SESSION['csrf']);
}

const PERIODOS = ['hoje' => 'Hoje', '7' => '7 dias', '30' => '30 dias', '90' => '90 dias', 'tudo' => 'Tudo'];

function e($v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES, 'UTF-8');
}

function usuario(): ?array
{
    static $u = false;
    if ($u === false) {
        $u = null;
        if (!empty($_SESSION['uid'])) {
            $st = db()->prepare('SELECT * FROM usuarios WHERE id = ? AND ativo = 1');
            $st->execute([$_SESSION['uid']]);
            $u = $st->fetch() ?: null;
            // usuário desativado ou senha trocada em outro lugar: encerra esta sessão
            if (!$u || !hash_equals((string) ($_SESSION['fp'] ?? ''), digital_senha($u))) {
                $u = null;
                $_SESSION = [];
                session_regenerate_id(true);
            } elseif (($_SESSION['schema'] ?? 0) !== SCHEMA_VERSAO) {
                // ajustes de estrutura do banco: só para usuário logado, uma vez por sessão
                try {
                    migrar();
                    $_SESSION['schema'] = SCHEMA_VERSAO;
                } catch (Throwable $e) {
                    error_log('[migrar] ' . $e->getMessage());
                }
            }
        }
    }
    return $u;
}

function exige_login(): array
{
    $u = usuario();
    if (!$u) {
        header('Location: /clientes/crmteste/painel/');
        exit;
    }
    return $u;
}

function eh_gestor(array $u): bool
{
    return $u['papel'] === 'gestor';
}

function csrf(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(16));
    }
    return $_SESSION['csrf'];
}

function confere_csrf(): void
{
    $t = (string) ($_POST['csrf'] ?? ($_SERVER['HTTP_X_CSRF'] ?? ''));
    if (!hash_equals($_SESSION['csrf'] ?? '', $t)) {
        http_response_code(400);
        exit('Sessão expirada. Volte e tente de novo.');
    }
}

function flash(?string $msg = null): ?string
{
    if ($msg !== null) {
        $_SESSION['flash'] = $msg;
        return null;
    }
    $m = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $m;
}

/** "há 5 min", "há 2 h", "ontem", "12/09" */
function ha_quanto(string $data): string
{
    $s = time() - strtotime($data);
    if ($s < 60) return 'agora';
    if ($s < 3600) return 'há ' . floor($s / 60) . ' min';
    if ($s < 86400) return 'há ' . floor($s / 3600) . ' h';
    if ($s < 172800) return 'ontem';
    if ($s < 604800) return 'há ' . floor($s / 86400) . ' dias';
    return date('d/m', strtotime($data));
}

function origem_legivel(array $l): string
{
    if (!empty($l['origem'])) {
        $o = strtolower($l['origem']);
        $map = ORIGENS + ['ig' => 'Instagram', 'fb' => 'Facebook', 'meta' => 'Facebook'];
        return $map[$o] ?? ucfirst($l['origem']);
    }
    if (!empty($l['referrer'])) {
        $h = (string) parse_url($l['referrer'], PHP_URL_HOST);
        if (strpos($h, 'instagram') !== false) return 'Instagram';
        if (strpos($h, 'facebook') !== false) return 'Facebook';
        if (strpos($h, 'google') !== false) return 'Google';
        return $h ?: 'Site';
    }
    return 'Direto';
}

/** Chave de ORIGENS equivalente à origem do lead ('' se não der para identificar). */
function origem_chave(array $l): string
{
    $rot = origem_legivel($l);
    $k = array_search($rot, ORIGENS, true);
    return $k === false ? ($rot === 'Site' ? '' : 'outro') : (string) $k;
}

/**
 * Monta o WHERE dos leads a partir dos filtros da URL.
 * Vendedor só enxerga os próprios leads, independente do filtro.
 */
function filtros_leads(array $u, string $periodoPadrao = 'tudo', ?string $forcarPeriodo = null): array
{
    if ($forcarPeriodo !== null) {
        [$f, $w, $p] = filtros_leads($u, $periodoPadrao);
        $bak = $_GET;
        $_GET['periodo'] = $forcarPeriodo;
        unset($_GET['de'], $_GET['ate']);
        [, $w, $p] = filtros_leads($u, $periodoPadrao);
        $_GET = $bak;
        return [$f, $w, $p];
    }
    $f = [
        'vendedor' => (string) ($_GET['vendedor'] ?? ''),
        'status'   => (string) ($_GET['status'] ?? ''),
        'produto'  => (string) ($_GET['produto'] ?? ''),
        'periodo'  => (string) ($_GET['periodo'] ?? $periodoPadrao),
        'de'       => (string) ($_GET['de'] ?? ''),
        'ate'      => (string) ($_GET['ate'] ?? ''),
        'q'        => trim((string) ($_GET['q'] ?? '')),
        'canal'    => in_array($_GET['canal'] ?? '', ['site', 'manual'], true) ? (string) $_GET['canal'] : '',
    ];
    if (!isset(PERIODOS[$f['periodo']])) $f['periodo'] = $periodoPadrao;
    $w = [];
    $p = [];
    if (!eh_gestor($u)) {
        $w[] = sql_vendedor((string) $u['vendedor']);
        $f['vendedor'] = '';
    } elseif (isset(VENDEDORES[$f['vendedor']])) {
        $w[] = 'vendedor = ?';
        $p[] = $f['vendedor'];
    }
    if (isset(STATUS[$f['status']])) { $w[] = 'status = ?'; $p[] = $f['status']; }
    if ($f['canal'] !== '') { $w[] = 'canal = ?'; $p[] = $f['canal']; }
    if (in_array($f['produto'], PRODUTOS, true)) { $w[] = 'produto = ?'; $p[] = $f['produto']; }

    $temData = false;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['de'])) { $w[] = 'criado_em >= ?'; $p[] = $f['de'] . ' 00:00:00'; $temData = true; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $f['ate'])) { $w[] = 'criado_em <= ?'; $p[] = $f['ate'] . ' 23:59:59'; $temData = true; }
    if (!$temData && $f['periodo'] !== 'tudo') {
        $desde = $f['periodo'] === 'hoje' ? date('Y-m-d') : date('Y-m-d', strtotime('-' . ((int) $f['periodo'] - 1) . ' days'));
        $w[] = 'criado_em >= ?';
        $p[] = $desde . ' 00:00:00';
    }
    if ($f['q'] !== '') {
        $dig = preg_replace('/\D+/', '', $f['q']);
        if ($dig !== '') {
            $w[] = '(nome LIKE ? OR telefone LIKE ?)';
            $p[] = '%' . $f['q'] . '%';
            $p[] = '%' . $dig . '%';
        } else {
            $w[] = 'nome LIKE ?';
            $p[] = '%' . $f['q'] . '%';
        }
    }
    return [$f, $w ? 'WHERE ' . implode(' AND ', $w) : '', $p];
}

function qs(array $f, array $extra = []): string
{
    return http_build_query(array_filter($extra + $f, function ($v) { return $v !== '' && $v !== null; }));
}

function foto_vendedor(string $slug): string
{
    $base = slugs_do_vendedor($slug)[0];
    return (VENDEDORES[$slug][2] ?? '') ?: ((VENDEDORES[$base][2] ?? '') ?: '/clientes/crmteste/assets/vendedor-padrao.svg');
}

/** Logo da empresa (imagem de config/empresa.php ou o nome em texto). */
function logo_html(string $classe = ''): string
{
    $logo = (string) empresa('logo');
    $nome = (string) empresa('nome');
    return $logo !== ''
        ? '<img class="' . e($classe) . '" src="' . e($logo) . '" alt="' . e($nome) . '">'
        : '<b class="logo-txt ' . e($classe) . '">' . e($nome) . '</b>';
}

function cabecalho(string $titulo, ?array $u = null, string $ativo = ''): void
{
    ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<meta name="csrf" content="<?= e(csrf()) ?>">
<title><?= e($titulo) ?> · <?= e(empresa('nome')) ?></title>
<link rel="icon" href="/clientes/crmteste/favicon.ico">
<link rel="manifest" href="/clientes/crmteste/painel/manifest.php">
<meta name="theme-color" content="<?= e(empresa('cores')['fundo'] ?? '#0b0a0d') ?>">
<link rel="apple-touch-icon" href="/clientes/crmteste/assets/apple-touch-icon.png">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="<?= e(empresa('app_nome')) ?>">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700;800&family=Sora:wght@600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="/clientes/crmteste/painel/painel.css?v=16">
<style><?= tema_css() ?></style>
</head>
<body>
<?php if ($u): $g = eh_gestor($u); ?>
<header class="top"><div class="wrap">
  <a class="brand" href="/clientes/crmteste/painel/"><?= logo_html() ?><span>Vendas</span></a>
  <?php
    $fu = followups_vencidos($u);
    $ic = function (string $p): string { return '<svg class="ni" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $p . '</svg>'; };
    $itens = [];
    if ($g) $itens[] = ['dashboard', '/clientes/crmteste/painel/dashboard.php', 'Dashboard', '<path d="M3 3h7v9H3zM14 3h7v5h-7zM14 12h7v9h-7zM3 16h7v5H3z"/>'];
    $itens[] = ['kanban', '/clientes/crmteste/painel/', 'Andamento', '<rect x="3" y="4" width="5" height="16" rx="1.5"/><rect x="10" y="4" width="5" height="11" rx="1.5"/><rect x="17" y="4" width="4" height="7" rx="1.5"/>'];
    $itens[] = ['cadastro', '/clientes/crmteste/painel/cadastro.php', 'Cadastro', '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6 1.6 0 3 .3 4.2 1M19 14v6M16 17h6"/>'];
    $itens[] = ['followups', '/clientes/crmteste/painel/followups.php', 'Follow-ups', '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>'];
    $itens[] = ['lista', '/clientes/crmteste/painel/lista.php', 'Lista', '<path d="M8 6h13M8 12h13M8 18h13M3.5 6h.01M3.5 12h.01M3.5 18h.01"/>'];
    $itens[] = $g
        ? ['usuarios', '/clientes/crmteste/painel/usuarios.php', 'Usuários', '<circle cx="9" cy="8" r="4"/><path d="M2 21c0-4 3-6 7-6s7 2 7 6M16 4a4 4 0 0 1 0 8M22 21c0-3-2-5-4-5.5"/>']
        : ['usuarios', '/clientes/crmteste/painel/usuarios.php', 'Senha', '<rect x="4" y="11" width="16" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>'];
    // placar da TV (abre em outra aba; no celular fica fora da barra de baixo)
    if ($g) $itens[] = ['tv', '/clientes/crmteste/painel/tv.php', 'TV', '<rect x="2" y="4" width="20" height="13" rx="2"/><path d="M8 21h8M12 17v4"/>'];
  ?>
  <nav class="menu" aria-label="Menu do painel">
    <?php foreach ($itens as [$k, $href, $rot, $svg]): ?>
      <a href="<?= e($href) ?>" class="<?= $ativo === $k ? 'on' : '' ?><?= $k === 'tv' ? ' so-pc' : '' ?>"<?= $ativo === $k ? ' aria-current="page"' : '' ?><?= $k === 'tv' ? ' target="_blank" rel="noopener" title="Abrir o placar da TV em outra aba"' : '' ?>>
        <?= $ic($svg) ?><span class="nl"><?= e($rot) ?></span><?php if ($k === 'followups' && $fu): ?><span class="badge" id="fuBadge"><?= $fu ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="me">
    <button type="button" class="btn sm ghost bell" id="btnNotif" hidden title="Avisos de novo lead" aria-label="Avisos de novo lead"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.9" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M6 8a6 6 0 0 1 12 0c0 7 3 9 3 9H3s3-2 3-9M10.3 21a1.94 1.94 0 0 0 3.4 0"/></svg><span class="nl">Avisos</span></button>
    <button type="button" class="btn sm ghost" id="btnInstalar" hidden>📲 <span class="nl">Instalar app</span></button>
    <?php if (!$g && $u['vendedor']): ?><img src="<?= e(foto_vendedor((string) $u['vendedor'])) ?>" alt=""><?php endif; ?>
    <span><?= e($u['nome']) ?><small><?= $g ? 'Gestor' : 'Vendedor' ?></small></span>
    <form method="post" action="/clientes/crmteste/painel/sair.php"><input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><button class="out" type="submit" title="Sair" aria-label="Sair">⏻</button></form>
  </div>
</div></header>
<?php endif; ?>
<main class="wrap">
<?php if ($m = flash()): ?><div class="flash"><?= e($m) ?></div><?php endif;
}

function rodape(array $scripts = []): void
{
    echo "</main>\n";
    array_unshift($scripts, '/clientes/crmteste/painel/ui.js?v=4');
    foreach ($scripts as $s) echo '<script src="' . e($s) . '"></script>' . "\n";
    echo "</body>\n</html>";
}

// ---------- follow-up ----------
/** SQL das situações (usa as colunas de leads). */
const FU_ATRASADO = "((followup_etapa = 0 AND proximo_followup <= NOW() - INTERVAL 1 HOUR) OR (followup_etapa > 0 AND proximo_followup < CURDATE()))";
const FU_ATE_HOJE = "(proximo_followup IS NOT NULL AND proximo_followup < CURDATE() + INTERVAL 1 DAY AND status IN ('novo','atendimento','proposta'))";

/** 'atrasado' | 'hoje' | 'futuro' | null */
function followup_situacao(array $l): ?string
{
    if (empty($l['proximo_followup']) || !in_array($l['status'], STATUS_ABERTOS, true)) return null;
    $t = strtotime($l['proximo_followup']);
    $etapa = (int) $l['followup_etapa'];
    if (($etapa === 0 && $t <= time() - 3600) || ($etapa > 0 && $t < strtotime('today'))) return 'atrasado';
    if ($t < strtotime('tomorrow')) return 'hoje';
    return 'futuro';
}

/** "atrasado há 3 h", "hoje às 14:30", "amanhã às 10:00", "sex, 02/10" */
function followup_quando(array $l): string
{
    $t = strtotime((string) $l['proximo_followup']);
    $s = followup_situacao($l);
    if ($s === 'atrasado') {
        $d = time() - $t;
        return 'atrasado há ' . ($d < 86400 ? max(1, floor($d / 3600)) . ' h' : floor($d / 86400) . ($d < 172800 ? ' dia' : ' dias'));
    }
    if ($t <= time()) return 'agora';
    if ($t < strtotime('tomorrow')) return 'hoje às ' . date('H:i', $t);
    if ($t < strtotime('tomorrow +1 day')) return 'amanhã às ' . date('H:i', $t);
    $dias = ['dom', 'seg', 'ter', 'qua', 'qui', 'sex', 'sáb'];
    return $dias[(int) date('w', $t)] . ', ' . date('d/m', $t);
}

/** Quantos follow-ups vencem até hoje (inclui atrasados) — badge do menu. */
function followups_vencidos(array $u): int
{
    static $n = null;
    if ($n === null) {
        $sql = 'SELECT COUNT(*) FROM leads WHERE ' . FU_ATE_HOJE . (eh_gestor($u) ? '' : ' AND ' . sql_vendedor((string) $u['vendedor']));
        $st = db()->prepare($sql);
        $st->execute();
        $n = (int) $st->fetchColumn();
    }
    return $n;
}

// ---------- aparelho na troca ----------
function troca_json(array $l): array
{
    $estado = (string) ($l['troca_estado'] ?? '');
    $partes = array_filter([
        $l['troca_modelo'] ?? '',
        $l['troca_capacidade'] ?? '',
        $estado ? explode(' (', TROCA_ESTADOS[$estado] ?? $estado)[0] : '',
        !empty($l['troca_bateria']) ? 'bateria ' . (int) $l['troca_bateria'] . '%' : '',
    ]);
    return [
        'modelo' => (string) ($l['troca_modelo'] ?? ''),
        'capacidade' => (string) ($l['troca_capacidade'] ?? ''),
        'estado' => $estado,
        'bateria' => !empty($l['troca_bateria']) ? (string) (int) $l['troca_bateria'] : '',
        'obs' => (string) ($l['troca_obs'] ?? ''),
        'tem' => !empty($l['troca_modelo']),
        'resumo' => implode(' · ', $partes),
    ];
}
