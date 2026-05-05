import { Head } from '@inertiajs/react'
import Heading from '@/components/heading'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'

const adminItems = [
  ['Ekran yetkileri', 'Teknik servis ekranları ayrı panel resource kodlarıyla Admin > Kullanıcı Yönetimi üzerinden atanır.'],
  ['Aksiyon yetkileri', 'Talep yönetimi, hakediş ödeme ve teknisyen yönetimi ayrı API resource kontrolleriyle korunur.'],
  ['Mikro sorguları', 'Seri no ve garanti sorguları technical_service_serial_query yetkisine bağlıdır.'],
]

export default function TechnicalServiceAdmin() {
  return (
    <>
      <Head title="Teknik Servis Admin" />

      <div className="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 md:px-6 lg:px-12">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="Teknik Servis Admin"
            description="Teknik servis modül yetkileri, yönetim ekranları ve güvenli aksiyon ayrımları."
          />
          <div className="flex flex-wrap gap-2">
            <TechnicalServicePageLinks />
          </div>
        </div>

        <section className="grid gap-4 md:grid-cols-3">
          {adminItems.map(([title, description]) => (
            <article key={title} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
              <p className="text-sm font-semibold text-slate-950">{title}</p>
              <p className="mt-2 text-sm leading-6 text-slate-600">{description}</p>
            </article>
          ))}
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
          <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
            Yetki merkezi
          </p>
          <h2 className="mt-2 text-lg font-bold text-slate-950">
            Teknik servis erişimleri kullanıcı bazında atanır.
          </h2>
          <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
            Bu ekran sadece technical_service_admin yetkisi olan kullanıcılar için görünür. Kullanıcı ekran erişimleri,
            hakediş ödeme aksiyonu ve teknisyen yönetimi {'Admin > Kullanıcı Yönetimi'} içinde ayrı kaynaklar olarak
            seçilebilir.
          </p>
        </section>
      </div>
    </>
  )
}
