<?php
declare(strict_types=1);
require __DIR__ . '/_core.php';
// sair só por formulário com token (um link externo não consegue deslogar ninguém)
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /clientes/crmteste/painel/');
    exit;
}
confere_csrf();
$_SESSION = [];
session_destroy();
setcookie('ts_painel', '', ['expires' => time() - 3600, 'path' => '/clientes/crmteste/painel', 'httponly' => true, 'samesite' => 'Lax']);
header('Location: /clientes/crmteste/painel/');
