<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The person's notifications from agents and watchers.
 */
class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        return Inertia::render('Notifications/Index', [
            'notifications' => $this->user($request)->notifications()->latest()->paginate(30)->through(fn (DatabaseNotification $n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'url' => $n->data['url'] ?? null,
                'from' => $n->data['from'] ?? null,
                'level' => $n->data['level'] ?? 'info',
                'read' => $n->read_at !== null,
                'created_at' => $n->created_at?->toIso8601String(),
            ]),
        ]);
    }

    /**
     * Marks it read and follows its link.
     */
    public function open(Request $request, string $notification): RedirectResponse
    {
        $notice = $this->user($request)->notifications()->findOrFail($notification);
        $notice->markAsRead();

        $url = (string) ($notice->data['url'] ?? '/notifications');

        return redirect(str_starts_with($url, '/') && ! str_starts_with($url, '//') && $this->exists($request, $url) ? $url : '/notifications');
    }

    /**
     * Older notifications can point at screens that no longer exist (contracts,
     * clients…); those stay on the notifications list instead of a 404.
     */
    private function exists(Request $request, string $url): bool
    {
        try {
            Route::getRoutes()->match(Request::create($request->getSchemeAndHttpHost().$url));

            return true;
        } catch (NotFoundHttpException|MethodNotAllowedHttpException) {
            return false;
        }
    }

    public function readAll(Request $request): RedirectResponse
    {
        $this->user($request)->unreadNotifications()->update(['read_at' => now()]);

        return back();
    }
}
