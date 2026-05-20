import { Head } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Heading from '@/components/heading'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type PartnerType = 'dealer' | 'locksmith' | 'manufacturer' | 'seller'
type FormMode = 'create' | 'edit' | 'detail'
type CariControlStatusFilter = '' | 'new' | 'existing' | 'changed' | 'review_required'

type Partner = {
  id: number
  partner_type: PartnerType
  capabilities?: PartnerType[]
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
  address?: string | null
  tax_no?: string | null
  tax_office?: string | null
  invoice_profile?: Record<string, string | null>
  shipping_profile?: Record<string, string | null>
  source_field_missing?: string[]
  active: boolean
  technical_service_technician_id: number | null
  linked_technician_name: string | null
  linked_technician_phone: string | null
  child_cari_accounts?: CariControlChildAccount[]
  invoice_usage_note?: string | null
  users_count?: number
  active_users_count?: number
}

type TechnicianOption = {
  id: number
  name: string
  display_name?: string
  phone: string | null
  city: string | null
  district: string | null
  address?: string | null
  mikro_cari_kodu: string | null
  mikro_cari_adi: string | null
  cari_code?: string | null
  cari_title?: string | null
  technician_type?: string | null
  active?: boolean
  source_key: string
  match_reason?: string | null
  requires_type_review?: boolean
}

type PartnerForm = {
  capabilities: PartnerType[]
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
  address: string
  active: boolean
  technical_service_technician_id: string
}

type Filters = {
  search: string
  partner_type: '' | PartnerType
  active: '' | '1' | '0'
  city: string
  mikro_cari_kodu: string
}

type CariControlCandidate = {
  mikro_cari_kodu: string
  display_name?: string | null
  mikro_cari_unvan?: string | null
  cari_grup_kodu?: string | null
  responsibility_code?: string | null
  phone?: string | null
  email?: string | null
  city?: string | null
  district?: string | null
  address?: string | null
  tax_no?: string | null
  tax_office?: string | null
  suggested_capabilities?: PartnerType[]
  capabilities?: PartnerType[]
  selected_capabilities?: PartnerType[]
  status?: string | null
  status_label?: string | null
  confidence?: number | null
  existing_partner_id?: number | null
  difference_summary?: string[]
  child_cari_accounts?: CariControlChildAccount[]
  matched_child_cari_codes?: string[]
  search_match?: 'parent' | 'child' | null
}

type CariControlChildAccount = {
  mikro_cari_kodu: string
  mikro_cari_unvan?: string | null
  display_name?: string | null
  usage_type?: string | null
  cari_usage_type?: string | null
  invoice_usage_note?: string | null
  status?: string | null
  status_label?: string | null
}

type CariControlSource = {
  code: string
  name: string
  db_type: string
  active: boolean
  usable_for_b2b_cari_control: boolean
  reason: string
}

type CariControlQuery = {
  key: string
  title: string
  sql: string
}

type CariControlState = {
  status: string
  message: string
  search?: string
  candidates?: CariControlCandidate[]
  items?: CariControlCandidate[]
  excluded_online_retail_count?: number
  source_used?: string | null
  existing_sources?: CariControlSource[]
  source_inventory?: CariControlSource[]
  query_contract?: {
    document_path: string
    mode: string
    discovery_queries?: CariControlQuery[]
    candidate_schema?: string[]
  }
  actions_enabled?: boolean
}

const emptyForm: PartnerForm = {
  capabilities: ['dealer'],
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
  address: '',
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

const partnerTypeLabel = (type: PartnerType) => {
  if (type === 'dealer') {
    return 'Bayi'
  }

  if (type === 'locksmith') {
    return 'Çilingir'
  }

  if (type === 'manufacturer') {
    return 'Üretici'
  }

  return 'Satıcı'
}

const partnerCapabilities = (partner: Partner): PartnerType[] => {
  const capabilities = partner.capabilities?.filter((capability): capability is PartnerType => ['dealer', 'locksmith', 'manufacturer', 'seller'].includes(capability)) ?? []

  return capabilities.length > 0 ? capabilities : [partner.partner_type]
}

const capabilityChips = (capabilities: PartnerType[]) => capabilities.map((capability) => (
  <span
    key={capability}
    className={`rounded-full px-2.5 py-1 text-xs font-semibold ${capability === 'dealer' ? 'bg-sky-50 text-sky-700' : capability === 'locksmith' ? 'bg-emerald-50 text-emerald-700' : capability === 'manufacturer' ? 'bg-violet-50 text-violet-700' : 'bg-amber-50 text-amber-700'}`}
  >
    {partnerTypeLabel(capability)}
  </span>
))

const locationLabel = (city: string | null, district: string | null) => {
  const parts = [city, district].filter(Boolean)

  return parts.length > 0 ? parts.join(' / ') : '-'
}

const selectedTechnician = (technicians: TechnicianOption[], id: string) => technicians.find((item) => String(item.id) === id) ?? null

const selectedTechnicianLabel = (technicians: TechnicianOption[], id: string) => {
  const technician = selectedTechnician(technicians, id)

  if (!technician) {
    return null
  }

  return `${technician.name}${technician.phone ? ` · ${technician.phone}` : ''}`
}

const primaryPartnerType = (capabilities: PartnerType[]): PartnerType => {
  if (capabilities.includes('dealer')) {
    return 'dealer'
  }

  if (capabilities.includes('locksmith')) {
    return 'locksmith'
  }

  if (capabilities.includes('manufacturer')) {
    return 'manufacturer'
  }

  return 'seller'
}

const candidateCapabilities = (candidate: CariControlCandidate): PartnerType[] => {
  const capabilities = (candidate.capabilities ?? candidate.suggested_capabilities ?? [])
    .filter((capability): capability is PartnerType => ['dealer', 'locksmith', 'manufacturer', 'seller'].includes(capability))

  return capabilities.length > 0 ? capabilities : ['dealer']
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
  const [cariChecking, setCariChecking] = useState(false)
  const [locksmithSyncing, setLocksmithSyncing] = useState(false)
  const [cariControl, setCariControl] = useState<CariControlState | null>(null)
  const [cariControlOpen, setCariControlOpen] = useState(false)
  const [cariSearch, setCariSearch] = useState('')
  const [cariCapabilityFilter, setCariCapabilityFilter] = useState<'' | PartnerType>('')
  const [cariStatusFilter, setCariStatusFilter] = useState<CariControlStatusFilter>('')
  const [selectedCariCodes, setSelectedCariCodes] = useState<string[]>([])
  const [selectedCariCandidates, setSelectedCariCandidates] = useState<Record<string, CariControlCandidate>>({})
  const [candidateCapabilitySelections, setCandidateCapabilitySelections] = useState<Record<string, PartnerType[]>>({})
  const skipNextCariSearchEffect = useRef(false)
  const cariControlRequestId = useRef(0)
  const cariControlAbortController = useRef<AbortController | null>(null)
  const [error, setError] = useState<string | null>(null)
  const [message, setMessage] = useState<string | null>(null)

  const hasLocksmithForm = form.capabilities.includes('locksmith')
  const hasMikroForm = form.capabilities.some((capability) => ['dealer', 'manufacturer', 'seller'].includes(capability))
  const cariCandidates = useMemo(() => cariControl?.candidates ?? cariControl?.items ?? [], [cariControl])
  const selectedCariItems = useMemo(
    () => selectedCariCodes.map((code) => selectedCariCandidates[code]).filter((candidate): candidate is CariControlCandidate => Boolean(candidate)),
    [selectedCariCandidates, selectedCariCodes],
  )
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

      if (form.mikro_cari_kodu.trim() !== '') {
        params.set('mikro_cari_kodu', form.mikro_cari_kodu.trim())
      }

      if (form.phone.trim() !== '') {
        params.set('phone', form.phone.trim())
      }

      if (form.city.trim() !== '') {
        params.set('city', form.city.trim())
      }

      const response = await apiRequest(`/api/b2b/locksmith-technicians?${params.toString()}`)
      setTechnicians(response.items ?? [])
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Çilingir listesi alınamadı.')
    } finally {
      setTechnicianLoading(false)
    }
  }, [form.city, form.mikro_cari_kodu, form.phone, technicianSearch])

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
      capabilities: partnerCapabilities(partner),
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
      address: partner.address ?? '',
      active: partner.active,
      technical_service_technician_id: partner.technical_service_technician_id ? String(partner.technical_service_technician_id) : '',
    })
    setFormMode(mode)
    setMessage(null)
    setError(null)
  }

  const updateForm = <K extends keyof PartnerForm>(key: K, value: PartnerForm[K]) => {
    setForm((current) => ({ ...current, [key]: value }))
  }

  const toggleCapability = (capability: PartnerType) => {
    setForm((current) => {
      const nextCapabilities = current.capabilities.includes(capability)
        ? current.capabilities.filter((item) => item !== capability)
        : [...current.capabilities, capability]
      const normalizedCapabilities = nextCapabilities.length > 0 ? nextCapabilities : current.capabilities

      return {
        ...current,
        capabilities: normalizedCapabilities,
        technical_service_technician_id: normalizedCapabilities.includes('locksmith') ? current.technical_service_technician_id : '',
      }
    })
  }

  const selectTechnician = (technicianId: string) => {
    const technician = selectedTechnician(technicians, technicianId)

    setForm((current) => ({
      ...current,
      technical_service_technician_id: technicianId,
      display_name: technician && current.display_name.trim() === '' ? technician.display_name ?? technician.name : current.display_name,
      phone: technician && current.phone.trim() === '' ? technician.phone ?? '' : current.phone,
      city: technician && current.city.trim() === '' ? technician.city ?? '' : current.city,
      district: technician && current.district.trim() === '' ? technician.district ?? '' : current.district,
      address: technician && current.address.trim() === '' ? technician.address ?? '' : current.address,
      mikro_cari_kodu: technician && current.mikro_cari_kodu.trim() === '' ? technician.mikro_cari_kodu ?? technician.cari_code ?? '' : current.mikro_cari_kodu,
      mikro_cari_unvan: technician && current.mikro_cari_unvan.trim() === '' ? technician.mikro_cari_adi ?? technician.cari_title ?? '' : current.mikro_cari_unvan,
    }))
  }

  const closeCariControlModal = useCallback(() => {
    cariControlRequestId.current += 1
    cariControlAbortController.current?.abort()
    setCariControlOpen(false)
  }, [])

  const runCariControl = useCallback(async (options: { search?: string; resetSelection?: boolean } = {}) => {
    const requestId = ++cariControlRequestId.current
    cariControlAbortController.current?.abort()
    const abortController = new AbortController()
    cariControlAbortController.current = abortController

    setCariChecking(true)

    if (options.resetSelection) {
      setSelectedCariCodes([])
      setSelectedCariCandidates({})
      setCandidateCapabilitySelections({})
    }

    setCariControlOpen(true)
    setError(null)
    setMessage(null)

    try {
      const search = (options.search ?? (cariSearch || filters.mikro_cari_kodu || filters.search)).trim()
      const params = new URLSearchParams()

      if (search !== '') {
        params.set('search', search)
      }

      if (cariCapabilityFilter !== '') {
        params.set('capability', cariCapabilityFilter)
      }

      if (cariStatusFilter !== '') {
        params.set('status', cariStatusFilter)
      }

      params.set('include_review_required', '1')

      const response = await fetch(`/api/b2b/cari-control${params.toString() ? `?${params.toString()}` : ''}`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        signal: abortController.signal,
      })

      if (requestId !== cariControlRequestId.current) {
        return
      }

      const payload = await response.json().catch(() => null)
      const fallbackMessage = response.ok ? 'Cari kontrol sonucu alındı.' : 'Cari adayları alınamadı. Gateway hatası varsa ekranda hata olarak gösterilir.'

      if (requestId !== cariControlRequestId.current) {
        return
      }

      const nextControl = {
        ...payload,
        status: payload?.status ?? (response.ok ? 'ok' : 'error'),
        message: payload?.message ?? fallbackMessage,
      }
      const nextCandidates = nextControl.candidates ?? nextControl.items ?? []
      const nextSelections = Object.fromEntries(
        nextCandidates.map((candidate: CariControlCandidate) => [candidate.mikro_cari_kodu, candidateCapabilities(candidate)]),
      )
      setCandidateCapabilitySelections((current) => ({ ...nextSelections, ...current }))
      setCariControl(nextControl)
    } catch (searchError) {
      if (searchError instanceof DOMException && searchError.name === 'AbortError') {
        return
      }

      if (requestId !== cariControlRequestId.current) {
        return
      }

      setCariControl({
        status: 'error',
        message: 'Cari adayları alınamadı. Gateway bağlantısı veya oturum durumunu kontrol edin.',
      })
    } finally {
      if (requestId === cariControlRequestId.current) {
        setCariChecking(false)
      }
    }
  }, [cariCapabilityFilter, cariSearch, cariStatusFilter, filters.mikro_cari_kodu, filters.search])

  useEffect(() => {
    if (!cariControlOpen) {
      return undefined
    }

    if (skipNextCariSearchEffect.current) {
      skipNextCariSearchEffect.current = false

      return undefined
    }

    const search = cariSearch.trim()

    if (search !== '' && search.length < 2) {
      return undefined
    }

    const timer = window.setTimeout(() => {
      void runCariControl({ search })
    }, 300)

    return () => window.clearTimeout(timer)
  }, [cariCapabilityFilter, cariControlOpen, cariSearch, cariStatusFilter, runCariControl])

  useEffect(() => {
    if (!cariControlOpen) {
      return undefined
    }

    const onKeyDown = (event: KeyboardEvent) => {
      if (event.key === 'Escape') {
        closeCariControlModal()
      }
    }

    window.addEventListener('keydown', onKeyDown)

    return () => {
      window.removeEventListener('keydown', onKeyDown)
    }
  }, [cariControlOpen, closeCariControlModal])

  const toggleCariCandidate = (candidate: CariControlCandidate) => {
    const mikroCariKodu = candidate.mikro_cari_kodu

    setSelectedCariCodes((current) => (
      current.includes(mikroCariKodu)
        ? current.filter((code) => code !== mikroCariKodu)
        : [...current, mikroCariKodu]
    ))
    setSelectedCariCandidates((current) => {
      if (current[mikroCariKodu]) {
        const next = { ...current }
        delete next[mikroCariKodu]

        return next
      }

      return { ...current, [mikroCariKodu]: candidate }
    })
  }

  const toggleCandidateCapability = (mikroCariKodu: string, capability: PartnerType) => {
    setCandidateCapabilitySelections((current) => {
      const currentCapabilities = current[mikroCariKodu] ?? ['dealer']
      const nextCapabilities = currentCapabilities.includes(capability)
        ? currentCapabilities.filter((item) => item !== capability)
        : [...currentCapabilities, capability]

      return {
        ...current,
        [mikroCariKodu]: nextCapabilities.length > 0 ? nextCapabilities : currentCapabilities,
      }
    })
  }

  const importSelectedCariCandidates = async () => {
    const candidates = selectedCariItems

    if (candidates.length === 0) {
      setError('Partner oluşturmak veya güncellemek için en az bir cari adayı seçin.')

      return
    }

    setSaving(true)
    setError(null)
    setMessage(null)

    try {
      const payload = await apiRequest('/api/b2b/cari-control/apply', {
        method: 'POST',
        body: JSON.stringify({
          action: 'import',
          candidates: candidates.map((candidate) => ({
            ...candidate,
            selected_capabilities: candidateCapabilitySelections[candidate.mikro_cari_kodu] ?? candidateCapabilities(candidate),
          })),
        }),
      })
      setSelectedCariCodes([])
      setSelectedCariCandidates({})
      setMessage(`${payload.items?.length ?? candidates.length} cari adayı işlendi.`)

      const defaultUsers = (payload.items ?? [])
        .map((item: { default_user?: { username?: string; default_password?: string } }) => item.default_user)
        .filter((user: { username?: string; default_password?: string } | undefined): user is { username?: string; default_password?: string } => Boolean(user?.username))

      if (defaultUsers.length > 0) {
        setMessage(`${payload.items?.length ?? candidates.length} cari adayi islendi. Bayi kullanicisi olusturuldu: ${defaultUsers.map((user) => user.username).join(', ')}. Varsayilan sifre: 12345678`)
      }

      await loadPartners()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Cari adayları işlenemedi.')
    } finally {
      setSaving(false)
    }
  }

  const syncLocksmithTechnicians = async () => {
    setLocksmithSyncing(true)
    setError(null)
    setMessage(null)

    try {
      const payload = await apiRequest('/api/b2b/locksmith-technicians/sync', {
        method: 'POST',
      })
      setMessage(`Çilingir eşitleme tamamlandı. Oluşturulan: ${payload.created ?? 0}, güncellenen: ${payload.updated ?? 0}, rol eklenen: ${payload.capability_added ?? 0}, kontrol gerekli: ${payload.review_required ?? 0}, atlanan: ${payload.skipped ?? 0}.`)
      await loadPartners()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Çilingirler eşitlenemedi.')
    } finally {
      setLocksmithSyncing(false)
    }
  }

  const submitPartner = async () => {
    setSaving(true)
    setError(null)
    setMessage(null)

    try {
      const payload = {
        ...form,
        partner_type: primaryPartnerType(form.capabilities),
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
      setForm({
        capabilities: partnerCapabilities(savedPartner),
        partner_code: savedPartner.partner_code ?? '',
        display_name: savedPartner.display_name ?? '',
        mikro_cari_kodu: savedPartner.mikro_cari_kodu ?? '',
        mikro_cari_unvan: savedPartner.mikro_cari_unvan ?? '',
        cari_grup_kodu: savedPartner.cari_grup_kodu ?? '',
        responsibility_code: savedPartner.responsibility_code ?? '',
        phone: savedPartner.phone ?? '',
        email: savedPartner.email ?? '',
        city: savedPartner.city ?? '',
        district: savedPartner.district ?? '',
        address: savedPartner.address ?? '',
        active: savedPartner.active,
        technical_service_technician_id: savedPartner.technical_service_technician_id ? String(savedPartner.technical_service_technician_id) : '',
      })
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
            description="Bayi ve çilingir partner rolleri, manuel Mikro cari bağlantısı ve teknik servis usta eşleştirmesi."
          />
          <div className="flex flex-wrap items-center gap-2">
            <Button
              type="button"
              variant="outline"
              onClick={() => {
                const nextSearch = (filters.mikro_cari_kodu || filters.search).trim()
                skipNextCariSearchEffect.current = true
                setCariSearch(nextSearch)
                void runCariControl({ search: nextSearch, resetSelection: true })
              }}
              disabled={cariChecking}
            >
              {cariChecking ? 'Kontrol ediliyor...' : 'Cari Kontrol'}
            </Button>
            <Button type="button" variant="outline" onClick={() => void syncLocksmithTechnicians()} disabled={locksmithSyncing}>
              {locksmithSyncing ? 'Eşitleniyor...' : 'Çilingirleri eşitle'}
            </Button>
            <Button type="button" onClick={startCreate}>Yeni Partner</Button>
          </div>
        </div>
        {(error || message || cariControl) && (
          <div className={`rounded-xl border px-4 py-3 text-sm ${error ? 'border-rose-200 bg-rose-50 text-rose-700' : cariControl ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-700'}`}>
            {error ?? cariControl?.message ?? message}
          </div>
        )}

        {cariControlOpen && (
          <div
            className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-950/40 px-4 py-8"
            onMouseDown={(event) => {
              if (event.target === event.currentTarget) {
                closeCariControlModal()
              }
            }}
          >
          <section className="w-full max-w-6xl rounded-2xl border border-amber-200 bg-amber-50/95 p-4 text-sm text-amber-950 shadow-2xl">
            <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
              <div>
                <h2 className="text-base font-semibold">Cari Kontrol</h2>
                <p className="mt-1 max-w-4xl text-amber-800">
                  Gateway üzerinden SELECT-only cari adayları çekilir, PostgreSQL snapshot üzerinden listelenir. Otomatik partner açılmaz.
                </p>
              </div>
              <div className="flex items-center gap-2">
                <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-amber-800">{cariControl?.status ?? 'Kontrol ediliyor...'}</span>
                <Button type="button" variant="outline" onClick={closeCariControlModal}>Kapat</Button>
              </div>
            </div>

            {cariControl.query_contract && (
              <div className="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.45fr)]">
                <div className="rounded-xl border border-amber-200 bg-white p-3">
                  <div className="text-sm font-semibold text-slate-900">Sorgu sözleşmesi</div>
                  <p className="mt-1 text-slate-600">{cariControl.query_contract.document_path}</p>
                  <p className="mt-2 text-xs font-medium text-slate-500">Mod: {cariControl.query_contract.mode}</p>
                  <div className="mt-3 grid gap-2">
                    {(cariControl.query_contract.discovery_queries ?? []).map((query) => (
                      <details key={query.key} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                        <summary className="cursor-pointer text-sm font-semibold text-slate-700">{query.title}</summary>
                        <pre className="mt-2 whitespace-pre-wrap rounded-lg bg-slate-950 p-3 text-xs text-slate-50">{query.sql}</pre>
                      </details>
                    ))}
                  </div>
                </div>
                <div className="rounded-xl border border-amber-200 bg-white p-3">
                  <div className="text-sm font-semibold text-slate-900">Mevcut kaynak envanteri</div>
                  <div className="mt-3 grid gap-2">
                    {(cariControl.existing_sources ?? []).length === 0 ? (
                      <p className="text-slate-600">B2B için onaylı cari kaynağı bulunamadı.</p>
                    ) : (
                      (cariControl.existing_sources ?? []).map((source) => (
                        <div key={source.code} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
                          <div className="font-semibold text-slate-800">{source.code}</div>
                          <div className="text-xs text-slate-500">{source.name} · {source.db_type} · {source.active ? 'aktif' : 'pasif'}</div>
                          <div className="mt-1 text-xs text-amber-700">{source.reason}</div>
                        </div>
                      ))
                    )}
                  </div>
                </div>
              </div>
            )}

            <div className="mt-4 rounded-xl border border-amber-200 bg-white p-3">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div>
                  <div className="text-sm font-semibold text-slate-900">Cari adayları</div>
                  <p className="mt-1 text-slate-600">Aday gelirse kullanıcı seçer; partner açma/güncelleme/rol ekleme otomatik çalışmaz.</p>
                </div>
                <Button type="button" variant="outline" onClick={() => void importSelectedCariCandidates()} disabled={saving || !cariControl.actions_enabled || selectedCariCodes.length === 0}>
                  Seçili adayları işle
                </Button>
              </div>

              <form
                className="mt-3 grid gap-2 rounded-xl border border-slate-200 bg-slate-50 p-3 lg:grid-cols-[minmax(0,1fr)_180px_180px_auto_auto]"
                onSubmit={(event) => {
                  event.preventDefault()
                  const search = cariSearch.trim()

                  if (search === '' || search.length >= 2) {
                    void runCariControl({ search })
                  }
                }}
              >
                <Input
                  value={cariSearch}
                  onChange={(event) => setCariSearch(event.target.value)}
                  placeholder="Cari kodu, ünvan, telefon, şehir, grup veya alt cari ara"
                />
                <select
                  className="h-10 w-full min-w-0 max-w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                  value={cariCapabilityFilter}
                  onChange={(event) => setCariCapabilityFilter(event.target.value as '' | PartnerType)}
                >
                  <option value="">Tüm roller</option>
                  <option value="dealer">Bayi</option>
                  <option value="locksmith">Çilingir</option>
                  <option value="manufacturer">Üretici</option>
                  <option value="seller">Satıcı</option>
                </select>
                <select
                  className="h-10 w-full min-w-0 max-w-full rounded-md border border-slate-200 bg-white px-3 text-sm"
                  value={cariStatusFilter}
                  onChange={(event) => setCariStatusFilter(event.target.value as CariControlStatusFilter)}
                >
                  <option value="">Tüm durumlar</option>
                  <option value="new">Yeni</option>
                  <option value="existing">Mevcut</option>
                  <option value="changed">Güncellenecek</option>
                  <option value="review_required">Kontrol gerekli</option>
                </select>
                <Button type="submit" variant="outline" disabled={cariChecking || (cariSearch.trim() !== '' && cariSearch.trim().length < 2)}>
                  Ara
                </Button>
                <Button
                  type="button"
                  variant="outline"
                  onClick={() => {
                    setCariSearch('')
                    setCariCapabilityFilter('')
                    setCariStatusFilter('')
                  }}
                  disabled={cariChecking}
                >
                  Temizle
                </Button>
              </form>

              <div className="mt-2 flex flex-wrap gap-3 text-xs text-slate-600">
                <span>Kaynak: {cariControl.source_used ?? '-'}</span>
                <span>{cariCandidates.length} aday bulundu</span>
                <span>Online perakende hariç: {cariControl.excluded_online_retail_count ?? 0}</span>
              </div>

              {cariChecking && (
                <div className="mt-3 rounded-lg border border-blue-100 bg-blue-50 px-3 py-2 text-sm font-semibold text-blue-700">
                  Cariler aranıyor...
                </div>
              )}

              {selectedCariItems.length > 0 && (
                <div className="mt-3 rounded-xl border border-emerald-100 bg-emerald-50 px-3 py-2">
                  <div className="text-xs font-semibold uppercase tracking-wide text-emerald-700">Seçilenler</div>
                  <div className="mt-2 flex flex-wrap gap-2">
                    {selectedCariItems.map((candidate) => (
                      <span key={candidate.mikro_cari_kodu} className="inline-flex items-center gap-2 rounded-full bg-white px-3 py-1 text-xs font-semibold text-emerald-800">
                        {candidate.mikro_cari_kodu}
                        <button type="button" className="text-emerald-500 hover:text-emerald-800" onClick={() => toggleCariCandidate(candidate)}>
                          Kaldır
                        </button>
                      </span>
                    ))}
                  </div>
                </div>
              )}

              {cariCandidates.length === 0 && cariSearch.trim() !== '' && (
                <div className="mt-3 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-slate-600">
                  Eşleşen cari bulunamadı.
                </div>
              )}

              {cariCandidates.length === 0 && cariSearch.trim() === '' ? (
                <div className="mt-3 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-slate-600">
                  Aday verisi yok. Gateway hata verdiyse durum hata olarak görünür; kayıt açmak için önce listeden cari adayı gelmelidir.
                </div>
              ) : cariCandidates.length > 0 ? (
                <div className="mt-3 grid gap-2">
                  {cariCandidates.map((candidate) => (
                    <article key={candidate.mikro_cari_kodu} className="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-3">
                      <input
                        type="checkbox"
                        className="mt-1"
                        checked={selectedCariCodes.includes(candidate.mikro_cari_kodu)}
                        onChange={() => toggleCariCandidate(candidate)}
                        disabled={!cariControl.actions_enabled}
                      />
                      <div className="min-w-0 flex-1">
                        <span className="block font-semibold text-slate-900">{candidate.display_name ?? candidate.mikro_cari_unvan ?? candidate.mikro_cari_kodu}</span>
                        <span className="mt-1 block text-xs text-slate-500">
                          {candidate.mikro_cari_kodu} · {candidate.city ?? '-'} / {candidate.district ?? '-'} · {candidate.status_label ?? candidate.status ?? 'Kontrol gerekli'}
                        </span>
                        <span className="mt-1 block text-xs text-slate-500">
                          Tel: {candidate.phone ?? '-'} · E-posta: {candidate.email ?? '-'} · Adres: {candidate.address ?? 'Mikro kaynağından gelmedi'}
                        </span>
                        {(candidate.child_cari_accounts ?? []).length > 0 && (
                          <div className="mt-2 grid gap-1">
                            {(candidate.child_cari_accounts ?? []).map((child) => {
                              const isMatched = (candidate.matched_child_cari_codes ?? []).includes(child.mikro_cari_kodu)

                              return (
                                <div key={child.mikro_cari_kodu} className={`rounded-lg border px-2 py-1 text-xs ${isMatched ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-slate-200 bg-white text-slate-600'}`}>
                                  <span className="font-semibold">{child.cari_usage_type ?? 'Alt cari'}</span>
                                  <span className="ml-2">{child.mikro_cari_kodu}</span>
                                  {(child.display_name ?? child.mikro_cari_unvan) && <span className="ml-2">{child.display_name ?? child.mikro_cari_unvan}</span>}
                                </div>
                              )
                            })}
                          </div>
                        )}
                        {candidate.existing_partner_id && (
                          <span className="mt-2 inline-flex rounded-full bg-blue-50 px-2.5 py-1 text-xs font-semibold text-blue-700">Mevcut partner bulundu</span>
                        )}
                        <div className="mt-2 grid gap-1 sm:grid-cols-4">
                          {(['dealer', 'locksmith', 'manufacturer', 'seller'] as const).map((capability) => (
                            <span key={capability} className="inline-flex items-center gap-1 rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-700">
                              <input
                                type="checkbox"
                                checked={(candidateCapabilitySelections[candidate.mikro_cari_kodu] ?? candidateCapabilities(candidate)).includes(capability)}
                                onChange={() => toggleCandidateCapability(candidate.mikro_cari_kodu, capability)}
                                disabled={!cariControl.actions_enabled}
                              />
                              {partnerTypeLabel(capability)}
                            </span>
                          ))}
                        </div>
                      </div>
                    </article>
                  ))}
                </div>
              ) : null}
            </div>
          </section>
          </div>
        )}

        <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="flex flex-wrap items-center gap-2">
            {(['', 'dealer', 'locksmith', 'manufacturer', 'seller'] as const).map((type) => (
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

          <div className="mt-4 grid gap-3 grid-cols-1 md:grid-cols-2 xl:grid-cols-5">
            <Input className="w-full min-w-0 max-w-full" value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} placeholder="Ara: kod, ad, cari" />
            <select className="h-10 w-full min-w-0 max-w-full rounded-md border border-slate-200 bg-white px-3 text-sm" value={filters.active} onChange={(event) => setFilters((current) => ({ ...current, active: event.target.value as Filters['active'] }))}>
              <option value="">Aktif/Pasif</option>
              <option value="1">Aktif</option>
              <option value="0">Pasif</option>
            </select>
            <Input className="w-full min-w-0 max-w-full" value={filters.city} onChange={(event) => setFilters((current) => ({ ...current, city: event.target.value }))} placeholder="Şehir" />
            <Input className="w-full min-w-0 max-w-full" value={filters.mikro_cari_kodu} onChange={(event) => setFilters((current) => ({ ...current, mikro_cari_kodu: event.target.value }))} placeholder="Mikro cari kodu" />
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => setFilters(emptyFilters)}>Temizle</Button>
              <Button type="button" onClick={() => void loadPartners()} disabled={loading}>{loading ? 'Yükleniyor...' : 'Filtrele'}</Button>
            </div>
          </div>
        </section>

        <div className="grid gap-6 grid-cols-1 xl:grid-cols-[minmax(0,1fr)_420px]">
          <section className="min-w-0 max-w-full rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
            {partners.length === 0 ? (
              <div className="rounded-xl border border-dashed border-slate-200 bg-slate-50 px-4 py-10 text-center text-sm text-slate-500">
                Kayıt bulunamadı.
              </div>
            ) : (
              <div className="grid gap-3">
                {partners.map((partner) => {
                  const capabilities = partnerCapabilities(partner)

                  return (
                    <article key={partner.id} className="rounded-2xl border border-slate-200 bg-slate-50/60 p-4 transition hover:border-blue-200 hover:bg-blue-50/30">
                      <div className="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
                        <div className="min-w-0 space-y-2">
                          <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-semibold text-slate-950">{partner.display_name}</h3>
                            {capabilityChips(capabilities)}
                            <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${partner.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                              {partner.active ? 'Aktif' : 'Pasif'}
                            </span>
                          </div>
                          <div className="flex flex-wrap gap-x-5 gap-y-1 text-sm text-slate-600">
                            <span><strong className="text-slate-800">Kod:</strong> {partner.partner_code}</span>
                            <span><strong className="text-slate-800">Cari:</strong> {partner.mikro_cari_kodu ?? '-'}</span>
                            <span><strong className="text-slate-800">Telefon:</strong> {partner.phone ?? '-'}</span>
                            <span><strong className="text-slate-800">E-posta:</strong> {partner.email ?? '-'}</span>
                            <span><strong className="text-slate-800">Konum:</strong> {locationLabel(partner.city, partner.district)}</span>
                            <span><strong className="text-slate-800">Kullanıcı:</strong> {partner.active_users_count ?? 0}/{partner.users_count ?? 0}</span>
                          </div>
                          <div className="grid gap-2 text-sm text-slate-600 md:grid-cols-3">
                            <div className="rounded-xl bg-white px-3 py-2">
                              <span className="block text-xs font-semibold uppercase tracking-wide text-slate-400">Mikro cari ünvanı</span>
                              <span className="line-clamp-1">{partner.mikro_cari_unvan ?? '-'}</span>
                            </div>
                            <div className="rounded-xl bg-white px-3 py-2">
                              <span className="block text-xs font-semibold uppercase tracking-wide text-slate-400">Bağlı usta</span>
                              <span className="line-clamp-1">{capabilities.includes('locksmith') ? partner.linked_technician_name ?? 'Teknik servis ustası bağlı değil' : '-'}</span>
                            </div>
                            <div className="rounded-xl bg-white px-3 py-2">
                              <span className="block text-xs font-semibold uppercase tracking-wide text-slate-400">Açık adres</span>
                              <span className="line-clamp-1">{partner.address ?? 'Mikro kaynağından gelmedi'}</span>
                            </div>
                          </div>
                          {(partner.source_field_missing ?? []).length > 0 && (
                            <div className="rounded-xl border border-amber-100 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-800">
                              Mikro kaynağından adres/telefon gelmedi: {(partner.source_field_missing ?? []).join(', ')}
                            </div>
                          )}
                        </div>
                        <div className="flex flex-wrap gap-2 lg:justify-end">
                          <Button type="button" size="sm" variant="outline" onClick={() => startEdit(partner, 'detail')}>Detay</Button>
                          <Button type="button" size="sm" variant="outline" onClick={() => startEdit(partner)}>Düzenle</Button>
                          <Button
                            type="button"
                            size="sm"
                            variant="outline"
                            onClick={() => {
                              window.location.href = '/panel/b2b/users'
                            }}
                          >
                            Kullanıcılar
                          </Button>
                          <Button type="button" size="sm" variant="outline" onClick={() => void toggleActive(partner)} disabled={saving}>
                            {partner.active ? 'Pasif' : 'Aktif'}
                          </Button>
                        </div>
                      </div>
                    </article>
                  )
                })}
              </div>
            )}
          </section>

          <aside className="min-w-0 w-full max-w-full rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="mb-4">
              <h3 className="text-base font-semibold text-slate-900">
                {formMode === 'create' ? 'Yeni Partner' : formMode === 'detail' ? 'Partner Detayı' : 'Partner Düzenle'}
              </h3>
              <p className="mt-1 text-sm text-slate-500">Mikro cari bağlantısı manuel veya cari kontrolünden alınır. Bu işlem Mikro'da yeni cari oluşturmaz.</p>
            </div>

            <div className="grid gap-4">
              <section className="rounded-xl border border-slate-200 bg-slate-50 p-3">
                <div className="mb-2 text-sm font-semibold text-slate-800">Roller</div>
                <div className="grid gap-2 sm:grid-cols-2">
                  {(['dealer', 'locksmith', 'manufacturer', 'seller'] as const).map((capability) => (
                    <label key={capability} className={`flex min-w-0 items-center gap-2 rounded-xl border px-3 py-2 text-sm font-semibold ${form.capabilities.includes(capability) ? 'border-blue-200 bg-white text-blue-700' : 'border-slate-200 bg-white text-slate-600'}`}>
                      <input
                        type="checkbox"
                        checked={form.capabilities.includes(capability)}
                        onChange={() => toggleCapability(capability)}
                        disabled={formMode === 'detail'}
                      />
                      {capability === 'dealer' ? 'Bayi kanalı' : capability === 'locksmith' ? 'Çilingir / servis kanalı' : capability === 'manufacturer' ? 'Üretici kanalı' : 'Satıcı kanalı'}
                    </label>
                  ))}
                </div>
              </section>

              <section className="grid gap-3 rounded-xl border border-slate-200 p-3">
                <div className="text-sm font-semibold text-slate-800">Temel bilgiler</div>
                <div className="grid gap-3 md:grid-cols-2">
                  <label className="grid gap-1 text-sm font-semibold text-slate-700">
                    Partner kodu
                    <Input className="w-full min-w-0 max-w-full" value={form.partner_code} onChange={(event) => updateForm('partner_code', event.target.value)} disabled={formMode === 'detail'} />
                  </label>
                  <label className="grid gap-1 text-sm font-semibold text-slate-700">
                    Görünen ad
                    <Input className="w-full min-w-0 max-w-full" value={form.display_name} onChange={(event) => updateForm('display_name', event.target.value)} disabled={formMode === 'detail'} />
                  </label>
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                  <label className="grid gap-1 text-sm font-semibold text-slate-700">
                    Telefon
                    <Input className="w-full min-w-0 max-w-full" value={form.phone} onChange={(event) => updateForm('phone', event.target.value)} disabled={formMode === 'detail'} />
                  </label>
                  <label className="grid gap-1 text-sm font-semibold text-slate-700">
                    E-posta
                    <Input className="w-full min-w-0 max-w-full" value={form.email} onChange={(event) => updateForm('email', event.target.value)} disabled={formMode === 'detail'} />
                  </label>
                </div>
                <div className="grid gap-3 md:grid-cols-2">
                  <label className="grid gap-1 text-sm font-semibold text-slate-700">
                    İl
                    <Input className="w-full min-w-0 max-w-full" value={form.city} onChange={(event) => updateForm('city', event.target.value)} disabled={formMode === 'detail'} />
                  </label>
                  <label className="grid gap-1 text-sm font-semibold text-slate-700">
                    İlçe
                    <Input className="w-full min-w-0 max-w-full" value={form.district} onChange={(event) => updateForm('district', event.target.value)} disabled={formMode === 'detail'} />
                  </label>
                </div>
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Açık adres
                  <textarea
                    className="min-h-[76px] rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-normal outline-none transition focus:border-slate-400"
                    value={form.address}
                    onChange={(event) => updateForm('address', event.target.value)}
                    disabled={formMode === 'detail'}
                  />
                </label>
                <label className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                  <input type="checkbox" checked={form.active} onChange={(event) => updateForm('active', event.target.checked)} disabled={formMode === 'detail'} />
                  Aktif partner
                </label>
              </section>

              {hasMikroForm && (
                <section className="grid gap-3 rounded-xl border border-sky-100 bg-sky-50/60 p-3">
                  <div>
                    <div className="text-sm font-semibold text-sky-900">Mikro cari bağlantısı</div>
                    <p className="mt-1 text-xs text-sky-700">Manuel girilir veya cari kontrolünden alınır; Mikro'da cari oluşturmaz ya da güncellemez.</p>
                  </div>
                  <div className="grid gap-3 md:grid-cols-2">
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                      Mikro cari kodu
                      <Input className="w-full min-w-0 max-w-full" value={form.mikro_cari_kodu} onChange={(event) => updateForm('mikro_cari_kodu', event.target.value)} disabled={formMode === 'detail'} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                      Mikro cari ünvanı
                      <Input className="w-full min-w-0 max-w-full" value={form.mikro_cari_unvan} onChange={(event) => updateForm('mikro_cari_unvan', event.target.value)} disabled={formMode === 'detail'} />
                    </label>
                  </div>
                  <div className="grid gap-3 md:grid-cols-2">
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                      Cari grup kodu
                      <Input className="w-full min-w-0 max-w-full" value={form.cari_grup_kodu} onChange={(event) => updateForm('cari_grup_kodu', event.target.value)} disabled={formMode === 'detail'} />
                    </label>
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                      Sorumluluk kodu
                      <Input className="w-full min-w-0 max-w-full" value={form.responsibility_code} onChange={(event) => updateForm('responsibility_code', event.target.value)} disabled={formMode === 'detail'} />
                    </label>
                  </div>
                </section>
              )}

              {hasLocksmithForm && (
                <section className="rounded-xl border border-emerald-100 bg-emerald-50/70 p-3">
                  <label className="grid gap-1 text-sm font-semibold text-emerald-900">
                    Teknik Servis Ustası
                    <div className="flex gap-2">
                      <Input className="w-full min-w-0 max-w-full" value={technicianSearch} onChange={(event) => setTechnicianSearch(event.target.value)} placeholder="Ad, telefon, cari kodu veya şehir" disabled={formMode === 'detail'} />
                      <Button type="button" variant="outline" onClick={() => void loadTechnicians()} disabled={technicianLoading || formMode === 'detail'}>
                        {technicianLoading ? 'Aranıyor...' : 'Ara'}
                      </Button>
                    </div>
                    <select
                       className="mt-2 h-10 w-full min-w-0 max-w-full rounded-md border border-emerald-200 bg-white px-3 text-sm"
                      value={form.technical_service_technician_id}
                      onChange={(event) => selectTechnician(event.target.value)}
                      disabled={formMode === 'detail'}
                    >
                      <option value="">Usta bağlantısı yok</option>
                      {technicians.map((technician) => (
                        <option key={technician.id} value={technician.id}>
                          {technician.name}{technician.phone ? ` · ${technician.phone}` : ''} · {locationLabel(technician.city, technician.district)}{(technician.mikro_cari_kodu ?? technician.cari_code) ? ` · ${technician.mikro_cari_kodu ?? technician.cari_code}` : ''}
                        </option>
                      ))}
                    </select>
                  </label>
                  {(selectedTechnicianLabel(technicians, form.technical_service_technician_id) || editingPartner?.linked_technician_name) && (
                    <div className="mt-3 rounded-lg bg-white px-3 py-2 text-sm text-emerald-800">
                      Bağlı usta: {selectedTechnicianLabel(technicians, form.technical_service_technician_id) ?? editingPartner?.linked_technician_name}
                    </div>
                  )}
                  {!form.technical_service_technician_id && (
                    <div className="mt-3 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                      Teknik servis ustası bağlı değil. Bu partner portal/yetki kaydı olarak durur; çilingir işleri için mevcut usta kaydına bağlanmalıdır.
                    </div>
                  )}
                </section>
              )}

              {(editingPartner?.child_cari_accounts ?? []).length > 0 && (
                <section className="rounded-xl border border-indigo-100 bg-indigo-50/70 p-3">
                  <div className="text-sm font-semibold text-indigo-900">Alt cari hesaplari</div>
                  <div className="mt-2 grid gap-2">
                    {(editingPartner?.child_cari_accounts ?? []).map((child) => (
                      <div key={child.mikro_cari_kodu} className="rounded-lg bg-white px-3 py-2 text-sm text-indigo-900">
                        <div className="font-semibold">{child.cari_usage_type ?? child.usage_type ?? 'Alt cari'}</div>
                        <div className="text-xs text-indigo-700">{child.mikro_cari_kodu} · {child.mikro_cari_unvan ?? child.display_name ?? '-'}</div>
                      </div>
                    ))}
                  </div>
                  <p className="mt-2 text-xs text-indigo-700">
                    Konsinye/teshir siparislerinde fatura cari kodu bu alt cari hesabindan secilecektir. Bu fazda siparis/fatura logic degistirilmedi.
                  </p>
                </section>
              )}

              <section className="rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm text-slate-600">
                <div className="font-semibold text-slate-800">Kullanıcılar</div>
                <p className="mt-1">Bu partner için {editingPartner?.active_users_count ?? 0} aktif / {editingPartner?.users_count ?? 0} toplam kullanıcı tanımı var.</p>
                <Button
                  type="button"
                  className="mt-3"
                  variant="outline"
                  onClick={() => {
                    window.location.href = '/panel/b2b/users'
                  }}
                >
                  Kullanıcıları yönet
                </Button>
              </section>

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
