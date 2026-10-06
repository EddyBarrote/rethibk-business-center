# E03 — Email e triagem

Estado da entrega E03 (secção 19 da [especificação](SPEC.md)), com a entrada por IMAP decidida em [DECISOES.md](DECISOES.md).

## Checklist

- [x] `email_threads`, `email_messages`, `email_attachments`, `tenders`, `follow_ups`
- [x] Entrada por IMAP (`mail:fetch`, a cada minuto) e por ficheiro (`mail:ingest`), guardando o bruto e deduplicando por `Message-ID` e hash
- [x] Extracção de texto dos anexos (PDF, Word, Excel, texto; OCR quando o Tesseract existe)
- [x] Fios de conversa por `In-Reply-To`/`References` e assunto
- [x] Agente de triagem (modelo `triage`): classifica, extrai campos, define prioridade e prazo, encaminha e notifica
- [x] Passagem automática ao agente da área: facturas e extractos → Finanças, cotações → Compras, candidaturas → RH, pedidos de clientes → Gestor de clientes
- [x] Leads no ERP a partir de emails comerciais, ligadas ao email (`erp_lead_id`)
- [x] Rascunhos de resposta que uma pessoa revê e envia na consola; envio pela caixa do agente com `In-Reply-To`
- [x] Defesa contra *prompt injection*: o conteúdo externo vai marcado como não confiável e os pedidos do email não mudam as regras do agente
- [x] Concursos: fontes por tenant, leitura de hora a hora, deduplicação por URL, registo e prazo
- [x] Seguimentos agendados pelos agentes, que os acordam quando vencem
- [x] Consola: Caixa (lista e email com anexos, rascunho, reclassificar, voltar a triar), Concursos, Notificações
- [x] Retenção do bruto configurável (`mail:prune`, 365 dias por omissão)
- **Pronto quando:** um email enviado para a caixa da triagem aparece classificado, com a lead criada no ERP e a pessoa certa notificada. Coberto por `tests/Feature/Email/TriageFlowTest.php` e `IngestionTest.php`, com os emails de exemplo de `tests/Fixtures/mail` (também em `docs/exemplos`).

## Como está montado

```
IMAP (mail:fetch) ou .eml (mail:ingest)
  └─ InboundEmailIngestor  bruto → disco, dedup, fio, anexos (+ texto)
       └─ ProcessInboundEmail (fila "email")  →  agente da caixa (normalmente a triagem)
            └─ email.read → email.classify → (erp.leads.create | email.draft_reply | followups.schedule …)
                 └─ ClassifyEmail notifica a pessoa e passa o email ao agente da área (AgentDirectory::forRole)
```

## Comandos

| Comando | O que faz |
|---|---|
| `php artisan mail:fetch` | Lê as caixas activas por IMAP (agendado a cada minuto). |
| `php artisan mail:ingest {tenant} {caixa} {ficheiro.eml}` | Injecta um email, como se tivesse chegado por IMAP. |
| `php artisan mail:prune` | Apaga o bruto mais antigo do que a retenção de cada tenant. |
| `php artisan agents:scan-tenders` | Lê as fontes de concursos (de hora a hora). |
| `php artisan agents:watch-deadlines` | Avisa dos prazos das próximas 48 h (de hora a hora). |
| `php artisan followups:notify` | Avisa e acorda os agentes nos seguimentos vencidos (a cada 5 minutos). |
