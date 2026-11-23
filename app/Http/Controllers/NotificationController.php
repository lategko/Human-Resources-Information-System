<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', ['notifications' => $request->user()->notifications()->latest()->paginate(20)]);
    }

    public function read(Request $request, string $notification): RedirectResponse|JsonResponse
    {
        $item = $request->user()->notifications()->whereKey($notification)->firstOrFail();
        $item->markAsRead();

        $redirectUrl = isset($item->data['application_id'])
            ? route('leave.show', $item->data['application_id'])
            : ($item->data['availability_url'] ?? null);

        if ($request->expectsJson()) {
            return new JsonResponse([
                'success' => true,
                'unread_count' => $request->user()->unreadNotifications()->count(),
                'redirect_url' => $redirectUrl,
            ]);
        }

        return $redirectUrl ? redirect($redirectUrl) : back();
    }

    public function readAll(Request $request): RedirectResponse|JsonResponse
    {
        $request->user()->unreadNotifications()->update(['read_at' => now()]);

        if ($request->expectsJson()) {
            return new JsonResponse([
                'success' => true,
                'unread_count' => 0,
            ]);
        }

        return back();
    }
}
