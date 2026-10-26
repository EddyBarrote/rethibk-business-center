# Guião da demo MICOMOC (27.10.2026)

Tenant **micomoc**: http://micomoc.localhost:8000, entrar como `owner@micomoc.test` (palavra-passe local `password`).
Ensaiado de ponta a ponta a 26.10 com o Gemini real. Nenhum email sai (`MAIL_MAILER=log`).

## Antes de começar (5 min antes)

1. O `composer dev` tem de estar a correr (servidor, Horizon, Reverb, Vite). Abrir http://micomoc.localhost:8000.
2. Ter um terminal aberto em `~/dev-projects/claude-code/business-center/micomoc` para o passo 2.
3. Em **Definições › Integrações › ERP**, carregar em «Testar ligação»: deve dizer *38 ferramentas disponíveis*.

## 1. MCP funcional (o ERP por MCP) — 3 min

- Mostrar **Definições › Integrações › ERP**: o ERP liga-se por MCP; as 38 ferramentas são as capacidades que os agentes
  recebem (clientes, oportunidades, projectos, fornecedores, pedidos de cotação…). «Actividade» mostra cada chamada auditada.
- Abrir a conversa com o **Gestor de Clientes** e escrever:
  > Que projectos temos com o Hotel Baía Azul, em que estado estão e quanto já se gastou em cada um?
- Resposta esperada (~30 s), lida do ERP: *Remodelação da ala norte* (em curso, 3 120 500 MZN de 7 400 000) e
  *Painéis solares da lavandaria* (planeado, 0 de 1 850 000). Abrir «Pensou durante…» para mostrar as chamadas ao ERP.

## 2. Triagem de um pedido por email — 2 min

No terminal:

```bash
php artisan mail:ingest micomoc triagem@agentes.micomoc.test docs/demo/pedido-cotacao-agro-zambeze.eml
```

- Ir a **Email › Triagem**: o email de Faizal Abdul (Agro Zambeze) a pedir cotação de cimento, varão e EPI.
- Em ~30 s fica classificado como **Pedido de cotação de cliente**, prioridade, resumo, prazo (5 de Novembro) e os campos
  extraídos. O sino mostra «Nova tarefa».

## 3. Passagem ao Gestor de Clientes — 2 min

- A triagem passou o email ao **Gestor de Clientes** pelo fluxo activo «Responder a pedido de cotação»: abrir a tarefa
  em **Tarefas**. O painel do fluxo mostra os blocos a avançar: ler o pedido → já é cliente? (sim, ACC-0003 no ERP) →
  actualizar a ficha → registar a oportunidade no ERP → para cada grupo de materiais a cotar.
- Mostrar o mapa em **Definições › Fluxos de trabalho** (canvas e lista).

## 4. Passagem ao Agente de Procurement — 3 min

- Para cada grupo de materiais, o fluxo abre uma tarefa para o **Agente de Procurement** («Pedir cotação ao fornecedor · …»).
- O Procurement escolhe no ERP os fornecedores que existem (Cimentos do Púnguè para o cimento, Ferragens Matola / Aço e
  Varão do Centro para o varão, Segurança Total EPI para o EPI) e prepara o pedido de cotação, **sem preços**.
- Criar o pedido no ERP está acima do nível dele: vai ao **Chief of Staff**, que revalida e aprova (tarefa «Revalidar…»),
  e o pedido fica criado no ERP. Mostrar em **Aprovações**.
- Do email até aos três pedidos no ERP (cimento, varão, EPI) são ~5 min. Enquanto corre, falar do passo 5.
- No ensaio de 26.10 com este mesmo email: RFQ de cimento à Cimentos do Púnguè, de varão à Ferragens Matola e à Aço e
  Varão do Centro, de EPI à Segurança Total EPI, cada um revalidado pelo Chief of Staff.

## 5. Chief of Staff sugere fluxos — 3 min

- Abrir a conversa com o **Chief of Staff** e escrever:
  > Olhando para os emails que estão a chegar, que fluxos de trabalho sugeres para automatizar? Prepara o mais útil.
- Ele vê os tipos de email e quem os trata (`workflows.overview`), diz porquê, e guarda o fluxo como **rascunho** com a
  ligação para o editor (no ensaio: «Processar factura de fornecedor», com o Agente de Finanças). Abrir a ligação:
  os blocos estão no canvas; o fluxo só corre depois de uma pessoa o **activar**.
- Extra: em **Definições › Fluxos de trabalho › Novo**, «Descreva o fluxo» desenha um fluxo a partir de uma frase, por
  exemplo: *«Quando chega uma candidatura, o Agente de RH compara com as vagas abertas e marca entrevista com o
  responsável da área.»*

## Se algo falhar

- **Sem internet não há agentes.** A 26.10, entre 15:06 e 15:28, o Mac perdeu o DNS («Could not resolve host») e a
  triagem falhou; as repetições automáticas (3×, ~10 s) não chegam para uma quebra longa. Testar a rede antes.
- Email com triagem falhada: abrir o email e carregar em **«Triar de novo»**.
- Nada acontece depois do `mail:ingest`: confirmar que o Horizon está a correr no `composer dev` e correr
  `php artisan queue:restart`.
- Os ensaios deixaram na Triagem três emails iguais (dois do Hotel Baía Azul, um da Agro Zambeze, tarefas canceladas);
  a triagem pode dizer que o pedido parece repetido. O ERP de demonstração foi reposto como estava antes dos ensaios.
- Repetir o passo 2: mudar o `Message-ID` do ficheiro (ex.: `-2` no fim), ou a plataforma trata-o como o mesmo email.
