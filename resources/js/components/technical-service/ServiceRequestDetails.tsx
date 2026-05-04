import { Link } from '@inertiajs/react'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { formatTechnicalServiceDate, formatTechnicalServiceDateTime, getServicePaymentInfo } from './utils'
import type { MikroMountCheckResult, ServiceRequest, ServiceRequestEvent, WarrantySerialResponse } from './types'

const statusVariant = (status: ServiceRequest['status']) => {
  switch (status) {
    case 'Yeni':
      return 'secondary'
    case 'Atandı':
      return 'default'
    case 'Randevulu':
      return 'warning'
    case 'Devam Ediyor':
      return 'accent'
    case 'Tamamlandı':
      return 'positive'
    case 'İptal':
      return 'destructive'
    default:
      return 'default'
  }
}

const priorityVariant = (priority: ServiceRequest['priority']) => {
  switch (priority) {
    case 'Kritik':
      return 'destructive'
    case 'Yüksek':
      return 'warning'
    case 'Orta':
      return 'default'
    default:
      return 'secondary'
  }
}

type ServiceRequestDetailsProps = {
  request: ServiceRequest
  displayMrn?: string
  events: ServiceRequestEvent[]
  loading: boolean
  error?: string | null
  mikroMountCheck?: MikroMountCheckResult | null
  mikroMountLoading?: boolean
  mikroMountError?: string | null
  warranty?: WarrantySerialResponse | null
  warrantyLoading?: boolean
  warrantyError?: string | null
  onAssign?: () => void
  onComplete?: () => void
  onReopen?: () => void
}

const eventTime = (timestamp: string): string => {
  return formatTechnicalServiceDateTime(timestamp, 'Bilinmiyor')
}

const formatOptionalDate = (value: string | null | undefined): string => {
  return formatTechnicalServiceDate(value)
}

const formatDocument = (...parts: Array<string | null | undefined>): string => parts.filter(Boolean).join(' / ')

const paymentBadgeLabel = (result: MikroMountCheckResult | null | undefined): string => {
  switch (result?.montaj_durumu) {
    case 'Montaj Dahil':
      return 'Montaj Ödemesi Alınmış'
    case 'Montaj Sonradan Dahil':
      return 'Montaj Ödemesi Sonradan Alınmış'
    case 'Montaj Hariç':
      return 'Montaj Ödemesi Alınmamış'
    default:
      return 'Kontrol Edilemedi'
  }
}

const mikroStatusClasses = (result: MikroMountCheckResult | null | undefined): string => {
  switch (result?.montaj_durumu) {
    case 'Montaj Dahil':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Montaj Sonradan Dahil':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Montaj Hariç':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

const warrantyStatusClasses = (status: WarrantySerialResponse['status'] | null | undefined): string => {
  switch (status) {
    case 'Garanti Aktif':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Garanti Başlamadı':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    case 'Garanti Bitti':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'Değişimle Kapandı':
    case 'Yeni SN’ye Devredildi':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'Yeniden Satış Bekliyor':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    default:
      return 'border-slate-200 bg-slate-100 text-slate-700'
  }
}

export function ServiceRequestDetails({
  request,
  displayMrn,
  events,
  loading,
  error,
  mikroMountCheck,
  mikroMountLoading = false,
  mikroMountError = null,
  warranty = null,
  warrantyLoading = false,
  warrantyError = null,
  onAssign,
  onComplete,
  onReopen,
}: ServiceRequestDetailsProps) {
  const paymentInfo = getServicePaymentInfo(
    request.serviceType,
    request.travelRoundTripKm,
    request.travelFeeAmount,
    request.travelBillableKm,
  )
  const isActionDisabled = request.status === 'Tamamlandı' || request.status === 'İptal'
  const disabledTitle = 'Tamamlanan veya iptal edilen taleplerde işlem yapılamaz'
  const isReopenVisible = isActionDisabled
  const hasSerialNumber = request.serialNumber.trim() !== ''
  const serialQueryHref = hasSerialNumber
    ? `/technical-service/serial-query?serial_no=${encodeURIComponent(request.serialNumber.trim())}`
    : '/technical-service/serial-query'

  return (
    <Card className="rounded-3xl border-slate-200 bg-white shadow-sm break-words min-w-0">
      <CardHeader className="space-y-3 px-6 py-4 sm:py-6 min-w-0">
        <div className="flex flex-col gap-3 sm:flex-row sm:items-end sm:justify-between">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.15em] text-slate-500">Seçili MRN</p>
            <CardTitle className="mt-2 text-lg sm:text-xl text-slate-950">{displayMrn ?? request.mrn}</CardTitle>
          </div>
          <div className="flex flex-wrap items-center gap-2">
            <Badge variant={statusVariant(request.status)}>{request.status}</Badge>
            <Badge variant={priorityVariant(request.priority)}>{request.priority}</Badge>
          </div>
        </div>
        <p className="text-sm text-slate-600">{request.customer} için servis, randevu ve maliyet detaylarını takip edin.</p>
      </CardHeader>

      <CardContent className="space-y-6 px-6 pb-6">
        <section className="grid gap-4 sm:grid-cols-2">
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Müşteri</p>
            <p className="mt-3 text-sm font-semibold text-slate-900">{request.customer}</p>
            <p className="mt-1 text-sm text-slate-600">{request.phone}</p>
          </div>
          <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 break-words">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Adres</p>
            <p className="mt-3 text-sm text-slate-900 break-words">{request.address}</p>
            <p className="mt-2 text-sm text-slate-600 break-words">{request.city} / {request.district}</p>
          </div>
        </section>

        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Cihaz / Seri No</p>
          <div className="grid gap-2 text-sm text-slate-700">
            <div className="flex justify-between">
              <span className="font-semibold">Ürün</span>
              <span>{request.product}</span>
            </div>
            <div className="flex justify-between">
              <span className="font-semibold">Model</span>
              <span>{request.model}</span>
            </div>
            <div className="flex justify-between">
              <span className="font-semibold">Seri No</span>
              <span>{request.serialNumber}</span>
            </div>
          </div>
        </section>

        <section className="grid gap-4 lg:grid-cols-2">
          {!hasSerialNumber ? (
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600 lg:col-span-2">
              Seri no olmadığı için montaj ve garanti sorgulanamaz.
            </div>
          ) : null}

          <div className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Montaj Ödemesi / Montaj Durumu</p>
                <p className="mt-2 text-sm text-slate-600">Mikro seri no geçmişindeki son geçerli satış ve montaj sinyali.</p>
              </div>
              <Link className="text-sm font-semibold text-blue-700 hover:text-blue-900" href={serialQueryHref}>
                Seri No Sorgu ekranında aç
              </Link>
            </div>

            {mikroMountLoading ? (
              <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">Montaj bilgisi sorgulanıyor...</div>
            ) : null}

            {mikroMountError ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{mikroMountError}</div>
            ) : null}

            <div className="flex flex-wrap items-center gap-2">
              <Badge variant="outline" className={mikroStatusClasses(mikroMountCheck)}>
                {mikroMountCheck?.found ? paymentBadgeLabel(mikroMountCheck) : 'Kontrol Edilemedi'}
              </Badge>
              {mikroMountCheck?.farkli_cari_uyarisi ? (
                <Badge variant="outline" className="border-amber-200 bg-amber-50 text-amber-800">
                  Farklı Cari ile Sonradan Montaj
                </Badge>
              ) : null}
            </div>

            {mikroMountCheck ? (
              <div className="grid gap-3 text-sm sm:grid-cols-2">
                {[
                  ['Montaj Durumu', mikroMountCheck.found ? mikroMountCheck.montaj_durumu : 'Mikro’da seri no bulunamadı'],
                  ['Montaj Ek Açıklama', mikroMountCheck.found ? mikroMountCheck.montaj_ek_aciklama : 'Mikro’da seri no bulunamadı'],
                  ['Sonradan Montaj Kaynağı', mikroMountCheck.sonradan_montaj_kaynagi],
                  ['Sonradan Montaj Tarihi', formatOptionalDate(mikroMountCheck.sonradan_montaj_tarihi)],
                  ['Sonradan Montaj Cari Kodu', mikroMountCheck.sonradan_montaj_cari_kodu],
                  ['Sonradan Montaj Cari Ünvanı', mikroMountCheck.sonradan_montaj_cari_unvani],
                  ['Son Geçerli Satış Cari', [mikroMountCheck.asil_cari_kodu, mikroMountCheck.asil_cari_unvani].filter(Boolean).join(' - ')],
                  ['Son Geçerli Satış Evrakı', formatDocument(mikroMountCheck.irsaliye_seri, mikroMountCheck.irsaliye_sira)],
                  ['Son Geçerli Satış Tarihi', formatOptionalDate(mikroMountCheck.irsaliye_tarihi)],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                    <p className="mt-2 whitespace-pre-wrap break-words text-slate-900">{value || '-'}</p>
                  </div>
                ))}
              </div>
            ) : null}

            {mikroMountCheck?.farkli_cari_uyarisi ? (
              <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                Farklı cari ile sonradan montaj uyarısı var. Sonradan montaj carisi son geçerli satış carisinden farklı görünüyor.
              </div>
            ) : null}
          </div>

          <div className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-4">
            <div className="flex flex-wrap items-start justify-between gap-3">
              <div>
                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Garanti Durumu</p>
                <p className="mt-2 text-sm text-slate-600">Panel garanti kartı ve son geçerli Mikro satış bilgisi.</p>
              </div>
              <Link className="text-sm font-semibold text-blue-700 hover:text-blue-900" href={serialQueryHref}>
                Seri No Sorgu ekranında aç
              </Link>
            </div>

            {warrantyLoading ? (
              <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-600">Garanti bilgisi sorgulanıyor...</div>
            ) : null}

            {warrantyError ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{warrantyError}</div>
            ) : null}

            <Badge variant="outline" className={warrantyStatusClasses(warranty?.status)}>
              {warranty?.status ?? 'Kontrol Edilemedi'}
            </Badge>

            {warranty ? (
              <div className="grid gap-3 text-sm sm:grid-cols-2">
                {[
                  ['Garanti Durumu', warranty.status],
                  ['Garanti Başlangıcı', formatOptionalDate(warranty.warranty_started_at)],
                  ['Garanti Bitişi', formatOptionalDate(warranty.warranty_ends_at)],
                  ['Kalan Gün', warranty.remaining_days === null || warranty.remaining_days === undefined ? '-' : String(warranty.remaining_days)],
                  ['Garanti Süresi', `${warranty.warranty_period_months} ay`],
                  ['Fiili Montaj Tarihi', formatOptionalDate(warranty.installation.completed_at)],
                  ['Son Satış Cari', [warranty.last_sale?.customer_code, warranty.last_sale?.customer_name].filter(Boolean).join(' - ')],
                  ['Son Satış Evrak', warranty.last_sale?.document_no],
                  ['Kaynak', warranty.source ?? warranty.installation.source],
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
          </div>
        </section>

        <section className="grid gap-4 rounded-2xl border border-slate-200 p-4">
          <div className="flex flex-wrap items-center justify-between gap-3">
            <div>
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Usta / Çilingir</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.technician}</p>
            </div>
            <div className="flex items-center gap-2">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Servis Tipi</p>
              <Badge variant="secondary">{request.serviceType}</Badge>
            </div>
          </div>
          <div className="grid gap-3">
            <div className="rounded-2xl border border-slate-200 bg-white p-3">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Randevu</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{request.appointment}</p>
            </div>
          </div>
        </section>

        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Ödeme / Maliyet</p>
          <div className="grid gap-4 sm:grid-cols-2">
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">İşlem tipi</p>
              <p className="mt-2 text-lg font-semibold text-slate-900">{paymentInfo.serviceTypeLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Müşteri tahsilatı</p>
              <p className="mt-2 text-lg font-semibold text-slate-900">{paymentInfo.customerAmountLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Usta ödemesi</p>
              <p className="mt-2 text-lg font-semibold text-slate-900">{paymentInfo.technicianAmountLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Gidiş-geliş km</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{paymentInfo.roundTripKmLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Ücretsiz km</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{paymentInfo.freeKmLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Ücretli km</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{paymentInfo.billableKmLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Yol ücreti</p>
              <p className="mt-2 text-sm font-semibold text-slate-900">{paymentInfo.travelAmountLabel}</p>
            </div>
            <div className="rounded-2xl border border-slate-200 bg-white p-4 sm:col-span-2">
              <p className="text-xs uppercase tracking-[0.14em] text-slate-500">Toplam usta maliyeti</p>
              <p className="mt-2 text-lg font-semibold text-slate-900">{paymentInfo.totalTechnicianCostLabel}</p>
            </div>
          </div>
        </section>

        <section className="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 break-words">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Notlar</p>
          <p className="mt-2 text-sm leading-6 text-slate-700 break-words whitespace-pre-wrap">{request.notes}</p>
        </section>

        <section className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Durum Timeline</p>
          <div className="mt-4 space-y-4">
            {loading ? (
              <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
                Detay yükleniyor...
              </div>
            ) : error ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                {error}
              </div>
            ) : events.length === 0 ? (
              <div className="rounded-2xl border border-slate-200 bg-white p-4 text-sm text-slate-500">
                Henüz işlem geçmişi yok.
              </div>
            ) : (
              events.map((event) => (
                <div key={String(event.id)} className="flex gap-3 text-sm">
                  <div className="mt-1 h-2.5 w-2.5 rounded-full bg-slate-400" />
                  <div>
                    <p className="font-semibold text-slate-900">{event.title}</p>
                    <p className="text-xs text-slate-500">
                      {eventTime(event.created_at)}
                      {event.note ? ` · ${event.note}` : ''}
                    </p>
                  </div>
                </div>
              ))
            )}
          </div>
        </section>

      </CardContent>

      <div
        className="sticky bottom-0 z-10 bg-white/95 border-t border-slate-200 pt-2 px-3 shadow-[0_-8px_20px_rgba(15,23,42,0.06)] backdrop-blur-sm sm:px-6 sm:pt-3"
        style={{ paddingBottom: 'calc(0.5rem + env(safe-area-inset-bottom))' }}
      >
        <div className="grid grid-cols-2 gap-2">
          <Button
            className="h-9 text-xs sm:text-sm"
            type="button"
            onClick={() => onAssign?.()}
            disabled={isActionDisabled}
            title={isActionDisabled ? disabledTitle : undefined}
          >
            Usta Ata
          </Button>
          <Button
            className="h-9 text-[0.72rem] sm:text-sm"
            variant="secondary"
            type="button"
            disabled
            title="WhatsApp link gönderimi henüz aktif değil"
          >
            WhatsApp Link Gönder
          </Button>
          <Button
            className="h-9 text-xs sm:text-sm"
            variant="destructive"
            type="button"
            onClick={() => !isActionDisabled && onComplete?.()}
            disabled={isActionDisabled}
            title={isActionDisabled ? disabledTitle : undefined}
          >
            Kapat / İptal Et
          </Button>
          {isReopenVisible ? (
            <Button
              className="h-9 text-xs sm:text-sm"
              variant="outline"
              type="button"
              onClick={() => onReopen?.()}
            >
              Talebi Yeniden Aç
            </Button>
          ) : null}
        </div>
      </div>
    </Card>
  )
}
