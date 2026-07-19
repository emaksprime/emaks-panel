<?php

namespace Tests\Feature;

use App\Models\TechnicalServiceMessageDispatch;
use App\Models\TechnicalServiceMessageTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class TechnicalServiceMessageTemplateTest extends TestCase
{
    use RefreshDatabase;

    public function test_message_template_can_be_saved_and_previewed_without_send(): void
    {
        Http::fake();

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'title' => 'Randevu onay test',
                'body' => 'Merhaba {customer_name}, randevunuz {appointment_date} {appointment_time}.',
                'required_variables' => ['customer_name', 'appointment_date', 'appointment_time'],
            ])
            ->assertOk()
            ->assertJsonPath('message_templates.preview.preview_ready', true)
            ->json('message_templates');

        $this->assertDatabaseHas('technical_service_message_templates', [
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'active' => true,
        ]);
        $this->assertStringContainsString('PR88 Test Müşteri', $payload['preview']['rendered_body']);
        $this->assertFalse($payload['preview']['send_ready']);
        Http::assertNothingSent();
    }

    public function test_template_preview_endpoint_renders_default_sample_context(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->assertJsonPath('preview.no_send', true)
            ->assertJsonFragment(['send_ready' => false]);
    }

    public function test_message_template_can_be_restored_to_default(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates', [
                'message_type' => 'payment_link_customer',
                'channel' => 'sms',
                'body' => 'Ödeme: {payment_link}',
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/restore-default', [
                'message_type' => 'payment_link_customer',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJsonPath('message_templates.template.is_default', true);

        $this->assertSame(0, TechnicalServiceMessageTemplate::query()->where('active', true)->count());
    }

    public function test_unknown_message_type_and_channel_are_rejected(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'unknown',
                'channel' => 'whatsapp',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['message_type']);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'fax',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel']);
    }

    public function test_variable_registry_lists_required_variables_and_forbidden_are_rejected(): void
    {
        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-templates')
            ->assertOk()
            ->json('message_templates');

        $this->assertContains('customer_name', collect($payload['variables'])->pluck('key'));
        $this->assertContains('payment_link_sms', collect($payload['variables'])->pluck('key'));
        $this->assertContains('confirmation_link_sms', collect($payload['variables'])->pluck('key'));
        $this->assertContains('technician_job_card_short_url', collect($payload['variables'])->pluck('key'));
        $this->assertContains('sms_payment_line', collect($payload['variables'])->pluck('key'));
        $this->assertContains('internal_note', $payload['forbidden_variables']);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'body' => 'Gizli not: {internal_note}',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['body']);
    }

    public function test_unresolved_undefined_null_and_nan_block_preview_ready(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'body' => 'Merhaba {customer_name} {unknown_variable} undefined null NaN',
                'context' => [
                    'customer_name' => 'Burhan Test',
                    'appointment_date' => '03.07.2026',
                    'appointment_time' => '14:00',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Bilinmeyen değişken: unknown_variable.']);
    }

    public function test_missing_payment_and_confirmation_links_block_preview(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'payment_link_customer',
                'channel' => 'sms',
                'body' => 'Ödeme linki: {payment_link}',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Link Test',
                    'payer_state_key' => 'pending_online_payment',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Ödeme linki mesajı için payment_link zorunlu.']);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'customer_approval_request',
                'channel' => 'whatsapp',
                'body' => 'Onay: {confirmation_link}',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Onay Test',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Müşteri onayı mesajı için confirmation_link zorunlu.']);
    }

    public function test_customer_pays_technician_requires_positive_amount_and_formats_try(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'customer_pays_technician_notice',
                'channel' => 'whatsapp',
                'body' => 'Ustaya ödenecek tutar: {customer_payment_amount_formatted}',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Tutar Test',
                    'payer_state_key' => 'customer_pays_technician',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Ustaya ödeme mesajı için pozitif müşteri ödeme tutarı zorunlu.']);

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'customer_pays_technician_notice',
                'channel' => 'whatsapp',
                'body' => 'Ustaya ödenecek tutar: {customer_payment_amount_formatted}',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Tutar Test',
                    'payer_state_key' => 'customer_pays_technician',
                    'customer_payment_amount' => 1250,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $this->assertStringContainsString('1.250,00 TL', $payload['rendered_body']);
    }

    public function test_customer_payment_note_customer_pays_technician_whatsapp_includes_cash_transfer_note(): void
    {
        $body = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Ödeme Notu',
                    'mrn' => 'MRN-PR88-NOTE',
                    'appointment_date' => '2026-07-08',
                    'appointment_time' => '14:00 - 16:00',
                    'payer_state_key' => 'customer_pays_technician',
                    'customer_payment_amount' => 1250,
                    'customer_payment_note_text' => 'Ödemeler nakit ve havale kabul edilmektedir.',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('Randevu sırasında ustaya ödenecek tutar: 1.250,00 TL.', $body);
        $this->assertStringContainsString('Not: Ödemeler nakit ve havale kabul edilmektedir.', $body);
    }

    public function test_customer_payment_note_company_collected_and_no_payment_omit_payment_note(): void
    {
        foreach (['company_collected_online', 'no_payment'] as $payerState) {
            $body = $this->actingAs($this->admin())
                ->postJson('/api/technical-service/message-templates/preview', [
                    'message_type' => 'appointment_approved_customer',
                    'channel' => 'whatsapp',
                    'sample_context' => false,
                    'context' => [
                        'customer_name' => 'PR88 Ödeme Yok',
                        'mrn' => 'MRN-PR88-NO-PAY',
                        'appointment_date' => '2026-07-08',
                        'appointment_time' => '14:00 - 16:00',
                        'payer_state_key' => $payerState,
                        'customer_payment_note_text' => 'Ödemeler nakit ve havale kabul edilmektedir.',
                    ],
                ])
                ->assertOk()
                ->assertJsonPath('preview.preview_ready', true)
                ->json('preview.rendered_body');

            $this->assertStringNotContainsString('Ödemeler nakit ve havale kabul edilmektedir.', $body);
            $this->assertStringNotContainsString('ustaya ödenecek tutar', mb_strtolower($body, 'UTF-8'));
        }
    }

    public function test_company_collected_message_cannot_say_pay_technician(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'body' => 'Merhaba {customer_name}, ustaya ödeme yapınız. Randevu {appointment_date} {appointment_time}.',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Tahsil Test',
                    'appointment_date' => '03.07.2026',
                    'appointment_time' => '14:00',
                    'payer_state_key' => 'company_collected_online',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Şirket tahsil etmişken müşteri mesajı ustaya ödeme talimatı içeremez.']);
    }

    public function test_sms_preview_counts_segments_warns_and_does_not_send(): void
    {
        Http::fake();

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
                'body' => 'Merhaba {customer_name}, randevunuz {appointment_date} {appointment_time}. Çağrı linki: https://panel.example.test/uzun-link',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $this->assertGreaterThan(0, $payload['sms']['characters']);
        $this->assertGreaterThanOrEqual(1, $payload['sms']['segments']);
        $this->assertSame('unicode', $payload['sms']['encoding']);
        $this->assertNotEmpty($payload['warnings']);
        Http::assertNothingSent();
    }

    public function test_voice_script_preview_available_without_voibot_call(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'voice_script',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->assertJsonFragment(['Voibot contract pending; bu sadece voice script önizlemesidir, çağrı yapılmaz.']);

        Http::assertNothingSent();
    }

    public function test_template_test_send_allows_evo_test_when_real_send_disabled_and_targets_shared_phone(): void
    {
        config([
            'services.evolution.n8n_webhook_url' => 'https://n8n.example.test/webhook/emaks/evo/send-message',
            'services.evolution.real_send_enabled' => false,
            'services.evolution.test_mode' => true,
            'services.evolution.test_phone' => '905467647428',
            'services.evolution.allow_unit_test_http_fake' => true,
        ]);
        Http::fake([
            'n8n.example.test/*' => Http::response(['ok' => true], 200),
        ]);

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'test_phone' => '905467647428',
                'active_provider' => 'evo_whatsapp',
                'message_types' => [
                    'appointment_approved_customer' => [
                        'enabled' => true,
                        'test_send_allowed' => true,
                    ],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('test_send.dispatch.status', 'sent')
            ->assertJsonPath('test_send.dispatch.target_type', 'shared_test_phone')
            ->assertJsonPath('test_send.dispatch.target_phone_masked', '9054***428');

        Http::assertSent(function ($request): bool {
            $payload = $request->data();

            return $request->url() === 'https://n8n.example.test/webhook/emaks/evo/send-message'
                && $payload['target_phone'] === '905467647428'
                && $payload['target_type'] === 'shared_test_phone'
                && $payload['message_type'] === 'appointment_approved_customer'
                && str_contains($payload['text'], "\n\nSayın")
                && str_contains($payload['text'], "\nRandevu Bilgileri\n")
                && str_contains($payload['text'], 'EMAKS Prime Teknik Servis');
        });
    }

    public function test_nac_sms_test_send_requires_explicit_sms_approval(): void
    {
        Http::fake();

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'test_phone' => '905467647428',
                'active_provider' => 'nac_sms',
                'nac_sms' => [
                    'enabled' => true,
                    'sender' => 'EMAKS',
                    'title' => 'EMAKS',
                ],
                'message_types' => [
                    'appointment_approved_customer' => [
                        'enabled' => true,
                        'test_send_allowed' => true,
                        'channel_policy' => 'sms_only',
                        'sms_mode' => 'test',
                    ],
                ],
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['real_sms_confirmed']);

        Http::assertNothingSent();
    }

    public function test_template_test_sms_uses_rendered_template_content_with_turkish_encoding_no_n8n_basic_auth_safe_and_no_visible_random_code(): void
    {
        Http::fake([
            'smslogin.nac.com.tr:9587/*' => Http::response([
                'data' => [
                    'pkgID' => 'PKG-REL4C7-TEST',
                ],
            ], 200),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/nac-sms/credentials', [
                'username' => 'nac-user@example.test',
                'password' => 'PR88_NAC_CREDENTIAL_TEST_ONLY',
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'test_phone' => '905467647428',
                'active_provider' => 'nac_sms',
                'nac_sms' => [
                    'enabled' => true,
                    'profile' => 'legacy_working_http_9587',
                    'scheme' => 'http',
                    'host' => 'smslogin.nac.com.tr',
                    'port' => 9587,
                    'path' => '/sms/create',
                    'request_shape' => 'legacy_working_minimal',
                    'sender' => 'EMAKS PRIME',
                    'title' => '',
                    'encoding' => 0,
                    'validity' => 60,
                    'recipient_type' => 0,
                    'use_shared_test_phone' => true,
                ],
                'message_types' => [
                    'appointment_approved_customer' => [
                        'enabled' => true,
                        'test_send_allowed' => true,
                        'channel_policy' => 'sms_only',
                        'sms_mode' => 'test',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.nac_sms.test_ready', true);

        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'real_sms_confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJsonPath('test_send.dispatch.status', 'sent')
            ->assertJsonPath('test_send.dispatch.target_type', 'shared_test_phone')
            ->assertJsonPath('test_send.dispatch.target_phone_masked', '9054***428')
            ->assertJsonPath('test_send.dispatch.provider_reference', 'PKG-REL4C7-TEST')
            ->assertJsonPath('test_send.dispatch.test_type', 'template_test_sms')
            ->assertJsonPath('test_send.dispatch.encoding', 1);

        $testCode = $response->json('test_send.dispatch.test_code');
        $customId = $response->json('test_send.dispatch.custom_id');
        $payloadHash = $response->json('test_send.dispatch.payload_hash');
        $previewBody = $response->json('test_send.preview.rendered_body');
        $contentPreview = $response->json('test_send.dispatch.content_preview');
        $encodedResponse = $response->getContent();
        $this->assertIsString($testCode);
        $this->assertMatchesRegularExpression('/^B\d{3,}$/', $testCode);
        $this->assertMatchesRegularExpression(
            '/^nac-test-'.$response->json('test_send.dispatch.id').'-T\d{8}-\d{6}-[A-Z0-9]{4}$/',
            (string) $customId,
        );
        $this->assertIsString($payloadHash);
        $this->assertIsString($previewBody);
        $this->assertStringContainsString('randevunuz onaylanmıştır', $previewBody);
        $this->assertStringContainsString('ödenecek tutar', $previewBody);
        $this->assertStringContainsString('randevunuz onaylanmıştır', (string) $contentPreview);
        $this->assertStringNotContainsString('Authorization', $encodedResponse);
        $this->assertStringNotContainsString('Basic ', $encodedResponse);
        $this->assertStringNotContainsString('nac-user@example.test', $encodedResponse);
        $this->assertStringNotContainsString('PR88_NAC_CREDENTIAL_TEST_ONLY', $encodedResponse);

        Http::assertSent(function ($request) use ($customId, $testCode, $previewBody): bool {
            $payload = $request->data();

            return $request->url() === 'http://smslogin.nac.com.tr:9587/sms/create'
                && $payload['number'] === 905467647428
                && $payload['sender'] === 'EMAKS PRIME'
                && $payload['title'] === 'EMAKS TPL '.$testCode
                && $payload['type'] === 1
                && $payload['sendingType'] === 0
                && $payload['encoding'] === 1
                && $payload['periodicSettings'] === null
                && $payload['sendingDate'] === null
                && $payload['validity'] === 60
                && $payload['pushSettings'] === null
                && $payload['customID'] === $customId
                && ! array_key_exists('gateway', $payload)
                && ! array_key_exists('commercial', $payload)
                && ! array_key_exists('skipAhsQuery', $payload)
                && ! array_key_exists('recipientType', $payload)
                && $payload['content'] === $previewBody
                && str_contains($payload['content'], 'randevunuz onaylanmıştır')
                && str_contains($payload['content'], 'ödenecek tutar')
                && ! str_contains($payload['content'], 'panel NAC SMS testi')
                && ! str_contains($payload['content'], 'panel NAC SMS ayar testi')
                && ! str_contains($payload['content'], 'EMAKS Prime test SMS')
                && ! str_contains($payload['content'], $testCode)
                && ! str_contains($payload['content'], 'T202')
                && ! str_contains($payload['title'], 'T202')
                && ! str_contains($payload['content'], 'MRN:')
                && ! str_contains($payload['content'], 'SRV:');
        });
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'n8n'));
    }

    public function test_provider_test_sms_uses_provider_test_content_direct_laravel_no_n8n_and_separate_endpoint(): void
    {
        Http::fake([
            'smslogin.nac.com.tr:9587/*' => Http::response([
                'data' => [
                    'pkgID' => 'PKG-PROVIDER-TEST',
                ],
            ], 200),
        ]);

        $this->configureNacSmsTestSettings();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/nac-sms/test-send', [
                'real_sms_confirmed' => true,
            ])
            ->assertOk()
            ->assertJsonPath('provider_test.dispatch.status', 'sent')
            ->assertJsonPath('provider_test.dispatch.test_type', 'provider_test_sms')
            ->assertJsonPath('provider_test.dispatch.provider_reference', 'PKG-PROVIDER-TEST')
            ->assertJsonPath('provider_test.dispatch.encoding', 1);

        $testCode = $response->json('provider_test.dispatch.test_code');

        Http::assertSent(function ($request) use ($testCode): bool {
            $payload = $request->data();

            return $request->url() === 'http://smslogin.nac.com.tr:9587/sms/create'
                && $payload['title'] === 'EMAKS TEST '.$testCode
                && $payload['encoding'] === 1
                && str_contains($payload['content'], 'EMAKS Prime SMS altyapı testi')
                && str_contains($payload['content'], 'Gönderim zamanı')
                && ! str_contains($payload['content'], 'randevunuz onaylanmıştır');
        });
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'n8n'));
    }

    public function test_nac_sms_test_send_http_fake_error_is_redacted_and_not_sent_status(): void
    {
        Http::fake([
            'smslogin.nac.com.tr:9587/*' => Http::response([
                'err' => [
                    'status' => 530,
                    'code' => 1033,
                    'message' => 'Basic password=SECRET failed',
                ],
            ], 530),
        ]);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/nac-sms/credentials', [
                'username' => 'nac-user@example.test',
                'password' => 'PR88_NAC_CREDENTIAL_TEST_ONLY',
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'test_phone' => '905467647428',
                'active_provider' => 'nac_sms',
                'nac_sms' => [
                    'enabled' => true,
                    'profile' => 'legacy_working_http_9587',
                    'scheme' => 'http',
                    'host' => 'smslogin.nac.com.tr',
                    'port' => 9587,
                    'path' => '/sms/create',
                    'request_shape' => 'legacy_working_minimal',
                    'sender' => 'EMAKS PRIME',
                ],
            ])
            ->assertOk();

        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'real_sms_confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJsonPath('test_send.dispatch.status', 'failed')
            ->json('test_send');

        $encoded = json_encode($payload, JSON_THROW_ON_ERROR);
        $this->assertStringContainsString('HTTP 530', $encoded);
        $this->assertStringContainsString('NAC code 1033', $encoded);
        $this->assertStringContainsString('NAC kimlik doğrulama başarısız', (string) $payload['dispatch']['error_message']);
        $this->assertStringNotContainsString('PR88_NAC_CREDENTIAL_TEST_ONLY', $encoded);
        $this->assertStringNotContainsString('SECRET', $encoded);
    }

    public function test_template_test_sms_keeps_body_same_but_generates_unique_title_custom_id_and_payload_hash_each_click(): void
    {
        Http::fake([
            'smslogin.nac.com.tr:9587/*' => Http::response([
                'data' => [
                    'pkgID' => 'PKG-REL4C8-TEST',
                ],
            ], 200),
        ]);

        $this->configureNacSmsTestSettings();

        foreach (range(1, 2) as $ignored) {
            $this->actingAs($this->admin())
                ->postJson('/api/technical-service/message-templates/test-send', [
                    'confirmed' => true,
                    'real_sms_confirmed' => true,
                    'message_type' => 'appointment_approved_customer',
                    'channel' => 'sms',
                ])
                ->assertOk()
                ->assertJsonPath('test_send.dispatch.status', 'sent');
        }

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('event', 'template_test_sms')
            ->oldest('id')
            ->get();

        $this->assertCount(2, $dispatches);

        $first = $dispatches[0]->request_payload;
        $second = $dispatches[1]->request_payload;

        foreach ([$first, $second] as $payload) {
            $this->assertMatchesRegularExpression('/^B\d{3,}$/', $payload['test_code']);
            $this->assertMatchesRegularExpression('/^T\d{8}-\d{6}-[A-Z0-9]{4}$/', $payload['internal_test_code']);
            $this->assertStringContainsString($payload['test_code'], $payload['title']);
            $this->assertStringNotContainsString($payload['test_code'], $payload['text']);
            $this->assertStringContainsString($payload['internal_test_code'], $payload['custom_id']);
            $this->assertStringContainsString($payload['test_code'], $payload['nac_payload_shape']['title']);
            $this->assertStringNotContainsString($payload['test_code'], $payload['nac_payload_shape']['content']);
            $this->assertStringNotContainsString($payload['internal_test_code'], $payload['nac_payload_shape']['title']);
            $this->assertStringNotContainsString($payload['internal_test_code'], $payload['nac_payload_shape']['content']);
            $this->assertSame($payload['custom_id'], $payload['nac_payload_shape']['customID']);
            $this->assertSame('template_test_sms', $payload['test_type']);
            $this->assertSame('rendered_template_preview', $payload['content_source']);
            $this->assertSame(1, $payload['encoding']);
        }

        $this->assertNotSame($first['test_code'], $second['test_code']);
        $this->assertNotSame($first['internal_test_code'], $second['internal_test_code']);
        $this->assertNotSame($first['title'], $second['title']);
        $this->assertSame($first['text'], $second['text']);
        $this->assertSame($first['template_body_hash'], $second['template_body_hash']);
        $this->assertNotSame($first['custom_id'], $second['custom_id']);
        $this->assertNotSame($first['payload_hash'], $second['payload_hash']);
        $this->assertSame($first['payload_hash'], $second['previous_payload_hash']);
        Http::assertSentCount(2);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'n8n'));
    }

    public function test_template_test_duplicate_does_not_mutate_body_and_reports_provider_policy(): void
    {
        Http::fake([
            'smslogin.nac.com.tr:9587/*' => Http::sequence()
                ->push([
                    'data' => [
                        'pkgID' => 'PKG-REL4C8-FIRST',
                    ],
                ], 200)
                ->push([
                    'err' => [
                        'status' => 417,
                        'code' => 'ERR_SMS_PKG_DUPLICATION',
                        'message' => 'Same SMS package was already sent.',
                    ],
                ], 200),
        ]);

        $this->configureNacSmsTestSettings();

        $first = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'real_sms_confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJsonPath('test_send.dispatch.status', 'sent')
            ->json('test_send.dispatch');

        $second = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'real_sms_confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJsonPath('test_send.dispatch.status', 'failed')
            ->assertJsonPath('test_send.dispatch.duplicate', true)
            ->assertJsonPath('test_send.dispatch.provider_reference', null)
            ->json('test_send.dispatch');

        $this->assertNotSame($first['test_code'], $second['test_code']);
        $this->assertNotSame($first['payload_hash'], $second['payload_hash']);
        $this->assertSame($first['payload_hash'], $second['previous_payload_hash']);
        $this->assertStringContainsString('ERR_SMS_PKG_DUPLICATION', $second['error_message']);
        $this->assertStringContainsString('NAC duplicate engeli', $second['error_message']);
        $this->assertStringContainsString('Şablon metni değiştirilmeden', $second['error_message']);
        $this->assertStringContainsString($first['payload_hash'], $second['error_message']);
        $this->assertStringContainsString($second['payload_hash'], $second['error_message']);

        $dispatches = TechnicalServiceMessageDispatch::query()
            ->where('event', 'template_test_sms')
            ->oldest('id')
            ->get();
        $this->assertSame($dispatches[0]->request_payload['text'], $dispatches[1]->request_payload['text']);
        $this->assertSame($dispatches[0]->request_payload['template_body_hash'], $dispatches[1]->request_payload['template_body_hash']);

        $encoded = json_encode($second, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('Authorization', $encoded);
        $this->assertStringNotContainsString('Basic ', $encoded);
        $this->assertStringNotContainsString('PR88_NAC_CREDENTIAL_TEST_ONLY', $encoded);
        Http::assertSentCount(2);
    }

    public function test_template_test_send_refuses_blockers_and_voice_channel(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'body' => 'Merhaba {unknown_variable}',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['template']);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'voice_script',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['channel']);
    }

    public function test_customer_whatsapp_canonical_template_has_operational_format_and_no_random_talep_suffix(): void
    {
        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $body = $payload['rendered_body'];

        $this->assertStringContainsString('EMAKS Prime Teknik Servis', $body);
        $this->assertStringContainsString("Sayın PR88 Test Müşteri,\nMRN-REL4C-0001 numaralı montaj randevunuz onaylanmıştır.", $body);
        $this->assertStringContainsString("Randevu Bilgileri\nTarih: 03.07.2026", $body);
        $this->assertStringContainsString('Saat Aralığı:', $body);
        $this->assertStringContainsString('Randevu sırasında ustaya ödenecek tutar: 1.250,00 TL.', $body);
        $this->assertStringContainsString('Not: Ödemeler nakit ve havale kabul edilmektedir.', $body);
        $this->assertStringNotContainsString('MRN:', $body);
        $this->assertStringNotContainsString('SRV:', $body);
        $this->assertStringNotContainsString('Ödeme:', $body);
        $this->assertStringNotContainsString('Talep:', $body);
        $this->assertStringNotContainsString('Yeni randevu:', $body);
    }

    public function test_template_readability_customer_whatsapp_is_sectioned_and_not_dense(): void
    {
        $body = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertGreaterThanOrEqual(9, substr_count($body, "\n"));
        $this->assertStringContainsString("\n\nRandevu Bilgileri\n", $body);
        $this->assertStringNotContainsString('EMAKS Prime Teknik Servis Sayın', $body);
        $this->assertStringNotContainsString('MRN:', $body);
        $this->assertStringNotContainsString('SRV:', $body);
        $this->assertStringNotContainsString('Ödeme:', $body);
        $this->assertStringNotContainsString('Talep:', $body);
        $this->assertStringNotContainsString('undefined', $body);
        $this->assertStringNotContainsString('null', $body);
    }

    public function test_customer_sms_canonical_template_is_short_and_reports_segments(): void
    {
        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $this->assertStringStartsWith("EMAKS Prime\nMRN-REL4C-0001 numaralı montaj randevunuz onaylanmıştır.", $payload['rendered_body']);
        $this->assertStringContainsString('Ustaya ödenecek tutar: 1.250,00 TL.', $payload['rendered_body']);
        $this->assertStringNotContainsString('MRN:', $payload['rendered_body']);
        $this->assertStringNotContainsString('SRV:', $payload['rendered_body']);
        $this->assertStringNotContainsString('Ödeme:', $payload['rendered_body']);
        $this->assertStringNotContainsString('Talep:', $payload['rendered_body']);
        $this->assertStringNotContainsString('Adres:', $payload['rendered_body']);
        $this->assertStringNotContainsString('Harita:', $payload['rendered_body']);
        $this->assertGreaterThan(0, $payload['sms']['characters']);
        $this->assertGreaterThanOrEqual(1, $payload['sms']['segments']);
        $this->assertGreaterThanOrEqual(4, $payload['sms']['line_count']);
    }

    public function test_whatsapp_preview_preserves_line_breaks_and_sms_keeps_clean_short_lines(): void
    {
        $whatsapp = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
            ])
            ->assertOk()
            ->json('preview.rendered_body');

        $sms = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
                'body' => "EMAKS\nSayın {customer_name}\nMRN: {mrn}",
            ])
            ->assertOk()
            ->json('preview.rendered_body');

        $this->assertStringContainsString("EMAKS Prime Teknik Servis\n\nSayın", $whatsapp);
        $this->assertStringContainsString("\n\nRandevu Bilgileri\n", $whatsapp);
        $this->assertStringNotContainsString('EMAKS Prime Teknik Servis Sayın', $whatsapp);
        $this->assertStringContainsString("\n", $sms);
        $this->assertSame("EMAKS\nSayın PR88 Test Müşteri\nMRN: MRN-REL4C-0001", $sms);
    }

    public function test_sms_readability_clean_sms_templates_avoid_whatsapp_only_fields_and_block_long_sms(): void
    {
        $technician = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_technician',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('Kart https://e.ms/job/PR88', $technician);
        $this->assertStringContainsString('Randevu 03.07.2026 14:00 - 16:00', $technician);
        $this->assertStringNotContainsString('13:00 - 19:00 arası', $technician);
        $this->assertStringNotContainsString('Adres:', $technician);
        $this->assertStringNotContainsString('Harita:', $technician);
        $this->assertStringNotContainsString('Hakediş Özeti', $technician);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
                'body' => str_repeat('Türkçe uzun SMS metni. ', 22),
                'required_variables' => [],
                'optional_variables' => [],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['SMS 4 veya daha fazla segment; admin override olmadan gönderilemez.']);
    }

    public function test_technician_whatsapp_template_includes_job_card_and_safe_earning_summary(): void
    {
        $payload = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_technician',
                'channel' => 'whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $body = $payload['rendered_body'];

        $this->assertStringContainsString('Yeni iş kartı hazır.', $body);
        $this->assertStringContainsString("Servis Kaydı\nMRN: MRN-REL4C-0001", $body);
        $this->assertStringContainsString('Müşteri Bilgileri', $body);
        $this->assertStringContainsString('Müşteri: PR88 Test Müşteri', $body);
        $this->assertStringContainsString('Telefon: 905555555555', $body);
        $this->assertStringContainsString('Adres: Test Mah. Örnek Sok. No:1', $body);
        $this->assertStringContainsString("Randevu\n03.07.2026 14:00 - 16:00", $body);
        $this->assertStringNotContainsString('13:00 - 19:00 arası', $body);
        $this->assertStringContainsString("İş Kartı\nhttps://panel.example.test/partner/jobs/PR88-REL4C", $body);
        $this->assertStringContainsString('Hakediş Özeti', $body);
        $this->assertStringContainsString('İşçilik/Montaj: 900,00 TL', $body);
        $this->assertStringContainsString('Yol: 350,00 TL', $body);
        $this->assertStringContainsString('Toplam: 1.250,00 TL', $body);
        $this->assertStringNotContainsString('internal_note', $body);
    }

    public function test_technician_template_uses_safe_earning_summary_when_amounts_are_missing(): void
    {
        $body = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_technician',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Usta Test',
                    'customer_phone' => '905551112233',
                    'address' => 'Test Sokak No:1',
                    'mrn' => 'PR88-REL4C1-MRN',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '14:00 - 16:00',
                    'technician_job_card_url' => 'https://panel.example.test/partner/jobs/PR88-REL4C1',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('Hakediş bilgisi paneldeki iş kartında görülebilir.', $body);
    }

    public function test_appointment_window_rules_render_customer_boundaries_and_exact_technician_ranges(): void
    {
        $base = [
            'message_type' => 'appointment_approved_customer',
            'channel' => 'whatsapp',
            'sample_context' => false,
            'context' => [
                'customer_name' => 'PR88 Pencere Test',
                'mrn' => 'PR88-REL4C1-MRN',
                'appointment_date' => '2026-07-03',
                'payer_state_key' => 'no_payment_required',
            ],
        ];

        $morning = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                ...$base,
                'context' => [...$base['context'], 'appointment_time' => '10:00'],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $noon = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                ...$base,
                'context' => [...$base['context'], 'appointment_time' => '12:00'],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $lastMorningMinute = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                ...$base,
                'context' => [...$base['context'], 'appointment_time' => '12:59'],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $boundaryAfternoon = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                ...$base,
                'context' => [...$base['context'], 'appointment_time' => '13:00'],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $afternoon = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                ...$base,
                'context' => [...$base['context'], 'appointment_time' => '14:00'],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $explicit = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                ...$base,
                'context' => [...$base['context'], 'appointment_time' => '10:00 - 12:00'],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('Saat Aralığı: 09:00 - 13:00 arası', $morning);
        $this->assertStringContainsString('Saat Aralığı: 09:00 - 13:00 arası', $noon);
        $this->assertStringContainsString('Saat Aralığı: 09:00 - 13:00 arası', $lastMorningMinute);
        $this->assertStringContainsString('Saat Aralığı: 13:00 - 19:00 arası', $boundaryAfternoon);
        $this->assertStringContainsString('Saat Aralığı: 13:00 - 19:00 arası', $afternoon);
        $this->assertStringContainsString('Saat Aralığı: 09:00 - 13:00 arası', $explicit);
        $this->assertStringNotContainsString('null', $morning);
        $this->assertStringNotContainsString('undefined', $afternoon);
    }

    public function test_missing_appointment_window_blocks_customer_appointment_template(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Eksik Saat',
                    'mrn' => 'PR88-REL4C1-MRN',
                    'appointment_date' => '2026-07-03',
                    'payer_state_key' => 'no_payment_required',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Müşteri randevu aralığı belirlenmeli.']);
    }

    public function test_customer_sms_uses_customer_window_and_technician_sms_uses_exact_time(): void
    {
        $customer = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
                'sample_context' => false,
                'context' => [
                    'job_type' => 'montaj',
                    'customer_name' => 'PR88 SMS Sınır',
                    'mrn' => 'MRN-REL4C11-SMS',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '13:00',
                    'payer_state_key' => 'no_payment_required',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('Aralık: 13:00 - 19:00 arası', $customer);
        $this->assertStringNotContainsString('13:00 - 13:00', $customer);

        $technician = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_technician',
                'channel' => 'sms',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Usta SMS',
                    'customer_phone' => '905551112233',
                    'mrn' => 'MRN-REL4C11-USTA',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '14:00 - 16:00',
                    'technician_job_card_url' => 'https://panel.example.test/partner/jobs/PR88-REL4C11',
                    'technician_job_card_short_url' => 'https://e.ms/job/R4C11',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('Randevu 03.07.2026 14:00 - 16:00', $technician);
        $this->assertStringContainsString('Kart https://e.ms/job/R4C11', $technician);
        $this->assertStringNotContainsString('13:00 - 19:00 arası', $technician);
    }

    public function test_technician_message_requires_and_renders_job_card_url(): void
    {
        $baseContext = [
            'customer_name' => 'PR88 Usta Test',
            'customer_phone' => '905551112233',
            'address' => 'Test Sokak No:1',
            'mrn' => 'PR88-REL4C1-MRN',
            'appointment_date' => '2026-07-03',
            'appointment_time' => '14:00 - 16:00',
        ];

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_technician',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => $baseContext,
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Usta mesajı için iş kartı linki zorunlu.']);

        $body = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_technician',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    ...$baseContext,
                    'technician_job_card_url' => 'https://panel.example.test/partner/jobs/PR88-REL4C1',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString("İş Kartı\nhttps://panel.example.test/partner/jobs/PR88-REL4C1", $body);
        $this->assertStringContainsString("Randevu\n03.07.2026 14:00 - 16:00", $body);
        $this->assertStringNotContainsString('13:00 - 19:00 arası', $body);
    }

    public function test_technician_exact_time_blocks_without_exact_time(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_technician',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Usta Test',
                    'customer_phone' => '905551112233',
                    'address' => 'Test Sokak No:1',
                    'mrn' => 'PR88-REL4C11-MRN',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '14:00',
                    'technician_job_card_url' => 'https://panel.example.test/partner/jobs/PR88-REL4C11',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Usta mesajı için tam randevu saati gerekli.']);
    }

    public function test_customer_message_does_not_require_job_card_url(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Müşteri Test',
                    'mrn' => 'PR88-REL4C1-MRN',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '14:00',
                    'payer_state_key' => 'no_payment_required',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true);
    }

    public function test_customer_payer_state_guards_apply_to_appointment_templates(): void
    {
        $companyCollected = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Tahsil Test',
                    'mrn' => 'PR88-REL4C1-MRN',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '14:00',
                    'payer_state_key' => 'company_collected_online',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringNotContainsString('Ustaya ayrıca ödeme yapmanız gerekmez.', $companyCollected);
        $this->assertStringNotContainsString('Ödeme:', $companyCollected);
        $this->assertStringNotContainsString('ustaya ödeme', mb_strtolower($companyCollected, 'UTF-8'));

        $customerPays = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Ustaya Ödeme',
                    'mrn' => 'PR88-REL4C1-MRN',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '14:00',
                    'payer_state_key' => 'customer_pays_technician',
                    'customer_payment_amount' => 1250,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('Randevu sırasında ustaya ödenecek tutar: 1.250,00 TL.', $customerPays);
        $this->assertStringNotContainsString('Ödeme:', $customerPays);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'customer_name' => 'PR88 Eksik Tutar',
                    'mrn' => 'PR88-REL4C1-MRN',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '14:00',
                    'payer_state_key' => 'customer_pays_technician',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Müşteri ustaya ödeme yapacaksa pozitif müşteri ödeme tutarı zorunlu.']);
    }

    public function test_customer_reference_mount_uses_mrn_sentence_and_service_uses_srv_without_mrn(): void
    {
        $mount = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'job_type' => 'montaj',
                    'customer_name' => 'PR88 Montaj Müşteri',
                    'mrn' => 'MRN-REL4C5-MONTAJ',
                    'srv' => 'SRV-REL4C5-HIDDEN',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '10:00',
                    'payer_state_key' => 'no_payment_required',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('MRN-REL4C5-MONTAJ numaralı montaj randevunuz onaylanmıştır.', $mount);
        $this->assertStringNotContainsString('MRN:', $mount);
        $this->assertStringNotContainsString('SRV:', $mount);

        $service = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'job_type' => 'servis',
                    'customer_name' => 'PR88 Servis Müşteri',
                    'mrn' => 'MRN-REL4C5-INTERNAL',
                    'srv' => 'SRV-REL4C5-SERVIS',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '14:00',
                    'payer_state_key' => 'no_payment_required',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $this->assertStringContainsString('SRV-REL4C5-SERVIS numaralı servis randevunuz onaylanmıştır.', $service['rendered_body']);
        $this->assertStringNotContainsString('MRN-REL4C5-INTERNAL', $service['rendered_body']);
        $this->assertSame('servis', $service['context']['customer_job_type_label']);
        $this->assertSame('SRV-REL4C5-SERVIS', $service['context']['customer_reference_code']);
        $this->assertSame('MRN-REL4C5-INTERNAL', $service['context']['customer_hidden_internal_references']['mrn'] ?? null);
    }

    public function test_customer_whatsapp_no_internal_labels_and_payment_line_is_natural(): void
    {
        $body = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'job_type' => 'montaj',
                    'customer_name' => 'PR88 Ustaya Ödeme',
                    'mrn' => 'MRN-REL4C5-PAY',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '14:00',
                    'payer_state_key' => 'customer_pays_technician',
                    'customer_payment_amount' => 1750,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('MRN-REL4C5-PAY numaralı montaj randevunuz onaylanmıştır.', $body);
        $this->assertStringContainsString('Randevu sırasında ustaya ödenecek tutar: 1.750,00 TL.', $body);
        $this->assertStringNotContainsString('MRN:', $body);
        $this->assertStringNotContainsString('SRV:', $body);
        $this->assertStringNotContainsString('Ödeme:', $body);
        $this->assertStringNotContainsString('Talep:', $body);
    }

    public function test_customer_sms_service_uses_srv_sentence_without_mrn_and_stays_readable(): void
    {
        $preview = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
                'sample_context' => false,
                'context' => [
                    'job_type' => 'servis',
                    'customer_name' => 'PR88 SMS Müşteri',
                    'mrn' => 'MRN-REL4C5-HIDDEN',
                    'srv' => 'SRV-REL4C5-SMS',
                    'appointment_date' => '2026-07-03',
                    'appointment_time' => '09:00',
                    'payer_state_key' => 'no_payment_required',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $this->assertStringContainsString('SRV-REL4C5-SMS numaralı servis randevunuz onaylanmıştır.', $preview['rendered_body']);
        $this->assertStringNotContainsString('MRN-REL4C5-HIDDEN', $preview['rendered_body']);
        $this->assertStringNotContainsString('MRN:', $preview['rendered_body']);
        $this->assertStringNotContainsString('SRV:', $preview['rendered_body']);
        $this->assertStringNotContainsString('Ödeme:', $preview['rendered_body']);
        $this->assertLessThanOrEqual(2, $preview['sms']['segments']);
    }

    public function test_payment_link_customer_uses_natural_reference_and_blocks_company_collected(): void
    {
        $servicePayment = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'payment_link_customer',
                'channel' => 'sms',
                'sample_context' => false,
                'context' => [
                    'job_type' => 'servis',
                    'customer_name' => 'PR88 Link Müşteri',
                    'mrn' => 'MRN-REL4C5-HIDDEN',
                    'srv' => 'SRV-REL4C5-LINK',
                    'payment_link' => 'https://sandbox.iyzi.link/rel4c5',
                    'payment_link_sms' => 'https://e.ms/pay/R4C5',
                    'payment_amount_formatted' => '1.250,00 TL',
                    'payer_state_key' => 'pending_online_payment',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('SRV-REL4C5-LINK numaralı servis için ödeme bağlantınız:', $servicePayment);
        $this->assertStringContainsString('https://e.ms/pay/R4C5', $servicePayment);
        $this->assertStringNotContainsString('MRN-REL4C5-HIDDEN', $servicePayment);
        $this->assertStringNotContainsString('MRN:', $servicePayment);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'payment_link_customer',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'job_type' => 'montaj',
                    'customer_name' => 'PR88 Tahsil Müşteri',
                    'mrn' => 'MRN-REL4C5-COLLECTED',
                    'payment_link' => 'https://sandbox.iyzi.link/rel4c5',
                    'payment_amount_formatted' => '1.250,00 TL',
                    'payer_state_key' => 'company_collected_online',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Şirket tahsil etmişken müşteriye ödeme linki mesajı gönderilemez.']);
    }

    public function test_technician_template_still_has_operational_labels(): void
    {
        $body = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_technician',
                'channel' => 'whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('MRN: MRN-REL4C-0001', $body);
        $this->assertStringContainsString('SRV: SRV-REL4C', $body);
        $this->assertStringContainsString('Müşteri:', $body);
        $this->assertStringContainsString('İş Kartı', $body);
    }

    public function test_template_catalog_all_required_message_types_have_defaults(): void
    {
        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-templates')
            ->assertOk()
            ->json('message_templates');

        $templates = collect($payload['templates']);
        foreach ([
            'appointment_approved_customer',
            'appointment_updated_customer',
            'payment_link_customer',
            'customer_pays_technician_notice',
            'customer_approval_request',
            'future_survey_customer',
            'appointment_approved_technician',
            'appointment_updated_technician',
            'assignment_offer_technician',
            'earnings_message_technician',
            'completion_submitted_ops',
            'support_request_ops',
            'job_rejected_ops',
            'price_revision_requested_ops',
        ] as $messageType) {
            $this->assertTrue(
                $templates->contains(fn (array $template): bool => $template['message_type'] === $messageType),
                "Missing default template for {$messageType}",
            );
        }

        $this->assertTrue($templates->every(fn (array $template): bool => trim((string) $template['body']) !== ''));
    }

    public function test_customer_language_policy_all_customer_templates(): void
    {
        foreach ([
            'appointment_approved_customer',
            'appointment_updated_customer',
            'payment_link_customer',
            'customer_pays_technician_notice',
            'customer_approval_request',
            'future_survey_customer',
        ] as $messageType) {
            foreach (['whatsapp', 'sms'] as $channel) {
                $preview = $this->actingAs($this->admin())
                    ->postJson('/api/technical-service/message-templates/preview', [
                        'message_type' => $messageType,
                        'channel' => $channel,
                        'context' => [
                            'job_type' => 'servis',
                            'mrn' => 'MRN-REL4C6-HIDDEN',
                            'srv' => 'SRV-REL4C6-CUSTOMER',
                            'payer_state_key' => $messageType === 'customer_pays_technician_notice'
                                ? 'customer_pays_technician'
                                : 'no_payment_required',
                            'customer_payment_amount' => 1250,
                            'customer_payment_amount_formatted' => '1.250,00 TL',
                        ],
                    ])
                    ->assertOk()
                    ->assertJsonPath('preview.preview_ready', true)
                    ->json('preview.rendered_body');

                $this->assertStringContainsString('SRV-REL4C6-CUSTOMER numaralı servis', $preview);
                $this->assertStringNotContainsString('MRN-REL4C6-HIDDEN', $preview);
                $this->assertStringNotContainsString('MRN:', $preview);
                $this->assertStringNotContainsString('SRV:', $preview);
                $this->assertStringNotContainsString('Talep:', $preview);
                $this->assertStringNotContainsString('Ödeme:', $preview);
                $this->assertStringNotContainsString('Request:', $preview);
                $this->assertStringNotContainsString('Job:', $preview);
                $this->assertStringNotContainsString('hakediş', mb_strtolower($preview, 'UTF-8'));
                $this->assertStringNotContainsString('Ödemeler nakit veya havale', $preview);
            }
        }
    }

    public function test_sms_readability_policy_all_default_sms_templates(): void
    {
        $payload = $this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-templates')
            ->assertOk()
            ->json('message_templates.templates');

        foreach (collect($payload)->where('channel', 'sms') as $template) {
            $preview = $this->actingAs($this->admin())
                ->postJson('/api/technical-service/message-templates/preview', [
                    'message_type' => $template['message_type'],
                    'channel' => 'sms',
                ])
                ->assertOk()
                ->json('preview');

            $this->assertStringNotContainsString('https://www.google.com/maps', $preview['rendered_body']);
            $this->assertStringNotContainsString('Test Mah. Örnek Sok.', $preview['rendered_body']);
            $this->assertStringNotContainsString('https://panel.example.test/partner/jobs', $preview['rendered_body']);
            $this->assertStringNotContainsString('Ödemeler nakit veya havale', $preview['rendered_body']);
            $this->assertLessThan(4, $preview['sms']['segments'], $template['message_type'].' SMS is too long.');
        }
    }

    public function test_assignment_offer_sms_keeps_required_business_fields_with_guarded_short_url_under_segment_policy(): void
    {
        $context = [
            'mrn' => 'MRN-2607MM180001',
            'customer_name' => 'REL 4E Test Müşteri',
            'customer_phone' => '905372081633',
            'address' => 'Pamukkale Test Mahallesi Güvenli Sokak No 17 Denizli',
            'product_name' => 'Çelik Kapı Kilidi Test Ürünü',
            'labor_amount_formatted' => '3.000,00 TL',
            'route_fee_formatted' => '200,00 TL',
            'technician_earning_total_formatted' => '3.200,00 TL',
            'technician_job_card_url' => 'http://10.0.28.64:8000/partner/service-jobs?partner_id=81&job_id=247',
            'technician_job_card_short_url' => 'http://10.0.28.64:8000/pj/247',
        ];

        $sms = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'assignment_offer_technician',
                'channel' => 'sms',
                'sample_context' => false,
                'context' => $context,
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $body = $sms['rendered_body'];
        foreach ([
            'EMAKS Yeni Is',
            'MRN-2607MM180001',
            'REL 4E Test Musteri',
            '905372081633',
            'Pamukkale Test Mahallesi Guvenli Sokak No 17 Denizli',
            'Celik Kapi Kilidi Test Urunu',
            'Is:3.000,00 TL',
            'Yol:200,00 TL',
            'Top:3.200,00 TL',
            'Saat oner:',
            'http://10.0.28.64:8000/pj/247',
        ] as $required) {
            $this->assertStringContainsString($required, $body);
        }
        $this->assertSame('gsm', $sms['sms']['encoding']);
        $this->assertLessThan(4, $sms['sms']['segments']);
        $this->assertStringNotContainsString('/technical-service/ops-support/', $body);
        $this->assertStringNotContainsString('/portal-preview', $body);
        $this->assertStringNotContainsString('undefined', $body);
        $this->assertStringNotContainsString('null', $body);

        $whatsapp = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'assignment_offer_technician',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => $context,
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('EMAKS Prime Teknik Servis', $whatsapp);
        $this->assertStringContainsString($context['technician_job_card_url'], $whatsapp);
        $this->assertStringNotContainsString($context['technician_job_card_short_url'], $whatsapp);
    }

    public function test_sms_compliance_sms_footer_is_separate_from_internal_custom_id(): void
    {
        $preview = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
                'context' => [
                    'sms_custom_id' => 'nac-template-test-T20260703-084712-X9K3',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $this->assertStringNotContainsString('nac-template-test', $preview['rendered_body']);
        $this->assertStringNotContainsString('T20260703-084712-X9K3', $preview['rendered_body']);
        $this->assertLessThan(4, $preview['sms']['segments']);
    }

    public function test_sms_compliance_footer_can_be_visible_without_exposing_custom_id(): void
    {
        $preview = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
                'body' => "EMAKS Prime\n{customer_appointment_action_phrase}\nTarih: {appointment_date_formatted}\nAralık: {appointment_customer_window}\nB028",
                'context' => [
                    'sms_custom_id' => 'nac-template-test-T20260703-084712-X9K3',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview');

        $this->assertStringContainsString('B028', $preview['rendered_body']);
        $this->assertStringNotContainsString('nac-template-test', $preview['rendered_body']);
        $this->assertStringNotContainsString('T20260703-084712-X9K3', $preview['rendered_body']);
        $this->assertLessThan(4, $preview['sms']['segments']);
    }

    public function test_sms_compliance_provider_auto_mode_does_not_mutate_template_body(): void
    {
        Http::fake([
            'smslogin.nac.com.tr:9587/*' => Http::response([
                'data' => [
                    'pkgID' => 'PKG-SMS-COMPLIANCE-BODY',
                ],
            ], 200),
        ]);

        $this->configureNacSmsTestSettings();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'real_sms_confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJsonPath('test_send.dispatch.status', 'sent')
            ->assertJsonPath('test_send.dispatch.test_type', 'template_test_sms');

        $previewBody = $response->json('test_send.preview.rendered_body');
        $contentPreview = $response->json('test_send.dispatch.content_preview');
        $dispatch = TechnicalServiceMessageDispatch::query()->where('event', 'template_test_sms')->firstOrFail();
        $payload = $dispatch->request_payload;

        $this->assertSame($previewBody, $contentPreview);
        $this->assertSame($previewBody, $payload['text']);
        $this->assertSame($previewBody, $payload['nac_payload_shape']['content']);
        $this->assertSame('rendered_template_preview', $payload['content_source']);
        $this->assertStringNotContainsString('panel NAC SMS testi', $previewBody);
        $this->assertStringNotContainsString('panel NAC SMS ayar testi', $previewBody);
        $this->assertStringNotContainsString('EMAKS Prime test SMS', $previewBody);
        $this->assertStringNotContainsString($payload['test_code'], $previewBody);
        $this->assertStringNotContainsString($payload['internal_test_code'], $previewBody);
        Http::assertSentCount(1);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'n8n'));
    }

    public function test_sms_compliance_footer_keeps_basic_auth_and_credentials_hidden(): void
    {
        Http::fake([
            'smslogin.nac.com.tr:9587/*' => Http::response([
                'data' => [
                    'pkgID' => 'PKG-SMS-COMPLIANCE-SECRET',
                ],
            ], 200),
        ]);

        $this->configureNacSmsTestSettings();

        $response = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/test-send', [
                'confirmed' => true,
                'real_sms_confirmed' => true,
                'message_type' => 'appointment_approved_customer',
                'channel' => 'sms',
            ])
            ->assertOk()
            ->assertJsonPath('test_send.dispatch.status', 'sent');

        $dispatch = TechnicalServiceMessageDispatch::query()->where('event', 'template_test_sms')->firstOrFail();
        $encodedResponse = $response->getContent();
        $encodedDispatch = json_encode([
            'request_payload' => $dispatch->request_payload,
            'response_payload' => $dispatch->response_payload,
            'error_message' => $dispatch->error_message,
        ], JSON_THROW_ON_ERROR);

        foreach ([$encodedResponse, $encodedDispatch] as $encoded) {
            $this->assertStringNotContainsString('Authorization', $encoded);
            $this->assertStringNotContainsString('Basic ', $encoded);
            $this->assertStringNotContainsString('nac-user@example.test', $encoded);
            $this->assertStringNotContainsString('PR88_NAC_CREDENTIAL_TEST_ONLY', $encoded);
        }
        Http::assertSentCount(1);
    }

    public function test_template_audit_all_active_templates_pass_time_language_and_event_policy(): void
    {
        $templates = collect($this->actingAs($this->admin())
            ->getJson('/api/technical-service/message-templates')
            ->assertOk()
            ->json('message_templates.templates'));

        foreach ($templates->where('active', true) as $template) {
            $previewResponse = $this->actingAs($this->admin())
                ->postJson('/api/technical-service/message-templates/preview', [
                    'message_type' => $template['message_type'],
                    'channel' => $template['channel'],
                    'provider_key' => $template['provider_key'] ?? null,
                ])
                ->assertOk();
            $preview = $previewResponse->json('preview');
            $this->assertTrue(
                (bool) ($preview['preview_ready'] ?? false),
                $template['template_key'].' blockers: '.json_encode($preview['blockers'] ?? [], JSON_UNESCAPED_UNICODE),
            );

            $body = (string) $preview['rendered_body'];
            $this->assertStringNotContainsString('undefined', mb_strtolower($body, 'UTF-8'), $template['template_key']);
            $this->assertStringNotContainsString('null', mb_strtolower($body, 'UTF-8'), $template['template_key']);
            $this->assertStringNotContainsString('NaN', $body, $template['template_key']);
            $this->assertStringNotContainsString('{', $body, $template['template_key']);
            $this->assertStringNotContainsString('}', $body, $template['template_key']);

            if (($preview['recipient_role'] ?? null) === 'customer') {
                $this->assertStringNotContainsString('MRN:', $body, $template['template_key']);
                $this->assertStringNotContainsString('SRV:', $body, $template['template_key']);
                $this->assertStringNotContainsString('Talep:', $body, $template['template_key']);
                $this->assertStringNotContainsString('Ödeme:', $body, $template['template_key']);
            }

            if (($preview['recipient_role'] ?? null) === 'technician'
                && in_array($template['message_type'], [
                    'appointment_approved_technician',
                    'appointment_updated_technician',
                ], true)) {
                $this->assertStringContainsString('14:00 - 16:00', $body, $template['template_key']);
                $this->assertStringNotContainsString('13:00 - 19:00 arası', $body, $template['template_key']);
            }

            if (($template['channel'] ?? null) === 'sms') {
                $this->assertLessThan(4, $preview['sms']['segments'], $template['template_key'].' SMS is too long.');
            }

            if ($template['message_type'] === 'job_rejected_ops') {
                $this->assertStringContainsString('Usta işi reddetti.', $body);
                $this->assertStringContainsString('Usta:', $body);
                $this->assertTrue(
                    str_contains($body, 'Reddetme Nedeni:') || str_contains($body, 'Neden:'),
                    'job_rejected_ops reason missing',
                );
                $this->assertTrue(
                    str_contains($body, 'Reddetme Tarihi:') || str_contains($body, 'Tarih:'),
                    'job_rejected_ops time missing',
                );
                $this->assertStringContainsString('Yeniden atama', $body);
            }
        }
    }

    public function test_ops_template_job_rejected_catalog_has_actor_reason_and_next_action_fields(): void
    {
        $jobRejected = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'job_rejected_ops',
                'channel' => 'whatsapp',
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('Usta işi reddetti.', $jobRejected);
        $this->assertStringContainsString('İş: SRV: SRV-REL4C / MRN: MRN-REL4C-0001', $jobRejected);
        $this->assertStringContainsString('Usta: Test Usta', $jobRejected);
        $this->assertStringContainsString('Reddetme Nedeni:', $jobRejected);
        $this->assertStringContainsString('Yeniden atama yapılmalı.', $jobRejected);

        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'job_rejected_ops',
                'channel' => 'whatsapp',
                'sample_context' => false,
                'context' => [
                    'internal_job_reference' => 'MRN: PR88-REL4C6',
                    'technician_name' => 'Test Usta',
                    'technician_phone' => '905444444444',
                    'rejected_at_formatted' => '03.07.2026 14:35',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', false)
            ->assertJsonFragment(['Zorunlu değişken eksik: rejection_reason.']);

        foreach ([
            'support_request_ops' => ['Talep Eden:', 'Konu:', 'Açıklama:'],
            'price_revision_requested_ops' => ['Önceki Tutar:', 'Yeni Talep:', 'Açıklama:'],
            'completion_submitted_ops' => ['Usta işi tamamladığını bildirdi.', 'Sonraki Aksiyon:'],
        ] as $messageType => $needles) {
            $body = $this->actingAs($this->admin())
                ->postJson('/api/technical-service/message-templates/preview', [
                    'message_type' => $messageType,
                    'channel' => 'whatsapp',
                ])
                ->assertOk()
                ->assertJsonPath('preview.preview_ready', true)
                ->json('preview.rendered_body');

            foreach ($needles as $needle) {
                $this->assertStringContainsString($needle, $body);
            }
        }
    }

    public function test_preview_sample_can_switch_mount_service_and_payer_state(): void
    {
        $mount = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'context' => [
                    'job_type' => 'montaj',
                    'mrn' => 'MRN-REL4C6-MOUNT',
                    'srv' => '',
                    'payer_state_key' => 'customer_pays_technician',
                    'customer_payment_amount' => 1250,
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('MRN-REL4C6-MOUNT numaralı montaj randevunuz onaylanmıştır.', $mount);
        $this->assertStringContainsString('Randevu sırasında ustaya ödenecek tutar: 1.250,00 TL.', $mount);

        $service = $this->actingAs($this->admin())
            ->postJson('/api/technical-service/message-templates/preview', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'context' => [
                    'job_type' => 'servis',
                    'mrn' => 'MRN-REL4C6-HIDDEN',
                    'srv' => 'SRV-REL4C6-SERVICE',
                    'payer_state_key' => 'company_collected_online',
                    'customer_payment_amount' => 0,
                    'customer_payment_amount_formatted' => '',
                ],
            ])
            ->assertOk()
            ->assertJsonPath('preview.preview_ready', true)
            ->json('preview.rendered_body');

        $this->assertStringContainsString('SRV-REL4C6-SERVICE numaralı servis randevunuz onaylanmıştır.', $service);
        $this->assertStringNotContainsString('MRN-REL4C6-HIDDEN', $service);
        $this->assertStringNotContainsString('ustaya ödeme', mb_strtolower($service, 'UTF-8'));
    }

    public function test_no_block_panel_not_red_and_warning_panel_not_yellow_without_warnings(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-admin.tsx'));

        $this->assertIsString($source);
        $normalizedSource = preg_replace('/\s+/u', ' ', $source);
        $this->assertIsString($normalizedSource);
        $this->assertStringContainsString('templateBlockPanelClass', $source);
        $this->assertStringContainsString('border-emerald-100 bg-emerald-50 text-emerald-900', $source);
        $this->assertStringContainsString('templateWarningPanelClass', $source);
        $this->assertStringContainsString('border-slate-200 bg-white text-slate-700', $source);
        $this->assertStringContainsString('Preview sample senaryosu', $normalizedSource);
        $this->assertStringContainsString('Montaj / MRN', $normalizedSource);
        $this->assertStringContainsString('Servis / SRV', $normalizedSource);
        $this->assertStringContainsString('12:00 - müşteri sabah', $normalizedSource);
        $this->assertStringContainsString('13:00 - müşteri öğleden sonra', $normalizedSource);
        $this->assertStringContainsString('14:00 - 16:00 net usta saati', $normalizedSource);
        $this->assertStringContainsString('Eksik saat / blok testi', $normalizedSource);
    }

    public function test_non_admin_and_public_cannot_access_template_api(): void
    {
        $this->getJson('/api/technical-service/message-templates')->assertUnauthorized();

        $this->actingAs(User::factory()->create(['role_code' => 'ops']))
            ->postJson('/api/technical-service/message-templates', [
                'message_type' => 'appointment_approved_customer',
                'channel' => 'whatsapp',
                'body' => 'Merhaba {customer_name}',
            ])
            ->assertForbidden();
    }

    public function test_admin_ui_contains_template_section_and_shared_test_send_only(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-admin.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Şablonlar', $source);
        $this->assertStringContainsString('Template / preview / değişken doğrulama', $source);
        $this->assertStringContainsString('WhatsApp Şablonları', $source);
        $this->assertStringContainsString('SMS Şablonları', $source);
        $this->assertStringContainsString('Sesli Arama Scriptleri', $source);
        $this->assertStringContainsString('Değişken ekle', $source);
        $this->assertStringContainsString('Önizleme', $source);
        $this->assertStringContainsString('Varsayılana dön', $source);
        $this->assertStringContainsString('Henüz önizleme alınmadı.', $source);
        $this->assertStringContainsString('Blok yok.', $source);
        $this->assertStringContainsString('whitespace-pre-wrap', $source);
        $this->assertStringContainsString('Seçili SMS şablonunun önizlemesi NAC üzerinden ortak test telefonuna gönderilecek.', $source);
        $this->assertStringContainsString('SMS şablonu NAC SMS ile ortak test telefonuna gönderilir; provider altyapı testi değildir.', $source);
        $this->assertStringContainsString('NAC altyapı test SMS’i gönder', $source);
        $this->assertStringContainsString('Test mesajı gönder', $source);
        $this->assertStringContainsString('shared test phone', $source);
        $this->assertStringContainsString('Müşteri mesajları teknik alan isimleriyle', $source);
        $this->assertStringContainsString('servis/SRV mesajında müşteriye MRN', $source);
        $this->assertStringContainsString('Müşteri referansı', $source);
        $this->assertStringContainsString('Gizlenen iç referans', $source);
        $this->assertStringContainsString('Müşteri aralığı', $source);
        $this->assertStringContainsString('Usta net saati', $source);
        $this->assertStringNotContainsString('Blok yok veya henüz preview alınmadı.', $source);
        $this->assertStringContainsString('Direct endpoint:', $source);
        $this->assertStringContainsString('Legacy working HTTP 9587', $source);
        $this->assertStringContainsString('templateTestResultClass', $source);
        $this->assertStringContainsString("dispatch.status === 'sent'", $source);
        $this->assertStringContainsString('dispatch.duplicate', $source);
        $this->assertStringContainsString('Test tipi:', $source);
        $this->assertStringContainsString('Gönderilen içerik:', $source);
        $this->assertStringContainsString('NAC encoding:', $source);
        $this->assertStringContainsString('Paket kodu:', $source);
        $this->assertStringContainsString('Custom ID:', $source);
        $this->assertStringContainsString('Payload hash:', $source);
        $this->assertStringContainsString('Şablon metni değiştirilmeden', $source);
    }

    public function test_admin_ui_has_section_navigation_and_separates_admin_areas(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-admin.tsx'));

        $this->assertIsString($source);
        foreach ([
            'Genel / Panel',
            'Ödeme',
            'Mail',
            'Mesajlaşma',
            'Şablonlar',
            'Kuyruk / Loglar',
            'Entegrasyonlar',
            'SMS API / NAC',
            'Mikro API',
            'Provider Credentials',
            'Operation Catalog',
        ] as $label) {
            $this->assertStringContainsString($label, $source);
        }

        $this->assertStringContainsString("activeAdminSection === 'templates'", $source);
        $this->assertStringContainsString("activeAdminSection === 'payment'", $source);
        $this->assertStringContainsString("activeAdminSection === 'mail'", $source);
        $this->assertStringContainsString("activeMessagingSection === 'nac_sms'", $source);
        $this->assertStringContainsString("activeIntegrationSection === 'mikro_api'", $source);
    }

    public function test_preview_state_ui_copy_is_not_contradictory(): void
    {
        $source = file_get_contents(resource_path('js/pages/panel/technical-service-admin.tsx'));

        $this->assertIsString($source);
        $this->assertStringContainsString('Önizleme alınmadı', $source);
        $this->assertStringContainsString('Önizleme hazır', $source);
        $this->assertStringContainsString('Blok var', $source);
        $this->assertStringContainsString('Önizleme hazır, uyarı var', $source);
        $this->assertStringContainsString('Önizleme oluşturulamadı', $source);
        $this->assertStringContainsString('Blok yok.', $source);
        $this->assertStringNotContainsString('Blok yok veya henüz preview alınmadı.', $source);
    }

    private function admin(): User
    {
        return User::factory()->create(['role_code' => 'admin']);
    }

    private function configureNacSmsTestSettings(): void
    {
        $this->actingAs($this->admin())
            ->postJson('/api/technical-service/messaging-settings/nac-sms/credentials', [
                'username' => 'nac-user@example.test',
                'password' => 'PR88_NAC_CREDENTIAL_TEST_ONLY',
            ])
            ->assertOk();

        $this->actingAs($this->admin())
            ->patchJson('/api/technical-service/messaging-settings', [
                'messaging_enabled' => true,
                'test_mode_enabled' => true,
                'test_phone' => '905467647428',
                'active_provider' => 'nac_sms',
                'nac_sms' => [
                    'enabled' => true,
                    'profile' => 'legacy_working_http_9587',
                    'scheme' => 'http',
                    'host' => 'smslogin.nac.com.tr',
                    'port' => 9587,
                    'path' => '/sms/create',
                    'request_shape' => 'legacy_working_minimal',
                    'sender' => 'EMAKS PRIME',
                    'title' => '',
                    'encoding' => 0,
                    'validity' => 60,
                    'recipient_type' => 0,
                    'use_shared_test_phone' => true,
                ],
                'message_types' => [
                    'appointment_approved_customer' => [
                        'enabled' => true,
                        'test_send_allowed' => true,
                        'channel_policy' => 'sms_only',
                        'sms_mode' => 'test',
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('messaging_settings.nac_sms.test_ready', true);
    }
}
