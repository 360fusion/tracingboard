<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Messages from the public contact form. The superadmins reply from their own email and mark each one handled here.
 */
class SuperAdminContactMessageController extends Controller
{
    public function index(Request $request): Response
    {
        $status = $request->query('status') === 'handled' ? 'handled' : 'new';

        return Inertia::render('SuperAdmin/ContactMessages/Index', [
            'messages' => ContactMessage::query()
                ->with('handledBy:id,name')
                ->when($status === 'new', fn ($query) => $query->whereNull('handled_at'), fn ($query) => $query->whereNotNull('handled_at'))
                ->latest('id')
                ->paginate(25)
                ->withQueryString()
                ->through(fn (ContactMessage $message) => [
                    'id' => $message->id,
                    'name' => $message->name,
                    'email' => $message->email,
                    'lodge' => $message->lodge,
                    'topic' => ContactMessage::TOPICS[$message->topic] ?? $message->topic,
                    'message' => $message->message,
                    'sent_at' => $message->created_at?->format('j M Y, g.ia'),
                    'handled_at' => $message->handled_at?->format('j M Y'),
                    'handled_by' => $message->handledBy?->name,
                ]),
            'status' => $status,
            'newCount' => ContactMessage::whereNull('handled_at')->count(),
        ]);
    }

    public function handle(Request $request, int $id): RedirectResponse
    {
        $message = ContactMessage::findOrFail($id);
        $message->forceFill(['handled_at' => now(), 'handled_by' => $request->user()->id])->save();

        return back()->with('success', 'Marked as handled.');
    }

    public function reopen(int $id): RedirectResponse
    {
        ContactMessage::findOrFail($id)->forceFill(['handled_at' => null, 'handled_by' => null])->save();

        return back()->with('success', 'Moved back to new messages.');
    }
}
