import path from 'node:path'
import process from 'node:process'
import { chromium } from 'file:///C:/Users/Emaks/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs'

const baseUrl = process.env.PAYMENT_ORDER_DOM_BASE_URL ?? 'http://127.0.0.1:5175'
const artifactDir = process.env.PAYMENT_ORDER_DOM_ARTIFACT_DIR ?? process.cwd()
const failures = []
const browserErrors = []

const assert = (condition, message) => {
  if (!condition) {
failures.push(message)
}
}

const output = async (page, id) => (await page.getByTestId(id).textContent() ?? '').trim()
const text = async (locator) => (await locator.innerText()).trim()
const money = (value) => `${new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} TL`
const roundTwo = (value) => Math.round((value + Number.EPSILON) * 100) / 100
const taxProfileByItemCode = {
  'EE.BCK.STD.0010': { pointer: 7, rate: 20 },
  'EE.BCK.STD.0011': { pointer: 8, rate: 10 },
  TKN000011: { pointer: 7, rate: 20 },
}
const normalizedSearch = (value) => value
  .normalize('NFD')
  .replace(/\p{M}/gu, '')
  .replace(/[ıİ]/g, 'I')
  .replace(/[şŞ]/g, 'S')
  .replace(/[ğĞ]/g, 'G')
  .replace(/[üÜ]/g, 'U')
  .replace(/[öÖ]/g, 'O')
  .replace(/[çÇ]/g, 'C')
  .toUpperCase()
  .replace(/\s+/g, ' ')
  .trim()

const stockItems = [
  {
    item_code: 'EE.BCK.STD.0010', item_name: 'PHILIPS SUNUM STANDI - D BOY', item_short_name: 'SUNUM STANDI',
    item_kind: 'accessory', item_kind_label: 'Aksesuar / sunum ekipmanı', classification_source: 'mikro_stock_type',
    classification_contract_version: 'technical-service-part-classification-v2', selectable: true, selection_blocker: null,
    unit_code: 'ADET', warehouse_code: null, on_hand: null, reserved: null, available: null, availability_verified: true,
    physical_stock_state: 'positive', physical_stock_verified: true, physical_stock_total: '83.000000', physical_stock_total_label: '83',
    physical_stock_warehouses: [{ warehouse_code: 1, physical_quantity: '83.000000' }, { warehouse_code: 5, physical_quantity: '0.000000' }],
    physical_stock_contract_version: 'technical-service-part-physical-stock-v1', physical_stock_correlation_id: 'dom-accessory-0010',
    stock_status_label: 'Stokta: 83 ADET', serial_tracking_state: 'not_required', serial_tracking_required: false, serials: [], source: 'mikro', source_label: 'Mikro API',
    freshness_at: '2026-08-14T17:45:00+03:00', mikro_contract_fingerprint: '1'.repeat(64), selection_token: 'stand-one-token',
  },
  {
    item_code: 'EE.BCK.STD.0011', item_name: 'PHILIPS SUNUM STANDI - Y BOY', item_short_name: 'SUNUM STANDI',
    item_kind: 'accessory', item_kind_label: 'Aksesuar / sunum ekipmanı', classification_source: 'mikro_stock_type',
    classification_contract_version: 'technical-service-part-classification-v2', selectable: true, selection_blocker: null,
    unit_code: 'ADET', warehouse_code: null, on_hand: null, reserved: null, available: null, availability_verified: true,
    physical_stock_state: 'positive', physical_stock_verified: true, physical_stock_total: '4.000000', physical_stock_total_label: '4',
    physical_stock_warehouses: [{ warehouse_code: 1, physical_quantity: '4.000000' }, { warehouse_code: 5, physical_quantity: '0.000000' }],
    physical_stock_contract_version: 'technical-service-part-physical-stock-v1', physical_stock_correlation_id: 'dom-accessory-0011',
    stock_status_label: 'Stokta: 4 ADET', serial_tracking_state: 'not_required', serial_tracking_required: false, serials: [], source: 'mikro', source_label: 'Mikro API',
    freshness_at: '2026-08-14T17:45:00+03:00', mikro_contract_fingerprint: '1'.repeat(64), selection_token: 'stand-two-token',
  },
  {
    item_code: 'TKN000011', item_name: 'Seri Takipli Motor', item_short_name: null,
    item_kind: 'part', item_kind_label: 'Yedek parça', classification_source: 'mikro_stock_type',
    classification_contract_version: 'technical-service-part-classification-v2', selectable: true, selection_blocker: null,
    unit_code: 'ADET', warehouse_code: null, on_hand: null, reserved: null, available: null, availability_verified: true,
    physical_stock_state: 'positive', physical_stock_verified: true, physical_stock_total: '2.000000', physical_stock_total_label: '2',
    physical_stock_warehouses: [{ warehouse_code: 1, physical_quantity: '2.000000' }, { warehouse_code: 5, physical_quantity: '0.000000' }],
    physical_stock_contract_version: 'technical-service-part-physical-stock-v1', physical_stock_correlation_id: 'dom-serial-0011',
    stock_status_label: 'Stokta: 2 ADET',
    serial_tracking_state: 'required', serial_tracking_required: true, serials: [], source: 'mikro', source_label: 'Mikro API',
    freshness_at: '2026-08-14T17:45:00+03:00', mikro_contract_fingerprint: '1'.repeat(64), selection_token: 'serial-part-token',
  },
  {
    item_code: 'TKN000009', item_name: 'DDL 720 DIŞ DOKUMATİK', item_short_name: 'DIŞ DOKUMATİK',
    item_kind: 'part', item_kind_label: 'Yedek parça', classification_source: 'mikro_stock_type',
    classification_contract_version: 'technical-service-part-classification-v2', selectable: false, selection_blocker: 'Stokta yok',
    unit_code: 'ADET', warehouse_code: null, on_hand: null, reserved: null, available: null, availability_verified: true,
    physical_stock_state: 'out_of_stock', physical_stock_verified: true, physical_stock_total: '0.000000', physical_stock_total_label: '0',
    physical_stock_warehouses: [{ warehouse_code: 1, physical_quantity: '0.000000' }, { warehouse_code: 5, physical_quantity: '0.000000' }],
    physical_stock_contract_version: 'technical-service-part-physical-stock-v1', physical_stock_correlation_id: 'dom-zero-0009',
    stock_status_label: 'Stokta yok', serial_tracking_state: 'not_required', serial_tracking_required: false, serials: [], source: 'mikro', source_label: 'Mikro API',
    freshness_at: '2026-08-14T17:45:00+03:00', mikro_contract_fingerprint: '1'.repeat(64), selection_token: 'tkn-zero-token',
  },
  {
    item_code: 'FAIL-001', item_name: 'Mikro Okuma Hatası Fikstürü', item_short_name: null,
    item_kind: 'accessory', item_kind_label: 'Aksesuar / sunum ekipmanı', classification_source: 'mikro_stock_type',
    classification_contract_version: 'technical-service-part-classification-v2', selectable: false, selection_blocker: 'Stok doğrulanamadı',
    unit_code: 'ADET', warehouse_code: null, on_hand: null, reserved: null, available: null, availability_verified: false,
    physical_stock_state: 'unverified', physical_stock_verified: false, physical_stock_total: null, physical_stock_total_label: null,
    physical_stock_warehouses: [], physical_stock_contract_version: 'technical-service-part-physical-stock-v1', physical_stock_correlation_id: null,
    stock_status_label: 'Stok doğrulanamadı', serial_tracking_state: 'not_required', serial_tracking_required: false, serials: [], source: 'mikro', source_label: 'Mikro API',
    freshness_at: null, mikro_contract_fingerprint: '1'.repeat(64), selection_token: 'failure-token',
  },
  {
    item_code: 'EP.BCK.003.0001.R001', item_name: 'Akıllı Kilit Cihazı', item_short_name: null,
    item_kind: 'device', item_kind_label: 'Cihaz / ürün', classification_source: 'panel_product_catalog',
    classification_contract_version: 'technical-service-part-classification-v2', selectable: false,
    selection_blocker: 'Bu stok cihaz ekleme akışına aittir; parça ödemesine eklenemez.',
    unit_code: 'ADET', warehouse_code: null, on_hand: null, reserved: null, available: null, availability_verified: false,
    physical_stock_state: 'not_applicable', physical_stock_verified: false, physical_stock_total: null, physical_stock_total_label: null,
    physical_stock_warehouses: [], physical_stock_contract_version: 'technical-service-part-physical-stock-v1', physical_stock_correlation_id: null,
    stock_status_label: null,
    serial_tracking_state: 'required', serial_tracking_required: true, serials: [], source: 'mikro', source_label: 'Mikro API',
    freshness_at: '2026-08-14T17:45:00+03:00', mikro_contract_fingerprint: '1'.repeat(64), selection_token: 'device-token',
  },
]

const previewResponse = (payload, taxProfilesAvailable) => {
  const input = payload.order_context ?? {}
  const commercialMode = input.commercial_mode ?? 'paid'
  const deliveryMode = input.delivery_mode ?? 'shipment'
  const forceZero = commercialMode === 'free' && deliveryMode === 'shipment'
  const desiredSeries = deliveryMode === 'hand_delivery' || commercialMode === 'free' ? 'Q' : 'S'
  const taxMode = desiredSeries === 'Q' ? 'none' : 'standard_from_mikro'
  const rawLines = Array.isArray(input.lines) ? input.lines : []
  const lines = rawLines.map((raw, index) => {
    const item = stockItems.find((candidate) => candidate.selection_token === raw.stock_selection_token)
    const quantity = Number(raw.quantity ?? 0)
    const unitPrice = forceZero ? 0 : Number(raw.unit_price ?? 0)
    const lineTotal = roundTwo(quantity * unitPrice)
    const installedTax = taxProfileByItemCode[item?.item_code]
    const selectedTaxRate = taxMode === 'none' ? 0 : taxProfilesAvailable ? installedTax?.rate ?? null : null
    const taxVerified = selectedTaxRate !== null
    const netLineTotal = taxVerified ? roundTwo(lineTotal / (1 + selectedTaxRate / 100)) : null
    const vatLineTotal = netLineTotal === null ? null : roundTwo(lineTotal - netLineTotal)

    return {
      id: null, line_key: item?.item_code ?? `line-${index}`, position: index + 1, selection_token: raw.stock_selection_token,
      item_code: item?.item_code ?? 'UNKNOWN', item_name: item?.item_name ?? 'Doğrulanmamış satır', item_short_name: item?.item_short_name ?? null,
      item_kind: item?.item_kind ?? 'unknown', classification_source: item?.classification_source ?? 'no_canonical_evidence',
      classification_contract_version: item?.classification_contract_version ?? 'technical-service-part-classification-v2',
      quantity, unit_code: item?.unit_code ?? null, unit_price: unitPrice, unit_price_label: money(unitPrice),
      line_total: lineTotal, line_total_label: money(lineTotal), currency: 'TRY', warehouse_code: null,
      gross_unit_price: unitPrice, gross_unit_price_label: money(unitPrice), gross_line_total: lineTotal, gross_line_total_label: money(lineTotal),
      net_line_total: netLineTotal, net_line_total_label: netLineTotal === null ? null : money(netLineTotal),
      vat_line_total: vatLineTotal, vat_line_total_label: vatLineTotal === null ? null : money(vatLineTotal),
      retail_tax_pointer: taxMode === 'none' ? null : installedTax?.pointer ?? null,
      retail_tax_rate: taxMode === 'none' ? null : installedTax?.rate ?? null,
      wholesale_tax_pointer: taxMode === 'none' ? null : installedTax?.pointer ?? null,
      wholesale_tax_rate: taxMode === 'none' ? null : installedTax?.rate ?? null,
      selected_tax_basis: taxMode === 'none' ? 'q_series_zero' : taxVerified ? 'equal_rates' : null,
      selected_tax_pointer: taxMode === 'none' ? null : taxVerified ? installedTax?.pointer ?? null : null,
      selected_tax_rate: selectedTaxRate,
      selected_tax_rate_label: selectedTaxRate === null ? null : `%${selectedTaxRate}`,
      tax_status: taxVerified ? 'verified' : 'unavailable',
      tax_source: taxMode === 'none' ? 'commercial_matrix' : 'mikro_api',
      tax_freshness_at: taxVerified ? '2026-08-16T11:00:00+03:00' : null,
      tax_contract_version: taxMode === 'none' ? 'technical-service-commercial-matrix-v1' : 'technical-service-stock-tax-profile-v1',
      stock_source: 'mikro', stock_source_label: 'Mikro API', stock_freshness_at: item?.freshness_at ?? null,
      availability_verified: item?.physical_stock_verified === true,
      physical_stock_verified: item?.physical_stock_verified === true,
      physical_stock_state: item?.physical_stock_state ?? 'unverified',
      physical_stock_total: item?.physical_stock_total ?? null,
      physical_stock_total_snapshot: item?.physical_stock_total ?? null,
      physical_stock_total_label: item?.physical_stock_total_label ?? null,
      physical_stock_contract_version: item?.physical_stock_contract_version ?? null,
      stock_status_label: item?.stock_status_label ?? null,
      serial_tracking_state: item?.serial_tracking_state ?? 'unverified',
      serial_tracking_required: item?.serial_tracking_state === 'required', selected_part_serial: null,
    }
  })
  const total = roundTwo(lines.reduce((sum, line) => sum + line.line_total, 0))
  const collectionTotal = commercialMode === 'paid' ? total : 0
  const taxVerified = lines.length > 0 && lines.every((line) => line.tax_status === 'verified')
  const netTotal = taxVerified ? roundTwo(lines.reduce((sum, line) => sum + line.net_line_total, 0)) : null
  const vatTotal = netTotal === null ? null : roundTwo(total - netTotal)
  const taxRates = [...new Set(lines.map((line) => line.selected_tax_rate).filter((rate) => rate !== null))]
  const mixedVatRates = taxVerified && taxRates.length > 1
  const shipmentRequired = deliveryMode === 'shipment'
  const paymentLinkRequired = commercialMode === 'paid' && shipmentRequired
  const serialBlocker = lines.some((line) => line.serial_tracking_state === 'required')
  const physicalStockBlocker = lines.some((line) => line.physical_stock_verified !== true)
    ? 'Mikro stok bilgisi doğrulanamadı. Stok doğrulanmadan işlem tamamlanamaz.'
    : lines.some((line) => Number(line.physical_stock_total_snapshot) <= 0)
      ? 'Seçilen parçalardan en az biri stokta bulunmuyor.'
      : null
  const taxBlocker = taxMode === 'standard_from_mikro' && !taxVerified
    ? 'KDV bilgisi Mikro stok kartından doğrulanmadan ücretli sevk hazırlığı tamamlanamaz.'
    : null
  const blockers = [
    physicalStockBlocker,
    serialBlocker ? 'Bu parça seri numarasıyla takip ediliyor. Güncel parça seri seçimi doğrulanmadan ödeme/sipariş hazırlığı tamamlanamaz.' : null,
    taxBlocker,
  ].filter(Boolean)
  const lineDescription = lines.map((line, index) => [
    `${index + 1}. ${line.quantity} ${line.unit_code ?? ''} · ${line.item_code} · ${line.item_name}`,
    `   BİRİM TUTAR (KDV DAHİL): ${line.gross_unit_price_label}`,
    `   SATIR TOPLAMI (KDV DAHİL): ${line.gross_line_total_label}`,
    `   KDV ORANI: ${line.selected_tax_rate_label ?? 'DOĞRULANMAYI BEKLİYOR'}`,
    `   MATRAH: ${line.net_line_total_label ?? '-'}`,
    `   KDV TUTARI: ${line.vat_line_total_label ?? '-'}`,
  ].join('\n')).join('\n\n')
  const totalQuantity = lines.reduce((sum, line) => sum + line.quantity, 0)

  return {
    ok: true,
    order_context: {
      id: null, payment_id: null, request_id: 9001, root_request_id: 9001, srv_request_id: null,
      payment_purpose: 'part_charge', purpose_label: 'Parça ödemesi', context_type: 'part_sale', state: 'draft',
      state_label: 'Parça taslağı; stok uygunluğu bekleniyor', desired_mikro_series: desiredSeries, tax_mode: taxMode,
      tax_label: taxMode === 'none' ? 'Yok / %0' : taxVerified ? mixedVatRates ? 'Satır bazında farklı oranlar · toplam fiyata dahil' : `%${taxRates[0]} · toplam fiyata dahil` : 'Mikro stok kartından',
      tax_status: taxVerified ? 'verified' : 'unavailable', tax_source: taxMode === 'none' ? 'commercial_matrix' : 'mikro_api',
      tax_source_label: taxMode === 'none' ? 'Ticari karar matrisi' : 'Mikro API', mixed_vat_rates: mixedVatRates,
      vat_rate: taxVerified && taxRates.length === 1 ? taxRates[0] : taxMode === 'none' ? 0 : null,
      future_mikro_write_state: 'not_authorized', future_mikro_write_label: 'Mikro yazımı bu aşamada kapalı',
      billing: { source: 'mrn_customer', billing_type: 'individual', name_or_title: 'Test Müşteri', phone: '9053****633', address: 'Test adresi', city: 'İstanbul', district: 'Kadıköy' },
      shipping_same_as_billing: shipmentRequired, delivery_target: shipmentRequired ? 'billing_address' : input.delivery_target ?? 'mrn_customer',
      delivery_target_label: shipmentRequired ? 'Fatura adresi' : 'MRN müşterisi',
      shipping: shipmentRequired ? { recipient_name: 'Test Müşteri', recipient_phone: '9053****633', address: 'Test adresi', city: 'İstanbul', district: 'Kadıköy' } : null,
      part_supplier: 'emaks_prime', part_supplier_label: 'EMAKS Prime', collection_allocation: 'retain_company',
      collection_allocation_label: 'Şirkette bırakılacak', part: lines[0] ?? null, lines, line_count: lines.length,
      total_quantity: totalQuantity, total_quantity_label: String(totalQuantity), commercial_mode: commercialMode,
      commercial_mode_label: commercialMode === 'free' ? 'Ücretsiz' : 'Ücretli', delivery_mode: deliveryMode,
      delivery_mode_label: deliveryMode === 'hand_delivery' ? 'Elden' : 'Sevk', delivery_status: deliveryMode === 'hand_delivery' ? 'pending' : null,
      delivery_status_label: deliveryMode === 'hand_delivery' ? 'Teslim bekliyor' : null,
      payment_collection_mode: paymentLinkRequired ? 'payment_link' : collectionTotal > 0 ? 'manual' : 'none',
      payment_status: collectionTotal > 0 ? 'pending' : 'not_required', payment_status_label: collectionTotal > 0 ? 'Ödeme bekleniyor' : 'Tahsilat gerekmiyor',
      payment_status_source: 'system', payment_status_source_label: 'Sistem', payment_link_required: paymentLinkRequired,
      collection_required: collectionTotal > 0, order_line_unit_price: lines.length === 1 ? lines[0].unit_price : 0,
      order_line_unit_price_label: money(lines.length === 1 ? lines[0].unit_price : 0), order_line_total: total,
      order_line_total_label: money(total), order_reference_total: total, order_reference_total_label: money(total),
      gross_total: total, gross_total_label: money(total), net_total: netTotal, net_total_label: netTotal === null ? null : money(netTotal),
      vat_total: vatTotal, vat_total_label: vatTotal === null ? null : money(vatTotal),
      collection_amount: collectionTotal, collection_amount_label: money(collectionTotal),
      future_order_trigger: paymentLinkRequired ? 'payment_paid' : commercialMode === 'paid' ? 'delivery_recorded' : 'ops_approved',
      finance_review_required: false, related_product_serial: 'SERI-DOM', charged_amount: total, charged_amount_label: money(total),
      currency: 'TRY', shipment_required: shipmentRequired, future_carrier_state: shipmentRequired ? 'waiting_future_integration' : 'not_required',
      future_carrier_label: shipmentRequired ? 'Kargo hazırlığı bekliyor; HepsiJet entegrasyonu çalıştırılmayacak' : 'Sevkiyat yok',
      readiness: { ready: blockers.length === 0, order_ready: blockers.length === 0, payment_ready: blockers.length === 0,
        blocker_codes: [
          ...(physicalStockBlocker ? ['physical_stock_unverified'] : []),
          ...(serialBlocker ? ['part_serial_selection_unverified'] : []),
          ...(taxBlocker ? ['vat_unverified'] : []),
        ], blockers },
      description2_preview: `MRN/SRV: MRN-DOM-EARNING\nİLGİLİ ÜRÜN SERİ NO: SERI-DOM\n\nPARÇALAR:\n${lineDescription}\n\nPARÇA KALEMİ: ${lines.length}\nTOPLAM ADET: ${totalQuantity}\nSİPARİŞ/REFERANS TOPLAMI (KDV DAHİL): ${money(total)}\nMÜŞTERİDEN TAHSİL EDİLECEK: ${money(collectionTotal)}\nKDV HARİÇ TOPLAM: ${netTotal === null ? '-' : money(netTotal)}\nKDV TOPLAMI: ${vatTotal === null ? '-' : money(vatTotal)}\nKDV TOPLAMA DAHİLDİR.\nTİCARİ DURUM: ${commercialMode === 'free' ? 'ÜCRETSİZ' : 'ÜCRETLİ'}\nTESLİM: ${deliveryMode === 'hand_delivery' ? 'ELDEN' : 'SEVK'}\nHEDEF SERİ: ${desiredSeries}\nKDV: ${taxMode === 'none' ? 'YOK / %0' : taxVerified ? 'MİKRO API' : 'DOĞRULANMAYI BEKLİYOR'}`,
      description2_version: 3, context_hash: (total === 2000 ? 'b' : 'c').repeat(64), revision: 1,
      mikro_write_execution_count: 0, carrier_execution_count: 0,
      payment_retry: { state: 'none', fresh_link_required: false, reason_required: false, action_label: null, message: null,
        authoritative_counts: { paid: 0, pending: 0, cancelled: 0, failed: 0, expired: 0 } },
    },
    external_execution: { mikro_read: 0, mikro_write: 0, hepsijet: 0 },
  }
}

const openPaymentModal = async (page) => {
  await page.locator('button').filter({ hasText: /^(Ödeme Al|Yeni ek ödeme al)$/ }).last().click()
  const dialog = page.getByRole('dialog', { name: /^(Ödeme Al|Yeni ek ödeme al)$/ })
  await dialog.waitFor({ state: 'visible' })
  await dialog.getByLabel('Tahsilat amacı').selectOption('part_charge')
  await dialog.getByRole('button', { name: 'EMAKS Prime', exact: true }).click()

  return dialog
}

const searchFor = async (dialog, query, code) => {
  const search = dialog.getByLabel('Mikro stok parçası ara')
  await search.fill(query)
  const row = dialog.getByTestId('mikro-part-search-results').locator('div').filter({ hasText: code }).first()
  await row.waitFor({ state: 'visible', timeout: 5000 })

  if (code !== 'EP.BCK.003.0001.R001') {
    await row.getByText(/^(Stokta:|Stokta yok|Stok doğrulanamadı)/).waitFor({ state: 'visible', timeout: 5000 })
  }

  return row
}

const waitForPreview = async (dialog, expected) => {
  const preview = dialog.getByTestId('payment-order-context-preview')
  await preview.waitFor({ state: 'visible', timeout: 5000 })
  await preview.getByText(expected, { exact: false }).first().waitFor({ state: 'visible', timeout: 5000 })

  return preview
}

const inspectViewport = async (browser, name, viewport) => {
  const counters = { partSearch: 0, physicalStock: 0, searchRetry: 0, physicalRetry: 0, taxRetry: 0, preview: 0, realExternal: 0, maxPhysicalInFlight: 0, maxTaxInFlight: 0 }
  let physicalInFlight = 0
  let taxInFlight = 0
  let taxProfilesAvailable = false
  const page = await browser.newPage({ viewport })
  page.on('pageerror', (error) => browserErrors.push(`${name}:page:${error.message}`))
  page.on('request', (request) => {
    if (/mikro|hepsijet|n8n/i.test(request.url()) && !request.url().includes('/payments/order-context/')) {
counters.realExternal += 1
}
  })
  await page.route('**/api/technical-service/requests/*/payments/order-context/parts*', async (route) => {
    if (route.request().method() === 'POST') {
      counters.physicalStock += 1
      physicalInFlight += 1
      counters.maxPhysicalInFlight = Math.max(counters.maxPhysicalInFlight, physicalInFlight)
      const payload = route.request().postDataJSON()
      const tokens = Array.isArray(payload.identity_tokens) ? payload.identity_tokens : []
      const retry = payload.retry_scope === 'physical_stock'

      if (retry) {
        counters.physicalRetry += 1
      }

      const items = stockItems
        .filter((item) => tokens.includes(item.selection_token))
        .map((item) => retry && item.item_code === 'FAIL-001'
          ? {
              ...item,
              identity_state: 'current',
              selectable: true,
              selection_blocker: null,
              physical_stock_state: 'positive',
              physical_stock_verified: true,
              physical_stock_total: '3.000000',
              physical_stock_total_label: '3',
              physical_stock_correlation_id: 'dom-retry-failure-001',
              stock_status_label: 'Stokta: 3 ADET',
              freshness_at: '2026-08-14T17:46:00+03:00',
            }
          : { ...item, identity_state: 'current' })
      const failed = items.some((item) => item.item_code === 'FAIL-001' && !retry)

      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        ok: true,
        source: 'mikro',
        source_label: 'Mikro API',
        freshness_at: '2026-08-14T17:45:00+03:00',
        search_state: 'current',
        physical_stock_state: failed ? 'unavailable' : 'current',
        error_code: failed ? 'MIKRO_CONNECT_TIMEOUT' : null,
        error_message: failed ? 'Ürün Mikro API’den bulundu. Stok miktarı doğrulanamadı.' : null,
        items,
        write_execution_count: 0,
      }) })
      physicalInFlight -= 1

      return
    }

    counters.partSearch += 1
    const url = new URL(route.request().url())
    const query = normalizedSearch(url.searchParams.get('query') ?? '')
    const retry = url.searchParams.get('retry_scope') === 'search'

    if (retry) {
      counters.searchRetry += 1
    }

    if (query === 'SEARCH-FAIL' && !retry) {
      await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
        ok: true,
        source: 'mikro',
        source_label: 'Mikro API',
        search_state: 'unavailable',
        physical_stock_state: 'not_requested',
        error_code: 'MIKRO_CONNECT_TIMEOUT',
        error_message: 'Mikro stok araması şu anda yapılamıyor. Stok aramasını yeniden dene.',
        items: [],
        write_execution_count: 0,
      }) })

      return
    }

    const matched = query === 'SEARCH-FAIL' && retry
      ? [stockItems[0]]
      : stockItems.filter((item) => normalizedSearch(`${item.item_code} ${item.item_name} ${item.item_short_name ?? ''}`).includes(query)).slice(0, 20)
    const items = matched.map((item) => (item.item_kind === 'part' || item.item_kind === 'accessory')
      ? {
          ...item,
          selectable: false,
          selection_blocker: 'Stok kontrol ediliyor...',
          physical_stock_state: 'unverified',
          physical_stock_verified: false,
          physical_stock_total: null,
          physical_stock_total_label: null,
          stock_status_label: 'Stok kontrol ediliyor...',
          identity_state: 'current',
        }
      : { ...item, identity_state: 'current' })

    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({
      ok: true, source: 'mikro', source_label: 'Mikro API', freshness_at: '2026-08-14T15:00:00+03:00', search_state: 'current', physical_stock_state: 'not_requested', items, write_execution_count: 0,
    }) })
  })
  await page.route('**/api/technical-service/requests/*/payments/order-context/preview', async (route) => {
    counters.preview += 1
    const payload = route.request().postDataJSON()
    const retry = payload.order_context?.retry_scope === 'tax_profile'

    if (retry) {
      counters.taxRetry += 1
      taxProfilesAvailable = true
    }

    taxInFlight += 1
    counters.maxTaxInFlight = Math.max(counters.maxTaxInFlight, taxInFlight)
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(previewResponse(payload, taxProfilesAvailable)) })
    taxInFlight -= 1
  })

  await page.goto(`${baseUrl}/tests/Browser/assignment-earning-canonical.html`, { waitUntil: 'networkidle' })
  await page.waitForFunction(() => window.__assignmentEarningDomReady === true)
  let dialog = await openPaymentModal(page)

  let row = await searchFor(dialog, 'EE.BCK.STD.0010', 'EE.BCK.STD.0010')
  let rowText = await text(row)
  assert(rowText.includes('PHILIPS SUNUM STANDI'), `${name}: exact code did not return installed Mikro identity`)
  assert(rowText.includes('Mikro API'), `${name}: source label is missing`)
  assert(rowText.includes('Güncellik:'), `${name}: freshness is missing`)
  assert(rowText.includes('Aksesuar / sunum ekipmanı'), `${name}: accessory classification is missing`)
  assert(rowText.includes('Stokta: 83 ADET'), `${name}: positive physical stock is missing`)
  assert(!(await row.getByRole('button', { name: 'Ekle' }).isDisabled()), `${name}: positive-stock accessory is blocked`)
  assert(!/Depo:|Eldeki:|Rezerve:|Kullanılabilir:/.test(rowText), `${name}: reserved or available inventory was rendered`)

  row = await searchFor(dialog, 'PHILIPS SUNUM STANDI', 'EE.BCK.STD.0010')
  assert((await text(row)).includes('EE.BCK.STD.0010'), `${name}: installed stock name search failed`)
  row = await searchFor(dialog, 'SUNUM STANDI', 'EE.BCK.STD.0010')
  assert((await text(row)).includes('EE.BCK.STD.0010'), `${name}: normalized stock name search failed`)

  const zeroStockRow = await searchFor(dialog, 'TKN000009', 'TKN000009')
  const zeroStockText = await text(zeroStockRow)
  assert(zeroStockText.includes('Yedek parça'), `${name}: part classification is missing`)
  assert(zeroStockText.includes('Stokta yok'), `${name}: zero physical stock label is missing`)
  assert(await zeroStockRow.getByRole('button', { name: 'Ekle' }).isDisabled(), `${name}: zero-stock add action is enabled`)

  const failedStockRow = await searchFor(dialog, 'FAIL-001', 'FAIL-001')
  assert((await text(failedStockRow)).includes('Stok doğrulanamadı'), `${name}: failed physical stock label is missing`)
  assert(await failedStockRow.getByRole('button', { name: 'Ekle' }).isDisabled(), `${name}: unverified-stock add action is enabled`)
  assert(await dialog.getByRole('button', { name: 'Stoku yeniden kontrol et', exact: true }).count() === 1, `${name}: physical-stock retry action is missing`)

  const deviceRow = await searchFor(dialog, 'EP.BCK.003.0001.R001', 'EP.BCK.003.0001.R001')
  const deviceText = await text(deviceRow)
  assert(deviceText.includes('Cihaz / ürün'), `${name}: canonical device classification is missing`)
  assert(deviceText.includes('cihaz ekleme akışına'), `${name}: device blocker is missing`)
  assert(await deviceRow.getByRole('button', { name: 'Ekle' }).isDisabled(), `${name}: device add action is enabled`)

  row = await searchFor(dialog, 'EE.BCK.STD.0010', 'EE.BCK.STD.0010')
  await row.getByRole('button', { name: 'Ekle' }).click()
  let selected = dialog.getByTestId('selected-payment-part-line')
  assert(await selected.count() === 1, `${name}: first part line was not added`)

  row = await searchFor(dialog, 'EE.BCK.STD.0011', 'EE.BCK.STD.0011')
  assert(await selected.count() === 1, `${name}: search change erased selected line`)
  assert((await text(row)).includes('Stokta: 4 ADET'), `${name}: second accessory physical stock is missing`)
  await row.getByRole('button', { name: 'Ekle' }).click()
  assert(await selected.count() === 2, `${name}: second part line was not added`)

  const selectedCountBeforeRetry = await selected.count()
  const retryRow = await searchFor(dialog, 'FAIL-001', 'FAIL-001')
  assert((await text(retryRow)).includes('Stok doğrulanamadı'), `${name}: controlled quantity failure did not stay distinct`)
  await dialog.getByRole('button', { name: 'Stoku yeniden kontrol et', exact: true }).click()
  await retryRow.getByText('Stokta: 3 ADET', { exact: true }).waitFor({ state: 'visible', timeout: 5000 })
  assert(!(await retryRow.getByRole('button', { name: 'Ekle' }).isDisabled()), `${name}: successful quantity retry did not enable Add`)
  assert(await selected.count() === selectedCountBeforeRetry, `${name}: quantity retry cleared selected lines`)

  await dialog.getByLabel('Mikro stok parçası ara').fill('SEARCH-FAIL')
  await dialog.getByText('Mikro stok araması şu anda yapılamıyor. Stok aramasını yeniden dene.', { exact: true }).waitFor({ state: 'visible', timeout: 5000 })
  assert(!(await text(dialog)).includes('Mikro stok bağlantısı hazır değil.'), `${name}: typed search failure collapsed to global readiness`)
  await dialog.getByRole('button', { name: 'Stok aramasını yeniden dene', exact: true }).click()
  const searchRetryRow = dialog.getByTestId('mikro-part-search-results').locator('div').filter({ hasText: 'EE.BCK.STD.0010' }).first()
  await searchRetryRow.waitFor({ state: 'visible', timeout: 5000 })
  await searchRetryRow.getByText('Stokta: 83 ADET', { exact: true }).waitFor({ state: 'visible', timeout: 5000 })
  assert(await selected.count() === selectedCountBeforeRetry, `${name}: stock-search retry cleared selected lines`)

  await dialog.getByLabel('Mikro stok parçası ara').fill('bulunmayan parça')
  await dialog.getByText('Aramaya uygun parça bulunamadı.', { exact: true }).waitFor({ state: 'visible' })
  assert(await selected.count() === 2, `${name}: empty search result erased selected lines`)

  row = await searchFor(dialog, 'EE.BCK.STD.0010', 'EE.BCK.STD.0010')
  await row.getByRole('button', { name: 'Ekle' }).click()
  assert(await selected.count() === 2, `${name}: duplicate item created a third line`)
  const firstLine = selected.filter({ hasText: 'EE.BCK.STD.0010' })
  let secondLine = selected.filter({ hasText: 'EE.BCK.STD.0011' })
  assert(await firstLine.getByLabel('Adet').inputValue() === '2', `${name}: duplicate add did not increment quantity`)

  await secondLine.getByLabel('Adet').fill('5')
  assert((await text(secondLine)).includes('Stokta yalnız 4 ADET bulunuyor.'), `${name}: quantity-over-stock warning is missing`)
  await secondLine.getByLabel('Adet').fill('1')

  await firstLine.getByLabel('Adet').fill('2')
  await firstLine.getByLabel('Birim fiyat').fill('500')
  await secondLine.getByLabel('Adet').fill('1')
  await secondLine.getByLabel('Birim fiyat').fill('1000')
  const grandTotal = dialog.getByText(/Genel toplam:/).first()
  await grandTotal.waitFor({ state: 'visible' })
  assert((await text(grandTotal)).includes('2.000'), `${name}: grand total is not 2.000 TL`)
  assert((await text(firstLine)).includes('1.000 TL'), `${name}: first line total is wrong`)
  assert((await text(secondLine)).includes('1.000 TL'), `${name}: second line total is wrong`)

  await secondLine.getByRole('button', { name: /satırını sil/ }).click()
  assert(await selected.count() === 1, `${name}: removing one line removed another line or failed`)
  row = await searchFor(dialog, 'EE.BCK.STD.0011', 'EE.BCK.STD.0011')
  await row.getByRole('button', { name: 'Ekle' }).click()
  secondLine = selected.filter({ hasText: 'EE.BCK.STD.0011' })
  await secondLine.getByLabel('Birim fiyat').fill('1000')
  assert(await selected.count() === 2, `${name}: second line could not be restored`)

  const commercial = dialog.getByRole('group', { name: 'Parça ticari durumu' })
  const delivery = dialog.getByRole('group', { name: 'Parça teslim şekli' })
  await commercial.getByRole('button', { name: 'Ücretsiz', exact: true }).click()
  await delivery.getByRole('button', { name: 'Elden', exact: true }).click()
  let preview = await waitForPreview(dialog, 'MÜŞTERİDEN TAHSİL EDİLECEK: 0,00 TL')
  let previewText = await text(preview)
  assert(previewText.includes('Hedef seri: Q'), `${name}: free hand target series is not Q`)
  assert(previewText.includes('KDV: YOK / %0'), `${name}: free hand VAT is not zero`)
  assert(previewText.includes('2.000,00 TL'), `${name}: free hand reference total is wrong`)

  await delivery.getByRole('button', { name: 'Sevk', exact: true }).click()
  const zeroGrandTotal = dialog.getByText(/Genel toplam:/).first()
  await zeroGrandTotal.waitFor({ state: 'visible' })
  assert((await text(zeroGrandTotal)).includes('0'), `${name}: free shipment grand total is not zero`)

  for (const line of await selected.all()) {
    const price = line.getByLabel('Birim fiyat')
    assert(await price.inputValue() === '0', `${name}: free shipment did not force zero price`)
    assert(await price.getAttribute('readonly') !== null, `${name}: free shipment price remains editable`)
  }

  preview = await waitForPreview(dialog, 'SİPARİŞ/REFERANS TOPLAMI (KDV DAHİL): 0,00 TL')
  previewText = await text(preview)
  assert(previewText.includes('Hedef seri: Q'), `${name}: free shipment target series is not Q`)
  assert(previewText.includes('Tahsilat tutarı: 0,00 TL'), `${name}: free shipment collection is nonzero`)

  await commercial.getByRole('button', { name: 'Ücretli', exact: true }).click()
  await delivery.getByRole('button', { name: 'Elden', exact: true }).click()
  await dialog.getByLabel('Elden teslim alıcısı').selectOption('mrn_customer')
  preview = await waitForPreview(dialog, 'MÜŞTERİDEN TAHSİL EDİLECEK: 2.000,00 TL')
  previewText = await text(preview)
  assert(previewText.includes('Hedef seri: Q'), `${name}: paid hand target series is not Q`)
  assert(previewText.includes('Ödeme bağlantısı: Yok'), `${name}: paid hand incorrectly requires a payment link`)

  await delivery.getByRole('button', { name: 'Sevk', exact: true }).click()
  preview = await waitForPreview(dialog, 'HEDEF SERİ: S')
  previewText = await text(preview)
  assert(previewText.includes('KDV kaynağı: Mikro API'), `${name}: paid shipment lost Mikro VAT authority`)
  assert(previewText.includes('Ödeme bağlantısı: Gerekli'), `${name}: paid shipment canonical link requirement is missing`)
  assert(previewText.includes('KDV bilgisi Mikro stok kartından doğrulanmadan'), `${name}: VAT readiness blocker is missing`)
  assert(await preview.getByRole('button', { name: 'KDV’yi yeniden kontrol et', exact: true }).count() === 1, `${name}: tax retry action is missing`)
  const selectedCountBeforeTaxRetry = await selected.count()
  const partSearchBeforeTaxRetry = counters.partSearch
  const physicalStockBeforeTaxRetry = counters.physicalStock
  const modalScrollBeforeTaxRetry = await dialog.evaluate((element) => element.scrollTop)
  await preview.getByRole('button', { name: 'KDV’yi yeniden kontrol et', exact: true }).click()
  await preview.getByText('KDV toplamı: 257,58 TL', { exact: false }).first().waitFor({ state: 'visible', timeout: 5000 })
  previewText = await text(preview)
  assert(previewText.includes('Brüt toplam (KDV dahil): 2.000,00 TL'), `${name}: gross total changed after VAT read`)
  assert(previewText.includes('KDV hariç toplam: 1.742,42 TL'), `${name}: mixed VAT net total is wrong`)
  assert(previewText.includes('KDV toplamı: 257,58 TL'), `${name}: mixed VAT total is wrong`)
  assert(previewText.includes('KDV oranı: %20'), `${name}: first line Mikro VAT rate is missing`)
  assert(previewText.includes('KDV oranı: %10'), `${name}: second line Mikro VAT rate is missing`)
  const dialogTextAfterTaxRetry = await text(dialog)
  assert(dialogTextAfterTaxRetry.includes('Satır bazında farklı KDV oranları uygulanıyor.'), `${name}: mixed-rate notice is missing`)
  assert(dialogTextAfterTaxRetry.includes('KDV müşteriye ayrıca eklenmeyecek; toplam tutara dahildir.'), `${name}: VAT-inclusive notice is missing`)
  assert(!previewText.includes('+ KDV'), `${name}: VAT was presented as an addition to gross`)
  assert(await selected.count() === selectedCountBeforeTaxRetry, `${name}: tax retry cleared selected lines`)
  assert(counters.partSearch === partSearchBeforeTaxRetry, `${name}: tax retry reran stock search`)
  assert(counters.physicalStock === physicalStockBeforeTaxRetry, `${name}: tax retry reran physical stock`)
  assert(await dialog.evaluate((element) => element.scrollTop) === modalScrollBeforeTaxRetry, `${name}: tax retry reset modal scroll`)
  assert(await output(page, 'payment-order-create-count') === '0', `${name}: tax retry created a payment/context side effect`)

  row = await searchFor(dialog, 'TKN000011', 'TKN000011')
  await row.getByRole('button', { name: 'Ekle' }).click()
  const serialLine = selected.filter({ hasText: 'TKN000011' })
  assert((await text(serialLine)).includes('Güncel parça seri seçimi doğrulanmadan'), `${name}: serial readiness blocker is missing`)
  assert(await serialLine.locator('select').count() === 0, `${name}: fake serial selector was rendered`)
  await serialLine.getByRole('button', { name: /satırını sil/ }).click()

  taxProfilesAvailable = false
  await secondLine.getByLabel('Birim fiyat').fill('999')
  await secondLine.getByLabel('Birim fiyat').fill('1000')
  await waitForPreview(dialog, 'KDV bilgisi Mikro stok kartından doğrulanmadan')

  const submit = dialog.getByRole('button', { name: 'Parça taslağını kaydet', exact: true })
  await submit.dblclick()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentCreateCount === 1)
  assert(await output(page, 'payment-order-create-count') === '1', `${name}: double submit created duplicate context`)
  assert(await dialog.getByText('Parça taslağı kaydedildi', { exact: false }).count() === 1, `${name}: draft-save feedback is missing`)

  const lastLineBox = await selected.last().boundingBox()
  const submitBox = await submit.boundingBox()
  assert(Boolean(lastLineBox && submitBox && lastLineBox.y + lastLineBox.height <= submitBox.y + 1), `${name}: final selected line intersects the action area`)
  assert(await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth + 1), `${name}: modal has horizontal overflow`)

  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()
  assert(await page.getByTestId('part-order-context-summary').count() === 1, `${name}: saved multi-line summary is missing`)
  assert(!(await text(page.getByTestId('part-order-context-summary'))).includes('Ödeme alındı'), `${name}: blocked draft is falsely shown as paid`)

  dialog = await openPaymentModal(page)
  selected = dialog.getByTestId('selected-payment-part-line')
  await selected.first().waitFor({ state: 'visible', timeout: 5000 })
  assert(await selected.count() === 2, `${name}: reopening did not hydrate both lines`)
  assert(await selected.filter({ hasText: 'EE.BCK.STD.0010' }).getByLabel('Adet').inputValue() === '2', `${name}: reopened quantity changed`)
  assert(await selected.filter({ hasText: 'EE.BCK.STD.0010' }).getByLabel('Birim fiyat').inputValue() === '500', `${name}: reopened first price changed`)
  assert(await selected.filter({ hasText: 'EE.BCK.STD.0011' }).getByLabel('Birim fiyat').inputValue() === '1000', `${name}: reopened second price changed`)
  assert((await text(dialog.getByText(/Genel toplam:/).first())).includes('2.000'), `${name}: reopened grand total changed`)

  await selected.first().getByRole('button', { name: /satırını sil/ }).click()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentOrderStateUpdateCount === 1)
  assert(await selected.count() === 1, `${name}: persisted line removal did not produce 2 → 1`)
  await selected.first().getByRole('button', { name: /satırını sil/ }).click()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentOrderStateUpdateCount === 2)
  await dialog.getByTestId('selected-payment-parts-empty').waitFor({ state: 'visible', timeout: 5000 })
  assert(await selected.count() === 0, `${name}: final persisted line did not produce 1 → 0`)
  assert((await text(dialog.getByTestId('selected-payment-parts'))).includes('Seçilen parçalar (0)'), `${name}: explicit empty count is missing`)
  assert((await text(dialog.getByTestId('selected-payment-parts'))).includes('Genel toplam: 0'), `${name}: explicit empty total is not zero`)

  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()
  dialog = await openPaymentModal(page)
  selected = dialog.getByTestId('selected-payment-part-line')
  await dialog.getByTestId('selected-payment-parts-empty').waitFor({ state: 'visible', timeout: 5000 })
  assert(await selected.count() === 0, `${name}: close/reopen rehydrated cleared persisted lines`)

  row = await searchFor(dialog, 'EE.BCK.STD.0010', 'EE.BCK.STD.0010')
  assert(await selected.count() === 0, `${name}: stock search rehydrated cleared persisted lines`)
  await row.getByRole('button', { name: 'Ekle' }).click()
  assert(await selected.count() === 1, `${name}: a new line could not be added after explicit clear`)
  await selected.first().getByRole('button', { name: /satırını sil/ }).click()
  await dialog.getByTestId('selected-payment-parts-empty').waitFor({ state: 'visible', timeout: 5000 })
  assert(await selected.count() === 0, `${name}: newly added final line returned after local removal`)
  assert(await output(page, 'payment-order-state-update-count') === '2', `${name}: local unsaved line removal emitted a duplicate persisted mutation`)

  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()
  await page.reload({ waitUntil: 'networkidle' })
  dialog = await openPaymentModal(page)
  selected = dialog.getByTestId('selected-payment-part-line')
  await dialog.getByTestId('selected-payment-parts-empty').waitFor({ state: 'visible', timeout: 5000 })
  assert(await selected.count() === 0, `${name}: page refresh rehydrated cleared persisted lines`)
  assert((await text(dialog.getByTestId('selected-payment-parts'))).includes('Genel toplam: 0'), `${name}: refreshed empty total is not zero`)

  const bodyOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  const overflowOwners = await page.evaluate(() => Array.from(document.querySelectorAll('body *'))
    .map((element) => ({ element, rect: element.getBoundingClientRect() }))
    .filter(({ rect }) => rect.right > document.documentElement.clientWidth + 1 || rect.left < -1)
    .slice(0, 8)
    .map(({ element, rect }) => ({
      tag: element.tagName,
      testid: element.getAttribute('data-testid'),
      className: element.getAttribute('class'),
      left: Math.round(rect.left),
      right: Math.round(rect.right),
      width: Math.round(rect.width),
    })))
  assert(bodyOverflow <= 1, `${name}: page horizontal overflow is ${bodyOverflow}px; owners=${JSON.stringify(overflowOwners)}`)
  assert(await output(page, 'financial-board-refetch-count') === '0', `${name}: board refetch count is not zero`)
  assert(await output(page, 'financial-modal-mount-count') === '1', `${name}: detail modal remounted`)
  assert(await output(page, 'assignment-scroll-reset-count') === '0', `${name}: scroll reset count is not zero`)
  assert(counters.searchRetry === 1, `${name}: search retry count is ${counters.searchRetry}`)
  assert(counters.physicalRetry === 1, `${name}: physical retry count is ${counters.physicalRetry}`)
  assert(counters.taxRetry === 1, `${name}: tax retry count is ${counters.taxRetry}`)
  assert(counters.maxPhysicalInFlight === 1, `${name}: parallel physical-stock request count exceeded one`)
  assert(counters.maxTaxInFlight === 1, `${name}: parallel tax-profile request count exceeded one`)
  assert(counters.realExternal === 0, `${name}: external Mikro/HepsiJet/n8n count is ${counters.realExternal}`)
  const fullText = await text(dialog)
  assert(!fullText.includes('TS-PART-001'), `${name}: TS-PART fixture is visible`)
  assert(!fullText.includes('Gateway'), `${name}: synthetic Gateway fixture is visible`)
  assert(!/\b[QS][-/]\d{2,}\b/.test(fullText), `${name}: fake Q/S order number is visible`)
  assert(!fullText.includes('Sipariş oluşturuldu'), `${name}: false order-created claim is visible`)

  await page.screenshot({ path: path.join(artifactDir, `payment-order-multi-line-${name}.png`), fullPage: true })
  await page.close()

  return counters
}

const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' })
const results = {
  desktop: await inspectViewport(browser, 'desktop', { width: 1440, height: 1000 }),
  mobile: await inspectViewport(browser, 'mobile', { width: 390, height: 844 }),
}

await browser.close()
assert(browserErrors.length === 0, `browser errors: ${browserErrors.join(' | ')}`)
console.log(JSON.stringify({ result: failures.length === 0 ? 'passed' : 'failed', results, failures, browserErrors }, null, 2))

if (failures.length > 0) {
process.exitCode = 1
}
