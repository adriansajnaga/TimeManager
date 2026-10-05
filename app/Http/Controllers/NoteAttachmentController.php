<?php

namespace App\Http\Controllers;

use App\Models\NoteAttachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plik z notatki: pobranie albo podgląd PDF/obrazu/tekstu w nowej karcie.
 */
class NoteAttachmentController extends Controller
{
    public function __invoke(Request $request, NoteAttachment $attachment): Response
    {
        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);

        $fallback = Str::ascii($attachment->name);

        if ($request->boolean('inline') && $attachment->previewable()) {
            $type = $attachment->previewMime().($attachment->previewMime() === 'text/plain' ? '; charset=utf-8' : '');

            $headers = [
                'Content-Type' => $type,
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $attachment->name, $fallback),
                'X-Content-Type-Options' => 'nosniff',
            ];

            if ($attachment->previewMime() !== 'application/pdf') {
                $headers['Content-Security-Policy'] = 'sandbox';
            }

            return $disk->response($attachment->path, $attachment->name, $headers);
        }

        return $disk->download($attachment->path, $attachment->name, [
            'Content-Type' => 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }
}
