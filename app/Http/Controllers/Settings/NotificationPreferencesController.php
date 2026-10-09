<?php

namespace App\Http\Controllers\Settings;

use App\Enums\NotificationCategory;
use App\Http\Controllers\Controller;
use App\Support\NotificationPreferences;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * A minha conta › Notificações: for each kind, whether it goes to the centre,
 * pops up on the screen, and arrives by email. Approvals always reach the centre.
 */
class NotificationPreferencesController extends Controller
{
    public function show(Request $request): Response
    {
        $user = $this->user($request);

        return Inertia::render('Settings/Notifications', [
            'categories' => array_map(fn (NotificationCategory $c) => [
                'key' => $c->value,
                'label' => $c->label(),
                'description' => $c->description(),
                'required' => $c->required(),
                'prefs' => NotificationPreferences::for($user, $c),
                'defaults' => $c->defaults(),
            ], NotificationCategory::cases()),
            'email' => $user->email,
        ]);
    }

    /**
     * One switch at a time, as the person flips it.
     */
    public function update(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $data = $request->validate([
            'category' => ['required', Rule::in(array_column(NotificationCategory::cases(), 'value'))],
            'channel' => ['required', Rule::in(NotificationPreferences::CHANNELS)],
            'enabled' => ['required', 'boolean'],
        ]);
        $category = NotificationCategory::from($data['category']);

        if ($category->required() && $data['channel'] === 'app') {
            return back()->with('error', 'As aprovações aparecem sempre no centro: só uma pessoa as pode decidir.');
        }

        $prefs = $user->notification_preferences ?? [];
        $prefs[$category->value][$data['channel']] = (bool) $data['enabled'];
        $user->forceFill(['notification_preferences' => $prefs])->save();

        return back();
    }
}
