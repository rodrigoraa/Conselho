# Relatório de importação curricular do APC

## Execução e fontes

- Data da extração e conferência: **11/08/2026**.
- Ensino Fundamental: *Currículo de Referência de Mato Grosso do Sul — Educação Infantil e Ensino Fundamental — versão 1.10*, SED/MS: `https://www.sed.ms.gov.br/wp-content/uploads/2020/02/curriculo_v110.pdf`.
- Ensino Médio: *Currículo de Referência de Mato Grosso do Sul — Ensino Médio — versão 1.1*, SED/MS: `https://www.sed.ms.gov.br/wp-content/uploads/2022/01/Curriculo-Novo-Ensino-Medio-v1.1.pdf`.
- Referência institucional: `https://www.sed.ms.gov.br/informativos/guias-e-manuais/`.
- TVT: páginas 82–85, 161–164 e 231–233 da *Matriz de Habilidades Essenciais* claramente identificada como material SED/MS. A cópia pública consultada estava em `https://pt.slideshare.net/slideshow/matriz-de-habilidades-essenciais-sedmspdf/256824491`; não foi localizada, durante esta execução, uma URL pública estável do arquivo integral no domínio da SED.

Os dois PDFs curriculares foram processados somente no ambiente de desenvolvimento pelo script `tools/curriculo/extract_ms.py`, com extração posicional das colunas. Páginas representativas de Língua Portuguesa, Matemática, Ciências e das áreas do Ensino Médio foram renderizadas e conferidas visualmente. A aplicação em produção não precisa dos PDFs, de Python nem de acesso à internet: ela importa os CSVs versionados e consulta o `apc.db`.

## Resultado importável

| Conjunto | Habilidades únicas |
|---|---:|
| Ensino Fundamental — currículo SED/MS v1.10 | 1.634 |
| Ensino Médio — currículo SED/MS v1.1 | 382 |
| TVT — matriz essencial/recomposição | 29 |
| **Total** | **2.045** |

O arquivo TVT possui 54 linhas de associação para 29 habilidades únicas porque registra separadamente ano curricular e ano recomendado para recomposição quando a fonte permite distingui-los. Após a importação, o catálogo contém 36 componentes por etapa e 2.701 relações habilidade × ano/série × tipo de associação.

### Ensino Fundamental por componente

| Componente | Quantidade |
|---|---:|
| Arte | 70 |
| Ciências | 117 |
| Educação Física | 132 |
| Ensino Religioso | 66 |
| Geografia | 130 |
| História | 171 |
| Língua Espanhola | 145 |
| Língua Inglesa | 156 |
| Língua Portuguesa | 391 |
| Matemática | 256 |

Associações por ano: EF1 178; EF2 200; EF3 225; EF4 205; EF5 220; EF6 304; EF7 307; EF8 313; EF9 311. Uma habilidade pode contar em mais de um ano, por isso essa soma é maior que o total de habilidades únicas.

### Ensino Médio por componente

| Componente | Quantidade |
|---|---:|
| Arte | 19 |
| Biologia | 23 |
| Educação Física | 17 |
| Filosofia | 31 |
| Física | 44 |
| Geografia | 31 |
| História | 31 |
| Língua Espanhola | 16 |
| Língua Inglesa | 17 |
| Língua Portuguesa | 70 |
| Matemática | 26 |
| Química | 26 |
| Sociologia | 31 |

Associações por série indicadas no documento: EM1 120; EM2 125; EM3 139. A série não foi inferida a partir de códigos `MS.EM13...`; foi obtida dos blocos seriados do próprio documento.

## Proveniência e normalização

Os registros gerais usam `CURRICULO_REFERENCIA_MS_EF_V1_10` ou `CURRICULO_REFERENCIA_MS_EM_V1_1`. TVT usa exclusivamente `SED_MS_MATRIZ_HABILIDADES_ESSENCIAIS` e escopo `ESSENCIAL_RECOMPOSICAO`. Código, descrição, unidade/prática, objeto de conhecimento, documento e página ficam em colunas separadas. Códigos estaduais conservam o prefixo `MS.` e particularidades tipográficas visíveis na fonte.

Foram removidos apenas artefatos estruturais do PDF — cabeçalhos/rodapés de tabela, paginação isolada, espaços repetidos e hifens inseridos unicamente por quebra de linha. Conteúdo curricular duvidoso não foi corrigido silenciosamente.

## Advertências e itens não interpretados

Resultado do extrator geral:

```text
Total importável do Fundamental: 1.634
Total importável do Médio: 382
Advertências: 31
Erros fatais: 0
```

As 31 advertências são:

- 22 casos em que o mesmo código oficial aparece, no mesmo componente, com duas ou mais descrições. Os registros foram preservados separadamente; a chave estável inclui descrição e origem.
- 5 indícios de palavra/hífen que merecem futura conferência manual: `MS.EF04LP04.s.04`, `MS.EM13CNT107` (em três posições seriadas) e `MS.EM13LP126`. Nada foi alterado automaticamente.
- 4 ocorrências não importadas por não ser possível atribuir componente com segurança pela tabela do PDF: página 166 (`MS.EM13LGG103`, `MS.EM13LGG601`, `MS.EM13LGG703`) e página 269 (`MS.EM13CHS206`). Elas permanecem documentadas aqui para conferência futura.

## Situação de TVT

O catálogo TVT desta entrega é **PARCIAL / MATRIZ ESSENCIAL**. Ele não deve ser apresentado como referencial TVT completo. Foram transcritos somente os itens comprováveis nas páginas consultadas, incluindo as associações `CURRICULAR` e `RECOMPOSICAO` que a matriz explicita. Nenhuma habilidade, sequência ou código ausente foi inventado. O schema aceita uma futura fonte oficial completa sem nova mudança estrutural e o Plano de Ação conserva o campo de complemento manual.

## Reprodução

Extração de desenvolvimento (os PDFs não são versionados):

```bash
python tools/curriculo/extract_ms.py \
  --ef /caminho/curriculo_v110.pdf \
  --em /caminho/Curriculo-Novo-Ensino-Medio-v1.1.pdf \
  --output apps/apc/resources/curriculo
```

Importação no servidor, depois da migration:

```bash
sudo -u www-data php scripts/console.php apc-importar-curriculo
```

A importação deve informar 36 componentes, 2.045 habilidades únicas e 2.701 associações. Uma segunda execução deve manter esses totais.
