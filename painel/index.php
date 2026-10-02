<?php
declare(strict_types=1);
require __DIR__ . '/_core.php';

// primeiro acesso: sem usuários cadastrados -> configuração inicial
try {
    $temUsuarios = (int) db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
} catch (Throwable $e) {
    $temUsuarios = false;
}
if (!$temUsuarios) {
    header('Location: /clientes/crmteste/painel/setup.php');
    exit;
}

// ---------- login ----------
if (!usuario()) {
    $erro = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        confere_csrf();
        $pdo = db();
        $login = strtolower(trim(mb_substr((string) ($_POST['login'] ?? ''), 0, 40)));
        $senha = mb_substr((string) ($_POST['senha'] ?? ''), 0, 200);
        // contadores de tentativas: por IP e por conta (a conta também é protegida contra ataques de vários IPs)
        $chaveIp = ip_hash();
        $chaveLogin = hash('sha256', 'login:' . $login . '|' . (cfg()['secret'] ?? ''));
        $pdo->exec('DELETE FROM login_tentativas WHERE em < (NOW() - INTERVAL 1 DAY)'); // limpeza
        $conta = $pdo->prepare('SELECT COUNT(*) FROM login_tentativas WHERE ip_hash = ? AND em > (NOW() - INTERVAL 15 MINUTE)');
        $conta->execute([$chaveIp]);
        $porIp = (int) $conta->fetchColumn();
        $conta->execute([$chaveLogin]);
        $porLogin = (int) $conta->fetchColumn();
        if ($porIp >= 8 || $porLogin >= 10) {
            $erro = 'Muitas tentativas. Aguarde 15 minutos e tente de novo.';
        } else {
            $st = $pdo->prepare('SELECT * FROM usuarios WHERE login = ? AND ativo = 1');
            $st->execute([$login]);
            $u = $st->fetch() ?: null;
            // mesmo tempo de resposta com ou sem usuário (não revela quais logins existem)
            $ok = password_verify($senha, $u ? $u['senha_hash'] : '$2y$10$73S.cnF7LY0WUOkTZ9MuH.zZTGIjrb9hqnLjIW5ZhnY.WQlMbsefG');
            if ($u && $ok) {
                if (password_needs_rehash($u['senha_hash'], PASSWORD_DEFAULT)) {
                    $u['senha_hash'] = password_hash($senha, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([$u['senha_hash'], $u['id']]);
                }
                iniciar_sessao($u);
                $pdo->prepare('UPDATE usuarios SET ultimo_acesso = NOW() WHERE id = ?')->execute([$u['id']]);
                $pdo->prepare('DELETE FROM login_tentativas WHERE ip_hash IN (?, ?)')->execute([$chaveIp, $chaveLogin]);
                header('Location: /clientes/crmteste/painel/');
                exit;
            }
            $ins = $pdo->prepare('INSERT INTO login_tentativas (ip_hash) VALUES (?)');
            $ins->execute([$chaveIp]);
            $ins->execute([$chaveLogin]);
            $erro = 'Login ou senha incorretos. Use o login (ex.: gestor), não o nome.';
        }
    }
    cabecalho('Entrar');
    ?>
    <form class="login card" method="post">
      <?= logo_html('login-logo') ?>
      <h1 style="text-align:center;font-size:20px">Painel de vendas</h1>
      <?php if ($erro): ?><div class="flash err"><?= e($erro) ?></div><?php endif; ?>
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
      <label>Login<input name="login" autocomplete="username" autocapitalize="none" required autofocus></label>
      <label>Senha<input name="senha" type="password" autocomplete="current-password" required></label>
      <button class="btn" type="submit">Entrar</button>
    </form>
    <?php
    rodape();
    exit;
}

// ---------- KANBAN (leads do site) ----------
$canal = 'site';
require __DIR__ . '/_kanban.php';
