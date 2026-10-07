# Quando usar
Sempre que fores gerar um ficheiro com «Gerar documento» (Word, PDF, PowerPoint ou Excel) que vá ser lido por alguém: um cliente, um fornecedor, a direcção ou outra equipa. Também quando te pedirem para rever um documento antes de o gerar.

# A marca
Estes são os valores de hoje em Definições › Empresa › Marca. A plataforma aplica-os sozinha ao ficheiro; tu não escreves cores nem fontes no markdown.

{{marca}}

Por isso:
- Não ponhas o nome da organização, o logótipo, a data nem o rodapé no conteúdo: a capa, o cabeçalho e o rodapé já os têm.
- Não repitas o título como primeira linha: passa-o em `title`.
- Se o logótipo disser «nenhum carregado», gera na mesma e diz a quem pediu que a Marca ainda não tem logótipo.

# Escolher o formato e o modelo
| Para quê | format | template |
|---|---|---|
| Proposta, relatório, análise para a direcção | pdf (ou docx se a pessoa o for editar) | relatorio (com capa) |
| Nota interna, procedimento, acta, resumo | docx ou pdf | documento |
| Carta ou ofício a uma entidade | docx ou pdf | carta |
| Apresentação | pptx | (não se aplica) |
| Tabelas, listas de preços, mapas de valores | xlsx | (não se aplica) |

Usa `subtitle` para o departamento, o cliente ou o período (ex.: «Direcção Financeira · Setembro de 2026»).

# Estrutura
1. Abre com um parágrafo curto que diga o essencial: a conclusão, o pedido ou a decisão. Quem só ler isso tem de ficar a saber o que importa.
2. Secções com títulos `##` curtos e concretos («Preços propostos», não «Secção 3»). Subtítulos `###` só quando uma secção é longa.
3. Números em tabela sempre que houver mais de três valores. Primeira coluna o nome, depois os valores; totais na última linha a negrito.
4. Fecha com os próximos passos: quem faz o quê e até quando.
5. Numa apresentação, um diapositivo por `##`, no máximo seis linhas cada, frases curtas.

# Escrita
- Português de Moçambique (pt-MZ), com a mesma ortografia do princípio ao fim.
- Tom profissional e directo, na terceira pessoa ou em nome da organização («A {{marca.nome}} propõe…»). Sem exclamações nem adjectivos de venda.
- Valores em meticais: `1 250 000,00 MT`. Datas por extenso: «7 de outubro de 2026».
- Siglas por extenso na primeira vez: «Imposto sobre o Valor Acrescentado (IVA)».
- Não inventes dados. O que não souberes fica assinalado como «[a confirmar]» e dizes a quem pediu o que falta.

# Nunca
- Nunca envies o ficheiro para fora da plataforma por tua iniciativa: gera-o e entrega a ligação.
- Nunca incluas dados pessoais ou valores de outros clientes que não sejam o destinatário.
