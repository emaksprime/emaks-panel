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
  city: string
  district: string
  address: string
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
  note: string
  active: boolean
}

type ImportError = {
  row: number
  reason: string
  data: unknown
}

type ImportResult = {
  created_count: number
  skipped_count: number
  errors: ImportError[]
}

const csvColumns = [
  'first_name',
  'last_name',
  'phone',
  'city',
  'district',
  'address',
  'google_plus_code',
  'google_formatted_address',
  'default_start_address',
  'default_start_plus_code',
  'mikro_cari_kodu',
  'mikro_cari_adi',
  'note',
  'active',
]

const emptyForm: TechnicianForm = {
  first_name: '',
  last_name: '',
  phone: '',
  city: '',
  district: '',
  address: '',
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
  note: '',
  active: true,
}

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''

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

export default function TechnicalServiceTechnicians() {
  const [technicians, setTechnicians] = useState<ServiceTechnician[]>([])
  const [loading, setLoading] = useState(true)
  const [error, setError] = useState<string | null>(null)
  const [dialogOpen, setDialogOpen] = useState(false)
  const [editing, setEditing] = useState<ServiceTechnician | null>(null)
  const [form, setForm] = useState<TechnicianForm>(emptyForm)
  const [saving, setSaving] = useState(false)
  const [saveError, setSaveError] = useState<string | null>(null)
  const [search, setSearch] = useState('')
  const [csvFile, setCsvFile] = useState<File | null>(null)
  const [importing, setImporting] = useState(false)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)
  const [typeFilter, setTypeFilter] = useState('')
  const [cityFilter, setCityFilter] = useState('')
  const [activeFilter, setActiveFilter] = useState('')
  const [needsReviewFilter, setNeedsReviewFilter] = useState('')
  const districtOptions = useMemo(() => getDistrictOptionsForProvince(form.city), [form.city])
  const hasDistrictFallback = form.district.trim() !== ''
    && !districtOptions.some((district) => district.normalizedName === normalizeTurkishLocation(form.district))

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

  const openCreate = () => {
    setEditing(null)
    setForm(emptyForm)
    setSaveError(null)
    setDialogOpen(true)
  }

  const openEdit = (technician: ServiceTechnician) => {
    const city = normalizeFormCity(technician.city)
    const district = normalizeFormDistrict(city, technician.district)

    setEditing(technician)
    setForm({
      first_name: technician.first_name ?? technician.name ?? '',
      last_name: technician.last_name ?? '',
      phone: technician.phone ?? '',
      city,
      district,
      address: technician.address ?? '',
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
      active: technician.active,
      note: technician.note ?? '',
    })
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

    setSaving(true)
    setSaveError(null)

    try {
      const payload = {
        first_name: form.first_name.trim(),
        last_name: nullableText(form.last_name),
        phone: nullableText(form.phone),
        city: nullableText(normalizeFormCity(form.city)),
        district: nullableText(normalizeFormDistrict(form.city, form.district)),
        address: nullableText(form.address),
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

  const importCsv = async () => {
    if (!csvFile) {
      setImportResult({
        created_count: 0,
        skipped_count: 0,
        errors: [{ row: 0, reason: 'CSV dosyası seçilmedi.', data: [] }],
      })

      return
    }

    setImporting(true)
    setImportResult(null)

    try {
      const formData = new FormData()
      formData.append('file', csvFile)

      const response = await fetch('/api/technical-service/technicians/import', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
        },
        body: formData,
      })
      const result = await response.json()

      if (!response.ok) {
        setImportResult(result as ImportResult)

        return
      }

      setImportResult(result as ImportResult)
      setCsvFile(null)
      await loadTechnicians()
    } finally {
      setImporting(false)
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
                      <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Konum / Adres Kodu</p>
                      <p className="line-clamp-2 break-words">{technician.location_code || technician.google_plus_code || '-'}</p>
                    </div>
                    {technician.import_note ? (
                      <div className="min-w-0 sm:col-span-2">
                        <p className="text-xs font-semibold uppercase tracking-wide text-slate-400">Durum / Kontrol Notu</p>
                        <p className="line-clamp-2 break-words">{technician.import_note}</p>
                      </div>
                    ) : null}
                  </div>

                  <div className="flex flex-wrap justify-end gap-2">
                    <Button type="button" variant="secondary" onClick={() => openEdit(technician)}>Düzenle</Button>
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
                      {technician.import_note ? <p className="mt-1 line-clamp-2 break-words text-xs text-slate-500">{technician.import_note}</p> : null}
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

        <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div>
            <h2 className="text-lg font-semibold text-slate-950">CSV ile toplu ekleme</h2>
            <p className="mt-1 text-sm text-slate-500">Beklenen kolon sırası:</p>
            <code className="mt-2 block overflow-x-auto rounded-xl bg-slate-100 p-3 text-xs text-slate-700">
              {csvColumns.join(',')}
            </code>
            <p className="mt-2 text-xs text-slate-500">
              Virgül veya noktalı virgül ayracı desteklenir. Türkçe Excel CSV dosyaları okunmaya çalışılır. first_name zorunlu, active sadece 1 veya 0 olmalı.
            </p>
          </div>
          <div className="flex flex-col gap-3 sm:flex-row sm:items-center">
            <Input type="file" accept=".csv,text/csv,text/plain" onChange={(event) => setCsvFile(event.target.files?.[0] ?? null)} />
            <Button type="button" onClick={() => void importCsv()} disabled={importing}>
              {importing ? 'İçe aktarılıyor...' : 'CSV İçe Aktar'}
            </Button>
          </div>
          {importResult ? (
            <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
              <div className="flex flex-wrap gap-4 font-semibold text-slate-700">
                <span>Eklenen: {importResult.created_count}</span>
                <span>Atlanan: {importResult.skipped_count}</span>
                <span>Hata: {importResult.errors.length}</span>
              </div>
              {importResult.errors.length > 0 ? (
                <div className="max-h-72 overflow-auto rounded-xl border border-slate-200 bg-white">
                  {importResult.errors.map((error, index) => (
                    <div key={`${error.row}-${index}`} className="border-b border-slate-100 p-3 last:border-b-0">
                      <p className="font-semibold text-rose-700">Satır {error.row}: {error.reason}</p>
                      <pre className="mt-2 whitespace-pre-wrap break-words text-xs text-slate-500">{JSON.stringify(error.data, null, 2)}</pre>
                    </div>
                  ))}
                </div>
              ) : null}
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
              <p className="text-xs text-slate-500">Canlı Google doğrulaması bu aşamada çalışmaz; alanlar ileride backend entegrasyonu için hazır tutulur.</p>
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
