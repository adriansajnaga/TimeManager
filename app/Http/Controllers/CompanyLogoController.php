<?php

namespace App\Http\Controllers;

use App\Models\CompanySetting;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Podgląd logo firmy z prywatnego storage (bez publicznego symlinku).
 */
class CompanyLogoController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $path = CompanySetting::current()->logo_path;

        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path);
    }
}
