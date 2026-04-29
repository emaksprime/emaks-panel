import { Head } from '@inertiajs/react'
import { useEffect, useMemo, useState } from 'react'
import { Button } from '@/components/ui/button'
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import Heading from '@/components/heading'
import { apiRequest } from '@/lib/api'
import { ServiceSummaryCards } from '@/components/technical-service/ServiceSummaryCards'
import { ServiceFilters } from '@/components/technical-service/ServiceFilters'
import { ServiceRequestDetails } from '@/components/technical-service/ServiceRequestDetails'
import { ServiceRequestTable } from '@/components/technical-service/ServiceRequestTable'
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
  risk_level: string
  technician_name?: string | null
  scheduled_at?: string | null
  sla_due_at?: string | null
  description?: string | null
  resolution_notes?: string | null
  source_channel?: string | null
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
  risk_level_counts: Record<string, number>
  scheduled_today: number
}

const initialFilters: FilterState = {
  search: '',
  serviceType: '',
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

function formatSla(value: string | null | undefined): string {
  if (!value) {
    return 'Belirlenmedi'
  }

  const date = new Date(value)

  if (Number.isNaN(date.getTime())) {
    return 'Belirlenmedi'
  }

  return date.toLocaleDateString('tr-TR')
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
    sla: formatSla(request.sla_due_at),
    address: request.service_address,
    notes: request.description ?? request.resolution_notes ?? '',
    riskLevel: request.risk_level,
    channel: request.source_channel ?? '',
  }
}

export default function TechnicalService() {
  const [filters, setFilters] = useState<FilterState>(initialFilters)
  const [requests, setRequests] = useState<ServiceRequest[]>([])
  const [selectedId, setSelectedId] = useState<string | null>(null)
  const [isDialogOpen, setIsDialogOpen] = useState(false)
  const [createForm, setCreateForm] = useState<NewRequestForm>(initialRequestForm)
  const [loading, setLoading] = useState(true)
  const [detailLoading, setDetailLoading] = useState(false)
  const [summaryLoading, setSummaryLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [detailError, setDetailError] = useState<string | null>(null)
  const [selectedEvents, setSelectedEvents] = useState<ApiTechnicalServiceEvent[]>([])
  const [assignDialogOpen, setAssignDialogOpen] = useState(false)
  const [assignTechnician, setAssignTechnician] = useState('')
  const [assignNote, setAssignNote] = useState('')
  const [assignLoading, setAssignLoading] = useState(false)
  const [assignError, setAssignError] = useState<string | null>(null)
  const [scheduleDialogOpen, setScheduleDialogOpen] = useState(false)
  const [scheduleDate, setScheduleDate] = useState('')
  const [scheduleHour, setScheduleHour] = useState('')
  const [scheduleMinute, setScheduleMinute] = useState('')
  const [scheduleNote, setScheduleNote] = useState('')
  const [scheduleLoading, setScheduleLoading] = useState(false)
  const [scheduleError, setScheduleError] = useState<string | null>(null)
  const [completeDialogOpen, setCompleteDialogOpen] = useState(false)
  const [completionNote, setCompletionNote] = useState('')
  const [completeLoading, setCompleteLoading] = useState(false)
  const [completeError, setCompleteError] = useState<string | null>(null)
  const [reopenDialogOpen, setReopenDialogOpen] = useState(false)
  const [reopenNote, setReopenNote] = useState('')
  const [reopenLoading, setReopenLoading] = useState(false)
  const [reopenError, setReopenError] = useState<string | null>(null)
  const [createLoading, setCreateLoading] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  const [summaryData, setSummaryData] = useState<SummaryResponse | null>(null)

  const loadRequests = async () => {
    setLoading(true)
    setError(null)

    const params = new URLSearchParams()

    if (filters.search.trim()) {
      params.append('search', filters.search.trim())
    }

    if (filters.status) {
      params.append('status', filters.status)
    }

    if (filters.serviceType) {
      params.append('service_type', filters.serviceType)
    }

    try {
      const response = await apiRequest(
        `/api/technical-service/requests${params.toString() ? `?${params.toString()}` : ''}`,
      )

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

  const loadRequestDetail = async (id: string) => {
    setDetailLoading(true)
    setDetailError(null)

    try {
      const response = await apiRequest(`/api/technical-service/requests/${id}`)
      const request = response.request

      setSelectedEvents(Array.isArray(request?.events) ? request.events : [])
    } catch (caught) {
      setDetailError(caught instanceof Error ? caught.message : 'Talep detayları yüklenemedi.')
      setSelectedEvents([])
    } finally {
      setDetailLoading(false)
    }
  }

  useEffect(() => {
    void loadSummary()
  }, [])

  useEffect(() => {
    void loadRequests()
  }, [filters])

  useEffect(() => {
    if (selectedId !== null && requests.some((request) => request.id === selectedId)) {
      return
    }

    setSelectedId(requests[0]?.id ?? null)
  }, [requests, selectedId])

  useEffect(() => {
    if (!selectedId) {
      setSelectedEvents([])
      setDetailError(null)
      return
    }

    void loadRequestDetail(selectedId)
  }, [selectedId])

  const filteredRequests = useMemo(() => {
    return requests.filter((request) => {
      const search = filters.search.toLowerCase().trim()
      const matchesSearch =
        !search ||
        [request.mrn, request.customer, request.phone, request.serialNumber].some((value) =>
          value.toLowerCase().includes(search),
        )
      const matchesType = !filters.serviceType || request.serviceType === filters.serviceType
      const matchesStatus = !filters.status || request.status === filters.status

      return matchesSearch && matchesType && matchesStatus
    })
  }, [filters, requests])

  const selectedRequest =
    filteredRequests.find((request) => request.id === selectedId) ?? filteredRequests[0] ?? null

  useEffect(() => {
    if (!selectedId || filteredRequests.some((request) => request.id === selectedId)) {
      return
    }

    setSelectedId(filteredRequests[0]?.id ?? null)
  }, [filteredRequests, selectedId])

  const unassignedCount = requests.filter((request) => !request.technician || request.technician === 'Atanmadı').length

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
      label: 'SLA Riski',
      value: summaryLoading
        ? '...'
        : String((summaryData?.risk_level_counts?.Yüksek ?? 0) + (summaryData?.risk_level_counts?.Kritik ?? 0)),
      tone: 'warning',
      description: 'Yüksek ve kritik SLA riski olan talepler',
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
    setAssignTechnician('')
    setAssignNote('')
    setAssignError(null)
  }

  const handleScheduleReset = () => {
    setScheduleDate('')
    setScheduleHour('')
    setScheduleMinute('')
    setScheduleNote('')
    setScheduleError(null)
  }

  const handleCompleteReset = () => {
    setCompletionNote('')
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

  const handleScheduleSubmit = async () => {
    if (!selectedId) {
      return
    }

    setScheduleLoading(true)
    setScheduleError(null)

    try {
      const scheduledAt = `${scheduleDate}T${scheduleHour}:${scheduleMinute}:00`

      await apiRequest(`/api/technical-service/requests/${selectedId}`, {
        method: 'PATCH',
        body: JSON.stringify({
          scheduled_at: scheduledAt,
          schedule_note: scheduleNote || null,
        }),
      })

      setScheduleDialogOpen(false)
      handleScheduleReset()
      await loadRequests()
      await loadSummary()
      await loadRequestDetail(selectedId)
    } catch (caught) {
      setScheduleError(caught instanceof Error ? caught.message : 'Randevu planlama işlemi başarısız oldu.')
    } finally {
      setScheduleLoading(false)
    }
  }

  const handleAssignSubmit = async () => {
    if (!selectedId) {
      return
    }

    setAssignLoading(true)
    setAssignError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/assign`, {
        method: 'POST',
        body: JSON.stringify({
          technician_name: assignTechnician,
          note: assignNote || null,
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

    setCompleteLoading(true)
    setCompleteError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/status`, {
        method: 'POST',
        body: JSON.stringify({
          status: 'Tamamlandı',
          resolution_notes: completionNote || null,
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
              description="Montaj ve servis taleplerini takip edin, SLA uyarılarını izleyin ve talep detaylarını görüntüleyin."
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
                <DialogTitle>Usta ata</DialogTitle>
                <DialogDescription>
                  Seçili talebe bir teknisyen atayın ve dilerseniz not ekleyin.
                </DialogDescription>
              </DialogHeader>

              {assignError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {assignError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Usta / Çilingir adı
                  <Input
                    value={assignTechnician}
                    onChange={(event) => setAssignTechnician(event.target.value)}
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

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleAssignReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button
                  type="button"
                  onClick={handleAssignSubmit}
                  disabled={assignLoading || !assignTechnician.trim()}
                >
                  {assignLoading ? 'Atanıyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={scheduleDialogOpen} onOpenChange={(open) => {
            setScheduleDialogOpen(open)
            if (!open) handleScheduleReset()
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
                <DialogTitle>Randevu planla</DialogTitle>
                <DialogDescription>
                  Seçili talebe randevu tarihi ve saati girin, isteğe bağlı not ekleyin.
                </DialogDescription>
              </DialogHeader>

              {scheduleError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {scheduleError}
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
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
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Not / açıklama
                  <textarea
                    value={scheduleNote}
                    onChange={(event) => setScheduleNote(event.target.value)}
                    className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                    placeholder="Not / açıklama"
                  />
                </label>
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleScheduleReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button
                  type="button"
                  onClick={handleScheduleSubmit}
                  disabled={scheduleLoading || !scheduleDate || !scheduleHour || !scheduleMinute}
                >
                  {scheduleLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={completeDialogOpen} onOpenChange={(open) => {
            setCompleteDialogOpen(open)
            if (!open) handleCompleteReset()
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
                <DialogTitle>Talebi kapat</DialogTitle>
                <DialogDescription>
                  Bu talebi tamamlandı olarak işaretleyin ve çözüm notu ekleyin.
                </DialogDescription>
              </DialogHeader>

              {completeError ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">
                  {completeError}
                </div>
              ) : null}

              <label className="grid gap-2 text-sm font-medium text-slate-700">
                Kapanış notu / çözüm açıklaması
                <textarea
                  value={completionNote}
                  onChange={(event) => setCompletionNote(event.target.value)}
                  className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  placeholder="Kapanış notu"
                />
              </label>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleCompleteReset}>
                    İptal
                  </Button>
                </DialogClose>
                    <Button type="button" onClick={handleCompleteSubmit} disabled={completeLoading}>
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
                  Bu talebi Yeni statüsüne geri almak için bir neden girin.
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

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1fr)_380px]">
          <div className="space-y-4">
            <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex flex-wrap items-center justify-between gap-3">
                <div>
                  <p className="text-sm font-semibold text-slate-900">Montaj / Servis Talepleri</p>
                  <p className="mt-1 text-sm text-slate-500">Toplam {filteredRequests.length} kayıt bulundu.</p>
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
            ) : filteredRequests.length === 0 ? (
              <div className="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                Henüz teknik servis talebi yok.
              </div>
            ) : (
              <ServiceRequestTable
                requests={filteredRequests}
                selectedId={selectedRequest?.id ?? ''}
                onSelect={(request) => setSelectedId(request.id)}
              />
            )}
          </div>

          <div className="xl:self-start xl:sticky xl:top-28 xl:max-w-[400px]">
            {selectedRequest ? (
              <ServiceRequestDetails
                request={selectedRequest}
                events={selectedEvents}
                loading={detailLoading}
                error={detailError}
                onAssign={() => setAssignDialogOpen(true)}
                onSchedule={() => setScheduleDialogOpen(true)}
                onComplete={() => setCompleteDialogOpen(true)}
                onReopen={() => setReopenDialogOpen(true)}
              />
            ) : (
              <div className="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-500">
                Seçili talep bulunamadı.
              </div>
            )}
          </div>
        </div>
      </div>
    </>
  )
}
