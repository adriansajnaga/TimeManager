<?php

use App\Enums\Language;
use App\Models\AiSetting;
use App\Models\Contractor;
use App\Models\Project;
use App\Models\TimeEntry;
use App\Models\User;
use App\Models\WeeklyReport;
use App\Services\Ai\AiException;
use App\Services\Ai\ClaudeTextAssistant;
use App\Services\Ai\TextAssistant;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

/**
 * Atrapa asystenta: zapisuje wywołania, nie łączy się z API.
 */
function fakeAssistant(?string $failWith = null): object
{
    $fake = new class($failWith) implements TextAssistant
    {
        /** @var list<array{text: string, section: string, target: Language|null}> */
        public array $calls = [];

        public bool $pinged = false;

        public function __construct(private readonly ?string $failWith) {}

        public function rewrite(string $text, string $section, ?Language $target): string
        {
            if ($this->failWith !== null) {
                throw new AiException($this->failWith);
            }

            $this->calls[] = compact('text', 'section', 'target');

            return $target === Language::German ? 'Leitung 5x6 verlegt.' : 'Ułożono przewód 5x6.';
        }

        public function ping(): void
        {
            if ($this->failWith !== null) {
                throw new AiException($this->failWith);
            }

            $this->pinged = true;
        }
    };

    app()->instance(TextAssistant::class, $fake);

    return $fake;
}

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->employee = User::factory()->create();
    $this->project = Project::factory()->for(Contractor::factory()->german())->create();
    $this->project->users()->attach($this->employee);

    $entry = TimeEntry::factory()->for($this->employee)->for($this->project)->create();
    $this->week = $entry->workWeek;
    $this->report = WeeklyReport::query()->create(['work_week_id' => $this->week->id, 'project_id' => $this->project->id]);

    AiSetting::query()->create(['api_key' => 'sk-ant-test-key', 'model' => AiSetting::DEFAULT_MODEL]);
});

function weekDetail(User $user, $week)
{
    return Livewire::actingAs($user)->test('pages::weeks.show', ['workWeek' => $week]);
}

test('AI corrects the draft and the suggestion can be used', function () {
    $fake = fakeAssistant();

    weekDetail($this->employee, $this->week)
        ->set("performed.{$this->report->id}", 'ulozylem przewod 5x6')
        ->call('suggest', $this->report->id, 'performed', false)
        ->assertSet("suggestions.{$this->report->id}.performed", 'Ułożono przewód 5x6.')
        ->call('acceptSuggestion', $this->report->id, 'performed')
        ->assertSet("performed.{$this->report->id}", 'Ułożono przewód 5x6.')
        ->assertSet("suggestions.{$this->report->id}", []);

    expect($fake->calls)->toHaveCount(1)
        ->and($fake->calls[0]['section'])->toBe('performed')
        ->and($fake->calls[0]['target'])->toBeNull();
});

test('translation goes into the client\'s document language', function () {
    $fake = fakeAssistant();

    weekDetail($this->admin, $this->week)
        ->set("remaining.{$this->report->id}", 'trzeba jeszcze opisać obwody')
        ->call('suggest', $this->report->id, 'remaining', true)
        ->assertSet("suggestions.{$this->report->id}.remaining", 'Leitung 5x6 verlegt.')
        ->call('discardSuggestion', $this->report->id, 'remaining')
        ->assertSet("remaining.{$this->report->id}", 'trzeba jeszcze opisać obwody');

    expect($fake->calls[0]['target'])->toBe(Language::German)
        ->and($fake->calls[0]['section'])->toBe('remaining');
});

test('empty draft is not sent and AI errors are shown to the user', function () {
    $fake = fakeAssistant();

    weekDetail($this->employee, $this->week)
        ->call('suggest', $this->report->id, 'performed', false)
        ->assertHasErrors(["ai.{$this->report->id}.performed"]);

    expect($fake->calls)->toBe([]);

    fakeAssistant('The AI account has no credit left.');

    weekDetail($this->employee, $this->week)
        ->set("performed.{$this->report->id}", 'Text')
        ->call('suggest', $this->report->id, 'performed', false)
        ->assertHasErrors(["ai.{$this->report->id}.performed"])
        ->assertSee('The AI account has no credit left.');
});

test('AI is not available for closed weeks or unassigned employees', function () {
    fakeAssistant();
    $stranger = User::factory()->create();

    weekDetail($stranger, $this->week)
        ->call('suggest', $this->report->id, 'performed', false)
        ->assertForbidden();

    $this->week->close($this->admin);

    weekDetail($this->admin, $this->week)
        ->set("performed.{$this->report->id}", 'Text')
        ->call('suggest', $this->report->id, 'performed', false)
        ->assertForbidden();
});

test('AI buttons appear only when an API key is configured', function () {
    weekDetail($this->admin, $this->week)->assertSee('AI: correct');

    AiSetting::query()->update(['api_key' => null]);

    weekDetail($this->admin, $this->week)->assertDontSee('AI: correct');
});

test('API key is stored encrypted and an empty field keeps it', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.ai')
        ->set('api_key', 'sk-ant-new-secret-key')
        ->set('model', 'claude-haiku-4-5')
        ->call('save')
        ->assertHasNoErrors();

    $raw = DB::table('ai_settings')->value('api_key');
    expect($raw)->not->toContain('sk-ant-new-secret-key')
        ->and(AiSetting::current()->api_key)->toBe('sk-ant-new-secret-key')
        ->and(AiSetting::current()->model)->toBe('claude-haiku-4-5');

    Livewire::actingAs($this->admin)
        ->test('pages::admin.ai')
        ->set('model', 'claude-opus-5-5')
        ->call('save')
        ->assertHasNoErrors();

    expect(AiSetting::current()->api_key)->toBe('sk-ant-new-secret-key');
});

test('API key format and model are validated', function () {
    Livewire::actingAs($this->admin)
        ->test('pages::admin.ai')
        ->set('api_key', 'not-a-key')
        ->set('model', 'gpt-whatever')
        ->call('save')
        ->assertHasErrors(['api_key', 'model']);
});

test('connection test records success or shows the error', function () {
    $fake = fakeAssistant();

    Livewire::actingAs($this->admin)->test('pages::admin.ai')->call('test')->assertHasNoErrors();

    expect($fake->pinged)->toBeTrue()
        ->and(AiSetting::current()->verified_at)->not->toBeNull();

    fakeAssistant('The AI API key is invalid.');

    Livewire::actingAs($this->admin)->test('pages::admin.ai')->call('test')->assertHasErrors(['test']);
});

test('only administrators manage AI settings', function () {
    $this->actingAs($this->employee)->get(route('admin.ai'))->assertForbidden();
    $this->actingAs($this->admin)->get(route('admin.ai'))->assertOk()->assertDontSee('sk-ant-test-key');
});

test('instructions keep facts and name the target language', function () {
    $prompt = ClaudeTextAssistant::systemPrompt('performed', Language::German);

    expect($prompt)->toContain('in German')
        ->toContain('Ausgeführte Arbeiten')
        ->toContain('never add work')
        ->and(ClaudeTextAssistant::systemPrompt('remaining', null))->toContain('the same language as the draft')
        ->toContain('Restarbeiten');
});

test('the Claude assistant refuses to start without an API key', function () {
    AiSetting::query()->update(['api_key' => null]);

    ClaudeTextAssistant::fromSettings();
})->throws(AiException::class);
