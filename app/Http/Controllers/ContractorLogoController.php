<?php

namespace App\Http\Controllers;

use App\Models\Contractor;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Podgląd logo kontrahenta z prywatnego storage.
 */
class ContractorLogoController extends Controller
{
    public function __invoke(Contractor $contractor): StreamedResponse
    {
        abort_if($contractor->logo_path === null || ! Storage::disk('local')->exists($contractor->logo_path), 404);

        return Storage::disk('local')->response($contractor->logo_path);
    }
}
