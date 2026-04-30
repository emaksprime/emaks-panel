import { Head } from '@inertiajs/react'
import { useEffect, useMemo, useRef, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import Heading from '@/components/heading'
import { apiRequest } from '@/lib/api'
import { ServiceSummaryCards } from '@/components/technical-service/ServiceSummaryCards'
import { ServiceFilters } from '@/components/technical-service/ServiceFilters'
import { ServiceRequestDetails } from '@/components/technical-service/ServiceRequestDetails'
import { ServiceRequestTable } from '@/components/technical-service/ServiceRequestTable'
import { formatTechnicalServiceMrn, getServicePaymentInfo, normalizeTechnicalServiceText } from '@/components/technical-service/utils'
import type { ServiceFilters as FilterState, ServiceRequest, SummaryItem } from '@/components/technical-service/types'

type NewRequestForm = {
  customer: string
  phone: string
  city: string
  district: string
  address: string
  product: string
  serialNumber: string
  serviceType: string
  notes: string
}

type ApiTechnicalServiceRequest = {
  id: number | string
  mrn: string
  customer_name: string
  customer_phone: string
  customer_city: string
  customer_district: string
  service_address: string
  product_name: string
  product_model?: string | null
  serial_number?: string | null
  service_type: string
  status: string
  priority: string
  technician_name?: string | null
  scheduled_at?: string | null
  description?: string | null
  resolution_notes?: string | null
  source_channel?: string | null
  created_at?: string | null
}

type ApiTechnicalServiceEvent = {
  id: number | string
  event_type: string
  title: string
  note?: string | null
  from_status?: string | null
  to_status?: string | null
  author_user_id?: number | null
  metadata?: Record<string, unknown> | null
  created_at: string
  updated_at: string
}

type SummaryResponse = {
  total_requests: number
  ongoing_requests: number
  status_counts: Record<string, number>
  priority_counts: Record<string, number>
  scheduled_today: number
}

const initialFilters: FilterState = {
  search: '',
  status: '',
}

const initialRequestForm: NewRequestForm = {
  customer: '',
  phone: '',
  city: '',
  district: '',
  address: '',
  product: '',
  serialNumber: '',
  serviceType: '',
  notes: '',
}

const TECHNICIANS = [
  'METİN USTA',
  'BURHAN USTA',
  'FATİH USTA',
  'EMRE USTA',
  'BARIŞ USTA',
  'Diğer',
] as const

const CLOSURE_REASONS = [
  'Montaj tamamlandı',
  'Müşterinin kapısı uygun değildi',
  'Müşteri siparişi iptal etti',
  'Müşteri randevuya gelmedi / evde yoktu',
  'Ürün / seri numarası uyumsuz',
  'Servis ücreti kabul edilmedi',
  'Diğer',
] as const

function formatDateTime(value: string | null | undefined): string {
  if (!value) {
    return 'Belirlenmedi'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return 'Belirlenmedi'
  }

  return date.toLocaleString('tr-TR', {
    dateStyle: 'medium',
    timeStyle: 'short',
  })
}

function normalizeSearchText(value: string | null | undefined): string {
  return normalizeTechnicalServiceText(value)
    .replace(/[-\s\p{Punctuation}]+/gu, '')
}

function statusFilterLabel(status: FilterState['status']): string {
  switch (status) {
    case 'unassigned':
      return 'Atanmamış İşler'
    case 'today_installations':
      return 'Bugünkü Montajlar'
    case 'scheduled':
      return 'Randevulu'
    case 'Tamamlandı':
      return 'Tamamlandı'
    case 'İptal':
      return 'İptal'
    default:
      return 'Tümü'
  }
}

function mapApiRequest(request: ApiTechnicalServiceRequest): ServiceRequest {
  return {
    id: String(request.id),
    mrn: request.mrn,
    customer: request.customer_name,
    phone: request.customer_phone,
    city: request.customer_city,
    district: request.customer_district,
    product: request.product_name,
    model: request.product_model ?? '',
    serialNumber: request.serial_number ?? '',
    serviceType: request.service_type,
    priority: request.priority,
    technician: request.technician_name ?? 'Atanmadı',
    appointment: formatDateTime(request.scheduled_at),
    status: request.status,
    address: request.service_address,
    notes: request.description ?? request.resolution_notes ?? '',
    channel: request.source_channel ?? '',
    scheduledAt: request.scheduled_at ?? null,
    createdAt: request.created_at ?? null,
  }
}

export default function TechnicalService() {
  const [filters, setFilters] = useState<FilterState>(initialFilters)
  const [requests, setRequests] = useState<ServiceRequest[]>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [selectedListRequest, setSelectedListRequest] = useState<ServiceRequest | null>(null)
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [isDetailDialogOpen, setIsDetailDialogOpen] = useState(false)
  const [createForm, setCreateForm] = useState<NewRequestForm>(initialRequestForm)
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)
  const [summaryLoading, setSummaryLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [selectedEvents, setSelectedEvents] = useState<ApiTechnicalServiceEvent[]>([])
  const [selectedDetailRequest, setSelectedDetailRequest] = useState<ServiceRequest | null>(null)
  const [assignDialogOpen, setAssignDialogOpen] = useState(false)
  const [assignTechnicianOption, setAssignTechnicianOption] = useState('')
  const [assignOtherTechnician, setAssignOtherTechnician] = useState('')
  const [assignNote, setAssignNote] = useState('')
  const [assignLoading, setAssignLoading] = useState(false)
  const [assignError, setAssignError] = useState<string | null>(null)
  const [scheduleDate, setScheduleDate] = useState('')
  const [scheduleHour, setScheduleHour] = useState('')
  const [scheduleMinute, setScheduleMinute] = useState('')
  const [completeDialogOpen, setCompleteDialogOpen] = useState(false)
  const [completionReason, setCompletionReason] = useState('')
  const [completionOtherNote, setCompletionOtherNote] = useState('')
  const [completeLoading, setCompleteLoading] = useState(false)
  const [completeError, setCompleteError] = useState<string | null>(null)
  const [reopenDialogOpen, setReopenDialogOpen] = useState(false)
  const [reopenNote, setReopenNote] = useState('')
  const [reopenLoading, setReopenLoading] = useState(false)
  const [reopenError, setReopenError] = useState<string | null>(null)
  const [createLoading, setCreateLoading] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  const [summaryData, setSummaryData] = useState<SummaryResponse | null>(null)
  const selectedIdRef = useRef<string | null>(null)
  const detailRequestTokenRef = useRef(0)

  const loadRequests = async () => {
    setLoading(true)
    setError(null)

    try {
      const response = await apiRequest('/api/technical-service/requests')
      const items = Array.isArray(response.items) ? response.items : []
      setRequests(items.map(mapApiRequest))
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Teknik servis talepleri alınamadı.')
    } finally {
      setLoading(false)
    }
  }

  const loadSummary = async () => {
    setSummaryLoading(true)

    try {
      const response = await apiRequest('/api/technical-service/summary')
      setSummaryData(response as SummaryResponse)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Özet verisi alınamadı.')
    } finally {
      setSummaryLoading(false)
    }
  }

  useEffect(() => {
    selectedIdRef.current = selectedId
  }, [selectedId])

  const loadRequestDetail = async (id: string) => {
    const requestId = String(id)
    const requestToken = detailRequestTokenRef.current + 1
    detailRequestTokenRef.current = requestToken
    const expectedListRequest = selectedListRequest ?? requests.find((item) => item.id === requestId) ?? null
    const isCurrentRequest = () => detailRequestTokenRef.current === requestToken && selectedIdRef.current === requestId
    setDetailLoading(true)
    setDetailError(null)
    setSelectedDetailRequest(null)
    setSelectedEvents([])

    try {
      const response = await apiRequest(`/api/technical-service/requests/${id}`)
      if (!isCurrentRequest()) {
        return
      }

      const request = response.request
      if (!request) {
        setDetailError('Talep detayları bulunamadı.')
        setDetailLoading(false)
        return
      }

      const mappedDetail = mapApiRequest(request)
      const currentListRequest = expectedListRequest

      if (!currentListRequest || String(mappedDetail.id) !== requestId || mappedDetail.mrn !== currentListRequest.mrn) {
        console.error('Technical service detail mismatch', {
          selectedListRequest: currentListRequest,
          detail: mappedDetail,
        })
        setDetailError('Seçilen kayıt ile detay verisi eşleşmedi. Lütfen listeyi yenileyin.')
        setSelectedDetailRequest(null)
        setSelectedEvents([])
        setDetailLoading(false)
        return
      }

      if (!isCurrentRequest()) {
        return
      }

      setSelectedDetailRequest(mappedDetail)
      setSelectedEvents(Array.isArray(request?.events) ? request.events : [])
    } catch (caught) {
      if (!isCurrentRequest()) {
        return
      }

      setDetailError(caught instanceof Error ? caught.message : 'Talep detayları yüklenemedi.')
      setSelectedEvents([])
      setSelectedDetailRequest(null)
    } finally {
      if (!isCurrentRequest()) {
        return
      }
      setDetailLoading(false)
    }
  }

  useEffect(() => {
    void loadSummary()
  }, [])

  useEffect(() => {
    void loadRequests()
  }, [])

  useEffect(() => {
    if (!selectedId) {
      detailRequestTokenRef.current += 1
      setSelectedEvents([])
      setSelectedListRequest(null)
      setSelectedDetailRequest(null)
      setDetailError(null)
      setDetailLoading(false)
      return
    }

    void loadRequestDetail(selectedId)
  }, [selectedId])

  const filteredRequests = useMemo(() => {
    const search = normalizeSearchText(filters.search)
    const today = new Date()
    const todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate())
    const todayEnd = new Date(today.getFullYear(), today.getMonth(), today.getDate() + 1)

    const isTodayAppointment = (request: ServiceRequest) => {
      const scheduled = request.scheduledAt ? new Date(request.scheduledAt) : null
      return scheduled !== null && scheduled >= todayStart && scheduled < todayEnd
    }

    const isOpenRequest = (request: ServiceRequest) => request.status !== 'Tamamlandı' && request.status !== 'İptal'
    const isUnassignedRequest = (request: ServiceRequest) => !request.technician?.trim() || request.technician === 'Atanmadı'
    const hasScheduledAppointment = (request: ServiceRequest) => Boolean(request.scheduledAt)

    const compareRequests = (a: ServiceRequest, b: ServiceRequest) => {
      const aUnassigned = a.technician === 'Atanmadı' || a.technician.trim() === ''
      const bUnassigned = b.technician === 'Atanmadı' || b.technician.trim() === ''
      if (aUnassigned !== bUnassigned) {
        return aUnassigned ? -1 : 1
      }

      const aToday = isTodayAppointment(a)
      const bToday = isTodayAppointment(b)
      if (aToday !== bToday) {
        return aToday ? -1 : 1
      }

      const aCreated = a.createdAt ? new Date(a.createdAt).getTime() : 0
      const bCreated = b.createdAt ? new Date(b.createdAt).getTime() : 0
      return bCreated - aCreated
    }

    return requests
      .filter((request) => {
        const displayMrn = formatTechnicalServiceMrn(request)
        const values = [
          request.mrn,
          displayMrn,
          request.customer,
          request.phone,
          request.city,
          request.district,
          request.product,
          request.model,
          request.serialNumber,
          request.serviceType,
          request.status,
          request.priority,
          request.technician,
          request.appointment,
          request.scheduledAt,
          request.notes,
        ]

        const matchesSearch =
          !search ||
          values.some((value) => normalizeSearchText(value).includes(search))

        const matchesStatus = (() => {
          switch (filters.status) {
            case '':
              return true
            case 'unassigned':
              return isOpenRequest(request) && isUnassignedRequest(request)
            case 'today_installations':
              return request.serviceType === 'Montaj' && isTodayAppointment(request)
            case 'scheduled':
              return isOpenRequest(request) && hasScheduledAppointment(request)
            case 'Tamamlandı':
              return request.status === 'Tamamlandı'
            case 'İptal':
              return request.status === 'İptal'
            default:
              return true
          }
        })()

        return matchesSearch && matchesStatus
      })
      .sort(compareRequests)
  }, [filters, requests])

  const selectedRequest = selectedId
    ? requests.find((request) => request.id === selectedId) ?? null
    : null
  const modalRequest = selectedDetailRequest ?? selectedListRequest ?? selectedRequest
  const selectedListDisplayMrn = selectedListRequest ? formatTechnicalServiceMrn(selectedListRequest) : null
  const selectedDetailDisplayMrn = selectedDetailRequest ? formatTechnicalServiceMrn(selectedDetailRequest) : null
  const modalDisplayMrn = selectedListDisplayMrn ?? selectedDetailDisplayMrn
  const modalPayment = getServicePaymentInfo(modalRequest?.serviceType)

  const unassignedCount = requests.filter((request) => {
    const isOpen = request.status !== 'Tamamlandı' && request.status !== 'İptal'
    const isUnassigned = !request.technician?.trim() || request.technician === 'Atanmadı'
    return isOpen && isUnassigned
  }).length
  const activeStatusFilterLabel = statusFilterLabel(filters.status)

  const summaryItems: SummaryItem[] = [
    {
      label: 'Açık Talep',
      value: summaryLoading ? '...' : String(summaryData?.ongoing_requests ?? 0),
      tone: 'accent',
      description: 'Henüz tamamlanmamış teknik servis talepleri',
    },
    {
      label: 'Bugünkü Randevu',
      value: summaryLoading ? '...' : String(summaryData?.scheduled_today ?? 0),
      tone: 'warning',
      description: 'Bugün planlanmış servis ziyaretleri',
    },
    {
      label: 'Tamamlanan İş',
      value: summaryLoading ? '...' : String(summaryData?.status_counts?.Tamamlandı ?? 0),
      tone: 'default',
      description: 'Bugüne kadar kapatılmış talepler',
    },
    {
      label: 'Atanmamış Talep',
      value: loading ? '...' : String(unassignedCount),
      tone: 'default',
      description: 'Usta atanmamış talepler',
    },
  ]

  const handleCreateChange = (field: keyof NewRequestForm, value: string) => {
    setCreateForm((current) => ({ ...current, [field]: value }))
  }

  const handleCreateReset = () => {
    setCreateForm(initialRequestForm)
  }

  const handleAssignReset = () => {
    setAssignTechnicianOption('')
    setAssignOtherTechnician('')
    setAssignNote('')
    setScheduleDate('')
    setScheduleHour('')
    setScheduleMinute('')
    setAssignError(null)
  }

  const handleCompleteReset = () => {
    setCompletionReason('')
    setCompletionOtherNote('')
    setCompleteError(null)
  }

  const handleReopenReset = () => {
    setReopenNote('')
    setReopenError(null)
  }

  const handleReopenSubmit = async () => {
    if (!selectedId) {
      return
    }

    setReopenLoading(true)
    setReopenError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/status`, {
        method: 'POST',
        body: JSON.stringify({
          status: 'Yeni',
          note: reopenNote || null,
        }),
      })

      setReopenDialogOpen(false)
      handleReopenReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setReopenError(caught instanceof Error ? caught.message : 'Talep yeniden açma işlemi başarısız oldu.')
    } finally {
      setReopenLoading(false)
    }
  }

  const handleAssignSubmit = async () => {
    if (!selectedId) {
      return
    }

    const selectedTechnician = assignTechnicianOption === 'Diğer' ? assignOtherTechnician.trim() : assignTechnicianOption
    if (!selectedTechnician) {
      setAssignError('Lütfen bir usta seçin veya manuel isim girin.')
      return
    }

    if (!scheduleDate || !scheduleHour || !scheduleMinute) {
      setAssignError('Lütfen randevu tarihi ve saatini seçin.')
      return
    }

    setAssignLoading(true)
    setAssignError(null)

    try {
      const scheduledAt = `${scheduleDate}T${scheduleHour}:${scheduleMinute}:00`

      await apiRequest(`/api/technical-service/requests/${selectedId}/assign`, {
        method: 'POST',
        body: JSON.stringify({
          technician_name: selectedTechnician,
          note: assignTechnicianOption === 'Diğer' ? assignNote || null : null,
        }),
      })

      await apiRequest(`/api/technical-service/requests/${selectedId}`, {
        method: 'PATCH',
        body: JSON.stringify({
          scheduled_at: scheduledAt,
          schedule_note: assignNote || null,
        }),
      })

      setAssignDialogOpen(false)
      handleAssignReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setAssignError(caught instanceof Error ? caught.message : 'Usta atama işlemi başarısız oldu.')
    } finally {
      setAssignLoading(false)
    }
  }

  const handleCompleteSubmit = async () => {
    if (!selectedId) {
      return
    }

    if (!completionReason) {
      setCompleteError('Lütfen bir kapanış nedeni seçin.')
      return
    }

    const isOtherReason = completionReason === 'Diğer'
    const notes = isOtherReason ? completionOtherNote.trim() : completionReason
    if (isOtherReason && !notes) {
      setCompleteError('Lütfen açıklama girin.')
      setCompleteLoading(false)
      return
    }

    const nextStatus = completionReason === 'Montaj tamamlandı' ? 'Tamamlandı' : 'İptal'

    setCompleteLoading(true)
    setCompleteError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/status`, {
        method: 'POST',
        body: JSON.stringify({
          status: nextStatus,
          resolution_notes: notes || null,
        }),
      })

      setCompleteDialogOpen(false)
      handleCompleteReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setCompleteError(caught instanceof Error ? caught.message : 'Talep kapatma işlemi başarısız oldu.')
    } finally {
      setCompleteLoading(false)
    }
  }

  const handleCreateSubmit = async () => {
    setCreateLoading(true)
    setCreateError(null)

    try {
      const response = await apiRequest('/api/technical-service/requests', {
        method: 'POST',
        body: JSON.stringify({
          customer_name: createForm.customer,
          customer_phone: createForm.phone,
          customer_city: createForm.city,
          customer_district: createForm.district,
          service_address: createForm.address,
          product_name: createForm.product,
          serial_number: createForm.serialNumber || null,
          service_type: createForm.serviceType,
          description: createForm.notes || null,
          source_channel: 'panel',
        }),
      })

      const createdRequest = mapApiRequest(response.request ?? response)
      setRequests((current) => [createdRequest, ...current])
      setSelectedId(createdRequest.id)
      setIsDialogOpen(false)
      handleCreateReset()
      void loadSummary()
    } catch (caught) {
      setCreateError(caught instanceof Error ? caught.message : 'Teknik servis talebi kaydedilemedi.')
    } finally {
      setCreateLoading(false)
    }
  }

  return (
    <>
      <Head title="Teknik Servis" />

      <div className="mx-auto w-full max-w-[2200px] space-y-6 px-4 py-6 md:px-6 lg:px-12">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <div>
            <Heading
              title="Teknik Servis"
              description="Montaj ve servis taleplerini takip edin, randevu ve talep detaylarını görüntüleyin."
            />
          </div>
          <Dialog open={isDialogOpen} onOpenChange={setIsDialogOpen}>
            <DialogTrigger asChild>
              <Button type="button">Yeni Servis Talebi</Button>
            </DialogTrigger>
            <DialogContent className="max-w-2xl">
              <DialogHeader>
                <DialogTitle>Yeni Servis Talebi</DialogTitle>
                <DialogDescription>
                  Yeni servis talebi bu ekran üzerinden backend API aracılığıyla kaydedilecektir.
                </DialogDescription>
              </DialogHeader>
              {createError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {createError}
                </div>
              ) : null}
              <div className="grid gap-4 pt-2">
                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Müşteri adı
                    <Input
                      value={createForm.customer}
                      onChange={(event) => handleCreateChange('customer', event.target.value)}
                      placeholder="Müşteri adı"
                    />
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Telefon
                    <Input
                      value={createForm.phone}
                      onChange={(event) => handleCreateChange('phone', event.target.value)}
                      placeholder="Telefon"
                    />
                  </label>
                </div>

                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    İl
                    <Input
                      value={createForm.city}
                      onChange={(event) => handleCreateChange('city', event.target.value)}
                      placeholder="İl"
                    />
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    İlçe
                    <Input
                      value={createForm.district}
                      onChange={(event) => handleCreateChange('district', event.target.value)}
                      placeholder="İlçe"
                    />
                  </label>
                </div>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Adres
                  <textarea
                    value={createForm.address}
                    onChange={(event) => handleCreateChange('address', event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="Adres"
                  />
                </label>

                <div className="grid gap-4 sm:grid-cols-2">
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Ürün
                    <Input
                      value={createForm.product}
                      onChange={(event) => handleCreateChange('product', event.target.value)}
                      placeholder="Ürün"
                    />
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Seri No
                    <Input
                      value={createForm.serialNumber}
                      onChange={(event) => handleCreateChange('serialNumber', event.target.value)}
                      placeholder="Seri No"
                    />
                  </label>
                </div>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Servis Tipi
                  <select
                    value={createForm.serviceType}
                    onChange={(event) => handleCreateChange('serviceType', event.target.value)}
                    className="border-input h-9 rounded-md border bg-transparent px-3 text-sm outline-none transition focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px]"
                  >
                    <option value="">Seçiniz</option>
                    <option value="Montaj">Montaj</option>
                    <option value="Arıza">Arıza</option>
                    <option value="Kontrol">Kontrol</option>
                  </select>
                </label>

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Not
                  <textarea
                    value={createForm.notes}
                    onChange={(event) => handleCreateChange('notes', event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="Talep notu"
                  />
                </label>
              </div>
              <DialogFooter>
                <Button variant="outline" type="button" onClick={() => setIsDialogOpen(false)}>
                  İptal
                </Button>
                <Button type="button" onClick={handleCreateSubmit} disabled={createLoading}>
                  {createLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={assignDialogOpen} onOpenChange={(open) => {
            setAssignDialogOpen(open)
            if (!open) handleAssignReset()
          }}>
            <DialogContent className="max-w-lg max-h-[92vh] overflow-y-auto">
              <DialogClose asChild>
                <button
                  type="button"
                  className="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                >
                  ×
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Usta ata ve randevu ver</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için ${modalRequest?.customer} adına usta atanıyor.` : 'Seçili talep yok.'}
                </DialogDescription>
                {modalRequest?.serviceType ? (
                  <p className="text-sm leading-6 text-slate-600">
                    Bu talep {modalRequest.serviceType} işlemidir. Ustaya / servise ödenecek tutar: {modalPayment.technicianAmountLabel}
                  </p>
                ) : null}
              </DialogHeader>

              {assignError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {assignError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <fieldset className="grid gap-3">
                  <legend className="text-sm font-medium text-slate-700">Usta / Çilingir adı</legend>
                  <div className="grid gap-2">
                    {TECHNICIANS.map((technician) => (
                      <label
                        key={technician}
                        className="flex cursor-pointer items-center rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300"
                      >
                        <input
                          type="radio"
                          name="assignTechnician"
                          value={technician}
                          checked={assignTechnicianOption === technician}
                          onChange={() => setAssignTechnicianOption(technician)}
                          className="mr-3 h-4 w-4 accent-primary"
                        />
                        {technician}
                      </label>
                    ))}
                  </div>
                </fieldset>

                {assignTechnicianOption === 'Diğer' ? (
                  <div className="grid gap-4">
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Manuel usta adı
                      <Input
                        value={assignOtherTechnician}
                        onChange={(event) => setAssignOtherTechnician(event.target.value)}
                        placeholder="Usta adı"
                      />
                    </label>

                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Not / açıklama
                      <textarea
                        value={assignNote}
                        onChange={(event) => setAssignNote(event.target.value)}
                        className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                        placeholder="Not / açıklama"
                      />
                    </label>
                  </div>
                ) : null}

                <div className="grid gap-2 text-sm font-medium text-slate-700">
                  <label>Randevu tarihi</label>
                  <Input
                    type="date"
                    value={scheduleDate}
                    onChange={(event) => setScheduleDate(event.target.value)}
                  />
                </div>

                <div className="grid gap-2 text-sm font-medium text-slate-700 sm:grid-cols-[1fr_1fr]">
                  <label className="grid gap-2">
                    Saat
                    <select
                      value={scheduleHour}
                      onChange={(event) => setScheduleHour(event.target.value)}
                      className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    >
                      <option value="">Saat</option>
                      {Array.from({ length: 24 }, (_, index) => {
                        const value = String(index).padStart(2, '0')
                        return (
                          <option key={value} value={value}>
                            {value}
                          </option>
                        )
                      })}
                    </select>
                  </label>
                  <label className="grid gap-2">
                    Dakika
                    <select
                      value={scheduleMinute}
                      onChange={(event) => setScheduleMinute(event.target.value)}
                      className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    >
                      <option value="">Dakika</option>
                      {['00', '15', '30', '45'].map((minute) => (
                        <option key={minute} value={minute}>
                          {minute}
                        </option>
                      ))}
                    </select>
                  </label>
                </div>
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleAssignReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button
                  type="button"
                  onClick={handleAssignSubmit}
                  disabled={
                    assignLoading ||
                    !assignTechnicianOption ||
                    (assignTechnicianOption === 'Diğer' && !assignOtherTechnician.trim()) ||
                    !scheduleDate ||
                    !scheduleHour ||
                    !scheduleMinute
                  }
                >
                  {assignLoading ? 'Kaydediliyor...' : 'Usta Ata ve Randevu Ver'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={completeDialogOpen} onOpenChange={(open) => {
            setCompleteDialogOpen(open)
            if (!open) handleCompleteReset()
          }}>
            <DialogContent className="max-w-lg max-h-[92vh] overflow-y-auto">
              <DialogClose asChild>
                <button
                  type="button"
                  className="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                >
                  ×
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Talebi kapat / iptal et</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için ${modalRequest?.customer} talebinin sonucunu seçin.` : 'Seçili talep yok.'}
                </DialogDescription>
                {modalRequest?.serviceType ? (
                  <p className="text-sm leading-6 text-slate-600">
                    Müşteriden alınacak tutar: {modalPayment.customerAmountLabel}
                  </p>
                ) : null}
              </DialogHeader>

              {completeError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {completeError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <fieldset className="grid gap-3">
                  <legend className="text-sm font-medium text-slate-700">Kapanış / iptal nedeni</legend>
                  <div className="grid gap-2">
                    {CLOSURE_REASONS.map((reason) => (
                      <label
                        key={reason}
                        className="flex cursor-pointer items-center rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300"
                      >
                        <input
                          type="radio"
                          name="completionReason"
                          value={reason}
                          checked={completionReason === reason}
                          onChange={() => setCompletionReason(reason)}
                          className="mr-3 h-4 w-4 accent-primary"
                        />
                        {reason}
                      </label>
                    ))}
                  </div>
                </fieldset>

                {completionReason === 'Diğer' ? (
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    Açıklama
                    <textarea
                      value={completionOtherNote}
                      onChange={(event) => setCompletionOtherNote(event.target.value)}
                      className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                      placeholder="Açıklama"
                    />
                  </label>
                ) : null}
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleCompleteReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button type="button" onClick={handleCompleteSubmit} disabled={completeLoading || !completionReason || (completionReason === 'Diğer' && !completionOtherNote.trim())}>
                  {completeLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={reopenDialogOpen} onOpenChange={(open) => {
            setReopenDialogOpen(open)
            if (!open) handleReopenReset()
          }}>
            <DialogContent className="max-w-lg">
              <DialogClose asChild>
                <button
                  type="button"
                  className="absolute right-4 top-4 inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                >
                  ×
                </button>
              </DialogClose>
              <DialogHeader>
                <DialogTitle>Talebi yeniden aç</DialogTitle>
                <DialogDescription>
                  {modalDisplayMrn ? `${modalDisplayMrn} için ${modalRequest?.customer} talebi yeniden açılacak.` : 'Seçili talep yok.'}
                </DialogDescription>
              </DialogHeader>

              {reopenError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {reopenError}
                </div>
              ) : null}

              <label className="grid gap-2 text-sm font-medium text-slate-700">
                Yeniden açma nedeni / açıklama
                <textarea
                  value={reopenNote}
                  onChange={(event) => setReopenNote(event.target.value)}
                  className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  placeholder="Yeniden açma nedeni"
                />
              </label>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleReopenReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button type="button" onClick={handleReopenSubmit} disabled={reopenLoading}>
                  {reopenLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>
        </div>

        <ServiceSummaryCards items={summaryItems} />

        <ServiceFilters
          filters={filters}
          onChange={setFilters}
          onReset={() => setFilters(initialFilters)}
        />

        <Dialog open={isDetailDialogOpen} onOpenChange={(open) => {
          if (!open) {
            detailRequestTokenRef.current += 1
            setIsDetailDialogOpen(false)
            setSelectedId(null)
            setSelectedEvents([])
            setSelectedDetailRequest(null)
            setDetailError(null)
          }
        }}>
          <DialogContent className="w-[calc(100vw-16px)] sm:w-[calc(100vw-24px)] sm:max-w-[900px] h-[96dvh] sm:h-[90vh] max-h-[96dvh] sm:max-h-[90vh] p-0 overflow-hidden flex flex-col rounded-[20px]">
            <div className="flex h-full min-h-[420px] flex-col overflow-hidden bg-white">
              <DialogHeader className="sticky top-0 z-20 border-b border-slate-200 bg-white px-4 py-4 md:px-6 md:py-5">
                <div className="flex items-start justify-between gap-4">
                  <div className="min-w-0 space-y-2">
                    <DialogTitle className="text-base font-semibold text-slate-900">Talep Detayı</DialogTitle>
                    <DialogDescription className="text-sm text-slate-600">
                      Seçili talebin MRN, müşteri, durum ve öncelik bilgilerini kontrol edin.
                    </DialogDescription>
                  </div>
                  <DialogClose asChild>
                    <button
                      type="button"
                      className="inline-flex h-10 w-10 items-center justify-center rounded-full text-slate-500 transition hover:bg-slate-100"
                      aria-label="Talep detay modalini kapat"
                    >
                      ×
                    </button>
                  </DialogClose>
                </div>
                <div className="mt-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                  <span className="text-sm font-semibold text-slate-900">{modalDisplayMrn ?? modalRequest?.mrn ?? 'Seçili talep yok'}</span>
                  <span className="text-sm text-slate-600 truncate">Müşteri: {modalRequest?.customer ?? '-'}</span>
                  <span className="text-sm text-slate-600 truncate">Durum: {modalRequest?.status ?? '-'}</span>
                  <span className="text-sm text-slate-600 truncate">Öncelik: {modalRequest?.priority ?? '-'}</span>
                </div>
              </DialogHeader>

              <div className="flex-1 min-h-0 overflow-y-auto overscroll-contain px-4 py-4 pb-28 md:px-6 md:py-5 md:pb-32">
                {detailLoading ? (
                  <div className="rounded-3xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                    Detay yükleniyor...
                  </div>
                ) : detailError ? (
                  <div className="rounded-3xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">
                    {detailError}
                  </div>
                ) : selectedDetailRequest ? (
                  <ServiceRequestDetails
                    request={selectedDetailRequest}
                    displayMrn={modalDisplayMrn ?? undefined}
                    events={selectedEvents}
                    loading={detailLoading}
                    error={detailError}
                    onAssign={() => setAssignDialogOpen(true)}
                    onComplete={() => setCompleteDialogOpen(true)}
                    onReopen={() => setReopenDialogOpen(true)}
                  />
                ) : (
                  <div className="rounded-3xl border border-slate-200 bg-slate-50 p-6 text-sm text-slate-500">
                    Seçilen kayıt için detay bekleniyor...
                  </div>
                )}
              </div>
            </div>
          </DialogContent>
        </Dialog>

        <div className="space-y-6">
          <div className="space-y-4">
            <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-semibold text-slate-900">Montaj / Servis Talepleri</p>
                  <p className="mt-1 text-sm text-slate-500">
                    Filtre: {activeStatusFilterLabel} • Toplam {filteredRequests.length} kayıt bulundu.
                  </p>
                </div>
                <div className="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">
                  Liste
                </div>
              </div>
            </div>

            {error ? (
              <div className="rounded-3xl border border-rose-200 bg-rose-50 p-6 text-sm text-rose-700">
                {error}
              </div>
            ) : loading ? (
              <div className="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                Teknik servis talepleri yükleniyor...
              </div>
            ) : requests.length === 0 ? (
              <div className="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                Henüz teknik servis talebi yok.
              </div>
            ) : filteredRequests.length === 0 ? (
              <div className="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                Filtreye uygun teknik servis talebi bulunamadı.
              </div>
            ) : (
              <ServiceRequestTable
                requests={filteredRequests}
                selectedId={selectedRequest?.id ?? ''}
                onSelect={(request) => {
                  detailRequestTokenRef.current += 1
                  setSelectedListRequest(request)
                  setSelectedDetailRequest(null)
                  setSelectedEvents([])
                  setDetailError(null)
                  setSelectedId(request.id)
                  setIsDetailDialogOpen(true)
                }}
              />
            )}
          </div>

        </div>
      </div>
    </>
  )
}
