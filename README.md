# Emaks Prime Panel

Laravel 13 + Inertia v3 + React + Vite tabanli production dashboard kabugu.

## Stack

- Laravel 13
- Inertia.js v3 with React
- Vite
- Laravel Fortify auth
- PostgreSQL
- Docker / Coolify

## Panel metadata

Menu, sayfa, buton ve admin yetkileri PostgreSQL `panel` semasindan okunur:

- `panel.users`
- `panel.roles`
- `panel.user_access`
- `panel.pages`
- `panel.menu_groups`
- `panel.page_menu`
- `panel.buttons`
- `panel.resources`
- `panel.role_resource_permissions`
- `panel.data_sources`
- `panel.page_configs`
- `panel.sessions`
- `panel.logs`

`data_sources` tablosu MSSQL baglanti metadata'sini tasir. MSSQL sorgulari uygulama koduna gomulmez. Su an executor arayuzu hazirdir, gercek SQL Server execution katmani bilerek baglanmamistir.

## Routes

- `/` authenticated kullaniciyi ilk yetkili sayfasina yonlendirir.
- `/dashboard` panel ana sayfasidir.
- `/sales/main` workflow referansli Sales Main dashboard sayfasidir.
- `/admin`, `/admin/users`, `/admin/pages`, `/admin/datasources`, `/admin/logs` admin moduludur.
- `GET /api/navigation` DB-driven navigation payload doner.
- `GET /api/pages/{code}/config` sayfa konfigurasyonunu doner.
- `POST /api/data/sales-main` Sales Main datasource payload'unu doner.

## Local setup

```bash
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan db:seed --class=PanelMetadataSeeder
composer install
npm install
npm run build
```

Ardindan:

```bash
php artisan serve
```

## Required environment

`.env` dosyasi repo disinda kalmali. `.gitignore` buna zaten zorlama getirir.

Temel degiskenler:

```env
APP_NAME="Emaks Prime Panel"
APP_URL=https://dashboard.emaksprime.com.tr
APP_ENV=production
APP_KEY=

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=emaks_panel
DB_USERNAME=emaks_panel
DB_PASSWORD=
DB_SSLMODE=prefer
DATABASE_URL=
PGHOST=
PGPORT=
PGDATABASE=
PGUSER=
PGPASSWORD=

SESSION_DRIVER=database
SESSION_DOMAIN=.emaksprime.com.tr
SESSION_SECURE_COOKIE=true

PANEL_BRAND="Emaks Prime Panel"
PANEL_DEFAULT_ROLE=admin
PANEL_BOOTSTRAP_ADMIN_USERNAME=
PANEL_BOOTSTRAP_ADMIN_PASSWORD=
PANEL_BOOTSTRAP_ADMIN_NAME="Panel Administrator"
PANEL_BOOTSTRAP_ADMIN_REP_CODE=0003

PRIMECRM_ENABLED=false
PRIMECRM_BASE_URL=
PRIMECRM_LAUNCH_MODE=external
```

`PANEL_BOOTSTRAP_ADMIN_PASSWORD` sadece Coolify environment variable olarak girilmeli, `.env` disinda hicbir yere yazilmamalidir. Seeder bu degerler varsa ilk admin kullanicisini olusturur ve tum `resource_code` erisimlerini atar.

## PrimeCRM entegrasyonu

PrimeCRM bu repoya gomulmez ve ASP.NET/IIS dosyalari tasinmaz. Ayrı bir Coolify servisi olarak deploy edilir; Emaks Panel sadece `PRIMECRM_BASE_URL` ile harici modül yollarını bilir.

Coolify tarafında PrimeCRM servisi hazır olduğunda panel container environment değerleri:

```env
PRIMECRM_ENABLED=true
PRIMECRM_BASE_URL=https://primecrm.emaksprime.com.tr
PRIMECRM_LAUNCH_MODE=external
```

Paneldeki Satış, Stok, Sipariş, Cari ve Proforma modülleri bu URL üzerinden PrimeCRM ekranlarına yönlendirme ve sonraki fazdaki API köprüsü için hazırlanmıştır.

## Coolify deploy

Coolify icin tek container yeterlidir.

1. Build source olarak bu repoyu sec.
2. Dockerfile path olarak repo root kullan.
3. Port olarak `8080` expose et.
4. `APP_KEY`, `DB_*`, `PANEL_BOOTSTRAP_ADMIN_*`, mail ve diger secret'lari Coolify environment variables olarak gir.
5. Domain olarak `dashboard.emaksprime.com.tr` bagla.
6. `RUN_MIGRATIONS=true` ve istersen `RUN_PANEL_SEED=true` birak. Seeder idempotent calisir.

Health endpoint Laravel tarafinda `/up` olarak mevcuttur.

## Docker Compose

Local veya VM uzerinde app + PostgreSQL birlikte calistirmak icin:

```bash
docker compose up -d --build
```

Compose dosyasi secret icermez. `APP_KEY`, `DB_PASSWORD` ve ilk admin icin `PANEL_BOOTSTRAP_ADMIN_USERNAME` / `PANEL_BOOTSTRAP_ADMIN_PASSWORD` degerlerini shell environment veya lokal `.env` dosyasindan ver.

```env
APP_KEY=base64:...
DB_PASSWORD=change-me
PANEL_BOOTSTRAP_ADMIN_USERNAME=admin
PANEL_BOOTSTRAP_ADMIN_PASSWORD=change-me-too
```

Uygulama container icinde `8080`, host tarafinda varsayilan olarak `8080` portundan yayinlanir. Host portunu degistirmek icin `APP_PORT=8081` kullanabilirsin.

## Notes

- n8n workflow dosyalari Laravel icinde HTML string olarak tutulmaz; layout/filter/datasource mantigi `page_configs` ve `data_sources` metadata kayitlarina tasinir.
- `Twenty - Stok Dashboard - Corrected v2.json`, `SALES_BAYI_PROJE_DETAY_V1.json`, `SALES_ONLINE_PERAKENDE_DETAY_V1.json` ve `EMAKS PRIME - Siparisler Workflow (TAM FIX).json` icin datasource slotlari hazirdir.
- Demo hizli linkler ve starter footer baglantilari projeden cikartilmistir.
