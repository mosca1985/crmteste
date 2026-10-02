<?php
/**
 * Configuração do banco de leads. Este arquivo é só um MODELO.
 *
 * ONDE COLOCAR A CÓPIA PREENCHIDA (no servidor da hospedagem, nunca no GitHub):
 *   1º (recomendado) FORA da pasta pública, ao lado da pasta www:  /vendas-config.php
 *      (assim, mesmo que o PHP falhe um dia, ninguém consegue baixar o arquivo pela internet)
 *   2º alternativa: api/config.php (bloqueado pelo .htaccess e ignorado pelo Git)
 */
return [
    // dados do banco MySQL criado no painel da hospedagem
    'db_host' => 'mysql.seudominio.com.br',
    'db_name' => 'nome_do_banco',
    'db_user' => 'usuario_do_banco',
    'db_pass' => 'senha_do_banco',

    // Texto aleatório e longo (40+ caracteres). Protege os códigos de IP e dos limites de tentativas.
    'secret'  => 'troque-por-um-texto-aleatorio-e-longo',

    // Opcional: IPs de proxy/CDN confiáveis (ex.: Cloudflare). Só com eles o sistema aceita o
    // cabeçalho X-Forwarded-For. Deixe vazio se o site não estiver atrás de um proxy.
    'proxies' => [],

    // Opcional: teto de leads aceitos por hora (proteção contra spam em massa).
    'limite_leads_hora' => 150,

    // Deixe false. Só mude para true, temporariamente, se precisar recriar o primeiro gestor no servidor.
    'permitir_setup' => false,
];
