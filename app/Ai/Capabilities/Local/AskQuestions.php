<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Ai\Runs\QuestionService;
use App\Enums\AutonomyLevel;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Like Claude's AskUserQuestion (docs/DECISOES.md, "Perguntas dos agentes"):
 * one to four multiple-choice questions, shown as a card in the conversation.
 * The run pauses until a person answers and then carries on with the answers.
 */
final class AskQuestions extends LocalCapability
{
    public function __construct(private readonly QuestionService $questions) {}

    public function key(): string
    {
        return 'questions.ask';
    }

    public function name(): string
    {
        return 'Fazer perguntas de escolha';
    }

    public function description(): string
    {
        return 'Faz à pessoa 1 a 4 perguntas de escolha, cada uma com 2 a 4 opções (rótulo curto e uma descrição de uma linha); a pessoa pode sempre escrever outra resposta. '
            .'Usa quando precisas de uma decisão, de uma preferência ou de esclarecer um pedido ambíguo antes de continuar. '
            .'A execução pausa até a pessoa responder e depois continua com as respostas. Depois de chamar, termina a resposta com uma frase curta; não respondas por ela nem chames outras ferramentas.';
    }

    public function defaultRisk(): AutonomyLevel
    {
        return AutonomyLevel::Observe;
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'questions' => $schema->array()->items($schema->object([
                'question' => $schema->string()->description('A pergunta completa, terminada em ponto de interrogação.')->required(),
                'header' => $schema->string()->description('Etiqueta muito curta (até 12 caracteres), ex.: "Formato", "Prazo".')->required(),
                'options' => $schema->array()->items($schema->object([
                    'label' => $schema->string()->description('O que a pessoa escolhe, em 1 a 5 palavras.')->required(),
                    'description' => $schema->string()->description('O que acontece ou o que implica escolher esta opção.')->required(),
                ]))->description('2 a 4 opções. Não incluas «Outra»: a plataforma acrescenta-a.')->required(),
                'multi_select' => $schema->boolean()->description('true quando as opções não se excluem e a pessoa pode escolher várias.'),
            ]))->description('1 a 4 perguntas.')->required(),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        try {
            $data = Validator::make($arguments, [
                'questions' => 'required|array|min:1|max:4',
                'questions.*.question' => 'required|string|max:500',
                'questions.*.header' => 'required|string|max:40',
                'questions.*.options' => 'required|array|min:2|max:4',
                'questions.*.options.*.label' => 'required|string|max:80',
                'questions.*.options.*.description' => 'nullable|string|max:300',
                'questions.*.multi_select' => 'nullable|boolean',
            ])->validate();
        } catch (ValidationException $e) {
            return CapabilityResult::error('perguntas inválidas: '.collect($e->errors())->flatten()->implode(' ').' Cada pergunta precisa de question, header e 2 a 4 options com label.');
        }

        $questions = array_map(fn (array $item) => [
            'question' => trim($item['question']),
            'header' => mb_strimwidth(trim($item['header']), 0, 24, '…'),
            'options' => $this->options($item['options']),
            'multi_select' => (bool) ($item['multi_select'] ?? false),
        ], $data['questions']);

        foreach ($questions as $item) {
            if (count($item['options']) < 2) {
                return CapabilityResult::error("a pergunta «{$item['header']}» precisa de pelo menos 2 opções diferentes (sem contar «Outra»).");
            }
        }

        $asked = $this->questions->ask($questions, $context);

        if (is_string($asked)) {
            return CapabilityResult::error($asked);
        }

        $person = $asked->askedUser->name ?? 'a pessoa';

        return CapabilityResult::text("Perguntas enviadas a {$person}. A execução fica em pausa e continua quando responder, com as respostas. "
            .'Termina já a tua resposta com uma frase curta (por exemplo, que ficas à espera); não respondas por ela, não repitas as perguntas e não chames outras ferramentas.');
    }

    /**
     * The options as given, without «Outra» (the card always offers it) and without repeats.
     *
     * @param  array<int, array{label: string, description?: string|null}>  $options
     * @return list<array{label: string, description: string}>
     */
    private function options(array $options): array
    {
        $result = [];

        foreach ($options as $option) {
            $label = trim($option['label']);

            if (in_array(mb_strtolower($label), ['outra', 'outro', 'other'], true) || in_array($label, array_column($result, 'label'), true)) {
                continue;
            }

            $result[] = ['label' => $label, 'description' => trim((string) ($option['description'] ?? ''))];
        }

        return $result;
    }

    public function summarise(array $arguments): string
    {
        return 'Perguntar: '.mb_strimwidth((string) ($arguments['questions'][0]['question'] ?? ''), 0, 120, '…');
    }
}
