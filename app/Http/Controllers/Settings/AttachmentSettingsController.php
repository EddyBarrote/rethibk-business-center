<?php

namespace App\Http\Controllers\Settings;

use App\Attachments\AttachmentLimits;
use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What people may send in conversations with agents: size, files per message,
 * the longest recording and the types (docs/DECISOES.md, "Anexos nas
 * conversas"). Owners and admins only.
 */
class AttachmentSettingsController extends Controller
{
    public function show(Request $request): Response
    {
        abort_unless($this->user($request)->canManageTenant(), 403);
        $limits = AttachmentLimits::current();

        return Inertia::render('Settings/Attachments', [
            'settings' => [
                'max_mb' => $limits->maxMb,
                'max_files' => $limits->maxFiles,
                'audio_max_minutes' => $limits->audioMaxMinutes,
                'types' => $limits->types,
            ],
            'types' => collect((array) config('attachments.types'))->map(fn (array $type, string $key) => [
                'value' => $key,
                'label' => $type['label'],
                'extensions' => $type['extensions'],
            ])->values(),
            'ceiling' => config('attachments.ceiling'),
            'defaults' => config('attachments.defaults'),
            'provider' => (string) config('ai.default'),
            'native' => array_values((array) config('attachments.native.'.(config('ai.providers.'.config('ai.default').'.driver') ?? config('ai.default')), [])),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        abort_unless($user->canManageTenant(), 403);
        $ceiling = (array) config('attachments.ceiling');

        $data = $request->validate([
            'max_mb' => ['required', 'integer', 'between:1,'.$ceiling['max_mb']],
            'max_files' => ['required', 'integer', 'between:1,'.$ceiling['max_files']],
            'audio_max_minutes' => ['required', 'integer', 'between:1,'.$ceiling['audio_max_minutes']],
            'types' => ['array'],
            'types.*' => [Rule::in(array_keys((array) config('attachments.types')))],
        ]);

        $tenant = Tenant::current();
        abort_if($tenant === null, 404);
        $settings = $tenant->settings ?? [];
        $settings['attachments'] = [
            'max_mb' => (int) $data['max_mb'],
            'max_files' => (int) $data['max_files'],
            'audio_max_minutes' => (int) $data['audio_max_minutes'],
            'types' => array_values(array_unique($data['types'] ?? [])),
        ];
        $tenant->update(['settings' => $settings]);
        AuditLog::record($user, 'settings.attachments_updated', $settings['attachments']);

        return back()->with('success', 'Definições de anexos guardadas.');
    }
}
