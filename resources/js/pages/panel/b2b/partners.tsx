import { Head } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import Heading from '@/components/heading'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type PartnerType = 'dealer' | 'locksmith'
type FormMode = 'create' | 'edit' | 'detail'

type Partner = {
  id: number
  partner_type: PartnerType
  partner_code: string
  display_name: string
  mikro_cari_kodu: string | null
  mikro_cari_unvan: string | null
  cari_grup_kodu: string | null
  responsibility_code: string | null
  phone: string | null
  email: string | null
  city: string | null
  district: string | null
  active: boolean
  technical_service_technician_id: number | null
  linked_technician_name: string | null
  linked_technician_phone: string | null
}

type TechnicianOption = {
  id: number
  name: string
  phone: string | null
  city: string | null
  district: string | null
  mikro_cari_kodu: string | null
  mikro_cari_adi: string | null
  source_key: string
}

type PartnerForm = {
  partner_type: PartnerType
  partner_code: string
  display_name: string
  mikro_cari_kodu: string
  mikro_cari_unvan: string
  cari_grup_kodu: string
  responsibility_code: string
  phone: string
  email: string
  city: string
  district: string
  active: boolean
  technical_service_technician_id: string
}

type Filters = {
  search: string
  partner_type: '' | PartnerType
  active: ''
  city: string
  mikro_cari_kodu: string
}

const emptyForm: PartnerForm = {
  partner_type: 'dealer',
  partner_code: '',
  display_name: '',
  mikro_cari_kodu: '',
  mikro_cari_unvan: '',
  cari_grup_kodu: '',
  responsibility_code: '',
  phone: '',
  email: '',
  city: '',
  district: '',
  active: true,
  technical_service_technician_id: '',
}

const emptyFilters: Filters = {
  search: '',
  partner_type: '',
  active: '',
  city: '',
  mikro_cari_kodu: '',
}

const partnerTypeLabel = (type: PartnerType) => (type === 'dealer' ? 'Bayi' : 'Çilingir')

const locationLabel = (city: string | null, district: string | null) => {
  const parts = [city, district].filter(Boolean)

  return parts.length > 0 ? parts.join(' / ') : '-'
}

const selectedTechnicianLabel = (technicians: TechnicianOption[], id: string) => {
  const technician = technicians.find((item) => String(item.id) === id)

  if (!technician) {
    return null
  }

  return `${technician.name}${technician.phone ? ` · ${technician.phone}` : ''}`
}

export default function B2BPartnersPage() {
  const [partners, setPartners] = useState<Partner[]>([])
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [form, setForm] = useState<PartnerForm>(emptyForm)
  const [formMode, setFormMode] = useState<FormMode>('create')
  const [editingPartner, setEditingPartner] = useState<Partner | null>(null)
  const [technicians, setTechnicians] = useState<TechnicianOption[]>([])
  const [technicianSearch, setTechnicianSearch] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [technicianLoading, setTechnicianLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const hasLocksmithForm = form.partner_type === 'locksmith'
  const activeFilterText = useMemo(() => {
    if (filters.partner_type === 'dealer') {
return 'Bayiler'
}

    if (filters.partner_type === 'locksmith') {
return 'Çilingirler'
}

    return 'Tümü'
  }, [filters.partner_type])

  const loadPartners = useCallback(async () => {
    setLoading(true)
    setError(null)

    try {
      const params = new URLSearchParams()
      Object.entries(filters).forEach(([key, value]) => {
        if (value !== '') {
          params.set(key, value)
        }
      })
      const response = await apiRequest(`/api/b2b/partners?${params.toString()}`)
      setPartners(response.items ?? [])
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Partner listesi alınamadı.')
    } finally {
      setLoading(false)
    }
  }, [filters])

  const loadTechnicians = useCallback(async (search = technicianSearch) => {
    setTechnicianLoading(true)

    try {
      const params = new URLSearchParams()

      if (search.trim() !== '') {
        params.set('search', search.trim())
      }

      const response = await apiRequest(`/api/b2b/locksmith-technicians?${params.toString()}`)
      setTechnicians(response.items ?? [])
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Çilingir listesi alınamadı.')
    } finally {
      setTechnicianLoading(false)
    }
  }, [technicianSearch])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void loadPartners()
    }, 0)

    return () => window.clearTimeout(timer)
  }, [loadPartners])

  useEffect(() => {
    if (hasLocksmithForm) {
      const timer = window.setTimeout(() => {
        void loadTechnicians()
      }, 0)

      return () => window.clearTimeout(timer)
    }

    return undefined
  }, [hasLocksmithForm, loadTechnicians])

  const startCreate = () => {
    setForm(emptyForm)
    setFormMode('create')
    setEditingPartner(null)
    setMessage(null)
    setError(null)
  }

  const startEdit = (partner: Partner, mode: FormMode = 'edit') => {
    setEditingPartner(partner)
    setForm({
      partner_type: partner.partner_type,
      partner_code: partner.partner_code ?? '',
      display_name: partner.display_name ?? '',
      mikro_cari_kodu: partner.mikro_cari_kodu ?? '',
      mikro_cari_unvan: partner.mikro_cari_unvan ?? '',
      cari_grup_kodu: partner.cari_grup_kodu ?? '',
      responsibility_code: partner.responsibility_code ?? '',
      phone: partner.phone ?? '',
      email: partner.email ?? '',
      city: partner.city ?? '',
      district: partner.district ?? '',
      active: partner.active,
      technical_service_technician_id: partner.technical_service_technician_id ? String(partner.technical_service_technician_id) : '',
    })
    setFormMode(mode)
    setMessage(null)
    setError(null)
  }

  const updateForm = <K extends keyof PartnerForm>(key: K, value: PartnerForm[K]) => {
    setForm((current) => {
      if (key === 'partner_type' && value === 'dealer') {
        return { ...current, partner_type: value, technical_service_technician_id: '' }
      }

      return { ...current, [key]: value }
    })
  }

  const submitPartner = async () => {
    setSaving(true)
    setError(null)
    setMessage(null)

    try {
      const payload = {
        ...form,
        technical_service_technician_id: form.technical_service_technician_id === '' ? null : Number(form.technical_service_technician_id),
      }
      const path = editingPartner ? `/api/b2b/partners/${editingPartner.id}` : '/api/b2b/partners'
      const response = await apiRequest(path, {
        method: editingPartner ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      })
      const savedPartner = response.partner as Partner
      setPartners((current) => {
        if (editingPartner) {
          return current.map((partner) => (partner.id === savedPartner.id ? savedPartner : partner))
        }

        return [savedPartner, ...current]
      })
      setEditingPartner(savedPartner)
      setFormMode('edit')
      setMessage(editingPartner ? 'Partner güncellendi.' : 'Partner oluşturuldu.')
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Partner kaydedilemedi.')
    } finally {
      setSaving(false)
    }
  }

  const toggleActive = async (partner: Partner) => {
    setSaving(true)
    setError(null)
    setMessage(null)

    try {
      const response = await apiRequest(`/api/b2b/partners/${partner.id}/active`, {
        method: 'PATCH',
        body: JSON.stringify({ active: !partner.active }),
      })
      const updatedPartner = response.partner as Partner
      setPartners((current) => current.map((item) => (item.id === updatedPartner.id ? updatedPartner : item)))

      if (editingPartner?.id === updatedPartner.id) {
        startEdit(updatedPartner, formMode)
      }

      setMessage(updatedPartner.active ? 'Partner aktif edildi.' : 'Partner pasif edildi.')
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Aktiflik durumu güncellenemedi.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <Head title="B2B Partner Yönetimi" />
      <div className="mx-auto w-full max-w-[1800px] space-y-6 px-4 py-6 md:px-6 lg:px-10">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="B2B Partner Yönetimi"
            description="Bayi ve çilingir partner kayıtları, manuel Mikro cari bilgileri ve teknik servis usta bağlantıları."
          />
          <Button type="button" onClick={startCreate}>Yeni Partner</Button>
        </div>

        {(error || message) && (
          <div className={`rounded-xl border px-4 py-3 text-sm ${error ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'}`}>
            {error ?? message}
          </div>
        )}

        <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex flex-wrap items-center gap-2">
            {(['', 'dealer', 'locksmith'] as const).map((type) => (
              <button
                key={type || 'all'}
                type="button"
                onClick={() => setFilters((current) => ({ ...current, partner_type: type }))}
                className={`rounded-full border px-4 py-2 text-sm font-semibold ${filters.partner_type === type ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 bg-white text-slate-600 hover:bg-slate-50'}`}
              >
                {type === '' ? 'Tümü' : partnerTypeLabel(type)}
              </button>
            ))}
            <span className="ml-auto text-sm text-slate-500">{activeFilterText} · {partners.length} kayıt</span>
          </div>

          <div className="mt-4 grid gap-3 md:grid-cols-5">
            <Input value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} placeholder="Ara: kod, ad, cari" />
            <select className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={filters.active} onChange={(event) => setFilters((current) => ({ ...current, active: event.target.value }))}>
              <option value="">Aktif/Pasif</option>
              <option value="1">Aktif</option>
              <option value="0">Pasif</option>
            </select>
            <Input value={filters.city} onChange={(event) => setFilters((current) => ({ ...current, city: event.target.value }))} placeholder="Şehir" />
            <Input value={filters.mikro_cari_kodu} onChange={(event) => setFilters((current) => ({ ...current, mikro_cari_kodu: event.target.value }))} placeholder="Mikro cari kodu" />
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => setFilters(emptyFilters)}>Temizle</Button>
              <Button type="button" onClick={() => void loadPartners()} disabled={loading}>{loading ? 'Yükleniyor...' : 'Filtrele'}</Button>
            </div>
          </div>
        </section>

        <div className="grid gap-6 xl:grid-cols-[minmax(0,1.45fr)_minmax(420px,0.65fr)]">
          <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-100 text-sm">
                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Tip</th>
                    <th className="px-4 py-3">Partner Kodu</th>
                    <th className="px-4 py-3">Ad / Ünvan</th>
                    <th className="px-4 py-3">Mikro Cari</th>
                    <th className="px-4 py-3">Şehir / İlçe</th>
                    <th className="px-4 py-3">Bağlı Usta</th>
                    <th className="px-4 py-3">Aktif</th>
                    <th className="px-4 py-3">İşlem</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {partners.length === 0 && (
                    <tr>
                      <td className="px-4 py-8 text-center text-slate-500" colSpan={8}>
                        Kayıt bulunamadı.
                      </td>
                    </tr>
                  )}
                  {partners.map((partner) => (
                    <tr key={partner.id} className="align-top hover:bg-slate-50/70">
                      <td className="px-4 py-3">
                        <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${partner.partner_type === 'dealer' ? 'bg-sky-50 text-sky-700' : 'bg-emerald-50 text-emerald-700'}`}>
                          {partnerTypeLabel(partner.partner_type)}
                        </span>
                      </td>
                      <td className="px-4 py-3 font-semibold text-slate-800">{partner.partner_code}</td>
                      <td className="px-4 py-3">
                        <div className="font-semibold text-slate-900">{partner.display_name}</div>
                        <div className="text-xs text-slate-500">{partner.phone ?? '-'}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-800">{partner.mikro_cari_kodu ?? '-'}</div>
                        <div className="max-w-[220px] truncate text-xs text-slate-500">{partner.mikro_cari_unvan ?? '-'}</div>
                      </td>
                      <td className="px-4 py-3 text-slate-700">{locationLabel(partner.city, partner.district)}</td>
                      <td className="px-4 py-3">
                        {partner.partner_type === 'locksmith' ? (
                          <div>
                            <div className="font-medium text-slate-800">{partner.linked_technician_name ?? 'Bağlı usta yok'}</div>
                            <div className="text-xs text-slate-500">{partner.linked_technician_phone ?? ''}</div>
                          </div>
                        ) : (
                          <span className="text-xs text-slate-500">Bayi için kullanılmaz</span>
                        )}
                      </td>
                      <td className="px-4 py-3">
                        <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${partner.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                          {partner.active ? 'Aktif' : 'Pasif'}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-2">
                          <Button type="button" size="sm" variant="outline" onClick={() => startEdit(partner, 'detail')}>Detay</Button>
                          <Button type="button" size="sm" variant="outline" onClick={() => startEdit(partner)}>Düzenle</Button>
                          <Button type="button" size="sm" variant="outline" onClick={() => void toggleActive(partner)} disabled={saving}>
                            {partner.active ? 'Pasif' : 'Aktif'}
                          </Button>
                        </div>
                      </td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>
          </section>

          <aside className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-4">
              <h3 className="text-base font-semibold text-slate-900">
                {formMode === 'create' ? 'Yeni Partner' : formMode === 'detail' ? 'Partner Detayı' : 'Partner Düzenle'}
              </h3>
              <p className="mt-1 text-sm text-slate-500">Mikro cari bağlantısı bu fazda manuel girilir. Gerçek Mikro arama entegrasyonu sonraki fazda bağlanacak.</p>
            </div>

            <div className="grid gap-3">
              <label className="grid gap-1 text-sm font-semibold text-slate-700">
                Partner tipi
                <select
                  className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm"
                  value={form.partner_type}
                  onChange={(event) => updateForm('partner_type', event.target.value as PartnerType)}
                  disabled={formMode === 'detail'}
                >
                  <option value="dealer">Bayi</option>
                  <option value="locksmith">Çilingir</option>
                </select>
              </label>

              <div className="grid gap-3 md:grid-cols-2">
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Partner kodu
                  <Input value={form.partner_code} onChange={(event) => updateForm('partner_code', event.target.value)} disabled={formMode === 'detail'} />
                </label>
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Görünen ad
                  <Input value={form.display_name} onChange={(event) => updateForm('display_name', event.target.value)} disabled={formMode === 'detail'} />
                </label>
              </div>

              <div className="grid gap-3 md:grid-cols-2">
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Mikro cari kodu
                  <Input value={form.mikro_cari_kodu} onChange={(event) => updateForm('mikro_cari_kodu', event.target.value)} disabled={formMode === 'detail'} />
                </label>
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Mikro cari ünvanı
                  <Input value={form.mikro_cari_unvan} onChange={(event) => updateForm('mikro_cari_unvan', event.target.value)} disabled={formMode === 'detail'} />
                </label>
              </div>

              <div className="grid gap-3 md:grid-cols-2">
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Cari grup kodu
                  <Input value={form.cari_grup_kodu} onChange={(event) => updateForm('cari_grup_kodu', event.target.value)} disabled={formMode === 'detail'} />
                </label>
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Sorumluluk kodu
                  <Input value={form.responsibility_code} onChange={(event) => updateForm('responsibility_code', event.target.value)} disabled={formMode === 'detail'} />
                </label>
              </div>

              <div className="grid gap-3 md:grid-cols-2">
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Telefon
                  <Input value={form.phone} onChange={(event) => updateForm('phone', event.target.value)} disabled={formMode === 'detail'} />
                </label>
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  E-posta
                  <Input value={form.email} onChange={(event) => updateForm('email', event.target.value)} disabled={formMode === 'detail'} />
                </label>
              </div>

              <div className="grid gap-3 md:grid-cols-2">
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  İl
                  <Input value={form.city} onChange={(event) => updateForm('city', event.target.value)} disabled={formMode === 'detail'} />
                </label>
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  İlçe
                  <Input value={form.district} onChange={(event) => updateForm('district', event.target.value)} disabled={formMode === 'detail'} />
                </label>
              </div>

              <label className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                <input type="checkbox" checked={form.active} onChange={(event) => updateForm('active', event.target.checked)} disabled={formMode === 'detail'} />
                Aktif partner
              </label>

              {hasLocksmithForm ? (
                <div className="rounded-xl border border-emerald-100 bg-emerald-50/70 p-3">
                  <label className="grid gap-1 text-sm font-semibold text-emerald-900">
                    Teknik Servis Ustası
                    <div className="flex gap-2">
                      <Input value={technicianSearch} onChange={(event) => setTechnicianSearch(event.target.value)} placeholder="Ad, telefon, cari kodu veya şehir" disabled={formMode === 'detail'} />
                      <Button type="button" variant="outline" onClick={() => void loadTechnicians()} disabled={technicianLoading || formMode === 'detail'}>
                        {technicianLoading ? 'Aranıyor...' : 'Ara'}
                      </Button>
                    </div>
                    <select
                      className="mt-2 h-10 rounded-md border border-emerald-200 bg-white px-3 text-sm"
                      value={form.technical_service_technician_id}
                      onChange={(event) => updateForm('technical_service_technician_id', event.target.value)}
                      disabled={formMode === 'detail'}
                    >
                      <option value="">Usta bağlantısı yok</option>
                      {technicians.map((technician) => (
                        <option key={technician.id} value={technician.id}>
                          {technician.name} · {locationLabel(technician.city, technician.district)}
                        </option>
                      ))}
                    </select>
                  </label>
                  {(selectedTechnicianLabel(technicians, form.technical_service_technician_id) || editingPartner?.linked_technician_name) && (
                    <div className="mt-3 rounded-lg bg-white px-3 py-2 text-sm text-emerald-800">
                      Bağlı usta: {selectedTechnicianLabel(technicians, form.technical_service_technician_id) ?? editingPartner?.linked_technician_name}
                    </div>
                  )}
                </div>
              ) : (
                <div className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                  Teknik servis ustası bağlantısı bayi için kullanılmaz.
                </div>
              )}

              <div className="flex flex-wrap justify-end gap-2 pt-2">
                {formMode !== 'create' && <Button type="button" variant="outline" onClick={startCreate}>Yeni kayıt</Button>}
                {formMode === 'detail' && <Button type="button" onClick={() => setFormMode('edit')}>Düzenle</Button>}
                {formMode !== 'detail' && <Button type="button" onClick={() => void submitPartner()} disabled={saving}>{saving ? 'Kaydediliyor...' : 'Kaydet'}</Button>}
              </div>
            </div>
          </aside>
        </div>
      </div>
    </>
  )
}
