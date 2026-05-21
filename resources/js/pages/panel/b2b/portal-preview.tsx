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

type PreviewProps = {
  preview: {
    read_only: boolean
    warning: string
    back_url: string
  }
  partnerPortal: {
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

const locationLabel = (partner: PartnerSummary) => [partner.city, partner.district].filter(Boolean).join(' / ') || '-'

function StatCard({ title, value, hint }: { title: string, value: string | number, hint: string }) {
  return (
    <div className="rounded-xl border border-slate-200 bg-white p-4 shadow-sm">
      <div className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{title}</div>
      <div className="mt-2 text-2xl font-semibold text-slate-950">{value}</div>
      <div className="mt-1 text-sm text-slate-500">{hint}</div>
    </div>
  )
}

function ReadOnlyPlaceholder({ title, message }: { title: string, message: string }) {
  return (
    <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">Salt okunur</div>
      <h2 className="mt-2 text-lg font-semibold text-slate-950">{title}</h2>
      <p className="mt-2 text-sm leading-6 text-slate-600">{message}</p>
      <button type="button" disabled className="mt-4 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-400">
        İşlem önizleme modunda kapalı
      </button>
    </section>
  )
}

export default function B2BPortalPreview({ preview, partnerPortal }: PreviewProps) {
  const partner = partnerPortal.selectedPartner
  const stats = partnerPortal.stats

  return (
    <div className="min-h-screen overflow-x-hidden bg-slate-100 text-slate-950">
      <Head title={`${partner.display_name} Portal Önizleme`} />
      <header className="border-b border-slate-200 bg-white">
        <div className="mx-auto flex max-w-7xl flex-col gap-4 px-4 py-5 lg:flex-row lg:items-center lg:justify-between">
          <div className="min-w-0">
            <p className="text-xs font-semibold uppercase tracking-[0.16em] text-slate-500">Emaks Prime Partner Portal</p>
            <h1 className="mt-1 break-words text-2xl font-semibold text-slate-950">{partner.display_name}</h1>
            <div className="mt-2 flex flex-wrap items-center gap-2 text-sm text-slate-500">
              <span>{partner.partner_code}</span>
              <span>{partner.mikro_cari_kodu ?? 'Cari kodu yok'}</span>
              <span>{locationLabel(partner)}</span>
            </div>
          </div>
          <div className="flex flex-wrap gap-2">
            {partner.capabilities.map((capability) => (
              <span key={capability} className="rounded-full border border-slate-200 bg-white px-3 py-1 text-xs font-semibold text-slate-700">
                {capabilityLabel(capability)}
              </span>
            ))}
            <Link href={preview.back_url} className="rounded-xl bg-slate-950 px-3 py-2 text-sm font-semibold text-white">
              Partner yönetimine dön
            </Link>
          </div>
        </div>
      </header>

      <main className="mx-auto grid max-w-7xl gap-5 px-4 py-6">
        <section className="rounded-2xl border border-amber-200 bg-amber-50 p-4 text-sm font-semibold text-amber-900">
          {preview.warning}
        </section>

        <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
          <StatCard title="Kullanıcılar" value={`${stats.active_users_count}/${stats.users_count}`} hint="Aktif / toplam portal profili" />
          <StatCard title="Bağlı ustalar" value={stats.linked_technicians_count} hint="Operasyonel usta bağlantısı" />
          <StatCard title="Açık servis işleri" value={stats.open_service_jobs_count} hint="Owner/field usta kapsamı" />
          <StatCard title="Cari kodu" value={partner.mikro_cari_kodu ?? '-'} hint={partner.mikro_cari_unvan ?? 'Mikro snapshot'} />
        </section>

        <section className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_380px]">
          <div className="grid gap-5">
            <ReadOnlyPlaceholder title="Siparişlerim" message={partnerPortal.placeholders.orders} />
            <ReadOnlyPlaceholder title="Stok Görünümü" message={partnerPortal.placeholders.stock} />
            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <h2 className="text-lg font-semibold text-slate-950">Servis işleri önizleme</h2>
              <p className="mt-1 text-sm text-slate-500">{partnerPortal.placeholders.service}</p>
              <div className="mt-4 grid gap-3">
                {partnerPortal.serviceJobs.length === 0 && (
                  <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-6 text-sm text-slate-500">
                    Bu partner kapsamında servis işi yok.
                  </div>
                )}
                {partnerPortal.serviceJobs.map((job) => (
                  <article key={job.id} className="rounded-xl border border-slate-100 bg-slate-50 px-4 py-3">
                    <div className="flex flex-wrap items-center justify-between gap-3">
                      <div>
                        <div className="font-semibold text-slate-950">{job.mrn}</div>
                        <div className="text-sm text-slate-500">{job.customer_name ?? '-'} · {[job.customer_city, job.customer_district].filter(Boolean).join(' / ') || '-'}</div>
                      </div>
                      <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-slate-700">{job.workflow_status ?? job.status ?? '-'}</span>
                    </div>
                  </article>
                ))}
              </div>
            </section>
          </div>

          <aside className="grid gap-5 lg:sticky lg:top-4 lg:self-start">
            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <h2 className="text-lg font-semibold text-slate-950">Partner profili</h2>
              <dl className="mt-4 grid gap-3">
                {[
                  ['Telefon', partner.phone],
                  ['E-posta', partner.email],
                  ['Konum', locationLabel(partner)],
                  ['Açık adres', partner.address],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-xl bg-slate-50 px-3 py-2">
                    <dt className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</dt>
                    <dd className="mt-1 break-words text-sm font-semibold text-slate-900">{value || '-'}</dd>
                  </div>
                ))}
              </dl>
            </section>

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <h2 className="text-sm font-semibold text-slate-950">Bağlı Teknik Servis Ustaları</h2>
              <div className="mt-3 grid gap-2">
                {partner.linked_technicians.length === 0 && <p className="text-sm text-slate-500">Bağlı usta yok.</p>}
                {partner.linked_technicians.map((technician) => (
                  <div key={technician.id} className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm">
                    <div className="font-semibold text-slate-900">{technician.name ?? '-'}</div>
                    <div className="text-slate-500">{[technician.phone, technician.city, technician.district].filter(Boolean).join(' · ') || '-'}</div>
                  </div>
                ))}
              </div>
            </section>

            <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <h2 className="text-sm font-semibold text-slate-950">Alt Cari Hesapları</h2>
              <div className="mt-3 grid gap-2">
                {partner.child_cari_accounts.length === 0 && <p className="text-sm text-slate-500">Alt cari hesabı yok.</p>}
                {partner.child_cari_accounts.map((child, index) => (
                  <div key={`${child.mikro_cari_kodu ?? index}`} className="rounded-xl border border-slate-100 bg-slate-50 px-3 py-2 text-sm">
                    <div className="font-semibold text-slate-900">{child.mikro_cari_kodu ?? '-'}</div>
                    <div className="text-slate-500">{child.usage_type ?? child.cari_usage_type ?? '-'}</div>
                  </div>
                ))}
              </div>
            </section>
          </aside>
        </section>
      </main>
    </div>
  )
}
