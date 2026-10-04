# E02 — Plataforma base de agentes

Estado da entrega E02 (secção 19 da [especificação](SPEC.md)), com as decisões de [DECISOES.md](DECISOES.md): agentes genéricos definidos pelo super admin e orçamento de IA no perfil do tenant.

## Checklist

- [x] `agents`, `skills`, `agent_skill`, `agent_routines`, `agent_runs`, `agent_run_steps`, `approvals`, `budget_events` (e `audit_logs` da E01)
- [x] Agente genérico (`GenericAgent`), `AgentRunner`, `InstructionComposer`, `ToolResolver` — em vez de seis classes fixas
- [x] `GatedTool` + níveis N0–N4 + tecto absoluto (`config/autonomy.php`)
- [x] Memória: `knowledge_items`, ingestão (texto ou documento), chunking, embeddings, `memory.search`, pesquisa por palavras quando não há embeddings
- [x] Hierarquia, departamentos, afectação de agentes a pessoas
- [x] Eventos Reverb e canais privados autorizados por tenant
- [x] Consola: lista de agentes, ficha, linha temporal ao vivo, fila de aprovações, suspender e reactivar
- [x] `mailboxes` + envio SMTP pela caixa do agente (`comms.send_email`)
- [x] Medição de custo por execução e tectos com corte automático (tenant, agente, execução)
- [x] Consola do super admin: organizações, perfil e orçamento, agentes, skills e rotinas
- **Pronto quando:** `php artisan agents:demo micomoc` põe um agente N1 a ler clientes do ERP falso e a tentar criar uma lead (risco N3). A tentativa vira aprovação, que aparece em **/approvals** e na linha temporal em **/runs/{id}**. Ao aprovar, a lead é criada no ERP e a execução fica concluída. Tudo fica em `audit_logs`. Coberto por `tests/Feature/Agents/DemoFlowTest.php`.

## Como está montado

```
Super admin (admin.<domínio central>)  →  agents (linha) + skills + rotinas
AgentRunner::dispatch()  →  fila agents-high | agents | agents-low  →  RunAgent
  └─ GenericAgent (laravel/ai), configurado pela linha do agente
       ├─ InstructionComposer   identidade, personalidade, instruções, regra do nível, regras da plataforma
       └─ ToolResolver          skills activas e disponíveis do tenant + memory.search, cada uma num GatedTool
            └─ GatedTool  →  AutonomyGate (tecto absoluto → leitura → nível ≥ risco)
                 ├─ permitido  → SkillExecutor → ErpGateway (auditado) ou skill local
                 └─ bloqueado  → ApprovalService::request → Approval + evento
Humano aprova  →  ExecuteApprovedAction  →  executa os argumentos guardados, uma só vez  →  fecha a execução
```

- **Skills:** as do ERP vêm do servidor MCP (`skills:sync` ou o botão no super admin); as locais estão em `App\Ai\Skills\Local`. Cada skill tem um risco (o nível de que um agente precisa para a usar sem aprovação); o super admin muda-o por tenant.
- **Tecto absoluto:** pagamentos, emissão de facturas, contratos, pessoas e permissões pedem sempre aprovação. Um email para uma entidade externa nova também.
- **Orçamento:** em `tenants.settings.ai_budget`. Antes de cada execução verifica os tectos mensais; durante, o tecto por execução; depois, regista os 80% e os 100% e suspende ao atingir 100%. Sem preço configurado para o modelo, usa um preço por omissão alto (`AI_FALLBACK_*`).
- **Ao vivo:** eventos `ShouldBroadcastNow` em canais `tenant.{id}.*`. Se o Reverb estiver em baixo, a execução não falha; a consola recarrega por polling quando o Echo não está configurado.
- **Sem chave de IA:** `agents:demo --scripted` simula as respostas do modelo; a memória pesquisa por palavras.

## Comandos

| Comando | O que faz |
|---|---|
| `php artisan admin:create {email}` | Cria um super admin. |
| `php artisan skills:sync {tenant}` | Actualiza o catálogo de skills (locais e ERP). |
| `php artisan agents:demo {tenant} [--scripted]` | Prova da E02 (acima). |
| `php artisan agents:run-routines` | Despacha as rotinas na hora (agendado a cada minuto). |
