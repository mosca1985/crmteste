<?php
/**
 * Cadastro manual: clientes atendidos pessoalmente ou pelo Direct.
 * Ficam separados dos leads do site (canal = 'manual'), mas somam no dashboard e na TV.
 *   GET   quadro (Kanban) só com os cadastros manuais
 *   POST  cria um cadastro (nome, telefone, produto, origem, vendedor, status, observacao)
 */
declare(strict_types=1);
require __DIR__ . '/_core.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = exige_login();
    confere_csrf();
    $gestor = eh_gestor($u);

    $nome = trim((string) preg_replace('/\s+/u', ' ', (string) preg_replace('/[\x00-\x1F\x7F]/u', '', (string) ($_POST['nome'] ?? ''))));
    $tel = normaliza_telefone((string) ($_POST['telefone'] ?? ''));
    $produto = (string) ($_POST['produto'] ?? '');
    $origem = (string) ($_POST['origem'] ?? '');
    $status = (string) ($_POST['status'] ?? 'atendimento');
    $vendedor = $gestor ? (string) ($_POST['vendedor'] ?? '') : (string) $u['vendedor'];
    $obs = mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 2000);

    $erro = null;
    if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) $erro = 'Informe o nome do cliente.';
    elseif (strlen($tel) < 12 || strlen($tel) > 13) $erro = 'Informe um WhatsApp válido com DDD.';
    elseif (!in_array($produto, PRODUTOS, true)) $erro = 'Escolha o produto.';
    elseif (!isset(ORIGENS_MANUAIS[$origem])) $erro = 'Escolha a origem: Pessoal ou Direct.';
    elseif (!isset(VENDEDORES[$vendedor])) $erro = 'Escolha o vendedor.';
    elseif (!isset(STATUS[$status]) || $status === 'perdido') $erro = 'Escolha o andamento.';

    if ($erro) {
        flash($erro);
        header('Location: /clientes/crmteste/painel/cadastro.php');
        exit;
    }

    // o primeiro contato já aconteceu (pessoalmente ou no Direct): a cadência segue para o follow-up 1, amanhã
    $aberto = in_array($status, STATUS_ABERTOS, true);
    $pdo = db();
    $pdo->prepare("INSERT INTO leads (nome, telefone, produto, vendedor, status, observacao, origem, canal,
            followup_etapa, primeiro_contato, ultimo_contato, proximo_followup, vendido_em)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'manual', 1, NOW(), NOW(), ?, ?)")
        ->execute([$nome, $tel, $produto, $vendedor, $status, $obs === '' ? null : $obs, $origem,
            $aberto ? date('Y-m-d H:i:s', strtotime('+1 day')) : null,
            $status === 'vendido' ? date('Y-m-d H:i:s') : null]);
    $id = (int) $pdo->lastInsertId();
    $pdo->prepare('INSERT INTO lead_eventos (lead_id, usuario_id, tipo, detalhe) VALUES (?, ?, ?, ?)')
        ->execute([$id, (int) $u['id'], 'manual', 'Cadastro manual · ' . ORIGENS_MANUAIS[$origem]]);

    flash($status === 'vendido' ? '🎉 Venda de ' . $nome . ' cadastrada.' : $nome . ' cadastrado(a).');
    header('Location: /clientes/crmteste/painel/cadastro.php');
    exit;
}

$canal = 'manual';
require __DIR__ . '/_kanban.php';
