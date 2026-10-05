<?php

namespace App\Http\Controllers;

use App\Models\Attachment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\Response;

/**
 * Plik z notatki albo kartoteki: pobranie albo podgląd PDF/obrazu/tekstu w nowej karcie.
 */
class AttachmentController extends Controller
{
    public function __invoke(Request $request, Attachment $attachment): Response
    {
        $permission = $attachment->permission();
        abort_if($permission === null || Gate::denies($permission), 403);

        $disk = Storage::disk('local');
        abort_unless($disk->exists($attachment->path), 404);

        if ($request->boolean('inline') && $attachment->previewable()) {
            $type = $attachment->previewMime().($attachment->previewMime() === 'text/plain' ? '; charset=utf-8' : '');

            $headers = [
                'Content-Type' => $type,
                'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_INLINE, $attachment->name, Str::ascii($attachment->name)),
                'X-Content-Type-Options' => 'nosniff',
            ];

            // Chrome nie pokazuje PDF z CSP „sandbox”; skrypty w obrazach/tekście blokujemy.
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
