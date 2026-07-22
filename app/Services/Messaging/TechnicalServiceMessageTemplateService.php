<?php

namespace App\Services\Messaging;

use App\Models\TechnicalServiceMessageTemplate;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TechnicalServiceMessageTemplateService
{
    public function __construct(
        private readonly TechnicalServiceMessageTypeRegistry $types,
        private readonly TechnicalServiceMessageVariableRegistry $variables,
        private readonly TechnicalServiceMessageContextBuilder $contextBuilder,
        private readonly TechnicalServiceMessageTemplateRenderer $renderer,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        return [
            'message_types' => array_values($this->types->definitions()),
            'channels' => collect(TechnicalServiceMessageTypeRegistry::CHANNELS)
                ->map(fn (string $label, string $key): array => ['key' => $key, 'label' => $label])
                ->values()
                ->all(),
            'providers' => collect(TechnicalServiceMessagingSettingsService::PROVIDERS)
                ->map(fn (array $definition, string $key): array => [
                    'key' => $key,
                    'label' => $definition['label'],
                    'channel' => $definition['channel'],
                    'contract_confirmed' => (bool) ($definition['contract_confirmed'] ?? false),
                ])
                ->values()
                ->all(),
            'variables' => $this->variablePayload(),
            'templates' => $this->templates(),
            'forbidden_variables' => $this->variables->forbiddenVariables(),
            'no_send' => true,
            'helper_text' => 'REL-4C business mesajı göndermez; manuel template test gönderimi sadece ortak test telefonuna izin verir.',
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function templates(): array
    {
        $templates = [];

        foreach ($this->types->definitions() as $messageType => $definition) {
            foreach ($definition['allowed_channels'] as $channel) {
                $templateKey = $this->types->defaultTemplateKey($messageType, $channel);
                $templates[] = $this->templateByKey($templateKey, $messageType, $channel);
            }
        }

        return $templates;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $messageType = (string) ($input['message_type'] ?? '');
        $channel = (string) ($input['channel'] ?? '');
        $providerKey = $this->nullableString($input['provider_key'] ?? null);

        $this->validateTemplateIdentity($messageType, $channel, $providerKey);

        $templateKey = $this->nullableString($input['template_key'] ?? null)
            ?: $this->types->defaultTemplateKey($messageType, $channel);
        $body = trim((string) ($input['body'] ?? ''));

        if ($body === '') {
            throw ValidationException::withMessages(['body' => 'Şablon gövdesi zorunlu.']);
        }

        $template = [
            'template_key' => $templateKey,
            'message_type' => $messageType,
            'channel' => $channel,
            'provider_key' => $providerKey,
            'title' => trim((string) ($input['title'] ?? '')) ?: $this->types->defaultTemplate($messageType, $channel, $providerKey)['title'],
            'body' => $body,
            'active' => (bool) ($input['active'] ?? true),
            'locale' => 'tr',
            'required_variables' => $this->stringList($input['required_variables'] ?? $this->types->requiredVariables($messageType)),
            'optional_variables' => $this->stringList($input['optional_variables'] ?? $this->types->optionalVariables($messageType)),
            'validation_rules' => is_array($input['validation_rules'] ?? null)
                ? $input['validation_rules']
                : $this->types->payerStateRequirements($messageType),
            'metadata' => [
                'source' => 'admin',
                'send_enabled' => false,
            ],
        ];

        $preview = $this->preview([
            ...$template,
            'sample_context' => true,
        ]);

        if ($preview['forbidden_variables'] !== []) {
            throw ValidationException::withMessages(['body' => 'Şablon yasak değişken içeriyor.']);
        }

        $latestVersion = (int) TechnicalServiceMessageTemplate::query()
            ->where('template_key', $templateKey)
            ->max('version');

        TechnicalServiceMessageTemplate::query()
            ->where('template_key', $templateKey)
            ->where('active', true)
            ->update([
                'active' => false,
                'superseded_at' => now(),
                'updated_by' => Auth::id(),
            ]);

        $record = TechnicalServiceMessageTemplate::query()->create([
            ...$template,
            'version' => max(1, $latestVersion + 1),
            'created_by' => Auth::id(),
            'updated_by' => Auth::id(),
        ]);

        return [
            'template' => $this->templatePayload($record->toArray(), false),
            'templates' => $this->templates(),
            'preview' => $preview,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function restoreDefault(array $input): array
    {
        $messageType = (string) ($input['message_type'] ?? '');
        $channel = (string) ($input['channel'] ?? '');
        $providerKey = $this->nullableString($input['provider_key'] ?? null);

        $this->validateTemplateIdentity($messageType, $channel, $providerKey);

        $templateKey = $this->types->defaultTemplateKey($messageType, $channel);

        TechnicalServiceMessageTemplate::query()
            ->where('template_key', $templateKey)
            ->where('active', true)
            ->update([
                'active' => false,
                'superseded_at' => now(),
                'updated_by' => Auth::id(),
            ]);

        return [
            'template' => $this->templateByKey($templateKey, $messageType, $channel, $providerKey),
            'templates' => $this->templates(),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function preview(array $input): array
    {
        $messageType = (string) ($input['message_type'] ?? '');
        $channel = (string) ($input['channel'] ?? TechnicalServiceMessageTemplate::CHANNEL_WHATSAPP);
        $providerKey = $this->nullableString($input['provider_key'] ?? null);

        $this->validateTemplateIdentity($messageType, $channel, $providerKey);

        $template = $this->resolveTemplate($input, $messageType, $channel, $providerKey);
        $contextPayload = $this->contextBuilder->build($messageType, $channel, $input);
        $render = $this->renderer->render($template, $contextPayload['context']);

        return [
            'template' => $this->templatePayload($template, ! isset($template['id'])),
            'rendered_body' => $render['rendered_body'],
            'missing_variables' => $render['missing_variables'],
            'unresolved_variables' => $render['unresolved_variables'],
            'forbidden_variables' => array_values(array_intersect($render['placeholders'], $this->variables->forbiddenVariables())),
            'warnings' => $render['warnings'],
            'blockers' => $render['blockers'],
            'sms' => $render['sms'],
            'preview_ready' => $render['preview_ready'],
            'send_ready' => false,
            'payer_state_key' => $render['payer_state_key'],
            'recipient_role' => $contextPayload['recipient_role'],
            'recipient_phone' => $contextPayload['recipient_phone'],
            'context' => $contextPayload['context'],
            'no_send' => true,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function resolveTemplate(array $input, string $messageType, string $channel, ?string $providerKey): array
    {
        if (array_key_exists('body', $input) && trim((string) $input['body']) !== '') {
            return [
                'template_key' => $this->nullableString($input['template_key'] ?? null) ?: $this->types->defaultTemplateKey($messageType, $channel),
                'message_type' => $messageType,
                'channel' => $channel,
                'provider_key' => $providerKey,
                'title' => trim((string) ($input['title'] ?? 'Önizleme')),
                'body' => (string) $input['body'],
                'required_variables' => $this->stringList($input['required_variables'] ?? $this->types->requiredVariables($messageType)),
                'optional_variables' => $this->stringList($input['optional_variables'] ?? $this->types->optionalVariables($messageType)),
                'validation_rules' => $this->types->payerStateRequirements($messageType),
            ];
        }

        $templateKey = $this->nullableString($input['template_key'] ?? null)
            ?: $this->types->defaultTemplateKey($messageType, $channel);

        return $this->templateByKey($templateKey, $messageType, $channel, $providerKey);
    }

    /**
     * @return array<string, mixed>
     */
    private function templateByKey(string $templateKey, string $messageType, string $channel, ?string $providerKey = null): array
    {
        $record = TechnicalServiceMessageTemplate::query()
            ->where('template_key', $templateKey)
            ->where('active', true)
            ->latest('version')
            ->first();

        if ($record instanceof TechnicalServiceMessageTemplate) {
            return $this->templatePayload($record->toArray(), false);
        }

        return $this->templatePayload($this->types->defaultTemplate($messageType, $channel, $providerKey), true);
    }

    /**
     * @param  array<string, mixed>  $template
     * @return array<string, mixed>
     */
    private function templatePayload(array $template, bool $isDefault): array
    {
        return [
            'id' => $template['id'] ?? null,
            'template_key' => $template['template_key'],
            'message_type' => $template['message_type'],
            'channel' => $template['channel'],
            'provider_key' => $template['provider_key'] ?? null,
            'title' => $template['title'],
            'body' => $template['body'],
            'active' => (bool) ($template['active'] ?? true),
            'locale' => $template['locale'] ?? 'tr',
            'version' => (int) ($template['version'] ?? 1),
            'required_variables' => $this->stringList($template['required_variables'] ?? []),
            'optional_variables' => $this->stringList($template['optional_variables'] ?? []),
            'validation_rules' => is_array($template['validation_rules'] ?? null) ? $template['validation_rules'] : [],
            'metadata' => is_array($template['metadata'] ?? null) ? $template['metadata'] : [],
            'is_default' => $isDefault,
            'no_send' => true,
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function variablePayload(): array
    {
        return collect($this->variables->definitions())
            ->map(fn (array $definition, string $key): array => [
                ...$definition,
                'key' => $key,
            ])
            ->values()
            ->all();
    }

    private function validateTemplateIdentity(string $messageType, string $channel, ?string $providerKey): void
    {
        if (! $this->types->knownMessageType($messageType)) {
            throw ValidationException::withMessages(['message_type' => 'Bilinmeyen mesaj tipi.']);
        }

        if (! $this->types->knownChannel($channel)) {
            throw ValidationException::withMessages(['channel' => 'Bilinmeyen kanal.']);
        }

        if (! in_array($channel, $this->types->allowedChannels($messageType), true)) {
            throw ValidationException::withMessages(['channel' => 'Bu mesaj tipi için kanal desteklenmiyor.']);
        }

        if (! $this->types->knownProvider($providerKey)) {
            throw ValidationException::withMessages(['provider_key' => 'Bilinmeyen sağlayıcı.']);
        }
    }

    /**
     * @return array<int, string>
     */
    private function stringList(mixed $values): array
    {
        return collect(is_array($values) ? $values : [])
            ->filter(fn (mixed $value): bool => is_string($value) && trim($value) !== '')
            ->map(fn (string $value): string => trim($value))
            ->values()
            ->all();
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
