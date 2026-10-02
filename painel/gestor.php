<?php
// endereço antigo -> Dashboard
header('Location: /clientes/crmteste/painel/dashboard.php' . (empty($_SERVER['QUERY_STRING']) ? '' : '?' . $_SERVER['QUERY_STRING']), true, 301);
