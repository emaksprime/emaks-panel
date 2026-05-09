import { Head } from '@inertiajs/react'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'

const adminItems = [
  ['Ekran Yetkileri', 'Teknik servis ekranları ayrı panel kaynakları üzerinden Admin > Kullanıcı Yönetimi içinde atanır.'],
  ['Aksiyon Yetkileri', 'Talep yönetimi, hakediş ödeme ve teknisyen yönetimi ayrı API kaynak kontrolleriyle korunur.'],
  ['Mikro Sorguları', 'Seri no ve garanti sorguları teknik servis erişim yetkileri üzerinden sınırlandırılır.'],
]

export default function TechnicalServiceAdmin() {
  return (
    <>
      <Head title="Teknik Servis Admin" />

      <div className="relative min-h-screen overflow-hidden bg-[#eaf1f8]">
        <div className="pointer-events-none absolute inset-x-0 top-0 h-80 bg-[radial-gradient(circle_at_top_left,_rgba(15,23,42,0.14),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(37,99,235,0.12),_transparent_34%)]" />
        <div className="relative mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 md:px-6 lg:px-10">
          <section className="relative overflow-hidden rounded-[28px] border border-white/80 bg-white/92 px-5 py-5 shadow-[0_18px_45px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 backdrop-blur sm:px-6 sm:py-6">
            <div className="absolute inset-x-0 top-0 h-1.5 bg-slate-950" />
            <div className="max-w-3xl">
              <p className="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">TEKNİK SERVİS YÖNETİMİ</p>
              <h1 className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">Teknik Servis Admin</h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                Yetki, erişim ve servis yönetim ayarlarını takip edin.
              </p>
            </div>
          </section>

          <TechnicalServicePageLinks />

          <section className="grid gap-4 md:grid-cols-3">
            {adminItems.map(([title, description]) => (
              <article key={title} className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm shadow-slate-200/70">
                <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">Yönetim</p>
                <p className="mt-3 text-lg font-semibold text-slate-950">{title}</p>
                <p className="mt-2 text-sm leading-6 text-slate-600">{description}</p>
              </article>
            ))}
          </section>

          <section className="grid gap-4 xl:grid-cols-[1.2fr_0.8fr]">
            <article className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm shadow-slate-200/70">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Yetki Merkezi</p>
              <h2 className="mt-3 text-2xl font-semibold tracking-tight text-slate-950">
                Teknik servis erişimleri kullanıcı bazında atanır
              </h2>
              <p className="mt-3 max-w-3xl text-sm leading-7 text-slate-600">
                Bu ekran yalnızca ilgili yönetim yetkilerine sahip kullanıcılar için görünür. Kullanıcı ekran erişimleri,
                hakediş ödeme aksiyonları ve teknisyen yönetimi ayrı kaynaklar halinde yönetilir.
              </p>
            </article>

            <article className="rounded-2xl border border-slate-200/80 bg-white p-6 shadow-sm shadow-slate-200/70">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Operasyon Notu</p>
              <p className="mt-3 text-lg font-semibold text-slate-950">Yetki kurgusu ürün yüzeyinden ayrı tutulur</p>
              <p className="mt-2 text-sm leading-6 text-slate-600">
                Bu sayfa yeni ayar üretmez; mevcut yönetim başlıklarını daha okunabilir, daha kontrollü bir idari özet halinde sunar.
              </p>
            </article>
          </section>
        </div>
      </div>
    </>
  )
}

