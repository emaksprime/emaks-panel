import { Head, usePage } from '@inertiajs/react'
import { Copy, Search } from 'lucide-react'
import { useMemo, useState } from 'react'
import Heading from '@/components/heading'
import { TechnicalServicePageLinks } from '@/components/technical-service/TechnicalServicePageLinks'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { apiRequest } from '@/lib/api'
import type { SharedPageProps } from '@/types'

type ActivationCodeSearchItem = {
  id: number
  serial_no: string
  serial_prefix: string | null
  stock_name: string | null
  stock_code: string | null
  activation_code: string | null
  activation_code_missing: boolean
}

type SearchResponse = {
  query: string
  normalized_query: string
  count: number
  items: ActivationCodeSearchItem[]
}

type ImportError = {
  row: number
  reason: string
  data: unknown
}

type ImportResult = {
  created_count: number
  updated_count: number
  skipped_count: number
  errors: ImportError[]
  source_file_name?: string
}

const activationImportColumns = ['STOK_KODU', 'STOK_ADI', 'SERI_NO']

const csrfToken = () => document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') ?? ''
const normalizeSerialQuery = (value: string) => value.toLocaleUpperCase('tr-TR').replace(/[^A-Z0-9]+/g, '')

function ActivationResultCard({
  item,
  copiedId,
  onCopy,
}: {
  item: ActivationCodeSearchItem
  copiedId: number | null
  onCopy: (item: ActivationCodeSearchItem) => Promise<void>
}) {
  const activationValue = item.activation_code?.trim() || null

  return (
    <article className="grid gap-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
      <div className="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
        <div>
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Aktivasyon Sonucu</p>
          <h2 className="mt-2 text-xl font-semibold text-slate-950">{item.stock_name || 'Stok adı yok'}</h2>
          <p className="mt-2 text-sm text-slate-500">{item.stock_code || 'Stok kodu yok'}</p>
        </div>

        <div className="min-w-0 rounded-3xl border border-slate-200 bg-slate-50 p-4 lg:min-w-[320px]">
          <p className="text-xs font-semibold uppercase tracking-[0.18em] text-slate-500">Aktivasyon Kodu</p>
          {activationValue ? (
            <div className="mt-3 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
              <p className="break-all text-3xl font-black tracking-[0.14em] text-slate-950">{activationValue}</p>
              <Button type="button" variant="secondary" onClick={() => void onCopy(item)}>
                <Copy className="mr-2 size-4" />
                {copiedId === item.id ? 'Kopyalandı' : 'Kopyala'}
              </Button>
            </div>
          ) : (
            <div className="mt-3 rounded-2xl border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
              Bu kayıtta aktivasyon kodu bulunamadı.
            </div>
          )}
        </div>
      </div>

      <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
        {[
          ['Seri No', item.serial_no || '-'],
          ['Ana Seri', item.serial_prefix || '-'],
          ['Stok Adı', item.stock_name || '-'],
          ['Stok Kodu', item.stock_code || '-'],
        ].map(([label, value]) => (
          <div key={label} className="rounded-2xl border border-slate-200 bg-slate-50 p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className="mt-2 break-words text-sm font-medium text-slate-900">{value}</p>
          </div>
        ))}
      </div>
    </article>
  )
}

export default function ActivationCodeSearchPage() {
  const { auth } = usePage<SharedPageProps>().props
  const [query, setQuery] = useState('')
  const [result, setResult] = useState<SearchResponse | null>(null)
  const [loading, setLoading] = useState(false)
  const [error, setError] = useState<string | null>(null)
  const [hasSearched, setHasSearched] = useState(false)
  const [copiedId, setCopiedId] = useState<number | null>(null)
  const [csvFile, setCsvFile] = useState<File | null>(null)
  const [importing, setImporting] = useState(false)
  const [importResult, setImportResult] = useState<ImportResult | null>(null)
  const [importError, setImportError] = useState<string | null>(null)

  const resultCount = result?.items.length ?? 0
  const singleResult = resultCount === 1 ? result?.items[0] ?? null : null
  const multipleResults = useMemo(() => (resultCount > 1 ? result?.items ?? [] : []), [result, resultCount])
  const canImport = Boolean(auth.user)

  const runSearch = async () => {
    const trimmedQuery = query.trim()
    const normalizedQuery = normalizeSerialQuery(trimmedQuery)

    if (normalizedQuery.length < 6) {
      setError('En az 6 karakter seri no girin.')
      setHasSearched(false)
      setResult(null)
      return
    }

    setLoading(true)
    setError(null)
    setCopiedId(null)
    setHasSearched(true)

    try {
      const params = new URLSearchParams({ query: trimmedQuery })
      const response = await apiRequest(`/api/activation-code-search?${params.toString()}`)
      setResult(response as SearchResponse)
    } catch (caught) {
      setError(caught instanceof Error ? caught.message : 'Arama yapılamadı.')
      setResult(null)
    } finally {
      setLoading(false)
    }
  }

  const handleCopy = async (item: ActivationCodeSearchItem) => {
    if (!item.activation_code) {
      return
    }

    try {
      await navigator.clipboard.writeText(item.activation_code)
      setCopiedId(item.id)
      window.setTimeout(() => setCopiedId((current) => (current === item.id ? null : current)), 1800)
    } catch {
      setError('Aktivasyon kodu kopyalanamadı.')
    }
  }

  const importCsv = async () => {
    if (!csvFile) {
      setImportError('CSV dosyası seçilmedi.')
      setImportResult(null)
      return
    }

    setImporting(true)
    setImportError(null)
    setImportResult(null)

    try {
      const formData = new FormData()
      formData.append('file', csvFile)

      const response = await fetch('/api/activation-code-search/import', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          Accept: 'application/json',
          ...(csrfToken() ? { 'X-CSRF-TOKEN': csrfToken() } : {}),
        },
        body: formData,
      })

      const payload = await response.json()

      if (!response.ok) {
        setImportResult(payload as ImportResult)
        setImportError(payload?.message || 'CSV import işlemi tamamlanamadı.')
        return
      }

      setImportResult(payload as ImportResult)
      setCsvFile(null)
    } catch (caught) {
      setImportError(caught instanceof Error ? caught.message : 'CSV import işlemi tamamlanamadı.')
    } finally {
      setImporting(false)
    }
  }

  return (
    <>
      <Head title="Aktivasyon Kodu Bul" />

      <div className="mx-auto w-full max-w-[1600px] space-y-6 px-4 py-6 md:px-6 lg:px-12">
        <div className="flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
          <Heading
            title="Aktivasyon Kodu Bul"
            description="Seri no yazın, en az 6 karakter girince eşleşen kayıtlar listelenir."
          />
          <div className="flex flex-wrap gap-2">
            <TechnicalServicePageLinks />
          </div>
        </div>

        <section className="grid gap-5 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
          <div className="grid gap-4 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-end">
            <label className="grid gap-2 text-sm font-medium text-slate-700">
              Arama
              <div className="relative">
                <Search className="pointer-events-none absolute left-4 top-1/2 size-5 -translate-y-1/2 text-slate-400" />
                <Input
                  value={query}
                  onChange={(event) => setQuery(event.target.value)}
                  onKeyDown={(event) => {
                    if (event.key === 'Enter') {
                      void runSearch()
                    }
                  }}
                  placeholder="Seri no yazın, en az 6 karakter girince eşleşen kayıtlar listelenir"
                  className="h-14 rounded-2xl pl-12 text-base"
                />
              </div>
            </label>
            <Button type="button" onClick={() => void runSearch()} disabled={loading} className="h-14 rounded-2xl px-8 text-base">
              {loading ? 'Aranıyor...' : 'Ara'}
            </Button>
          </div>

          <div className="grid gap-2 text-sm text-slate-500 lg:grid-cols-3">
            <p>Arama sadece seri no ana kısmı üzerinden yapılır.</p>
            <p>En az 6 karakter girince uyumlu seri numaraları listelenir.</p>
            <p>Aktivasyon kodu ile arama yapılmaz. Aktivasyon kodu, bulunan seri numarasından gösterilir.</p>
          </div>

          {error ? (
            <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
              {error}
            </div>
          ) : null}
        </section>

        {hasSearched && !loading && !error && resultCount === 0 ? (
          <section className="rounded-3xl border border-slate-200 bg-white p-6 text-sm text-slate-600 shadow-sm">
            Kayıt bulunamadı.
          </section>
        ) : null}

        {singleResult ? (
          <ActivationResultCard item={singleResult} copiedId={copiedId} onCopy={handleCopy} />
        ) : null}

        {multipleResults.length > 0 ? (
          <section className="grid gap-4">
            <div className="rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
              <p className="text-sm font-semibold text-slate-900">{multipleResults.length} eşleşme bulundu</p>
              <p className="mt-1 text-sm text-slate-500">Uyumlu seri numaraları listeleniyor. Doğru seri üzerinden aktivasyon kodunu alabilirsiniz.</p>
            </div>

            <div className="grid gap-4">
              {multipleResults.map((item) => (
                <ActivationResultCard key={item.id} item={item} copiedId={copiedId} onCopy={handleCopy} />
              ))}
            </div>
          </section>
        ) : null}

        {canImport ? (
          <section className="grid gap-4 rounded-3xl border border-slate-200 bg-white p-5 shadow-sm">
            <div className="flex flex-col gap-2">
              <h2 className="text-lg font-semibold text-slate-950">CSV ile kayıt yükle</h2>
              <p className="text-sm text-slate-500">
                Bakım ve toplu yükleme alanıdır. Arama sonuçlarının altında tutulur; import sonrası aynı ekrandan hemen seri no araması yapabilirsiniz.
              </p>
            </div>

            <div>
              <p className="text-sm font-medium text-slate-700">Beklenen CSV başlığı</p>
              <code className="mt-2 block overflow-x-auto rounded-2xl bg-slate-100 p-3 text-xs text-slate-700">
                {activationImportColumns.join(',')}
              </code>
              <p className="mt-2 text-xs text-slate-500">
                Virgül veya noktalı virgül ayracı desteklenir. Aktivasyon kodu CSV&apos;den okunmaz; `SERI_NO` içinden backend tarafından türetilir.
              </p>
            </div>

            <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto] lg:items-center">
              <Input
                type="file"
                accept=".csv,text/csv,text/plain"
                onChange={(event) => setCsvFile(event.target.files?.[0] ?? null)}
              />
              <Button type="button" onClick={() => void importCsv()} disabled={importing} className="h-11 rounded-2xl px-6">
                {importing ? 'İçe aktarılıyor...' : 'CSV Yükle'}
              </Button>
            </div>

            {importError ? (
              <div className="rounded-2xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-700">
                {importError}
              </div>
            ) : null}

            {importResult ? (
              <div className="grid gap-4 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
                <div className="flex flex-wrap gap-3 font-semibold text-slate-700">
                  <span>Oluşturulan kayıt: {importResult.created_count}</span>
                  <span>Güncellenen kayıt: {importResult.updated_count}</span>
                  <span>Atlanan kayıt: {importResult.skipped_count}</span>
                  <span>Hatalı satırlar: {importResult.errors.length}</span>
                </div>

                {importResult.source_file_name ? (
                  <p className="text-xs text-slate-500">Dosya: {importResult.source_file_name}</p>
                ) : null}

                {importResult.errors.length > 0 ? (
                  <div className="max-h-72 overflow-auto rounded-2xl border border-slate-200 bg-white">
                    {importResult.errors.map((entry, index) => (
                      <div key={`${entry.row}-${index}`} className="border-b border-slate-100 p-3 last:border-b-0">
                        <p className="font-semibold text-rose-700">Satır {entry.row}: {entry.reason}</p>
                        <pre className="mt-2 whitespace-pre-wrap break-words text-xs text-slate-500">{JSON.stringify(entry.data, null, 2)}</pre>
                      </div>
                    ))}
                  </div>
                ) : (
                  <div className="rounded-2xl border border-emerald-200 bg-emerald-50 p-3 text-sm text-emerald-700">
                    CSV import tamamlandı. Aynı ekrandan hemen seri no araması yapabilirsiniz.
                  </div>
                )}
              </div>
            ) : null}
          </section>
        ) : null}
      </div>
    </>
  )
}
