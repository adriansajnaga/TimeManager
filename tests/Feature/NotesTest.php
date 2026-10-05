<?php

use App\Models\Attachment;
use App\Models\Contractor;
use App\Models\Note;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(function () {
    Storage::fake('local');
    $this->admin = User::factory()->admin()->create();
});

test('employee cannot open notes', function () {
    $employee = User::factory()->create();

    $this->actingAs($employee)->get(route('notes.index'))->assertForbidden();
    $this->actingAs($employee)->get(route('notes.create'))->assertForbidden();
});

test('admin creates a note with files', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::notes.form')
        ->set('title', 'Lürssen — dane dostępowe do placu')
        ->set('body', "Brama 3, zgłoszenie u ochrony.\nTelefon do kierownika w umowie.")
        ->set('uploads', [
            UploadedFile::fake()->create('umowa.pdf', 120, 'application/pdf'),
            UploadedFile::fake()->create('plan placu.png', 40, 'image/png'),
        ])
        ->call('save')
        ->assertHasNoErrors();

    $note = Note::query()->sole();

    expect($note->title)->toBe('Lürssen — dane dostępowe do placu')
        ->and($note->user_id)->toBe($this->admin->id)
        ->and($note->attachments)->toHaveCount(2);

    $pdf = $note->attachments->firstWhere('name', 'umowa.pdf');
    Storage::disk('local')->assertExists($pdf->path);
    expect($pdf->kind())->toBe('pdf');

    $this->get(route('notes.index'))->assertOk()->assertSee('Lürssen — dane dostępowe do placu');
    $this->get(route('attachments.show', $pdf))->assertOk()->assertDownload('umowa.pdf');
    $this->get(route('attachments.show', ['attachment' => $pdf, 'inline' => 1]))->assertOk()->assertHeader('Content-Type', 'application/pdf');
});

test('files picked in an existing note are attached right away and can be deleted', function () {
    $this->actingAs($this->admin);
    $note = Note::query()->create(['title' => 'TKMS', 'user_id' => $this->admin->id]);

    $component = Livewire::test('pages::notes.form', ['note' => $note])
        ->set('uploads', [UploadedFile::fake()->create('zdjecie.jpg', 30, 'image/jpeg')])
        ->assertHasNoErrors();

    $attachment = $note->attachments()->sole();
    Storage::disk('local')->assertExists($attachment->path);

    $component->call('deleteAttachment', $attachment->id);

    expect(Attachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($attachment->path);
});

test('deleting a note removes its files', function () {
    $this->actingAs($this->admin);
    $note = Note::query()->create(['title' => 'Do usunięcia', 'user_id' => $this->admin->id]);

    Livewire::test('pages::notes.form', ['note' => $note])
        ->set('uploads', [UploadedFile::fake()->create('a.txt', 1, 'text/plain')]);

    $path = $note->attachments()->sole()->path;

    Livewire::test('pages::notes.form', ['note' => $note->refresh()])
        ->call('delete')
        ->assertRedirect(route('notes.index'));

    expect(Note::query()->count())->toBe(0)
        ->and(Attachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($path);
});

test('search finds notes by text and file name', function () {
    $this->actingAs($this->admin);
    Note::query()->create(['title' => 'Kiel', 'body' => 'Kod do szafy: 4711']);
    $other = Note::query()->create(['title' => 'Wolgast']);
    $other->attachments()->create(['name' => 'rysunek-szafy.dwg', 'path' => 'notes/x.dwg', 'mime' => 'application/octet-stream', 'size' => 10]);
    Note::query()->create(['title' => 'Bremen']);

    Livewire::test('pages::notes.index')
        ->set('search', 'szaf')
        ->assertSee('Kiel')
        ->assertSee('Wolgast')
        ->assertDontSee('Bremen');
});

test('contractor record keeps attached documents', function () {
    $this->actingAs($this->admin);
    $contractor = Contractor::factory()->create();

    Livewire::test('pages::contractors.form', ['contractor' => $contractor])
        ->set('uploads', [UploadedFile::fake()->create('Rahmenvertrag 2026.pdf', 200, 'application/pdf')])
        ->assertHasNoErrors()
        ->assertSee('Rahmenvertrag 2026.pdf');

    $document = $contractor->attachments()->sole();
    expect($document->path)->toStartWith('contractors/'.$contractor->id.'/documents/');

    $this->get(route('attachments.show', $document))->assertOk()->assertDownload('Rahmenvertrag 2026.pdf');

    // Plik kontrahenta wymaga uprawnienia do kontrahentów.
    $this->actingAs(User::factory()->create())->get(route('attachments.show', $document))->assertForbidden();

    $this->actingAs($this->admin);
    $contractor->delete();

    expect(Attachment::query()->count())->toBe(0);
    Storage::disk('local')->assertMissing($document->path);
});

test('new contractor gets files chosen before the first save', function () {
    $this->actingAs($this->admin);

    Livewire::test('pages::contractors.form')
        ->set('form.name', 'Neptun Werft GmbH')
        ->set('form.street', 'Werftallee 13')
        ->set('form.zip', '18119')
        ->set('form.city', 'Rostock')
        ->set('form.country_code', 'de')
        ->set('uploads', [UploadedFile::fake()->create('Stammdaten.xlsx', 15)])
        ->call('save')
        ->assertHasNoErrors();

    expect(Contractor::query()->where('name', 'Neptun Werft GmbH')->sole()->attachments)->toHaveCount(1);
});
