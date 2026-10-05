<?php

namespace App\Support;

/**
 * Rodzaj pliku, ikona, podgląd i rozmiar — wspólne dla załączników poczty i notatek.
 * Klasa musi mieć pola name (nazwa pliku), mime i size (bajty).
 */
trait DescribesFile
{
    /**
     * Rodzaj pliku do ikony: pdf, image, word, excel, archive, text albo other.
     */
    public function kind(): string
    {
        $extension = strtolower(pathinfo($this->name, PATHINFO_EXTENSION));
        $mime = strtolower($this->mime);

        return match (true) {
            $extension === 'pdf' || $mime === 'application/pdf' => 'pdf',
            str_starts_with($mime, 'image/') || in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp', 'bmp', 'heic'], true) => 'image',
            in_array($extension, ['doc', 'docx', 'odt', 'rtf', 'pages'], true) || str_contains($mime, 'wordprocessing') || $mime === 'application/msword' => 'word',
            in_array($extension, ['xls', 'xlsx', 'ods', 'csv', 'numbers'], true) || str_contains($mime, 'spreadsheet') || $mime === 'application/vnd.ms-excel' => 'excel',
            in_array($extension, ['zip', 'rar', '7z', 'gz', 'tar'], true) || str_contains($mime, 'zip') || str_contains($mime, 'compressed') => 'archive',
            in_array($extension, ['txt', 'xml', 'json', 'log', 'eml'], true) || str_starts_with($mime, 'text/') => 'text',
            default => 'other',
        };
    }

    /**
     * Ikona Heroicons i kolor dla kafelka załącznika.
     *
     * @return array{0: string, 1: string}
     */
    public function icon(): array
    {
        return match ($this->kind()) {
            'pdf' => ['document-text', 'text-red-600'],
            'image' => ['photo', 'text-violet-600'],
            'word' => ['document', 'text-blue-600'],
            'excel' => ['table-cells', 'text-green-600'],
            'archive' => ['archive-box', 'text-amber-600'],
            'text' => ['document-text', 'text-zinc-500'],
            default => ['paper-clip', 'text-zinc-500'],
        };
    }

    /**
     * Pliki, które przeglądarka pokaże bezpiecznie (PDF, obrazy, tekst) — otwierane w nowej karcie.
     */
    public function previewable(): bool
    {
        return in_array($this->previewMime(), ['application/pdf', 'image/png', 'image/jpeg', 'image/gif', 'image/webp', 'text/plain'], true);
    }

    public function previewMime(): string
    {
        return match (strtolower(pathinfo($this->name, PATHINFO_EXTENSION))) {
            'pdf' => 'application/pdf',
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'txt', 'log' => 'text/plain',
            default => strtolower($this->mime),
        };
    }

    public function sizeLabel(): string
    {
        return $this->size >= 1048576
            ? number_format($this->size / 1048576, 1, ',', ' ').' MB'
            : max(1, (int) round($this->size / 1024)).' kB';
    }
}
