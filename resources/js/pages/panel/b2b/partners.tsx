import { Head, Link } from '@inertiajs/react'
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
  primary_technician_id?: number | null
  linked_technicians?: PartnerTechnicianLink[]
  linked_technician_name: string | null
  linked_technician_phone: string | null
  child_cari_accounts?: CariControlChildAccount[]
  metadata?: {
    child_cari_accounts?: unknown
    shipping_profile?: {
      child_account_mapping?: unknown
    }
  }
  invoice_usage_note?: string | null
  users_count?: number
  active_users_count?: number
  portal_admin_users?: PortalAdminUser[]
  has_portal_admin?: boolean
}

type PortalAdminUser = {
  user_id: number
  username: string | null
  name: string | null
  role_code: string | null
  active: boolean
  portal_admin: boolean
}

type ProvisionResult = {
  partner_id: number
  partner_name?: string | null
  user_id?: number
  username?: string | null
  role_code?: string | null
  created?: boolean
  linked?: boolean
  skipped?: boolean
  failed?: boolean
  status?: string
  default_password?: string | null
  message?: string
}

type PartnerTechnicianLink = {
  id: number
  partner_id: number
  technical_service_technician_id: number
  relationship_type: string
  is_primary: boolean
  active: boolean
  source?: string | null
  match_reason?: string | null
  service_city?: string | null
  service_district?: string | null
  service_region_note?: string | null
  priority?: number | string | null
  needs_review?: boolean | null
  review_reason?: string | null
  review_reasons?: string[] | null
  technician: {
    id: number
    name: string
    display_name?: string | null
    phone: string | null
    city: string | null
    district: string | null
    address?: string | null
    mikro_cari_kodu?: string | null
    mikro_cari_adi?: string | null
    technician_type?: string | null
    needs_review?: boolean | null
    review_reason?: string | null
    review_reasons?: string[] | null
    geocode_status?: string | null
    location_source?: string | null
    active?: boolean
  } | null
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
  linked_partner_id?: number | null
  linked_partner_name?: string | null
  linked_partner_ids?: number[]
  linked_partner_names?: string[]
  linked_to_current_partner?: boolean
  can_link?: boolean
  cannot_link_reason?: string | null
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
  legal_name?: string | null
  contact_or_service_name?: string | null
  cari_grup_kodu?: string | null
  responsibility_code?: string | null
  phone?: string | null
  phone_source?: string | null
  email?: string | null
  city?: string | null
  district?: string | null
  address?: string | null
  address_source?: string | null
  source_field_missing?: string[]
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
  sync_preview?: CariControlSyncPreview
}

type CariControlSyncPreview = {
  writes_enabled: boolean
  role_model?: 'single_partner' | 'single_partner_multi_role'
  partner_action: string
  technician_action: string
  link_action: string
  geocode_plan?: {
    mode?: string
    status?: string
    source?: string | null
    query?: string | null
    message?: string | null
    review_required?: boolean
  } | null
  partner_phone_matches?: { id: number, name: string | null }[]
  technician_phone_matches?: { id: number, name: string | null }[]
  duplicate_flags?: string[]
  warnings?: string[]
}

type PartnerChildAccountDisplay = {
  code: string
  usageTypeLabel: string
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
  loaded_count?: number
  filtered_total?: number
  source_total?: number | null
  source_total_known?: boolean
  snapshot_total?: number
  role_counts?: Partial<Record<PartnerType, number>>
  filtered_role_counts?: Partial<Record<PartnerType, number>>
  snapshot_counts?: Partial<Record<'new' | 'matched' | 'changed' | 'review_required', number>>
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

const partnerStatusBadgeClass = (active: boolean) => {
  return active
    ? 'bg-emerald-50 text-emerald-700 border border-emerald-200'
    : 'bg-rose-50 text-rose-700 border border-rose-100'
}

const normalizeText = (value: string) => value.normalize('NFD').replace(/\p{M}/gu, '')

const childCariUsageTypeToLabel = (rawUsageType: string | null | undefined): string | null => {
  if (!rawUsageType) {
    return null
  }

  const normalized = normalizeText(rawUsageType.toLowerCase())

  if (normalized === 'consignment') {
    return 'Konsinye'
  }

  if (normalized === 'showroom') {
    return 'Teşhir'
  }

  if (normalized === 'project') {
    return 'Proje'
  }

  return null
}

const childCariUsageLabelFromCode = (code: string | null | undefined): string | null => {
  const suffix = code?.split('.').pop()?.trim() ?? ''
  const normalized = normalizeText(suffix.toUpperCase())

  if (normalized.includes('KONSINYE')) {
    return 'Konsinye'
  }

  if (normalized.includes('TESHIR')) {
    return 'Teşhir'
  }

  if (normalized.includes('PROJE')) {
    return 'Proje'
  }

  return null
}

const normalizeChildCariSourceCode = (account: unknown): string | null => {
  if (account === null || typeof account !== 'object') {
    return null
  }

  const value = account as { mikro_cari_kodu?: unknown, code?: unknown }
  const fromPrimary = typeof value.mikro_cari_kodu === 'string' ? value.mikro_cari_kodu : typeof value.code === 'string' ? value.code : null

  if (!fromPrimary) {
    return null
  }

  return fromPrimary.trim()
}

const normalizeChildCariUsageType = (account: unknown): string | null => {
  if (account === null || typeof account !== 'object') {
    return null
  }

  const value = account as { usage_type?: unknown, cari_usage_type?: unknown }
  const usageType = typeof value.usage_type === 'string'
    ? value.usage_type
    : typeof value.cari_usage_type === 'string'
      ? value.cari_usage_type
      : null

  return usageType?.trim() ?? null
}

const partnerChildCariAccounts = (partner: Partner): PartnerChildAccountDisplay[] => {
  const metadata = partner.metadata as { child_cari_accounts?: unknown } | null
  const shippingProfile = metadata?.shipping_profile as { child_account_mapping?: unknown } | undefined
  const allChildAccounts: unknown[] = [
    ...(partner.child_cari_accounts ?? []),
    ...(Array.isArray(metadata?.child_cari_accounts) ? metadata.child_cari_accounts : []),
    ...(Array.isArray(shippingProfile?.child_account_mapping) ? shippingProfile.child_account_mapping : []),
  ]

  const mapped = allChildAccounts
    .map((account) => {
      const code = normalizeChildCariSourceCode(account)

      if (!code) {
        return null
      }

      const usageType = normalizeChildCariUsageType(account)
      const usageTypeLabel = childCariUsageTypeToLabel(usageType)
        ?? childCariUsageLabelFromCode(code)
        ?? 'Alt cari'

      return {
        code,
        usageTypeLabel,
      }
    })
    .filter((account): account is PartnerChildAccountDisplay => Boolean(account))
    .filter((account, index, array) => array.findIndex((item) => item.code === account.code) === index)

  const order = {
    Konsinye: 0,
    Teşhir: 1,
    Proje: 2,
  } as const

  return mapped.sort((left, right) => {
    const leftOrder = order[left.usageTypeLabel as keyof typeof order] ?? 99
    const rightOrder = order[right.usageTypeLabel as keyof typeof order] ?? 99

    if (leftOrder !== rightOrder) {
      return leftOrder - rightOrder
    }

    return left.code.localeCompare(right.code)
  })
}

const partnerToFormValues = (partner: Partner): PartnerForm => {
  const primaryTechnicianId = partner.primary_technician_id ?? partner.technical_service_technician_id

  return {
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
    technical_service_technician_id: primaryTechnicianId ? String(primaryTechnicianId) : '',
  }
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

const sameText = (first: string | null | undefined, second: string | null | undefined) => {
  if (!first || !second) {
    return false
  }

  return normalizeText(first).trim().toLocaleUpperCase('tr-TR') === normalizeText(second).trim().toLocaleUpperCase('tr-TR')
}

const candidateDisplayName = (candidate: CariControlCandidate) => (
  candidate.display_name
  ?? candidate.legal_name
  ?? candidate.mikro_cari_unvan
  ?? candidate.mikro_cari_kodu
)

const candidateContactName = (candidate: CariControlCandidate) => {
  const contact = candidate.contact_or_service_name
  const title = candidateDisplayName(candidate)

  return contact && !sameText(contact, title) ? contact : null
}

const candidateAddressLabel = (candidate: CariControlCandidate) => {
  if (!candidate.address) {
    return 'Mikro kaynağında adres yok'
  }

  return candidate.address_source && candidate.address_source !== 'kaynak yok'
    ? `${candidate.address} · ${candidate.address_source}`
    : candidate.address
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

const partnerCardAccentClass = (active: boolean, capabilities: PartnerType[]) => {
  if (!active) {
    return 'border-rose-200 bg-rose-50/70 hover:border-rose-300 hover:shadow-rose-100/80'
  }

  const primary = primaryPartnerType(capabilities)
  const hasMultipleRoles = capabilities.length > 1

  if (hasMultipleRoles) {
    return 'border-slate-200 bg-gradient-to-br from-white via-slate-100/55 to-white hover:border-slate-300'
  }

  if (primary === 'dealer') {
    return 'border-sky-200 bg-sky-50/60 hover:border-sky-300 hover:shadow-blue-100/80'
  }

  if (primary === 'locksmith') {
    return 'border-emerald-200 bg-emerald-50/50 hover:border-emerald-300 hover:shadow-emerald-100/80'
  }

  if (primary === 'manufacturer') {
    return 'border-violet-200 bg-violet-50/55 hover:border-violet-300 hover:shadow-violet-100/80'
  }

  return 'border-amber-200 bg-amber-50/45 hover:border-amber-300 hover:shadow-amber-100/80'
}

const partnerActionButtonClass = (variant: 'detail' | 'edit' | 'users' | 'danger' | 'success') => {
  const base = 'inline-flex h-9 w-full items-center justify-center rounded-lg border text-sm font-semibold transition focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2'

  if (variant === 'edit') {
    return `${base} border-sky-300 bg-sky-50 text-sky-700 hover:bg-sky-100 focus-visible:ring-sky-300`
  }

  if (variant === 'users') {
    return `${base} border-indigo-300 bg-indigo-50 text-indigo-700 hover:bg-indigo-100 focus-visible:ring-indigo-300`
  }

  if (variant === 'danger') {
    return `${base} border-rose-300 bg-rose-50 text-rose-700 hover:bg-rose-100 focus-visible:ring-rose-300`
  }

  if (variant === 'success') {
    return `${base} border-emerald-300 bg-emerald-50 text-emerald-700 hover:bg-emerald-100 focus-visible:ring-emerald-300`
  }

  return `${base} border-slate-300 bg-white text-slate-700 hover:bg-slate-50 focus-visible:ring-slate-300`
}

const candidateCapabilities = (candidate: CariControlCandidate): PartnerType[] => {
  const capabilities = (candidate.capabilities ?? candidate.suggested_capabilities ?? [])
    .filter((capability): capability is PartnerType => ['dealer', 'locksmith', 'manufacturer', 'seller'].includes(capability))

  return capabilities.length > 0 ? capabilities : ['dealer']
}

const syncPreviewActionLabel = (action: string) => {
  const labels: Record<string, string> = {
    create_partner_preview: 'Partner oluşturma önizlemesi',
    update_partner_preview: 'Partner güncelleme önizlemesi',
    no_partner_change: 'Partner değişmez',
    create_or_match_technician_preview: 'Teknisyen oluştur/eşleştir önizlemesi',
    match_existing_technician: 'Mevcut teknisyen eşleşir',
    not_applicable: 'Uygulanmaz',
    ensure_partner_technician_link_preview: 'Partner-teknisyen bağı önizlemesi',
    ensure_partner_technician_link: 'Partner-teknisyen bağı kurulacak',
    no_link_change: 'Bağ değişmez',
    create_technician: 'Teknisyen oluşturulacak',
    update_or_use_existing_technician: 'Teknisyen güncellenecek/eşleşecek',
    create_partner: 'Partner oluşturulacak',
    update_partner: 'Partner güncellenecek',
    add_capability: 'Rol eklenecek',
  }

  return labels[action] ?? action
}

const primaryPortalAdmin = (partner: Partner | null | undefined): PortalAdminUser | null => partner?.portal_admin_users?.[0] ?? null

const portalAdminLabel = (partner: Partner | null | undefined): string => {
  const admin = primaryPortalAdmin(partner)

  return admin?.username ?? admin?.name ?? '-'
}

export default function B2BPartnersPage() {
  const [partners, setPartners] = useState<Partner[]>([])
  const [filters, setFilters] = useState<Filters>(emptyFilters)
  const [form, setForm] = useState<PartnerForm>(emptyForm)
  const [formMode, setFormMode] = useState<FormMode>('create')
  const [editingPartner, setEditingPartner] = useState<Partner | null>(null)
  const [selectedPartnerId, setSelectedPartnerId] = useState<number | null>(null)
  const [technicians, setTechnicians] = useState<TechnicianOption[]>([])
  const [partnerTechnicianLinks, setPartnerTechnicianLinks] = useState<PartnerTechnicianLink[]>([])
  const editingPartnerId = editingPartner?.id
  const [technicianSearch, setTechnicianSearch] = useState('')
  const [loading, setLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [technicianLoading, setTechnicianLoading] = useState(false)
  const [technicianLinkLoading, setTechnicianLinkLoading] = useState(false)
  const [cariChecking, setCariChecking] = useState(false)
  const [locksmithSyncing, setLocksmithSyncing] = useState(false)
  const [adminProvisioning, setAdminProvisioning] = useState(false)
  const [cariGeocodeMode, setCariGeocodeMode] = useState<'none' | 'auto'>('none')
  const [provisionResults, setProvisionResults] = useState<ProvisionResult[] | null>(null)
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
  const showTechnicianLinks = form.capabilities.length > 0
  const showLegacyTechnicianSelect = false
  const cariCandidates = useMemo(() => cariControl?.candidates ?? cariControl?.items ?? [], [cariControl])
  const cariControlStatus = cariControl?.status ?? 'idle'
  const cariControlMeta = (cariControl as (CariControlState & { meta?: Record<string, unknown> }) | null)?.meta ?? {}
  const queryContract = cariControl?.query_contract
  const existingSources = cariControl?.existing_sources ?? []
  const sourceUsed = cariControl?.source_used ?? '-'
  const excludedOnlineRetailCount = cariControl?.excluded_online_retail_count ?? 0
  const actionsEnabled = Boolean(cariControl?.actions_enabled)
  const loadedCount = cariControl?.loaded_count ?? cariCandidates.length
  const filteredTotal = cariControl?.filtered_total ?? cariCandidates.length
  const sourceTotal = cariControl?.source_total ?? null
  const sourceTotalKnown = Boolean(cariControl?.source_total_known)
  const snapshotTotal = cariControl?.snapshot_total ?? 0
  const roleCounts = cariControl?.role_counts ?? {}
  const filteredRoleCounts = cariControl?.filtered_role_counts ?? {}
  const cariControlLimitLabel = sourceTotalKnown && sourceTotal !== null
    ? `${loadedCount}/${sourceTotal} kaynak satır`
    : `${loadedCount} yüklendi`
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
      const nextPartners = response.items ?? []
      setPartners(nextPartners)

      if (formMode !== 'create' && selectedPartnerId !== null) {
        const refreshedPartner = nextPartners.find((partner) => partner.id === selectedPartnerId)

        if (!refreshedPartner) {
          setSelectedPartnerId(null)
          setEditingPartner(null)
          setPartnerTechnicianLinks([])
          setForm(emptyForm)
          setFormMode('create')
          setMessage(null)
          setError(null)
        } else if (editingPartnerId === undefined || editingPartnerId !== refreshedPartner.id) {
          setEditingPartner(refreshedPartner)
          setPartnerTechnicianLinks(refreshedPartner.linked_technicians ?? [])
          setForm(partnerToFormValues(refreshedPartner))
          setMessage(null)
          setError(null)
        } else {
          setEditingPartner(refreshedPartner)
          setPartnerTechnicianLinks(refreshedPartner.linked_technicians ?? [])
          setForm(partnerToFormValues(refreshedPartner))
        }
      }
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Partner listesi alınamadı.')
    } finally {
      setLoading(false)
    }
  }, [editingPartnerId, filters, formMode, selectedPartnerId])

  const loadPartnerTechnicians = useCallback(async (partnerId: number) => {
    setTechnicianLinkLoading(true)

    try {
      const response = await apiRequest(`/api/b2b/partners/${partnerId}/technicians`)
      setPartnerTechnicianLinks(response.items ?? [])

      if (response.partner) {
        const refreshedPartner = response.partner as Partner
        setEditingPartner(refreshedPartner)
        setForm(partnerToFormValues(refreshedPartner))
        setPartners((current) => current.map((partner) => (partner.id === refreshedPartner.id ? refreshedPartner : partner)))
      }
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Bagli usta listesi alinamadi.')
    } finally {
      setTechnicianLinkLoading(false)
    }
  }, [])

  const loadTechnicians = useCallback(async (search = technicianSearch, contextPartnerId: number | undefined = editingPartnerId) => {
    setTechnicianLoading(true)

    try {
      const params = new URLSearchParams()

      if (search.trim() !== '') {
        params.set('search', search.trim())
      }

      if (contextPartnerId !== undefined) {
        params.set('partner_id', String(contextPartnerId))
      }

      const response = await apiRequest(`/api/b2b/locksmith-technicians?${params.toString()}`)
      setTechnicians(response.items ?? [])
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Çilingir listesi alınamadı.')
    } finally {
      setTechnicianLoading(false)
    }
  }, [editingPartnerId, technicianSearch])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void loadPartners()
    }, 0)

    return () => window.clearTimeout(timer)
  }, [loadPartners])

  useEffect(() => {
    if (showTechnicianLinks) {
      const timer = window.setTimeout(() => {
        void loadTechnicians()
      }, 0)

      return () => window.clearTimeout(timer)
    }

    return undefined
  }, [showTechnicianLinks, loadTechnicians])

  const startCreate = () => {
    setSelectedPartnerId(null)
    setForm(emptyForm)
    setFormMode('create')
    setEditingPartner(null)
    setPartnerTechnicianLinks([])
    setMessage(null)
    setError(null)
  }

  const startEdit = (partner: Partner, mode: FormMode = 'edit') => {
    const nextForm = partnerToFormValues(partner)
    setSelectedPartnerId(partner.id)
    setEditingPartner(partner)
    setPartnerTechnicianLinks(partner.linked_technicians ?? [])
    setForm(nextForm)
    setFormMode(mode)
    setMessage(null)
    setError(null)

    void loadPartnerTechnicians(partner.id)
    void loadTechnicians('', partner.id)
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

  const runCariControl = useCallback(async (options: { search?: string; resetSelection?: boolean; refresh?: boolean } = {}) => {
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
      params.set('limit', '1000')

      if (options.refresh ?? options.resetSelection) {
        params.set('refresh', '1')
      }

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
        message: 'Cari kontrol sırasında hata oluştu. Lütfen tekrar deneyin.',
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

  const importSelectedCariCandidates = async (dryRun = false) => {
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
          dry_run: dryRun,
          sync_technician: true,
          geocode_mode: dryRun && cariGeocodeMode === 'auto' ? 'dry_run' : cariGeocodeMode,
          update_existing: true,
          override_existing_coordinates: false,
          candidates: candidates.map((candidate) => ({
            ...candidate,
            selected_capabilities: candidateCapabilitySelections[candidate.mikro_cari_kodu] ?? candidateCapabilities(candidate),
          })),
        }),
      })

      if (!dryRun) {
        setSelectedCariCodes([])
        setSelectedCariCandidates({})
      }

      setMessage(dryRun ? `${payload.items?.length ?? candidates.length} cari adayı için dry-run tamamlandı.` : `${payload.items?.length ?? candidates.length} cari adayı işlendi.`)

      const defaultUsers = (payload.items ?? [])
        .map((item: { default_user?: { username?: string; default_password?: string } }) => item.default_user)
        .filter((user: { username?: string; default_password?: string } | undefined): user is { username?: string; default_password?: string } => Boolean(user?.username))

      if (!dryRun && defaultUsers.length > 0) {
        setMessage(`${payload.items?.length ?? candidates.length} cari adayi islendi. Bayi kullanicisi olusturuldu: ${defaultUsers.map((user) => user.username).join(', ')}. Varsayilan sifre: 12345678`)
      }

      if (!dryRun) {
        await loadPartners()
      }
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
      setMessage(`Çilingir eşitleme tamamlandı. Oluşturulan partner: ${payload.created_partners ?? payload.created ?? 0}, güncellenen partner: ${payload.updated_partners ?? payload.updated ?? 0}, bağlanan usta: ${payload.linked_technicians ?? 0}, zaten bağlı: ${payload.already_linked ?? 0}, kontrol gerekli: ${payload.review_required ?? 0}, hata/atlanan: ${payload.skipped_errors ?? payload.skipped ?? 0}.`)
      await loadPartners()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Çilingirler eşitlenemedi.')
    } finally {
      setLocksmithSyncing(false)
    }
  }

  const provisionPartnerAdmin = async (partner: Partner) => {
    setAdminProvisioning(true)
    setError(null)
    setMessage(null)

    try {
      const payload = await apiRequest(`/api/b2b/partners/${partner.id}/provision-admin-user`, {
        method: 'POST',
        body: JSON.stringify({ show_default_password: true }),
      })
      const result = payload as ProvisionResult & { partner?: Partner }
      setProvisionResults([result])

      if (payload.partner) {
        const refreshedPartner = payload.partner as Partner
        setPartners((current) => current.map((item) => (item.id === refreshedPartner.id ? refreshedPartner : item)))

        if (selectedPartnerId === refreshedPartner.id) {
          setEditingPartner(refreshedPartner)
          setForm(partnerToFormValues(refreshedPartner))
        }
      }

      setMessage(result.created ? `Portal admin kullanıcısı oluşturuldu: ${result.username}.` : `Portal admin kullanıcısı hazır: ${result.username ?? portalAdminLabel(partner)}.`)
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Portal admin kullanıcısı oluşturulamadı.')
    } finally {
      setAdminProvisioning(false)
    }
  }

  const bulkProvisionPartnerAdmins = async () => {
    setAdminProvisioning(true)
    setError(null)
    setMessage(null)

    try {
      const payload = await apiRequest('/api/b2b/partners/provision-admin-users', {
        method: 'POST',
        body: JSON.stringify({
          only_without_users: true,
          active_only: true,
          show_default_password: true,
        }),
      })
      const results = (payload.results ?? []) as ProvisionResult[]
      setProvisionResults(results)
      setMessage(`Toplu portal admin işlemi tamamlandı. Oluşturulan: ${payload.created ?? 0}, mevcut: ${payload.skipped_existing ?? 0}, hata: ${payload.failed ?? 0}.`)
      await loadPartners()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Toplu portal admin işlemi tamamlanamadı.')
    } finally {
      setAdminProvisioning(false)
    }
  }

  const applyTechnicianLinkResponse = async (response: { items?: PartnerTechnicianLink[], partner?: Partner }) => {
    if (response.items) {
      setPartnerTechnicianLinks(response.items)
    }

    if (response.partner) {
      const refreshedPartner = response.partner
      setEditingPartner(refreshedPartner)
      setForm(partnerToFormValues(refreshedPartner))
      setPartners((current) => current.map((partner) => (partner.id === refreshedPartner.id ? refreshedPartner : partner)))
    }

    await loadTechnicians()
  }

  const linkTechnician = async (technicianId: number, isPrimary = false) => {
    if (!editingPartner) {
      setError('Usta bağlamak için önce partner kaydını oluşturun.')

      return
    }

    setTechnicianLinkLoading(true)
    setError(null)
    setMessage(null)

    try {
      const response = await apiRequest(`/api/b2b/partners/${editingPartner.id}/technicians`, {
        method: 'POST',
        body: JSON.stringify({
          technical_service_technician_id: technicianId,
          relationship_type: hasLocksmithForm ? 'field_technician' : 'contracted_technician',
          is_primary: isPrimary,
        }),
      })
      await applyTechnicianLinkResponse(response)
      setMessage('Teknik servis ustası bağlandı.')
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Teknik servis ustası bağlanamadı.')
    } finally {
      setTechnicianLinkLoading(false)
    }
  }

  const updateTechnicianLink = async (link: PartnerTechnicianLink, payload: { is_primary?: boolean, active?: boolean }) => {
    if (!editingPartner) {
      return
    }

    setTechnicianLinkLoading(true)
    setError(null)
    setMessage(null)

    try {
      const response = await apiRequest(`/api/b2b/partners/${editingPartner.id}/technicians/${link.id}`, {
        method: 'PATCH',
        body: JSON.stringify(payload),
      })
      await applyTechnicianLinkResponse(response)
      setMessage(payload.is_primary ? 'Birincil usta güncellendi.' : 'Usta bağlantısı güncellendi.')
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Usta bağlantısı güncellenemedi.')
    } finally {
      setTechnicianLinkLoading(false)
    }
  }

  const unlinkTechnician = async (link: PartnerTechnicianLink) => {
    if (!editingPartner) {
      return
    }

    setTechnicianLinkLoading(true)
    setError(null)
    setMessage(null)

    try {
      const response = await apiRequest(`/api/b2b/partners/${editingPartner.id}/technicians/${link.id}`, {
        method: 'DELETE',
      })
      await applyTechnicianLinkResponse(response)
      setMessage('Usta bağlantısı pasifleştirildi.')
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Usta bağlantısı kaldırılamadı.')
    } finally {
      setTechnicianLinkLoading(false)
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
      setSelectedPartnerId(savedPartner.id)
      setPartners((current) => {
        if (editingPartner) {
          return current.map((partner) => (partner.id === savedPartner.id ? savedPartner : partner))
        }

        return [savedPartner, ...current]
      })
      setEditingPartner(savedPartner)
      setFormMode('edit')
      setForm(partnerToFormValues(savedPartner))
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

      if (selectedPartnerId === updatedPartner.id) {
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
            <Button type="button" variant="outline" onClick={() => void bulkProvisionPartnerAdmins()} disabled={adminProvisioning}>
              {adminProvisioning ? 'Hazırlanıyor...' : 'Kullanıcısı olmayanlara admin aç'}
            </Button>
            <Button type="button" onClick={startCreate}>Yeni Partner</Button>
          </div>
        </div>
        {(error || message || cariControl) && (
          <div className={`rounded-xl border px-4 py-3 text-sm ${error ? 'border-rose-200 bg-rose-50 text-rose-700' : cariControl ? 'border-amber-200 bg-amber-50 text-amber-800' : 'border-emerald-200 bg-emerald-50 text-emerald-700'}`}>
            {error ?? cariControl?.message ?? message}
          </div>
        )}

        {provisionResults && provisionResults.length > 0 && (
          <section className="rounded-2xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-950">
            <div className="flex flex-wrap items-center justify-between gap-2">
              <div>
                <h2 className="font-semibold">Portal admin kullanıcı sonucu</h2>
                <p className="mt-1 text-emerald-800">Varsayılan şifre sadece yeni oluşturulan kullanıcılar için bu ekranda bir kez gösterilir.</p>
              </div>
              <Button type="button" variant="outline" onClick={() => setProvisionResults(null)}>Kapat</Button>
            </div>
            <div className="mt-3 grid gap-2 md:grid-cols-2 xl:grid-cols-3">
              {provisionResults.slice(0, 24).map((result, index) => (
                <div key={`${result.partner_id}-${result.user_id ?? index}`} className="rounded-xl border border-emerald-100 bg-white px-3 py-2">
                  <div className="font-semibold text-slate-900">{result.partner_name ?? `Partner #${result.partner_id}`}</div>
                  <div className="mt-1 text-slate-600">Kullanıcı: {result.username ?? '-'}</div>
                  <div className="text-slate-600">Rol: {result.role_code ?? '-'}</div>
                  {result.default_password && <div className="font-semibold text-emerald-700">Varsayılan şifre: {result.default_password}</div>}
                  {result.failed && <div className="font-semibold text-rose-700">Hata: {result.message ?? 'İşlem tamamlanamadı'}</div>}
                </div>
              ))}
            </div>
            {provisionResults.length > 24 && <div className="mt-2 text-xs font-semibold text-emerald-800">+{provisionResults.length - 24} sonuç daha var.</div>}
          </section>
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
                <span className="rounded-full bg-white px-3 py-1 text-xs font-semibold text-amber-800">{cariControlStatus}</span>
                <Button type="button" variant="outline" onClick={closeCariControlModal}>Kapat</Button>
              </div>
            </div>

            {queryContract && (
              <div className="mt-4 grid gap-3 lg:grid-cols-[minmax(0,1fr)_minmax(280px,0.45fr)]">
                <div className="rounded-xl border border-amber-200 bg-white p-3">
                  <div className="text-sm font-semibold text-slate-900">Sorgu sözleşmesi</div>
                  <p className="mt-1 text-slate-600">{queryContract.document_path}</p>
                  <p className="mt-2 text-xs font-medium text-slate-500">Mod: {queryContract.mode}</p>
                  <div className="mt-3 grid gap-2">
                    {(queryContract.discovery_queries ?? []).map((query) => (
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
                    {existingSources.length === 0 ? (
                      <p className="text-slate-600">B2B için onaylı cari kaynağı bulunamadı.</p>
                    ) : (
                      existingSources.map((source) => (
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
                  <p className="mt-1 text-slate-600">Aday gelirse kullanıcı seçer; sadece seçili adaylar partner/teknisyen/bağ olarak işlenir.</p>
                </div>
                <div className="flex flex-wrap items-center gap-2">
                  <select
                    className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm text-slate-700"
                    value={cariGeocodeMode}
                    onChange={(event) => setCariGeocodeMode(event.target.value as 'none' | 'auto')}
                  >
                    <option value="none">Geocode yapma</option>
                    <option value="auto">Adres varsa otomatik çöz</option>
                  </select>
                  <Button type="button" variant="outline" onClick={() => void importSelectedCariCandidates(true)} disabled={saving || !actionsEnabled || selectedCariCodes.length === 0}>
                    Dry-run önizle
                  </Button>
                  <Button type="button" variant="outline" onClick={() => void importSelectedCariCandidates(false)} disabled={saving || !actionsEnabled || selectedCariCodes.length === 0}>
                    Seçili adayları işle
                  </Button>
                </div>
              </div>

              {cariControlStatus === 'error' && (
                <div className="mt-2 rounded-lg border border-rose-100 bg-rose-50 px-3 py-2 text-sm text-rose-800">
                  Cari kontrol sırasında hata oluştu. Lütfen tekrar deneyin.
                </div>
              )}

              {Object.keys(cariControlMeta).length > 0 ? (
                <details className="mt-2 text-xs text-slate-600">
                  <summary className="cursor-pointer">Teknik detay</summary>
                  <pre className="mt-1 max-h-24 overflow-auto rounded-lg border border-slate-200 bg-slate-50 p-2 text-xs text-slate-600">{JSON.stringify(cariControlMeta, null, 2)}</pre>
                </details>
              ) : null}

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

              {actionsEnabled && cariControlStatus === 'ok' && (
                <div className="mt-3 rounded-lg border border-emerald-100 bg-emerald-50 px-3 py-2 text-xs font-semibold text-emerald-800">
                  Faz 1B aktif: DB yazımı sadece işaretlenen adaylar ve açık onayla yapılır.
                </div>
              )}

              <div className="mt-3 grid gap-2 text-xs text-slate-600 sm:grid-cols-2 xl:grid-cols-4">
                <div className="rounded-xl border border-slate-200 bg-white px-3 py-2">
                  <span className="block font-semibold text-slate-800">Kaynak</span>
                  <span>{sourceUsed}</span>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white px-3 py-2">
                  <span className="block font-semibold text-slate-800">Yüklenen / toplam</span>
                  <span>{cariControlLimitLabel}</span>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white px-3 py-2">
                  <span className="block font-semibold text-slate-800">Filtre sonucu</span>
                  <span>{filteredTotal} aday · snapshot {snapshotTotal}</span>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white px-3 py-2">
                  <span className="block font-semibold text-slate-800">Roller</span>
                  <span>Bayi {filteredRoleCounts.dealer ?? roleCounts.dealer ?? 0} · Çilingir {filteredRoleCounts.locksmith ?? roleCounts.locksmith ?? 0}</span>
                </div>
                <div className="rounded-xl border border-slate-200 bg-white px-3 py-2 sm:col-span-2 xl:col-span-4">
                  <span>Online perakende hariç: {excludedOnlineRetailCount}</span>
                  {snapshotTotal > 100 && loadedCount > 100 ? <span className="ml-3 font-semibold text-emerald-700">Snapshot 100 ile sınırlı değil.</span> : null}
                </div>
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
                        disabled={!actionsEnabled}
                      />
                      <div className="min-w-0 flex-1">
                        <span className="block font-semibold text-slate-900">{candidateDisplayName(candidate)}</span>
                        {candidateContactName(candidate) && (
                          <span className="mt-0.5 block text-xs text-slate-500">Kişi/servis adı: {candidateContactName(candidate)}</span>
                        )}
                        <span className="mt-1 block text-xs text-slate-500">
                          {candidate.mikro_cari_kodu} · {candidate.city ?? '-'} / {candidate.district ?? '-'} · {candidate.status_label ?? candidate.status ?? 'Kontrol gerekli'}
                        </span>
                        <span className="mt-1 block text-xs text-slate-500">
                          Tel: {candidate.phone ?? 'Mikro kaynağında telefon yok'} · E-posta: {candidate.email ?? '-'} · Adres: {candidateAddressLabel(candidate)}
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
                        {candidate.sync_preview && (
                          <div className="mt-2 rounded-lg border border-emerald-100 bg-white px-3 py-2 text-xs text-slate-600">
                            <div className="font-semibold text-emerald-800">Eşitleme önizleme</div>
                            <div className="mt-1 grid gap-1 sm:grid-cols-3">
                              <span>{syncPreviewActionLabel(candidate.sync_preview.partner_action)}</span>
                              <span>{syncPreviewActionLabel(candidate.sync_preview.technician_action)}</span>
                              <span>{syncPreviewActionLabel(candidate.sync_preview.link_action)}</span>
                            </div>
                            {candidate.sync_preview.geocode_plan ? (
                              <div className="mt-1 text-sky-700">
                                Geocode: {candidate.sync_preview.geocode_plan.message ?? candidate.sync_preview.geocode_plan.status ?? 'Plan hazır'}
                              </div>
                            ) : null}
                            {(candidate.sync_preview.duplicate_flags ?? []).length > 0 && (
                              <div className="mt-1 text-amber-700">
                                Eşleşme/duplikasyon: {(candidate.sync_preview.duplicate_flags ?? []).join(', ')}
                              </div>
                            )}
                            {(candidate.sync_preview.warnings ?? []).length > 0 && (
                              <div className="mt-1 text-rose-700">
                                {(candidate.sync_preview.warnings ?? []).join(' ')}
                              </div>
                            )}
                          </div>
                        )}
                        <div className="mt-2 grid gap-1 sm:grid-cols-4">
                          {(['dealer', 'locksmith', 'manufacturer', 'seller'] as const).map((capability) => (
                            <span key={capability} className="inline-flex items-center gap-1 rounded-full bg-white px-2 py-1 text-xs font-semibold text-slate-700">
                              <input
                                type="checkbox"
                                checked={(candidateCapabilitySelections[candidate.mikro_cari_kodu] ?? candidateCapabilities(candidate)).includes(capability)}
                                onChange={() => toggleCandidateCapability(candidate.mikro_cari_kodu, capability)}
                                disabled={!actionsEnabled}
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
                  const childCariAccounts = partnerChildCariAccounts(partner)
                  const visibleChildCariAccounts = childCariAccounts.slice(0, 3)
                  const hiddenChildCariCount = childCariAccounts.length - visibleChildCariAccounts.length

                  return (
                    <article
                      key={partner.id}
                      className={`min-w-0 rounded-2xl p-4 transition shadow-sm ${partnerCardAccentClass(partner.active, capabilities)} hover:shadow-md`}
                    >
                      <div className="flex min-w-0 flex-col gap-4">
                        <div className="min-w-0 space-y-2">
                          <div className="flex flex-wrap items-center gap-2">
                            <h3 className="text-base font-semibold text-slate-950">{partner.display_name}</h3>
                            {capabilityChips(capabilities)}
                            <span className={`rounded-full border px-2.5 py-1 text-xs font-semibold ${partnerStatusBadgeClass(partner.active)}`}>
                              {partner.active ? 'Aktif' : 'Pasif'}
                            </span>
                          </div>
                          <div className="grid min-w-0 gap-x-5 gap-y-2 text-sm text-slate-600 sm:grid-cols-2">
                            <span><strong className="text-slate-800">Kod:</strong> {partner.partner_code}</span>
                            <span><strong className="text-slate-800">Cari:</strong> {partner.mikro_cari_kodu ?? '-'}</span>
                            {childCariAccounts.length > 0 && (
                              <div className="min-w-0 sm:col-span-2">
                                <div className="text-xs font-semibold uppercase tracking-wide text-slate-500">Alt cariler</div>
                                <ul className="mt-1 grid gap-1 text-xs">
                                  {visibleChildCariAccounts.map((child) => (
                                    <li key={child.code} className="rounded-lg border border-slate-200 bg-white px-2 py-1 text-slate-600">
                                      <span className="font-semibold text-slate-800">{child.usageTypeLabel}:</span>
                                      <span className="ml-1 inline-block max-w-full truncate align-middle">{child.code}</span>
                                    </li>
                                  ))}
                                  {hiddenChildCariCount > 0 && (
                                    <li className="text-slate-500">+{hiddenChildCariCount} alt cari</li>
                                  )}
                                </ul>
                              </div>
                            )}
                            <span><strong className="text-slate-800">Telefon:</strong> {partner.phone ?? '-'}</span>
                            <span><strong className="text-slate-800">E-posta:</strong> {partner.email ?? '-'}</span>
                            <span><strong className="text-slate-800">Konum:</strong> {locationLabel(partner.city, partner.district)}</span>
                            <span><strong className="text-slate-800">Kullanıcı:</strong> {partner.active_users_count ?? 0}/{partner.users_count ?? 0}</span>
                            <span><strong className="text-slate-800">Portal admin:</strong> {partner.has_portal_admin ? portalAdminLabel(partner) : 'Yok'}</span>
                          </div>
                          <div className="grid gap-2 text-sm text-slate-600 md:grid-cols-3">
                            <div className="rounded-xl bg-white px-3 py-2">
                              <span className="block text-xs font-semibold uppercase tracking-wide text-slate-400">Mikro cari ünvanı</span>
                              <span className="line-clamp-1">{partner.mikro_cari_unvan ?? '-'}</span>
                            </div>
                            <div className="rounded-xl bg-white px-3 py-2">
                              <span className="block text-xs font-semibold uppercase tracking-wide text-slate-400">Bağlı usta</span>
                              <span className="line-clamp-1">
                                {capabilities.includes('locksmith')
                                  ? (partner.linked_technicians ?? []).length > 0
                                    ? (partner.linked_technicians ?? []).map((link) => link.technician?.name).filter(Boolean).join(', ')
                                    : partner.linked_technician_name ?? 'Teknik servis ustası bağlı değil'
                                  : '-'}
                              </span>
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
                        <div className="grid w-full grid-cols-2 gap-2 sm:grid-cols-3 xl:grid-cols-6">
                          <Button type="button" className={partnerActionButtonClass('detail')} variant="outline" onClick={() => startEdit(partner, 'detail')}>Detay</Button>
                          <Button type="button" className={partnerActionButtonClass('edit')} variant="outline" onClick={() => startEdit(partner)}>Düzenle</Button>
                          <Button
                            type="button"
                            variant="outline"
                            className={partnerActionButtonClass('users')}
                            onClick={() => {
                              window.location.href = '/panel/b2b/users'
                            }}
                          >
                            Kullanıcılar
                          </Button>
                          {!partner.has_portal_admin && (
                            <Button
                              type="button"
                              className={partnerActionButtonClass('users')}
                              variant="outline"
                              onClick={() => void provisionPartnerAdmin(partner)}
                              disabled={adminProvisioning}
                            >
                              Admin aç
                            </Button>
                          )}
                          <Link
                            href={`/panel/b2b/partners/${partner.id}/portal-preview`}
                            className={partnerActionButtonClass('users')}
                          >
                            Portal Önizle
                          </Link>
                          <Button
                            type="button"
                            className={partnerActionButtonClass(partner.active ? 'danger' : 'success')}
                            variant="outline"
                            onClick={() => void toggleActive(partner)}
                            disabled={saving}
                          >
                            {partner.active ? 'Pasif yap' : 'Aktif yap'}
                          </Button>
                        </div>
                      </div>
                    </article>
                  )
                })}
              </div>
            )}
          </section>

          <aside className="lg:sticky lg:top-24 lg:self-start min-w-0 w-full max-w-full max-h-[calc(100vh-7rem)] overflow-y-auto rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
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

              {showTechnicianLinks && (
                <section className="rounded-xl border border-emerald-100 bg-emerald-50/70 p-3">
                  <div className="flex flex-wrap items-center justify-between gap-2">
                    <div>
                      <div className="text-sm font-semibold text-emerald-900">Bağlı Teknik Servis Ustaları</div>
                      <p className="mt-1 text-xs text-emerald-700">Bir partner birden fazla ustaya bağlanabilir; cari kodu sadece öneri olarak kullanılır.</p>
                    </div>
                    {editingPartner && (
                      <Button type="button" variant="outline" onClick={() => void loadPartnerTechnicians(editingPartner.id)} disabled={technicianLinkLoading}>
                        {technicianLinkLoading ? 'Yükleniyor...' : 'Yenile'}
                      </Button>
                    )}
                  </div>

                  {partnerTechnicianLinks.length > 0 ? (
                    <div className="mt-3 grid gap-2">
                      {partnerTechnicianLinks.map((link) => (
                        <div key={link.id} className="rounded-lg border border-emerald-100 bg-white px-3 py-2 text-sm text-slate-700">
                          <div className="flex flex-wrap items-center gap-2">
                            <span className="font-semibold text-slate-900">{link.technician?.name ?? `Usta #${link.technical_service_technician_id}`}</span>
                            {link.is_primary && <span className="rounded-full bg-blue-50 px-2 py-0.5 text-xs font-semibold text-blue-700">Birincil</span>}
                            <span className={`rounded-full px-2 py-0.5 text-xs font-semibold ${link.active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                              {link.active ? 'Aktif' : 'Pasif'}
                            </span>
                          </div>
                          <div className="mt-1 text-xs text-slate-500">
                            {[link.technician?.phone, locationLabel(link.technician?.city ?? null, link.technician?.district ?? null), link.technician?.mikro_cari_kodu].filter(Boolean).join(' · ')}
                          </div>
                          <div className="mt-2 flex flex-wrap gap-1">
                            {(link.service_city || link.service_district) && (
                              <span className="rounded-full bg-sky-50 px-2 py-0.5 text-xs font-semibold text-sky-700">
                                Servis kapsamı: {locationLabel(link.service_city ?? null, link.service_district ?? null)}
                              </span>
                            )}
                            {link.priority ? <span className="rounded-full bg-indigo-50 px-2 py-0.5 text-xs font-semibold text-indigo-700">Öncelik {link.priority}</span> : null}
                            {link.needs_review || link.technician?.needs_review ? (
                              <span className="rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">Kontrol gerekli</span>
                            ) : null}
                            {link.technician?.geocode_status ? (
                              <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">Geocode: {link.technician.geocode_status}</span>
                            ) : null}
                          </div>
                          {(link.review_reason || link.technician?.review_reason) ? (
                            <div className="mt-1 text-xs text-amber-700">{link.review_reason ?? link.technician?.review_reason}</div>
                          ) : null}
                          {formMode !== 'detail' && (
                            <div className="mt-2 flex flex-wrap gap-2">
                              {!link.is_primary && link.active && (
                                <Button type="button" size="sm" variant="outline" onClick={() => void updateTechnicianLink(link, { is_primary: true })} disabled={technicianLinkLoading}>
                                  Birincil yap
                                </Button>
                              )}
                              {link.active && (
                                <Button type="button" size="sm" variant="outline" onClick={() => void unlinkTechnician(link)} disabled={technicianLinkLoading}>
                                  Bağlantıyı kaldır
                                </Button>
                              )}
                            </div>
                          )}
                        </div>
                      ))}
                    </div>
                  ) : (
                    <div className="mt-3 rounded-lg border border-amber-100 bg-amber-50 px-3 py-2 text-sm text-amber-800">
                      Teknik servis ustası bağlı değil. Bu partner portal/yetki kaydı olarak durur; çilingir işleri için mevcut usta kaydına bağlanmalıdır.
                    </div>
                  )}

                  {!hasLocksmithForm && (
                    <div className="mt-3 rounded-lg border border-sky-100 bg-sky-50 px-3 py-2 text-sm text-sky-800">
                      Bu partner bayi kanalı olsa da anlaşmalı çilingir bağlanabilir. Bu işlem çilingir portal rolünü otomatik eklemez.
                    </div>
                  )}

                  {formMode !== 'detail' && (
                    <div className="mt-3 rounded-lg border border-emerald-100 bg-white p-3">
                      <div className="flex gap-2">
                        <Input className="w-full min-w-0 max-w-full" value={technicianSearch} onChange={(event) => setTechnicianSearch(event.target.value)} placeholder="Ad, telefon, cari kodu veya şehir" />
                        <Button type="button" variant="outline" onClick={() => void loadTechnicians()} disabled={technicianLoading || !editingPartner}>
                          {technicianLoading ? 'Aranıyor...' : 'Ara'}
                        </Button>
                      </div>
                      {!editingPartner && (
                        <div className="mt-2 text-xs font-semibold text-amber-700">Usta bağlamak için önce partner kaydını oluşturun.</div>
                      )}
                      {!technicianLoading && editingPartner && technicians.length === 0 && (
                        <div className="mt-3 rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-sm text-slate-600">
                          Eşleşen usta bulunamadı.
                        </div>
                      )}
                      {technicians.length > 0 && (
                        <div className="mt-3 grid gap-2">
                          {technicians.map((technician) => (
                            <div key={technician.id} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm">
                              <div className="flex flex-wrap items-center gap-2">
                                <span className="font-semibold text-slate-900">{technician.name}</span>
                                {technician.linked_to_current_partner && <span className="rounded-full bg-emerald-100 px-2 py-0.5 text-xs font-semibold text-emerald-700">Zaten bağlı</span>}
                                {technician.linked_partner_id && !technician.linked_to_current_partner && <span className="rounded-full bg-slate-100 px-2 py-0.5 text-xs font-semibold text-slate-700">Başka partnerlarda da bağlı</span>}
                                {technician.requires_type_review && <span className="rounded-full bg-amber-50 px-2 py-0.5 text-xs font-semibold text-amber-700">Tip kontrol gerekli</span>}
                              </div>
                              <div className="mt-1 text-xs text-slate-500">
                                {[technician.phone, locationLabel(technician.city, technician.district), technician.mikro_cari_kodu ?? technician.cari_code].filter(Boolean).join(' · ')}
                              </div>
                              {(technician.linked_partner_names?.length ?? 0) > 0 && !technician.linked_to_current_partner && (
                                <div className="mt-1 text-xs text-slate-500">Bağlı partnerler: {(technician.linked_partner_names ?? []).join(', ')}</div>
                              )}
                              <div className="mt-2 flex flex-wrap gap-2">
                                {!technician.linked_to_current_partner && (
                                  <Button type="button" size="sm" variant="outline" onClick={() => void linkTechnician(technician.id)} disabled={technicianLinkLoading || !editingPartner || technician.can_link === false}>
                                    Bağla
                                  </Button>
                                )}
                                {technician.can_link !== false && !technician.linked_to_current_partner && (
                                  <Button type="button" size="sm" variant="outline" onClick={() => void linkTechnician(technician.id, true)} disabled={technicianLinkLoading || !editingPartner}>
                                    Bağla ve birincil yap
                                  </Button>
                                )}
                              </div>
                            </div>
                          ))}
                        </div>
                      )}
                    </div>
                  )}
                </section>
              )}

              {showLegacyTechnicianSelect && hasLocksmithForm && (
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
                <div className="mt-2 rounded-lg bg-white px-3 py-2 text-xs font-semibold text-slate-700">
                  Portal admin: {editingPartner?.has_portal_admin ? portalAdminLabel(editingPartner) : 'Yok'}
                </div>
                <div className="mt-3 flex flex-wrap gap-2">
                  {editingPartner && !editingPartner.has_portal_admin && (
                    <Button
                      type="button"
                      variant="outline"
                      onClick={() => void provisionPartnerAdmin(editingPartner)}
                      disabled={adminProvisioning}
                    >
                      Admin kullanıcı oluştur
                    </Button>
                  )}
                  {editingPartner?.has_portal_admin && (
                    <Link
                      href={`/panel/b2b/partners/${editingPartner.id}/portal-preview`}
                      className="rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700"
                    >
                      Portal Önizle
                    </Link>
                  )}
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => {
                      window.location.href = '/panel/b2b/users'
                    }}
                  >
                    Kullanıcıları yönet
                  </Button>
                </div>
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
