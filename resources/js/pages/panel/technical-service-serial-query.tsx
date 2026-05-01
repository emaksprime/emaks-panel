import { Head, Link } from '@inertiajs/react'
import { useState } from 'react'
import Heading from '@/components/heading'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'
import type { MikroMountCheckResult, MikroSerialHistoryEvent, MikroSerialHistoryResponse } from '@/components/technical-service/types'

const formatDate = (value: string | null | undefined): string => {
  if (!value) {
    return '-'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return value
  }

  return date.toLocaleDateString('tr-TR')
}

const statusClassName = (decision: MikroMountCheckResult | null | undefined): string => {
  switch (decision?.montaj_durumu) {
    case 'Montaj Dahil':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Montaj Sonradan Dahil':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'Montaj Hariç':
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
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'iade':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'sonradan_montaj':
      return 'border-violet-200 bg-violet-50 text-violet-700'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

export default function TechnicalServiceSerialQuery() {
  const [serialNo, setSerialNo] = useState('')
  const [result, setResult] = useState<MikroSerialHistoryResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)

  const runQuery = async () => {
    const cleanedSerialNo = serialNo.trim()

    if (!cleanedSerialNo) {
      setError('Seri no girin.')
      setResult(null)
      return
    }

    setLoading(true)
    setError(null)

    try {
      const params = new URLSearchParams({ serial_no: cleanedSerialNo })
      const response = await apiRequest(`/api/technical-service/mikro/serial-history?${params.toString()}`)
      setResult(response as MikroSerialHistoryResponse)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Seri no sorgusu yapılamadı.')
      setResult(null)
    } finally {
      setLoading(false)
    }
  }

  const decision = result?.decision ?? null

  return (
    <>
      <Head title="Seri No Sorgu" />

      <div className="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 md:px-6 lg:px-12">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="Seri No Sorgu"
            description="Mikro geçmişini seri no üzerinden okuyun ve montaj kararını son geçerli satışa göre görün."
          />
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="secondary">
              <Link href="/technical-service">Teknik Servis</Link>
            </Button>
            <Button asChild variant="secondary">
              <Link href="/technical-service/technicians">Ustalar / Çilingirler</Link>
            </Button>
          </div>
        </div>

        <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Seri no
              <Input
                value={serialNo}
                onChange={(event) => setSerialNo(event.target.value)}
                onKeyDown={(event) => {
                  if (event.key === 'Enter') {
                    void runQuery()
                  }
                }}
                placeholder="Cihaz seri no"
              />
            </label>
            <Button type="button" onClick={() => void runQuery()} disabled={loading}>
              {loading ? 'Sorgulanıyor...' : 'Mikro’dan sorgula'}
            </Button>
          </div>

          {error ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
              {error}
            </div>
          ) : null}
        </section>

        {decision ? (
          <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-wrap items-center gap-2">
              <Badge variant="outline" className={statusClassName(decision)}>
                {decision.found ? decision.montaj_durumu : 'Mikro’da seri no bulunamadı'}
              </Badge>
              {decision.farkli_cari_uyarisi ? (
                <Badge variant="outline" className="border-rose-200 bg-rose-50 text-rose-700">
                  Farklı Cari ile Sonradan Montaj
                </Badge>
              ) : null}
            </div>

            <p className="text-sm leading-6 text-slate-700">
              {decision.found ? decision.montaj_ek_aciklama : 'Mikro’da seri no bulunamadı'}
            </p>

            {decision.farkli_cari_uyarisi ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                Sonradan montaj carisi asıl satış carisinden farklı. Teknik servis kararında cari bilgisini ayrıca kontrol edin.
              </div>
            ) : null}

            {decision.found ? (
              <div className="grid gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                {[
                  ['Seri No', decision.cihaz_seri_no],
                  ['Stok', decision.stok_adi],
                  ['Asıl Cari', [decision.asil_cari_kodu, decision.asil_cari_unvani].filter(Boolean).join(' - ')],
                  ['İrsaliye', [formatDate(decision.irsaliye_tarihi), decision.irsaliye_seri, decision.irsaliye_sira].filter(Boolean).join(' / ')],
                  ['Fatura', [formatDate(decision.fatura_tarihi), decision.fatura_seri, decision.fatura_sira].filter(Boolean).join(' / ')],
                  ['Sipariş', [formatDate(decision.siparis_tarihi), decision.siparis_seri, decision.siparis_sira].filter(Boolean).join(' / ')],
                  ['Sonradan Montaj', [decision.sonradan_montaj_kaynagi, formatDate(decision.sonradan_montaj_tarihi)].filter(Boolean).join(' / ')],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                    <p className="mt-2 break-words text-slate-900">{value || '-'}</p>
                  </div>
                ))}
              </div>
            ) : null}
          </section>
        ) : null}

        {result ? (
          <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div>
              <h2 className="text-lg font-semibold text-slate-950">Mikro Geçmişi</h2>
              <p className="mt-1 text-sm text-slate-500">Satış, iade, tekrar satış, fatura, sipariş ve sonradan montaj hareketleri kronolojik gösterilir.</p>
            </div>

            {result.items.length === 0 ? (
              <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
                Mikro’da seri no bulunamadı.
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
                          {event.is_latest_valid_sale ? 'En son geçerli çıkış/satış' : event.title}
                        </Badge>
                        <span className="font-semibold text-slate-900">{formatDate(event.event_date)}</span>
                      </div>
                      <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        {event.event_type}
                      </span>
                    </div>

                    <div className="grid gap-2 text-slate-700 sm:grid-cols-2 lg:grid-cols-4">
                      <span>Stok: {event.stok_adi || '-'}</span>
                      <span>Cari: {[event.cari_kodu, event.cari_unvani].filter(Boolean).join(' - ') || '-'}</span>
                      <span>Evrak: {[event.evrak_seri, event.evrak_sira].filter(Boolean).join(' / ') || '-'}</span>
                      <span>Sipariş: {[event.siparis_seri, event.siparis_sira].filter(Boolean).join(' / ') || '-'}</span>
                      <span>Fatura: {[event.fatura_seri, event.fatura_sira].filter(Boolean).join(' / ') || '-'}</span>
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
    </>
  )
}
