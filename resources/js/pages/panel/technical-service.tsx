import { Head, Link } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Heading from '@/components/heading'
import { DateTimeFields } from '@/components/technical-service/DateTimeFields'
import { ServiceFilters } from '@/components/technical-service/ServiceFilters'
import { ServiceRequestDetails } from '@/components/technical-service/ServiceRequestDetails'
import { ServiceRequestTable } from '@/components/technical-service/ServiceRequestTable'
import { ServiceSummaryCards } from '@/components/technical-service/ServiceSummaryCards'
import {
  findProvinceByName,
  getDistrictOptionsForProvince,
  haversineKm,
  normalizeDistrictName,
  normalizeProvinceName,
  normalizeTurkishLocation,
  TURKEY_PROVINCES,
} from '@/components/technical-service/turkey-locations'
import type {
  MikroMountCheckResult,
  MikroSerialHistoryResponse,
  ServiceFilters as FilterState,
  ServiceRequest,
  ServiceTechnician,
  SummaryItem,
  WarrantySerialResponse,
} from '@/components/technical-service/types'
import {
  calculateTravelPreview,
  formatTechnicalServiceDateTime,
  formatTechnicalServiceMrn,
  getServicePaymentInfo,
  normalizeTechnicalServiceText,
  toTechnicalServiceDateTimeInputValue,
} from '@/components/technical-service/utils'
import { Button } from '@/components/ui/button'
import { Dialog, DialogClose, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle, DialogTrigger } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

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
  technical_service_technician_id?: number | string | null
  technician_name?: string | null
  scheduled_at?: string | null
  completed_at?: string | null
  description?: string | null
  resolution_notes?: string | null
  source_channel?: string | null
  created_at?: string | null
  travel_round_trip_km?: number | string | null
  travel_billable_km?: number | string | null
  travel_fee_amount?: number | string | null
  travel_calculation_source?: string | null
  travel_calculated_at?: string | null
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

const selectClassName = 'h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]'

const normalizeFormCity = (value: string | null | undefined) => normalizeProvinceName(value) ?? String(value ?? '').trim()
const normalizeFormDistrict = (city: string | null | undefined, value: string | null | undefined) =>
  normalizeDistrictName(city, value) ?? String(value ?? '').trim()

const CLOSURE_REASONS = [
  'Montaj tamamlandı',
  'Müşterinin kapısı uygun değildi',
  'Müşteri siparişi iptal etti',
  'Müşteri randevuya gelmedi / evde yoktu',
  'Ürün / seri numarası uyumsuz',
  'Servis ücreti kabul edilmedi',
  'Diğer',
] as const

const REOPEN_REASONS = [
  'Yanlışlıkla tamamlandı',
  'Eksik fotoğraf / belge',
  'Müşteri onayı hatası',
  'Usta yanlış kapattı',
  'Operasyon düzeltmesi',
  'Diğer',
] as const

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

function parseNullableNumber(value: number | string | null | undefined): number | null {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const parsed = typeof value === 'number' ? value : Number(value)

  return Number.isFinite(parsed) ? parsed : null
}

function technicianDisplayName(technician: ServiceTechnician): string {
  return [technician.first_name, technician.last_name].filter(Boolean).join(' ').trim() || technician.name
}

function normalizeLocationText(value: string | null | undefined): string {
  return normalizeTurkishLocation(value)
}

type TechnicianMatch = {
  technician: ServiceTechnician
  badge: 'Aynı ilçe' | 'Aynı il' | 'Yakın il / diğer'
  rank: number
  distanceKm: number | null
  sameCity: boolean
}

function technicianMatchInfo(technician: ServiceTechnician, request: ServiceRequest | null): TechnicianMatch {
  const technicianCity = normalizeLocationText(technician.city)
  const technicianDistrict = normalizeLocationText(technician.district)
  const requestCity = normalizeLocationText(request?.city)
  const requestDistrict = normalizeLocationText(request?.district)
  const technicianProvince = findProvinceByName(technician.city)
  const requestProvince = findProvinceByName(request?.city)
  const technicianLat = parseNullableNumber(technician.start_latitude ?? technician.latitude) ?? technicianProvince?.latitude ?? null
  const technicianLng = parseNullableNumber(technician.start_longitude ?? technician.longitude) ?? technicianProvince?.longitude ?? null
  const requestLat = requestProvince?.latitude ?? null
  const requestLng = requestProvince?.longitude ?? null
  const sameCity = technicianCity !== '' && technicianCity === requestCity
  const sameDistrict = sameCity && technicianDistrict !== '' && technicianDistrict === requestDistrict
  const distanceKm = haversineKm(technicianLat, technicianLng, requestLat, requestLng)

  if (sameDistrict) {
    return { technician, badge: 'Aynı ilçe', rank: 0, distanceKm, sameCity }
  }

  if (sameCity) {
    return { technician, badge: 'Aynı il', rank: 1, distanceKm, sameCity }
  }

  return { technician, badge: 'Yakın il / diğer', rank: 2, distanceKm, sameCity }
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
    technicianId: request.technical_service_technician_id === null || request.technical_service_technician_id === undefined
      ? null
      : String(request.technical_service_technician_id),
    technician: request.technician_name ?? 'Atanmadı',
    appointment: formatTechnicalServiceDateTime(request.scheduled_at, 'Belirlenmedi'),
    status: request.status,
    address: request.service_address,
    notes: request.description ?? request.resolution_notes ?? '',
    channel: request.source_channel ?? '',
    scheduledAt: request.scheduled_at ?? null,
    createdAt: request.created_at ?? null,
    completedAt: request.completed_at ?? null,
    travelRoundTripKm: parseNullableNumber(request.travel_round_trip_km),
    travelBillableKm: parseNullableNumber(request.travel_billable_km),
    travelFeeAmount: parseNullableNumber(request.travel_fee_amount),
    travelCalculationSource: request.travel_calculation_source ?? null,
    travelCalculatedAt: request.travel_calculated_at ?? null,
  }
}

export default function TechnicalService() {
  const [filters, setFilters] = useState<FilterState>(initialFilters)
  const [requests, setRequests] = useState<ServiceRequest[]>([])
  const [technicians, setTechnicians] = useState<ServiceTechnician[]>([])
  const [techniciansLoading, setTechniciansLoading] = useState(false)
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
  const [showNearbyTechnicians, setShowNearbyTechnicians] = useState(false)
  const [scheduleDate, setScheduleDate] = useState('')
  const [scheduleHour, setScheduleHour] = useState('')
  const [scheduleMinute, setScheduleMinute] = useState('')
  const [travelRoundTripKm, setTravelRoundTripKm] = useState('')
  const [completeDialogOpen, setCompleteDialogOpen] = useState(false)
  const [completionReason, setCompletionReason] = useState('')
  const [completionOtherNote, setCompletionOtherNote] = useState('')
  const [installationCompletedAt, setInstallationCompletedAt] = useState('')
  const [installationCompletionNote, setInstallationCompletionNote] = useState('')
  const [completeLoading, setCompleteLoading] = useState(false)
  const [completeError, setCompleteError] = useState<string | null>(null)
  const [reopenDialogOpen, setReopenDialogOpen] = useState(false)
  const [reopenReason, setReopenReason] = useState('')
  const [reopenNote, setReopenNote] = useState('')
  const [reopenLoading, setReopenLoading] = useState(false)
  const [reopenError, setReopenError] = useState<string | null>(null)
  const [createLoading, setCreateLoading] = useState(false)
  const [createError, setCreateError] = useState<string | null>(null)
  const [summaryData, setSummaryData] = useState<SummaryResponse | null>(null)
  const [mikroMountCheck, setMikroMountCheck] = useState<MikroMountCheckResult | null>(null)
  const [mikroMountLoading, setMikroMountLoading] = useState(false)
  const [mikroMountError, setMikroMountError] = useState<string | null>(null)
  const [warranty, setWarranty] = useState<WarrantySerialResponse | null>(null)
  const [warrantyLoading, setWarrantyLoading] = useState(false)
  const [warrantyError, setWarrantyError] = useState<string | null>(null)
  const selectedIdRef = useRef<string | null>(null)
  const detailRequestTokenRef = useRef(0)
  const serialLookupTokenRef = useRef(0)
  const createDistrictOptions = useMemo(() => getDistrictOptionsForProvince(createForm.city), [createForm.city])
  const hasCreateDistrictFallback = createForm.district.trim() !== ''
    && !createDistrictOptions.some((district) => district.normalizedName === normalizeTurkishLocation(createForm.district))

  const loadRequests = useCallback(async () => {
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
  }, [])

  const loadSummary = useCallback(async () => {
    setSummaryLoading(true)

    try {
      const response = await apiRequest('/api/technical-service/summary')
      setSummaryData(response as SummaryResponse)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Özet verisi alınamadı.')
    } finally {
      setSummaryLoading(false)
    }
  }, [])

  const loadTechnicians = useCallback(async () => {
    setTechniciansLoading(true)

    try {
      const response = await apiRequest('/api/technical-service/technicians?active=1')
      const items = Array.isArray(response.items) ? response.items : []
      setTechnicians(items.map((technician: ServiceTechnician) => ({
        ...technician,
        id: String(technician.id),
      })))
    } catch (caught) {
      setAssignError(caught instanceof Error ? caught.message : 'Usta listesi alınamadı.')
    } finally {
      setTechniciansLoading(false)
    }
  }, [])

  useEffect(() => {
    selectedIdRef.current = selectedId
  }, [selectedId])

  const loadRequestDetail = useCallback(async (id: string) => {
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
      if (isCurrentRequest()) {
        setDetailLoading(false)
      }
    }
  }, [requests, selectedListRequest])

  useEffect(() => {
    void Promise.resolve().then(loadSummary)
  }, [loadSummary])

  useEffect(() => {
    void Promise.resolve().then(loadRequests)
  }, [loadRequests])

  useEffect(() => {
    void Promise.resolve().then(loadTechnicians)
  }, [loadTechnicians])

  useEffect(() => {
    let cancelled = false

    if (!selectedId) {
      detailRequestTokenRef.current += 1
      void Promise.resolve().then(() => {
        if (cancelled) {
          return
        }

        setSelectedEvents([])
        setSelectedListRequest(null)
        setSelectedDetailRequest(null)
        setMikroMountCheck(null)
        setMikroMountError(null)
        setMikroMountLoading(false)
        setWarranty(null)
        setWarrantyError(null)
        setWarrantyLoading(false)
        setShowNearbyTechnicians(false)
        setDetailError(null)
        setDetailLoading(false)
      })

      return () => {
        cancelled = true
      }
    }

    void Promise.resolve().then(() => {
      if (!cancelled) {
        void loadRequestDetail(selectedId)
      }
    })

    return () => {
      cancelled = true
    }
  }, [loadRequestDetail, selectedId])

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
  const modalPayment = getServicePaymentInfo(
    modalRequest?.serviceType,
    modalRequest?.travelRoundTripKm,
    modalRequest?.travelFeeAmount,
    modalRequest?.travelBillableKm,
  )
  const assignTravelRoundTripKm = travelRoundTripKm.trim() === '' ? null : Number(travelRoundTripKm)
  const assignTravelPreview = calculateTravelPreview(
    typeof assignTravelRoundTripKm === 'number' && Number.isFinite(assignTravelRoundTripKm) && assignTravelRoundTripKm >= 0
      ? assignTravelRoundTripKm
      : null,
  )
  const assignPaymentPreview = getServicePaymentInfo(
    modalRequest?.serviceType,
    assignTravelPreview.roundTripKm,
    assignTravelPreview.travelFeeAmount,
  )
  const technicianMatches = technicians
    .map((technician) => technicianMatchInfo(technician, modalRequest))
    .sort((a, b) => {
      if (a.rank !== b.rank) {
        return a.rank - b.rank
      }

      if (a.distanceKm !== null && b.distanceKm !== null && a.distanceKm !== b.distanceKm) {
        return a.distanceKm - b.distanceKm
      }

      return technicianDisplayName(a.technician).localeCompare(technicianDisplayName(b.technician), 'tr')
    })
  const sameCityTechnicians = technicianMatches.filter((match) => match.sameCity)
  const otherCityTechnicians = technicianMatches.filter((match) => !match.sameCity)
  const visibleTechnicianMatches = showNearbyTechnicians
    ? technicianMatches
    : sameCityTechnicians

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
    setCreateForm((current) => {
      if (field === 'city') {
        return {
          ...current,
          city: normalizeFormCity(value),
          district: '',
        }
      }

      if (field === 'district') {
        return {
          ...current,
          district: normalizeFormDistrict(current.city, value),
        }
      }

      return { ...current, [field]: value }
    })
  }

  const handleCreateReset = () => {
    setCreateForm(initialRequestForm)
  }

  useEffect(() => {
    const serialNo = selectedDetailRequest?.serialNumber?.trim() ?? ''
    const lookupToken = serialLookupTokenRef.current + 1
    serialLookupTokenRef.current = lookupToken
    const isCurrentLookup = () => serialLookupTokenRef.current === lookupToken

    void Promise.resolve().then(() => {
      if (!isCurrentLookup()) {
        return
      }

      setMikroMountCheck(null)
      setMikroMountError(null)
      setWarranty(null)
      setWarrantyError(null)

      if (!serialNo) {
        setMikroMountLoading(false)
        setWarrantyLoading(false)

        return
      }

      const params = new URLSearchParams({ serial_no: serialNo })

      setMikroMountLoading(true)
      setWarrantyLoading(true)

      void Promise.allSettled([
        apiRequest(`/api/technical-service/mikro/serial-history?${params.toString()}`),
        apiRequest(`/api/technical-service/warranty/serial?${params.toString()}`),
      ]).then(([historyResponse, warrantyResponse]) => {
        if (!isCurrentLookup()) {
          return
        }

        if (historyResponse.status === 'fulfilled') {
          setMikroMountCheck((historyResponse.value as MikroSerialHistoryResponse).decision)
        } else {
          setMikroMountCheck(null)
          setMikroMountError(historyResponse.reason instanceof Error ? historyResponse.reason.message : 'Mikro montaj kontrolü yapılamadı.')
        }

        if (warrantyResponse.status === 'fulfilled') {
          setWarranty(warrantyResponse.value as WarrantySerialResponse)
        } else {
          setWarranty(null)
          setWarrantyError(warrantyResponse.reason instanceof Error ? warrantyResponse.reason.message : 'Garanti bilgisi alınamadı.')
        }
      }).finally(() => {
        if (isCurrentLookup()) {
          setMikroMountLoading(false)
          setWarrantyLoading(false)
        }
      })
    })
  }, [selectedDetailRequest?.serialNumber])

  const handleAssignReset = () => {
    setAssignTechnicianOption('')
    setAssignOtherTechnician('')
    setAssignNote('')
    setScheduleDate('')
    setScheduleHour('')
    setScheduleMinute('')
    setTravelRoundTripKm('')
    setShowNearbyTechnicians(false)
    setAssignError(null)
  }

  const handleCompleteReset = () => {
    setCompletionReason('')
    setCompletionOtherNote('')
    setInstallationCompletedAt('')
    setInstallationCompletionNote('')
    setCompleteError(null)
  }

  const openCompleteDialog = () => {
    setInstallationCompletedAt(toTechnicalServiceDateTimeInputValue(modalRequest?.scheduledAt ?? null))
    setCompleteDialogOpen(true)
  }

  const handleReopenReset = () => {
    setReopenReason('')
    setReopenNote('')
    setReopenError(null)
  }

  const handleReopenSubmit = async () => {
    if (!selectedId) {
      return
    }

    if (!reopenReason) {
      setReopenError('Yeniden açma nedeni seçin.')

      return
    }

    if (reopenReason === 'Diğer' && !reopenNote.trim()) {
      setReopenError('Diğer nedeni seçildiğinde açıklama zorunludur.')

      return
    }

    setReopenLoading(true)
    setReopenError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/status`, {
        method: 'POST',
        body: JSON.stringify({
          status: 'Yeni',
          reopen_reason: reopenReason,
          reopen_note: reopenNote || null,
          note: reopenNote || reopenReason,
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

    const isManualTechnician = assignTechnicianOption === 'other'
    const selectedTechnicianRecord = technicians.find((technician) => technician.id === assignTechnicianOption)
    const selectedTechnician = isManualTechnician
      ? assignOtherTechnician.trim()
      : selectedTechnicianRecord ? technicianDisplayName(selectedTechnicianRecord) : ''

    if (!selectedTechnician) {
      setAssignError('Lütfen bir usta seçin veya manuel isim girin.')

      return
    }

    if (!scheduleDate || !scheduleHour || !scheduleMinute) {
      setAssignError('Lütfen randevu tarihi ve saatini seçin.')

      return
    }

    const parsedTravelRoundTripKm = Number(travelRoundTripKm)

    if (travelRoundTripKm.trim() === '' || !Number.isFinite(parsedTravelRoundTripKm) || parsedTravelRoundTripKm < 0) {
      setAssignError('Lütfen gidiş-geliş km bilgisini girin.')

      return
    }

    setAssignLoading(true)
    setAssignError(null)

    try {
      const scheduledAt = `${scheduleDate}T${scheduleHour}:${scheduleMinute}:00`

      await apiRequest(`/api/technical-service/requests/${selectedId}/assign`, {
        method: 'POST',
        body: JSON.stringify({
          ...(isManualTechnician
            ? { technician_name: selectedTechnician }
            : { technical_service_technician_id: assignTechnicianOption }),
          travel_round_trip_km: parsedTravelRoundTripKm,
          note: isManualTechnician ? assignNote || null : null,
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
    const isCompletingInstallation = nextStatus === 'Tamamlandı' && modalRequest?.serviceType === 'Montaj'

    if (isCompletingInstallation && !installationCompletedAt) {
      setCompleteError('Fiili montaj tarihi zorunludur.')

      return
    }

    if (isCompletingInstallation) {
      if (!/^\d{4}-\d{2}-\d{2}T([01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/.test(installationCompletedAt)) {
        setCompleteError('Fiili montaj saati 00:00 - 23:59 aralığında HH:mm formatında olmalıdır.')

        return
      }

      const actualDate = new Date(installationCompletedAt)
      const scheduledDate = modalRequest?.scheduledAt ? new Date(modalRequest.scheduledAt) : null
      const nowDate = new Date()
      const actualDateKey = actualDate.toISOString().slice(0, 10)
      const scheduledDateKey = scheduledDate && !Number.isNaN(scheduledDate.getTime()) ? scheduledDate.toISOString().slice(0, 10) : null
      const differsFromSchedule = scheduledDateKey !== null && actualDateKey !== scheduledDateKey
      const olderThanOneDay = nowDate.getTime() - actualDate.getTime() > 24 * 60 * 60 * 1000

      if ((differsFromSchedule || olderThanOneDay) && !installationCompletionNote.trim()) {
        setCompleteError('Fiili montaj tarihi randevudan farklıysa veya kapanıştan 1 günden fazla eskiyse açıklama zorunludur.')

        return
      }
    }

    setCompleteLoading(true)
    setCompleteError(null)

    try {
      await apiRequest(`/api/technical-service/requests/${selectedId}/status`, {
        method: 'POST',
        body: JSON.stringify({
          status: nextStatus,
          resolution_notes: notes || null,
          ...(isCompletingInstallation
            ? {
                installation_completed_at: installationCompletedAt,
                installation_completion_note: installationCompletionNote || null,
                note: installationCompletionNote || notes || null,
              }
            : {}),
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
          customer_city: normalizeFormCity(createForm.city),
          customer_district: normalizeFormDistrict(createForm.city, createForm.district),
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
          <div className="flex flex-wrap gap-2">
            <Button asChild variant="secondary">
              <Link href="/technical-service/dashboard">Operasyon Dashboard</Link>
            </Button>
            <Button asChild variant="secondary">
              <Link href="/technical-service/earnings">Hakedişler</Link>
            </Button>
            <Button asChild variant="secondary">
              <Link href="/technical-service/technicians">Ustalar / Çilingirler</Link>
            </Button>
            <Button asChild variant="secondary">
              <Link href="/technical-service/serial-query">Seri No Sorgu</Link>
            </Button>
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
                    <select
                      value={createForm.city}
                      onChange={(event) => handleCreateChange('city', event.target.value)}
                      className={selectClassName}
                    >
                      <option value="">Seçiniz</option>
                      {TURKEY_PROVINCES.map((province) => (
                        <option key={province.plateCode} value={province.name}>
                          {province.name}
                        </option>
                      ))}
                    </select>
                  </label>
                  <label className="grid gap-2 text-sm font-medium text-slate-700">
                    İlçe
                    <select
                      value={createForm.district}
                      onChange={(event) => handleCreateChange('district', event.target.value)}
                      disabled={!createForm.city}
                      className={selectClassName}
                    >
                      <option value="">{createForm.city ? 'Seçiniz' : 'Önce il seçiniz'}</option>
                      {hasCreateDistrictFallback ? (
                        <option value={createForm.district}>Mevcut değer: {createForm.district}</option>
                      ) : null}
                      {createDistrictOptions.map((district) => (
                        <option key={district.normalizedName} value={district.name}>
                          {district.name}
                        </option>
                      ))}
                    </select>
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
                    className={selectClassName}
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
          </div>

          <Dialog open={assignDialogOpen} onOpenChange={(open) => {
            setAssignDialogOpen(open)

            if (!open) {
              handleAssignReset()
            }
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

              {mikroMountCheck?.montaj_durumu === 'Montaj Hariç' ? (
                <div className="rounded-2xl border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
                  <p className="font-semibold">Mikro kontrolü: Montaj Hariç</p>
                  <p className="mt-1">{mikroMountCheck.montaj_ek_aciklama || 'Bu seri no için son geçerli satışta montaj ödemesi bulunamadı.'}</p>
                </div>
              ) : null}

              {mikroMountCheck?.montaj_durumu === 'Montaj Sonradan Dahil' ? (
                <div className="rounded-2xl border border-blue-200 bg-blue-50 p-4 text-sm text-blue-800">
                  <p className="font-semibold">Mikro kontrolü: Montaj Sonradan Dahil</p>
                  <p className="mt-1">{mikroMountCheck.montaj_ek_aciklama}</p>
                </div>
              ) : null}

              {mikroMountCheck?.farkli_cari_uyarisi ? (
                <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                  <p className="font-semibold">Dikkat: Farklı cari ile sonradan montaj</p>
                  <p className="mt-1">Sonradan montaj carisi asıl satış carisinden farklı. Atama yapmadan önce cari bilgisini kontrol edin.</p>
                </div>
              ) : null}

              <div className="grid gap-4 pt-2">
                <fieldset className="grid gap-3">
                  <legend className="text-sm font-medium text-slate-700">Usta / Çilingir adı</legend>
                  <div className="grid gap-2">
                    {techniciansLoading ? (
                      <div className="rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm text-slate-500">
                        Usta listesi yükleniyor...
                      </div>
                    ) : null}
                    {!techniciansLoading && technicians.length === 0 ? (
                      <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                        Aktif usta kaydı bulunamadı. Manuel giriş için Diğer seçeneğini kullanabilirsiniz.
                      </div>
                    ) : null}
                    {!techniciansLoading && technicians.length > 0 && sameCityTechnicians.length > 0 ? (
                      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Aynı şehirdeki ustalar</p>
                    ) : null}
                    {!techniciansLoading && technicians.length > 0 && sameCityTechnicians.length === 0 ? (
                      <div className="rounded-2xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-700">
                        Bu taleple aynı şehirde aktif usta bulunamadı.
                      </div>
                    ) : null}
                    {visibleTechnicianMatches.map((match, index) => (
                      <div key={match.technician.id} className="grid gap-2">
                        {showNearbyTechnicians && index === sameCityTechnicians.length && otherCityTechnicians.length > 0 ? (
                          <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Yakın / diğer şehirlerdeki ustalar</p>
                        ) : null}
                        <label className="flex cursor-pointer items-start gap-3 rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300">
                          <input
                            type="radio"
                            name="assignTechnician"
                            value={match.technician.id}
                            checked={assignTechnicianOption === match.technician.id}
                            onChange={() => setAssignTechnicianOption(match.technician.id)}
                            className="mt-1 h-4 w-4 accent-primary"
                          />
                          <span className="min-w-0 flex-1">
                            <span className="flex flex-wrap items-center gap-2">
                              <span className="font-semibold">{technicianDisplayName(match.technician)}</span>
                              <span className={[
                                'rounded-full px-2 py-0.5 text-[0.68rem] font-semibold',
                                match.rank === 0 ? 'bg-emerald-50 text-emerald-700' : match.rank === 1 ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-600',
                              ].join(' ')}>
                                {match.badge}
                              </span>
                            </span>
                            <span className="mt-1 block text-xs font-normal text-slate-500">
                              {[match.technician.phone, [match.technician.city, match.technician.district].filter(Boolean).join(' / ')].filter(Boolean).join(' · ') || 'İletişim / konum bilgisi yok'}
                            </span>
                            {(match.technician.mikro_cari_adi || match.technician.mikro_cari_kodu || match.distanceKm !== null) ? (
                              <span className="mt-1 block text-xs font-normal text-slate-500">
                                {[match.technician.mikro_cari_adi || match.technician.mikro_cari_kodu, match.distanceKm !== null ? `Yaklaşık ${match.distanceKm.toLocaleString('tr-TR')} km` : null].filter(Boolean).join(' · ')}
                              </span>
                            ) : null}
                          </span>
                        </label>
                      </div>
                    ))}
                    {!showNearbyTechnicians && otherCityTechnicians.length > 0 ? (
                      <Button type="button" variant="secondary" onClick={() => setShowNearbyTechnicians(true)}>
                        Diğer / Yakın İlleri Göster
                      </Button>
                    ) : null}
                    <label className="flex cursor-pointer items-center rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300">
                      <input
                        type="radio"
                        name="assignTechnician"
                        value="other"
                        checked={assignTechnicianOption === 'other'}
                        onChange={() => setAssignTechnicianOption('other')}
                        className="mr-3 h-4 w-4 accent-primary"
                      />
                      Diğer
                      </label>
                  </div>
                </fieldset>

                {assignTechnicianOption === 'other' ? (
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

                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Gidiş-geliş km
                  <Input
                    type="number"
                    min="0"
                    step="0.01"
                    value={travelRoundTripKm}
                    onChange={(event) => setTravelRoundTripKm(event.target.value)}
                    placeholder="Örn. 42"
                  />
                </label>

                <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Ücretsiz km</span>
                    <span className="font-semibold text-slate-900">{assignPaymentPreview.freeKmLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Ücretli km</span>
                    <span className="font-semibold text-slate-900">{assignPaymentPreview.billableKmLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3">
                    <span className="font-medium text-slate-600">Yol ücreti</span>
                    <span className="font-semibold text-slate-900">{assignPaymentPreview.travelAmountLabel}</span>
                  </div>
                  <div className="flex items-center justify-between gap-3 border-t border-slate-200 pt-3">
                    <span className="font-medium text-slate-600">Toplam usta maliyeti</span>
                    <span className="font-semibold text-slate-950">{assignPaymentPreview.totalTechnicianCostLabel}</span>
                  </div>
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
                    (assignTechnicianOption === 'other' && !assignOtherTechnician.trim()) ||
                    !scheduleDate ||
                    !scheduleHour ||
                    !scheduleMinute ||
                    travelRoundTripKm.trim() === '' ||
                    !Number.isFinite(Number(travelRoundTripKm)) ||
                    Number(travelRoundTripKm) < 0
                  }
                >
                  {assignLoading ? 'Kaydediliyor...' : 'Usta Ata ve Randevu Ver'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={completeDialogOpen} onOpenChange={(open) => {
            setCompleteDialogOpen(open)

            if (!open) {
              handleCompleteReset()
            }
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

                {completionReason === 'Montaj tamamlandı' && modalRequest?.serviceType === 'Montaj' ? (
                  <div className="grid gap-4 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                    <div className="text-sm leading-6 text-amber-800">
                      Garanti, talebin panelde kapatıldığı tarihte değil, fiili montaj tarihinde başlar.
                    </div>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Fiili Montaj Tarihi
                      <DateTimeFields
                        value={installationCompletedAt}
                        max={toTechnicalServiceDateTimeInputValue(null)}
                        onChange={setInstallationCompletedAt}
                      />
                      <span className="text-xs font-normal text-slate-600">Garanti bu tarihten itibaren başlar.</span>
                    </label>
                    <label className="grid gap-2 text-sm font-medium text-slate-700">
                      Fiili montaj açıklaması
                      <textarea
                        value={installationCompletionNote}
                        onChange={(event) => setInstallationCompletionNote(event.target.value)}
                        className="min-h-[84px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                        placeholder="Randevu tarihinden farklıysa veya geçmiş tarih girildiyse açıklama zorunlu"
                      />
                    </label>
                  </div>
                ) : null}
              </div>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleCompleteReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button
                  type="button"
                  onClick={handleCompleteSubmit}
                  disabled={
                    completeLoading ||
                    !completionReason ||
                    (completionReason === 'Diğer' && !completionOtherNote.trim()) ||
                    (completionReason === 'Montaj tamamlandı' && modalRequest?.serviceType === 'Montaj' && !installationCompletedAt)
                  }
                >
                  {completeLoading ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </DialogFooter>
            </DialogContent>
          </Dialog>

          <Dialog open={reopenDialogOpen} onOpenChange={(open) => {
            setReopenDialogOpen(open)

            if (!open) {
              handleReopenReset()
            }
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

              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm leading-6 text-rose-700">
                Bu talep daha önce tamamlandıysa garanti başlangıcı geri alınmaz. Yeniden açma işlemi sadece operasyonel düzeltme içindir.
              </div>

              {modalRequest?.serviceType === 'Montaj' && modalRequest.completedAt ? (
                <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm leading-6 text-amber-800">
                  Bu montaj talebi tamamlanmış görünüyor. Garanti başladıysa önerilen aksiyon yeni bağlı servis/takip talebi açmaktır.
                </div>
              ) : null}

              <fieldset className="grid gap-3">
                <legend className="text-sm font-medium text-slate-700">Yeniden açma nedeni</legend>
                <div className="grid gap-2">
                  {REOPEN_REASONS.map((reason) => (
                    <label
                      key={reason}
                      className="flex cursor-pointer items-center rounded-2xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-medium text-slate-900 transition hover:border-slate-300"
                    >
                      <input
                        type="radio"
                        name="reopenReason"
                        value={reason}
                        checked={reopenReason === reason}
                        onChange={() => setReopenReason(reason)}
                        className="mr-3 h-4 w-4 accent-primary"
                      />
                      {reason}
                    </label>
                  ))}
                </div>
              </fieldset>

              <label className="grid gap-2 text-sm font-medium text-slate-700">
                Açıklama
                <textarea
                  value={reopenNote}
                  onChange={(event) => setReopenNote(event.target.value)}
                  className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                  placeholder={reopenReason === 'Diğer' ? 'Açıklama zorunlu' : 'Opsiyonel açıklama'}
                />
              </label>

              <DialogFooter className="gap-2">
                <DialogClose asChild>
                  <Button variant="secondary" type="button" onClick={handleReopenReset}>
                    İptal
                  </Button>
                </DialogClose>
                <Button type="button" onClick={handleReopenSubmit} disabled={reopenLoading || !reopenReason || (reopenReason === 'Diğer' && !reopenNote.trim())}>
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
            setWarranty(null)
            setWarrantyError(null)
            setWarrantyLoading(false)
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
                    mikroMountCheck={mikroMountCheck}
                    mikroMountLoading={mikroMountLoading}
                    mikroMountError={mikroMountError}
                    warranty={warranty}
                    warrantyLoading={warrantyLoading}
                    warrantyError={warrantyError}
                    onAssign={() => setAssignDialogOpen(true)}
                    onComplete={openCompleteDialog}
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
                  setMikroMountCheck(null)
                  setMikroMountError(null)
                  setMikroMountLoading(false)
                  setWarranty(null)
                  setWarrantyError(null)
                  setWarrantyLoading(false)
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
