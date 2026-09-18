# Linkagem contextual

Execute na pasta do plugin:

```powershell
C:/xampp/php/php.exe tests/contextual-links.php
C:/xampp/php/php.exe tests/analysis-model.php
```

Os testes usam as funções de formatação do WordPress local, posts em memória e transporte de IA simulado. Não acessam o banco, não publicam posts e não consomem créditos.

O primeiro cobre seleção e aplicação: HTML/Gutenberg preservados, formatação inline, entidades, acentos, âncoras literais, limites, duplicados, destinos protegidos, busca por termos, falhas de API/gravação e edições concorrentes. O segundo isola a classe real do gerador do bootstrap do plugin e verifica o modelo e o contrato enviados ao transporte HTTP, além das configurações.

## Uso no painel

- Em **Content Rank → Configurações**, ajuste **Modelo de análise** (padrão: `gpt-4.1-mini`). Planejamento e linkagem usam esse modelo; redação e SEO mantêm o modelo padrão.
- No formulário de cada gerador, **Links internos contextualizados** vem desativado. Selecione **Sim, inserir até 4 links por conteúdo** para ativar somente naquele gerador. A escolha é preservada ao editar, duplicar, exportar e importar. A etapa roda ao final de cada nova geração, inclusive na pipeline em etapas. Falhas da linkagem são registradas sem impedir a conclusão do artigo.
- Para testar um conteúdo existente: **Content Rank → Sugestões de links**, escolha o post, clique em **Gerar sugestões**, confira os resultados e clique em **Aplicar links**.

O PHP extrai até 8 termos específicos dos metadados e do título, removendo palavras editoriais genéricas. Ele consulta posts publicados ou em rascunho, sem senha, mas só mantém resultados que tenham correspondência desses termos no título; uma ocorrência apenas no corpo é descartada. No máximo 20 títulos candidatos seguem para uma chamada ao modelo.

A IA recebe os títulos e os parágrafos em texto e retorna apenas objetos com post_id, anchor e paragraph. paragraph é o parágrafo completo copiado literalmente e serve para o PHP localizar a posição; somente anchor, uma expressão curta que também precisa aparecer no título candidato, vira o texto do link. O endereço é obtido pelo PHP com get_permalink(post_id). Sem candidatos realmente relacionados ou sem parágrafos elegíveis, não há chamada à IA.

O limite é de até 4 novos links por execução, um por destino e por parágrafo. A primeira versão trabalha em elementos `<p>` sem links, controles, mídia ou shortcodes. Frases ambíguas ou que exigiriam quebrar a formatação são descartadas. Mudanças no conteúdo após a análise exigem gerar novas sugestões. Planos salvos pela versão antiga também precisam ser gerados novamente.

**Limpar sugestões** apaga o plano salvo; não remove links já aplicados. Para desfazer alterações no conteúdo, use as revisões do WordPress quando habilitadas.
