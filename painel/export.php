<?php
declare(strict_types=1);
require __DIR__ . '/_core.php';
$u = exige_login();
[$f, $where, $par] = filtros_leads($u);

$st = db()->prepare("SELECT * FROM leads $where ORDER BY criado_em DESC");
$st->execute($par);

/**
 * Proteção contra "injeção de fórmula": texto vindo do formulário público que comece com
 * = + - @ (ou tab/enter) seria executado como fórmula pelo Excel. Um apóstrofo na frente
 * faz o Excel tratar como texto puro.
 */
function celula($v): string
{
    $v = (string) $v;
    return ($v !== '' && strpos("=+-@\t\r", $v[0]) !== false) ? "'" . $v : $v;
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="leads-' . date('Y-m-d') . '.csv"');
$out = fopen('php://output', 'w');
fwrite($out, "\xEF\xBB\xBF"); // acentos corretos no Excel
fputcsv($out, ['ID', 'Data', 'Nome', 'Telefone', 'Produto', 'Vendedor', 'Status', 'Observação', 'Origem', 'Mídia', 'Campanha', 'Troca: modelo', 'Troca: capacidade', 'Troca: estado', 'Troca: bateria', 'Troca: detalhes'], ';');
while ($l = $st->fetch()) {
    fputcsv($out, array_map('celula', [
        $l['id'], date('d/m/Y H:i', strtotime($l['criado_em'])), $l['nome'], formata_telefone($l['telefone']),
        $l['produto'], VENDEDORES[$l['vendedor']][0] ?? $l['vendedor'], STATUS[$l['status']] ?? $l['status'],
        $l['observacao'], $l['origem'], $l['midia'], $l['campanha'],
        $l['troca_modelo'] ?? '', $l['troca_capacidade'] ?? '', TROCA_ESTADOS[$l['troca_estado'] ?? ''] ?? '',
        !empty($l['troca_bateria']) ? $l['troca_bateria'] . '%' : '', $l['troca_obs'] ?? '',
    ]), ';');
}
fclose($out);
