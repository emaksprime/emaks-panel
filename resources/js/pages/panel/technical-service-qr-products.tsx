import { Head } from '@inertiajs/react'
import { Copy, ExternalLink, FileText, Printer, QrCode, RefreshCw, Search } from 'lucide-react'
import { useCallback, useEffect, useMemo, useRef, useState } from 'react'
import Heading from '@/components/heading'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'

type SerialContext = {
  serial_number: string | null
  product_name: string | null
  product_model: string | null
  brand: string | null
  activation_code: string | null
  sale_mount_status: string | null
  suggested_link_type: string | null
  current_serial_state: string | null
  has_current_sale: boolean
  latest_event_type: string | null
  latest_valid_sale_exists: boolean
  stock_code: string | null
}

type QrProductLink = {
  id: number
  serial_number: string
  product_name: string
  product_model: string | null
  brand: string | null
  link_type: string
  link_type_label: string
  status: string
  status_label: string
  token: string
  path: string
  public_url: string
  qr_svg_url: string
  printed_at: string | null
  last_scanned_at: string | null
  scan_count: number
  sessions_count: number
  created_at: string | null
  serial_context: SerialContext | null
}

type LinkResponse = {
  link: QrProductLink
  context: SerialContext
  created: boolean
  duplicate: boolean
}

type BulkResult = {
  row: number
  status: 'created' | 'skipped_duplicate' | 'failed'
  message: string
  serial_number?: string | null
  link?: QrProductLink
  context?: SerialContext
}

type BulkResponse = {
  summary: {
    total: number
    created: number
    updated?: number
    skipped: number
    failed: number
  }
  results: BulkResult[]
  errors?: BulkResult[]
  meta?: {
    result_limit: number
    results_truncated: boolean
    errors_truncated: boolean
  }
}

type QrProductListMeta = {
  total: number
  page: number
  per_page: number
  last_page: number
  from?: number | null
  to?: number | null
}

const emptyCsv = 'seri_no,product_name,model,brand\nSN001,Test Akıllı Kilit,F3-LOCK,Emaks Prime'

const formatDateTime = (value: string | null | undefined) => {
  if (!value) {
    return '-'
  }

  try {
    return new Intl.DateTimeFormat('tr-TR', {
      dateStyle: 'short',
      timeStyle: 'short',
    }).format(new Date(value))
  } catch {
    return value
  }
}

const mountStatusLabels: Record<string, string> = {
  unknown: 'Bilinmiyor',
  not_found: 'Seri bulunamadı',
  montaj_dahil: 'Montaj dahil',
  montaj_sonradan_dahil: 'Montaj sonradan dahil',
  montaj_haric: 'Montaj hariç',
  check_failed: 'Kontrol tamamlanamadı',
}

const serialStateLabels: Record<string, string> = {
  sold_current: 'Güncel satış var',
  in_stock_or_center: 'Merkez depo / stokta',
  returned: 'İade / geri dönüş',
  unknown: 'Bilinmiyor',
}

const statusClasses: Record<string, string> = {
  active: 'border-emerald-200 bg-emerald-50 text-emerald-700',
  revoked: 'border-rose-200 bg-rose-50 text-rose-700',
  expired: 'border-slate-200 bg-slate-100 text-slate-700',
}

function InfoTile({ label, value }: { label: string; value: string | number | null | undefined }) {
  return (
    <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3">
      <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
      <p className="mt-2 break-words text-sm font-semibold text-slate-950">{value || '-'}</p>
    </div>
  )
}

function StatusMessage({ message }: { message: { type: 'idle' | 'loading' | 'success' | 'error'; text: string } }) {
  if (!message.text) {
    return null
  }

  const className = {
    idle: 'border-slate-200 bg-slate-50 text-slate-700',
    loading: 'border-blue-200 bg-blue-50 text-blue-700',
    success: 'border-emerald-200 bg-emerald-50 text-emerald-700',
    error: 'border-rose-200 bg-rose-50 text-rose-700',
  }[message.type]

  return <div className={`rounded-2xl border p-3 text-sm font-semibold ${className}`}>{message.text}</div>
}

export default function TechnicalServiceQrProducts() {
  const [serialNumber, setSerialNumber] = useState('')
  const [productName, setProductName] = useState('')
  const [productModel, setProductModel] = useState('')
  const [brand, setBrand] = useState('')
  const [context, setContext] = useState<SerialContext | null>(null)
  const [links, setLinks] = useState<QrProductLink[]>([])
  const [selectedLink, setSelectedLink] = useState<QrProductLink | null>(null)
  const [selectedIds, setSelectedIds] = useState<number[]>([])
  const [search, setSearch] = useState('')
  const [productFilter, setProductFilter] = useState('')
  const [modelFilter, setModelFilter] = useState('')
  const [brandFilter, setBrandFilter] = useState('')
  const [statusFilter, setStatusFilter] = useState('')
  const [printColumns, setPrintColumns] = useState<2 | 3>(2)
  const [page, setPage] = useState(1)
  const [perPage, setPerPage] = useState(25)
  const [listMeta, setListMeta] = useState<QrProductListMeta>({
    total: 0,
    page: 1,
    per_page: 25,
    last_page: 1,
    from: null,
    to: null,
  })
  const [csvText, setCsvText] = useState(emptyCsv)
  const [csvFile, setCsvFile] = useState<File | null>(null)
  const [bulkResults, setBulkResults] = useState<BulkResponse | null>(null)
  const [message, setMessage] = useState<{ type: 'idle' | 'loading' | 'success' | 'error'; text: string }>({ type: 'idle', text: '' })
  const [loading, setLoading] = useState(false)
  const fileInputRef = useRef<HTMLInputElement | null>(null)

  const selectedPrintLinks = useMemo(() => {
    const checkedLinks = links.filter((link) => selectedIds.includes(link.id))

    if (checkedLinks.length > 0) {
      return checkedLinks
    }

    if (selectedLink) {
      return [selectedLink]
    }

    return []
  }, [links, selectedIds, selectedLink])

  const loadLinks = useCallback(async (nextPage = page) => {
    setLoading(true)

    try {
      const params = new URLSearchParams()
      params.set('page', String(nextPage))
      params.set('per_page', String(perPage))

      if (search.trim()) {
        params.set('search', search.trim())
      }

      if (productFilter.trim()) {
        params.set('product_name', productFilter.trim())
      }

      if (modelFilter.trim()) {
        params.set('product_model', modelFilter.trim())
      }

      if (brandFilter.trim()) {
        params.set('brand', brandFilter.trim())
      }

      if (statusFilter.trim()) {
        params.set('status', statusFilter.trim())
      }

      const response = (await apiRequest(`/api/technical-service/qr-products?${params.toString()}`)) as { data?: QrProductLink[], links?: QrProductLink[], meta?: QrProductListMeta }
      const nextLinks = response.data ?? response.links ?? []

      setLinks(nextLinks)
      setListMeta(response.meta ?? {
        total: nextLinks.length,
        page: nextPage,
        per_page: perPage,
        last_page: 1,
        from: nextLinks.length > 0 ? 1 : null,
        to: nextLinks.length,
      })
      setSelectedIds((current) => current.filter((id) => nextLinks.some((link) => link.id === id)))
      setSelectedLink((current) => {
        if (current && nextLinks.some((link) => link.id === current.id)) {
          return current
        }

        return nextLinks[0] ?? null
      })
    } catch (error) {
      setMessage({ type: 'error', text: error instanceof Error ? error.message : 'QR listesi alınamadı.' })
    } finally {
      setLoading(false)
    }
  }, [brandFilter, modelFilter, page, perPage, productFilter, search, statusFilter])

  useEffect(() => {
    const timer = window.setTimeout(() => {
      void loadLinks(page)
    }, 350)

    return () => window.clearTimeout(timer)
  }, [loadLinks, page])

  const resolveSerial = async () => {
    if (!serialNumber.trim()) {
      setMessage({ type: 'error', text: 'Seri no girin.' })

      return
    }

    setMessage({ type: 'loading', text: 'Seri Mikro bağlamı sorgulanıyor...' })

    try {
      const params = new URLSearchParams({ serial_number: serialNumber.trim() })
      const response = (await apiRequest(`/api/technical-service/qr-products/serial-context?${params.toString()}`)) as { context: SerialContext }
      setContext(response.context)
      setProductName(response.context.product_name ?? '')
      setProductModel(response.context.product_model ?? '')
      setBrand(response.context.brand ?? '')
      setMessage({ type: 'success', text: 'Seri bağlamı çözüldü.' })
    } catch (error) {
      setContext(null)
      setMessage({
        type: 'error',
        text: error instanceof Error ? error.message : 'Seri bağlamı çözülemedi. Manuel ürün bilgisiyle QR oluşturabilirsiniz.',
      })
    }
  }

  const createSingle = async () => {
    if (!serialNumber.trim()) {
      setMessage({ type: 'error', text: 'Seri no girin.' })

      return
    }

    setMessage({ type: 'loading', text: 'QR kaydı oluşturuluyor...' })

    try {
      const response = (await apiRequest('/api/technical-service/qr-products', {
        method: 'POST',
        body: JSON.stringify({
          serial_number: serialNumber.trim(),
          product_name: productName.trim() || undefined,
          product_model: productModel.trim() || undefined,
          brand: brand.trim() || undefined,
        }),
      })) as LinkResponse

      setContext(response.context)
      setSelectedLink(response.link)
      setMessage({
        type: 'success',
        text: response.duplicate ? 'Bu seri için aktif QR zaten vardı; mevcut kayıt açıldı.' : 'QR oluşturuldu.',
      })
      setPage(1)
      await loadLinks(1)
    } catch (error) {
      setMessage({ type: 'error', text: error instanceof Error ? error.message : 'QR oluşturulamadı.' })
    }
  }

  const submitBulk = async () => {
    setMessage({ type: 'loading', text: 'Toplu seri yükleniyor...' })
    const token = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content')
    const formData = new FormData()
    formData.append('csv_text', csvText)

    if (csvFile) {
      formData.append('file', csvFile)
    }

    try {
      const response = await fetch('/api/technical-service/qr-products/bulk', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...(token ? { 'X-CSRF-TOKEN': token } : {}),
        },
        body: formData,
      })

      const payload = (await response.json()) as BulkResponse | { message?: string }

      if (!response.ok) {
        throw new Error('message' in payload && payload.message ? payload.message : 'Toplu yükleme başarısız.')
      }

      setBulkResults(payload as BulkResponse)
      setMessage({ type: 'success', text: 'Toplu yükleme tamamlandı.' })
      setPage(1)
      await loadLinks(1)
    } catch (error) {
      setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Toplu yükleme yapılamadı.' })
    }
  }

  const copyText = async (value: string, label: string) => {
    await navigator.clipboard?.writeText(value)
    setMessage({ type: 'success', text: `${label} kopyalandı.` })
  }

  const markPrintedAndPrint = async () => {
    if (!selectedPrintLinks.length) {
      setMessage({ type: 'error', text: 'Yazdırılacak QR seçin.' })

      return
    }

    if (selectedPrintLinks.length > 100) {
      setMessage({ type: 'error', text: 'Çok fazla QR seçildi. Yazdırma için arama/filtreyle grubu daraltın.' })

      return
    }

    try {
      const printedLinks = await Promise.all(selectedPrintLinks.map(async (link) => {
        const response = (await apiRequest(`/api/technical-service/qr-products/${link.id}/printed`, {
          method: 'POST',
          body: JSON.stringify({}),
        })) as { link: QrProductLink }

        return response.link
      }))

      if (selectedLink) {
        setSelectedLink(printedLinks.find((link) => link.id === selectedLink.id) ?? selectedLink)
      }
    } catch (error) {
      setMessage({ type: 'error', text: error instanceof Error ? error.message : 'Yazdırma işaretlenemedi.' })

      return
    }

    window.setTimeout(() => window.print(), 50)
  }

  return (
    <>
      <Head title="Ürün QR Yönetimi" />
      <style>{`
        @media print {
          body * {
            visibility: hidden;
          }

          .qr-print-root,
          .qr-print-root * {
            visibility: visible;
          }

          .qr-print-root {
            display: block !important;
            position: absolute;
            inset: 0;
            background: white;
          }
        }
      `}</style>

      <div className="w-full max-w-none space-y-6 px-4 py-6 md:px-6 xl:px-8 2xl:px-10">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="Ürün QR Yönetimi"
            description="Ürün seri numarasına bağlı public montaj QR linkleri oluşturun, toplu yükleyin ve yazdırın."
          />
          <TechnicalServicePageLinks />
        </div>

        <StatusMessage message={message} />

        <div className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_420px]">
          <div className="space-y-5">
            <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                <div>
                  <h2 className="text-lg font-semibold text-slate-950">Tekil QR oluştur</h2>
                  <p className="mt-1 text-sm text-slate-500">Seri no önce mevcut Mikro resolver ile çözülebilir. Çözülmezse ürün bilgisi manuel girilir.</p>
                </div>
                <Badge variant="outline" className="w-fit border-blue-200 bg-blue-50 text-blue-700">
                  QR URL token taşır
                </Badge>
              </div>

              <div className="grid gap-3 md:grid-cols-[minmax(0,1fr)_auto_auto] md:items-end">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Seri no
                  <Input value={serialNumber} onChange={(event) => setSerialNumber(event.target.value)} placeholder="SN001" />
                </label>
                <Button type="button" variant="secondary" onClick={() => void resolveSerial()}>
                  <Search className="mr-2 size-4" />
                  Mikro sorgula
                </Button>
                <Button type="button" onClick={() => void createSingle()}>
                  <QrCode className="mr-2 size-4" />
                  QR oluştur
                </Button>
              </div>

              <div className="grid gap-3 md:grid-cols-3">
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Ürün adı
                  <Input value={productName} onChange={(event) => setProductName(event.target.value)} placeholder="Akıllı Kilit" />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Model
                  <Input value={productModel} onChange={(event) => setProductModel(event.target.value)} placeholder="DDL720" />
                </label>
                <label className="grid gap-2 text-sm font-medium text-slate-700">
                  Marka
                  <Input value={brand} onChange={(event) => setBrand(event.target.value)} placeholder="Emaks Prime" />
                </label>
              </div>

              {context ? (
                <div className="grid gap-3 rounded-2xl border border-blue-100 bg-blue-50 p-4 md:grid-cols-2 xl:grid-cols-4">
                  <InfoTile label="Mikro ürün" value={context.product_name} />
                  <InfoTile label="Stok kodu" value={context.stock_code} />
                  <InfoTile label="Montaj kararı" value={mountStatusLabels[context.sale_mount_status ?? 'unknown'] ?? context.sale_mount_status} />
                  <InfoTile label="Seri durumu" value={serialStateLabels[context.current_serial_state ?? 'unknown'] ?? context.current_serial_state} />
                </div>
              ) : null}
            </section>

            <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex flex-col gap-2 md:flex-row md:items-start md:justify-between">
                <div>
                  <h2 className="text-lg font-semibold text-slate-950">Toplu seri yükle</h2>
                  <p className="mt-1 text-sm text-slate-500">CSV kolonları: seri_no, product_name, model, brand. Aynı seri tekrar gelirse yeni QR üretilmez.</p>
                </div>
                <Button type="button" variant="outline" onClick={() => fileInputRef.current?.click()}>
                  <FileText className="mr-2 size-4" />
                  CSV seç
                </Button>
              </div>

              <input
                ref={fileInputRef}
                type="file"
                accept=".csv,text/csv,text/plain"
                className="hidden"
                onChange={(event) => setCsvFile(event.target.files?.[0] ?? null)}
              />

              {csvFile ? (
                <div className="rounded-2xl border border-slate-200 bg-slate-50 p-3 text-sm font-semibold text-slate-700">
                  Seçilen dosya: {csvFile.name}
                </div>
              ) : null}

              <textarea
                value={csvText}
                onChange={(event) => setCsvText(event.target.value)}
                rows={7}
                className="min-h-44 w-full resize-y rounded-2xl border border-slate-200 bg-slate-50 p-3 font-mono text-sm text-slate-900 outline-none transition focus:border-blue-300 focus:bg-white"
              />

              <div className="flex flex-wrap gap-2">
                <Button type="button" onClick={() => void submitBulk()}>
                  Toplu QR oluştur
                </Button>
                <Button type="button" variant="outline" onClick={() => setCsvText(emptyCsv)}>
                  Örnek CSV yükle
                </Button>
              </div>

              {bulkResults ? (
                <div className="grid gap-3">
                  <div className="grid gap-3 md:grid-cols-4">
                    <InfoTile label="Toplam" value={bulkResults.summary.total} />
                    <InfoTile label="Oluşturulan" value={bulkResults.summary.created} />
                    <InfoTile label="Güncellenen" value={bulkResults.summary.updated ?? 0} />
                    <InfoTile label="Duplicate" value={bulkResults.summary.skipped} />
                    <InfoTile label="Hatalı" value={bulkResults.summary.failed} />
                  </div>
                  {bulkResults.meta?.results_truncated ? (
                    <div className="rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900">
                      Sonuç listesi performans için ilk {bulkResults.meta.result_limit} satırla sınırlandı.
                    </div>
                  ) : null}
                  <div className="max-h-72 overflow-auto rounded-2xl border border-slate-200">
                    {bulkResults.results.map((result) => (
                      <div key={`${result.row}-${result.serial_number ?? result.link?.serial_number ?? result.status}`} className="grid gap-1 border-b border-slate-100 p-3 last:border-b-0">
                        <div className="flex flex-wrap items-center gap-2">
                          <Badge variant="outline" className={result.status === 'failed' ? 'border-rose-200 bg-rose-50 text-rose-700' : 'border-emerald-200 bg-emerald-50 text-emerald-700'}>
                            Satır {result.row}
                          </Badge>
                          <span className="text-sm font-semibold text-slate-950">{result.link?.serial_number ?? result.serial_number ?? '-'}</span>
                        </div>
                        <p className="text-sm text-slate-500">{result.message}</p>
                      </div>
                    ))}
                  </div>
                  {bulkResults.errors?.length ? (
                    <div className="grid gap-2 rounded-2xl border border-rose-200 bg-rose-50 p-3">
                      <p className="text-sm font-semibold text-rose-800">
                        İlk {bulkResults.errors.length} hatalı satır
                        {bulkResults.meta?.errors_truncated ? ' gösteriliyor' : ''}
                      </p>
                      {bulkResults.errors.map((result) => (
                        <div key={`error-${result.row}-${result.serial_number ?? result.message}`} className="rounded-xl bg-white p-3 text-sm text-rose-800">
                          <span className="font-semibold">Satır {result.row}:</span> {result.message}
                        </div>
                      ))}
                    </div>
                  ) : null}
                </div>
              ) : null}
            </section>

            <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex flex-col gap-3 md:flex-row md:items-end md:justify-between">
                <div>
                  <h2 className="text-lg font-semibold text-slate-950">QR listesi</h2>
                  <p className="mt-1 text-sm text-slate-500">Aktif QR kayıtları, yazdırma ve okutma bilgisi.</p>
                </div>
                <div className="flex gap-2">
                  <Input
                    value={search}
                    onChange={(event) => {
                      setSearch(event.target.value)
                      setPage(1)
                    }}
                    placeholder="Seri, ürün, model ara"
                  />
                  <Button type="button" variant="outline" onClick={() => void loadLinks(page)} disabled={loading}>
                    <RefreshCw className="size-4" />
                  </Button>
                </div>
              </div>

              <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-3 md:grid-cols-4">
                <Input
                  value={productFilter}
                  onChange={(event) => {
                    setProductFilter(event.target.value)
                    setPage(1)
                  }}
                  placeholder="Ürün filtresi"
                />
                <Input
                  value={modelFilter}
                  onChange={(event) => {
                    setModelFilter(event.target.value)
                    setPage(1)
                  }}
                  placeholder="Model filtresi"
                />
                <Input
                  value={brandFilter}
                  onChange={(event) => {
                    setBrandFilter(event.target.value)
                    setPage(1)
                  }}
                  placeholder="Marka filtresi"
                />
                <select
                  value={statusFilter}
                  onChange={(event) => {
                    setStatusFilter(event.target.value)
                    setPage(1)
                  }}
                  className="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm outline-none transition focus-visible:ring-1 focus-visible:ring-ring"
                >
                  <option value="">Tüm durumlar</option>
                  <option value="active">Aktif</option>
                  <option value="revoked">İptal edildi</option>
                  <option value="expired">Süresi doldu</option>
                </select>
                <select
                  value={perPage}
                  onChange={(event) => {
                    setPerPage(Number(event.target.value))
                    setPage(1)
                  }}
                  className="rounded-md border border-input bg-background px-3 py-2 text-sm shadow-sm outline-none transition focus-visible:ring-1 focus-visible:ring-ring md:col-span-4"
                >
                  <option value={25}>Sayfa başı 25</option>
                  <option value={50}>Sayfa başı 50</option>
                </select>
              </div>

              <div className="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-blue-100 bg-blue-50 p-3">
                <div className="grid gap-1">
                  <p className="text-sm font-semibold text-blue-900">
                    Toplam {listMeta.total} kayıt. {listMeta.from ?? 0}-{listMeta.to ?? 0} arası gösteriliyor.
                  </p>
                  <p className="text-xs font-medium text-blue-800">
                    Seçili QR: {selectedIds.length}. Seçim yoksa önizlenen QR yazdırılır.
                  </p>
                </div>
                <div className="flex flex-wrap gap-2">
                  <Button
                    type="button"
                    variant="outline"
                    onClick={() => setSelectedIds(links.map((link) => link.id))}
                    disabled={links.length === 0}
                  >
                    Görünenleri seç
                  </Button>
                  <Button type="button" variant="outline" onClick={() => setSelectedIds([])} disabled={selectedIds.length === 0}>
                    Seçimi temizle
                  </Button>
                </div>
              </div>

              <div className="flex flex-wrap items-center justify-between gap-2 rounded-2xl border border-slate-200 bg-white p-3">
                <Button type="button" variant="outline" onClick={() => setPage((current) => Math.max(1, current - 1))} disabled={loading || listMeta.page <= 1}>
                  Önceki
                </Button>
                <p className="text-sm font-semibold text-slate-700">
                  Sayfa {listMeta.page} / {listMeta.last_page}
                </p>
                <Button type="button" variant="outline" onClick={() => setPage((current) => Math.min(listMeta.last_page, current + 1))} disabled={loading || listMeta.page >= listMeta.last_page}>
                  Sonraki
                </Button>
              </div>

              <div className="grid gap-3">
                {links.length === 0 ? (
                  <div className="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                    QR kaydı yok.
                  </div>
                ) : links.map((link) => (
                  <div
                    key={link.id}
                    className={[
                      'grid gap-3 rounded-2xl border p-4 text-left transition hover:border-blue-200 hover:bg-blue-50/60',
                      selectedLink?.id === link.id ? 'border-blue-300 bg-blue-50' : 'border-slate-200 bg-white',
                    ].join(' ')}
                  >
                    <div className="flex flex-wrap items-start justify-between gap-3">
                      <div className="flex min-w-0 flex-1 items-start gap-3">
                        <input
                          type="checkbox"
                          aria-label={`${link.serial_number} QR seç`}
                          checked={selectedIds.includes(link.id)}
                          onChange={(event) => {
                            setSelectedIds((current) => event.target.checked
                              ? Array.from(new Set([...current, link.id]))
                              : current.filter((id) => id !== link.id))
                          }}
                          className="mt-1 size-4 rounded border-slate-300 text-blue-700 focus:ring-blue-500"
                        />
                        <span className="min-w-0">
                          <button
                            type="button"
                            onClick={() => setSelectedLink(link)}
                            className="block min-w-0 text-left"
                          >
                            <span className="block text-sm font-semibold text-slate-950">{link.serial_number}</span>
                            <span className="mt-1 block truncate text-sm text-slate-600">{link.product_name} {link.product_model ? `/ ${link.product_model}` : ''}</span>
                          </button>
                        </span>
                      </div>
                      <div className="flex flex-wrap gap-2">
                        <Badge variant="outline" className={statusClasses[link.status] ?? statusClasses.active}>{link.status_label}</Badge>
                        <Badge variant="outline" className="border-slate-200 bg-slate-50 text-slate-700">{link.link_type_label}</Badge>
                      </div>
                    </div>
                    <div className="grid gap-2 text-xs text-slate-500 md:grid-cols-4">
                      <span>Okutma: {link.scan_count}</span>
                      <span>Son okutma: {formatDateTime(link.last_scanned_at)}</span>
                      <span>Yazdırma: {formatDateTime(link.printed_at)}</span>
                      <span>Oturum: {link.sessions_count}</span>
                    </div>
                  </div>
                ))}
              </div>
            </section>
          </div>

          <aside className="space-y-5 xl:sticky xl:top-24 xl:self-start">
            <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <div className="flex items-start justify-between gap-3">
                <div>
                  <h2 className="text-lg font-semibold text-slate-950">QR önizleme</h2>
                  <p className="mt-1 text-sm text-slate-500">Seçili ürünün public montaj linki.</p>
                </div>
                <QrCode className="size-6 text-slate-400" />
              </div>

              {selectedLink ? (
                <>
                  <div className="grid place-items-center rounded-3xl border border-slate-200 bg-slate-50 p-5">
                    <img src={selectedLink.qr_svg_url} alt={`${selectedLink.serial_number} QR`} className="size-64 rounded-2xl bg-white p-3 shadow-sm" />
                  </div>
                  <div className="grid gap-3">
                    <InfoTile label="Seri" value={selectedLink.serial_number} />
                    <InfoTile label="Ürün" value={selectedLink.product_name} />
                    <InfoTile label="Model" value={selectedLink.product_model} />
                    <InfoTile label="Marka" value={selectedLink.brand} />
                  </div>
                  <code className="block break-all rounded-2xl border border-slate-200 bg-slate-50 p-3 text-xs font-semibold text-slate-700">
                    {selectedLink.public_url}
                  </code>
                  <div className="grid gap-2 rounded-2xl border border-slate-200 bg-slate-50 p-3">
                    <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Yazdırma düzeni</p>
                    <div className="grid grid-cols-2 gap-2">
                      <Button
                        type="button"
                        variant={printColumns === 2 ? 'default' : 'outline'}
                        onClick={() => setPrintColumns(2)}
                      >
                        2 kolon
                      </Button>
                      <Button
                        type="button"
                        variant={printColumns === 3 ? 'default' : 'outline'}
                        onClick={() => setPrintColumns(3)}
                      >
                        3 kolon
                      </Button>
                    </div>
                    <p className="text-xs font-semibold text-slate-600">
                      {selectedPrintLinks.length} QR etiketi yazdırılacak.
                    </p>
                  </div>
                  <div className="grid grid-cols-2 gap-2">
                    <Button type="button" variant="outline" onClick={() => void copyText(selectedLink.public_url, 'QR linki')}>
                      <Copy className="mr-2 size-4" />
                      Kopyala
                    </Button>
                    <Button type="button" variant="outline" asChild>
                      <a href={selectedLink.public_url} target="_blank" rel="noreferrer">
                        <ExternalLink className="mr-2 size-4" />
                        Aç
                      </a>
                    </Button>
                    <Button type="button" className="col-span-2" onClick={() => void markPrintedAndPrint()}>
                      <Printer className="mr-2 size-4" />
                      {selectedIds.length > 0 ? 'Seçili QR etiketlerini yazdır' : 'Tekil QR yazdır'}
                    </Button>
                  </div>
                </>
              ) : (
                <div className="rounded-2xl border border-dashed border-slate-300 p-6 text-center text-sm text-slate-500">
                  Önizleme için listeden QR seçin.
                </div>
              )}
            </section>
          </aside>
        </div>
      </div>

      <div className="qr-print-root hidden print:block">
        <div
          className="grid gap-4 p-6"
          style={{ gridTemplateColumns: `repeat(${printColumns}, minmax(0, 1fr))` }}
        >
          {selectedPrintLinks.map((link) => (
            <div key={link.id} className="break-inside-avoid rounded-xl border border-slate-300 p-4">
              <div className="grid gap-3">
                <div className="flex items-start gap-4">
                  <img src={link.qr_svg_url} alt={`${link.serial_number} QR`} className="size-36 shrink-0" />
                  <div className="grid gap-1 text-sm">
                    <strong className="text-base">{link.product_name}</strong>
                    <span>Model: {link.product_model || '-'}</span>
                    <span>Marka: {link.brand || '-'}</span>
                    <span>Seri No: {link.serial_number}</span>
                  </div>
                </div>
                <p className="text-xs font-semibold text-slate-700">Montaj talebi için QR kodu okutun.</p>
                <span className="break-all text-[10px] text-slate-500">{link.public_url}</span>
              </div>
            </div>
          ))}
        </div>
      </div>
    </>
  )
}
