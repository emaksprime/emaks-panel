import { Head, Link } from '@inertiajs/react'

type Capability = 'dealer' | 'locksmith' | 'manufacturer' | 'seller'

type LinkedTechnician = {
  id: number
  technical_service_technician_id: number
  relationship_type: string
  is_primary: boolean
  name: string | null
  phone: string | null
  city: string | null
  district: string | null
}

type PartnerSummary = {
  id: number
  display_name: string
  partner_code: string
  capabilities: Capability[]
  mikro_cari_kodu: string | null
  mikro_cari_unvan: string | null
  phone: string | null
  email: string | null
  city: string | null
  district: string | null
  address: string | null
  child_cari_accounts: Array<{
    mikro_cari_kodu?: string | null
    usage_type?: string | null
    cari_usage_type?: string | null
  }>
  linked_technicians: LinkedTechnician[]
  users_count: number
  active_users_count: number
}

type ServiceJob = {
  id: number
  mrn: string
  customer_name: string | null
  customer_city: string | null
  customer_district: string | null
  status: string | null
  workflow_status: string | null
  updated_at: string | null
}

type PartnerPortalProps = {
  partnerPortal: {
    view: 'dashboard' | 'profile' | 'orders' | 'stock' | 'service-jobs'
    allowed: boolean
    deniedMessage: string | null
    partners: PartnerSummary[]
    selectedPartner: PartnerSummary
    stats: {
      linked_technicians_count: number
      users_count: number
      active_users_count: number
      open_service_jobs_count: number
    }
    serviceJobs: ServiceJob[]
    placeholders: Record<string, string>
  }
}

const capabilityLabel = (capability: Capability) => {
  if (capability === 'dealer') {
    return 'Bayi'
  }

  if (capability === 'locksmith') {
    return 'Çilingir'
  }

  if (capability === 'manufacturer') {
    return 'Üretici'
  }

  return 'Satıcı'
}

const viewTitle = (view: PartnerPortalProps['partnerPortal']['view']) => {
  if (view === 'profile') {
    return 'Partner Profili'
  }

  if (view === 'orders') {
    return 'Siparişlerim'
  }

  if (view === 'stock') {
    return 'Stok Görünümü'
  }

  if (view === 'service-jobs') {
    return 'Servis İşleri'
  }

  return 'Partner Dashboard'
}

const locationLabel = (partner: PartnerSummary) => [partner.city, partner.district].filter(Boolean).join(' / ') || '-'

const portalHref = (path: string, partnerId: number) => `${path}?partner_id=${partnerId}`

function CapabilityChips({ capabilities }: { capabilities: Capability[] }) {
  return (
    <div className="flex flex-wrap gap-2">
      {capabilities.map((capability) => (
        <span key={capability} className="rounded-full border border-slate-200 bg-white px-2.5 py-1 text-xs font-semibold text-slate-700">
          {capabilityLabel(capability)}
        </span>
      ))}
    </div>
  )
}

function PortalShell({ partnerPortal }: PartnerPortalProps) {
  const { selectedPartner, stats, view } = partnerPortal
  const navItems = [
    ['/partner/dashboard', 'Dashboard'],
    ['/partner/profile', 'Profil'],
    ['/partner/orders', 'Siparişler'],
    ['/partner/stock', 'Stok'],
    ['/partner/service-jobs', 'Servis İşleri'],
  ] as const

  return (
    <div className="min-h-screen bg-slate-100 text-slate-950">
      <Head title={viewTitle(view)} />
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-4 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Emaks Prime Partner Portal</p>
            <h1 className="mt-1 text-2xl font-semibold text-slate-950">{selectedPartner.display_name}</h1>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-500">
              <span>{selectedPartner.partner_code}</span>
              <span>·</span>
              <span>{selectedPartner.mikro_cari_kodu ?? 'Cari kodu yok'}</span>
            </div>
          </div>
          <div className="flex flex-col gap-3 lg:items-end">
            <CapabilityChips capabilities={selectedPartner.capabilities} />
            <div className="flex flex-wrap gap-2">
              {partnerPortal.partners.length > 1 && (
                <select
                  className="rounded-xl border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700"
                  defaultValue={selectedPartner.id}
                  onChange={(event) => {
                    window.location.href = portalHref(window.location.pathname, Number(event.target.value))
                  }}
                >
                  {partnerPortal.partners.map((partner) => (
                    <option key={partner.id} value={partner.id}>{partner.display_name}</option>
                  ))}
                </select>
              )}
              <Link href="/logout" method="post" as="button" className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700">
                Çıkış
              </Link>
            </div>
          </div>
        </div>
        <nav className="mx-auto flex max-w-7xl gap-2 overflow-x-auto px-4 pb-4">
          {navItems.map(([href, label]) => {
            const active = href.endsWith(view)
              || (view === 'dashboard' && href.endsWith('dashboard'))
              || (view === 'service-jobs' && href.endsWith('service-jobs'))

            return (
              <Link
                key={href}
                href={portalHref(href, selectedPartner.id)}
                className={`shrink-0 rounded-xl px-3 py-2 text-sm font-semibold ${active ? 'bg-slate-950 text-white' : 'bg-white text-slate-700 hover:bg-slate-50'}`}
              >
                {label}
              </Link>
            )
          })}
        </nav>
      </header>

      <main className="mx-auto max-w-7xl px-4 py-6">
        {!partnerPortal.allowed && (
          <section className="rounded-2xl border border-amber-200 bg-amber-50 p-5 text-amber-900">
            <h2 className="text-lg font-semibold">Bu ekrana erişiminiz yok.</h2>
            <p className="mt-1 text-sm">{partnerPortal.deniedMessage ?? 'Partner yetkiniz bu ekran için yeterli değil.'}</p>
          </section>
        )}

        {partnerPortal.allowed && view === 'dashboard' && (
          <DashboardView partner={selectedPartner} stats={stats} placeholders={partnerPortal.placeholders} />
        )}
        {partnerPortal.allowed && view === 'profile' && <ProfileView partner={selectedPartner} />}
        {partnerPortal.allowed && view === 'orders' && <PlaceholderView title="Siparişlerim" message={partnerPortal.placeholders.orders} />}
        {partnerPortal.allowed && view === 'stock' && <PlaceholderView title="Stok Görünümü" message={partnerPortal.placeholders.stock} />}
        {partnerPortal.allowed && view === 'service-jobs' && <ServiceJobsView jobs={partnerPortal.serviceJobs} />}
      </main>
    </div>
  )
}

function DashboardView({ partner, stats, placeholders }: { partner: PartnerSummary, stats: PartnerPortalProps['partnerPortal']['stats'], placeholders: Record<string, string> }) {
  const cards = [
    ['Partner', partner.display_name, partner.mikro_cari_kodu ?? '-'],
    ['Kullanıcılar', `${stats.active_users_count}/${stats.users_count}`, 'Aktif / toplam'],
    ['Bağlı ustalar', String(stats.linked_technicians_count), 'Operasyonel ilişki'],
    ['Açık servis işleri', String(stats.open_service_jobs_count), 'Owner/field kapsamı'],
  ]

  return (
    <div className="grid gap-5">
      <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
        {cards.map(([label, value, hint]) => (
          <div key={label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{label}</p>
            <p className="mt-2 text-2xl font-semibold text-slate-950">{value}</p>
            <p className="mt-1 text-sm text-slate-500">{hint}</p>
          </div>
        ))}
      </section>
      <section className="grid gap-4 lg:grid-cols-2">
        <PlaceholderView title="Bayi kanalı" message={`${placeholders.orders} ${placeholders.stock}`} />
        <PlaceholderView title="Çilingir kanalı" message={placeholders.service} />
      </section>
    </div>
  )
}

function ProfileView({ partner }: { partner: PartnerSummary }) {
  return (
    <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
      <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
        <h2 className="text-lg font-semibold text-slate-950">Partner Bilgileri</h2>
        <dl className="mt-4 grid gap-3 sm:grid-cols-2">
          {[
            ['Cari kodu', partner.mikro_cari_kodu],
            ['Cari unvanı', partner.mikro_cari_unvan],
            ['Telefon', partner.phone],
            ['E-posta', partner.email],
            ['Konum', locationLabel(partner)],
            ['Açık adres', partner.address],
          ].map(([label, value]) => (
            <div key={label} className="rounded-xl bg-slate-50 px-3 py-2">
              <dt className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</dt>
              <dd className="mt-1 text-sm font-semibold text-slate-900">{value || '-'}</dd>
            </div>
          ))}
        </dl>
      </section>
      <section className="grid gap-4">
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-slate-900">Bağlı Teknik Servis Ustaları</h3>
          <div className="mt-3 grid gap-2">
            {partner.linked_technicians.length === 0 && <p className="text-sm text-slate-500">Bağlı usta yok.</p>}
            {partner.linked_technicians.map((technician) => (
              <div key={technician.id} className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm">
                <div className="font-semibold text-slate-900">{technician.name ?? '-'}</div>
                <div className="text-slate-500">{[technician.phone, technician.city, technician.district].filter(Boolean).join(' · ') || '-'}</div>
              </div>
            ))}
          </div>
        </div>
        <div className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <h3 className="text-sm font-semibold text-slate-900">Alt Cari Hesapları</h3>
          <div className="mt-3 grid gap-2">
            {partner.child_cari_accounts.length === 0 && <p className="text-sm text-slate-500">Alt cari hesabı yok.</p>}
            {partner.child_cari_accounts.map((child, index) => (
              <div key={`${child.mikro_cari_kodu ?? index}`} className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm">
                <div className="font-semibold text-slate-900">{child.mikro_cari_kodu ?? '-'}</div>
                <div className="text-slate-500">{child.usage_type ?? child.cari_usage_type ?? '-'}</div>
              </div>
            ))}
          </div>
        </div>
      </section>
    </div>
  )
}

function PlaceholderView({ title, message }: { title: string, message: string }) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
      <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Hazırlanıyor</p>
      <h2 className="mt-2 text-xl font-semibold text-slate-950">{title}</h2>
      <p className="mt-2 text-sm leading-6 text-slate-600">{message}</p>
    </section>
  )
}

function ServiceJobsView({ jobs }: { jobs: ServiceJob[] }) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <h2 className="text-lg font-semibold text-slate-950">Atanmış Servis İşleri</h2>
      <div className="mt-4 grid gap-3">
        {jobs.length === 0 && <p className="rounded-xl bg-slate-50 px-4 py-3 text-sm text-slate-500">Bu kapsamda servis işi yok.</p>}
        {jobs.map((job) => (
          <div key={job.id} className="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <p className="font-semibold text-slate-950">{job.mrn}</p>
                <p className="text-sm text-slate-500">{job.customer_name ?? '-'} · {[job.customer_city, job.customer_district].filter(Boolean).join(' / ') || '-'}</p>
              </div>
              <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700">{job.workflow_status ?? job.status ?? '-'}</span>
            </div>
          </div>
        ))}
      </div>
    </section>
  )
}

export default PortalShell
