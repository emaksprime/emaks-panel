# B2B Partner Portal Architecture Plan

## Current Repo Inventory

### Permission and User Infrastructure

- Panel users are stored in `panel.users` through `App\Models\User`.
- Users have a `role_code`, optional `temsilci_kodu`, and active/passive state.
- Roles are stored in `panel.roles` through `App\Models\Role`.
- Page, button, and datasource authorization is resource based:
  - `panel.resources`
  - `panel.role_resource_permissions`
  - `panel.user_access`
- `App\Services\PanelAccessService` resolves access in this order:
  1. inactive or missing user is denied
  2. super admin role is allowed
  3. explicit user deny wins
  4. explicit user allow wins
  5. role resource permission decides access
- `App\Http\Middleware\EnsurePanelUserCanAccess` enforces `panel.access:*` route middleware.
- The current access model is good for page/resource visibility, but it is not enough for B2B entity isolation. A partner user must be scoped to specific dealer or locksmith rows at the backend query level.

### Admin and Page Metadata Infrastructure

- Admin APIs under `/api/admin/*` manage users, pages, buttons, datasources, and logs.
- `database/migrations/2026_04_24_120000_create_panel_metadata_tables.php` creates the panel metadata schema.
- `database/seeders/PanelMetadataSeeder.php` seeds pages, menu groups, resources, role permissions, and page configs.
- `App\Services\PanelNavigationService` builds sidebar/navigation from `panel.pages`, `panel.page_menu`, and `panel.menu_groups`.
- `App\Http\Controllers\PanelPageController` resolves the visible panel page and renders the matching Inertia component.
- New B2B pages should follow this metadata model instead of hardcoding navigation.

### Technical Service and Locksmith Infrastructure

- Locksmiths are currently represented as `technical_service_technicians`.
- `App\Models\TechnicalServiceTechnician` already contains:
  - `technician_type`
  - phone and normalized phone fields
  - city, district, address and location fields
  - `mikro_cari_kodu`, `mikro_cari_adi`
  - `cari_code`, `cari_title`, `cari_address`
  - geocode and review fields
  - `source_key`
- `App\Services\TechnicalService\LocksmithImportService` imports locksmith rows and uses `technician_type = locksmith`.
- `TechnicalServiceRequest` links assignment through `technical_service_technician_id`.
- Route quote, assignment, technician approval, and earning flows depend on the technician record.
- `TechnicalServiceEarningService` groups completed requests by `technical_service_technician_id` for hakediş.
- B2B locksmith portal must link to this technician record, but should not turn `technical_service_technicians` itself into the portal tenant table.

### Mikro, Cari, Dealer, Sales, Stock, and Order Touchpoints

- Cari pages are served through `CariBilgiPageService`, `PanelDataSourceManager`, and `N8nPanelDataGateway`.
- Sales pages and customer search have resource-scoped access such as `sales_main`, `sales_online`, and `sales_bayi`.
- Current sales/customer lookup scopes are representative and resource based, not partner entity based.
- Mikro cari fields already appear in technician records and datasource rows:
  - `cari_kodu`
  - `cari_unvani`
  - `cari_grup_kodu`
  - responsibility/sorumluluk fields in technical service serial payloads
- `PanelDataSourcesSeeder` and `PanelKnownWorkflowDataSourcesSeeder` contain datasource and query-template material. These are red-zone for this planning task and must remain separate from the B2B scaffold.

## Scope

- Bayi portalı
- Çilingir / Usta portalı
- Yönetim izleme ekranı
- Partner kullanıcı yönetimi
- Partner bazlı yetkilendirme
- Mikro cari bağlama
- Teknik Servis bağlantısı

## Non-goals

- Bu fazda gerçek Mikro datasource sorgusu yok.
- Bu fazda Sales/Stok/Sipariş logic değişmeyecek.
- Bu fazda canlı deploy yok.
- Bu fazda ödeme/teknik servis route akışı değişmeyecek.
- Bu fazda datasource, query_template, allowed_params veya connection_meta değişikliği yok.
- Bu fazda yeni ekran, model veya migration uygulanmayacak; bu doküman mimari plan içindir.

## Partner Types

- `dealer`
- `locksmith`

## Data Model Draft

Bu tablolar öneridir; bu görevde migration yazılmayacak.

### 1. `b2b_partners`

- `id`
- `partner_type`: `dealer|locksmith`
- `partner_code`
- `display_name`
- `mikro_cari_kodu`
- `mikro_cari_unvan`
- `cari_grup_kodu`
- `responsibility_code`
- `phone`
- `email`
- `city`
- `district`
- `active`
- `technical_service_technician_id` nullable
- `metadata` json
- `created_at`
- `updated_at`

Notes:

- `partner_code` is the internal stable partner key.
- `mikro_cari_kodu` links dealer or locksmith to Mikro cari data.
- `technical_service_technician_id` is only used for locksmith partners.
- Dealer partners should normally not have `technical_service_technician_id`.

### 2. `b2b_partner_user_access`

- `id`
- `user_id`
- `partner_id`
- `access_scope`: `view|manage|orders|stock|finance|technical_service|users`
- `can_view`
- `can_create`
- `can_update`
- `can_approve`
- `created_by`
- `created_at`
- `updated_at`

Notes:

- This table fills the gap that current `panel.user_access` cannot cover: entity-level access.
- Backend queries must use this table to scope partner data.
- Frontend filtering alone is not acceptable.

### 3. `b2b_partner_user_profiles`

- `id`
- `user_id`
- `partner_id`
- `title`
- `phone`
- `active`
- `last_seen_at`
- `metadata` json

Notes:

- `panel.users` remains the authentication identity.
- This table stores partner-specific user profile details.

### 4. `b2b_partner_audit_logs`

- `id`
- `partner_id`
- `user_id`
- `action`
- `subject_type`
- `subject_id`
- `old_values` json
- `new_values` json
- `ip`
- `user_agent`
- `created_at`

Notes:

- Every partner-user assignment, scope change, and sensitive portal action should create an audit row.
- Admin changes and partner self-service changes must be distinguishable by `action`.

## Permission Matrix

### Global Permissions

- `b2b.view`
- `b2b.manage`
- `b2b.dealers.view`
- `b2b.dealers.manage`
- `b2b.locksmiths.view`
- `b2b.locksmiths.manage`
- `b2b.orders.view`
- `b2b.orders.manage`
- `b2b.stock.view`
- `b2b.finance.view`
- `b2b.technical_service.view`
- `b2b.partner_users.manage`

### Entity Permissions

- can view partner X
- can view dealer X
- can view locksmith X
- can view all dealers
- can view all locksmiths
- can view own partner only

### Mapping to Existing Panel Access

- Existing `panel.access:*` should still protect page entry.
- New partner scopes must protect the query results and detail endpoints.
- Example:
  - `panel.access:b2b.dealers.view` lets the user open dealer pages.
  - `b2b_partner_user_access` decides which dealer rows the user can see.

## Security Rules

- Frontend filtering tek başına yeterli değil.
- Backend policy/scope zorunlu.
- Her endpoint partner access scope ile filtrelenmeli.
- Bir kullanıcı URL manipülasyonu ile yetkisiz partner verisi görememeli.
- Bayi kullanıcıları başka bayinin verisini göremez.
- Çilingir kullanıcıları başka çilingirin işlerini göremez.
- Admin/Yönetim rolü tümünü görebilir.
- Kullanıcı yönetimi ayrı admin süreci olacak.
- Technical Service locksmith access must filter by linked `technical_service_technician_id`.
- Dealer access must filter by partner `mikro_cari_kodu` or a reviewed future datasource scope, not by UI tab state.

## Screens

### Management B2B Dashboard

Cards:

- toplam bayi
- toplam çilingir
- aktif/pasif partner
- açık siparişler
- açık teknik servis işleri
- partner bazlı stok
- cari/risk uyarıları
- SLA/performans
- son aktiviteler

### Partner Directory

- Bayi ve çilingir listesi
- filtre: tip, şehir, aktif, cari kodu, sorumluluk kodu
- Mikro cari bağla
- Teknik servis çilingir bağla
- kullanıcı ata
- yetki ata

### Dealer Portal

- cari bakiye / risk özeti
- siparişler
- teklifler
- stok görünümü
- proje teklifleri
- konsinye/teşhir stoklar
- kendi kullanıcıları

### Locksmith Portal

- atanmış işler
- günlük ajanda
- iş geçmişi
- hakedişler
- rota/konum
- teknik servis dokümanları
- stok/parça görünümü varsa

### Partner Users Admin

- kullanıcı oluştur
- partner ata
- rol ata
- scope ata
- aktif/pasif
- şifre reset
- son giriş
- audit

## Technical Service Link

- A locksmith partner links to one `technical_service_technicians.id`.
- `b2b_partners.technical_service_technician_id` is the bridge.
- The technician record remains the operational service actor.
- The partner record becomes the portal and reporting tenant.
- Usta atama ekranında çilingir partner bilgisi görünür:
  - partner adı
  - Mikro cari kodu
  - bağlı kullanıcı sayısı
  - aktif/pasif partner durumu
- Hakediş, iş geçmişi, performans partner üzerinden raporlanır.
- Çilingir portalında sadece atanmış MRN’ler görünür.
- Query scope for locksmith portal:
  - current user -> partner access -> partner technical technician id -> `technical_service_requests.technical_service_technician_id`
- This link must not bypass current technical service assignment, route, geocode, payment, or earning logic.

## Mikro Link

- partner `mikro_cari_kodu` ile bağlanır.
- `mikro_cari_unvan`, `cari_grup_kodu`, and `responsibility_code` are copied or synchronized metadata.
- cari/risk/stok/sipariş okumaları mevcut datasource/gateway ile ileride bağlanır.
- Red-zone gerektiren datasource değişiklikleri ayrı review konusu olur.
- B2B Phase 1 should store the relation, not create new MSSQL queries.
- Dealer portal MVP should start with local partner records and reviewed safe read contracts.

## Route / Page Plan

Suggested management routes:

- `/panel/b2b`
- `/panel/b2b/partners`
- `/panel/b2b/partners/{partner}`
- `/panel/b2b/users`
- `/panel/b2b/permissions`
- `/panel/b2b/dealers`
- `/panel/b2b/locksmiths`

Suggested partner portal routes:

- `/partner/dashboard`
- `/partner/orders`
- `/partner/stock`
- `/partner/service-jobs`

Suggested API routes:

- `GET /api/b2b/partners`
- `POST /api/b2b/partners`
- `PATCH /api/b2b/partners/{partner}`
- `GET /api/b2b/partners/{partner}/users`
- `POST /api/b2b/partners/{partner}/users`
- `PATCH /api/b2b/partners/{partner}/users/{user}`
- `GET /api/partner/dashboard`
- `GET /api/partner/orders`
- `GET /api/partner/stock`
- `GET /api/partner/service-jobs`

Page metadata notes:

- Add B2B pages through `panel.pages`, `panel.resources`, `panel.page_menu`, and role permissions.
- Do not hardcode B2B sidebar items outside the existing panel navigation system.

## Implementation Phases

### Phase 0

- inventory + architecture doc

### Phase 1

- data model + permissions scaffold
- create B2B resources and page metadata
- add backend entity-scope service
- no datasource integration yet

### Phase 2

- partner directory + Mikro cari bağlama UI
- dealer/locksmith filters
- technical service technician linking for locksmith partners

### Phase 3

- partner user admin + entity ACL
- user assignment
- access scopes
- audit logs

### Phase 4

- management B2B dashboard
- partner counts
- operational summaries
- technical service and financial placeholders from safe sources

### Phase 5

- dealer portal MVP
- cari/risk summary if safe read contract exists
- stock/order read-only placeholders until datasource review

### Phase 6

- locksmith portal MVP linked to technical service
- assigned jobs
- daily agenda
- job history
- hakediş summary

### Phase 7

- stock/orders/cari datasource integration
- red-zone review if needed
- MSSQL/n8n changes only in a separate reviewed package

## Acceptance Criteria

- Admin user dealer/locksmith görebilir.
- Sadece bayi yetkisi olan kullanıcı çilingir göremez.
- Sadece çilingir yetkisi olan kullanıcı bayi göremez.
- Kullanıcı sadece atanmış partner kayıtlarını görebilir.
- URL manipülasyonu ile yetkisiz partner görülmez.
- Çilingir portalı sadece kendi MRN’lerini gösterir.
- Bayi portalı sadece kendi sipariş/stok/cari görünümünü gösterir.
- Kullanıcı yönetimi ayrı admin ekranındadır.
- Mikro cari kodu bağlanabilir.
- Technical service technician çilingir partner ile bağlanabilir.

## Risks

- The current permission system is resource-level. Treating it as partner-level security would be a weak design.
- Sales/Stok/Sipariş datasets already have datasource and scope logic. Reusing them without partner entity filtering can leak data.
- Technical Service locksmith records are operational actors, not portal tenants. Overloading them directly for portal access would mix concerns.
- Mikro cari grouping and responsibility-code rules are not fully confirmed. Hardcoding partner classification from guessed cari patterns would be risky.
- Datasource/query_template work is red-zone and must be isolated in a later review package.
- Dealer and locksmith users may need different login/home routing than internal panel users.

## Open Questions

- Bayi ve çilingir aynı Mikro cari tipiyle mi ayrılıyor, yoksa grup/sorumluluk kodu mu kullanılacak?
- Çilingirlerin cari kodları her zaman 320.ÇLG... formatında mı?
- Bayi kodları hangi Mikro alanıyla ayırt edilecek?
- Partner kullanıcıları kendi şifrelerini değiştirecek mi?
- Bayi portalında sipariş oluşturma ilk MVP’ye dahil mi, yoksa sadece görüntüleme mi?
- Çilingir portalı PWA mı olacak, yoksa panel içinde web ekranı mı?
- Stok görünümü gerçek zamanlı mı, cache/daily snapshot mı?
