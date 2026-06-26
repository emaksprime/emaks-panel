import { Head } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import Heading from '@/components/heading'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'
import { formatTechnicalServiceDateTime } from '@/components/technical-service/utils'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type Technician = {
  id: number | string
  name: string
  city?: string | null
}

type Earning = {
  id: number
  technical_service_technician_id?: number | null
  technician_name_snapshot: string
  city_snapshot?: string | null
  job_count: number
  installation_count: number
  service_count: number
  labor_total: string | number
  travel_fee_total: string | number
  travel_round_trip_km_total: string | number
  travel_billable_km_total: string | number
  grand_total: string | number
  status: string
  internal_note?: string | null
  dispute_note?: string | null
  paid_at?: string | null
  company_payable_amount?: string | number
  company_paid_amount?: string | number
  company_remaining_amount?: string | number
  customer_direct_assumed_paid_amount?: string | number
  customer_collection_amount?: string | number
  can_record_payout?: boolean
  can_pay_company_payout?: boolean
  payout_disabled_reason?: string | null
  settlement_status_key?: string
  settlement_status_label?: string
  settlement_disabled_reason?: string | null
  payment_action_label?: string
  reconciliation_missing?: boolean
  reconciliation_missing_reason?: string | null
  payer_state_key?: string
  payer_state_label?: string
  payer_state_description?: string
  settlement_summary?: SettlementSummary
}

type ReviewDecisionValue = 'approve_difference' | 'correct_direct_amount' | 'exclude'

type SettlementReviewDecision = {
  decision?: string | null
  decision_label?: string | null
  reason?: string | null
  reviewed_at?: string | null
  reviewed_by?: number | string | null
  reviewed_by_name?: string | null
  customer_direct_to_technician_amount?: string | number | null
  company_payable_amount?: string | number | null
  overpay_warning_amount?: string | number | null
  requires_review_after_decision?: boolean | null
}

type SettlementPaymentContext = {
  paid_total?: string | number
  pending_total?: string | number
  cancelled_total?: string | number
  paid_count?: number
  pending_count?: number
  cancelled_count?: number
}

type EarningItem = {
  id: number
  mrn: string
  job_date: string
  customer_city?: string | null
  customer_district?: string | null
  service_type?: string | null
  product_name?: string | null
  serial_number?: string | null
  labor_amount: string | number
  travel_round_trip_km: string | number
  travel_billable_km: string | number
  travel_fee_amount: string | number
  line_total: string | number
  note?: string | null
  settlement_summary?: SettlementSummary | null
}

type SettlementSummary = {
  id?: number | string
  settlement_count?: number
  missing_settlement_count?: number
  request_code?: string | null
  root_mrn?: string | null
  labor_earning_amount?: string | number
  route_earning_amount?: string | number
  technician_earning_total?: string | number
  company_payable_amount: string | number
  company_paid_amount: string | number
  company_remaining_amount: string | number
  customer_direct_to_technician_amount?: string | number
  customer_direct_assumed_paid_amount?: string | number
  customer_collection_amount?: string | number
  admin_review_count?: number
  excluded_count?: number
  paid_count?: number
  partial_paid_count?: number
  can_record_payout?: boolean
  can_pay_company_payout?: boolean
  payout_disabled_reason?: string | null
  settlement_status_key?: string
  settlement_status_label?: string
  settlement_disabled_reason?: string | null
  payment_action_label?: string
  reconciliation_missing?: boolean
  reconciliation_missing_reason?: string | null
  status?: string
  status_label?: string
  review_reason?: string | null
  review_decision?: SettlementReviewDecision | null
  payment_context?: SettlementPaymentContext | null
  overpay_requires_review?: boolean
  overpay_warning_amount?: string | number
}

type EarningDetail = Earning & {
  items: EarningItem[]
}

type EarningsResponse = {
  period: null | {
    id: number
    year: number
    month: number
    status: string
    calculated_at?: string | null
  }
  items: Earning[]
  summary: {
    technician_count: number
    job_count: number
    labor_total: number
    travel_fee_total: number
    grand_total: number
    payable_count: number
    paid_count: number
    disputed_count: number
    partial_paid_count: number
    reconciliation_missing_count: number
    no_company_payable_count: number
    company_payable_total: number
    company_paid_total: number
    company_remaining_total: number
    admin_review_count: number
  }
}

const statusOptions = ['', 'Mutabakat oluşmadı', 'Ödenecek', 'Kısmi ödendi', 'Ödendi', 'Şirket ödemesi yok', 'Admin incelemesi', 'Hakedişe dahil değil', 'İtirazlı']
const months = [
  ['1', 'Ocak'],
  ['2', 'Şubat'],
  ['3', 'Mart'],
  ['4', 'Nisan'],
  ['5', 'Mayıs'],
  ['6', 'Haziran'],
  ['7', 'Temmuz'],
  ['8', 'Ağustos'],
  ['9', 'Eylül'],
  ['10', 'Ekim'],
  ['11', 'Kasım'],
  ['12', 'Aralık'],
]

const money = (value: string | number | null | undefined): string => `${Number(value ?? 0).toLocaleString('tr-TR', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})} TL`

const numberText = (value: string | number | null | undefined): string => Number(value ?? 0).toLocaleString('tr-TR', {
  minimumFractionDigits: 2,
  maximumFractionDigits: 2,
})

const amountValue = (value: string | number | null | undefined): number => Number(value ?? 0)
const inputAmount = (value: string | number | null | undefined): string => amountValue(value).toFixed(2)

const reviewDecisionOptions: Array<{ value: ReviewDecisionValue, label: string, description: string }> = [
  {
    value: 'approve_difference',
    label: 'Farkı onayla',
    description: 'Fazla bildirim kayıtlı kalır, şirket ödemesi negatif üretilmez.',
  },
  {
    value: 'correct_direct_amount',
    label: 'Tutarları düzelt',
    description: 'Müşteriye bildirilecek ustaya ödeme tutarı düzeltilir ve mutabakat yeniden hesaplanır.',
  },
  {
    value: 'exclude',
    label: 'Hakedişe dahil değil',
    description: 'Bu iş için şirket ödemesi kapatılır; ödeme geçmişi silinmez.',
  },
]

const settlementNeedsReview = (settlement: SettlementSummary | null | undefined): boolean => (
  Boolean(settlement && (settlement.status === 'admin_review' || settlement.overpay_requires_review))
)

const formatDate = (value: string | null | undefined): string => {
  return formatTechnicalServiceDateTime(value)
}

const badgeClass = (status: string): string => {
  switch (status) {
    case 'Ödenecek':
      return 'border-emerald-200 bg-emerald-50 text-emerald-700'
    case 'Ödendi':
      return 'border-blue-200 bg-blue-50 text-blue-700'
    case 'Kısmi ödendi':
      return 'border-sky-200 bg-sky-50 text-sky-700'
    case 'Mutabakat oluşmadı':
      return 'border-amber-200 bg-amber-50 text-amber-800'
    case 'Şirket ödemesi yok':
      return 'border-slate-200 bg-slate-50 text-slate-700'
    case 'Admin incelemesi':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    case 'Hakedişe dahil değil':
      return 'border-slate-200 bg-slate-100 text-slate-600'
    case 'İtirazlı':
      return 'border-rose-200 bg-rose-50 text-rose-700'
    default:
      return 'border-amber-200 bg-amber-50 text-amber-800'
  }
}

export default function TechnicalServiceEarnings() {
  const now = new Date()
  const [year, setYear] = useState(String(now.getFullYear()))
  const [month, setMonth] = useState(String(now.getMonth() + 1))
  const [technicianId, setTechnicianId] = useState('')
  const [status, setStatus] = useState('')
  const [technicians, setTechnicians] = useState<Technician[]>([])
  const [data, setData] = useState<EarningsResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [calculating, setCalculating] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [detail, setDetail] = useState<EarningDetail | null>(null)
  const [detailOpen, setDetailOpen] = useState(false)
  const [detailLoading, setDetailLoading] = useState(false)
  const [whatsappText, setWhatsappText] = useState('')
  const [whatsappOpen, setWhatsappOpen] = useState(false)
  const [payoutOpen, setPayoutOpen] = useState(false)
  const [payoutEarning, setPayoutEarning] = useState<Earning | null>(null)
  const [payoutAmount, setPayoutAmount] = useState('')
  const [payoutReason, setPayoutReason] = useState('')
  const [payoutReference, setPayoutReference] = useState('')
  const [payoutError, setPayoutError] = useState<string | null>(null)
  const [payoutSubmitting, setPayoutSubmitting] = useState(false)
  const [reviewOpen, setReviewOpen] = useState(false)
  const [reviewEarning, setReviewEarning] = useState<EarningDetail | null>(null)
  const [reviewLoading, setReviewLoading] = useState(false)
  const [reviewSettlementId, setReviewSettlementId] = useState('')
  const [reviewDecision, setReviewDecision] = useState<ReviewDecisionValue>('approve_difference')
  const [reviewReason, setReviewReason] = useState('')
  const [reviewCorrectDirectAmount, setReviewCorrectDirectAmount] = useState('')
  const [reviewError, setReviewError] = useState<string | null>(null)
  const [reviewSubmitting, setReviewSubmitting] = useState(false)

  const query = useMemo(() => {
    const params = new URLSearchParams({ year, month })

    if (technicianId) {
      params.set('technician_id', technicianId)
    }

    if (status) {
      params.set('status', status)
    }

    return params.toString()
  }, [month, status, technicianId, year])

  const loadTechnicians = useCallback(async () => {
    const response = await apiRequest('/api/technical-service/technicians?active=1')
    setTechnicians(Array.isArray(response.items) ? response.items : [])
  }, [])

  const loadEarnings = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const response = await apiRequest(`/api/technical-service/earnings?${query}`)
      setData(response as EarningsResponse)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Hakediş verisi alınamadı.')
    } finally {
      setLoading(false)
    }
  }, [query])

  const calculatePeriod = async () => {
    setCalculating(true)
    setError(null)

    try {
      await apiRequest('/api/technical-service/earnings/periods/calculate', {
        method: 'POST',
        body: JSON.stringify({ year: Number(year), month: Number(month) }),
      })
      await loadEarnings()
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Hakediş dönemi hesaplanamadı.')
    } finally {
      setCalculating(false)
    }
  }

  const openDetail = async (earning: Earning) => {
    setDetail({ ...earning, items: [] })
    setDetailOpen(true)
    setDetailLoading(true)

    try {
      const response = await apiRequest(`/api/technical-service/earnings/${earning.id}`)
      setDetail(response.earning as EarningDetail)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Hakediş detayı alınamadı.')
    } finally {
      setDetailLoading(false)
    }
  }

  const openPayout = (earning: Earning) => {
    const remaining = amountValue(earning.company_remaining_amount ?? earning.settlement_summary?.company_remaining_amount)
    setPayoutEarning(earning)
    setPayoutAmount(inputAmount(remaining))
    setPayoutReason('')
    setPayoutReference('')
    setPayoutError(null)
    setPayoutOpen(true)
  }

  const reviewableItems = (earning: EarningDetail | null): EarningItem[] => (
    earning?.items.filter((item) => settlementNeedsReview(item.settlement_summary)) ?? []
  )

  const setReviewSelection = (earning: EarningDetail) => {
    const firstReviewable = reviewableItems(earning)[0]
    const settlement = firstReviewable?.settlement_summary ?? null

    setReviewSettlementId(settlement?.id !== undefined && settlement?.id !== null ? String(settlement.id) : '')
    setReviewDecision('approve_difference')
    setReviewReason('')
    setReviewCorrectDirectAmount(inputAmount(settlement?.customer_direct_to_technician_amount))
    setReviewError(null)
  }

  const openReview = async (earning: Earning) => {
    setReviewEarning({ ...earning, items: [] })
    setReviewOpen(true)
    setReviewLoading(true)
    setReviewError(null)

    try {
      const response = await apiRequest(`/api/technical-service/earnings/${earning.id}`)
      const loaded = response.earning as EarningDetail

      setReviewEarning(loaded)
      setReviewSelection(loaded)
    } catch (caught) {
      setReviewError(caught instanceof Error ? caught.message : 'Hakediş inceleme detayı alınamadı.')
    } finally {
      setReviewLoading(false)
    }
  }

  const openReviewFromDetail = (earning: EarningDetail, item: EarningItem) => {
    const settlement = item.settlement_summary

    setReviewEarning(earning)
    setReviewOpen(true)
    setReviewLoading(false)
    setReviewSettlementId(settlement?.id !== undefined && settlement?.id !== null ? String(settlement.id) : '')
    setReviewDecision('approve_difference')
    setReviewReason('')
    setReviewCorrectDirectAmount(inputAmount(settlement?.customer_direct_to_technician_amount))
    setReviewError(null)
  }

  const submitReview = async () => {
    if (!reviewEarning || !reviewSettlementId) {
      setReviewError('İncelenecek mutabakat satırı seçilmelidir.')

      return
    }

    const reason = reviewReason.trim()

    if (!reason) {
      setReviewError('Admin inceleme kararı için açıklama zorunludur.')

      return
    }

    const payload: Record<string, unknown> = {
      settlement_id: Number(reviewSettlementId),
      decision: reviewDecision,
      reason,
    }

    if (reviewDecision === 'correct_direct_amount') {
      const correctedAmount = Number(reviewCorrectDirectAmount)

      if (!Number.isFinite(correctedAmount) || correctedAmount < 0) {
        setReviewError('Müşteriye bildirilecek tutar 0 TL veya üstü olmalıdır.')

        return
      }

      payload.customer_direct_to_technician_amount = correctedAmount
    }

    setReviewSubmitting(true)
    setReviewError(null)

    try {
      const response = await apiRequest(`/api/technical-service/earnings/${reviewEarning.id}/review`, {
        method: 'POST',
        body: JSON.stringify(payload),
      })

      if (detail?.id === reviewEarning.id && response.earning) {
        setDetail(response.earning as EarningDetail)
      }

      setReviewOpen(false)
      setReviewEarning(null)
      await loadEarnings()
    } catch (caught) {
      setReviewError(caught instanceof Error ? caught.message : 'Hakediş incelemesi kaydedilemedi.')
    } finally {
      setReviewSubmitting(false)
    }
  }

  const submitPayout = async () => {
    if (!payoutEarning) {
      return
    }

    const amount = Number(payoutAmount)
    const remaining = amountValue(payoutEarning.company_remaining_amount ?? payoutEarning.settlement_summary?.company_remaining_amount)

    if (!Number.isFinite(amount) || amount <= 0) {
      setPayoutError('Ödenen tutar 0 TL’den büyük olmalıdır.')

      return
    }

    if (amount > remaining) {
      setPayoutError('Ödenen tutar kalan şirket ödemesinden büyük olamaz.')

      return
    }

    setPayoutSubmitting(true)
    setPayoutError(null)

    try {
      const response = await apiRequest(`/api/technical-service/earnings/${payoutEarning.id}/mark-paid`, {
        method: 'POST',
        body: JSON.stringify({
          amount,
          reason: payoutReason.trim() || null,
          reference: payoutReference.trim() || null,
        }),
      })

      if (detail?.id === payoutEarning.id && response.earning) {
        setDetail(response.earning as EarningDetail)
      }

      setPayoutOpen(false)
      setPayoutEarning(null)
      await loadEarnings()
    } catch (caught) {
      setPayoutError(caught instanceof Error ? caught.message : 'Hakediş ödemesi kaydedilemedi.')
    } finally {
      setPayoutSubmitting(false)
    }
  }

  const markPaid = (earning: Earning) => {
    openPayout(earning)
  }

  const markDisputed = async (earning: Earning) => {
    await apiRequest(`/api/technical-service/earnings/${earning.id}`, {
      method: 'PATCH',
      body: JSON.stringify({ status: 'İtirazlı', dispute_note: 'Operasyon kontrolü için itirazlı işaretlendi.' }),
    })
    await loadEarnings()
  }

  const openWhatsapp = async (earning: Earning) => {
    const response = await apiRequest(`/api/technical-service/earnings/${earning.id}/whatsapp-text`)
    setWhatsappText(String(response.text ?? ''))
    setWhatsappOpen(true)
  }

  useEffect(() => {
    void Promise.resolve().then(loadTechnicians)
  }, [loadTechnicians])

  useEffect(() => {
    void Promise.resolve().then(loadEarnings)
  }, [loadEarnings])

  const summary = data?.summary ?? {
    technician_count: 0,
    job_count: 0,
    labor_total: 0,
    travel_fee_total: 0,
    grand_total: 0,
    payable_count: 0,
    paid_count: 0,
    disputed_count: 0,
    partial_paid_count: 0,
    company_payable_total: 0,
    company_paid_total: 0,
    company_remaining_total: 0,
    admin_review_count: 0,
    reconciliation_missing_count: 0,
    no_company_payable_count: 0,
  }
  const payoutRemaining = amountValue(payoutEarning?.company_remaining_amount ?? payoutEarning?.settlement_summary?.company_remaining_amount)
  const payoutAlreadyPaid = amountValue(payoutEarning?.company_paid_amount ?? payoutEarning?.settlement_summary?.company_paid_amount)
  const payoutCompanyPayable = amountValue(payoutEarning?.company_payable_amount ?? payoutEarning?.settlement_summary?.company_payable_amount)
  const currentReviewItems = reviewableItems(reviewEarning)
  const selectedReviewItem = currentReviewItems.find((item) => String(item.settlement_summary?.id ?? '') === reviewSettlementId)
    ?? currentReviewItems[0]
    ?? null
  const selectedReviewSettlement = selectedReviewItem?.settlement_summary ?? null
  const selectedReviewPaymentContext = selectedReviewSettlement?.payment_context ?? null
  const selectedReviewDecisionOption = reviewDecisionOptions.find((option) => option.value === reviewDecision)

  return (
    <>
      <Head title="Servis Hakedişleri" />
      <div className="mx-auto w-full max-w-[1800px] space-y-6 px-4 py-6 md:px-6 lg:px-10">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="Servis Hakedişleri"
            description="Servis ve çilingir bazlı aylık hakediş kontrolü."
          />
          <div className="flex flex-wrap gap-2">
            <TechnicalServicePageLinks />
            <Button type="button" onClick={() => void loadEarnings()} disabled={loading}>{loading ? 'Yükleniyor...' : 'Yenile'}</Button>
          </div>
        </div>

        <section className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 md:grid-cols-5">
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Ay
            <select className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={month} onChange={(event) => setMonth(event.target.value)}>
              {months.map(([value, label]) => <option key={value} value={value}>{label}</option>)}
            </select>
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Yıl
            <Input value={year} onChange={(event) => setYear(event.target.value)} />
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Servis / Usta
            <select className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={technicianId} onChange={(event) => setTechnicianId(event.target.value)}>
              <option value="">Tümü</option>
              {technicians.map((technician) => (
                <option key={technician.id} value={String(technician.id)}>{technician.name}</option>
              ))}
            </select>
          </label>
          <label className="grid gap-1 text-xs font-semibold text-slate-600">
            Durum
            <select className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={status} onChange={(event) => setStatus(event.target.value)}>
              {statusOptions.map((option) => <option key={option || 'all'} value={option}>{option || 'Tümü'}</option>)}
            </select>
          </label>
          <div className="flex items-end">
            <Button className="w-full" type="button" onClick={() => void calculatePeriod()} disabled={calculating}>
              {calculating ? 'Hesaplanıyor...' : 'Hesapla / Yeniden Hesapla'}
            </Button>
          </div>
        </section>

        {error ? (
          <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div>
        ) : null}

        <section className="grid gap-3 md:grid-cols-3 xl:grid-cols-6">
          {[
            ['Servis Sayısı', summary.technician_count],
            ['İş Sayısı', summary.job_count],
            ['Hizmet Bedeli', money(summary.labor_total)],
            ['Yol Ücreti', money(summary.travel_fee_total)],
            ['Genel Hakediş', money(summary.grand_total)],
            ['Şirket Ödeyecek', money(summary.company_payable_total)],
            ['Şirket Ödedi', money(summary.company_paid_total)],
            ['Kalan Bakiye', money(summary.company_remaining_total)],
            ['Mutabakat bekleyen', summary.reconciliation_missing_count],
            ['Ödenecek / Kısmi / Ödendi', `${summary.payable_count} / ${summary.partial_paid_count} / ${summary.paid_count}`],
          ].map(([label, value]) => (
            <div key={label} className="rounded-2xl border border-slate-200 bg-white p-4">
              <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
              <p className="mt-2 text-lg font-semibold text-slate-950">{value}</p>
            </div>
          ))}
        </section>

        <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white">
          <div className="flex flex-wrap items-center justify-between gap-3 border-b border-slate-200 p-4">
            <div>
              <h2 className="text-sm font-semibold text-slate-950">Servis Bazlı Hakedişler</h2>
              <p className="mt-1 text-xs text-slate-500">Period: {data?.period ? `${data.period.month}/${data.period.year} - ${data.period.status}` : 'Henüz hesaplanmadı'}</p>
            </div>
          </div>
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1500px] text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-[0.08em] text-slate-500">
                <tr>
                  <th className="px-4 py-3">Servis</th>
                  <th className="px-4 py-3">Şehir</th>
                  <th className="px-4 py-3">İş</th>
                  <th className="px-4 py-3">Montaj</th>
                  <th className="px-4 py-3">Servis/Arıza</th>
                  <th className="px-4 py-3">Hizmet</th>
                  <th className="px-4 py-3">Yol</th>
                  <th className="px-4 py-3">Toplam</th>
                  <th className="px-4 py-3">Şirket Ödeyecek</th>
                  <th className="px-4 py-3">Ödenen</th>
                  <th className="px-4 py-3">Kalan</th>
                  <th className="px-4 py-3">Durum</th>
                  <th className="px-4 py-3">Aksiyon</th>
                </tr>
              </thead>
              <tbody>
                {data?.items.length ? data.items.map((earning) => {
                  const statusLabel = earning.settlement_status_label || earning.status
                  const canPay = earning.can_pay_company_payout ?? earning.can_record_payout ?? false
                  const isAdminReview = earning.settlement_status_key === 'admin_review'
                    || Number(earning.settlement_summary?.admin_review_count ?? 0) > 0
                  const disabledReason = earning.settlement_disabled_reason || earning.payout_disabled_reason || undefined
                  const actionLabel = earning.payment_action_label
                    || (amountValue(earning.company_remaining_amount) <= 0 ? 'Ödendi' : amountValue(earning.company_paid_amount) > 0 ? 'Kalanı öde' : 'Ödeme Yap')

                  return (
                  <tr key={earning.id} className="border-t border-slate-100 align-top">
                    <td className="px-4 py-3 font-semibold text-slate-950">{earning.technician_name_snapshot}</td>
                    <td className="px-4 py-3 text-slate-700">{earning.city_snapshot || '-'}</td>
                    <td className="px-4 py-3 text-slate-700">{earning.job_count}</td>
                    <td className="px-4 py-3 text-slate-700">{earning.installation_count}</td>
                    <td className="px-4 py-3 text-slate-700">{earning.service_count}</td>
                    <td className="px-4 py-3 text-slate-700">{money(earning.labor_total)}</td>
                    <td className="px-4 py-3 text-slate-700">{money(earning.travel_fee_total)}</td>
                    <td className="px-4 py-3 font-semibold text-slate-950">{money(earning.grand_total)}</td>
                    <td className="px-4 py-3 text-slate-700">{money(earning.company_payable_amount)}</td>
                    <td className="px-4 py-3 text-slate-700">{money(earning.company_paid_amount)}</td>
                    <td className="px-4 py-3 font-semibold text-slate-950">{money(earning.company_remaining_amount)}</td>
                    <td className="px-4 py-3"><Badge variant="outline" className={badgeClass(statusLabel)}>{statusLabel}</Badge></td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-2">
                        <Button size="sm" variant="secondary" onClick={() => void openDetail(earning)}>Detay</Button>
                        <Button size="sm" variant="secondary" onClick={() => void openWhatsapp(earning)}>WhatsApp</Button>
                        <Button
                          size="sm"
                          variant={isAdminReview ? 'default' : 'outline'}
                          onClick={() => void (isAdminReview ? openReview(earning) : markPaid(earning))}
                          disabled={isAdminReview ? false : !canPay}
                          title={disabledReason}
                        >
                          {isAdminReview ? 'İncele' : actionLabel}
                        </Button>
                        <Button size="sm" variant="destructive" onClick={() => void markDisputed(earning)}>İtirazlı</Button>
                        {isAdminReview ? (
                          <span className="basis-full text-xs text-rose-600">Admin incelemesi tamamlanmadan ödeme yapılamaz.</span>
                        ) : !canPay && disabledReason ? (
                          <span className="basis-full text-xs text-slate-500">{disabledReason}</span>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                  )
                }) : (
                  <tr>
                    <td colSpan={13} className="px-4 py-6 text-slate-500">Bu dönem için hakediş kaydı yok. Hesapla / Yeniden Hesapla ile oluşturabilirsiniz.</td>
                  </tr>
                )}
              </tbody>
            </table>
          </div>
        </section>
      </div>

      <Dialog open={detailOpen} onOpenChange={setDetailOpen}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[1100px]">
          <DialogHeader>
            <DialogTitle>{detail?.technician_name_snapshot ?? 'Hakediş Detayı'}</DialogTitle>
            <DialogDescription>Servis detay ekstresi</DialogDescription>
          </DialogHeader>
          {detailLoading ? (
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
              Hakediş detayı yükleniyor...
            </div>
          ) : null}
          <div className="overflow-x-auto">
            <table className="w-full min-w-[1000px] text-left text-sm">
              <thead className="bg-slate-50 text-xs uppercase tracking-[0.08em] text-slate-500">
                <tr>
                  <th className="px-3 py-2">MRN</th>
                  <th className="px-3 py-2">İş Tarihi</th>
                  <th className="px-3 py-2">İl/İlçe</th>
                  <th className="px-3 py-2">Tip</th>
                  <th className="px-3 py-2">Ürün</th>
                  <th className="px-3 py-2">Seri No</th>
                  <th className="px-3 py-2">Hizmet</th>
                  <th className="px-3 py-2">Km</th>
                  <th className="px-3 py-2">Ücretli Km</th>
                  <th className="px-3 py-2">Yol</th>
                  <th className="px-3 py-2">Toplam</th>
                  <th className="px-3 py-2">Şirket Ödeyecek</th>
                  <th className="px-3 py-2">Ödenen</th>
                  <th className="px-3 py-2">Kalan</th>
                  <th className="px-3 py-2">Mutabakat</th>
                  <th className="px-3 py-2">Aksiyon</th>
                  <th className="px-3 py-2">Not</th>
                </tr>
              </thead>
              <tbody>
                {detail?.items.length ? detail.items.map((item) => {
                  const itemNeedsReview = settlementNeedsReview(item.settlement_summary)
                  const itemStatusLabel = item.settlement_summary?.status_label || item.settlement_summary?.settlement_status_label || '-'

                  return (
                    <tr key={item.id} className="border-t border-slate-100 align-top">
                      <td className="px-3 py-2 font-semibold">{item.mrn}</td>
                      <td className="px-3 py-2">{formatDate(item.job_date)}</td>
                      <td className="px-3 py-2">{[item.customer_city, item.customer_district].filter(Boolean).join(' / ') || '-'}</td>
                      <td className="px-3 py-2">{item.service_type || '-'}</td>
                      <td className="px-3 py-2">{item.product_name || '-'}</td>
                      <td className="px-3 py-2">{item.serial_number || '-'}</td>
                      <td className="px-3 py-2">{money(item.labor_amount)}</td>
                      <td className="px-3 py-2">{numberText(item.travel_round_trip_km)}</td>
                      <td className="px-3 py-2">{numberText(item.travel_billable_km)}</td>
                      <td className="px-3 py-2">{money(item.travel_fee_amount)}</td>
                      <td className="px-3 py-2 font-semibold">{money(item.line_total)}</td>
                      <td className="px-3 py-2">{money(item.settlement_summary?.company_payable_amount)}</td>
                      <td className="px-3 py-2">{money(item.settlement_summary?.company_paid_amount)}</td>
                      <td className="px-3 py-2 font-semibold">{money(item.settlement_summary?.company_remaining_amount)}</td>
                      <td className="px-3 py-2"><Badge variant="outline" className={badgeClass(itemStatusLabel)}>{itemStatusLabel}</Badge></td>
                      <td className="px-3 py-2">
                        {itemNeedsReview && detail ? (
                          <Button type="button" size="sm" onClick={() => openReviewFromDetail(detail, item)}>İncele</Button>
                        ) : (
                          <span className="text-xs text-slate-400">-</span>
                        )}
                      </td>
                      <td className="px-3 py-2 text-amber-700">{item.note || '-'}</td>
                    </tr>
                  )
                }) : !detailLoading ? (
                  <tr>
                    <td colSpan={17} className="px-3 py-5 text-slate-500">Detay satırı yok.</td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>
        </DialogContent>
      </Dialog>

      <Dialog open={reviewOpen} onOpenChange={(open) => {
        setReviewOpen(open)

        if (!open) {
          setReviewError(null)
          setReviewEarning(null)
        }
      }}>
        <DialogContent className="max-h-[90vh] overflow-y-auto sm:max-w-[920px]">
          <DialogHeader>
            <DialogTitle>Hakediş mutabakatı incelemesi</DialogTitle>
            <DialogDescription>
              Admin incelemesi tamamlanmadan ödeme yapılamaz. Karar ledger satırına ve talep olay geçmişine yazılır.
            </DialogDescription>
          </DialogHeader>

          {reviewLoading ? (
            <div className="rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-600">
              İnceleme detayı yükleniyor...
            </div>
          ) : null}

          <div className="grid gap-4">
            {currentReviewItems.length > 1 ? (
              <label className="grid gap-1 text-sm font-medium text-slate-700">
                İncelenecek iş
                <select
                  className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm"
                  value={reviewSettlementId}
                  onChange={(event) => {
                    const nextId = event.target.value
                    const nextItem = currentReviewItems.find((item) => String(item.settlement_summary?.id ?? '') === nextId)

                    setReviewSettlementId(nextId)
                    setReviewCorrectDirectAmount(inputAmount(nextItem?.settlement_summary?.customer_direct_to_technician_amount))
                  }}
                >
                  {currentReviewItems.map((item) => (
                    <option key={String(item.settlement_summary?.id ?? item.id)} value={String(item.settlement_summary?.id ?? '')}>
                      {item.mrn} - {item.serial_number || 'Seri yok'} - {money(item.settlement_summary?.overpay_warning_amount)}
                    </option>
                  ))}
                </select>
              </label>
            ) : null}

            {selectedReviewItem && selectedReviewSettlement ? (
              <>
                <section className="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <p className="font-semibold text-slate-950">Talep bilgisi</p>
                    <Badge variant="outline">{selectedReviewSettlement.status_label || 'Admin incelemesi'}</Badge>
                  </div>
                  <div className="grid gap-2 sm:grid-cols-3">
                    <span>MRN / SRV: <strong>{selectedReviewSettlement.request_code || selectedReviewItem.mrn}</strong></span>
                    <span>Seri: <strong>{selectedReviewItem.serial_number || '-'}</strong></span>
                    <span>İl/İlçe: <strong>{[selectedReviewItem.customer_city, selectedReviewItem.customer_district].filter(Boolean).join(' / ') || '-'}</strong></span>
                    <span>Ürün: <strong>{selectedReviewItem.product_name || '-'}</strong></span>
                    <span>İş tipi: <strong>{selectedReviewItem.service_type || '-'}</strong></span>
                    <span>Tamamlanma: <strong>{formatDate(selectedReviewItem.job_date)}</strong></span>
                  </div>
                </section>

                <section className="grid gap-3 sm:grid-cols-3">
                  <div className="grid gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm">
                    <p className="font-semibold text-slate-950">Hakediş bilgisi</p>
                    <span>İşçilik: <strong>{money(selectedReviewSettlement.labor_earning_amount)}</strong></span>
                    <span>Yol: <strong>{money(selectedReviewSettlement.route_earning_amount)}</strong></span>
                    <span>Toplam usta hakedişi: <strong>{money(selectedReviewSettlement.technician_earning_total ?? selectedReviewItem.line_total)}</strong></span>
                  </div>

                  <div className="grid gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm">
                    <p className="font-semibold text-slate-950">Müşteri ödeme bilgisi</p>
                    <span>Müşteriye bildirilecek ustaya ödeme: <strong>{money(selectedReviewSettlement.customer_direct_to_technician_amount)}</strong></span>
                    <span>Ustaya ödendi varsayılan: <strong>{money(selectedReviewSettlement.customer_direct_assumed_paid_amount)}</strong></span>
                    <span>Online müşteri tahsilatı: <strong>{money(selectedReviewSettlement.customer_collection_amount)}</strong></span>
                    <span>Paid link: <strong>{selectedReviewPaymentContext?.paid_count ?? 0} / {money(selectedReviewPaymentContext?.paid_total)}</strong></span>
                    <span>Bekleyen link: <strong>{selectedReviewPaymentContext?.pending_count ?? 0} / {money(selectedReviewPaymentContext?.pending_total)}</strong></span>
                    <span>İptal link: <strong>{selectedReviewPaymentContext?.cancelled_count ?? 0} / {money(selectedReviewPaymentContext?.cancelled_total)}</strong></span>
                  </div>

                  <div className="grid gap-2 rounded-lg border border-slate-200 bg-white p-3 text-sm">
                    <p className="font-semibold text-slate-950">Şirket ödeme bilgisi</p>
                    <span>Şirket ödeyecek: <strong>{money(selectedReviewSettlement.company_payable_amount)}</strong></span>
                    <span>Şirket ödedi: <strong>{money(selectedReviewSettlement.company_paid_amount)}</strong></span>
                    <span>Kalan bakiye: <strong>{money(selectedReviewSettlement.company_remaining_amount)}</strong></span>
                  </div>
                </section>

                <section className="grid gap-2 rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm text-amber-950">
                  <p className="font-semibold">İnceleme sebebi</p>
                  <span>Fazla bildirim: <strong>{money(selectedReviewSettlement.overpay_warning_amount)}</strong></span>
                  <p>{selectedReviewSettlement.review_reason || 'Müşteriye bildirilen tutar usta hakedişinden yüksek.'}</p>
                </section>

                <section className="grid gap-3 rounded-lg border border-slate-200 bg-white p-3">
                  <label className="grid gap-1 text-sm font-medium text-slate-700">
                    Admin kararı
                    <select
                      className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm"
                      value={reviewDecision}
                      onChange={(event) => setReviewDecision(event.target.value as ReviewDecisionValue)}
                    >
                      {reviewDecisionOptions.map((option) => <option key={option.value} value={option.value}>{option.label}</option>)}
                    </select>
                  </label>
                  <p className="text-xs text-slate-500">{selectedReviewDecisionOption?.description}</p>

                  {reviewDecision === 'correct_direct_amount' ? (
                    <div className="grid gap-2 rounded-lg border border-blue-200 bg-blue-50 p-3">
                      <label className="grid gap-1 text-sm font-medium text-blue-950">
                        Müşteriye bildirilecek ustaya ödeme tutarı
                        <Input
                          type="number"
                          min="0"
                          step="0.01"
                          value={reviewCorrectDirectAmount}
                          onChange={(event) => setReviewCorrectDirectAmount(event.target.value)}
                        />
                      </label>
                      <p className="text-xs font-semibold text-blue-800">Usta hakedişi revizyonu ayrı akıştan yapılır.</p>
                    </div>
                  ) : null}

                  <label className="grid gap-1 text-sm font-medium text-slate-700">
                    Karar açıklaması
                    <textarea
                      className="min-h-24 rounded-md border border-slate-200 p-3 text-sm"
                      value={reviewReason}
                      onChange={(event) => setReviewReason(event.target.value)}
                      placeholder="Kararın gerekçesini yazın"
                    />
                  </label>
                </section>
              </>
            ) : !reviewLoading ? (
              <div className="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-900">
                İncelenecek admin_review mutabakat satırı bulunamadı.
              </div>
            ) : null}

            {reviewError ? (
              <div className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{reviewError}</div>
            ) : null}
          </div>

          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setReviewOpen(false)} disabled={reviewSubmitting}>İncelemeyi sürdür / beklet</Button>
            <Button type="button" onClick={() => void submitReview()} disabled={reviewSubmitting || !selectedReviewSettlement}>
              {reviewSubmitting ? 'Kaydediliyor...' : 'Kararı kaydet'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={payoutOpen} onOpenChange={(open) => {
        setPayoutOpen(open)

        if (!open) {
          setPayoutError(null)
        }
      }}>
        <DialogContent className="sm:max-w-[560px]">
          <DialogHeader>
            <DialogTitle>Hakediş ödemesi</DialogTitle>
            <DialogDescription>
              Şirketin ustaya ödeyeceği tutar için ledger kaydı oluşturulur. Müşterinin ustaya ödediği varsayılan tutar şirket ödemesi sayılmaz.
            </DialogDescription>
          </DialogHeader>
          <div className="grid gap-4">
            <div className="grid gap-2 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm">
              <div className="flex justify-between gap-3">
                <span className="text-slate-600">Şirket ödeyecek</span>
                <span className="font-semibold text-slate-950">{money(payoutCompanyPayable)}</span>
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-slate-600">Ödenen</span>
                <span className="font-semibold text-slate-950">{money(payoutAlreadyPaid)}</span>
              </div>
              <div className="flex justify-between gap-3">
                <span className="text-slate-600">Kalan</span>
                <span className="font-semibold text-slate-950">{money(payoutRemaining)}</span>
              </div>
            </div>

            <label className="grid gap-1 text-sm font-medium text-slate-700">
              Ödenen tutar
              <Input
                type="number"
                min="0.01"
                max={payoutRemaining || undefined}
                step="0.01"
                value={payoutAmount}
                onChange={(event) => setPayoutAmount(event.target.value)}
              />
            </label>

            <label className="grid gap-1 text-sm font-medium text-slate-700">
              Referans
              <Input
                value={payoutReference}
                onChange={(event) => setPayoutReference(event.target.value)}
                placeholder="Dekont / işlem referansı"
              />
            </label>

            <label className="grid gap-1 text-sm font-medium text-slate-700">
              Not
              <textarea
                className="min-h-24 rounded-md border border-slate-200 p-3 text-sm"
                value={payoutReason}
                onChange={(event) => setPayoutReason(event.target.value)}
                placeholder="Opsiyonel ödeme notu"
              />
            </label>

            {payoutError ? (
              <div className="rounded-lg border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{payoutError}</div>
            ) : null}
          </div>
          <DialogFooter>
            <Button type="button" variant="outline" onClick={() => setPayoutOpen(false)} disabled={payoutSubmitting}>İptal</Button>
            <Button type="button" onClick={() => void submitPayout()} disabled={payoutSubmitting}>
              {payoutSubmitting ? 'Kaydediliyor...' : 'Ödemeyi kaydet'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>

      <Dialog open={whatsappOpen} onOpenChange={setWhatsappOpen}>
        <DialogContent className="sm:max-w-[720px]">
          <DialogHeader>
            <DialogTitle>WhatsApp Hakediş Metni</DialogTitle>
            <DialogDescription>Kopyalanabilir servis ekstresi</DialogDescription>
          </DialogHeader>
          <textarea
            className="min-h-[420px] w-full rounded-md border border-slate-200 p-3 text-sm"
            readOnly
            value={whatsappText}
          />
          <Button type="button" onClick={() => void navigator.clipboard?.writeText(whatsappText)}>Kopyala</Button>
        </DialogContent>
      </Dialog>
    </>
  )
}
