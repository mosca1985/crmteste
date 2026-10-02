<?php
/**
 * CONFIGURAÇÃO DA EMPRESA — o único arquivo que precisa mudar para cada cliente.
 *
 * Tudo aqui é informação pública (aparece no site). Senhas e acesso ao banco ficam em
 * api/config.php (fora do Git) — veja api/config.example.php.
 *
 * Imagens: coloque os arquivos em /assets e informe o caminho (ex.: '/clientes/crmteste/assets/hero-1.jpg').
 * Campo de imagem vazio = o site usa um fundo neutro no lugar.
 */
return [
    // ---------- identidade ----------
    'nome'        => 'Loja Exemplo',                 // nome da empresa
    'app_nome'    => 'Vendas',                       // nome curto do app do painel no celular
    'dominio'     => 'agenciaklik.com.br',        // sem https:// e sem www
    'logo'        => '',                             // ex.: '/clientes/crmteste/assets/logo.png' (vazio = nome em texto)
    'cores'       => [
        'primaria'   => '#ff4400',                   // botões, destaques (laranja da marca)
        'secundaria' => '#c23600',                   // laranja mais escuro (degradês)
        'escura'     => '#4a1500',                   // fundo dos blocos de destaque
        'fundo'      => '#0a0a0a',                   // preto da marca
    ],

    // ---------- contato e local ----------
    'cidade'      => 'Sua Cidade',
    'uf'          => 'SP',
    'regiao'      => 'Sua Cidade e região',          // usado em "Atendemos ..."
    'endereco'    => [
        'nome_local' => '',                          // ex.: 'Edifício Central' (opcional)
        'rua'        => 'Rua Exemplo, 123 – Sala 1',
        'bairro'     => 'Centro',
        'cep'        => '00000-000',
    ],
    'mostrar_mapa' => true,                          // mapa do endereço na seção "Onde estamos"
    'telefone'    => '5511900000000',                // WhatsApp/telefone principal (com 55 + DDD)
    'instagram'   => '',                             // usuário sem @ (vazio = esconde)

    // ---------- marketing (deixe vazio para desligar) ----------
    'pixel_meta'  => '',                             // ID do Pixel da Meta
    'google_tag'  => '',                             // ex.: 'AW-123456789' ou 'G-XXXXXXX'

    // ---------- SEO ----------
    'seo' => [
        'titulo'    => 'Loja Exemplo | Atendimento direto no WhatsApp',
        'descricao' => 'Fale direto com um vendedor no WhatsApp. Atendimento rápido, condições facilitadas e entrega na sua região.',
        'imagem'    => '',                           // imagem de compartilhamento (1200x630), ex.: '/clientes/crmteste/assets/compartilhar.jpg'
        'tipo'      => 'Store',                      // tipo no Google (schema.org): Store, LocalBusiness, AutoDealer...
    ],

    // ---------- topo da página ----------
    'hero' => [
        'chamada'   => 'Atendimento direto com quem entende',
        'titulo'    => 'Sua próxima compra.',
        'subtitulo' => 'Começa com um <em>oi.</em>',  // o trecho entre <em> ganha destaque
        'botao'     => 'Consultar preços no WhatsApp',
        // fotos que passam conforme o cliente rola a página (vazio = fundo neutro animado)
        'imagens'   => [
            // ['src' => '/clientes/crmteste/assets/hero-1.jpg', 'legenda' => 'Pronta entrega', 'alt' => 'Vitrine da loja'],
        ],
    ],

    // ---------- vendedores (porta de entrada: cada cartão abre o WhatsApp do vendedor) ----------
    // a chave (ex.: 'ana') identifica o vendedor no painel; não mude depois de ter leads
    'vendedores' => [
        'ana'   => ['nome' => 'Ana',   'whatsapp' => '5511900000001', 'foto' => ''],
        'bruno' => ['nome' => 'Bruno', 'whatsapp' => '5511900000002', 'foto' => ''],
        'carla' => ['nome' => 'Carla', 'whatsapp' => '5511900000003', 'foto' => ''],
    ],
    // vendedor com mais de um número: os cartões somam juntos no painel e no ranking
    // ex.: ['ana' => ['ana', 'ana2'], 'ana2' => ['ana', 'ana2']]
    'mesma_pessoa' => [],
    'titulo_vendedores' => 'Escolha seu vendedor e fale <span class="grad">direto no WhatsApp.</span>',
    'mensagem_whatsapp' => 'Olá {vendedor}! Vim pelo site e gostaria de mais informações.',

    // ---------- produtos (cartões do site e opções do formulário) ----------
    'produtos' => [
        ['nome' => 'Produto A', 'chamada' => 'Ver opções', 'imagem' => '', 'selo' => 'Mais procurado'],
        ['nome' => 'Produto B', 'chamada' => 'Ver opções', 'imagem' => ''],
        ['nome' => 'Produto C', 'chamada' => 'Ver opções', 'imagem' => ''],
        ['nome' => 'Serviços',  'chamada' => 'Saiba mais', 'imagem' => ''],
    ],
    'produto_generico'  => 'nossos produtos',        // usado quando o cliente escolhe "Outro"
    'titulo_produtos'   => 'Tudo o que você procura, em um só lugar.',

    // faixa que corre na tela (vazio = esconde)
    'faixa' => ['Pronta entrega', 'Condições facilitadas', 'Atendimento no WhatsApp', 'Entrega rápida'],

    // ---------- benefícios (4 cartões) ----------
    // ícones disponíveis: pagamento, relogio, troca, local, entrega, escudo, estrela, conversa
    'titulo_beneficios' => 'Por que comprar com a gente.',
    'beneficios' => [
        ['icone' => 'pagamento', 'titulo' => 'Pague do seu jeito',  'texto' => 'Várias formas de pagamento, com condições facilitadas.'],
        ['icone' => 'relogio',   'titulo' => 'Atendimento rápido',  'texto' => 'Resposta no WhatsApp em poucos minutos.'],
        ['icone' => 'escudo',    'titulo' => 'Compra segura',       'texto' => 'Produtos com garantia e nota fiscal.'],
        ['icone' => 'local',     'titulo' => 'Perto de você',       'texto' => 'Atendimento presencial e entrega na região.'],
    ],
    // bloco de destaque abaixo dos benefícios (número vazio = esconde)
    'destaque' => ['numero' => '10+', 'texto' => 'anos atendendo a região.', 'imagem' => ''],

    // ---------- avaliações (total = 0 esconde o selo; lista vazia esconde o carrossel) ----------
    'avaliacoes' => [
        'total'        => 0,                         // ex.: 350 → "+350 avaliações no Google"
        'link_google'  => '',                        // link "Ver todas no Google"
        'link_avaliar' => '',                        // link "Deixe sua avaliação"
        // use somente avaliações reais, copiadas do Google
        'lista' => [
            // ['nome' => 'Maria S.', 'texto' => 'Atendimento excelente!', 'data' => 'jan. 2026'],
        ],
    ],

    // ---------- perguntas frequentes (também vão para o Google) ----------
    'faq' => [
        ['Como faço para comprar?', 'Escolha um dos nossos vendedores e chame no WhatsApp. A conversa já abre direto com quem vai te atender.'],
        ['Quais formas de pagamento vocês aceitam?', 'Pix, cartão de crédito e débito. Consulte as condições com o vendedor.'],
        ['Vocês entregam na minha região?', 'Atendemos a cidade e a região. Confirme com o vendedor a disponibilidade para o seu endereço.'],
    ],

    // ---------- seções finais ----------
    'local'     => ['titulo' => 'Venha nos visitar.', 'entrega' => 'Entrega rápida na cidade e região'],
    'cta_final' => ['chamada' => 'Fale com a gente', 'titulo' => 'Seu pedido pode sair ainda hoje.', 'botao' => 'Chamar no WhatsApp'],
    'rodape'    => ['frase' => 'Atendimento de verdade,', 'frase_destaque' => 'do primeiro oi à entrega.'],
    'botao_flutuante' => 'Fale com a gente',

    // ---------- painel ----------
    'meta_vendas_padrao' => 100,                     // meta mensal inicial do placar da TV (muda no painel)

    // aparelho/produto usado na troca (seção nos detalhes do lead). false = esconde
    'troca'         => false,
    'troca_modelos' => [],                           // sugestões para o campo "Modelo"

    // cadência de follow-up: índice = contatos já feitos; "dias" contam a partir do 1º contato
    // marcadores: {cliente} {vendedor} {produto} {empresa}
    'followup' => [
        ['nome' => '1º contato',  'dias' => 0, 'texto' => 'Olá {cliente}! Aqui é {vendedor}, da {empresa} 😊 Recebi seu contato pelo site sobre {produto}. Como posso te ajudar? Já te passo os valores e as condições.'],
        ['nome' => 'Follow-up 1', 'dias' => 1, 'texto' => 'Oi {cliente}, tudo bem? É {vendedor}, da {empresa}. Conseguiu ver as opções de {produto} que te mandei? Posso separar pra você ainda hoje.'],
        ['nome' => 'Follow-up 2', 'dias' => 3, 'texto' => 'Oi {cliente}! Passando pra saber se você ainda tem interesse em {produto}. Posso te ajudar com alguma dúvida?'],
        ['nome' => 'Follow-up 3', 'dias' => 7, 'texto' => 'Oi {cliente}, aqui é {vendedor}, da {empresa}. Não quero te incomodar 🙂 Quando quiser retomar a conversa sobre {produto}, é só me chamar por aqui!'],
    ],
];
