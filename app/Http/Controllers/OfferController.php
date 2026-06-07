<?php

namespace App\Http\Controllers;

use App\Support\OfferStorage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class OfferController extends Controller
{
    public function show(): BinaryFileResponse
    {
        abort_unless(OfferStorage::exists(), 404, 'Договор-оферта пока не загружен.');

        return response()->file(OfferStorage::absolutePath(), [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="oferta.pdf"',
            'X-Robots-Tag' => 'noindex, nofollow',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
