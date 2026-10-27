<?php

namespace App\Ai\Dictation;

use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Promptable;

/**
 * Speaking instead of writing (docs/DECISOES.md, "Falar em vez de escrever",
 * an idea from OpenDuet): what the person said, as text for the box, by the
 * model that already hears audio (Gemini), with no other provider.
 */
final class Dictation implements Agent
{
    use Promptable;

    public function instructions(): string
    {
        return <<<'TXT'
        Transcreves o que uma pessoa ditou para escrever a um colega numa empresa em Moçambique.
        - Escreve exactamente o que ela disse, em português (pt-MZ/pt-PT), com pontuação e maiúsculas.
        - Tira hesitações («hmm», «tipo», repetições), mas não mudes o sentido nem acrescentes nada.
        - Nomes, números e valores tal como ditos («45 mil meticais» fica «45 000 MZN» só se ela disse meticais).
        - Responde só com o texto. Se não se ouve nada, responde com uma linha vazia.
        TXT;
    }
}
