<?php

namespace App\Http\Controllers;

use App\Services\Mailbox\Mailbox;
use App\Services\Mailbox\MailboxException;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Załącznik z wiadomości w skrzynce firmowej: pobranie albo podgląd PDF/obrazu/tekstu w nowej karcie.
 */
class MailboxController extends Controller
{
    public function attachment(Request $request, Mailbox $mailbox): Response
    {
        $validated = $request->validate([
            'folder' => ['required', 'string', 'max:255'],
            'uid' => ['required', 'integer', 'min:1'],
            'index' => ['required', 'integer', 'min:0'],
            'inline' => ['nullable', 'boolean'],
        ]);

        try {
            $attachment = $mailbox->attachment($validated['folder'], (int) $validated['uid'], (int) $validated['index']);
        } catch (MailboxException $exception) {
            abort(404, $exception->getMessage());
        }

        $content = (string) $attachment->content;

        // Podgląd tylko bezpiecznych typów i w trybie „sandbox” — treść z zewnątrz nie wykona skryptów.
        if ($request->boolean('inline') && $attachment->previewable()) {
            $type = $attachment->previewMime().($attachment->previewMime() === 'text/plain' ? '; charset=utf-8' : '');

            $headers = [
                'Content-Type' => $type,
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $attachment->name, Str::ascii($attachment->name)),
                'X-Content-Type-Options' => 'nosniff',
            ];

            // Chrome nie pokazuje PDF z CSP „sandbox”; PDF otwiera i tak wbudowana przeglądarka PDF.
            if ($attachment->previewMime() !== 'application/pdf') {
                $headers['Content-Security-Policy'] = 'sandbox';
            }

            return response($content, 200, $headers);
        }

        return response()->streamDownload(
            fn () => print $content,
            $attachment->name,
            ['Content-Type' => 'application/octet-stream', 'X-Content-Type-Options' => 'nosniff'],
        );
    }
}
