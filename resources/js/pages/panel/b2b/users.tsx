import { Head } from '@inertiajs/react'
import { useCallback, useEffect, useMemo, useState } from 'react'
import Heading from '@/components/heading'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type PartnerType = 'dealer' | 'locksmith'
type ScopeKey = 'view' | 'manage' | 'orders' | 'stock' | 'finance' | 'technical_service' | 'users'
type AbilityKey = 'can_view' | 'can_create' | 'can_update' | 'can_approve'

type Partner = {
  id: number
  partner_type: PartnerType
  capabilities?: PartnerType[]
  partner_code: string
  display_name: string
  city: string | null
  district: string | null
  active: boolean
}

type ScopeAbilities = Record<AbilityKey, boolean>

type PartnerUser = {
  user_id: number
  name: string
  email: string
  username: string
  role_code: string | null
  active: boolean
  profile_title: string | null
  profile_phone: string | null
  profile_active: boolean
  last_seen_at: string | null
  scopes: Partial<Record<ScopeKey, ScopeAbilities>>
}

type UserOption = {
  user_id: number
  name: string
  email: string
  username: string
  role_code: string | null
  active: boolean
}

type PartnerFilters = {
  search: string
  partner_type: '' | PartnerType
  active: '' | '1' | '0'
}

type UserForm = {
  user_id: string
  title: string
  phone: string
  active: boolean
  scopes: Record<ScopeKey, ScopeAbilities>
}

const scopeRows: Array<{ key: ScopeKey; label: string; hint: string }> = [
  { key: 'view', label: 'Genel görünüm', hint: 'Partner ana bilgileri' },
  { key: 'manage', label: 'Yönetim', hint: 'Partner yönetim aksiyonları' },
  { key: 'orders', label: 'Siparişler', hint: 'Gelecek faz için scope' },
  { key: 'stock', label: 'Stok', hint: 'Gelecek faz için scope' },
  { key: 'finance', label: 'Finans', hint: 'Cari/risk görünümü' },
  { key: 'technical_service', label: 'Teknik servis', hint: 'Çilingir işleri' },
  { key: 'users', label: 'Kullanıcılar', hint: 'Partner kullanıcı yönetimi' },
]

const abilityColumns: Array<{ key: AbilityKey; label: string }> = [
  { key: 'can_view', label: 'Görüntüle' },
  { key: 'can_create', label: 'Oluştur' },
  { key: 'can_update', label: 'Güncelle' },
  { key: 'can_approve', label: 'Onayla' },
]

const emptyScope = (): ScopeAbilities => ({
  can_view: false,
  can_create: false,
  can_update: false,
  can_approve: false,
})

const emptyScopes = (): Record<ScopeKey, ScopeAbilities> => scopeRows.reduce((carry, row) => ({
  ...carry,
  [row.key]: emptyScope(),
}), {} as Record<ScopeKey, ScopeAbilities>)

const emptyForm = (): UserForm => ({
  user_id: '',
  title: '',
  phone: '',
  active: true,
  scopes: emptyScopes(),
})

const partnerTypeLabel = (type: PartnerType) => (type === 'dealer' ? 'Bayi' : 'Çilingir')

const partnerCapabilities = (partner: Partner): PartnerType[] => {
  const capabilities = partner.capabilities?.filter((capability): capability is PartnerType => capability === 'dealer' || capability === 'locksmith') ?? []

  return capabilities.length > 0 ? capabilities : [partner.partner_type]
}

const capabilityChips = (partner: Partner) => partnerCapabilities(partner).map((capability) => (
  <span
    key={capability}
    className={`rounded-full px-2 py-1 text-[11px] font-semibold ${capability === 'dealer' ? 'bg-sky-50 text-sky-700' : 'bg-emerald-50 text-emerald-700'}`}
  >
    {partnerTypeLabel(capability)}
  </span>
))

const locationLabel = (partner: Partner) => {
  const parts = [partner.city, partner.district].filter(Boolean)

  return parts.length > 0 ? parts.join(' / ') : '-'
}

const scopesToPayload = (scopes: Record<ScopeKey, ScopeAbilities>) => scopeRows.map((row) => ({
  access_scope: row.key,
  ...scopes[row.key],
}))

const userSummary = (user: Pick<UserOption, 'name' | 'email' | 'role_code'>) => `${user.name} · ${user.email}${user.role_code ? ` · ${user.role_code}` : ''}`

export default function B2BPartnerUsersPage() {
  const [partners, setPartners] = useState<Partner[]>([])
  const [partnerUsers, setPartnerUsers] = useState<PartnerUser[]>([])
  const [userOptions, setUserOptions] = useState<UserOption[]>([])
  const [filters, setFilters] = useState<PartnerFilters>({ search: '', partner_type: '', active: '' })
  const [selectedPartnerId, setSelectedPartnerId] = useState<number | null>(null)
  const [editingUserId, setEditingUserId] = useState<number | null>(null)
  const [form, setForm] = useState<UserForm>(emptyForm())
  const [partnerLoading, setPartnerLoading] = useState(false)
  const [usersLoading, setUsersLoading] = useState(false)
  const [saving, setSaving] = useState(false)
  const [userSearch, setUserSearch] = useState('')
  const [message, setMessage] = useState<string | null>(null)
  const [error, setError] = useState<string | null>(null)

  const selectedPartner = useMemo(
    () => partners.find((partner) => partner.id === selectedPartnerId) ?? null,
    [partners, selectedPartnerId],
  )

  const filteredPartnerUsers = useMemo(() => {
    const query = userSearch.trim().toLowerCase()

    if (query === '') {
      return partnerUsers
    }

    return partnerUsers.filter((user) => [
      user.name,
      user.email,
      user.username,
      user.role_code ?? '',
      user.profile_title ?? '',
    ].some((value) => value.toLowerCase().includes(query)))
  }, [partnerUsers, userSearch])

  const loadPartners = useCallback(async () => {
    setPartnerLoading(true)
    setError(null)

    try {
      const params = new URLSearchParams()

      Object.entries(filters).forEach(([key, value]) => {
        if (value !== '') {
          params.set(key, value)
        }
      })

      const response = await apiRequest(`/api/b2b/partners?${params.toString()}`)
      const items = (response.items ?? []) as Partner[]
      setPartners(items)

      if (selectedPartnerId === null && items.length > 0) {
        setSelectedPartnerId(items[0].id)
      }
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Partner listesi alınamadı.')
    } finally {
      setPartnerLoading(false)
    }
  }, [filters, selectedPartnerId])

  const loadPartnerUsers = useCallback(async (partnerId: number | null = selectedPartnerId) => {
    if (!partnerId) {
      setPartnerUsers([])

      return
    }

    setUsersLoading(true)
    setError(null)

    try {
      const response = await apiRequest(`/api/b2b/partners/${partnerId}/users`)
      setPartnerUsers(response.items ?? [])
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Partner kullanıcıları alınamadı.')
    } finally {
      setUsersLoading(false)
    }
  }, [selectedPartnerId])

  const searchPanelUsers = useCallback(async () => {
    setError(null)

    try {
      const params = new URLSearchParams()

      if (userSearch.trim() !== '') {
        params.set('search', userSearch.trim())
      }

      params.set('active', '1')

      const response = await apiRequest(`/api/b2b/users/search?${params.toString()}`)
      setUserOptions(response.items ?? [])
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Panel kullanıcıları aranamadı.')
    }
  }, [userSearch])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void loadPartners()
    }, 0)

    return () => window.clearTimeout(timer)
  }, [loadPartners])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void loadPartnerUsers(selectedPartnerId)
    }, 0)

    return () => window.clearTimeout(timer)
  }, [loadPartnerUsers, selectedPartnerId])

  const resetForm = () => {
    setEditingUserId(null)
    setForm(emptyForm())
    setMessage(null)
    setError(null)
  }

  const editUser = (user: PartnerUser) => {
    setEditingUserId(user.user_id)
    setForm({
      user_id: String(user.user_id),
      title: user.profile_title ?? '',
      phone: user.profile_phone ?? '',
      active: user.profile_active,
      scopes: scopeRows.reduce((carry, row) => ({
        ...carry,
        [row.key]: {
          ...emptyScope(),
          ...(user.scopes[row.key] ?? {}),
        },
      }), {} as Record<ScopeKey, ScopeAbilities>),
    })
    setMessage(null)
    setError(null)
  }

  const setScopeAbility = (scope: ScopeKey, ability: AbilityKey, value: boolean) => {
    setForm((current) => ({
      ...current,
      scopes: {
        ...current.scopes,
        [scope]: {
          ...current.scopes[scope],
          [ability]: value,
        },
      },
    }))
  }

  const submitUserAccess = async () => {
    if (!selectedPartnerId) {
      setError('Önce partner seçin.')

      return
    }

    if (!editingUserId && form.user_id === '') {
      setError('Atanacak panel kullanıcısını seçin.')

      return
    }

    setSaving(true)
    setError(null)
    setMessage(null)

    try {
      const payload = {
        user_id: form.user_id === '' ? undefined : Number(form.user_id),
        title: form.title,
        phone: form.phone,
        active: form.active,
        scopes: scopesToPayload(form.scopes),
      }
      const path = editingUserId
        ? `/api/b2b/partners/${selectedPartnerId}/users/${editingUserId}`
        : `/api/b2b/partners/${selectedPartnerId}/users`

      const response = await apiRequest(path, {
        method: editingUserId ? 'PATCH' : 'POST',
        body: JSON.stringify(payload),
      })

      setPartnerUsers(response.items ?? [])
      setMessage('Partner kullanıcı yetkileri güncellendi.')
      resetForm()
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Partner kullanıcı yetkileri kaydedilemedi.')
    } finally {
      setSaving(false)
    }
  }

  const revokeUser = async (user: PartnerUser) => {
    if (!selectedPartnerId) {
      return
    }

    setSaving(true)
    setError(null)
    setMessage(null)

    try {
      const response = await apiRequest(`/api/b2b/partners/${selectedPartnerId}/users/${user.user_id}`, {
        method: 'DELETE',
      })
      setPartnerUsers(response.items ?? [])
      setMessage('Partner kullanıcı erişimi pasife alındı.')

      if (editingUserId === user.user_id) {
        resetForm()
      }
    } catch (requestError) {
      setError(requestError instanceof Error ? requestError.message : 'Partner kullanıcı erişimi pasife alınamadı.')
    } finally {
      setSaving(false)
    }
  }

  return (
    <>
      <Head title="B2B Partner Kullanıcıları" />
      <div className="mx-auto w-full max-w-[1800px] space-y-6 px-4 py-6 md:px-6 lg:px-10">
        <Heading
          title="B2B Partner Kullanıcıları"
          description="Mevcut panel kullanıcılarını bayi ve çilingir partner kayıtlarına bağlayın, entity-level scope matrisini yönetin."
        />

        {(error || message) && (
          <div className={`rounded-xl border px-4 py-3 text-sm ${error ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'}`}>
            {error ?? message}
          </div>
        )}

        <section className="rounded-2xl border border-amber-100 bg-amber-50/80 p-4 text-sm text-amber-900">
          Yeni panel kullanıcısı Admin &gt; Kullanıcı Yönetimi ekranından oluşturulur. Burada sadece mevcut kullanıcı partner'a atanır.
        </section>

        <section className="rounded-2xl border border-slate-200 bg-white p-4 shadow-sm">
          <div className="grid gap-3 lg:grid-cols-[1fr_180px_160px_auto]">
            <Input value={filters.search} onChange={(event) => setFilters((current) => ({ ...current, search: event.target.value }))} placeholder="Partner ara: kod, ad, cari" />
            <select className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={filters.partner_type} onChange={(event) => setFilters((current) => ({ ...current, partner_type: event.target.value as '' | PartnerType }))}>
              <option value="">Tüm partnerlar</option>
              <option value="dealer">Bayiler</option>
              <option value="locksmith">Çilingirler</option>
            </select>
            <select className="h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={filters.active} onChange={(event) => setFilters((current) => ({ ...current, active: event.target.value as PartnerFilters['active'] }))}>
              <option value="">Aktif/Pasif</option>
              <option value="1">Aktif</option>
              <option value="0">Pasif</option>
            </select>
            <div className="flex gap-2">
              <Button type="button" variant="outline" onClick={() => setFilters({ search: '', partner_type: '', active: '' })}>Temizle</Button>
              <Button type="button" onClick={() => void loadPartners()} disabled={partnerLoading}>{partnerLoading ? 'Yükleniyor...' : 'Filtrele'}</Button>
            </div>
          </div>
        </section>

        <div className="grid gap-6 xl:grid-cols-[360px_minmax(0,1fr)_460px]">
          <section className="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="border-b border-slate-100 px-4 py-3">
              <h2 className="text-base font-semibold text-slate-900">Partner seç</h2>
              <p className="text-sm text-slate-500">{partners.length} kayıt</p>
            </div>
            <div className="max-h-[680px] overflow-y-auto p-3">
              {partners.length === 0 && <div className="rounded-xl bg-slate-50 p-4 text-sm text-slate-500">Partner bulunamadı.</div>}
              {partners.map((partner) => (
                <button
                  key={partner.id}
                  type="button"
                  onClick={() => {
                    setSelectedPartnerId(partner.id)
                    resetForm()
                  }}
                  className={`mb-2 w-full rounded-xl border p-3 text-left transition ${selectedPartnerId === partner.id ? 'border-blue-300 bg-blue-50' : 'border-slate-200 bg-white hover:bg-slate-50'}`}
                >
                  <div className="flex items-start justify-between gap-3">
                    <div>
                      <div className="text-sm font-semibold text-slate-900">{partner.display_name}</div>
                      <div className="text-xs text-slate-500">{partner.partner_code}</div>
                    </div>
                    <div className="flex flex-wrap justify-end gap-1">
                      {capabilityChips(partner)}
                    </div>
                  </div>
                  <div className="mt-2 text-xs text-slate-500">{locationLabel(partner)}</div>
                </button>
              ))}
            </div>
          </section>

          <section className="rounded-2xl border border-slate-200 bg-white shadow-sm">
            <div className="flex flex-col gap-3 border-b border-slate-100 px-4 py-3 md:flex-row md:items-center md:justify-between">
              <div>
                <h2 className="text-base font-semibold text-slate-900">Atanmış kullanıcılar</h2>
                <p className="text-sm text-slate-500">{selectedPartner ? selectedPartner.display_name : 'Partner seçilmedi'}</p>
              </div>
              <Input className="md:max-w-xs" value={userSearch} onChange={(event) => setUserSearch(event.target.value)} placeholder="Kullanıcı ara" />
            </div>

            <div className="overflow-x-auto">
              <table className="min-w-full divide-y divide-slate-100 text-sm">
                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                  <tr>
                    <th className="px-4 py-3">Kullanıcı</th>
                    <th className="px-4 py-3">Profil</th>
                    <th className="px-4 py-3">Scope</th>
                    <th className="px-4 py-3">Durum</th>
                    <th className="px-4 py-3">İşlem</th>
                  </tr>
                </thead>
                <tbody className="divide-y divide-slate-100">
                  {(usersLoading || filteredPartnerUsers.length === 0) && (
                    <tr>
                      <td className="px-4 py-8 text-center text-slate-500" colSpan={5}>
                        {usersLoading ? 'Yükleniyor...' : 'Atanmış kullanıcı yok.'}
                      </td>
                    </tr>
                  )}
                  {filteredPartnerUsers.map((user) => (
                    <tr key={user.user_id} className="align-top hover:bg-slate-50/70">
                      <td className="px-4 py-3">
                        <div className="font-semibold text-slate-900">{user.name}</div>
                        <div className="text-xs text-slate-500">{user.email}</div>
                        <div className="text-xs text-slate-400">{user.role_code ?? '-'}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="font-medium text-slate-800">{user.profile_title ?? '-'}</div>
                        <div className="text-xs text-slate-500">{user.profile_phone ?? '-'}</div>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex max-w-[260px] flex-wrap gap-1">
                          {Object.entries(user.scopes).filter(([, ability]) => ability.can_view || ability.can_update || ability.can_approve || ability.can_create).map(([scope]) => (
                            <span key={scope} className="rounded-full bg-slate-100 px-2 py-1 text-[11px] font-semibold text-slate-600">{scope}</span>
                          ))}
                        </div>
                      </td>
                      <td className="px-4 py-3">
                        <span className={`rounded-full px-2.5 py-1 text-xs font-semibold ${user.profile_active ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-500'}`}>
                          {user.profile_active ? 'Aktif' : 'Pasif'}
                        </span>
                      </td>
                      <td className="px-4 py-3">
                        <div className="flex flex-wrap gap-2">
                          <Button type="button" size="sm" variant="outline" onClick={() => editUser(user)}>Düzenle</Button>
                          <Button type="button" size="sm" variant="outline" onClick={() => void revokeUser(user)} disabled={saving}>Pasife al</Button>
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
              <h3 className="text-base font-semibold text-slate-900">{editingUserId ? 'Yetki düzenle' : 'Kullanıcı ata'}</h3>
              <p className="mt-1 text-sm text-slate-500">Scope satırları partner bazlıdır. Frontend filtresi güvenlik değildir; backend her isteği entity scope ile kontrol eder.</p>
            </div>

            <div className="grid gap-3">
              {!editingUserId && (
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Kullanıcı seç
                  <div className="flex gap-2">
                    <Input value={userSearch} onChange={(event) => setUserSearch(event.target.value)} placeholder="Ad, e-posta veya rol" />
                    <Button type="button" variant="outline" onClick={() => void searchPanelUsers()}>Ara</Button>
                  </div>
                  <select className="mt-2 h-10 rounded-md border border-slate-200 bg-white px-3 text-sm" value={form.user_id} onChange={(event) => setForm((current) => ({ ...current, user_id: event.target.value }))}>
                    <option value="">Panel kullanıcısı seçin</option>
                    {userOptions.map((user) => (
                      <option key={user.user_id} value={user.user_id}>{userSummary(user)}</option>
                    ))}
                  </select>
                </label>
              )}

              {editingUserId && (
                <div className="rounded-xl border border-blue-100 bg-blue-50 px-3 py-2 text-sm text-blue-800">
                  Kullanıcı ID: {editingUserId}
                </div>
              )}

              <div className="grid gap-3 md:grid-cols-2">
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Ünvan
                  <Input value={form.title} onChange={(event) => setForm((current) => ({ ...current, title: event.target.value }))} />
                </label>
                <label className="grid gap-1 text-sm font-semibold text-slate-700">
                  Telefon
                  <Input value={form.phone} onChange={(event) => setForm((current) => ({ ...current, phone: event.target.value }))} />
                </label>
              </div>

              <label className="flex items-center gap-2 rounded-xl border border-slate-200 bg-slate-50 px-3 py-2 text-sm font-semibold text-slate-700">
                <input type="checkbox" checked={form.active} onChange={(event) => setForm((current) => ({ ...current, active: event.target.checked }))} />
                Partner profili aktif
              </label>

              <div className="overflow-hidden rounded-xl border border-slate-200">
                <table className="min-w-full divide-y divide-slate-100 text-sm">
                  <thead className="bg-slate-50">
                    <tr>
                      <th className="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">Scope</th>
                      {abilityColumns.map((ability) => (
                        <th key={ability.key} className="px-2 py-2 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">{ability.label}</th>
                      ))}
                    </tr>
                  </thead>
                  <tbody className="divide-y divide-slate-100">
                    {scopeRows.map((scope) => (
                      <tr key={scope.key}>
                        <td className="px-3 py-2">
                          <div className="font-semibold text-slate-800">{scope.label}</div>
                          <div className="text-xs text-slate-500">{scope.hint}</div>
                        </td>
                        {abilityColumns.map((ability) => (
                          <td key={ability.key} className="px-2 py-2 text-center">
                            <input
                              type="checkbox"
                              checked={form.scopes[scope.key][ability.key]}
                              onChange={(event) => setScopeAbility(scope.key, ability.key, event.target.checked)}
                              aria-label={`${scope.label} ${ability.label}`}
                            />
                          </td>
                        ))}
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>

              <div className="flex flex-wrap justify-end gap-2 pt-2">
                {editingUserId && <Button type="button" variant="outline" onClick={resetForm}>Yeni atama</Button>}
                <Button type="button" onClick={() => void submitUserAccess()} disabled={saving || !selectedPartnerId}>
                  {saving ? 'Kaydediliyor...' : 'Kaydet'}
                </Button>
              </div>
            </div>
          </aside>
        </div>
      </div>
    </>
  )
}
