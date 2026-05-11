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
  const districtOptions = useMemo(() => getDistrictOptionsForProvince(form.city), [form.city])
  const hasDistrictFallback = form.district.trim() !== ''
    && !districtOptions.some((district) => district.normalizedName === normalizeTurkishLocation(form.district))

  const loadTechnicians = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const response = await apiRequest('/api/technical-service/technicians')
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
  }, [])

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
      technician.phone,
      technician.city,
      technician.district,
      technician.address,
      technician.google_plus_code,
      technician.mikro_cari_kodu,
      technician.mikro_cari_adi,
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
      <Head title="Teknisyen Yönetimi" />

      <div className="relative min-h-screen overflow-hidden bg-[#eaf1f8]">
        <div className="pointer-events-none absolute inset-x-0 top-0 h-80 bg-[radial-gradient(circle_at_top_left,_rgba(15,23,42,0.14),_transparent_38%),radial-gradient(circle_at_top_right,_rgba(37,99,235,0.12),_transparent_34%)]" />
        <div className="relative w-full max-w-none space-y-6 px-4 py-6 md:px-6 xl:px-8 2xl:px-10">
          <section className="relative overflow-hidden rounded-[28px] border border-white/80 bg-white/92 px-5 py-5 shadow-[0_18px_45px_rgba(15,23,42,0.08)] ring-1 ring-slate-200/70 backdrop-blur sm:px-6 sm:py-6">
            <div className="absolute inset-x-0 top-0 h-1.5 bg-slate-950" />
            <div className="flex flex-col gap-5 lg:flex-row lg:items-end lg:justify-between">
              <div className="max-w-3xl">
                <p className="text-[11px] font-semibold uppercase tracking-[0.24em] text-slate-500">TEKNİSYEN EKİBİ</p>
                <h1 className="mt-3 text-3xl font-semibold tracking-tight text-slate-950">Teknisyen Yönetimi</h1>
                <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                  Teknik servis ekibini, konum bilgilerini ve operasyon için kullanılan saha kayıtlarını yönetin.
                </p>
              </div>
              <Button type="button" onClick={openCreate} className="h-11 rounded-xl bg-slate-950 px-5 text-white hover:bg-slate-900">
                Yeni Teknisyen
              </Button>
            </div>
          </section>

          <TechnicalServicePageLinks />

          <section className="grid gap-4 md:grid-cols-2 xl:grid-cols-4">
            {[
              ['Toplam Teknisyen', technicians.length, 'Kayıtlı ekip'],
              ['Aktif Teknisyen', technicians.filter((item) => item.active).length, 'Sahada kullanılabilir'],
              ['Bugün Gösterilen', filteredTechnicians.length, loading ? 'Yükleniyor...' : 'Filtre sonucu'],
              ['Şehir Kapsamı', new Set(technicians.map((item) => item.city).filter(Boolean)).size, 'Aktif bölge sayısı'],
            ].map(([label, value, detail]) => (
              <article key={String(label)} className="rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/70">
                <p className="text-[11px] font-semibold uppercase tracking-[0.18em] text-slate-500">{label}</p>
                <p className="mt-4 text-4xl font-semibold tracking-[-0.04em] text-slate-950">{value}</p>
                <p className="mt-3 text-sm text-slate-600">{detail}</p>
              </article>
            ))}
          </section>

          <section className="grid gap-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/70">
            <div className="flex flex-col gap-1">
              <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Teknisyen Havuzu</p>
              <p className="text-sm text-slate-600">Arama ve operasyon görünümü üzerinden ekip kayıtlarını yönetin.</p>
            </div>

            <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto] md:items-end">
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Arama
              <Input value={search} onChange={(event) => setSearch(event.target.value)} placeholder="Ad, soyad, telefon, il, ilçe veya Mikro cari ile ara" className="h-11 border-slate-200 bg-slate-50" />
            </label>
            <div className="rounded-xl border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-600">
              {loading ? 'Yükleniyor...' : `${filteredTechnicians.length} kayıt`}
            </div>
          </div>

          {error ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">{error}</div>
          ) : null}

          <div className="overflow-x-auto rounded-2xl border border-slate-200">
            <table className="min-w-[1100px] w-full divide-y divide-slate-200 text-sm">
              <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                <tr>
                  <th className="px-4 py-3">Usta</th>
                  <th className="px-4 py-3">Telefon</th>
                  <th className="px-4 py-3">Konum</th>
                  <th className="px-4 py-3">Google / Başlangıç</th>
                  <th className="px-4 py-3">Mikro Cari</th>
                  <th className="px-4 py-3">Durum</th>
                  <th className="px-4 py-3 text-right">İşlem</th>
                </tr>
              </thead>
              <tbody className="divide-y divide-slate-100 bg-white">
                {filteredTechnicians.map((technician) => (
                  <tr key={technician.id} className="transition hover:bg-slate-50">
                    <td className="px-4 py-3">
                      <div className="flex items-start gap-3">
                        <div className="flex h-10 w-10 items-center justify-center rounded-full bg-slate-950 text-sm font-semibold text-white">
                          {displayName(technician).split(' ').map((part) => part[0]).join('').slice(0, 2).toUpperCase()}
                        </div>
                        <div>
                          <p className="font-semibold text-slate-950">{displayName(technician)}</p>
                          {technician.note ? <p className="mt-1 text-xs text-slate-500">{technician.note}</p> : null}
                        </div>
                      </div>
                    </td>
                    <td className="px-4 py-3 text-slate-700">{technician.phone || '-'}</td>
                    <td className="px-4 py-3 text-slate-700">
                      <p>{[technician.city, technician.district].filter(Boolean).join(' / ') || '-'}</p>
                      {technician.address ? <p className="mt-1 max-w-xs truncate text-xs text-slate-500">{technician.address}</p> : null}
                    </td>
                    <td className="max-w-md px-4 py-3 text-slate-700">
                      <p>{technician.google_plus_code || '-'}</p>
                      <p className="mt-1 text-xs text-slate-500">{technician.default_start_plus_code || technician.default_start_address || '-'}</p>
                    </td>
                    <td className="px-4 py-3 text-slate-700">
                      <p>{technician.mikro_cari_kodu || '-'}</p>
                      <p className="mt-1 text-xs text-slate-500">{technician.mikro_cari_adi || '-'}</p>
                    </td>
                    <td className="px-4 py-3">
                      <span className={[
                        'inline-flex rounded-full border px-2.5 py-1 text-xs font-semibold',
                        technician.active ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-slate-200 bg-slate-100 text-slate-500',
                      ].join(' ')}>
                        {technician.active ? 'Aktif' : 'Pasif'}
                      </span>
                    </td>
                    <td className="px-4 py-3">
                      <div className="flex justify-end gap-2">
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
                    <td className="px-4 py-8 text-center text-slate-500" colSpan={7}>Kayıt bulunamadı.</td>
                  </tr>
                ) : null}
              </tbody>
            </table>
          </div>
          </section>

          <section className="grid gap-4 rounded-2xl border border-slate-200/80 bg-white p-5 shadow-sm shadow-slate-200/70">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Toplu İşlem</p>
            <h2 className="mt-2 text-lg font-semibold text-slate-950">CSV ile toplu ekleme</h2>
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

