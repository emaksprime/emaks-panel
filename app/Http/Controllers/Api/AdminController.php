<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Button;
use App\Models\DataSource;
use App\Models\MenuGroup;
use App\Models\Page;
use App\Models\PageConfig;
use App\Models\PageMenu;
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
    ) {
    }

    public function overview(): JsonResponse
    {
        return response()->json([
            'counts' => [
                'users' => User::query()->count(),
                'pages' => Page::query()->count(),
                'datasources' => DataSource::query()->count(),
                'logs' => \App\Models\AuditLog::query()->count(),
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
            'pages' => Page::query()
                ->with('menuItems.menuGroup')
                ->orderBy('page_order')
                ->get()
                ->map(fn (Page $page) => [
                    'id' => $page->id,
                    'code' => $page->code,
                    'name' => $page->name,
                    'route' => $page->route,
                    'icon' => $page->icon,
                    'resource_code' => $page->resource_code,
                    'component' => $page->component,
                    'layout_type' => $page->layout_type ?? 'module',
                    'description' => $page->description,
                    'page_order' => $page->page_order,
                    'active' => $page->active,
                    'menu_group_id' => $page->menuItems->first()?->menu_group_id,
                    'menu_label' => $page->menuItems->first()?->label,
                    'menu_visible' => $page->menuItems->first()?->is_visible ?? true,
                    'menu_sort_order' => $page->menuItems->first()?->sort_order ?? $page->page_order,
                ]),
            'menuGroups' => MenuGroup::query()->orderBy('menu_order')->get(),
            'resources' => Resource::query()->where('type', 'page')->orderBy('name')->get(['code', 'name']),
            'buttons' => Button::query()
                ->with('page')
                ->orderBy('page_id')
                ->orderBy('sort_order')
                ->get()
                ->map(fn (Button $button) => [
                    'id' => $button->id,
                    'page_id' => $button->page_id,
                    'page_code' => $button->page?->code,
                    'code' => $button->code,
                    'label' => $button->label,
                    'resource_code' => $button->resource_code,
                    'variant' => $button->variant,
                    'action_type' => $button->action_type,
                    'action_target' => $button->action_target,
                    'position' => $button->position ?? 'page_top',
                    'config_json' => $button->config_json ?? [],
                    'confirmation_required' => $button->confirmation_required ?? false,
                    'confirmation_text' => $button->confirmation_text,
                    'sort_order' => $button->sort_order,
                    'is_visible' => $button->is_visible,
                ]),
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
            'layout_type' => ['nullable', Rule::in(['admin', 'module'])],
            'description' => ['nullable', 'string'],
            'page_order' => ['integer', 'min:0'],
            'active' => ['boolean'],
            'menu_group_id' => ['nullable', 'integer', 'exists:panel.menu_groups,id'],
            'menu_label' => ['nullable', 'string', 'max:255'],
            'menu_visible' => ['boolean'],
            'menu_sort_order' => ['integer', 'min:0'],
        ]);

        Resource::query()->updateOrCreate(
            ['code' => $data['resource_code'] ?: $data['code']],
            ['name' => $data['name'], 'type' => 'page', 'active' => true],
        );

        $pagePayload = [
            ...$data,
            'resource_code' => $data['resource_code'] ?: $data['code'],
            'layout_type' => $data['layout_type'] ?? 'module',
            'active' => (bool) ($data['active'] ?? true),
        ];

        unset($pagePayload['id']);
        unset($pagePayload['menu_group_id'], $pagePayload['menu_label'], $pagePayload['menu_visible'], $pagePayload['menu_sort_order']);

        $page = isset($data['id'])
            ? tap(Page::query()->findOrFail($data['id']))->update($pagePayload)
            : Page::query()->create($pagePayload);

        PageConfig::query()->firstOrCreate(['page_code' => $page->code], ['layout_json' => [], 'filters_json' => []]);

        if (! empty($data['menu_group_id'])) {
            PageMenu::query()->updateOrCreate(
                ['page_id' => $page->id, 'menu_group_id' => $data['menu_group_id']],
                [
                    'label' => $data['menu_label'] ?: $page->name,
                    'icon' => $page->icon,
                    'sort_order' => $data['menu_sort_order'] ?? $page->page_order,
                    'is_visible' => (bool) ($data['menu_visible'] ?? true),
                ],
            );
        }

        $this->auditLogger->log($request->user(), 'admin.page.save', ['page_code' => $page->code], $request);

        return $this->pages();
    }

    public function saveButton(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['nullable', 'integer', 'exists:panel.buttons,id'],
            'page_id' => ['required', 'integer', 'exists:panel.pages,id'],
            'code' => ['required', 'string', 'max:128', Rule::unique('panel.buttons', 'code')->ignore($request->integer('id'))],
            'label' => ['required', 'string', 'max:255'],
            'resource_code' => ['nullable', 'string', 'max:128'],
            'variant' => ['required', Rule::in(['primary', 'secondary', 'danger', 'ghost'])],
            'action_type' => ['required', Rule::in(['navigate', 'webhook', 'modal', 'refresh', 'custom'])],
            'action_target' => ['nullable', 'string', 'max:500'],
            'position' => ['nullable', Rule::in(['header_right', 'filter_bar', 'table_row', 'table_bulk', 'card_footer', 'page_top'])],
            'config_json' => ['nullable', 'array'],
            'confirmation_required' => ['boolean'],
            'confirmation_text' => ['nullable', 'string', 'max:500'],
            'sort_order' => ['integer', 'min:0'],
            'is_visible' => ['boolean'],
        ]);

        $payload = [
            ...$data,
            'position' => $data['position'] ?? 'page_top',
            'config_json' => $data['config_json'] ?? [],
            'confirmation_required' => (bool) ($data['confirmation_required'] ?? false),
            'is_visible' => (bool) ($data['is_visible'] ?? true),
        ];

        unset($payload['id']);

        $button = isset($data['id'])
            ? tap(Button::query()->findOrFail($data['id']))->update($payload)
            : Button::query()->create($payload);

        $this->auditLogger->log($request->user(), 'admin.button.save', ['button_code' => $button->code], $request);

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
            'db_type' => ['required', Rule::in(['mssql', 'postgres', 'n8n_json', 'static_preview'])],
            'query_template' => ['required', 'string'],
            'allowed_params' => ['array'],
            'connection_meta' => ['array'],
            'preview_payload' => ['array'],
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
            'logs' => \App\Models\AuditLog::query()
                ->orderByDesc('created_at')
                ->limit(200)
                ->get(),
        ]);
    }
}
