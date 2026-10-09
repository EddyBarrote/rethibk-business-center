<?php

namespace App\Http\Controllers;

use App\Ai\Runs\QuestionService;
use App\Models\AgentQuestion;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Answers to an agent's questions, from the card in the conversation
 * (docs/DECISOES.md, "Perguntas dos agentes").
 */
class QuestionController extends Controller
{
    public function answer(Request $request, AgentQuestion $question, QuestionService $questions): RedirectResponse
    {
        $user = $this->user($request);
        $task = $question->task;

        abort_unless($user->id === $question->asked_user_id || ($task !== null && $user->can('reply', $task)), 403);

        $data = $request->validate([
            'answers' => ['required', 'array', 'max:4'],
            'answers.*.selected' => ['array', 'max:4'],
            'answers.*.selected.*' => ['string', 'max:80'],
            'answers.*.other' => ['nullable', 'string', 'max:2000'],
        ]);

        $questions->answer($question, $user, array_values($data['answers']));

        return back();
    }
}
