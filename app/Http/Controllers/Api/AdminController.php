<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\Resource;
use App\Models\Role;
use App\Models\User;
use App\Models\UserAccess;
use App\Services\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;

class AdminController extends Controller
{
    public function __construct(
        private readonly AuditLogger $auditLogger,
    ) {}

    public function overview(): JsonResponse
    {
        return response()->json([
            'counts' => [
                'users' => User::query()->count(),
                'pages' => Page::query()->count(),
                'datasources' => DataSource::query()->count(),
                'logs' => AuditLog::query()->count(),
            ],
            'roles' => Role::query()->orderBy('code')->get(['code', 'name', 'description']),
            'urls' => [
                'publicUrl' => config('panel.public_url'),
                'apiBaseUrl' => config('panel.api_base_url'),
                'webhookBaseUrl' => config('panel.webhook_base_url'),
                'workflowUrls' => config('panel.workflow_urls'),
            ],
        ]);
    }

    public function users(): JsonResponse
    {
        return response()->json([
            'users' => User::query()
                ->with('role')
                ->orderBy('full_name')
                ->get()
                ->map(fn (User $user) => [
                    'id' => $user->id,
                    'username' => $user->username,
                    'full_name' => $user->full_name,
                    'role_code' => $user->role_code,
                    'temsilci_kodu' => $user->temsilci_kodu,
                    'aktif' => $user->aktif,
                    'access' => UserAccess::query()->where('user_id', $user->id)->pluck('resource_code')->values(),
                ]),
            'roles' => Role::query()->orderBy('code')->get(['code', 'name']),
            'resources' => Resource::query()->where('active', true)->orderBy('type')->orderBy('name')->get(['code', 'name', 'type']),
        ]);
    }

    public function saveUser(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:panel.users,id'],
            'username' => [
                'required',
                'string',
                'max:255',
                Rule::unique('panel.users', 'username')->ignore($request->integer('id')),
            ],
            'full_name' => ['required', 'string', 'max:255'],
            'password' => [$request->filled('id') ? 'nullable' : 'required', 'string', 'min:8'],
            'role_code' => ['required', Rule::exists('panel.roles', 'code')],
            'temsilci_kodu' => ['nullable', 'string', 'max:32'],
            'aktif' => ['boolean'],
            'access' => ['array'],
            'access.*' => ['string', Rule::exists('panel.resources', 'code')],
        ]);

        $payload = [
            'username' => $data['username'],
            'full_name' => $data['full_name'],
            'role_code' => $data['role_code'],
            'temsilci_kodu' => $data['temsilci_kodu'] ?? null,
            'aktif' => (bool) ($data['aktif'] ?? true),
        ];

        if (! empty($data['password'])) {
            $payload['password_hash'] = Hash::make($data['password']);
        }

        $user = isset($data['id'])
            ? tap(User::query()->findOrFail($data['id']))->update($payload)
            : User::query()->create($payload);

        UserAccess::query()->where('user_id', $user->id)->delete();
        foreach (array_unique($data['access'] ?? []) as $resourceCode) {
            UserAccess::query()->create([
                'user_id' => $user->id,
                'resource_code' => $resourceCode,
            ]);
        }

        $this->auditLogger->log($request->user(), 'admin.user.save', ['target_user_id' => $user->id], $request);

        return $this->users();
    }

    public function pages(): JsonResponse
    {
        return response()->json([
            'pages' => Page::query()->orderBy('page_order')->get(),
            'menuGroups' => MenuGroup::query()->orderBy('menu_order')->get(),
            'resources' => Resource::query()->where('type', 'page')->orderBy('name')->get(['code', 'name']),
        ]);
    }

    public function savePage(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:panel.pages,id'],
            'code' => ['required', 'string', 'max:128', Rule::unique('panel.pages', 'code')->ignore($request->integer('id'))],
            'name' => ['required', 'string', 'max:255'],
            'route' => ['required', 'string', 'max:255', Rule::unique('panel.pages', 'route')->ignore($request->integer('id'))],
            'icon' => ['nullable', 'string', 'max:80'],
            'resource_code' => ['nullable', 'string', 'max:128'],
            'component' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'page_order' => ['integer', 'min:0'],
            'active' => ['boolean'],
        ]);

        Resource::query()->updateOrCreate(
            ['code' => $data['resource_code'] ?: $data['code']],
            ['name' => $data['name'], 'type' => 'page', 'active' => true],
        );

        $pagePayload = [
            ...$data,
            'resource_code' => $data['resource_code'] ?: $data['code'],
            'active' => (bool) ($data['active'] ?? true),
        ];

        unset($pagePayload['id']);

        $page = isset($data['id'])
            ? tap(Page::query()->findOrFail($data['id']))->update($pagePayload)
            : Page::query()->create($pagePayload);

        PageConfig::query()->firstOrCreate(['page_code' => $page->code], ['layout_json' => [], 'filters_json' => []]);
        $this->auditLogger->log($request->user(), 'admin.page.save', ['page_code' => $page->code], $request);

        return $this->pages();
    }

    public function deletePage(Request $request, Page $page): JsonResponse
    {
        $this->auditLogger->log($request->user(), 'admin.page.delete', ['page_code' => $page->code], $request);
        $page->delete();

        return $this->pages();
    }

    public function dataSources(): JsonResponse
    {
        return response()->json([
            'dataSources' => DataSource::query()->orderBy('sort_order')->orderBy('name')->get(),
        ]);
    }

    public function saveDataSource(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:panel.data_sources,id'],
            'code' => ['required', 'string', 'max:128', Rule::unique('panel.data_sources', 'code')->ignore($request->integer('id'))],
            'name' => ['required', 'string', 'max:255'],
            'db_type' => ['required', Rule::in(['mssql', 'postgres'])],
            'query_template' => ['required', 'string'],
            'allowed_params' => ['array'],
            'connection_meta' => ['array'],
            'active' => ['boolean'],
            'description' => ['nullable', 'string'],
            'sort_order' => ['integer', 'min:0'],
        ]);

        $sourcePayload = [
            ...$data,
            'active' => (bool) ($data['active'] ?? true),
        ];

        unset($sourcePayload['id']);

        $source = isset($data['id'])
            ? tap(DataSource::query()->findOrFail($data['id']))->update($sourcePayload)
            : DataSource::query()->create($sourcePayload);

        Resource::query()->updateOrCreate(
            ['code' => $source->code],
            ['name' => $source->name, 'type' => 'data_source', 'active' => $source->active],
        );

        $this->auditLogger->log($request->user(), 'admin.datasource.save', ['data_source_code' => $source->code], $request);

        return $this->dataSources();
    }

    public function logs(): JsonResponse
    {
        return response()->json([
            'logs' => AuditLog::query()
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(),
        ]);
    }
}
