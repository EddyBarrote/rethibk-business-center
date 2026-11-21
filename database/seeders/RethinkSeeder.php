<?php

namespace Database\Seeders;

use App\Ai\Capabilities\CapabilityCatalog;
use App\Ai\Knowledge\KnowledgeAccess;
use App\Ai\Knowledge\KnowledgeBase;
use App\Ai\Templates\AgentTemplates;
use App\Ai\Templates\TemplateInstaller;
use App\Enums\CapabilitySource;
use App\Enums\CompanySection;
use App\Enums\ErpConnectionStatus;
use App\Enums\ErpTransport;
use App\Enums\KnowledgeType;
use App\Enums\Role;
use App\Erp\ErpGateway;
use App\Models\Agent;
use App\Models\AgentRoutine;
use App\Models\Capability;
use App\Models\CompanyFact;
use App\Models\Department;
use App\Models\ErpConnection;
use App\Models\KnowledgeDomain;
use App\Models\KnowledgeFolder;
use App\Models\KnowledgeItem;
use App\Models\Mailbox;
use App\Models\MailboxOwner;
use App\Models\MailboxReader;
use App\Models\Tenant;
use App\Models\User;
use App\Tenancy\TenantManager;
use App\Tenancy\TenantProvisioner;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * The Rethink Technologies tenant as it was set up on 15–17.10.2026
 * (docs/DECISOES.md, "Rethink Technologies como tenant de teste" and
 * "Produtos e serviços para cotações"): owner, the two colleagues from the
 * ERP, one department, the six template agents (five with Rethink's own
 * descriptions; HR keeps its Director de Recursos Humanos text, with the
 * routines off), read-only ERP, no outgoing email, 22 approved facts and the two
 * Reactive articles. Safe to run again: it fills in what is missing.
 *
 * Secrets stay out of the repository: the Perfex ERP connection is made only
 * when RETHINK_ERP_URL and RETHINK_ERP_TOKEN are set, and the geral@ mailbox
 * is connected in Settings (its owner and readers are set here once it exists).
 */
class RethinkSeeder extends Seeder
{
    private const OWNER_EMAIL = 'ebarrote@rethink.co.mz';

    private const MAILBOX = 'geral@rethink.co.mz';

    public function run(TenantManager $tenants): void
    {
        $tenant = Tenant::query()->where('slug', 'rethink')->first() ?? app(TenantProvisioner::class)->create(
            ['name' => 'Rethink Technologies', 'slug' => 'rethink'],
            ['name' => 'Edy Barrote', 'email' => self::OWNER_EMAIL, 'password' => $this->ownerPassword()],
        );

        $tenants->run($tenant, function (Tenant $tenant) {
            $this->brand($tenant);

            $general = Department::query()->firstOrCreate(['slug' => 'direccao-geral'], ['name' => 'Direcção-Geral']);
            $owner = User::query()->where('email', self::OWNER_EMAIL)->firstOrFail();
            $owner->forceFill(['job_title' => 'CEO', 'department_id' => $general->id])->save();

            // Staff from the ERP (list_staff, 15.10.2026): no role on record, nobody invited.
            foreach (['kmacatange@rethink.co.mz' => 'Kleyton Macatange', 'nairasitoe@rethink.co.mz' => 'Naira Sitoe'] as $email => $name) {
                User::query()->firstOrCreate(['email' => $email], ['name' => $name, 'password' => Str::random(48), 'role' => Role::Member]);
            }

            $this->erp();
            $this->agents($owner, $general);
            $this->readOnly();
            $this->mailbox($owner);
            $this->facts($owner);
            $this->knowledge($owner);
        });

        $this->command->info('Tenant rethink pronto: http://rethink.localhost:8000');
    }

    private function ownerPassword(): string
    {
        $password = config('services.rethink.owner_password');

        if (is_string($password) && $password !== '') {
            return $password;
        }

        if (app()->environment('local', 'testing')) {
            return 'password';
        }

        $this->command->warn('Sem RETHINK_OWNER_PASSWORD: palavra-passe aleatória para '.self::OWNER_EMAIL.'; use «Esqueci-me da palavra-passe».');

        return Str::random(48);
    }

    private function brand(Tenant $tenant): void
    {
        $settings = (array) $tenant->settings;
        $brand = (array) ($settings['brand'] ?? []);
        $logo = $brand['logo_path'] ?? null;

        if (! is_string($logo) || ! Storage::disk('local')->exists($logo)) {
            $logo = "brand/{$tenant->id}-rethink-logo.png";
            Storage::disk('local')->put($logo, (string) file_get_contents(__DIR__.'/rethink/logo.png'));
        }

        // No mail_domain: the agents have no mailboxes of their own.
        $tenant->forceFill(['settings' => [
            ...$settings,
            'email_retention_days' => $settings['email_retention_days'] ?? 365,
            'tender_sources' => $settings['tender_sources'] ?? [],
            'brand' => [
                ...$brand,
                'color' => '#0052CC',
                'font' => 'Space Grotesk',
                'footer' => 'Rethink Technologies, Lda · Maputo · rethink.co.mz · geral@rethink.co.mz',
                'logo_path' => $logo,
            ],
        ]])->save();
    }

    /**
     * The live Perfex ERP (rethink.rethinkerp.online), only with credentials
     * from the environment; without them the tenant runs with no ERP.
     */
    private function erp(): void
    {
        $catalog = app(CapabilityCatalog::class);
        $catalog->syncLocal();

        $url = config('services.rethink.erp_url');
        $token = config('services.rethink.erp_token');

        if (! filled($url) || ! filled($token)) {
            $this->command->warn('Sem RETHINK_ERP_URL e RETHINK_ERP_TOKEN: o tenant rethink fica sem ERP. Defina-os e corra o seeder outra vez.');

            return;
        }

        $connection = ErpConnection::query()->oldest('id')->first() ?? new ErpConnection;
        $connection->fill(['name' => 'Rethink ERP (Perfex)', 'transport' => ErpTransport::Web, 'base_url' => $url, 'auth_type' => 'token']);
        $connection->credentials = ['token' => $token];
        $connection->status = ErpConnectionStatus::Untested;
        $connection->save();

        try {
            app(ErpGateway::class)->test($connection);
            $catalog->syncErp();
        } catch (Throwable $e) {
            $this->command->warn('Não foi possível ler as ferramentas do ERP: '.$e->getMessage());
        }
    }

    private function agents(User $owner, Department $general): void
    {
        foreach (AgentTemplates::all() as $template) {
            app(TemplateInstaller::class)->install($template, $owner);
        }

        // All six in the one department, reporting to the CEO (who also holds «Gerir pessoas e papéis»).
        Agent::query()->whereIn('key', array_keys(AgentTemplates::all()))->update(['department_id' => $general->id, 'reports_to_user_id' => $owner->id]);

        foreach (self::AGENTS as $key => [$description, $suggestions]) {
            Agent::query()->where('key', $key)->first()?->forceFill(['description' => $description, 'suggestions' => $suggestions])->save();
        }

        // One department only: the template ones are structure nobody at Rethink confirmed.
        Department::query()->whereKeyNot($general->id)->whereDoesntHave('users')->get()
            ->reject(fn (Department $department) => Agent::query()->where('department_id', $department->id)->exists())
            ->each->delete();

        // The HR routines need an HR module the Perfex ERP does not have.
        AgentRoutine::query()->whereIn('agent_id', Agent::query()->where('key', 'hr')->select('id'))->update(['is_active' => false]);
    }

    /**
     * Agents only read the ERP and send no email; syncs keep the choice.
     */
    private function readOnly(): void
    {
        Capability::query()->where('source', CapabilitySource::Mcp)->where('is_mutating', true)->update(['is_enabled' => false]);
        Capability::query()->where('key', 'comms.send_email')->update(['is_enabled' => false]);
    }

    /**
     * geral@ is connected in Settings, with its IMAP password; once it is,
     * the CEO owns it, Triagem processes new mail and the Chief of Staff reads.
     */
    private function mailbox(User $owner): void
    {
        $mailbox = Mailbox::query()->where('address', self::MAILBOX)->first();

        if ($mailbox === null) {
            return;
        }

        MailboxOwner::query()->firstOrCreate(['mailbox_id' => $mailbox->id, 'user_id' => $owner->id]);

        foreach (['triage' => true, 'chief_of_staff' => false] as $key => $processesNew) {
            $agent = Agent::query()->where('key', $key)->value('id');

            if ($agent !== null) {
                MailboxReader::query()->firstOrCreate(['mailbox_id' => $mailbox->id, 'agent_id' => $agent], ['processes_new' => $processesNew]);
            }
        }

        $owner->forceFill(['last_mailbox_id' => $owner->last_mailbox_id ?? $mailbox->id])->save();
    }

    private function facts(User $owner): void
    {
        foreach (self::FACTS as [$section, $content]) {
            CompanyFact::query()->firstOrCreate(['content' => $content], [
                'section' => $section,
                'status' => CompanyFact::PUBLISHED,
                'created_by_user_id' => $owner->id,
                'reviewed_by_user_id' => $owner->id,
                'reviewed_at' => now(),
            ]);
        }
    }

    /**
     * Geral › Produtos e serviços › Reactive, read by every agent.
     */
    private function knowledge(User $owner): void
    {
        app(KnowledgeAccess::class)->ensureDefaults();
        $domain = KnowledgeDomain::query()->where('slug', 'geral')->firstOrFail();
        $products = KnowledgeFolder::query()->firstOrCreate(['knowledge_domain_id' => $domain->id, 'parent_id' => null, 'name' => 'Produtos e serviços']);
        $folder = KnowledgeFolder::query()->firstOrCreate(['knowledge_domain_id' => $domain->id, 'parent_id' => $products->id, 'name' => 'Reactive']);

        foreach ([
            'reactive-servicos.md' => ['Reactive: serviços e o que incluem', 'O que a Reactive faz (bilhetes, convites, acessos, cashless, fecho, páginas bio, plataforma de eventos), com a fonte de cada facto.'],
            'reactive-precos.md' => ['Reactive: preços e condições nas fontes', 'Os preços que constam no ERP (catálogo, facturas e cotações) e nas skills internas, e o que nenhuma fonte diz e se pergunta ao CEO.'],
        ] as $file => [$title, $summary]) {
            if (KnowledgeItem::query()->where('title', $title)->exists()) {
                continue;
            }

            app(KnowledgeBase::class)->remember(KnowledgeType::Article, $title, (string) file_get_contents(__DIR__.'/rethink/'.$file), $owner, [
                'summary' => $summary,
                'knowledge_domain_id' => $domain->id,
                'knowledge_folder_id' => $folder->id,
                'visibility' => 'tenant',
                'status' => 'published',
            ]);
        }
    }

    /**
     * @var array<string, array{string, list<string>}>
     */
    private const AGENTS = [
        'triage' => [
            'Lê a caixa geral@rethink.co.mz, classifica cada email (compradores de bilhetes, pedidos de cotação, concursos, candidaturas, fornecedores, spam) e passa-o a quem o trata.',
            ['O que entrou hoje na caixa geral?', 'Há pedidos de reembolso de bilhetes por responder?', 'Que pedidos de cotação chegaram esta semana?', 'Mostra os emails suspeitos de phishing'],
        ],
        'chief_of_staff' => [
            'Braço direito do CEO: briefing do dia, prioridades, o que espera por decisão e seguimento do que ficou combinado.',
            ['Prepara o meu briefing de hoje', 'O que está à espera de uma decisão minha?', 'Resume a semana da Rethink', 'Que prazos tenho nos próximos 7 dias?'],
        ],
        'client_manager' => [
            'Acompanha clientes e compradores: bilhetes não recebidos e reembolsos, pedidos de cotação (Reactive, Wedly, websites e software) e a ficha de cada cliente no ERP.',
            ['Quem são os clientes mais recentes no ERP?', 'Faz a ficha de um cliente', 'Que pedidos de clientes estão em aberto?', 'Prepara a resposta a um pedido de cotação de bilhética'],
        ],
        'finance' => [
            'Facturação, cobranças e fecho do mês a partir do ERP, só em leitura.',
            ['Que facturas estão por cobrar?', 'Resume a facturação deste mês', 'Que pagamentos entraram esta semana?', 'Que despesas foram lançadas este mês?'],
        ],
        'procurement' => [
            'Fornecedores e compras registadas no ERP, só em leitura.',
            ['Lista os fornecedores no ERP', 'Que encomendas a fornecedores estão em aberto?', 'Quando termina o contrato com a TVCabo?', 'Compara fornecedores de material informático'],
        ],
        // 'hr' keeps the Director de Recursos Humanos text of its template (20.11.2026).
    ];

    /**
     * The 22 approved facts of the Memória da empresa, each with its source:
     * the proposals to the MTC (2023) and the FDC (2024), the certidões in
     * «Documentos da empresa», the geral@ mailbox and the ERP.
     *
     * @var list<array{CompanySection, string}>
     */
    private const FACTS = [
        [CompanySection::About, 'A Rethink Technologies, Lda é uma empresa 100% moçambicana de tecnologias de informação, no mercado desde 2019, criada para servir pequenas e médias empresas. Sede em Maputo. (Proposta ao MTC, Julho de 2023)'],
        [CompanySection::About, 'Competências apresentadas pela Rethink: instalação e manutenção de equipamentos; sistemas de segurança e redes TCP/IP; fornecimento de equipamentos informáticos; engenharia e desenvolvimento de software; Big Data e IoT; infraestrutura e redes Cisco; videovigilância CCTV; segurança cibernética. (Proposta ao MTC 2023 e manifestação de interesse ao FDC 2024)'],
        [CompanySection::About, 'Reactive (reactive.rethink.co.mz) é o sistema da Rethink para eventos, em operação desde 2019: venda de bilhetes online e em pontos físicos, pagamentos por proximidade NFC com cartões e fitas, portal do promotor, apps de recarga, de ponto de venda e de controlo de bilhetes por QR e NFC, e pagamentos com M-Pesa, e-Mola e MasterCard. Mais de 15 milhões de MZN transaccionados (dado de 2023/2024).'],
        [CompanySection::About, 'Wedly é a plataforma da Rethink para casamentos: convite digital personalizado partilhável por WhatsApp, página do evento, lista de convidados, RSVP, checklist e planeamento. No ERP existe o artigo «Plataforma Wedly - Pacote Essential» a 6 000 MZN. (Email de 16.09.2026 e ERP)'],
        [CompanySection::About, 'Projectos de referência da Rethink: sistema de gestão de bolsas do programa PEPA (Tsemba, 2021); ERP com vendas, contabilidade, RH, recrutamento, compras, assiduidade e projectos (desde 2022); plataforma de marketing com envio em massa e robôs de SMS e WhatsApp (2023); sistema de gestão escolar (2023); app de entrega de refeições MAKEEAT para Android e iOS (2PC, 2023); portal da Feira Imobiliária FIMAP para a N2N e a CTA (2023-2024). (Manifestação de interesse ao FDC, 2024)'],
        [CompanySection::About, 'Websites feitos pela Rethink, segundo as propostas: rethink.co.mz, reactive.rethink.co.mz, sb.co.mz, ldmarch.co.mz, menisagroup.com, metrobus.co.mz, fimap.co.mz, polanaplaza.com, sirmotors.com, i2aconsultoria.com, lds.co.mz, nolimits.co.mz, ocsida.org e axiainnovations.com.'],
        [CompanySection::About, 'Contactos da Rethink: geral@rethink.co.mz, rethink.co.mz, +258 84 818 5005 (também +258 84 183 1823 e +258 84 670 6248). Sede registada: Rua da Resistência n.º 805 R/C, bairro da Malhangalene, Maputo. A assinatura do CEO em Setembro de 2026 diz Rua da Resistência 820.'],
        [CompanySection::About, 'A Rethink diz que os parceiros são uma das suas maiores riquezas, valoriza relações de longo prazo com fornecedores e clientes, e promete agir com integridade, transparência e honestidade, pedindo o mesmo em troca.'],
        [CompanySection::About, 'A Rethink concorre a concursos públicos: website do MTC (2023), redesenho da página web e consumíveis do FUNAE (2024) e plataforma de gestão de activos do FDC (2024).'],
        [CompanySection::About, 'Os documentos da Rethink usam o azul #0052CC como cor principal e a letra Space Grotesk, com o logótipo na capa.'],
        [CompanySection::About, 'Dados legais: RETHINK TECHNOLOGIES, LIMITADA, sociedade por quotas, NUIT 401126449, número único da entidade legal 101301648, constituída a 05.03.2020 e registada a 03.06.2020, capital social de 20 000 MZN, realizado. (Certidão da Conservatória das Entidades Legais e certidão da Autoridade Tributária, em «Documentos da empresa Rethink (2024)»)'],
        [CompanySection::About, 'Objecto social registado: prestação de serviços e consultoria informática; importação, exportação e distribuição de equipamentos; assistência técnica de equipamentos e sistemas informáticos; publicidade e marketing digital; desenvolvimento de sistemas informáticos; fabrico de equipamentos electrónicos; sistemas de pagamentos digitais.'],
        [CompanySection::About, 'A Rethink está inscrita no Cadastro Único de Fornecedores do Estado (certificado 2024A000021879PE, 25.01.2024) como prestadora de serviços: programação informática, consultoria e programação informática, edição de programas informáticos e gestão e exploração de equipamento informático.'],
        [CompanySection::People, 'Edy Barrote é o proprietário e CEO da Rethink Technologies (ebarrote@rethink.co.mz). Decide propostas, preços e tudo o que sai em nome da empresa.'],
        [CompanySection::People, 'Kleyton Macatange (kmacatange@rethink.co.mz) e Naira Sitoe (nairasitoe@rethink.co.mz) são colaboradores registados no ERP. As funções ainda não estão registadas.'],
        [CompanySection::Clients, 'O ERP tem 83 clientes registados, 72 activos, sobretudo empresas de Maputo e Matola: promotores de eventos e produtoras, restaurantes e bares, hotelaria e algumas instituições (por exemplo CTA, N2N Corporation, SB Entertainment, Menisa, Tempus, PayTech, Pérola acomodação e MICOMOC).'],
        [CompanySection::Clients, 'O único projecto em curso no ERP é o «Sistema de Gestão de Manutenção de Hotel» para a Pérola acomodação.'],
        [CompanySection::Clients, 'A Rethink tem o contrato n.º 2026164132 com a TVCabo para serviços de telecomunicações. A Hostinger é o fornecedor de alojamento e email.'],
        [CompanySection::Clients, 'A Construarte Engenharia e Serviços passou à Rethink uma carta abonatória (28.11.2023) pelos serviços de gestão de dados e administração de redes.'],
        [CompanySection::Rules, 'A caixa geral@rethink.co.mz recebe sobretudo compradores de bilhetes (bilhete não recebido, reembolsos, como os do espectáculo «O Bailão – MC Meno K» em Julho e Agosto de 2026), pedidos de cotação, candidaturas espontâneas e avisos de fornecedores. Os compradores de bilhetes são tratados pelo Gestor de Clientes.'],
        [CompanySection::Rules, 'Emails do domínio nxcli.io que imitam a OpenAI, o ChatGPT ou a Hostinger são phishing: não se clica em nada nem se responde.'],
        [CompanySection::Rules, 'Os agentes só lêem o ERP; qualquer registo ou alteração no ERP e qualquer email para fora é feito por uma pessoa.'],
    ];
}
