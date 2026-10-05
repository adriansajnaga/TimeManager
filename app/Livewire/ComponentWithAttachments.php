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
 * Strona z plikami rekordu (notatka, kontrahent): wgrywanie i usuwanie.
 * W zapisanym rekordzie pliki dołączamy od razu po wybraniu; w nowym — przy zapisie (storeUploads).
 */
abstract class ComponentWithAttachments extends Component
{
    use WithFileUploads;

    /** @var list<TemporaryUploadedFile> */
    public array $uploads = [];

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
