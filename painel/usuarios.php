<?php
declare(strict_types=1);
require __DIR__ . '/_core.php';
$u = exige_login();
$gestor = eh_gestor($u);
$erro = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    confere_csrf();
    $acao = (string) ($_POST['acao'] ?? '');
    $pdo = db();

    if ($acao === 'minha_senha') {
        $atual = (string) ($_POST['atual'] ?? '');
        $nova = (string) ($_POST['nova'] ?? '');
        if (!password_verify($atual, $u['senha_hash'])) $erro = 'Senha atual incorreta.';
        elseif (mb_strlen($nova) < 8) $erro = 'A nova senha precisa ter pelo menos 8 caracteres.';
        else {
            $u['senha_hash'] = password_hash($nova, PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([$u['senha_hash'], $u['id']]);
            $_SESSION['fp'] = digital_senha($u); // mantém esta sessão; as outras sessões desse usuário caem
            session_regenerate_id(true);
            flash('Senha alterada. Outras sessões abertas com esse login foram encerradas.');
            header('Location: /clientes/crmteste/painel/usuarios.php');
            exit;
        }
    } elseif ($gestor && $acao === 'criar') {
        $nome = trim((string) ($_POST['nome'] ?? ''));
        $login = strtolower(trim((string) ($_POST['login'] ?? '')));
        $senha = (string) ($_POST['senha'] ?? '');
        $papel = ($_POST['papel'] ?? '') === 'gestor' ? 'gestor' : 'vendedor';
        $vend = (string) ($_POST['vendedor'] ?? '');
        if ($nome === '' || !preg_match('/^[a-z0-9._-]{3,40}$/', $login)) $erro = 'Preencha o nome e um login de 3 a 40 caracteres (letras, números, ponto, hífen).';
        elseif (mb_strlen($senha) < 8) $erro = 'A senha precisa ter pelo menos 8 caracteres.';
        elseif ($papel === 'vendedor' && !isset(VENDEDORES[$vend])) $erro = 'Escolha qual vendedor do site esse usuário representa.';
        else {
            try {
                $pdo->prepare('INSERT INTO usuarios (nome, login, senha_hash, papel, vendedor) VALUES (?, ?, ?, ?, ?)')
                    ->execute([$nome, $login, password_hash($senha, PASSWORD_DEFAULT), $papel, $papel === 'vendedor' ? $vend : null]);
                flash('Usuário "' . $login . '" criado.');
                header('Location: /clientes/crmteste/painel/usuarios.php');
                exit;
            } catch (PDOException $e) {
                $erro = 'Esse login já existe.';
            }
        }
    } elseif ($gestor && $acao === 'resetar') {
        $senha = (string) ($_POST['senha'] ?? '');
        if (mb_strlen($senha) < 8) $erro = 'A senha precisa ter pelo menos 8 caracteres.';
        else {
            $pdo->prepare('UPDATE usuarios SET senha_hash = ? WHERE id = ?')->execute([password_hash($senha, PASSWORD_DEFAULT), (int) $_POST['id']]);
            flash('Senha redefinida. Se a pessoa estava logada, precisará entrar de novo.');
            header('Location: /clientes/crmteste/painel/usuarios.php');
            exit;
        }
    } elseif ($gestor && $acao === 'tv_meta') {
        $meta = (int) ($_POST['meta'] ?? 0);
        if ($meta < 1 || $meta > 100000) $erro = 'Informe uma meta entre 1 e 100.000 vendas.';
        else {
            salvar_ajuste('meta_vendas_mes', (string) $meta);
            flash('Meta do mês atualizada para ' . $meta . ' vendas.');
            header('Location: /clientes/crmteste/painel/usuarios.php#tv');
            exit;
        }
    } elseif ($gestor && $acao === 'tv_link') {
        salvar_ajuste('tv_chave', bin2hex(random_bytes(16)));
        flash('Novo link da TV gerado. O link antigo parou de funcionar — atualize na TV.');
        header('Location: /clientes/crmteste/painel/usuarios.php#tv');
        exit;
    } elseif ($gestor && $acao === 'ativo') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id === (int) $u['id']) $erro = 'Você não pode desativar o próprio acesso.';
        else {
            $pdo->prepare('UPDATE usuarios SET ativo = 1 - ativo WHERE id = ?')->execute([$id]);
            flash('Acesso atualizado.');
            header('Location: /clientes/crmteste/painel/usuarios.php');
            exit;
        }
    }
}

cabecalho($gestor ? 'Usuários' : 'Minha senha', $u, 'usuarios');
if ($erro): ?><div class="flash err"><?= e($erro) ?></div><?php endif; ?>

<?php if ($gestor):
    $lista = db()->query('SELECT * FROM usuarios ORDER BY papel, nome')->fetchAll(); ?>
<h1>Usuários do painel</h1>
<div class="card" style="overflow-x:auto">
<table class="leads">
  <thead><tr><th>Nome</th><th>Login</th><th>Perfil</th><th>Último acesso</th><th>Redefinir senha</th><th>Acesso</th></tr></thead>
  <tbody>
  <?php foreach ($lista as $x): ?>
    <tr>
      <td data-l="Nome"><b><?= e($x['nome']) ?></b></td>
      <td data-l="Login"><?= e($x['login']) ?></td>
      <td data-l="Perfil"><?= $x['papel'] === 'gestor' ? 'Gestor' : 'Vendedor · ' . e(VENDEDORES[$x['vendedor']][0] ?? $x['vendedor']) ?></td>
      <td data-l="Último acesso" class="small muted"><?= $x['ultimo_acesso'] ? e(date('d/m/Y H:i', strtotime($x['ultimo_acesso']))) : '—' ?></td>
      <td class="act">
        <form method="post" class="row-form">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="resetar"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
          <input name="senha" type="text" placeholder="Nova senha" minlength="8" style="width:150px" autocomplete="off">
          <button class="btn sm ghost">Salvar</button>
        </form>
      </td>
      <td class="act">
        <form method="post">
          <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="ativo"><input type="hidden" name="id" value="<?= (int) $x['id'] ?>">
          <button class="btn sm ghost"><?= $x['ativo'] ? 'Ativo · desativar' : 'Inativo · ativar' ?></button>
        </form>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody>
</table>
</div>

<?php
    $tvChave = (string) ajuste('tv_chave', '');
    if ($tvChave === '') { $tvChave = bin2hex(random_bytes(16)); salvar_ajuste('tv_chave', $tvChave); }
    $tvLink = 'https://' . ($_SERVER['HTTP_HOST'] ?? (string) empresa('dominio')) . '/clientes/crmteste/painel/tv.php?k=' . $tvChave;
?>
<h2 id="tv">📺 TV do escritório</h2>
<div class="card tv-cfg">
  <p class="muted small">Placar com a meta, o ranking e as vendas do mês. Atualiza sozinho e comemora cada venda. Abra o link no navegador da TV — não precisa de login e não mostra dados de clientes.</p>
  <div class="tv-link">
    <input id="tvLink" type="text" readonly value="<?= e($tvLink) ?>" aria-label="Link da TV">
    <button type="button" class="btn sm" data-copiar="tvLink">Copiar link</button>
    <a class="btn sm ghost" href="<?= e($tvLink) ?>" target="_blank" rel="noopener">Abrir</a>
  </div>
  <div class="tv-forms">
    <form method="post" class="row-form">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="tv_meta">
      <label>Meta de vendas do mês <input name="meta" type="number" min="1" max="100000" value="<?= (int) ajuste('meta_vendas_mes', (string) (empresa('meta_vendas_padrao') ?: 100)) ?>" style="width:110px"></label>
      <button class="btn sm ghost">Salvar meta</button>
    </form>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="tv_link">
      <button class="btn sm ghost" title="Use se o link vazar: o antigo deixa de funcionar">Gerar novo link</button>
    </form>
  </div>
</div>

<h2>Novo usuário</h2>
<form method="post" class="card filters">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="criar">
  <label>Nome<input name="nome" required></label>
  <label>Login<input name="login" required placeholder="ex.: gabi"></label>
  <label>Senha inicial<input name="senha" type="text" minlength="8" required autocomplete="off"></label>
  <label>Perfil<select name="papel"><option value="vendedor">Vendedor</option><option value="gestor">Gestor</option></select></label>
  <label>Vendedor do site<select name="vendedor"><option value="">—</option>
    <?php foreach (VENDEDORES as $slug => [$nomeV]): ?><option value="<?= e($slug) ?>"><?= e($nomeV) ?></option><?php endforeach; ?>
  </select></label>
  <div><button class="btn sm" type="submit">Criar usuário</button></div>
</form>
<?php endif; ?>

<h2><?= $gestor ? 'Minha senha' : 'Alterar minha senha' ?></h2>
<form method="post" class="card filters" style="max-width:640px">
  <input type="hidden" name="csrf" value="<?= e(csrf()) ?>"><input type="hidden" name="acao" value="minha_senha">
  <label>Senha atual<input name="atual" type="password" required autocomplete="current-password"></label>
  <label>Nova senha<input name="nova" type="password" minlength="8" required autocomplete="new-password"></label>
  <div><button class="btn sm" type="submit">Alterar</button></div>
</form>
<?php rodape();
