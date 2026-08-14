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

const text = async (locator) => (await locator.innerText()).trim()
const output = async (page, id) => (await page.getByTestId(id).textContent() ?? '').trim()

const stockItems = [
  {
    item_code: 'EMK-GW-001',
    item_name: 'Gateway Bağlantı Modülü',
    unit_code: 'ADET',
    warehouse_code: 'MERKEZ',
    on_hand: 24,
    reserved: 3,
    available: 21,
    serial_tracking_required: false,
    serials: [],
    source: 'mikro',
    source_label: 'Mikro API',
    freshness_at: '2026-08-14T06:00:00+03:00',
    selection_token: 'gateway-token',
  },
  {
    item_code: 'EMK-MOTOR-002',
    item_name: 'Akıllı Kilit Motor Modülü',
    unit_code: 'ADET',
    warehouse_code: 'MERKEZ',
    on_hand: 6,
    reserved: 1,
    available: 5,
    serial_tracking_required: true,
    serials: ['TSP-2026-0001', 'TSP-2026-0002'],
    source: 'mikro',
    source_label: 'Mikro API',
    freshness_at: '2026-08-14T06:00:00+03:00',
    selection_token: 'motor-token',
  },
  {
    item_code: 'EMK-IDENTITY-003',
    item_name: 'Mikro Kimlik Parçası',
    unit_code: 'ADET',
    warehouse_code: null,
    on_hand: null,
    reserved: null,
    available: null,
    serial_tracking_required: false,
    serials: [],
    source: 'mikro',
    source_label: 'Mikro API',
    freshness_at: '2026-08-14T12:00:00+03:00',
    selection_token: 'identity-only-token',
  },
]

const previewResponse = (payload) => {
  const input = payload.order_context ?? {}
  const purpose = payload.purpose
  const supplier = input.part_supplier ?? null
  const selectedPart = stockItems.find((item) => item.selection_token === input.stock_selection_token) ?? null
  const rawBilling = input.billing ?? {}
  const billing = input.billing_source === 'manual_billing_draft'
    ? {
        ...rawBilling,
        source: 'manual_billing_draft',
        name_or_title: rawBilling.billing_type === 'company'
          ? rawBilling.legal_title
          : [rawBilling.first_name, rawBilling.last_name].filter(Boolean).join(' '),
      }
    : {
        source: 'mrn_customer',
        billing_type: 'individual',
        name_or_title: 'Test Müşteri',
        phone: '9053****633',
        address: 'Test adresi',
        city: 'İstanbul',
        district: 'Kadıköy',
      }
  const commercialMode = purpose === 'part_charge' && supplier === 'emaks_prime'
    ? input.commercial_mode ?? 'paid'
    : purpose === 'mount_collection' ? 'paid' : null
  const deliveryMode = purpose === 'part_charge' && supplier === 'emaks_prime'
    ? input.delivery_mode ?? 'shipment'
    : null
  const shipmentRequired = purpose === 'part_charge' && supplier === 'emaks_prime' && deliveryMode === 'shipment'
  const paymentLinkRequired = purpose === 'mount_collection'
    || supplier === 'technician'
    || (supplier === 'emaks_prime' && commercialMode === 'paid' && deliveryMode === 'shipment')
  const collectionRequired = purpose === 'mount_collection'
    || supplier === 'technician'
    || (supplier === 'emaks_prime' && commercialMode === 'paid')
  const desiredSeries = purpose === 'mount_collection'
    ? 'S'
    : supplier === 'technician' ? null : deliveryMode === 'hand_delivery' || commercialMode === 'free' ? 'Q' : 'S'
  const taxMode = desiredSeries === 'Q'
    ? 'none'
    : purpose === 'mount_collection' ? 'standard_from_mikro_service_item' : supplier === 'emaks_prime' ? 'standard_from_mikro' : null
  const amount = Number(payload.amount ?? 0)
  const orderLineTotal = commercialMode === 'free' && deliveryMode === 'shipment' ? 0 : amount
  const collectionAmount = collectionRequired ? amount : 0
  const paymentStatus = collectionRequired ? 'pending' : 'not_required'
  const sameAsBilling = shipmentRequired && Boolean(input.shipping_same_as_billing)
  const shipping = !shipmentRequired
    ? null
    : sameAsBilling || input.delivery_target === 'billing_address'
      ? {
          recipient_name: billing.name_or_title,
          recipient_phone: billing.phone,
          address: billing.address,
          city: billing.city,
          district: billing.district,
        }
      : input.delivery_target === 'technician'
        ? {
            recipient_name: 'Test Usta',
            recipient_phone: '905467647428',
            address: 'Pamukkale Usta Adresi No:1',
            city: 'Denizli',
            district: 'Pamukkale',
          }
        : input.delivery_target === 'mrn_customer'
          ? {
              recipient_name: 'Test Müşteri',
              recipient_phone: '9053****633',
              address: 'Test adresi',
              city: 'İstanbul',
              district: 'Kadıköy',
            }
          : input.shipping
  const part = purpose !== 'part_charge'
    ? null
    : supplier === 'technician'
      ? {
          item_code: input.technician_part_code || null,
          item_name: input.technician_part_name,
          quantity: input.quantity,
          unit_code: 'ADET',
          stock_source: 'technician_declaration',
          stock_source_label: 'Usta beyanı',
          serial_tracking_required: false,
          selected_part_serial: null,
        }
      : selectedPart ? {
          ...selectedPart,
          quantity: input.quantity,
          selected_part_serial: input.selected_part_serial || null,
        } : null
  const money = (value) => `${new Intl.NumberFormat('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value)} TL`
  const lines = purpose === 'mount_collection'
    ? [
        'MRN/SRV: MRN-DOM-EARNING',
        'HİZMET: MONTAJ',
        'ÜRÜN: Test Model',
        'SERİ NO: SERI-DOM',
        `FATURA MÜŞTERİSİ: ${billing.name_or_title}`,
        'SEVKİYAT: YOK',
      ]
    : supplier === 'technician'
      ? [
          'MRN/SRV: MRN-DOM-EARNING',
          'İLGİLİ ÜRÜN SERİ NO: SERI-DOM',
          `PARÇA: ${part?.item_code ? `${part.item_code} - ` : ''}${part?.item_name ?? ''}`,
          `ADET: ${input.quantity}`,
          'TEDARİK: USTA',
          'SEVKİYAT: YOK',
          `FATURA MÜŞTERİSİ: ${billing.name_or_title}`,
        ]
      : [
          ...(shipmentRequired && !sameAsBilling ? ['SEVK ADRESİ FARKLIDIR.'] : []),
          'MRN/SRV: MRN-DOM-EARNING',
          'İLGİLİ ÜRÜN SERİ NO: SERI-DOM',
          `PARÇA: ${part?.item_code ? `${part.item_code} - ` : ''}${part?.item_name ?? ''}`,
          `ADET: ${input.quantity}`,
          ...(deliveryMode === 'hand_delivery' ? ['TESLİM: ELDEN'] : []),
          `TİCARİ DURUM: ${commercialMode === 'free' ? 'ÜCRETSİZ' : 'ÜCRETLİ'}`,
          ...(commercialMode === 'free' && deliveryMode === 'hand_delivery'
            ? [`SİPARİŞ SATIR DEĞERİ: ${money(orderLineTotal)}`]
            : commercialMode === 'free'
              ? [`SİPARİŞ TUTARI: ${money(0)}`]
              : [`TUTAR: ${money(amount)}`]),
          `KDV: ${taxMode === 'none' ? 'YOK' : 'MİKRO STOK KARTI'}`,
          `HEDEF SERİ: ${desiredSeries}`,
          ...(collectionRequired ? [`ÖDEME DURUMU: ${paymentStatus === 'pending' ? 'BEKLİYOR' : 'ALINDI'}`] : ['TAHSİLAT: GEREKMİYOR']),
          ...(shipmentRequired
            ? [
                `ALICI: ${shipping?.recipient_name ?? ''}`,
                `TELEFON: ${shipping?.recipient_phone ?? ''}`,
                `ADRES: ${shipping?.address ?? ''} / ${shipping?.district ?? ''} / ${shipping?.city ?? ''}`,
                `FATURA MÜŞTERİSİ: ${billing.name_or_title}`,
              ]
            : []),
        ]

  return {
    ok: true,
    order_context: {
      id: null,
      request_id: 9001,
      root_request_id: 9001,
      srv_request_id: null,
      payment_purpose: purpose,
      purpose_label: purpose === 'part_charge' ? 'Parça ödemesi' : 'Montaj ücreti tahsilatı',
      context_type: purpose === 'mount_collection' ? 'mount_service' : supplier === 'technician' ? 'technician_supplied_part' : 'part_sale',
      state: 'draft',
      desired_mikro_series: desiredSeries,
      tax_mode: taxMode,
      tax_label: taxMode === 'none' ? 'Yok / %0' : taxMode === 'standard_from_mikro_service_item' ? 'Mikro hizmet kartından' : taxMode === 'standard_from_mikro' ? 'Mikro stok kartından' : null,
      vat_rate: taxMode === 'none' ? 0 : null,
      future_mikro_write_state: supplier === 'technician' ? 'not_required' : 'not_authorized',
      future_mikro_write_label: supplier === 'technician' ? 'Mikro siparişi gerekmiyor' : 'Mikro yazımı bu aşamada kapalı',
      billing,
      shipping_same_as_billing: sameAsBilling,
      delivery_target: shipmentRequired ? (sameAsBilling ? 'billing_address' : input.delivery_target) : deliveryMode === 'hand_delivery' ? input.delivery_target : null,
      delivery_target_label: shipmentRequired ? (sameAsBilling ? 'Fatura adresi' : input.delivery_target) : deliveryMode === 'hand_delivery' ? input.delivery_target : null,
      shipping,
      part_supplier: supplier,
      part_supplier_label: supplier === 'technician' ? 'Usta' : supplier === 'emaks_prime' ? 'EMAKS Prime' : null,
      collection_allocation: supplier === 'technician' ? 'pay_technician' : supplier === 'emaks_prime' ? 'retain_company' : null,
      collection_allocation_label: supplier === 'technician' ? 'Ustaya hakediş olarak eklenecek' : supplier === 'emaks_prime' ? 'Şirkette bırakılacak' : null,
      part,
      commercial_mode: commercialMode,
      commercial_mode_label: commercialMode === 'free' ? 'Ücretsiz' : commercialMode === 'paid' ? 'Ücretli' : null,
      delivery_mode: deliveryMode,
      delivery_mode_label: deliveryMode === 'hand_delivery' ? 'Elden' : deliveryMode === 'shipment' ? 'Sevk' : 'Yok',
      delivery_status: deliveryMode === 'hand_delivery' ? 'pending' : null,
      delivery_status_label: deliveryMode === 'hand_delivery' ? 'Teslim bekliyor' : null,
      payment_collection_mode: paymentLinkRequired ? 'payment_link' : collectionRequired ? 'manual' : 'none',
      payment_status: paymentStatus,
      payment_status_label: paymentStatus === 'pending' ? 'Ödeme bekleniyor' : 'Tahsilat gerekmiyor',
      payment_status_source: 'system',
      payment_status_source_label: 'Sistem',
      payment_link_required: paymentLinkRequired,
      collection_required: collectionRequired,
      order_line_unit_price: orderLineTotal,
      order_line_unit_price_label: money(orderLineTotal),
      order_line_total: orderLineTotal,
      order_line_total_label: money(orderLineTotal),
      collection_amount: collectionAmount,
      collection_amount_label: money(collectionAmount),
      future_order_trigger: paymentLinkRequired ? 'payment_paid' : deliveryMode === 'hand_delivery' && commercialMode === 'paid' ? 'delivery_recorded' : 'ops_approved',
      finance_review_required: false,
      related_product_serial: 'SERI-DOM',
      charged_amount: amount,
      charged_amount_label: money(amount),
      currency: 'TRY',
      shipment_required: shipmentRequired,
      future_carrier_state: shipmentRequired ? 'waiting_future_integration' : 'not_required',
      future_carrier_label: shipmentRequired ? 'Kargo hazırlığı bekliyor; HepsiJet entegrasyonu çalıştırılmayacak' : 'Sevkiyat yok',
      description2_preview: lines.join('\n'),
      description2_version: 1,
      context_hash: (purpose === 'mount_collection' ? 'a' : supplier === 'technician' ? 'c' : 'b').repeat(64),
      revision: 1,
      mikro_write_execution_count: 0,
      carrier_execution_count: 0,
      payment_retry: amount === 601 ? {
        state: 'fresh_link_required',
        fresh_link_required: true,
        reason_required: false,
        action_label: 'Yeni bağlantı oluştur',
        message: 'Fatura, sevk, parça veya tutar değişti. Eski ödeme bağlantısı sonlandırılıp bu işlem için yeni bağlantı oluşturulacaktır.',
        authoritative_counts: { paid: 0, pending: 1, cancelled: 0, failed: 0, expired: 0 },
      } : {
        state: 'none',
        fresh_link_required: false,
        reason_required: false,
        action_label: null,
        message: null,
        authoritative_counts: { paid: 0, pending: 0, cancelled: 0, failed: 0, expired: 0 },
      },
    },
    external_execution: { mikro_read: purpose === 'part_charge' && supplier === 'emaks_prime' ? 1 : 0, mikro_write: 0, hepsijet: 0 },
  }
}

const openPaymentModal = async (page) => {
  const button = page.locator('button').filter({ hasText: /^(Ödeme Al|Yeni ek ödeme al)$/ }).last()
  await button.click()
  const dialog = page.getByRole('dialog', { name: /^(Ödeme Al|Yeni ek ödeme al)$/ })
  await dialog.waitFor({ state: 'visible' })

  return dialog
}

const waitForPreview = async (dialog, expectedText) => {
  const preview = dialog.getByTestId('payment-order-context-preview')

  try {
    await preview.waitFor({ state: 'visible' })
    await preview.locator('pre').waitFor({ state: 'visible', timeout: 5000 })
    await preview.locator('pre').filter({ hasText: expectedText }).waitFor({ state: 'visible', timeout: 5000 })
  } catch (error) {
    const controls = await dialog.locator('input, select, textarea').evaluateAll((elements) => elements.map((element) => ({
      label: element.getAttribute('aria-label') ?? element.closest('label')?.childNodes[0]?.textContent?.trim() ?? element.getAttribute('name'),
      type: element.getAttribute('type') ?? element.tagName.toLowerCase(),
      value: 'value' in element ? element.value : null,
      checked: 'checked' in element ? element.checked : null,
    })))

    throw new Error(`Preview did not contain "${expectedText}". Current state: ${await preview.innerText()}. Controls: ${JSON.stringify(controls)}`, { cause: error })
  }

  return preview
}

const inspectViewport = async (browser, name, viewport) => {
  const counters = { partSearch: 0, preview: 0, realExternal: 0 }
  const page = await browser.newPage({ viewport })
  page.on('pageerror', (error) => browserErrors.push(`${name}:page:${error.message}`))
  page.on('response', (response) => {
    if (response.status() >= 400 && !response.url().endsWith('/favicon.ico')) {
      browserErrors.push(`${name}:http:${response.status()}:${response.url()}`)
    }
  })
  page.on('request', (request) => {
    if (/mikro|hepsijet|n8n/i.test(request.url()) && !request.url().includes('/payments/order-context/')) {
      counters.realExternal += 1
    }
  })
  await page.route('**/api/technical-service/requests/*/payments/order-context/parts?*', async (route) => {
    counters.partSearch += 1
    const query = new URL(route.request().url()).searchParams.get('query')?.toLocaleLowerCase('tr-TR') ?? ''
    const items = stockItems.filter((item) => `${item.item_code} ${item.item_name}`.toLocaleLowerCase('tr-TR').includes(query))
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, source: 'mikro', source_label: 'Mikro API', freshness_at: '2026-08-14T06:00:00+03:00', items, write_execution_count: 0 }) })
  })
  await page.route('**/api/technical-service/requests/*/payments/order-context/preview', async (route) => {
    counters.preview += 1
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(previewResponse(route.request().postDataJSON())) })
  })

  await page.goto(`${baseUrl}/tests/Browser/assignment-earning-canonical.html`, { waitUntil: 'networkidle' })
  await page.waitForFunction(() => window.__assignmentEarningDomReady === true)

  let dialog = await openPaymentModal(page)
  const purpose = dialog.getByLabel('Tahsilat amacı')
  const purposeLabels = await purpose.locator('option').allTextContents()
  assert(purposeLabels.includes('Montaj ücreti tahsilatı'), `${name}: mount collection purpose is missing`)
  assert(purposeLabels.includes('Parça ödemesi'), `${name}: part charge purpose is missing`)

  await purpose.selectOption('mount_collection')
  const billingContext = dialog.getByTestId('payment-order-billing-context')
  await billingContext.locator('select').first().selectOption('manual_billing_draft')
  assert(await billingContext.getByLabel('Ad', { exact: true }).count() === 1, `${name}: individual first name field is missing`)
  assert(await billingContext.getByLabel('Soyad', { exact: true }).count() === 1, `${name}: individual surname field is missing`)
  await billingContext.getByLabel('Ad', { exact: true }).fill('Ahmet')
  await billingContext.getByLabel('Soyad', { exact: true }).fill('Aslan')
  await billingContext.locator('#order-billing-phone').fill('Aslan')
  await billingContext.getByLabel('Adres', { exact: true }).fill('Fatura Caddesi No:1')
  await billingContext.locator('#order-billing-city').selectOption('İstanbul')
  await billingContext.locator('#order-billing-district').selectOption('Esenyurt')
  await dialog.getByLabel('2. Montaj tahsilat tutarı').fill('1500')
  let preview = await waitForPreview(dialog, 'FATURA MÜŞTERİSİ: Ahmet Aslan')
  await dialog.getByRole('button', { name: 'Link oluştur', exact: true }).click()
  assert(await billingContext.getByText('Geçerli bir telefon numarası girin.', { exact: true }).count() === 1, `${name}: alphabetic billing phone has no inline error`)
  assert(await billingContext.locator('#order-billing-phone').evaluate((element) => element === document.activeElement), `${name}: invalid billing phone did not receive focus`)
  assert(await output(page, 'payment-order-create-count') === '0', `${name}: invalid billing created a payment context`)
  await billingContext.locator('#order-billing-phone').fill('905551112233')
  preview = await waitForPreview(dialog, 'FATURA MÜŞTERİSİ: Ahmet Aslan')
  const mountPreviewText = await text(preview)
  assert(mountPreviewText.includes('Hedef seri: S'), `${name}: mount preview omits target series S`)
  assert(mountPreviewText.includes('KDV: Mikro hizmet kartından'), `${name}: mount preview omits Mikro service tax authority`)
  assert(mountPreviewText.includes('Sevkiyat: Yok'), `${name}: mount preview does not suppress shipment`)
  assert(!/S[-/]?\d{2,}/.test(mountPreviewText), `${name}: mount preview fabricates an S order number`)
  await dialog.getByRole('button', { name: 'Link oluştur', exact: true }).dblclick()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentCreateCount === 1)
  assert(await output(page, 'payment-order-create-count') === '1', `${name}: mount double-click emitted duplicate command`)
  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()

  dialog = await openPaymentModal(page)
  await dialog.getByLabel('Tahsilat amacı').selectOption('part_charge')
  await dialog.getByRole('button', { name: 'EMAKS Prime', exact: true }).click()
  const identitySearch = dialog.getByLabel('Mikro stok parçası ara')
  const previewCountBeforeIdentitySelection = counters.preview
  await identitySearch.fill('IDENTITY')
  const identityOnly = dialog.getByTestId('mikro-part-search-results').getByRole('button', { name: /EMK-IDENTITY-003/ })
  await identityOnly.waitFor({ state: 'visible' })
  const identityText = await text(identityOnly)
  assert(identityText.includes('Mikro API'), `${name}: identity-only result omits Mikro source`)
  assert(identityText.includes('Güncellik:'), `${name}: identity-only result omits freshness`)
  assert(identityText.includes('Stok miktarı henüz doğrulanmadı.'), `${name}: identity-only result omits availability blocker`)
  assert(!identityText.includes('Depo:'), `${name}: identity-only result invents warehouse`)
  assert(!identityText.includes('Eldeki:'), `${name}: identity-only result invents on-hand quantity`)
  assert(!identityText.includes('Rezerve:'), `${name}: identity-only result invents reserved quantity`)
  assert(!identityText.includes('Kullanılabilir:'), `${name}: identity-only result invents available quantity`)
  assert(!identityText.includes('Seri takibi:'), `${name}: identity-only result invents serial state`)
  await identityOnly.click()
  assert(await dialog.getByText('Stok uygunluğu doğrulanmadan ödeme ve sipariş hazırlığı başlatılamaz.', { exact: true }).count() === 1, `${name}: identity-only selection is not blocked`)
  await page.waitForTimeout(450)
  assert(counters.preview === previewCountBeforeIdentitySelection, `${name}: identity-only selection triggered payment/order preview`)
  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()

  const chooseEmaksPart = async () => {
    dialog = await openPaymentModal(page)
    await dialog.getByLabel('Tahsilat amacı').selectOption('part_charge')
    await dialog.getByRole('button', { name: 'EMAKS Prime', exact: true }).click()
    const search = dialog.getByLabel('Mikro stok parçası ara')
    await search.fill('EMK-GW')
    const gateway = dialog.getByTestId('mikro-part-search-results').getByRole('button', { name: /EMK-GW-001/ })
    await gateway.waitFor({ state: 'visible' })
    const gatewayText = await text(gateway)
    assert(gatewayText.includes('Mikro API'), `${name}: real-stock result omits Mikro source`)
    assert(gatewayText.includes('Depo: MERKEZ'), `${name}: real-stock result omits warehouse`)
    assert(gatewayText.includes('Kullanılabilir: 21'), `${name}: real-stock result omits available quantity`)
    await gateway.click()
    assert(await dialog.getByTestId('selected-payment-part').count() === 1, `${name}: stock selection is not visible`)

    return search
  }

  let partSearch = await chooseEmaksPart()
  await partSearch.fill('MOTOR')
  assert(await dialog.getByTestId('selected-payment-part').count() === 0, `${name}: changed query retained stale stock selection`)
  await dialog.getByTestId('mikro-part-search-results').getByRole('button', { name: /EMK-MOTOR-002/ }).waitFor({ state: 'visible' })
  await partSearch.fill('EMK-GW')
  await dialog.getByTestId('mikro-part-search-results').getByRole('button', { name: /EMK-GW-001/ }).click()

  let commercial = dialog.getByRole('group', { name: 'Parça ticari durumu' })
  let delivery = dialog.getByRole('group', { name: 'Parça teslim şekli' })
  await commercial.getByRole('button', { name: 'Ücretsiz', exact: true }).click()
  await delivery.getByRole('button', { name: 'Elden', exact: true }).click()
  await dialog.getByLabel('Sipariş satırı referans değeri').fill('750')
  preview = await waitForPreview(dialog, 'SİPARİŞ SATIR DEĞERİ: 750,00 TL')
  let previewText = await text(preview)
  assert(previewText.includes('Hedef seri: Q'), `${name}: free hand preview omits Q`)
  assert(previewText.includes('KDV: Yok / %0'), `${name}: free hand preview is not zero VAT`)
  assert(previewText.includes('Ödeme bağlantısı: Yok'), `${name}: free hand preview exposes a payment link`)
  assert(previewText.includes('Tahsilat tutarı: 0,00 TL'), `${name}: free-hand reference price leaked into collection`)
  await dialog.getByRole('button', { name: 'Parça bağlamını kaydet', exact: true }).dblclick()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentCreateCount === 2)
  assert(await output(page, 'payment-order-create-count') === '2', `${name}: free-hand double-click emitted duplicate command`)
  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()
  assert(await page.getByTestId('part-order-context-summary').count() === 1, `${name}: compact Part summary is missing or duplicated`)
  assert((await text(page.getByTestId('part-order-context-summary'))).includes('Tahsilat gerekmiyor'), `${name}: free Part summary is not truthful`)

  await chooseEmaksPart()
  commercial = dialog.getByRole('group', { name: 'Parça ticari durumu' })
  delivery = dialog.getByRole('group', { name: 'Parça teslim şekli' })
  await commercial.getByRole('button', { name: 'Ücretsiz', exact: true }).click()
  await delivery.getByRole('button', { name: 'Sevk', exact: true }).click()
  preview = await waitForPreview(dialog, 'SİPARİŞ TUTARI: 0,00 TL')
  previewText = await text(preview)
  assert(previewText.includes('Hedef seri: Q'), `${name}: free shipment preview omits Q`)
  assert(previewText.includes('Tahsilat tutarı: 0,00 TL'), `${name}: free shipment has a collection`)
  assert(previewText.includes('Ödeme bağlantısı: Yok'), `${name}: free shipment exposes payment link`)
  assert(previewText.includes('Sevkiyat: Hazırlık bekliyor'), `${name}: free shipment loses recipient/shipment context`)
  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()

  await chooseEmaksPart()
  commercial = dialog.getByRole('group', { name: 'Parça ticari durumu' })
  delivery = dialog.getByRole('group', { name: 'Parça teslim şekli' })
  await commercial.getByRole('button', { name: 'Ücretli', exact: true }).click()
  await delivery.getByRole('button', { name: 'Elden', exact: true }).click()
  await dialog.getByLabel('Elden teslim alıcısı').selectOption('technician')
  await dialog.getByLabel('Tahsilat tutarı').fill('600')
  preview = await waitForPreview(dialog, 'TESLİM: ELDEN')
  previewText = await text(preview)
  assert(previewText.includes('Hedef seri: Q'), `${name}: paid hand preview omits Q`)
  assert(previewText.includes('KDV: Yok / %0'), `${name}: paid hand preview is not zero VAT`)
  assert(previewText.includes('Ödeme bağlantısı: Yok'), `${name}: paid hand preview exposes a payment link`)
  assert(previewText.includes('Tahsilat: Ödeme bekleniyor'), `${name}: paid hand preview does not start pending`)
  await dialog.getByRole('button', { name: 'Parça bağlamını kaydet', exact: true }).dblclick()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentCreateCount === 3)
  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()
  const partSummary = page.getByTestId('part-order-context-summary')
  await partSummary.getByRole('button', { name: 'Elden teslim edildi' }).click()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentOrderStateUpdateCount === 1)
  assert((await text(partSummary)).includes('Ödeme alındı'), `${name}: technician delivery did not mark payment paid`)
  assert((await text(partSummary)).includes('Teslim kaydı'), `${name}: auto-paid source is not visible`)
  await partSummary.locator('details').getByText('Ödeme durumunu düzelt', { exact: true }).click()
  await partSummary.locator('#part-hand-payment-status').selectOption('pending')
  await partSummary.getByPlaceholder('Değişiklik nedeni').fill('Tahsilat teyidi bekleniyor')
  await partSummary.getByRole('button', { name: 'Kaydet', exact: true }).click()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentOrderStateUpdateCount === 2)
  assert((await text(partSummary)).includes('Ödeme bekleniyor'), `${name}: OPS override did not restore pending state`)

  await chooseEmaksPart()
  commercial = dialog.getByRole('group', { name: 'Parça ticari durumu' })
  delivery = dialog.getByRole('group', { name: 'Parça teslim şekli' })
  await commercial.getByRole('button', { name: 'Ücretli', exact: true }).click()
  await delivery.getByRole('button', { name: 'Sevk', exact: true }).click()
  await dialog.getByLabel('6. Sevk ve fatura bilgileri aynıdır').uncheck()
  await dialog.getByLabel('7. Sevk alıcısı / adresi').selectOption('technician')
  await dialog.getByLabel('Tahsilat tutarı').fill('600')
  preview = await waitForPreview(dialog, 'HEDEF SERİ: S')
  previewText = await text(preview)
  assert(previewText.includes('KDV: Mikro stok kartından'), `${name}: paid shipment omits Mikro VAT authority`)
  assert(previewText.includes('Ödeme bağlantısı: Gerekli'), `${name}: paid shipment does not require payment link`)
  assert(previewText.includes('ALICI: Test Usta'), `${name}: paid shipment loses technician recipient`)
  await dialog.getByRole('button', { name: 'Link oluştur', exact: true }).dblclick()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentCreateCount === 4)
  assert(await output(page, 'payment-order-create-count') === '4', `${name}: paid-shipment double-click emitted duplicate command`)
  const submittedPayload = JSON.parse(await output(page, 'payment-order-last-payload'))
  assert(submittedPayload.order_context.commercial_mode === 'paid', `${name}: paid commercial mode is missing from submit payload`)
  assert(submittedPayload.order_context.delivery_mode === 'shipment', `${name}: shipment mode is missing from submit payload`)
  await dialog.getByLabel('Tahsilat tutarı').fill('601')
  await waitForPreview(dialog, 'TUTAR: 601,00 TL')
  assert(await dialog.getByText('Eski ödeme bağlantısı sonlandırılıp bu işlem için yeni bağlantı oluşturulacaktır.', { exact: false }).count() === 1, `${name}: changed context has no explicit fresh-link explanation`)
  await dialog.getByRole('button', { name: 'Yeni bağlantı oluştur', exact: true }).dblclick()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentCreateCount === 5)
  const retryPayload = JSON.parse(await output(page, 'payment-order-last-payload'))
  assert(retryPayload.fresh_payment_requested === true, `${name}: explicit fresh-link action was not submitted`)
  assert(retryPayload.terminal_retry_reason === null, `${name}: changed context fabricated a terminal retry reason`)
  assert(await output(page, 'payment-order-create-count') === '5', `${name}: fresh-link double-click emitted duplicate command`)
  assert(await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth + 1), `${name}: payment wizard overflows horizontally`)
  assert(await output(page, 'financial-board-refetch-count') === '0', `${name}: payment flow refetched the board`)
  assert(await output(page, 'financial-modal-mount-count') === '1', `${name}: payment flow remounted the detail modal`)
  assert(await output(page, 'assignment-scroll-reset-count') === '0', `${name}: payment flow reset scroll`)
  assert(counters.realExternal === 0, `${name}: real Mikro/HepsiJet/n8n request count is ${counters.realExternal}`)
  assert(!/S[-/]?\d{2,}/.test(await text(dialog)), `${name}: UI falsely displays an S order number`)
  assert(!(await text(dialog)).includes('Sipariş oluşturuldu'), `${name}: UI falsely displays Sipariş oluşturuldu`)

  await page.screenshot({ path: path.join(artifactDir, `payment-order-context-${name}.png`), fullPage: true })
  await page.close()

  return counters
}

const browser = await chromium.launch({
  headless: true,
  executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe',
})

const results = {
  desktop: await inspectViewport(browser, 'desktop', { width: 1440, height: 1000 }),
  mobile: await inspectViewport(browser, 'mobile', { width: 390, height: 844 }),
}

await browser.close()
assert(browserErrors.length === 0, `browser errors: ${browserErrors.join(' | ')}`)

console.log(JSON.stringify({
  result: failures.length === 0 ? 'passed' : 'failed',
  results,
  failures,
  browserErrors,
}, null, 2))

if (failures.length > 0) {
  process.exitCode = 1
}
