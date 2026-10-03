<?php

namespace App\Documents;

use App\Enums\Language;

/**
 * Dokument PDF składany przez PdfRenderer (jedna lub więcej stron w jednej orientacji).
 */
interface Document
{
    /**
     * @return view-string
     */
    public function view(): string;

    /**
     * @return array<string, mixed>
     */
    public function data(): array;

    /**
     * "P" (pionowo) albo "L" (poziomo).
     */
    public function orientation(): string;

    public function language(): Language;

    public function filename(): string;
}
