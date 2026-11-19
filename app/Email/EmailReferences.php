<?php

namespace App\Email;

use App\Models\Agent;
use App\Models\EmailMessage;

/**
 * Emails are named by who wrote them and what about, never by their internal
 * number: "Análise do Email #143" reads "Análise do email de Carmélia Buque
 * («Solicitação de Cotação…»)" (docs/DECISOES.md, "Emails por quem escreveu").
 * The number stays for the tools.
 */
class EmailReferences
{
    private const PATTERN = '/(\*\*|__)?\b([Ee])mail\s*#\s*(\d+)\b(\*\*|__)?/u';

    /**
     * An agent's text, with each "Email #N" of an email it may read written
     * out; one it cannot read keeps its number.
     */
    public function inText(string $text, ?Agent $agent = null): string
    {
        if (! preg_match(self::PATTERN, $text)) {
            return $text;
        }

        $emails = $this->emails($text, $agent);

        return (string) preg_replace_callback(self::PATTERN, function (array $m) use ($emails) {
            $email = $emails[(int) $m[3]] ?? null;

            if ($email === null) {
                return $m[0];
            }

            $name = $m[2].'mail '.$this->who($email);
            $subject = filled($email->subject) ? ' («'.str($email->subject)->squish()->limit(80).'»)' : '';
            $open = $m[1];
            $close = $m[4] ?? '';

            // "**Email #143**" stays bold on the name, the subject after it.
            return $open !== '' && $close !== '' ? "{$open}{$name}{$close}{$subject}" : $open.$name.$subject.$close;
        }, $text);
    }

    /**
     * A task title without the "(Email #N)" or "- Email #N" it was given.
     */
    public function inTitle(string $title): string
    {
        $clean = trim((string) preg_replace('/\s*[(\[]\s*e-?mail\s*#\s*\d+\s*[)\]]|\s*[-–—:]\s*e-?mail\s*#\s*\d+\s*$/iu', '', $title));

        return $clean !== '' ? $clean : $title;
    }

    /**
     * @return array<int, EmailMessage>
     */
    private function emails(string $text, ?Agent $agent): array
    {
        preg_match_all(self::PATTERN, $text, $all);

        return EmailMessage::query()->whereKey(array_unique(array_map('intval', $all[3])))
            ->when($agent, fn ($q) => $q->readableBy($agent))
            ->get(['id', 'direction', 'from_name', 'from_address', 'to', 'subject'])
            ->keyBy('id')->all();
    }

    private function who(EmailMessage $email): string
    {
        if ($email->direction === 'outbound') {
            $to = (array) ($email->to ?? []);

            return 'para '.($to[0] ?? 'fora');
        }

        $name = str((string) $email->from_name)->squish()->toString();

        // A name written all in small letters reads as a name.
        if ($name !== '' && mb_strtolower($name) === $name) {
            $name = mb_convert_case($name, MB_CASE_TITLE);
        }

        return 'de '.($name !== '' ? $name : (string) $email->from_address);
    }
}
