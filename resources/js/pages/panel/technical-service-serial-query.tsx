import { Head } from '@inertiajs/react'
import { useState } from 'react'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'
import type { MikroMountCheckResult, MikroSerialHistoryEvent, MikroSerialHistoryResponse, WarrantySerialResponse } from '@/components/technical-service/types'
import { formatTechnicalServiceDate } from '@/components/technical-service/utils'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

const formatDate = (value: string | null | undefined): string => formatTechnicalServiceDate(value)

const statusClassName = (decision: MikroMountCheckResult | null | undefined): string => {
  switch (decision?.montaj_durumu) {
    case 'Montaj Dahil':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Montaj Sonradan Dahil':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'Montaj Hariç':
    case 'Montaj HariÃ§':
    case 'Montaj HariÃƒÂ§':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const eventBadgeClassName = (event: MikroSerialHistoryEvent): string => {
  if (event.is_latest_valid_sale) {
    return 'border-emerald-200 bg-emerald-50 text-emerald-700'
  }

  switch (event.event_type) {
    case 'satış':
    case 'satÄ±ÅŸ':
    case 'satÃ„Â±Ã…Å¸':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'iade':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'sonradan_montaj':
      return 'border-slate-200 bg-slate-100 text-slate-700'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const warrantyStatusClassName = (status: WarrantySerialResponse['status'] | null | undefined): string => {
  switch (status) {
    case 'Garanti Aktif':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Garanti Başlamadı':
    case 'Garanti Başlamadı':
    case 'Garanti BaÃ…Å¸lamadÃ„Â±':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    case 'Garanti Bitti':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'Değişimle Kapandı':
    case 'DeÄŸişimle KapandÄ±':
    case 'DeÃ„Å¸iÃ…Å¸imle KapandÃ„Â±':
    case 'Yeni SNâ€™ye Devredildi':
    case 'Yeni SNÃ¢â‚¬â„¢ye Devredildi':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'Yeniden Satış Bekliyor':
    case 'Yeniden SatÄ±ÅŸ Bekliyor':
    case 'Yeniden SatÃ„Â±Ã…Å¸ Bekliyor':
      return 'border-slate-300 bg-slate-100 text-slate-700'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const formatSonradanMontaj = (decision: MikroMountCheckResult): string => {
  const sourceLine = [
    decision.sonradan_montaj_kaynagi,
    decision.sonradan_montaj_tarihi ? formatDate(decision.sonradan_montaj_tarihi) : null,
  ].filter(Boolean).join(' / ')
  const cariLine = [decision.sonradan_montaj_cari_kodu, decision.sonradan_montaj_cari_unvani].filter(Boolean).join(' - ')

  return [sourceLine, cariLine].filter(Boolean).join('\n')
}

export default function TechnicalServiceSerialQuery() {
  const [serialNo, setSerialNo] = useState('')
  const [result, setResult] = useState<MikroSerialHistoryResponse | null>(null)
  const [warranty, setWarranty] = useState<WarrantySerialResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [warrantyError, setWarrantyError] = useState<string | null>(null)

  const runQuery = async () => {
    const cleanedSerialNo = serialNo.trim()

    if (!cleanedSerialNo) {
      setError('Seri no girin.')
      setResult(null)
      setWarranty(null)
      setWarrantyError(null)
      return
    }

    setLoading(true)
    setError(null)
    setWarrantyError(null)

    try {
      const params = new URLSearchParams({ serial_no: cleanedSerialNo })
      const [historyResponse, warrantyResponse] = await Promise.allSettled([
        apiRequest(`/api/technical-service/mikro/serial-history?${params.toString()}`),
        apiRequest(`/api/technical-service/warranty/serial?${params.toString()}`),
      ])

      if (historyResponse.status === 'fulfilled') {
        setResult(historyResponse.value as MikroSerialHistoryResponse)
      } else {
        throw historyResponse.reason
      }

      if (warrantyResponse.status === 'fulfilled') {
        setWarranty(warrantyResponse.value as WarrantySerialResponse)
      } else {
        setWarranty(null)
        setWarrantyError(warrantyResponse.reason instanceof Error ? warrantyResponse.reason.message : 'Garanti bilgisi alınamadı.')
      }
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Seri no sorgusu yapılamadı.')
      setResult(null)
      setWarranty(null)
    } finally {
      setLoading(false)
    }
  }

  const decision = result?.decision ?? null

  return (
    <>
      <Head title="Seri No Sorgu" />

      <div className="relative min-h-screen overflow-hidden bg-[#eaf1f8]">
        <div className="pointer-events-none absolute inset-x-0 top-0 h-80 bg-[radial-gradient(circle_at_top_left,_rgba(15,23,42,0.14),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(37,99,235,0.12),_transparent_34%)]" />
        <div className="relative mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 md:px-6 lg:px-10">
          <section className="relative overflow-hidden rounded-[28px] border border-white/80 bg-white/92 px-5 py-5 shadow-[0_18px_45px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 backdrop-blur sm:px-6 sm:py-6">
            <div className="absolute inset-x-0 top-0 h-1.5 bg-slate-950" />
            <div className="max-w-3xl">
              <p className="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">CİHAZ SORGULAMA</p>
              <h1 className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">Seri No Sorgu</h1>
              <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                Seri no, müşteri, telefon ve aktivasyon bilgilerini hızlıca sorgulayın.
              </p>
            </div>
          </section>

          <TechnicalServicePageLinks />

          <section className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/70">
            <div className="grid gap-5 lg:grid-cols-[minmax(0,1fr)_280px] lg:items-end">
              <div className="grid gap-4">
                <div>
                  <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Hızlı Sorgu</p>
                  <p className="mt-1 text-sm text-slate-600">Seri numarası üzerinden montaj ve garanti akışını tek ekranda görün.</p>
                </div>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Seri No
                  <Input
                    value={serialNo}
                    onChange={(event) => setSerialNo(event.target.value)}
                    onKeyDown={(event) => {
                      if (event.key === 'Enter') {
                        void runQuery()
                      }
                    }}
                    placeholder="Cihaz seri numarası"
                    className="h-12 border-slate-200 bg-slate-50"
                  />
                </label>
              </div>
              <Button type="button" onClick={() => void runQuery()} disabled={loading} className="h-12 rounded-xl bg-slate-950 px-6 text-white hover:bg-slate-900">
                {loading ? 'Sorgulanıyor...' : 'Seri No Sorgula'}
              </Button>
            </div>

            {error ? (
              <div className="mt-4 rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                {error}
              </div>
            ) : null}
          </section>

          {decision ? (
            <section className="grid gap-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/70">
              <div className="flex flex-wrap items-center gap-2">
                <Badge variant="outline" className={statusClassName(decision)}>
                  {decision.found ? decision.montaj_durumu.replace('Montaj HariÃƒÂ§', 'Montaj Hariç').replace('Montaj HariÃ§', 'Montaj Hariç') : 'Seri no bulunamadı'}
                </Badge>
                {decision.farkli_cari_uyarisi ? (
                  <Badge variant="outline" className="border-rose-200 bg-rose-50 text-rose-700">
                    Farklı cari ile sonradan montaj
                  </Badge>
                ) : null}
              </div>

              <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                {[
                  ['Montaj Durumu', decision.montaj_durumu.replace('Montaj HariÃƒÂ§', 'Montaj Hariç').replace('Montaj HariÃ§', 'Montaj Hariç')],
                  ['Cihaz Seri No', decision.cihaz_seri_no],
                  ['Stok Adı', decision.stok_adi],
                  ['Son Satış Tarihi', formatDate(decision.irsaliye_tarihi)],
                  ['Evrak Seri No', [decision.irsaliye_seri, decision.irsaliye_sira].filter(Boolean).join(' / ')],
                  ['Ürünü Satın Alan Müşteri', [decision.asil_cari_kodu, decision.asil_cari_unvani].filter(Boolean).join(' - ')],
                  ['Fatura', [formatDate(decision.fatura_tarihi), decision.fatura_seri, decision.fatura_sira].filter(Boolean).join(' / ')],
                  ['Sonradan Montaj', formatSonradanMontaj(decision)],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                    <p className="mt-2 whitespace-pre-wrap break-words text-sm font-medium text-slate-900">{value || '-'}</p>
                  </div>
                ))}
              </div>
            </section>
          ) : null}

          {warranty || warrantyError ? (
            <section className="grid gap-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/70">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <h2 className="text-lg font-semibold text-slate-950">Garanti Bilgisi</h2>
                  <p className="mt-1 text-sm text-slate-500">Garanti kararı son geçerli satış ve panel kayıtları üzerinden değerlendirilir.</p>
                </div>
                {warranty ? (
                  <Badge variant="outline" className={warrantyStatusClassName(warranty.status)}>
                    {warranty.status
                      .replace('Garanti BaÃ…Å¸lamadÃ„Â±', 'Garanti Başlamadı')
                      .replace('Garanti Başlamadı', 'Garanti Başlamadı')
                      .replace('DeÃ„Å¸iÃ…Å¸imle KapandÃ„Â±', 'Değişimle Kapandı')
                      .replace('DeÄŸişimle KapandÄ±', 'Değişimle Kapandı')
                      .replace('Yeni SNÃ¢â‚¬â„¢ye Devredildi', 'Yeni SNâ€™ye Devredildi')
                      .replace('Yeniden SatÃ„Â±Ã…Å¸ Bekliyor', 'Yeniden Satış Bekliyor')
                      .replace('Yeniden SatÄ±ÅŸ Bekliyor', 'Yeniden Satış Bekliyor')}
                  </Badge>
                ) : null}
              </div>

              {warrantyError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {warrantyError}
                </div>
              ) : null}

              {warranty ? (
                <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-4">
                  {[
                    ['Garanti Başlangıcı', formatDate(warranty.warranty_started_at)],
                    ['Garanti Bitişi', formatDate(warranty.warranty_ends_at)],
                    ['Kalan Gün', warranty.remaining_days === null || warranty.remaining_days === undefined ? '-' : String(warranty.remaining_days)],
                    ['Garanti Süresi', `${warranty.warranty_period_months} ay`],
                    ['Montaj Tamamlanma', formatDate(warranty.installation.completed_at)],
                    ['Son Satış Tarihi', formatDate(warranty.last_sale?.date)],
                    ['Son Satış Cari', [warranty.last_sale?.customer_code, warranty.last_sale?.customer_name].filter(Boolean).join(' - ')],
                    ['Son Satış Evrakı', warranty.last_sale?.document_no],
                  ].map(([label, value]) => (
                    <div key={label} className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                      <p className="mt-2 whitespace-pre-wrap break-words text-slate-900">{value || '-'}</p>
                    </div>
                  ))}
                </div>
              ) : null}

              {warranty?.warnings.length ? (
                <div className="grid gap-2">
                  {warranty.warnings.map((warning) => (
                    <div key={warning} className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                      {warning}
                    </div>
                  ))}
                </div>
              ) : null}
            </section>
          ) : null}

          {result ? (
            <section className="grid gap-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/70">
              <div>
                <h2 className="text-lg font-semibold text-slate-950">Cihaz Hareket Geçmişi</h2>
                <p className="mt-1 text-sm text-slate-500">Satış, iade ve sonradan montaj hareketleri kronolojik olarak listelenir.</p>
              </div>

              {result.items.length === 0 ? (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                  Mikro tarafında seri no kaydı bulunamadı.
                </div>
              ) : (
                <div className="grid gap-3">
                  {result.items.map((event, index) => (
                    <div
                      key={`${event.event_type}-${event.event_date ?? 'date'}-${index}`}
                      className={[
                        'grid gap-3 rounded-2xl border p-4 text-sm',
                        event.is_latest_valid_sale ? 'border-emerald-300 bg-emerald-50' : 'border-slate-200 bg-slate-50',
                      ].join(' ')}
                    >
                      <div className="flex flex-wrap items-center justify-between gap-2">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge variant="outline" className={eventBadgeClassName(event)}>
                            {event.is_latest_valid_sale ? 'En Son Geçerli Satış' : event.title}
                          </Badge>
                          <span className="font-semibold text-slate-900">{formatDate(event.event_date)}</span>
                        </div>
                        <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                          {event.event_type.replace('satÃ„Â±Ã…Å¸', 'satış').replace('satÄ±ÅŸ', 'satış')}
                        </span>
                      </div>

                      <div className="grid gap-2 text-slate-700 sm:grid-cols-2 lg:grid-cols-4">
                        <span>Stok: {event.stok_adi || '-'}</span>
                        <span>Cari: {[event.cari_kodu, event.cari_unvani].filter(Boolean).join(' - ') || '-'}</span>
                        <span>Evrak: {[event.evrak_seri, event.evrak_sira].filter(Boolean).join(' / ') || '-'}</span>
                        <span>Sipariş: {[event.siparis_seri, event.siparis_sira].filter(Boolean).join(' / ') || '-'}</span>
                        <span>Fatura: {[event.fatura_seri, event.fatura_sira].filter(Boolean).join(' / ') || '-'}</span>
                        <span>Hareket Grup Kodu 1: {event.hareket_grup_kodu_1 || '-'}</span>
                        <span>Sorumluluk Kodu: {event.sorumluluk_kodu || '-'}</span>
                      </div>

                      {event.description ? (
                        <p className="whitespace-pre-wrap break-words text-slate-600">{event.description}</p>
                      ) : null}
                    </div>
                  ))}
                </div>
              )}
            </section>
          ) : null}
        </div>
      </div>
    </>
  )
}

