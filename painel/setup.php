<?php
/**
 * Configuração inicial: cria as tabelas e o primeiro usuário gestor.
 * Fica bloqueado automaticamente depois que existir algum usuário.
 */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$erro = null;
try {
    // com usuários já cadastrados, esta tela não faz nada (nem toca na estrutura do banco)
    try {
        $jaConfigurado = (int) db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0;
    } catch (PDOException $e) {
        $jaConfigurado = false; // tabela ainda não existe
    }
    if ($jaConfigurado) {
        header('Location: /clientes/crmteste/painel/');
        exit;
    }
    // criar o primeiro gestor só é permitido no computador local ou se liberado no config ('permitir_setup' => true)
    $local = in_array(ip_real(), ['127.0.0.1', '::1'], true);
    if (!$local && empty(cfg()['permitir_setup'])) {
        http_response_code(403);
        cabecalho('Configuração');
        echo '<div class="card login"><h1 style="margin:0;font-size:20px">Configuração bloqueada</h1><p class="muted">Por segurança, a criação do primeiro gestor está desativada neste servidor.</p></div>';
        rodape();
        exit;
    }
    migrar();
} catch (Throwable $ex) {
    error_log('[setup] ' . $ex->getMessage()); // detalhe técnico só no log, nunca na tela
    http_response_code(503);
    cabecalho('Configuração');
    echo '<div class="card login"><h1 style="margin:0;font-size:20px">Banco não conectado</h1><p class="muted">Confira o arquivo de configuração do banco no servidor.</p></div>';
    rodape();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    confere_csrf();
    $nome = trim((string) ($_POST['nome'] ?? ''));
    $login = strtolower(trim((string) ($_POST['login'] ?? '')));
    $senha = (string) ($_POST['senha'] ?? '');
    if ($nome === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $login)) $erro = 'Preencha o nome e um login de 3 a 40 caracteres (letras, números, ponto, hífen).';
    elseif (mb_strlen($senha) < 8) $erro = 'A senha precisa ter pelo menos 8 caracteres.';
    elseif ($senha !== (string) ($_POST['senha2'] ?? '')) $erro = 'As senhas não conferem.';
    else {
        // reconfere dentro da gravação: evita dois "primeiros gestores" em envios simultâneos
        if ((int) db()->query('SELECT COUNT(*) FROM usuarios')->fetchColumn() > 0) {
            header('Location: /clientes/crmteste/painel/');
            exit;
        }
        db()->prepare('INSERT INTO usuarios (nome, login, senha_hash, papel) VALUES (?, ?, ?, ?)')
            ->execute([$nome, $login, password_hash($senha, PASSWORD_DEFAULT), 'gestor']);
        flash('Pronto! Entre com o login de gestor que você acabou de criar.');
        header('Location: /clientes/crmteste/painel/');
        exit;
    }
}

cabecalho('Configuração');
?>
<form class="login card" method="post">
  <?= logo_html('login-logo') ?>
  <h1 style="margin:0;text-align:center;font-size:20px">Configuração do painel</h1>
  <p class="muted small" style="text-align:center">Banco conectado e tabelas criadas. Crie agora o acesso do <b>gestor</b>.</p>
  <?php if ($erro): ?><div class="flash err"><?= e($erro) ?></div><?php endif; ?>
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>">
  <label>Seu nome<input name="nome" required value="<?= e($_POST['nome'] ?? '') ?>"></label>
  <label>Login<input name="login" required placeholder="ex.: gestor" value="<?= e($_POST['login'] ?? '') ?>"></label>
  <label>Senha (mín. 8 caracteres)<input name="senha" type="password" minlength="8" required autocomplete="new-password"></label>
  <label>Repita a senha<input name="senha2" type="password" minlength="8" required autocomplete="new-password"></label>
  <button class="btn" type="submit">Criar gestor</button>
</form>
<?php rodape();
