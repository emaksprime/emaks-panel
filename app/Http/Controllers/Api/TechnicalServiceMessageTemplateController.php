<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Messaging\TechnicalServiceMessageTemplateService;
use App\Services\Messaging\TechnicalServiceMessageTypeRegistry;
use App\Services\Messaging\TechnicalServiceMessagingSettingsService;
use App\Services\Messaging\TechnicalServiceTemplateTestSendService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TechnicalServiceMessageTemplateController extends Controller
{
    public function index(TechnicalServiceMessageTemplateService $templates): JsonResponse
    {
        return response()->json([
            'message_templates' => $templates->payload(),
        ]);
    }

    public function variables(TechnicalServiceMessageTemplateService $templates): JsonResponse
    {
        return response()->json([
            'variables' => $templates->payload()['variables'],
            'forbidden_variables' => $templates->payload()['forbidden_variables'],
        ]);
    }

    public function preview(Request $request, TechnicalServiceMessageTemplateService $templates): JsonResponse
    {
        return response()->json([
            'preview' => $templates->preview($this->validatedPayload($request, preview: true)),
        ]);
    }

    public function store(Request $request, TechnicalServiceMessageTemplateService $templates): JsonResponse
    {
        return response()->json([
            'message_templates' => $templates->save($this->validatedPayload($request)),
            'message' => 'Mesaj şablonu kaydedildi. Bu işlem provider mesajı göndermez.',
        ]);
    }

    public function restoreDefault(Request $request, TechnicalServiceMessageTemplateService $templates): JsonResponse
    {
        return response()->json([
            'message_templates' => $templates->restoreDefault($this->validatedPayload($request, restore: true)),
            'message' => 'Mesaj şablonu varsayılana döndürüldü.',
        ]);
    }

    public function testSend(Request $request, TechnicalServiceTemplateTestSendService $sender): JsonResponse
    {
        return response()->json([
            'test_send' => $sender->send($this->validatedPayload($request, preview: true, testSend: true), $request->user()),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedPayload(Request $request, bool $preview = false, bool $restore = false, bool $testSend = false): array
    {
        $messageTypes = array_keys(TechnicalServiceMessagingSettingsService::MESSAGE_TYPES);
        $channels = array_keys(TechnicalServiceMessageTypeRegistry::CHANNELS);
        $providers = array_keys(TechnicalServiceMessagingSettingsService::PROVIDERS);

        return $request->validate([
            'template_key' => [$restore ? 'sometimes' : 'sometimes', 'nullable', 'string', 'max:160'],
            'message_type' => ['required', 'string', Rule::in($messageTypes)],
            'channel' => ['required', 'string', Rule::in($channels)],
            'provider_key' => ['sometimes', 'nullable', 'string', Rule::in($providers)],
            'title' => [$restore ? 'sometimes' : 'sometimes', 'nullable', 'string', 'max:160'],
            'body' => [$preview || $restore ? 'sometimes' : 'required', 'nullable', 'string', 'max:5000'],
            'active' => ['sometimes', 'boolean'],
            'required_variables' => ['sometimes', 'array'],
            'required_variables.*' => ['required', 'string', 'max:80'],
            'optional_variables' => ['sometimes', 'array'],
            'optional_variables.*' => ['required', 'string', 'max:80'],
            'validation_rules' => ['sometimes', 'array'],
            'context' => ['sometimes', 'array'],
            'sample_context' => ['sometimes', 'boolean'],
            'technical_service_request_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'request_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'confirmed' => [$testSend ? 'accepted' : 'sometimes', 'boolean'],
            'real_sms_confirmed' => [$testSend ? 'sometimes' : 'sometimes', 'boolean'],
        ]);
    }
}
