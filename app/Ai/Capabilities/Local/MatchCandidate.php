<?php

namespace App\Ai\Capabilities\Local;

use App\Ai\Capabilities\CapabilityContext;
use App\Ai\Capabilities\CapabilityResult;
use App\Ai\Capabilities\LocalCapability;
use App\Models\EmailAttachment;
use App\Models\EmailMessage;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * First-pass screening (section 7.3, MatchCandidate): which requirements of
 * the opening the CV and the application email mention. A transparent
 * baseline the agent explains and a person decides on; it never rejects.
 */
final class MatchCandidate extends LocalCapability
{
    private const STOPWORDS = ['de', 'da', 'do', 'das', 'dos', 'em', 'e', 'a', 'o', 'ou', 'com', 'para', 'anos', 'ano', 'curso', 'experiencia', 'conhecimento', 'conhecimentos'];

    public function key(): string
    {
        return 'hr.match_candidate';
    }

    public function name(): string
    {
        return 'Comparar candidato com a vaga';
    }

    public function description(): string
    {
        return 'Compara uma candidatura (email e anexos, ou texto) com os requisitos da vaga e devolve os requisitos cumpridos, os em falta e uma pontuação de 0 a 100. Serve de base; a decisão é de uma pessoa.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'requirements' => $schema->array()->items($schema->string())->description('Requisitos da vaga (hr.list_openings).')->required(),
            'email_id' => $schema->integer()->description('Email da candidatura: lê o corpo e os anexos.'),
            'text' => $schema->string()->description('Ou o texto do CV.'),
        ];
    }

    public function execute(array $arguments, CapabilityContext $context): CapabilityResult
    {
        $data = Validator::make($arguments, [
            'requirements' => 'required|array|min:1|max:40',
            'requirements.*' => 'string|max:255',
            'email_id' => 'nullable|integer|required_without:text',
            'text' => 'nullable|string|required_without:email_id',
        ])->validate();

        $text = (string) ($data['text'] ?? '');

        if (isset($data['email_id'])) {
            $email = EmailMessage::query()->readableBy($context->agent)->with('attachments')->find($data['email_id']);

            if ($email === null) {
                return CapabilityResult::error('email não encontrado.');
            }

            $text .= "\n".$email->plainText()."\n".$email->attachments->map(fn (EmailAttachment $a) => (string) $a->extracted_text)->implode("\n");
        }

        return CapabilityResult::data(self::match($data['requirements'], $text));
    }

    /**
     * @param  list<string>  $requirements
     * @return array{score: int, met: list<string>, partial: list<string>, missing: list<string>}
     */
    public static function match(array $requirements, string $text): array
    {
        $haystack = ' '.self::normalise($text).' ';
        $met = $partial = $missing = [];

        foreach ($requirements as $requirement) {
            $words = array_values(array_filter(explode(' ', self::normalise($requirement)), fn (string $w) => mb_strlen($w) > 2 && ! in_array($w, self::STOPWORDS, true)));

            if ($words === []) {
                continue;
            }

            $found = count(array_filter($words, fn (string $w) => str_contains($haystack, ' '.$w) || str_contains($haystack, ' '.Str::singular($w))));
            $ratio = $found / count($words);

            match (true) {
                $ratio >= 0.99 => $met[] = $requirement,
                $ratio >= 0.5 => $partial[] = $requirement,
                default => $missing[] = $requirement,
            };
        }

        $total = count($met) + count($partial) + count($missing);

        return [
            'score' => $total === 0 ? 0 : (int) round((count($met) + 0.5 * count($partial)) / $total * 100),
            'met' => $met,
            'partial' => $partial,
            'missing' => $missing,
        ];
    }

    private static function normalise(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[^a-z0-9]+/', ' ', Str::lower(Str::ascii($text)))));
    }
}
