<?php

namespace App\Http\Controllers;

use App\Models\ContactMessage;
use App\Models\User;
use App\Notifications\LodgeClaimNotification;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public site's contact form: how a lodge asks for access while Tracing Board is invitation-only, and how anyone
 * asks a question. Each message is kept for the superadmins and put in their bell and inbox.
 */
class ContactController extends Controller
{
    /** A person takes longer than this to fill in the form; a script posting it straight away does not. */
    private const MIN_SECONDS = 3;

    public function create(Request $request): Response
    {
        $topic = (string) $request->query('topic');

        return Inertia::render('Contact', [
            'topics' => collect(ContactMessage::TOPICS)->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values(),
            'topic' => array_key_exists($topic, ContactMessage::TOPICS) ? $topic : 'access',
            'startedAt' => Crypt::encryptString((string) now()->getTimestamp()),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'email', 'max:255'],
            'lodge' => ['nullable', 'string', 'max:160'],
            'topic' => ['required', Rule::in(array_keys(ContactMessage::TOPICS))],
            'message' => ['required', 'string', 'max:5000'],
        ]);

        // A filled-in hidden field or a form sent within seconds is a script: it is told the same as a person, and dropped.
        if (! $this->looksLikeSpam($request)) {
            $message = ContactMessage::create($validated);

            Notification::send(User::where('is_super_admin', true)->get(), LodgeClaimNotification::contactMessage($message));
        }

        return redirect()->route('contact')->with('success', 'Thank you. We have your message and will reply by email, usually within two working days.');
    }

    private function looksLikeSpam(Request $request): bool
    {
        if (filled($request->input('website'))) {
            return true;
        }

        try {
            $startedAt = (int) Crypt::decryptString((string) $request->input('started_at'));
        } catch (DecryptException) {
            return true;
        }

        return now()->getTimestamp() - $startedAt < self::MIN_SECONDS;
    }
}
