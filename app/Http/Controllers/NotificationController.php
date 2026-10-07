<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class NotificationController extends Controller
{
    public function index(Request $r)
    {
        $u = $r->user();
        $items = $u->notifications()->paginate(20);
        $u->unreadNotifications->markAsRead();

        return view('notifications.index', ['items' => $items]);
    }

    /** Feed for the header bell dropdown. */
    public function latest(Request $r)
    {
        $u = $r->user();

        return response()->json([
            'unread' => $u->unreadNotifications()->count(),
            'items' => $u->notifications()->limit(8)->get()->map(fn ($n) => [
                'id' => $n->id,
                'title' => $n->data['title'] ?? '',
                'body' => $n->data['body'] ?? '',
                'url' => $n->data['url'] ?? null,
                'time' => $n->created_at->diffForHumans(),
                'read' => (bool) $n->read_at,
            ]),
        ]);
    }

    public function readAll(Request $r)
    {
        $r->user()->unreadNotifications->markAsRead();

        return response()->json(['ok' => true]);
    }

    public function audit(Request $r)
    {
        abort_unless($r->user()->can('audit.view'), 403);

        return view('notifications.audit', ['logs' => \App\Models\AuditLog::with('user')->latest('id')->paginate(40)]);
    }
}
