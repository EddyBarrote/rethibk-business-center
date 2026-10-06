<?php

namespace App\Http\Controllers;

use App\Enums\BriefingType;
use App\Enums\Permission;
use App\Models\Briefing;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Briefing history (section 11.1, /briefings).
 */
class BriefingController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('Briefings/Index', [
            'briefings' => $this->visible($user)->with(['agent:id,name', 'forUser:id,name'])->latest('id')->paginate(20)->withQueryString()
                ->through(fn (Briefing $b) => self::present($b)),
        ]);
    }

    public function show(Request $request, Briefing $briefing): Response
    {
        $user = $this->user($request);
        abort_unless($this->visible($user)->whereKey($briefing->id)->exists(), 403);

        if ($briefing->for_user_id === $user->id && $briefing->read_at === null) {
            $briefing->forceFill(['read_at' => now()])->save();
        }

        return Inertia::render('Briefings/Show', [
            'briefing' => [...self::present($briefing->load(['agent:id,name', 'forUser:id,name'])), 'content' => $briefing->content],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(Briefing $b): array
    {
        return [
            'id' => $b->id,
            'type' => $b->type->value,
            'type_label' => $b->type->label(),
            'title' => $b->title,
            'highlights' => $b->highlights ?? [],
            'decisions_pending' => $b->decisions_pending ?? [],
            'agent' => $b->agent?->name,
            'for' => $b->forUser?->name,
            'run_id' => $b->agent_run_id,
            'read' => $b->read_at !== null,
            'created_at' => $b->created_at->toIso8601String(),
        ];
    }

    /**
     * @return Builder<Briefing>
     */
    private function visible(User $user): Builder
    {
        return Briefing::query()->when(! $user->hasPermission(Permission::ReadAllReports), fn ($q) => $q->where('for_user_id', $user->id));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function types(): array
    {
        return BriefingType::options();
    }
}
