<?php

namespace App\Livewire;

use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\Note;
use Flux\Flux;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/**
 * Strona z plikami rekordu (notatka, kontrahent): wgrywanie, podpis, data ważności i usuwanie.
 * W zapisanym rekordzie pliki dołączamy od razu po wybraniu; w nowym — przy zapisie (storeUploads).
 */
abstract class ComponentWithAttachments extends Component
{
    use WithFileUploads;

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

    /** Edycja podpisu i daty ważności jednego pliku (okno attachment-edit). */
    public ?int $editingAttachmentId = null;

    public string $attachmentDescription = '';

    public bool $attachmentHasExpiry = false;

    public string $attachmentExpiresAt = '';

    /** Rekord, do którego dołączamy pliki (null = jeszcze niezapisany). */
    abstract protected function attachmentOwner(): Note|Contractor|null;

    /** Gate wymagany do zmian w plikach. */
    abstract protected function attachmentPermission(): string;

    public function updatedUploads(): void
    {
        $owner = $this->attachmentOwner();

        if ($owner === null) {
            return;
        }

        $this->authorize($this->attachmentPermission());
        $this->validate($this->uploadRules());

        $this->storeUploads($owner);
        $owner->touch();
        $owner->unsetRelation('attachments');

        Flux::toast(variant: 'success', text: __('Files attached.'));
    }

    public function removeUpload(int $index): void
    {
        $uploads = $this->uploads;
        array_splice($uploads, $index, 1);
        $this->uploads = $uploads;
    }

    public function deleteAttachment(int $id): void
    {
        $this->authorize($this->attachmentPermission());

        $owner = $this->attachmentOwner();
        $owner?->attachments()->whereKey($id)->first()?->delete();
        $owner?->touch();
        $owner?->unsetRelation('attachments');

        Flux::toast(variant: 'success', text: __('File deleted.'));
    }

    public function editAttachment(int $id): void
    {
        $attachment = $this->attachmentOwner()?->attachments()->whereKey($id)->first();

        if ($attachment === null) {
            return;
        }

        $this->resetValidation();
        $this->editingAttachmentId = $attachment->id;
        $this->attachmentDescription = (string) $attachment->description;
        $this->attachmentHasExpiry = $attachment->expires_at !== null;
        $this->attachmentExpiresAt = $attachment->expires_at?->toDateString() ?? '';

        Flux::modal('attachment-edit')->show();
    }

    public function saveAttachment(): void
    {
        $this->authorize($this->attachmentPermission());

        $this->validate([
            'attachmentDescription' => ['nullable', 'string', 'max:255'],
            'attachmentExpiresAt' => $this->attachmentHasExpiry ? ['required', 'date'] : ['nullable'],
        ]);

        $owner = $this->attachmentOwner();
        $attachment = $owner?->attachments()->whereKey($this->editingAttachmentId)->first();

        $attachment?->update([
            'description' => trim($this->attachmentDescription) ?: null,
            'expires_at' => $this->attachmentHasExpiry ? $this->attachmentExpiresAt : null,
        ]);
        $owner?->unsetRelation('attachments');

        Flux::modal('attachment-edit')->close();
        Flux::toast(variant: 'success', text: __('File details saved.'));
    }

    /**
     * @return array<string, list<string>>
     */
    protected function uploadRules(): array
    {
        return [
            'uploads' => ['array', 'max:20'],
            'uploads.*' => ['file', 'max:'.Attachment::MAX_KB],
        ];
    }

    protected function storeUploads(Note|Contractor $owner): void
    {
        foreach ($this->uploads as $file) {
            Attachment::store($owner, $file);
        }

        $this->uploads = [];
    }
}
