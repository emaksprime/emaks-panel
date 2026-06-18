import { Head } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import Heading from '@/components/heading'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'
import {
  getDistrictOptionsForProvince,
  normalizeDistrictName,
  normalizeProvinceName,
  normalizeTurkishLocation,
  TURKEY_PROVINCES,
} from '@/components/technical-service/turkey-locations'
import type { ServiceTechnician } from '@/components/technical-service/types'
import { Button } from '@/components/ui/button'
import { Dialog, DialogContent, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type TechnicianForm = {
  first_name: string
  last_name: string
  phone: string
  city_plate_code: string
  city: string
  district: string
  address: string
  location_code: string
  google_plus_code: string
  google_formatted_address: string
  latitude: string
  longitude: string
  default_start_address: string
  default_start_plus_code: string
  start_latitude: string
  start_longitude: string
  mikro_cari_kodu: string
  mikro_cari_adi: string
  cari_address: string
  cari_city_district_country: string
  note: string
  active: boolean
}

type ImportPreviewSummary = {
  total_rows: number
  parsed_rows: number
  valid_rows: number
  create_count: number
  update_count: number
  skip_count: number
  error_count: number
  warning_count: number
  duplicate_count: number
  partner_link_create_count: number
  partner_link_update_count: number
  partner_link_skip_count: number
  partner_missing_count: number
  geocode_ready_count: number
  geocode_warning_count: number
  geocode_existing_coordinates_count: number
  geocode_preserve_existing_count: number
  geocode_error_count: number
}

type ImportPreviewRow = {
  row_number: number
  action: string
  confidence: string
  normalized: Record<string, string | number | boolean | null>
  existing_match: {
    id: number | string
    name: string
    reason: string
    reliable: boolean
  } | null
  partner_match: {
    id: number | string
    name: string
    cari_code: string | null
  } | null
  link_plan: {
    action: string
    reason: string
  } | null
  geocode_plan: {
    status: string
    reason: string
    query: string | null
    source: string | null
  }
  warnings: string[]
  errors: string[]
  duplicates: string[]
  changed_fields: string[]
}

type ImportPreviewResult = {
  ok: boolean
  dry_run: boolean
  writes_performed: boolean
  file: {
    original_name: string
    extension: string
    sheet_name: string | null
    detected_header_row: number | null
    row_count: number
    file_hash: string
  }
  summary: ImportPreviewSummary
  rows: ImportPreviewRow[]
  message?: string
}

const importColumns = [
  'first_name',
  'last_name',
  'full_name',
  'phone',
  'city',
  'district',
  'address',
  'latitude',
  'longitude',
  'google_plus_code',
  'google_formatted_address',
  'default_start_address',
  'default_start_plus_code',
  'start_latitude',
  'start_longitude',
  'mikro_cari_kodu',
  'mikro_cari_adi',
  'note',
  'active',
]

const emptyForm: TechnicianForm = {
  first_name: '',
  last_name: '',
  phone: '',
  city_plate_code: '',
  city: '',
  district: '',
  address: '',
  location_code: '',
  google_plus_code: '',
  google_formatted_address: '',
  latitude: '',
  longitude: '',
  default_start_address: '',
  default_start_plus_code: '',
  start_latitude: '',
  start_longitude: '',
  mikro_cari_kodu: '',
  mikro_cari_adi: '',
  cari_address: '',
  cari_city_district_country: '',
  note: '',
  active: true,
}

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''

const importActionLabels: Record<string, string> = {
  create: 'Yeni',
  update: 'Güncelleme',
  skip: 'Skip',
  error: 'Hata',
}

const importConfidenceLabels: Record<string, string> = {
  new: 'Yeni kayıt',
  reliable: 'Güvenilir eşleşme',
  weak: 'Manuel kontrol',
  blocked: 'Bloklandı',
}

const geocodePlanLabels: Record<string, string> = {
  coordinates_present: 'Koordinat dosyada var',
  preserve_existing: 'Mevcut koordinat korunacak',
  ready_plus_code: 'Plus code ile hazır',
  ready_address: 'Adres ile hazır',
  warning_city_only: 'Şehir-only uyarı',
  warning_missing_address: 'Adres eksik',
  invalid_coordinate: 'Koordinat hatası',
  skipped: 'Geocode kapalı',
}

const importRowValue = (row: ImportPreviewRow, key: string): string => {
  const value = row.normalized[key]

  if (value === null || value === undefined || typeof value === 'boolean') {
    return ''
  }

  return String(value)
}

const importRowDisplayName = (row: ImportPreviewRow): string => (
  importRowValue(row, 'full_name')
  || [importRowValue(row, 'first_name'), importRowValue(row, 'last_name')].filter(Boolean).join(' ')
  || '-'
)

const displayName = (technician: ServiceTechnician) =>
  [technician.first_name, technician.last_name].filter(Boolean).join(' ').trim() || technician.name

const nullableText = (value: string) => value.trim() || null
const selectClassName = 'h-10 w-full rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]'

const normalizeFormCity = (value: string | null | undefined) => normalizeProvinceName(value) ?? String(value ?? '').trim()
const normalizeFormDistrict = (city: string | null | undefined, value: string | null | undefined) =>
  normalizeDistrictName(city, value) ?? String(value ?? '').trim()

const nullableNumber = (value: string) => {
  if (value.trim() === '') {
    return null
  }

  const parsed = Number(value)

  return Number.isFinite(parsed) ? parsed : null
}

const parseCoordinateValue = (value: number | string | null | undefined) => {
  if (value === null || value === undefined || value === '') {
    return null
  }

  const parsed = typeof value === 'number' ? value : Number(String(value).trim())

  return Number.isFinite(parsed) ? parsed : null
}

const hasRealCoordinates = (technician: ServiceTechnician) => {
  const primaryLatitude = parseCoordinateValue(technician.latitude)
  const primaryLongitude = parseCoordinateValue(technician.longitude)
  const startLatitude = parseCoordinateValue(technician.start_latitude)
  const startLongitude = parseCoordinateValue(technician.start_longitude)
  const latitude = primaryLatitude ?? startLatitude
  const longitude = primaryLongitude ?? startLongitude

  if (latitude === null || longitude === null) {
    return false
  }

  return !(latitude === 0 && longitude === 0) && Math.abs(latitude) <= 90 && Math.abs(longitude) <= 180
}

const hasPlusCodeInfo = (technician: ServiceTechnician) => [
  technician.location_code,
  technician.google_plus_code,
  technician.default_start_plus_code,
].some((value) => typeof value === 'string' && value.trim() !== '')

const hasAddressInfo = (technician: ServiceTechnician) => [
  technician.address,
  technician.default_start_address,
  technician.google_formatted_address,
  technician.cari_address,
  technician.cari_city_district_country,
].some((value) => typeof value === 'string' && value.trim() !== '')

const reviewReasonText = (technician: ServiceTechnician) => {
  const reasons = Array.isArray(technician.review_reasons) ? technician.review_reasons.filter(Boolean) : []

  return reasons.length > 0 ? reasons.join(' ') : (technician.review_reason ?? technician.import_note ?? null)
}

const activeB2BPartnerLinks = (technician: ServiceTechnician) => (technician.b2b_partner_links ?? [])
  .filter((link) => link.active !== false && link.partner)

const coordinateWatchedFields: Array<keyof TechnicianForm> = [
  'city',
  'district',
  'address',
  'location_code',
  'google_plus_code',
  'google_formatted_address',
  'default_start_address',
  'default_start_plus_code',
  'cari_address',
  'cari_city_district_country',
]

const addressSignatureForForm = (form: TechnicianForm): string => JSON.stringify(
  coordinateWatchedFields.map((field) => String(form[field] ?? '').trim()),
)

const formHasCoordinates = (form: TechnicianForm): boolean => (
  hasRealCoordinates({
    id: 'form',
    name: 'form',
    active: true,
    latitude: form.latitude,
    longitude: form.longitude,
    start_latitude: form.start_latitude,
    start_longitude: form.start_longitude,
  })
)

const manualCoordinateInvalid = (form: TechnicianForm): boolean => {
  const latitude = parseCoordinateValue(form.latitude)
  const longitude = parseCoordinateValue(form.longitude)
  const startLatitude = parseCoordinateValue(form.start_latitude)
  const startLongitude = parseCoordinateValue(form.start_longitude)

  return (latitude === 0 && longitude === 0) || (startLatitude === 0 && startLongitude === 0)
}

export default function TechnicalServiceTechnicians() {
  const [technicians, setTechnicians] = useState<ServiceTechnician[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<ServiceTechnician | null>(null)
  const [form, setForm] = useState<TechnicianForm>(emptyForm)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [initialAddressSignature, setInitialAddressSignature] = useState('')
  const [geocoding, setGeocoding] = useState(false)
  const [geocodeMessage, setGeocodeMessage] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [importFile, setImportFile] = useState<File | null>(null)
  const [previewingImport, setPreviewingImport] = useState(false)
  const [importPreview, setImportPreview] = useState<ImportPreviewResult | null>(null)
  const [importError, setImportError] = useState<string | null>(null)
  const [importPreviewTab, setImportPreviewTab] = useState('all')
  const [importUpdateExisting, setImportUpdateExisting] = useState(false)
  const [importPreserveCoordinates, setImportPreserveCoordinates] = useState(true)
  const [importLinkPartners, setImportLinkPartners] = useState(true)
  const [importGeocodeMode, setImportGeocodeMode] = useState('plan_only')
  const [typeFilter, setTypeFilter] = useState('')
  const [cityFilter, setCityFilter] = useState('')
  const [activeFilter, setActiveFilter] = useState('')
  const [needsReviewFilter, setNeedsReviewFilter] = useState('')
  const districtOptions = useMemo(() => getDistrictOptionsForProvince(form.city), [form.city])
  const hasDistrictFallback = form.district.trim() !== ''
    && !districtOptions.some((district) => district.normalizedName === normalizeTurkishLocation(form.district))
  const coordinateStale = Boolean(editing && formHasCoordinates(form) && initialAddressSignature !== '' && addressSignatureForForm(form) !== initialAddressSignature)

  const loadTechnicians = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const params = new URLSearchParams()

      if (typeFilter) {
        params.set('technician_type', typeFilter)
      }

      if (cityFilter) {
        params.set('city', cityFilter)
      }

      if (activeFilter) {
        params.set('active', activeFilter)
      }

      if (needsReviewFilter) {
        params.set('needs_review', needsReviewFilter)
      }

      const response = await apiRequest(`/api/technical-service/technicians${params.toString() ? `?${params.toString()}` : ''}`)
      const items = Array.isArray(response.items) ? response.items : []
      setTechnicians(items.map((technician: ServiceTechnician) => ({
        ...technician,
        id: String(technician.id),
      })))
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Usta listesi alınamadı.')
    } finally {
      setLoading(false)
    }
  }, [activeFilter, cityFilter, needsReviewFilter, typeFilter])

  useEffect(() => {
    void Promise.resolve().then(loadTechnicians)
  }, [loadTechnicians])

  const filteredTechnicians = useMemo(() => {
    const normalizedSearch = search.trim().toLocaleLowerCase('tr-TR')

    if (!normalizedSearch) {
      return technicians
    }

    return technicians.filter((technician) => [
      displayName(technician),
      technician.display_name,
      technician.phone,
      technician.phone_e164,
      technician.phone_display,
      technician.city,
      technician.district,
      technician.address,
      technician.google_plus_code,
      technician.location_code,
      technician.mikro_cari_kodu,
      technician.mikro_cari_adi,
      technician.cari_code,
      technician.cari_title,
      technician.import_status,
      technician.import_note,
    ].some((value) => String(value ?? '').toLocaleLowerCase('tr-TR').includes(normalizedSearch)))
  }, [search, technicians])

  const filteredImportRows = useMemo(() => {
    const rows = importPreview?.rows ?? []

    if (importPreviewTab === 'create' || importPreviewTab === 'update') {
      return rows.filter((row) => row.action === importPreviewTab)
    }

    if (importPreviewTab === 'error') {
      return rows.filter((row) => row.errors.length > 0)
    }

    if (importPreviewTab === 'warning') {
      return rows.filter((row) => row.warnings.length > 0)
    }

    if (importPreviewTab === 'duplicate') {
      return rows.filter((row) => row.duplicates.length > 0)
    }

    return rows
  }, [importPreview, importPreviewTab])

  const openCreate = () => {
    setEditing(null)
    setForm(emptyForm)
    setInitialAddressSignature('')
    setGeocodeMessage(null)
    setSaveError(null)
    setDialogOpen(true)
  }

  const openEdit = (technician: ServiceTechnician) => {
    const city = normalizeFormCity(technician.city)
    const district = normalizeFormDistrict(city, technician.district)

    const nextForm = {
      first_name: technician.first_name ?? technician.name ?? '',
      last_name: technician.last_name ?? '',
      phone: technician.phone ?? '',
      city_plate_code: technician.city_plate_code ?? '',
      city,
      district,
      address: technician.address ?? '',
      location_code: technician.location_code ?? '',
      google_plus_code: technician.google_plus_code ?? '',
      google_formatted_address: technician.google_formatted_address ?? '',
      latitude: technician.latitude === null || technician.latitude === undefined ? '' : String(technician.latitude),
      longitude: technician.longitude === null || technician.longitude === undefined ? '' : String(technician.longitude),
      default_start_address: technician.default_start_address ?? '',
      default_start_plus_code: technician.default_start_plus_code ?? '',
      start_latitude: technician.start_latitude === null || technician.start_latitude === undefined ? '' : String(technician.start_latitude),
      start_longitude: technician.start_longitude === null || technician.start_longitude === undefined ? '' : String(technician.start_longitude),
      mikro_cari_kodu: technician.mikro_cari_kodu ?? '',
      mikro_cari_adi: technician.mikro_cari_adi ?? '',
      cari_address: technician.cari_address ?? '',
      cari_city_district_country: technician.cari_city_district_country ?? '',
      active: technician.active,
      note: technician.note ?? '',
    }

    setEditing(technician)
    setForm(nextForm)
    setInitialAddressSignature(addressSignatureForForm(nextForm))
    setGeocodeMessage(null)
    setSaveError(null)
    setDialogOpen(true)
  }

  const updateForm = (field: keyof TechnicianForm, value: string | boolean) => {
    setForm((current) => {
      if (field === 'city') {
        return {
          ...current,
          city: typeof value === 'string' ? normalizeFormCity(value) : current.city,
          district: '',
        }
      }

      if (field === 'district') {
        return {
          ...current,
          district: typeof value === 'string' ? normalizeFormDistrict(current.city, value) : current.district,
        }
      }

      return { ...current, [field]: value }
    })
  }

  const saveTechnician = async () => {
    if (!form.first_name.trim()) {
      setSaveError('Usta adı zorunlu.')

      return
    }

    if (manualCoordinateInvalid(form)) {
      setSaveError('Koordinat geçersiz. Latitude/Longitude 0/0 olamaz.')

      return
    }

    setSaving(true)
    setSaveError(null)

    try {
      const payload = {
        first_name: form.first_name.trim(),
        last_name: nullableText(form.last_name),
        phone: nullableText(form.phone),
        city_plate_code: nullableText(form.city_plate_code),
        city: nullableText(normalizeFormCity(form.city)),
        district: nullableText(normalizeFormDistrict(form.city, form.district)),
        address: nullableText(form.address),
        location_code: nullableText(form.location_code),
        google_plus_code: nullableText(form.google_plus_code),
        google_formatted_address: nullableText(form.google_formatted_address),
        latitude: nullableNumber(form.latitude),
        longitude: nullableNumber(form.longitude),
        default_start_address: nullableText(form.default_start_address),
        default_start_plus_code: nullableText(form.default_start_plus_code),
        start_latitude: nullableNumber(form.start_latitude),
        start_longitude: nullableNumber(form.start_longitude),
        mikro_cari_kodu: nullableText(form.mikro_cari_kodu),
        mikro_cari_adi: nullableText(form.mikro_cari_adi),
        cari_address: nullableText(form.cari_address),
        cari_city_district_country: nullableText(form.cari_city_district_country),
        needs_review: coordinateStale ? true : undefined,
        active: form.active,
        note: nullableText(form.note),
      }

      await apiRequest(editing ? `/api/technical-service/technicians/${editing.id}` : '/api/technical-service/technicians', {
        method: editing ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      })

      setDialogOpen(false)
      await loadTechnicians()
    } catch (caught) {
      setSaveError(caught instanceof Error ? caught.message : 'Usta kaydı yapılamadı.')
    } finally {
      setSaving(false)
    }
  }

  const disableTechnician = async (technician: ServiceTechnician) => {
    await apiRequest(`/api/technical-service/technicians/${technician.id}`, { method: 'DELETE' })
    await loadTechnicians()
  }

  const markTechnicianReviewed = async (technician: ServiceTechnician) => {
    try {
      const response = await apiRequest(`/api/technical-service/technicians/${technician.id}/mark-reviewed`, {
        method: 'POST',
      })
      setMessageFromTechnicianResponse(response.technician as ServiceTechnician, 'Kontrol uyarısı kapatıldı.')
      await loadTechnicians()
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Kontrol kapatılamadı.')
    }
  }

  const setMessageFromTechnicianResponse = (technician: ServiceTechnician, fallback: string) => {
    setGeocodeMessage(`${displayName(technician)}: ${fallback}`)
  }

  const geocodeTechnician = async (dryRun = false) => {
    if (!editing) {
      setGeocodeMessage('Önce kayıt oluşturun, sonra Google ile koordinatı güncelleyin.')

      return
    }

    setGeocoding(true)
    setSaveError(null)
    setGeocodeMessage(null)

    try {
      const response = await apiRequest(`/api/technical-service/technicians/${editing.id}/geocode`, {
        method: 'POST',
        body: JSON.stringify({
          dry_run: dryRun,
          apply: !dryRun,
          override_existing_coordinates: false,
        }),
      })

      if (dryRun) {
        setGeocodeMessage(response.plan?.query ? `Geocode dry-run: ${response.plan.query}` : (response.message ?? 'Geocode dry-run tamamlandı.'))

        return
      }

      const technician = response.technician as ServiceTechnician
      const city = normalizeFormCity(technician.city)
      const district = normalizeFormDistrict(city, technician.district)
      const nextForm = {
        ...form,
        city_plate_code: technician.city_plate_code ?? form.city_plate_code,
        city,
        district,
        address: technician.address ?? '',
        location_code: technician.location_code ?? '',
        google_plus_code: technician.google_plus_code ?? '',
        google_formatted_address: technician.google_formatted_address ?? '',
        latitude: technician.latitude === null || technician.latitude === undefined ? '' : String(technician.latitude),
        longitude: technician.longitude === null || technician.longitude === undefined ? '' : String(technician.longitude),
        default_start_address: technician.default_start_address ?? '',
        default_start_plus_code: technician.default_start_plus_code ?? '',
        start_latitude: technician.start_latitude === null || technician.start_latitude === undefined ? '' : String(technician.start_latitude),
        start_longitude: technician.start_longitude === null || technician.start_longitude === undefined ? '' : String(technician.start_longitude),
        cari_address: technician.cari_address ?? '',
        cari_city_district_country: technician.cari_city_district_country ?? '',
      }

      setEditing({ ...technician, id: String(technician.id) })
      setForm(nextForm)
      setInitialAddressSignature(addressSignatureForForm(nextForm))
      setGeocodeMessage(response.message ?? 'Koordinat Google ile güncellendi.')
      await loadTechnicians()
    } catch (caught) {
      setGeocodeMessage(caught instanceof Error ? caught.message : 'Google ile koordinat güncellenemedi.')
    } finally {
      setGeocoding(false)
    }
  }

  const resetImportPreview = () => {
    setImportPreview(null)
    setImportError(null)
    setImportPreviewTab('all')
  }

  const runImportPreview = async () => {
    if (!importFile) {
      setImportError('CSV veya Excel dosyası seçin.')

      return
    }

    setPreviewingImport(true)
    setImportError(null)
    setImportPreview(null)

    try {
      const formData = new FormData()
      formData.append('file', importFile)
      formData.append('dry_run', '1')
      formData.append('update_existing', importUpdateExisting ? '1' : '0')
      formData.append('override_existing_coordinates', importPreserveCoordinates ? '0' : '1')
      formData.append('link_existing_partners', importLinkPartners ? '1' : '0')
      formData.append('geocode_mode', importGeocodeMode)

      const response = await fetch('/api/technical-service/technicians/import-preview', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
        },
        body: formData,
      })
      const result = await response.json().catch(() => null) as ImportPreviewResult | null

      if (!response.ok || !result?.ok) {
        setImportError(result?.message ?? 'İçe aktarma önizlemesi alınamadı.')

        return
      }

      setImportPreview(result)
    } catch (caught) {
      setImportError(caught instanceof Error ? caught.message : 'İçe aktarma önizlemesi alınamadı.')
    } finally {
      setPreviewingImport(false)
    }
  }

  return (
    <>
      <Head title="Ustalar / Çilingirler" />

      <div className="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 md:px-6 lg:px-12">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="Ustalar / Çilingirler"
            description="Teknik servis atamalarında kullanılacak usta kayıtlarını yönetin."
          />
          <div className="flex flex-wrap gap-2">
            <TechnicalServicePageLinks />
            <Button type="button" onClick={openCreate}>Yeni Usta</Button>
          </div>
        </div>

        <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Usta ara
              <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Ad, soyad, telefon, il, ilçe, Mikro cari" />
            </label>
            <div className="text-sm font-semibold text-slate-600">
              {loading ? 'Yükleniyor...' : `${filteredTechnicians.length} kayıt`}
            </div>
          </div>

          <div className="grid gap-3 md:grid-cols-4">
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Tip
              <select className={selectClassName} value={typeFilter} onChange={(event) => setTypeFilter(event.target.value)}>
                <option value="">Tümü</option>
                <option value="locksmith">Çilingir</option>
                <option value="technician">Teknisyen</option>
              </select>
            </label>
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Şehir
              <select className={selectClassName} value={cityFilter} onChange={(event) => setCityFilter(event.target.value)}>
                <option value="">Tümü</option>
                {TURKEY_PROVINCES.map((province) => (
                  <option key={province.name} value={province.name}>{province.name}</option>
                ))}
              </select>
            </label>
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Aktif
              <select className={selectClassName} value={activeFilter} onChange={(event) => setActiveFilter(event.target.value)}>
                <option value="">Tümü</option>
                <option value="1">Aktif</option>
                <option value="0">Pasif</option>
              </select>
            </label>
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Kontrol gerekli
              <select className={selectClassName} value={needsReviewFilter} onChange={(event) => setNeedsReviewFilter(event.target.value)}>
                <option value="">Tümü</option>
                <option value="1">Kontrol gerekli</option>
                <option value="0">Kontrol gerekmiyor</option>
              </select>
            </label>
          </div>

          {error ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div>
          ) : null}

          {!loading && filteredTechnicians.length === 0 ? (
            <div className="rounded-2xl border border-slate-200 bg-slate-50 p-6 text-center text-sm text-slate-500">Kayıt bulunamadı.</div>
          ) : null}

          {filteredTechnicians.length > 0 ? (
            <div className="grid gap-3 xl:hidden">
              {filteredTechnicians.map((technician) => (
                <article key={technician.id} className="grid gap-3 rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
                  <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                      <h3 className="min-w-0 flex-1 truncate text-base font-semibold text-slate-950">{displayName(technician)}</h3>
                      <span className={[
                        'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                        technician.technician_type === 'locksmith' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600',
                      ].join(' ')}>
                        {technician.technician_type === 'locksmith' ? 'Çilingir' : 'Teknisyen'}
                      </span>
                    </div>
                    {technician.display_name ? <p className="mt-1 line-clamp-1 break-words text-sm text-slate-600">{technician.display_name}</p> : null}
                  </div>

                  <div className="flex flex-wrap gap-2">
                    {technician.city ? <span className="rounded-full bg-sky-50 px-2.5 py-1 text-xs font-semibold text-sky-700">{technician.city}</span> : null}
                    {technician.priority ? <span className="rounded-full bg-indigo-50 px-2.5 py-1 text-xs font-semibold text-indigo-700">Öncelik: {technician.priority}</span> : null}
                    {technician.active ? (
                      <span className="rounded-full bg-emerald-50 px-2.5 py-1 text-xs font-semibold text-emerald-700">Aktif</span>
                    ) : (
                      <span className="rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-500">Pasif</span>
                    )}
                    {technician.needs_review ? <span className="rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">Kontrol gerekli</span> : null}
                  </div>

                  <div className="grid gap-2 text-sm text-slate-700 sm:grid-cols-2">
                    <div className="min-w-0">
                      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Telefon</p>
                      <p className="truncate font-medium text-slate-900">{technician.phone_display || technician.phone_e164 || technician.phone || '-'}</p>
                    </div>
                    <div className="min-w-0">
                      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Cari Kodu</p>
                      <p className="truncate">{technician.cari_code || technician.mikro_cari_kodu || '-'}</p>
                    </div>
                    <div className="min-w-0 sm:col-span-2">
                      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Cari Ünvan</p>
                      <p className="line-clamp-2 break-words">{technician.cari_title || technician.mikro_cari_adi || '-'}</p>
                    </div>
                    <div className="min-w-0 sm:col-span-2">
                      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Bağlı B2B partnerlar</p>
                      {activeB2BPartnerLinks(technician).length > 0 ? (
                        <div className="mt-1 flex flex-wrap gap-1">
                          {activeB2BPartnerLinks(technician).map((link) => (
                            <span key={link.id} className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                              {link.partner?.display_name ?? `Partner #${link.partner_id}`}{link.is_primary ? ' · Birincil' : ''}
                            </span>
                          ))}
                        </div>
                      ) : (
                        <p className="text-sm text-slate-500">B2B partner bağlantısı yok.</p>
                      )}
                    </div>
                    <div className="min-w-0 sm:col-span-2">
                      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Konum / Adres Kodu</p>
                      <p className="line-clamp-2 break-words">{technician.location_code || technician.google_plus_code || '-'}</p>
                      <div className="mt-2 flex flex-wrap gap-1">
                        <span className={[
                          'inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold',
                          hasRealCoordinates(technician) ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700',
                        ].join(' ')}>
                          {hasRealCoordinates(technician) ? 'Gerçek koordinat var' : 'Gerçek koordinat yok'}
                        </span>
                        {hasPlusCodeInfo(technician) ? <span className="inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700">Plus Code var</span> : null}
                        {hasAddressInfo(technician) ? <span className="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">Adres var</span> : null}
                        {technician.geocode_status ? <span className="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">Geocode: {technician.geocode_status}</span> : null}
                        {technician.needs_review && hasRealCoordinates(technician) ? <span className="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">Koordinat kontrol gerekli</span> : null}
                      </div>
                    </div>
                    {reviewReasonText(technician) ? (
                      <div className="min-w-0 sm:col-span-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Durum / Kontrol Notu</p>
                        <p className="line-clamp-2 break-words">{reviewReasonText(technician)}</p>
                      </div>
                    ) : null}
                  </div>

                  <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={() => openEdit(technician)}>Düzenle</Button>
                    {technician.needs_review ? (
                      <Button type="button" variant="outline" onClick={() => void markTechnicianReviewed(technician)}>Kontrol edildi</Button>
                    ) : null}
                    {technician.active ? (
                      <Button type="button" variant="destructive" onClick={() => void disableTechnician(technician)}>Pasifleştir</Button>
                    ) : null}
                  </div>
                </article>
              ))}
            </div>
          ) : null}

          <div className="hidden max-w-full overflow-x-auto rounded-2xl border border-slate-200 xl:block">
            <table className="w-full table-fixed divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                <tr>
                  <th className="w-[18%] px-4 py-3">Ad Soyad / Cari ADI</th>
                  <th className="w-[10%] px-4 py-3">Şehir / Öncelik</th>
                  <th className="w-[12%] px-4 py-3">Telefon</th>
                  <th className="w-[12%] px-4 py-3">Cari Kodu</th>
                  <th className="w-[17%] px-4 py-3">Cari Ünvan</th>
                  <th className="w-[15%] px-4 py-3">Konum / Adres Kodu</th>
                  <th className="w-[10%] px-4 py-3">Aktif / Kontrol</th>
                  <th className="w-[6%] px-4 py-3 text-right">İşlem</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 bg-white">
                {filteredTechnicians.map((technician) => (
                  <tr key={technician.id}>
                    <td className="min-w-0 px-4 py-3">
                      <p className="truncate font-semibold text-slate-950">{displayName(technician)}</p>
                      {technician.display_name ? <p className="mt-1 line-clamp-1 break-words text-xs text-slate-500">{technician.display_name}</p> : null}
                      {technician.note ? <p className="mt-1 line-clamp-2 break-words text-xs text-slate-500">{technician.note}</p> : null}
                      {activeB2BPartnerLinks(technician).length > 0 ? (
                        <div className="mt-2 flex flex-wrap gap-1">
                          {activeB2BPartnerLinks(technician).map((link) => (
                            <span key={link.id} className="inline-flex rounded-full bg-emerald-50 px-2 py-0.5 text-[11px] font-semibold text-emerald-700">
                              {link.partner?.display_name ?? `Partner #${link.partner_id}`}{link.is_primary ? ' · Birincil' : ''}
                            </span>
                          ))}
                        </div>
                      ) : null}
                    </td>
                    <td className="min-w-0 px-4 py-3">
                      <span className={[
                        'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                        technician.technician_type === 'locksmith' ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-600',
                      ].join(' ')}>
                        {technician.technician_type === 'locksmith' ? 'Çilingir' : 'Teknisyen'}
                      </span>
                      {technician.city ? <p className="mt-1 truncate text-xs font-semibold text-slate-700">{technician.city}</p> : null}
                      {technician.priority ? <p className="mt-1 text-xs text-slate-500">Öncelik: {technician.priority}</p> : null}
                    </td>
                    <td className="min-w-0 px-4 py-3 text-slate-700">
                      <p className="truncate">{technician.phone_display || technician.phone_e164 || technician.phone || '-'}</p>
                    </td>
                    <td className="min-w-0 px-4 py-3 text-slate-700">
                      <p className="truncate">{technician.cari_code || technician.mikro_cari_kodu || '-'}</p>
                    </td>
                    <td className="min-w-0 px-4 py-3 text-slate-700">
                      <p className="line-clamp-2 break-words">{technician.cari_title || technician.mikro_cari_adi || '-'}</p>
                    </td>
                    <td className="min-w-0 px-4 py-3 text-slate-700">
                      <p className="line-clamp-2 break-words">{technician.location_code || technician.google_plus_code || '-'}</p>
                      <div className="mt-2 flex flex-wrap gap-1">
                        <span className={[
                          'inline-flex rounded-full px-2 py-0.5 text-[11px] font-semibold',
                          hasRealCoordinates(technician) ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700',
                        ].join(' ')}>
                          {hasRealCoordinates(technician) ? 'Gerçek koordinat var' : 'Gerçek koordinat yok'}
                        </span>
                        {hasPlusCodeInfo(technician) ? <span className="inline-flex rounded-full bg-sky-50 px-2 py-0.5 text-[11px] font-semibold text-sky-700">Plus Code var</span> : null}
                        {hasAddressInfo(technician) ? <span className="inline-flex rounded-full bg-slate-100 px-2 py-0.5 text-[11px] font-semibold text-slate-700">Adres var</span> : null}
                        {technician.geocode_status ? <span className="inline-flex rounded-full bg-indigo-50 px-2 py-0.5 text-[11px] font-semibold text-indigo-700">Geocode: {technician.geocode_status}</span> : null}
                        {technician.needs_review && hasRealCoordinates(technician) ? <span className="inline-flex rounded-full bg-amber-100 px-2 py-0.5 text-[11px] font-semibold text-amber-800">Koordinat kontrol gerekli</span> : null}
                      </div>
                      {reviewReasonText(technician) ? <p className="mt-1 line-clamp-2 break-words text-xs text-slate-500">{reviewReasonText(technician)}</p> : null}
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap gap-1">
                        <span className={[
                          'inline-flex rounded-full px-2.5 py-1 text-xs font-semibold',
                          technician.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500',
                        ].join(' ')}>
                          {technician.active ? 'Aktif' : 'Pasif'}
                        </span>
                        {technician.needs_review ? (
                          <span className="inline-flex rounded-full bg-amber-100 px-2.5 py-1 text-xs font-semibold text-amber-800">Kontrol gerekli</span>
                        ) : null}
                      </div>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex flex-wrap justify-end gap-2">
                        <Button type="button" variant="secondary" onClick={() => openEdit(technician)}>Düzenle</Button>
                        {technician.needs_review ? (
                          <Button type="button" variant="outline" onClick={() => void markTechnicianReviewed(technician)}>Kontrol edildi</Button>
                        ) : null}
                        {technician.active ? (
                          <Button type="button" variant="destructive" onClick={() => void disableTechnician(technician)}>Pasifleştir</Button>
                        ) : null}
                      </div>
                    </td>
                  </tr>
                ))}
                {!loading && filteredTechnicians.length === 0 ? (
                  <tr>
                    <td className="px-4 py-8 text-center text-slate-500" colSpan={8}>Kayıt bulunamadı.</td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>
        </section>

        <section className="grid gap-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div>
            <h2 className="text-lg font-semibold text-slate-950">CSV / Excel ile toplu içe aktarma</h2>
            <p className="mt-1 text-sm text-slate-500">
              Bu faz sadece önizleme yapar; usta, partner veya bağlantı kaydı yazmaz.
            </p>
            <code className="mt-3 block overflow-x-auto rounded-xl bg-slate-100 p-3 text-xs text-slate-700">
              {importColumns.join(', ')}
            </code>
            <p className="mt-2 text-xs text-slate-500">
              CSV için virgül, noktalı virgül ve tab ayracı desteklenir. Excel dosyasında “Tam Liste” sayfası varsa otomatik kullanılır.
            </p>
          </div>

          <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Dosya
              <Input
                type="file"
                accept=".csv,.xlsx,.xls,text/csv,text/plain,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet"
                onChange={(event) => {
                  setImportFile(event.target.files?.[0] ?? null)
                  resetImportPreview()
                }}
              />
            </label>
            <div className="flex flex-wrap gap-2">
              <Button type="button" onClick={() => void runImportPreview()} disabled={previewingImport || !importFile}>
                {previewingImport ? 'Önizleniyor...' : 'Önizle / Dry-run'}
              </Button>
              <Button
                type="button"
                variant="secondary"
                onClick={() => {
                  setImportFile(null)
                  resetImportPreview()
                }}
              >
                Sonucu temizle
              </Button>
              <Button type="button" disabled title="Apply Faz 2B'de açılacak">
                Faz 2B’de aktif olacak
              </Button>
            </div>
          </div>

          <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 md:grid-cols-2 xl:grid-cols-4">
            <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input
                type="checkbox"
                checked={importUpdateExisting}
                onChange={(event) => {
                  setImportUpdateExisting(event.target.checked)
                  resetImportPreview()
                }}
              />
              Mevcut kayıtları güncelle
            </label>
            <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input
                type="checkbox"
                checked={importPreserveCoordinates}
                onChange={(event) => {
                  setImportPreserveCoordinates(event.target.checked)
                  resetImportPreview()
                }}
              />
              Mevcut koordinatı koru
            </label>
            <label className="flex items-center gap-2 text-sm font-medium text-slate-700">
              <input
                type="checkbox"
                checked={importLinkPartners}
                onChange={(event) => {
                  setImportLinkPartners(event.target.checked)
                  resetImportPreview()
                }}
              />
              Partner eşleşmesini göster
            </label>
            <label className="grid gap-1 text-sm font-medium text-slate-700">
              Geocode planı
              <select
                value={importGeocodeMode}
                onChange={(event) => {
                  setImportGeocodeMode(event.target.value)
                  resetImportPreview()
                }}
                className={selectClassName}
              >
                <option value="plan_only">Plan oluştur</option>
                <option value="none">Geocode planlama</option>
              </select>
            </label>
          </div>

          {importError ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm font-medium text-rose-700">{importError}</div>
          ) : null}

          {importPreview ? (
            <div className="grid gap-5">
              <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
                <p className="font-semibold">Dry-run tamamlandı. DB yazımı yapılmadı.</p>
                <p className="mt-1">
                  Dosya: {importPreview.file.original_name} · Sayfa: {importPreview.file.sheet_name ?? '-'} · Header satırı: {importPreview.file.detected_header_row ?? '-'}
                </p>
              </div>

              <div className="grid gap-3 sm:grid-cols-2 lg:grid-cols-4 xl:grid-cols-6">
                {[
                  ['Toplam', importPreview.summary.total_rows],
                  ['Parsed', importPreview.summary.parsed_rows],
                  ['Yeni', importPreview.summary.create_count],
                  ['Güncelleme', importPreview.summary.update_count],
                  ['Skip', importPreview.summary.skip_count],
                  ['Hata', importPreview.summary.error_count],
                  ['Uyarı', importPreview.summary.warning_count],
                  ['Duplicate', importPreview.summary.duplicate_count],
                  ['Partner eşleşti', importPreview.summary.partner_link_create_count + importPreview.summary.partner_link_update_count + importPreview.summary.partner_link_skip_count],
                  ['Partner eksik', importPreview.summary.partner_missing_count],
                  ['Geocode hazır', importPreview.summary.geocode_ready_count],
                  ['Geocode uyarı', importPreview.summary.geocode_warning_count],
                  ['Koordinat var', importPreview.summary.geocode_existing_coordinates_count],
                  ['Koordinat korunacak', importPreview.summary.geocode_preserve_existing_count],
                  ['Geocode hata', importPreview.summary.geocode_error_count],
                ].map(([label, value]) => (
                  <div key={label} className="rounded-2xl border border-slate-200 bg-white p-3">
                    <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">{label}</p>
                    <p className="mt-1 text-xl font-semibold text-slate-950">{value}</p>
                  </div>
                ))}
              </div>

              <div className="flex flex-wrap gap-2">
                {[
                  ['all', 'Tümü'],
                  ['create', 'Yeni'],
                  ['update', 'Güncelleme'],
                  ['error', 'Hata'],
                  ['warning', 'Uyarı'],
                  ['duplicate', 'Duplicate'],
                ].map(([value, label]) => (
                  <Button
                    key={value}
                    type="button"
                    variant={importPreviewTab === value ? 'default' : 'secondary'}
                    onClick={() => setImportPreviewTab(value)}
                  >
                    {label}
                  </Button>
                ))}
              </div>

              <div className="max-h-[520px] overflow-auto rounded-2xl border border-slate-200">
                <table className="w-full min-w-[1180px] table-fixed divide-y divide-slate-200 text-sm">
                  <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                    <tr>
                      <th className="w-[7%] px-3 py-3">Satır</th>
                      <th className="w-[17%] px-3 py-3">Usta</th>
                      <th className="w-[12%] px-3 py-3">Telefon</th>
                      <th className="w-[15%] px-3 py-3">Şehir / Adres</th>
                      <th className="w-[10%] px-3 py-3">Aksiyon</th>
                      <th className="w-[15%] px-3 py-3">Eşleşme</th>
                      <th className="w-[13%] px-3 py-3">Geocode</th>
                      <th className="w-[11%] px-3 py-3">Uyarı / Hata</th>
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100 bg-white">
                    {filteredImportRows.map((row) => (
                      <tr key={`${row.row_number}-${row.action}`}>
                        <td className="px-3 py-3 font-semibold text-slate-700">{row.row_number}</td>
                        <td className="min-w-0 px-3 py-3">
                          <p className="truncate font-semibold text-slate-950">{importRowDisplayName(row)}</p>
                          <p className="mt-1 truncate text-xs text-slate-500">{importRowValue(row, 'mikro_cari_kodu') || importRowValue(row, 'source_key') || '-'}</p>
                        </td>
                        <td className="min-w-0 px-3 py-3 text-slate-700">
                          <p className="truncate">{importRowValue(row, 'phone_e164') || importRowValue(row, 'phone') || '-'}</p>
                        </td>
                        <td className="min-w-0 px-3 py-3 text-slate-700">
                          <p className="truncate font-medium">{[importRowValue(row, 'city'), importRowValue(row, 'district')].filter(Boolean).join(' / ') || '-'}</p>
                          <p className="mt-1 line-clamp-2 break-words text-xs text-slate-500">{importRowValue(row, 'address') || importRowValue(row, 'google_formatted_address') || '-'}</p>
                        </td>
                        <td className="px-3 py-3">
                          <span className="inline-flex rounded-full bg-slate-100 px-2.5 py-1 text-xs font-semibold text-slate-700">
                            {importActionLabels[row.action] ?? row.action}
                          </span>
                          <p className="mt-1 text-xs text-slate-500">{importConfidenceLabels[row.confidence] ?? row.confidence}</p>
                        </td>
                        <td className="min-w-0 px-3 py-3 text-slate-700">
                          <p className="line-clamp-2 break-words">{row.existing_match?.name ?? 'Teknisyen eşleşmesi yok'}</p>
                          <p className="mt-1 line-clamp-2 break-words text-xs text-slate-500">{row.partner_match?.name ?? row.link_plan?.reason ?? 'Partner eşleşmesi yok'}</p>
                        </td>
                        <td className="min-w-0 px-3 py-3 text-slate-700">
                          <p className="font-medium">{geocodePlanLabels[row.geocode_plan.status] ?? row.geocode_plan.status}</p>
                          <p className="mt-1 line-clamp-2 break-words text-xs text-slate-500">{row.geocode_plan.reason}</p>
                        </td>
                        <td className="min-w-0 px-3 py-3">
                          {[...row.errors, ...row.warnings, ...row.duplicates].length > 0 ? (
                            <div className="grid gap-1">
                              {row.errors.map((message) => <span key={`error-${message}`} className="rounded-lg bg-rose-50 px-2 py-1 text-xs font-semibold text-rose-700">{message}</span>)}
                              {row.warnings.map((message) => <span key={`warning-${message}`} className="rounded-lg bg-amber-50 px-2 py-1 text-xs font-semibold text-amber-800">{message}</span>)}
                              {row.duplicates.map((message) => <span key={`duplicate-${message}`} className="rounded-lg bg-violet-50 px-2 py-1 text-xs font-semibold text-violet-700">{message}</span>)}
                            </div>
                          ) : (
                            <span className="text-xs text-slate-400">Temiz</span>
                          )}
                        </td>
                      </tr>
                    ))}
                    {filteredImportRows.length === 0 ? (
                      <tr>
                        <td className="px-4 py-8 text-center text-slate-500" colSpan={8}>Bu filtrede satır yok.</td>
                      </tr>
                    ) : null}
                  </tbody>
                </table>
              </div>
            </div>
          ) : null}
        </section>
      </div>

      <Dialog open={dialogOpen} onOpenChange={setDialogOpen}>
        <DialogContent className="max-w-3xl max-h-[92vh] overflow-y-auto">
          <DialogHeader>
            <DialogTitle>{editing ? 'Usta düzenle' : 'Yeni usta'}</DialogTitle>
          </DialogHeader>

          {saveError ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-3 text-sm text-rose-700">{saveError}</div>
          ) : null}
          {coordinateStale ? (
            <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900">
              <p className="font-semibold">Adres değişti, koordinat yeniden doğrulanmalı</p>
              <p className="mt-1">Mevcut koordinat eski adrese ait olabilir. Kaydedilirse kayıt kontrol gerekli olarak işaretlenir.</p>
            </div>
          ) : null}
          {geocodeMessage ? (
            <div className="rounded-2xl border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">{geocodeMessage}</div>
          ) : null}

          <div className="grid gap-5">
            <section className="grid gap-4">
              <h3 className="text-sm font-semibold text-slate-900">Kimlik ve iletişim</h3>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Usta Adı
                  <Input value={form.first_name} onChange={(event) => updateForm('first_name', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Usta Soyadı
                  <Input value={form.last_name} onChange={(event) => updateForm('last_name', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Telefon
                  <Input value={form.phone} onChange={(event) => updateForm('phone', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Aktif
                  <select
                    value={form.active ? '1' : '0'}
                    onChange={(event) => updateForm('active', event.target.value === '1')}
                    className={selectClassName}
                  >
                    <option value="1">Aktif</option>
                    <option value="0">Pasif</option>
                  </select>
                </label>
              </div>
            </section>

            <section className="grid gap-4">
              <h3 className="text-sm font-semibold text-slate-900">Adres ve Google alanları</h3>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  İl
                  <select
                    value={form.city}
                    onChange={(event) => updateForm('city', event.target.value)}
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
                  Plaka Kodu
                  <Input value={form.city_plate_code} onChange={(event) => updateForm('city_plate_code', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  İlçe
                  <select
                    value={form.district}
                    onChange={(event) => updateForm('district', event.target.value)}
                    disabled={!form.city}
                    className={selectClassName}
                  >
                    <option value="">{form.city ? 'Seçiniz' : 'Önce il seçiniz'}</option>
                    {hasDistrictFallback ? (
                      <option value={form.district}>Mevcut değer: {form.district}</option>
                    ) : null}
                    {districtOptions.map((district) => (
                      <option key={district.normalizedName} value={district.name}>
                        {district.name}
                      </option>
                    ))}
                  </select>
                </label>
              </div>
              <label className="grid gap-2 text-sm font-medium text-slate-700">
                Açık Adres
                <textarea
                  value={form.address}
                  onChange={(event) => updateForm('address', event.target.value)}
                  className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                />
              </label>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Konum / Adres Kodu
                  <Input value={form.location_code} onChange={(event) => updateForm('location_code', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Google Konum Kodu (Plus Code)
                  <Input value={form.google_plus_code} onChange={(event) => updateForm('google_plus_code', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Google Doğrulanmış Adres
                  <Input value={form.google_formatted_address} onChange={(event) => updateForm('google_formatted_address', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Latitude
                  <Input type="number" step="0.0000001" value={form.latitude} onChange={(event) => updateForm('latitude', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Longitude
                  <Input type="number" step="0.0000001" value={form.longitude} onChange={(event) => updateForm('longitude', event.target.value)} />
                </label>
              </div>
              {formHasCoordinates(form) ? (
                <p className="rounded-xl border border-emerald-200 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">
                  {editing?.location_source === 'manual' ? 'Manuel koordinat' : 'Gerçek koordinat var'}
                </p>
              ) : null}
              <div className="flex flex-wrap items-center gap-2">
                <Button type="button" variant="outline" onClick={() => void geocodeTechnician(true)} disabled={!editing || geocoding}>
                  Geocode dry-run
                </Button>
                <Button type="button" variant="outline" onClick={() => void geocodeTechnician(false)} disabled={!editing || geocoding}>
                  {geocoding ? 'Google ile güncelleniyor...' : 'Google ile koordinatı güncelle'}
                </Button>
                {editing?.needs_review ? (
                  <Button type="button" variant="outline" onClick={() => void markTechnicianReviewed(editing)} disabled={saving}>
                    Kontrol edildi
                  </Button>
                ) : null}
                {!editing ? <span className="text-xs text-slate-500">Önce kaydı oluşturun.</span> : null}
              </div>
            </section>

            <section className="grid gap-4">
              <h3 className="text-sm font-semibold text-slate-900">Varsayılan başlangıç</h3>
              <label className="grid gap-2 text-sm font-medium text-slate-700">
                Varsayılan Başlangıç Adresi
                <textarea
                  value={form.default_start_address}
                  onChange={(event) => updateForm('default_start_address', event.target.value)}
                  className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                />
              </label>
              <div className="grid gap-4 sm:grid-cols-3">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Varsayılan Başlangıç Plus Code
                  <Input value={form.default_start_plus_code} onChange={(event) => updateForm('default_start_plus_code', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Başlangıç Latitude
                  <Input type="number" step="0.0000001" value={form.start_latitude} onChange={(event) => updateForm('start_latitude', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Başlangıç Longitude
                  <Input type="number" step="0.0000001" value={form.start_longitude} onChange={(event) => updateForm('start_longitude', event.target.value)} />
                </label>
              </div>
            </section>

            <section className="grid gap-4">
              <h3 className="text-sm font-semibold text-slate-900">Mikro cari ve not</h3>
              <div className="grid gap-4 sm:grid-cols-2">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Mikro Cari Kodu
                  <Input value={form.mikro_cari_kodu} onChange={(event) => updateForm('mikro_cari_kodu', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Mikro Cari Adı
                  <Input value={form.mikro_cari_adi} onChange={(event) => updateForm('mikro_cari_adi', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Cari Adres
                  <Input value={form.cari_address} onChange={(event) => updateForm('cari_address', event.target.value)} />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Cari İlçe İl Ülke
                  <Input value={form.cari_city_district_country} onChange={(event) => updateForm('cari_city_district_country', event.target.value)} />
                </label>
              </div>
              <label className="grid gap-2 text-sm font-medium text-slate-700">
                Not
                <textarea
                  value={form.note}
                  onChange={(event) => updateForm('note', event.target.value)}
                  className="min-h-[92px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm text-slate-900 outline-none transition focus:border-ring focus:ring-ring/50 focus:ring-[3px]"
                />
              </label>
            </section>
          </div>

          <DialogFooter>
            <Button type="button" variant="secondary" onClick={() => setDialogOpen(false)}>Vazgeç</Button>
            <Button type="button" onClick={() => void saveTechnician()} disabled={saving}>
              {saving ? 'Kaydediliyor...' : 'Kaydet'}
            </Button>
          </DialogFooter>
        </DialogContent>
      </Dialog>
    </>
  )
}
