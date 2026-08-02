<?php

namespace App\Services\Payments;

use App\Mail\TechnicalServicePaymentAuditMail;
use App\Models\MailTransportProfile;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use Illuminate\Http\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Mail;
use Throwable;

class TechnicalServiceMailTransportSettingsService
{
    public const NOT_READY_MESSAGE = 'Gerçek mail gönderimi için SMTP ayarları tamamlanmalı.';

    public function __construct(private readonly AuditLogger $auditLogger) {}

    /**
     * @return array<string, mixed>
     */
    public function payload(): array
    {
        $profile = $this->profile();

        return [
            'outgoing' => [
                'enabled' => (bool) $profile->outgoing_enabled,
                'mailer' => $profile->outgoing_mailer ?: MailTransportProfile::MAILER_SMTP,
                'host' => $profile->smtp_host,
                'port' => $profile->smtp_port,
                'encryption' => $this->normalizeEncryption($profile->smtp_encryption),
                'username_mask' => $profile->smtp_username_mask,
                'password_mask' => $profile->smtp_password_mask,
                'from_address' => $profile->from_address,
                'from_name' => $profile->from_name,
                'ready' => $profile->outgoingReady(),
                'status_label' => $profile->outgoingReady() ? 'SMTP hazır' : 'SMTP eksik',
                'readiness_message' => $profile->outgoingReady() ? 'SMTP mail gönderimi için hazır.' : self::NOT_READY_MESSAGE,
                'last_tested_at' => $profile->last_outgoing_tested_at?->toIso8601String(),
                'last_test_status' => $profile->last_outgoing_test_status,
                'last_test_message' => $profile->last_outgoing_test_message,
            ],
            'incoming' => [
                'enabled' => (bool) $profile->incoming_enabled,
                'protocol' => $this->normalizeIncomingProtocol($profile->incoming_protocol),
                'host' => $profile->incoming_host,
                'port' => $profile->incoming_port,
                'encryption' => $this->normalizeEncryption($profile->incoming_encryption),
                'username_mask' => $profile->incoming_username_mask,
                'password_mask' => $profile->incoming_password_mask,
                'mailbox' => $profile->incoming_mailbox ?: 'INBOX',
                'ready' => $profile->incomingReady(),
                'status_label' => $profile->incomingReady() ? 'Gelen kutu ayarı hazır' : 'Gelen kutu ayarı eksik',
                'readiness_message' => $profile->incomingReady()
                    ? 'IMAP/POP3 bağlantı testi yapılabilir.'
                    : 'Gelen kutu bağlantı testi için IMAP/POP3 ayarları tamamlanmalı.',
                'last_tested_at' => $profile->last_incoming_tested_at?->toIso8601String(),
                'last_test_status' => $profile->last_incoming_test_status,
                'last_test_message' => $profile->last_incoming_test_message,
            ],
            'payment_notification_ready' => $profile->outgoingReady(),
            'helper_texts' => [
                'outgoing' => 'SMTP mail göndermek için kullanılır.',
                'incoming' => 'IMAP/POP3 gelen kutu okumak içindir; ödeme bildirimi göndermek için kullanılmaz.',
                'secrets' => 'Şifreler encrypted saklanır; kayıttan sonra tam değer gösterilmez.',
            ],
        ];
    }

    public function scopedLocalUatConfigurationFingerprint(): string
    {
        $profile = $this->profile();
        $configuration = [
            'scope' => MailTransportProfile::SCOPE_TECHNICAL_SERVICE,
            'profile_key' => MailTransportProfile::PROFILE_DEFAULT,
            'profile_id' => $profile->exists ? (int) $profile->getKey() : null,
            'profile_revision' => $profile->updated_at?->toIso8601String(),
            'enabled' => (bool) $profile->outgoing_enabled,
            'transport' => (string) ($profile->outgoing_mailer ?: MailTransportProfile::MAILER_SMTP),
            'host' => strtolower(trim((string) $profile->smtp_host)),
            'port' => (int) $profile->smtp_port,
            'encryption' => $this->normalizeEncryption($profile->smtp_encryption),
            'credential_reference' => hash('sha256', implode('|', [
                (string) $profile->smtp_username_mask,
                (string) $profile->smtp_password_mask,
                $profile->updated_at?->toIso8601String() ?? '',
            ])),
            'username_identity_fingerprint' => hash('sha256', (string) $profile->smtp_username_mask),
            'from_address' => strtolower(trim((string) $profile->from_address)),
            'from_name' => trim((string) $profile->from_name),
            'event_revision' => 'sandbox_payment_notification:v1',
        ];

        return hash('sha256', json_encode($configuration, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function saveOutgoing(array $values, ?User $actor = null, ?Request $request = null): array
    {
        return app(TechnicalServiceMessagingSettingsService::class)
            ->withScopedLocalUatConfigurationMutationLock('smtp', function () use ($values, $actor, $request): array {
                $profile = $this->profile();
                $username = trim((string) ($values['username'] ?? ''));
                $password = trim((string) ($values['password'] ?? ''));

                $fill = [
                    'outgoing_enabled' => (bool) ($values['enabled'] ?? false),
                    'outgoing_mailer' => MailTransportProfile::MAILER_SMTP,
                    'smtp_host' => $this->nullableString($values['host'] ?? null),
                    'smtp_port' => isset($values['port']) ? (int) $values['port'] : null,
                    'smtp_encryption' => $this->normalizeEncryption($values['encryption'] ?? null),
                    'from_address' => $this->nullableString($values['from_address'] ?? null),
                    'from_name' => $this->nullableString($values['from_name'] ?? null),
                    'updated_by' => $actor?->id,
                ];

                if (! $profile->exists && $actor instanceof User) {
                    $fill['created_by'] = $actor->id;
                }

                if ($username !== '') {
                    $fill['smtp_username_encrypted'] = $username;
                    $fill['smtp_username_mask'] = $this->maskUsername($username);
                }

                if ($password !== '') {
                    $fill['smtp_password_encrypted'] = $password;
                    $fill['smtp_password_mask'] = str_repeat('*', 12);
                }

                $profile->forceFill($fill)->save();

                $this->auditLogger->log($actor, 'technical_service.mail_transport.outgoing_saved', [
                    'scope' => MailTransportProfile::SCOPE_TECHNICAL_SERVICE,
                    'profile_key' => MailTransportProfile::PROFILE_DEFAULT,
                    'outgoing_enabled' => (bool) $profile->outgoing_enabled,
                    'smtp_host' => $profile->smtp_host,
                    'smtp_username_mask' => $profile->smtp_username_mask,
                    'from_address' => $profile->from_address,
                ], $request);

                return $this->payload();
            });
    }

    /**
     * @return array<string, mixed>
     */
    public function clearOutgoing(?User $actor = null, ?Request $request = null): array
    {
        return app(TechnicalServiceMessagingSettingsService::class)
            ->withScopedLocalUatConfigurationMutationLock('smtp', function () use ($actor, $request): array {
                $profile = $this->profile();
                $profile->forceFill([
                    'outgoing_enabled' => false,
                    'smtp_host' => null,
                    'smtp_port' => null,
                    'smtp_encryption' => null,
                    'smtp_username_encrypted' => null,
                    'smtp_password_encrypted' => null,
                    'smtp_username_mask' => null,
                    'smtp_password_mask' => null,
                    'from_address' => null,
                    'from_name' => null,
                    'last_outgoing_tested_at' => null,
                    'last_outgoing_test_status' => null,
                    'last_outgoing_test_message' => null,
                    'updated_by' => $actor?->id,
                ])->save();

                $this->auditLogger->log($actor, 'technical_service.mail_transport.outgoing_cleared', [
                    'scope' => MailTransportProfile::SCOPE_TECHNICAL_SERVICE,
                    'profile_key' => MailTransportProfile::PROFILE_DEFAULT,
                ], $request);

                return $this->payload();
            });
    }

    /**
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function saveIncoming(array $values, ?User $actor = null, ?Request $request = null): array
    {
        $profile = $this->profile();
        $username = trim((string) ($values['username'] ?? ''));
        $password = trim((string) ($values['password'] ?? ''));

        $fill = [
            'incoming_enabled' => (bool) ($values['enabled'] ?? false),
            'incoming_protocol' => $this->normalizeIncomingProtocol($values['protocol'] ?? null),
            'incoming_host' => $this->nullableString($values['host'] ?? null),
            'incoming_port' => isset($values['port']) ? (int) $values['port'] : null,
            'incoming_encryption' => $this->normalizeEncryption($values['encryption'] ?? null),
            'incoming_mailbox' => $this->nullableString($values['mailbox'] ?? null) ?: 'INBOX',
            'updated_by' => $actor?->id,
        ];

        if (! $profile->exists && $actor instanceof User) {
            $fill['created_by'] = $actor->id;
        }

        if ($username !== '') {
            $fill['incoming_username_encrypted'] = $username;
            $fill['incoming_username_mask'] = $this->maskUsername($username);
        }

        if ($password !== '') {
            $fill['incoming_password_encrypted'] = $password;
            $fill['incoming_password_mask'] = str_repeat('*', 12);
        }

        $profile->forceFill($fill)->save();

        $this->auditLogger->log($actor, 'technical_service.mail_transport.incoming_saved', [
            'scope' => MailTransportProfile::SCOPE_TECHNICAL_SERVICE,
            'profile_key' => MailTransportProfile::PROFILE_DEFAULT,
            'incoming_enabled' => (bool) $profile->incoming_enabled,
            'incoming_protocol' => $profile->incoming_protocol,
            'incoming_host' => $profile->incoming_host,
            'incoming_username_mask' => $profile->incoming_username_mask,
        ], $request);

        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function clearIncoming(?User $actor = null, ?Request $request = null): array
    {
        $profile = $this->profile();
        $profile->forceFill([
            'incoming_enabled' => false,
            'incoming_protocol' => null,
            'incoming_host' => null,
            'incoming_port' => null,
            'incoming_encryption' => null,
            'incoming_username_encrypted' => null,
            'incoming_password_encrypted' => null,
            'incoming_username_mask' => null,
            'incoming_password_mask' => null,
            'incoming_mailbox' => null,
            'last_incoming_tested_at' => null,
            'last_incoming_test_status' => null,
            'last_incoming_test_message' => null,
            'updated_by' => $actor?->id,
        ])->save();

        $this->auditLogger->log($actor, 'technical_service.mail_transport.incoming_cleared', [
            'scope' => MailTransportProfile::SCOPE_TECHNICAL_SERVICE,
            'profile_key' => MailTransportProfile::PROFILE_DEFAULT,
        ], $request);

        return $this->payload();
    }

    /**
     * @return array<string, mixed>
     */
    public function sendTestMail(string $recipient): array
    {
        app(TechnicalServiceMessagingSettingsService::class)->assertScopedLocalUatSmtpTestAllowed();
        $profile = $this->profile();

        if (! $profile->outgoingReady()) {
            $this->recordOutgoingTest($profile, MailTransportProfile::STATUS_BLOCKED, self::NOT_READY_MESSAGE);

            throw new TechnicalServiceMailTransportNotReadyException(self::NOT_READY_MESSAGE);
        }

        try {
            $this->configureProfileMailer($profile);
            Mail::mailer('technical_service_smtp')->raw(
                "EMAKS Teknik Servis SMTP test mailidir.\n\nBu mail admin panelindeki SMTP ayarlarıyla gönderildi.",
                function (Message $message) use ($recipient): void {
                    $message->to($recipient)->subject('EMAKS Teknik Servis SMTP Test');
                },
            );
            $this->recordOutgoingTest($profile, MailTransportProfile::STATUS_SENT, 'Test mail gönderildi.');
        } catch (Throwable $exception) {
            $message = $this->redactedError($exception);
            $this->recordOutgoingTest($profile, MailTransportProfile::STATUS_FAILED, $message);

            throw $exception;
        }

        return $this->payload();
    }

    public function sendPaymentAuditMail(array $recipients, TechnicalServicePaymentAuditMail $mail): void
    {
        $profile = $this->profile();
        $authority = app(TechnicalServiceMessagingSettingsService::class);
        $claim = $authority->claimScopedLocalUatEmailEffect($mail->payment, $recipients);

        if ($claim['duplicate']) {
            return;
        }

        if (! $profile->outgoingReady()) {
            if (is_string($claim['claim_nonce'])) {
                $authority->failScopedLocalUatEffect($claim['claim_nonce']);
            }

            throw new TechnicalServiceMailTransportNotReadyException(self::NOT_READY_MESSAGE);
        }

        try {
            if (is_string($claim['claim_nonce'])) {
                $mail->build();
                $authority->beginScopedLocalUatEffectDispatch($claim['claim_nonce']);
            }
            $this->sendUsingProfile($profile, $recipients, $mail);
            if (is_string($claim['claim_nonce'])) {
                $authority->completeScopedLocalUatEffect($claim['claim_nonce']);
            }
        } catch (Throwable $exception) {
            if (is_string($claim['claim_nonce'])) {
                $authority->failScopedLocalUatEffect($claim['claim_nonce'], $exception);
            }

            throw $exception;
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function testIncomingConnection(): array
    {
        $profile = $this->profile();

        if (! $profile->incomingReady()) {
            $this->recordIncomingTest($profile, MailTransportProfile::STATUS_BLOCKED, 'Gelen kutu bağlantı testi için IMAP/POP3 ayarları tamamlanmalı.');

            return $this->payload();
        }

        try {
            $message = $profile->incoming_protocol === MailTransportProfile::PROTOCOL_IMAP
                ? $this->testImapConnection($profile)
                : $this->testPop3Connection($profile);

            $this->recordIncomingTest($profile, MailTransportProfile::STATUS_READY, $message);
        } catch (Throwable $exception) {
            $this->recordIncomingTest($profile, MailTransportProfile::STATUS_FAILED, $this->redactedError($exception));

            throw $exception;
        }

        return $this->payload();
    }

    public function profile(): MailTransportProfile
    {
        return MailTransportProfile::query()->firstOrNew([
            'scope' => MailTransportProfile::SCOPE_TECHNICAL_SERVICE,
            'profile_key' => MailTransportProfile::PROFILE_DEFAULT,
        ], [
            'display_name' => 'Teknik Servis Mail',
            'outgoing_mailer' => MailTransportProfile::MAILER_SMTP,
            'incoming_protocol' => MailTransportProfile::PROTOCOL_IMAP,
            'incoming_mailbox' => 'INBOX',
        ]);
    }

    private function sendUsingProfile(MailTransportProfile $profile, array $recipients, TechnicalServicePaymentAuditMail $mail): void
    {
        $this->configureProfileMailer($profile);
        Mail::mailer('technical_service_smtp')->to($recipients)->send($mail);
    }

    private function configureProfileMailer(MailTransportProfile $profile): void
    {
        $mailerName = 'technical_service_smtp';
        config([
            "mail.mailers.{$mailerName}" => [
                'transport' => 'smtp',
                'host' => $profile->smtp_host,
                'port' => (int) $profile->smtp_port,
                'encryption' => $profile->smtp_encryption === 'none' ? null : $profile->smtp_encryption,
                'username' => $profile->smtp_username_encrypted,
                'password' => $profile->smtp_password_encrypted,
                'timeout' => 15,
                'local_domain' => parse_url((string) config('app.url'), PHP_URL_HOST) ?: null,
            ],
            'mail.from.address' => $profile->from_address,
            'mail.from.name' => $profile->from_name ?: config('app.name'),
        ]);

        app(MailManager::class)->forgetMailers();
    }

    private function testImapConnection(MailTransportProfile $profile): string
    {
        if (! extension_loaded('imap')) {
            return 'IMAP bağlantı testi için PHP imap extension gerekli. Bu fazda mesaj çekilmedi.';
        }

        $flags = $profile->incoming_encryption === 'ssl'
            ? '/imap/ssl'
            : ($profile->incoming_encryption === 'tls' ? '/imap/tls' : '/imap/notls');
        $mailbox = sprintf('{%s:%d%s}%s', $profile->incoming_host, (int) $profile->incoming_port, $flags, $profile->incoming_mailbox ?: 'INBOX');
        $connection = @imap_open($mailbox, (string) $profile->incoming_username_encrypted, (string) $profile->incoming_password_encrypted, OP_HALFOPEN);

        if ($connection === false) {
            $error = imap_last_error() ?: 'IMAP bağlantısı kurulamadı.';
            throw new \RuntimeException($this->redactSecretLikeText($error));
        }

        imap_close($connection);

        return 'IMAP bağlantısı doğrulandı; mesaj çekilmedi.';
    }

    private function testPop3Connection(MailTransportProfile $profile): string
    {
        $scheme = in_array($profile->incoming_encryption, ['ssl', 'tls'], true) ? 'ssl://' : '';
        $socket = @fsockopen($scheme.$profile->incoming_host, (int) $profile->incoming_port, $errno, $errstr, 10);

        if (! is_resource($socket)) {
            throw new \RuntimeException($this->redactSecretLikeText($errstr ?: 'POP3 bağlantısı kurulamadı.'));
        }

        stream_set_timeout($socket, 10);
        fgets($socket);
        fwrite($socket, 'USER '.(string) $profile->incoming_username_encrypted."\r\n");
        $userResponse = fgets($socket) ?: '';
        fwrite($socket, 'PASS '.(string) $profile->incoming_password_encrypted."\r\n");
        $passResponse = fgets($socket) ?: '';
        fwrite($socket, "QUIT\r\n");
        fclose($socket);

        if (! str_starts_with($userResponse, '+OK') || ! str_starts_with($passResponse, '+OK')) {
            throw new \RuntimeException('POP3 kullanıcı adı veya parola doğrulanamadı.');
        }

        return 'POP3 bağlantısı doğrulandı; mesaj çekilmedi.';
    }

    private function recordOutgoingTest(MailTransportProfile $profile, string $status, string $message): void
    {
        $profile->forceFill([
            'last_outgoing_tested_at' => now(),
            'last_outgoing_test_status' => $status,
            'last_outgoing_test_message' => $this->redactSecretLikeText($message),
        ])->save();
    }

    private function recordIncomingTest(MailTransportProfile $profile, string $status, string $message): void
    {
        $profile->forceFill([
            'last_incoming_tested_at' => now(),
            'last_incoming_test_status' => $status,
            'last_incoming_test_message' => $this->redactSecretLikeText($message),
        ])->save();
    }

    private function normalizeEncryption(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return in_array($value, ['tls', 'ssl', 'none'], true) ? $value : 'tls';
    }

    private function normalizeIncomingProtocol(mixed $value): string
    {
        $value = strtolower(trim((string) $value));

        return $value === MailTransportProfile::PROTOCOL_POP3
            ? MailTransportProfile::PROTOCOL_POP3
            : MailTransportProfile::PROTOCOL_IMAP;
    }

    private function nullableString(mixed $value): ?string
    {
        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function maskUsername(string $username): string
    {
        if (str_contains($username, '@')) {
            [$local, $domain] = array_pad(explode('@', $username, 2), 2, '');
            $prefix = mb_substr($local, 0, min(3, mb_strlen($local)));
            $suffix = mb_strlen($local) > 5 ? mb_substr($local, -2) : '';

            return $prefix.'****'.$suffix.'@'.$domain;
        }

        if (mb_strlen($username) <= 6) {
            return mb_substr($username, 0, 1).'****'.mb_substr($username, -1);
        }

        return mb_substr($username, 0, 3).'****'.mb_substr($username, -3);
    }

    private function redactedError(Throwable $exception): string
    {
        return $this->redactSecretLikeText($exception->getMessage() ?: 'Mail işlemi başarısız.');
    }

    private function redactSecretLikeText(string $message): string
    {
        $redacted = preg_replace(
            '/\b(password|parola|secret|api[_\s-]?key|auth(?:orization)?|signature|token|username|kullanıcı)\s*[:=]\s*[^,\s;]+/i',
            '$1=[redacted]',
            $message,
        );

        return trim((string) ($redacted ?: 'Mail işlemi başarısız.'));
    }
}
