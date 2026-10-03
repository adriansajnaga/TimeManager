<?php

namespace App\Http\Controllers;

use App\Services\Mailbox\Mailbox;
use App\Services\Mailbox\MailboxException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Pobranie załącznika z wiadomości w skrzynce firmowej.
 */
class MailboxController extends Controller
{
    public function attachment(Request $request, Mailbox $mailbox): Response
    {
        $validated = $request->validate([
            'folder' => ['required', 'string', 'max:255'],
            'uid' => ['required', 'integer', 'min:1'],
            'index' => ['required', 'integer', 'min:0'],
        ]);

        try {
            $attachment = $mailbox->attachment($validated['folder'], (int) $validated['uid'], (int) $validated['index']);
        } catch (MailboxException $exception) {
            abort(404, $exception->getMessage());
        }

        // Zawsze jako pobranie (nie inline) — treść z zewnątrz nie renderuje się w aplikacji.
        return response()->streamDownload(
            fn () => print ((string) $attachment->content),
            $attachment->name,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
