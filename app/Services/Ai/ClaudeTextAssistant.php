<?php

namespace App\Services\Ai;

use Anthropic\Beta\Messages\BetaTextBlock;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\BadRequestException;
use Anthropic\Core\Exceptions\RateLimitException;
use App\Enums\Language;
use App\Models\AiSetting;
use App\Models\AiUsageLog;

/**
 * Asystent opisów prac na Claude API (oficjalne SDK Anthropic dla PHP).
 */
final class ClaudeTextAssistant implements TextAssistant
{
    /** Modele obsługujące serwerowy fallback przy odmowie (`fallbacks: "default"`). */
    private const FALLBACK_MODELS = ['claude-opus-5-5', 'claude-sonnet-5-5'];

    public function __construct(
        private readonly Client $client,
        private readonly string $model,
        private readonly ?string $instructions = null,
    ) {}

    public static function fromSettings(): self
    {
        $settings = AiSetting::current();

        if (! $settings->isConfigured()) {
            throw new AiException(__('The AI assistant is not configured.'));
        }

        return new self(
            new Client(apiKey: (string) $settings->api_key, requestOptions: ['timeout' => 60, 'maxRetries' => 2]),
            $settings->model,
            $settings->instructions,
        );
    }

    public function rewrite(string $text, string $section, ?Language $target): string
    {
        return $this->ask(self::systemPrompt($section, $target, $this->instructions), $text);
    }

    public function ping(): void
    {
        $this->ask('Reply with the single word OK.', 'Test');
    }

    /**
     * Instrukcja dla modelu: styl raportu montażowego, bez zmiany faktów.
     *
     * @param  'performed'|'remaining'  $section
     */
    public static function systemPrompt(string $section, ?Language $target, ?string $instructions = null): string
    {
        $sectionName = $section === 'remaining'
            ? '"Restarbeiten" (work still to be done)'
            : '"Ausgeführte Arbeiten" (work performed)';

        $language = match ($target) {
            Language::German => 'German',
            Language::Polish => 'Polish',
            Language::English => 'English',
            null => 'the same language as the draft',
        };

        $prompt = <<<PROMPT
        You help an electrician (electrical and telecommunication installation) write the {$sectionName} section of a weekly installation report ("Montageauftrag") for a German contractor.
        The user sends a draft. It may contain typos, missing diacritics or colloquial wording, and may be written in another language.

        Rewrite it as a clean, concise text in {$language}, in the style of a construction work report:
        - short statements in the past tense for performed work (for example "Leitung 5x6 verlegt. Verteiler montiert.");
        - keep every fact, quantity, unit, cable type, device name, building, hall or room number exactly as in the draft;
        - never add work, materials or details that are not in the draft;
        - keep trade terms and designations such as NYM-J, NYY-J, LWL, NH00, CEE, RCD or Ø160 unchanged;
        - fix spelling, grammar and punctuation.

        Return only the rewritten text, without quotes, headings, explanations or alternatives.
        PROMPT;

        $instructions = trim((string) $instructions);

        // Wskazówki użytkownika (Administracja → Asystent AI) — styl, słownictwo, nazwy własne.
        return $instructions === ''
            ? $prompt
            : $prompt.PHP_EOL.PHP_EOL
                .'Additional instructions from the user (follow them unless they conflict with keeping the facts):'.PHP_EOL
                .$instructions;
    }

    private function ask(string $system, string $text): string
    {
        $usesFallback = in_array($this->model, self::FALLBACK_MODELS, true);

        try {
            $message = $this->client->beta->messages->create(
                maxTokens: 16000,
                messages: [['role' => 'user', 'content' => $text]],
                model: $this->model,
                // Proste zadanie: niski wysiłek = szybciej i taniej.
                outputConfig: ['effort' => 'low'],
                system: $system,
                // Przy odmowie (rzadkiej przy takim tekście) serwer ponawia na modelu zastępczym.
                fallbacks: $usesFallback ? 'default' : null,
                betas: $usesFallback ? ['server-side-fallback-2026-07-01'] : null,
            );
        } catch (AuthenticationException) {
            throw new AiException(__('The AI API key is invalid. Check it in Administration → AI.'));
        } catch (RateLimitException) {
            throw new AiException(__('Too many AI requests. Try again in a moment.'));
        } catch (BadRequestException $exception) {
            throw new AiException(__('The AI service rejected the request: :message', ['message' => $exception->getMessage()]));
        } catch (APIStatusException $exception) {
            throw new AiException($exception->type?->value === 'billing_error'
                ? __('The AI account has no credit left. Top it up at console.anthropic.com.')
                : __('The AI service is temporarily unavailable (:type).', ['type' => $exception->type->value ?? (string) $exception->getCode()]));
        } catch (APIConnectionException) {
            throw new AiException(__('Cannot reach the AI service. Check the internet connection.'));
        }

        // Koszt zapytania wg tokenów z odpowiedzi (saldo konta API nie udostępnia — liczymy sami).
        AiUsageLog::record(
            (string) $message->model,
            $message->usage->inputTokens,
            $message->usage->outputTokens,
            $message->usage->cacheReadInputTokens ?? 0,
            $message->usage->cacheCreationInputTokens ?? 0,
        );

        if ($message->stopReason === 'refusal') {
            throw new AiException(__('The AI declined to rewrite this text.'));
        }

        $result = '';

        foreach ($message->content as $block) {
            if ($block instanceof BetaTextBlock) {
                $result .= $block->text;
            }
        }

        $result = trim($result);

        if ($result === '') {
            throw new AiException(__('The AI returned an empty answer. Try again.'));
        }

        return $result;
    }
}
