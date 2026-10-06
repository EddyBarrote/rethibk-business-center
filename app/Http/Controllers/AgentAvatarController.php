<?php

namespace App\Http\Controllers;

use App\Ai\Agents\AgentAvatars;
use App\Models\Agent;
use App\Models\AuditLog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The agent's photo: served to people of the same tenant, changed by its
 * owners and admins (upload, generate with AI, or back to initials).
 */
class AgentAvatarController extends Controller
{
    public function __construct(private readonly AgentAvatars $avatars) {}

    public function show(Agent $agent): StreamedResponse
    {
        Gate::authorize('view', $agent);
        abort_if($agent->avatar_path === null, 404);

        return Storage::disk(AgentAvatars::DISK)->response($agent->avatar_path, headers: ['Cache-Control' => 'private, max-age=86400']);
    }

    public function store(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('update', $agent);

        $data = $request->validate(['avatar' => ['required', 'image', 'mimes:png,jpg,jpeg,webp', 'max:4096']]);
        $this->avatars->upload($agent, $data['avatar']);
        AuditLog::record($this->user($request), 'agent.avatar_uploaded', [], subject: $agent);

        return back()->with('success', 'Foto actualizada.');
    }

    public function generate(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('update', $agent);

        try {
            $this->avatars->generate($agent);
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'Não foi possível gerar a foto: '.Str::limit($e->getMessage(), 200));
        }

        AuditLog::record($this->user($request), 'agent.avatar_generated', [], subject: $agent);

        return back()->with('success', 'Foto gerada.');
    }

    public function destroy(Request $request, Agent $agent): RedirectResponse
    {
        Gate::authorize('update', $agent);

        $this->avatars->remove($agent);

        return back()->with('success', 'Foto removida; ficam as iniciais.');
    }
}
