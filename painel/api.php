<?php
/**
 * Ações do painel (JSON). Exige login; vendedor só mexe nos próprios leads.
 *   POST acao=mover       id, status
 *   POST acao=obs         id, observacao
 *   POST acao=vendedor    id, vendedor        (só gestor)
 *   POST acao=editar      id + campos alterados (nome, telefone, produto, origem, proximo, vendedor*, observacao)
 *   POST acao=contato     id                  registra o contato da etapa atual e agenda a próxima
 *   POST acao=adiar       id, horas=24        empurra o próximo follow-up
 *   GET  acao=novos       desde=<último id>   quantos leads novos chegaram
 *   GET  acao=pendentes                       follow-ups vencidos (badge do menu)
 *   GET  acao=historico   id                  linha do tempo do lead
 */
declare(strict_types=1);
require __DIR__ . '/_core.php';

$u = usuario();
if (!$u) json_out(['error' => 'login'], 401);
$gestor = eh_gestor($u);
$acao = (string) ($_REQUEST['acao'] ?? '');
$pdo = db();

// restrição do vendedor
$escopo = $gestor ? '' : ' AND ' . sql_vendedor((string) $u['vendedor']);

function evento(int $lead, string $tipo, ?string $detalhe = null): void
{
    global $u;
    db()->prepare('INSERT INTO lead_eventos (lead_id, usuario_id, tipo, detalhe) VALUES (?, ?, ?, ?)')
        ->execute([$lead, (int) $u['id'], $tipo, $detalhe]);
}

function lead_por_id(int $id, string $escopo): ?array
{
    $st = db()->prepare('SELECT * FROM leads WHERE id = ?' . $escopo);
    $st->execute([$id]);
    return $st->fetch() ?: null;
}

function lead_json(array $l): array
{
    return [
        'id' => (int) $l['id'], 'status' => $l['status'], 'vendedor' => $l['vendedor'], 'observacao' => $l['observacao'],
        'nome' => $l['nome'], 'tel' => $l['telefone'], 'telFmt' => formata_telefone($l['telefone']), 'produto' => $l['produto'],
        'vendedorNome' => VENDEDORES[$l['vendedor']][0] ?? $l['vendedor'], 'foto' => foto_vendedor($l['vendedor']), 'origem' => origem_legivel($l), 'origemKey' => origem_chave($l),
        'fu' => followup_situacao($l), 'fuTexto' => $l['proximo_followup'] ? followup_quando($l) : '',
        'proximoInput' => $l['proximo_followup'] ? date('Y-m-d\TH:i', strtotime($l['proximo_followup'])) : '',
        'etapaNome' => FOLLOWUP[min((int) $l['followup_etapa'], count(FOLLOWUP) - 1)]['nome'],
        'etapa' => (int) $l['followup_etapa'], 'proximo' => $l['proximo_followup'],
        'proximoTexto' => $l['proximo_followup'] ? followup_quando($l) : null,
        'mensagem' => followup_texto((int) $l['followup_etapa'], $l),
        'troca' => troca_json($l),
    ];
}

/** Linha do tempo do lead: chegada + eventos registrados, com quem fez e quando. */
function historico(array $l): array
{
    $st = db()->prepare('SELECT e.tipo, e.detalhe, e.em, u.nome FROM lead_eventos e LEFT JOIN usuarios u ON u.id = e.usuario_id WHERE e.lead_id = ? ORDER BY e.em DESC, e.id DESC LIMIT 200');
    $st->execute([(int) $l['id']]);
    $status = function (string $s): string { return STATUS[$s] ?? $s; };
    $itens = [];
    foreach ($st->fetchAll() as $e) {
        $d = (string) $e['detalhe'];
        switch ($e['tipo']) {
            case 'contato':  $txt = 'Mensagem enviada no WhatsApp' . ($d ? " ({$d})" : ''); $ic = 'wa'; break;
            case 'status':
                [$de, $para] = array_pad(explode(' → ', $d), 2, '');
                $txt = 'Andamento: ' . $status($de) . ' → ' . $status($para); $ic = 'status'; break;
            case 'adiado':   $txt = 'Follow-up adiado' . ($d ? ' em ' . $d : ''); $ic = 'adiar'; break;
            case 'edicao':   $txt = 'Editou ' . $d; $ic = 'edit'; break;
            case 'obs':      $txt = 'Atualizou a observação'; $ic = 'edit'; break;
            case 'troca':    $txt = 'Atualizou o aparelho na troca'; $ic = 'troca'; break;
            case 'vendedor':
                [$de, $para] = array_pad(explode(' → ', $d), 2, '');
                $txt = 'Lead passado de ' . (VENDEDORES[$de][0] ?? $de) . ' para ' . (VENDEDORES[$para][0] ?? $para); $ic = 'vend'; break;
            case 'manual':   continue 2; // a chegada do cadastro manual já aparece no fim da linha do tempo
            default:         $txt = $e['tipo'] . ($d ? ': ' . $d : ''); $ic = 'edit';
        }
        $itens[] = ['texto' => $txt, 'quem' => $e['nome'] ?: 'Sistema', 'quando' => date('d/m/Y H:i', strtotime($e['em'])), 'ic' => $ic];
    }
    if (($l['canal'] ?? 'site') === 'manual') {
        $st = db()->prepare("SELECT u.nome FROM lead_eventos e LEFT JOIN usuarios u ON u.id = e.usuario_id WHERE e.lead_id = ? AND e.tipo = 'manual' LIMIT 1");
        $st->execute([(int) $l['id']]);
        $itens[] = [
            'texto' => 'Cadastro manual · ' . origem_legivel($l) . ' · interesse em ' . $l['produto'],
            'quem' => (string) ($st->fetchColumn() ?: 'Painel'), 'quando' => date('d/m/Y H:i', strtotime($l['criado_em'])), 'ic' => 'edit',
        ];
    } else {
        $itens[] = [
            'texto' => 'Chegou pelo site · ' . origem_legivel($l) . ($l['campanha'] ? ' · campanha ' . $l['campanha'] : '') . ' · interesse em ' . $l['produto'],
            'quem' => 'Formulário do site', 'quando' => date('d/m/Y H:i', strtotime($l['criado_em'])), 'ic' => 'site',
        ];
    }
    return $itens;
}

try {
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($acao === 'novos') {
            $st = $pdo->prepare("SELECT COUNT(*) FROM leads WHERE id > ? AND status = 'novo' AND canal = 'site'" . $escopo);
            $st->execute([(int) ($_GET['desde'] ?? 0)]);
            json_out(['novos' => (int) $st->fetchColumn()]);
        }
        if ($acao === 'pendentes') {
            json_out(['pendentes' => followups_vencidos($u)]);
        }
        if ($acao === 'historico') {
            $l = lead_por_id((int) ($_GET['id'] ?? 0), $escopo);
            if (!$l) json_out(['error' => 'não encontrado'], 404);
            json_out(['ok' => true, 'itens' => historico($l)]);
        }
        json_out(['error' => 'ação'], 400);
    }

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error' => 'método'], 405);
    confere_csrf();
    $id = (int) ($_POST['id'] ?? 0);
    $l = lead_por_id($id, $escopo);
    if (!$l) json_out(['error' => 'não encontrado'], 404);

    // apagar de vez (só gestor): leva junto o histórico do lead
    if ($acao === 'apagar') {
        if (!$gestor) json_out(['error' => 'Só o gestor pode apagar leads.'], 403);
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM lead_eventos WHERE lead_id = ?')->execute([$id]);
        $pdo->prepare('DELETE FROM leads WHERE id = ?')->execute([$id]);
        $pdo->commit();
        error_log('[painel] lead ' . $id . ' apagado pelo usuário ' . (int) $u['id']);
        json_out(['ok' => true]);
    }

    if ($acao === 'mover') {
        $status = (string) ($_POST['status'] ?? '');
        if (!isset(STATUS[$status])) json_out(['error' => 'status'], 422);
        if (in_array($status, ['vendido', 'perdido'], true)) {
            $prox = null;                                   // encerra a cadência
        } elseif ($l['proximo_followup'] || (int) $l['followup_etapa'] >= count(FOLLOWUP)) {
            $prox = $l['proximo_followup'];                 // mantém a agenda atual
        } else {
            // reaberto: volta para a agenda (1º contato agora, ou próxima etapa amanhã)
            $prox = (int) $l['followup_etapa'] === 0 ? date('Y-m-d H:i:s') : date('Y-m-d H:i:s', strtotime('+1 day'));
        }
        // venda: guarda quando foi fechada (mantém a data se já era venda; limpa se deixou de ser)
        $vendidoEm = $status === 'vendido' ? ($l['status'] === 'vendido' && !empty($l['vendido_em']) ? $l['vendido_em'] : date('Y-m-d H:i:s')) : null;
        $pdo->prepare('UPDATE leads SET status = ?, proximo_followup = ?, vendido_em = ? WHERE id = ?')->execute([$status, $prox, $vendidoEm, $id]);
        evento($id, 'status', $l['status'] . ' → ' . $status);
    } elseif ($acao === 'obs') {
        $obs = mb_substr(trim((string) ($_POST['observacao'] ?? '')), 0, 2000);
        $pdo->prepare('UPDATE leads SET observacao = ? WHERE id = ?')->execute([$obs === '' ? null : $obs, $id]);
        evento($id, 'obs');
    } elseif ($acao === 'vendedor' && $gestor) {
        $v = (string) ($_POST['vendedor'] ?? '');
        if (!isset(VENDEDORES[$v])) json_out(['error' => 'vendedor'], 422);
        $pdo->prepare('UPDATE leads SET vendedor = ? WHERE id = ?')->execute([$v, $id]);
        evento($id, 'vendedor', $l['vendedor'] . ' → ' . $v);
    } elseif ($acao === 'editar') {
        // só os campos enviados são alterados
        $set = [];
        $par = [];
        $mud = [];
        if (isset($_POST['nome'])) {
            $nome = trim(preg_replace('/\s+/', ' ', (string) $_POST['nome']));
            if (mb_strlen($nome) < 2 || mb_strlen($nome) > 80) json_out(['error' => 'Nome inválido'], 422);
            $set[] = 'nome = ?'; $par[] = $nome; $mud[] = 'nome';
        }
        if (isset($_POST['telefone'])) {
            $tel = normaliza_telefone((string) $_POST['telefone']);
            if (strlen($tel) < 12 || strlen($tel) > 13) json_out(['error' => 'WhatsApp inválido (use DDD + número)'], 422);
            $set[] = 'telefone = ?'; $par[] = $tel; $mud[] = 'telefone';
        }
        if (isset($_POST['produto'])) {
            if (!in_array($_POST['produto'], PRODUTOS, true)) json_out(['error' => 'Produto inválido'], 422);
            $set[] = 'produto = ?'; $par[] = $_POST['produto']; $mud[] = 'produto';
        }
        if (isset($_POST['origem'])) {
            $o = (string) $_POST['origem'];
            if ($o !== '' && !isset(ORIGENS[$o])) json_out(['error' => 'Origem inválida'], 422);
            $set[] = 'origem = ?'; $par[] = $o === '' ? null : $o; $mud[] = 'origem';
        }
        if (isset($_POST['proximo'])) {
            $p = (string) $_POST['proximo'];
            if ($p !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $p)) json_out(['error' => 'Data inválida'], 422);
            $set[] = 'proximo_followup = ?'; $par[] = $p === '' ? null : str_replace('T', ' ', $p) . ':00'; $mud[] = 'próximo contato';
        }
        if (isset($_POST['vendedor']) && $gestor) {
            if (!isset(VENDEDORES[$_POST['vendedor']])) json_out(['error' => 'Vendedor inválido'], 422);
            $set[] = 'vendedor = ?'; $par[] = $_POST['vendedor']; $mud[] = 'vendedor';
        }
        if (isset($_POST['observacao'])) {
            $obs = mb_substr(trim((string) $_POST['observacao']), 0, 2000);
            $set[] = 'observacao = ?'; $par[] = $obs === '' ? null : $obs; $mud[] = 'observação';
        }
        // aparelho na troca
        $trocou = false;
        if (isset($_POST['troca_modelo'])) {
            $v = trim(mb_substr((string) $_POST['troca_modelo'], 0, 40));
            $set[] = 'troca_modelo = ?'; $par[] = $v === '' ? null : $v; $trocou = true;
        }
        if (isset($_POST['troca_capacidade'])) {
            $v = (string) $_POST['troca_capacidade'];
            if ($v !== '' && !in_array($v, TROCA_CAPACIDADES, true)) json_out(['error' => 'Capacidade inválida'], 422);
            $set[] = 'troca_capacidade = ?'; $par[] = $v === '' ? null : $v; $trocou = true;
        }
        if (isset($_POST['troca_estado'])) {
            $v = (string) $_POST['troca_estado'];
            if ($v !== '' && !isset(TROCA_ESTADOS[$v])) json_out(['error' => 'Estado inválido'], 422);
            $set[] = 'troca_estado = ?'; $par[] = $v === '' ? null : $v; $trocou = true;
        }
        if (isset($_POST['troca_bateria'])) {
            $v = trim((string) $_POST['troca_bateria']);
            if ($v !== '' && (!ctype_digit($v) || (int) $v < 1 || (int) $v > 100)) json_out(['error' => 'Saúde da bateria: use um número de 1 a 100'], 422);
            $set[] = 'troca_bateria = ?'; $par[] = $v === '' ? null : (int) $v; $trocou = true;
        }
        if (isset($_POST['troca_obs'])) {
            $v = trim(mb_substr((string) $_POST['troca_obs'], 0, 255));
            $set[] = 'troca_obs = ?'; $par[] = $v === '' ? null : $v; $trocou = true;
        }
        if ($set) {
            $par[] = $id;
            $pdo->prepare('UPDATE leads SET ' . implode(', ', $set) . ' WHERE id = ?')->execute($par);
            if ($mud) evento($id, 'edicao', implode(', ', $mud));
            if ($trocou) evento($id, 'troca');
        }
    } elseif ($acao === 'contato') {
        $etapa = (int) $l['followup_etapa'];
        $feitos = $etapa + 1;
        $primeiro = $l['primeiro_contato'] ?: (string) $pdo->query('SELECT NOW()')->fetchColumn(); // relógio do banco
        $prox = in_array($l['status'], ['vendido', 'perdido'], true) ? null : followup_proximo($feitos, $primeiro);
        $status = $l['status'] === 'novo' ? 'atendimento' : $l['status'];
        $pdo->prepare('UPDATE leads SET followup_etapa = ?, primeiro_contato = ?, ultimo_contato = NOW(), proximo_followup = ?, status = ? WHERE id = ?')
            ->execute([min($feitos, 255), $primeiro, $prox, $status, $id]);
        evento($id, 'contato', FOLLOWUP[min($etapa, count(FOLLOWUP) - 1)]['nome']);
    } elseif ($acao === 'adiar') {
        $horas = max(1, min(24 * 14, (int) ($_POST['horas'] ?? 24)));
        $base = max(time(), $l['proximo_followup'] ? strtotime($l['proximo_followup']) : time());
        $prox = date('Y-m-d H:i:s', $base + $horas * 3600);
        $pdo->prepare('UPDATE leads SET proximo_followup = ? WHERE id = ?')->execute([$prox, $id]);
        evento($id, 'adiado', $horas . 'h');
    } else {
        json_out(['error' => 'ação'], 400);
    }

    json_out(['ok' => true, 'lead' => lead_json(lead_por_id($id, $escopo))]);
} catch (Throwable $e) {
    error_log('[painel/api] ' . $e->getMessage());
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    // o gestor vê o motivo técnico (ajuda a resolver); o vendedor, só a mensagem genérica
    json_out(['error' => !empty($gestor) ? 'Erro: ' . mb_substr($e->getMessage(), 0, 160) : 'falha'], 500);
}
