# Planejamento de Posts Automaticos em Redes Sociais

## 1. Diagnostico do plugin

O Content Rank ja possui uma base aproveitavel para este projeto:

- gera e organiza conteudo para WordPress;
- trabalha com posts pilares, satelites e planejamento editorial;
- possui execucao automatica por cron;
- ja lida com imagens e videos no fluxo de artigos;
- pode fornecer o post-base e seus dados para a camada social.

Hoje ele nao publica nativamente em Facebook, Instagram, YouTube ou WhatsApp. A publicacao social deve ser criada como um modulo separado, consumindo o conteudo existente sem alterar o fluxo principal de artigos.

O campo de video que ja existe nos geradores extrai videos de uma fonte RSS. Ele nao representa uma biblioteca de videos enviados pelo usuario. A nova fonte `videos` deve ser criada separadamente para o fluxo de redes sociais.

## 2. Objetivo do novo modulo

Adicionar ao gerador de conteudo uma camada de redes sociais. Ao criar ou agendar um conteudo editorial, o sistema tambem deve poder criar automaticamente os pacotes sociais correspondentes, colocando-os em uma fila para revisao opcional, agendamento e publicacao.

Nao sera necessario selecionar manualmente cada post para criar um carrossel. A selecao manual deve existir apenas como excecao, para regenerar ou corrigir um pacote especifico.

Exemplo:

> Conteudo-base: "5 filmes de aventura"
>
> Saida: carrossel com capa, um filme por slide, informacoes curtas e CTA final.

O mesmo conteudo podera futuramente gerar video vertical, imagem simples e legendas especificas para cada plataforma.

## 3. Prioridade recomendada

### Fase 1: carrossel baseado no conteudo existente

Este deve ser o primeiro formato porque:

- usa diretamente titulo, resumo, subtitulos e itens do post;
- nao exige edicao de video;
- funciona bem no Instagram e no Facebook;
- permite revisar cada slide antes da publicacao;
- se aproxima do formato de webstory, mas distribuido como imagens em sequencia.

### Fase 2: video com texto sobreposto

O video exige processamento de arquivo, conversao de proporcao, renderizacao de texto, armazenamento e validacao de direitos autorais. Deve ser implementado depois que o fluxo de carrossel estiver funcionando.

### Fase 3: YouTube Shorts e outros destinos

O mesmo video vertical pode ser enviado ao YouTube como Short, desde que atenda as regras atuais de duracao, proporcao e conta/API. A integracao deve ser opcional e independente das publicacoes Meta.

### Fora do MVP: grupo de WhatsApp

A publicacao automatica em grupos nao deve ser tratada como equivalente a Facebook e Instagram. O canal exige uma solucao oficial e permissao especifica; automacao por WhatsApp Web ou navegador e fragil, pode quebrar e pode violar regras da plataforma. Inicialmente, gerar um pacote para compartilhamento manual e suficiente.

## 3.1. Nova fonte: Videos

Videos devem ser uma fonte selecionavel nos geradores, assim como RSS e KW Lists. O video precisa ser um registro reutilizavel, associado a um post WordPress, e nao apenas um upload temporario dentro da tela social.

### Tela de cadastro da fonte

A tela de Videos deve permitir:

- fazer upload do arquivo original;
- informar titulo ou nome interno;
- selecionar o post WordPress relacionado;
- opcionalmente selecionar o trecho inicial e final;
- informar origem e autorizacao de uso;
- visualizar duracao, dimensoes e proporcao;
- salvar o video como item disponivel para os geradores.

O post relacionado e a base textual do video. A partir dele, o sistema gera gancho, textos sobrepostos, legenda, CTA e URL. O upload nao precisa criar um artigo novo.

### Uso nos geradores

O gerador tera `source_type = videos` e podera configurar:

- formatos de saida;
- conversao automatica para 9:16;
- estrategia de reenquadramento;
- template de texto;
- publicacao imediata ou agendada;
- aprovacao obrigatoria ou opcional;
- contas de destino.

Quando o gerador rodar, ele busca os videos disponiveis dessa fonte, cria um item de fila para cada video ainda nao processado e vincula o post relacionado. O mesmo video nao deve ser publicado duas vezes no mesmo destino, salvo quando houver um novo ciclo solicitado.

## 4. Fluxo principal do carrossel

### Entrada automatica

O gerador deve criar o pacote social a partir do planejamento editorial, sem exigir uma selecao post a post:

1. o plano gera ou publica o conteudo-base;
2. a regra social identifica que o tema e adequado para carrossel;
3. o sistema cria o roteiro, busca as imagens e monta os slides;
4. o pacote entra na fila social com a data prevista;
5. o usuario pode aprovar, editar, reagendar ou deixar a publicacao automatica.

As regras devem permitir configurar, por gerador:

- quais formatos podem ser criados;
- quais plataformas recebem cada formato;
- intervalo entre artigo e post social;
- dias e horarios permitidos;
- necessidade de aprovacao antes de publicar;
- limite diario por plataforma;
- quantidade de slides;
- template visual.

### Interpretacao do conteudo

O sistema extrai do post:

- titulo e promessa principal;
- introducao e resumo;
- subtitulos;
- listas numeradas ou com marcadores;
- entidades importantes, como nomes de filmes;
- imagem de destaque e imagens relacionadas;
- URL canonica do post.

Para um artigo com itens, cada item deve virar uma unidade de slide. Exemplo para "5 filmes de desenho que nao sao so para criancas":

- Slide 1: "Esses 5 filmes de desenho nao sao so para criancas";
- Slide 2: filme 1, capa do filme 1 e descricao breve;
- Slide 3: filme 2, capa do filme 2 e descricao breve;
- Slide 4: filme 3, capa do filme 3 e descricao breve;
- Slide 5: filme 4, capa do filme 4 e descricao breve;
- Slide 6: filme 5, capa do filme 5 e descricao breve;
- Slide 7: CTA para ler o conteudo completo.

Se o artigo nao tiver itens claros, a IA deve propor uma divisao em 5 a 8 telas. O usuario pode editar o texto, mas isso nao deve ser obrigatorio para que o pacote seja gerado.

### Como obter a capa de cada filme

O gerador nao deve procurar capas genericamente no Pexels. Primeiro ele deve extrair os nomes dos filmes e resolver cada titulo em uma base estruturada de filmes, preferencialmente TMDB:

1. extrair o titulo do item;
2. pesquisar o filme por titulo, ano e idioma;
3. confirmar o resultado com ano, tipo e sinopse;
4. obter `poster_path` e dados do filme;
5. baixar a imagem para a biblioteca de midia do WordPress;
6. guardar o ID do filme, a URL original, a fonte e os dados de atribuicao;
7. usar a capa baixada na composicao do slide.

Quando houver mais de um resultado, o sistema deve escolher pelo ano citado no artigo ou colocar o item em `aguardando_conferencia`, em vez de publicar a capa errada. Se nao houver capa autorizada ou resultado confiavel, deve usar uma arte alternativa, como fundo gerado ou imagem licenciada, e registrar o motivo.

O TMDB documenta a busca de filmes por titulo e a montagem da URL da imagem a partir de `base_url`, tamanho e `poster_path`; a chave da API e os termos de uso devem ser configurados no plugin. A capa de um filme continua sendo um material de terceiros, portanto a fonte e a permissao de uso precisam ser consideradas antes da publicacao.

### Pacote gerado

Para cada carrossel:

- imagem de cada slide;
- legenda da plataforma;
- CTA;
- hashtags ou palavras-chave;
- URL do artigo;
- ordem dos slides;
- texto alternativo, quando suportado;
- status de revisao e publicacao.

### Publicacao

O Instagram deve receber as imagens como carrossel. O Facebook pode receber o conjunto de imagens e a legenda com o link do artigo. O link deve permanecer na legenda, pois o Instagram nao oferece o mesmo comportamento de link clicavel no texto comum do feed.

## 5. Fluxo do video enviado pelo usuario

### Experiencia desejada

O fluxo deve ser simples:

1. fazer upload de um video, inicialmente mesmo que esteja em 16:9;
2. selecionar o post sobre o qual o video vai falar;
3. escolher o trecho ou aceitar o video completo;
4. gerar o texto com base no post;
5. revisar a capa, o texto e o link;
6. renderizar a versao vertical;
7. escolher os destinos;
8. publicar ou agendar.

### Saidas do video

O arquivo original deve ser preservado. A partir dele, o sistema pode criar:

- versao vertical 9:16 para Reels, Facebook Reels e YouTube Shorts;
- eventualmente uma versao quadrada ou horizontal, se houver necessidade;
- capa vertical com headline;
- legenda especifica por plataforma;
- link do post para o Facebook e para campos permitidos pela plataforma.

### Conversao de 16:9 para 9:16

Nao basta esticar o video. O renderizador deve oferecer uma estrategia:

- corte central, quando o assunto esta no centro;
- reenquadramento manual por pontos inicial e final;
- fundo ampliado e desfocado, preservando o video horizontal;
- faixa superior ou inferior para texto;
- opcionalmente, deteccao de rosto ou objeto para manter o foco.

O texto deve ser renderizado em blocos curtos, com tempo de entrada e saida. O video final precisa respeitar uma area segura para nao ficar escondido por botoes e legendas das redes.

### Links por plataforma

- Facebook: pode publicar o video com legenda e URL do post.
- Instagram: o video pode ser publicado como Reel, mas o link deve ser tratado principalmente por CTA na legenda, bio ou recurso de link disponivel na conta.
- YouTube: a versao vertical pode ser enviada como Short, com titulo, descricao e URL quando permitido.

## 6. Direitos autorais e seguranca do conteudo

Adicionar texto e cortar um trecho nao garante que o video esteja liberado para uso. O sistema nao deve prometer que uma edicao evita bloqueios, reivindicacoes ou remocoes.

O modulo deve exigir uma confirmacao de origem do arquivo:

- video proprio;
- video licenciado;
- material de dominio publico ou com permissao documentada;
- material autorizado pelo titular.

Tambem deve guardar a fonte e a observacao de licenca. Como medida de risco, o sistema pode priorizar videos de apoio licenciados, banco de midia, capturas autorizadas e imagens geradas, em vez de trechos de filmes comerciais.

## 7. Como editar video dentro do plugin

E tecnicamente possivel editar o video a partir do WordPress, mas o processamento nao deve acontecer durante uma requisicao comum do painel. A arquitetura recomendada e:

- o plugin salva o upload;
- cria um job de renderizacao;
- o cron ou uma fila executa o job;
- um renderizador baseado em FFmpeg cria o arquivo final;
- o painel mostra progresso, erro ou resultado;
- o arquivo renderizado e salvo na biblioteca de midia.

Antes de implementar, verificar se o servidor possui FFmpeg, memoria, espaco em disco e tempo de execucao suficientes. Em hospedagem limitada, o processamento deve ser enviado para um servico externo de renderizacao.

## 8. Arquitetura proposta

Criar um modulo social com responsabilidades separadas:

- `social-video-source`: registra uploads, post relacionado, direitos e status de uso;
- `social-content`: cria o pacote a partir do post-base;
- `social-assets`: monta imagens de carrossel e imagem simples;
- `social-video`: valida upload, cria roteiro e solicita renderizacao;
- `social-queue`: agenda e controla tentativas;
- `social-publishers`: integra Facebook, Instagram e YouTube;
- `social-admin`: telas de selecao, revisao e historico;
- `social-logs`: registra publicacoes e erros.

Cada item da fila deve guardar, no minimo:

- `source_id`;
- `source_item_id`;
- `source_post_id`;
- `platform`;
- `format`;
- `caption`;
- `canonical_url`;
- `asset_ids` ou `video_id`;
- `status`;
- `scheduled_at`;
- `published_at`;
- `external_post_id`;
- `error_message`;
- `rights_confirmation`.

## 9. Roadmap de implementacao

### Marco 1: geracao automatica de carrossel

- adicionar regras sociais ao planejamento editorial;
- criar automaticamente um pacote quando o conteudo-base for gerado;
- extrair itens e subtitulos;
- resolver titulos de filmes e buscar capas;
- gerar textos dos slides;
- montar imagens com template fixo;
- colocar o pacote na fila com data calculada;
- permitir edicao ou aprovacao sem bloquear a automacao;
- salvar os assets na biblioteca de midia.

### Marco 2: fila e publicacao Meta

- criar fila social;
- adicionar agendamento;
- conectar pagina do Facebook;
- conectar conta profissional do Instagram;
- publicar imagem unica e carrossel;
- registrar retorno e erros da API.

### Marco 3: fonte de videos e video vertical

- criar a tela de fonte `Videos`;
- permitir upload e associacao com post WordPress;
- disponibilizar `videos` na lista de fontes dos geradores;
- upload e validacao do video;
- selecao opcional do trecho;
- roteiro de textos;
- renderizacao FFmpeg em job assincrono;
- revisao da capa e do arquivo final;
- publicacao como Reel e video do Facebook.

### Marco 4: YouTube Shorts

- conectar canal do YouTube;
- gerar titulo e descricao;
- enviar o mesmo asset vertical;
- registrar ID e status do video.

### Marco 5: WhatsApp

- avaliar primeiro compartilhamento manual;
- somente depois estudar uma API oficial e o modelo de permissao;
- nao automatizar grupos por navegador como base do produto.

## 10. Decisao de produto

O primeiro entregavel deve ser uma regra dentro do planejamento editorial: sempre que um conteudo de lista for gerado, o plugin cria automaticamente um carrossel com capa, um item por slide, imagens correspondentes, CTA, legenda e data de publicacao.

O exemplo "5 filmes de desenho que nao sao so para criancas" deve gerar sete slides: uma abertura, cinco filmes com suas capas e descricoes curtas, e um CTA final. A selecao manual fica como ferramenta de excecao e revisao, nao como fluxo principal.

Depois disso, o video deve ser tratado como uma segunda linha de produto: upload, selecao do post, geracao de textos, conversao 16:9 para 9:16, revisao, renderizacao e publicacao opcional no YouTube.
