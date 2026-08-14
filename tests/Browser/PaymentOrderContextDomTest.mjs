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
    item_code: 'TS-PART-001',
    item_name: 'Gateway',
    unit_code: 'ADET',
    warehouse_code: 'MERKEZ',
    on_hand: 24,
    reserved: 3,
    available: 21,
    serial_tracking_required: false,
    serials: [],
    source: 'test_fixture',
    source_label: 'Test verisi',
    freshness_at: '2026-08-14T06:00:00+03:00',
    selection_token: 'gateway-token',
  },
  {
    item_code: 'TS-PART-002',
    item_name: 'Akıllı Kilit Motor Modülü',
    unit_code: 'ADET',
    warehouse_code: 'MERKEZ',
    on_hand: 6,
    reserved: 1,
    available: 5,
    serial_tracking_required: true,
    serials: ['TSP-2026-0001', 'TSP-2026-0002'],
    source: 'test_fixture',
    source_label: 'Test verisi',
    freshness_at: '2026-08-14T06:00:00+03:00',
    selection_token: 'motor-token',
  },
]

const previewResponse = (payload) => {
  const input = payload.order_context ?? {}
  const purpose = payload.purpose
  const supplier = input.part_supplier ?? null
  const selectedPart = stockItems.find((item) => item.selection_token === input.stock_selection_token) ?? null
  const billing = input.billing_source === 'manual_billing_draft'
    ? input.billing
    : {
        source: 'mrn_customer',
        name_or_title: 'Test Müşteri',
        phone: '9053****633',
        address: 'Test adresi',
        city: 'İstanbul',
        district: 'Kadıköy',
      }
  const shipmentRequired = purpose === 'part_charge' && supplier === 'emaks_prime'
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
  const lines = purpose === 'mount_collection'
    ? [
        'MRN/SRV: MRN-DOM-EARNING',
        'HİZMET: MONTAJ',
        'ÜRÜN: Test Model',
        'SERİ NO: SERI-DOM',
        `FATURA MÜŞTERİSİ: ${billing.name_or_title}`,
        'SEVKİYAT: YOK',
      ]
    : [
        ...(shipmentRequired && !sameAsBilling ? ['SEVK ADRESİ FARKLIDIR.'] : []),
        'MRN/SRV: MRN-DOM-EARNING',
        'İLGİLİ ÜRÜN SERİ NO: SERI-DOM',
        `PARÇA: ${part?.item_code ? `${part.item_code} - ` : ''}${part?.item_name ?? ''}`,
        `ADET: ${input.quantity}`,
        ...(shipmentRequired
          ? [
              ...(sameAsBilling ? ['SEVK/FATURA: AYNI'] : [`TESLİM TİPİ: ${String(input.delivery_target ?? '').toLocaleUpperCase('tr-TR')}`]),
              `ALICI: ${shipping?.recipient_name ?? ''}`,
              `TELEFON: ${shipping?.recipient_phone ?? ''}`,
              `ADRES: ${shipping?.address ?? ''} / ${shipping?.district ?? ''} / ${shipping?.city ?? ''}`,
              `FATURA MÜŞTERİSİ: ${billing.name_or_title}`,
            ]
          : ['TEDARİK: USTA', 'SEVKİYAT: YOK', `FATURA MÜŞTERİSİ: ${billing.name_or_title}`]),
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
      desired_mikro_series: supplier === 'technician' ? null : 'S',
      future_mikro_write_state: supplier === 'technician' ? 'not_required' : 'not_authorized',
      future_mikro_write_label: supplier === 'technician' ? 'Mikro siparişi gerekmiyor' : 'Mikro yazımı bu aşamada kapalı',
      billing,
      shipping_same_as_billing: sameAsBilling,
      delivery_target: shipmentRequired ? (sameAsBilling ? 'billing_address' : input.delivery_target) : null,
      delivery_target_label: shipmentRequired ? (sameAsBilling ? 'Fatura adresi' : input.delivery_target) : null,
      shipping,
      part_supplier: supplier,
      part_supplier_label: supplier === 'technician' ? 'Usta' : supplier === 'emaks_prime' ? 'EMAKS Prime' : null,
      collection_allocation: supplier === 'technician' ? 'pay_technician' : supplier === 'emaks_prime' ? 'retain_company' : null,
      collection_allocation_label: supplier === 'technician' ? 'Ustaya hakediş olarak eklenecek' : supplier === 'emaks_prime' ? 'Şirkette bırakılacak' : null,
      part,
      related_product_serial: 'SERI-DOM',
      charged_amount: Number(payload.amount),
      charged_amount_label: `${Number(payload.amount).toLocaleString('tr-TR')},00 TL`,
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
    },
    external_execution: { mikro_read: 0, mikro_write: 0, hepsijet: 0 },
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
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ ok: true, source: 'test_fixture', source_label: 'Test verisi', freshness_at: '2026-08-14T06:00:00+03:00', items, write_execution_count: 0 }) })
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
  assert(await dialog.getByTestId('payment-order-part-context').count() === 0, `${name}: mount purpose renders part controls`)
  assert(await dialog.getByTestId('payment-order-shipping-context').count() === 0, `${name}: mount purpose renders shipping controls`)
  await dialog.getByLabel('2. Montaj tahsilat tutarı').fill('1500')
  const mountPreview = await waitForPreview(dialog, 'HİZMET: MONTAJ')
  const mountPreviewText = await text(mountPreview)
  assert(mountPreviewText.includes('Hedef seri: S'), `${name}: mount preview omits target series S`)
  assert(mountPreviewText.includes('Sevkiyat: Yok'), `${name}: mount preview does not suppress shipment`)
  assert(mountPreviewText.includes('Mikro yazımı bu aşamada kapalı'), `${name}: mount preview does not disclose closed Mikro write`)
  assert(!mountPreviewText.includes('Sipariş oluşturuldu'), `${name}: mount preview falsely claims order creation`)
  assert(!/S[-/]?\d{2,}/.test(mountPreviewText), `${name}: mount preview fabricates an S order number`)
  await dialog.getByRole('button', { name: 'Link oluştur', exact: true }).dblclick()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentCreateCount === 1)
  const paidMountSummary = dialog.getByTestId('payment-order-context-summary')
  await paidMountSummary.waitFor({ state: 'visible' })
  const paidMountText = await text(paidMountSummary)
  assert(paidMountText.includes('Ödeme alındı; Mikro yazımı bekliyor'), `${name}: paid fake mount does not render its local ready state`)
  assert(paidMountText.includes('Sevkiyat: Yok'), `${name}: paid fake mount falsely prepares shipment`)
  assert(await output(page, 'payment-order-create-count') === '1', `${name}: mount double-click emitted duplicate command`)
  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()

  dialog = await openPaymentModal(page)
  await dialog.getByLabel('Tahsilat amacı').selectOption('part_charge')
  assert(await dialog.getByTestId('payment-order-part-context').count() === 1, `${name}: part purpose does not open the bounded context`)
  await dialog.getByRole('button', { name: 'EMAKS Prime', exact: true }).click()
  const partSearch = dialog.getByLabel('Mikro stok parçası ara')
  await partSearch.fill('Gateway')
  const gateway = dialog.getByTestId('mikro-part-search-results').getByRole('button', { name: /TS-PART-001/ })
  await gateway.waitFor({ state: 'visible' })
  assert((await text(gateway)).includes('Test verisi'), `${name}: local fixture is not marked Test verisi`)
  await gateway.click()
  assert(await dialog.getByText('4. Parça seri numarası', { exact: true }).count() === 0, `${name}: non-serial item renders a serial field`)
  assert((await text(dialog.getByTestId('payment-order-part-context'))).includes('Servis verilen ürün seri numarası: SERI-DOM'), `${name}: related product serial is not separately visible`)
  await dialog.getByLabel('8. Tahsilat tutarı').fill('2000')
  let partPreview = await waitForPreview(dialog, 'SEVK/FATURA: AYNI')
  assert((await text(partPreview)).includes('İLGİLİ ÜRÜN SERİ NO: SERI-DOM'), `${name}: same-address preview omits related product serial`)

  const sameAsBilling = dialog.getByLabel('6. Sevk ve fatura bilgileri aynıdır')
  await sameAsBilling.uncheck()
  const deliveryTarget = dialog.getByLabel('7. Sevk alıcısı / adresi')
  await deliveryTarget.selectOption('technician')
  partPreview = await waitForPreview(dialog, 'SEVK ADRESİ FARKLIDIR.')
  const technicianPreviewText = await text(partPreview)
  assert(technicianPreviewText.includes('ALICI: Test Usta'), `${name}: technician destination does not use active technician`)
  assert(technicianPreviewText.includes('Pamukkale Usta Adresi No:1'), `${name}: technician destination does not use canonical technician address`)
  await deliveryTarget.selectOption('mrn_customer')
  assert((await text(dialog.getByTestId('payment-order-shipping-context'))).includes('Test Müşteri · 9053****633'), `${name}: MRN customer destination does not use request details`)
  await deliveryTarget.selectOption('custom_recipient')
  await dialog.getByLabel('Alıcı adı', { exact: true }).fill('Farklı Alıcı')
  await dialog.getByLabel('Telefon', { exact: true }).fill('905551112233')
  await dialog.getByLabel('Adres', { exact: true }).fill('Farklı Adres No:8')
  await dialog.getByLabel('İl', { exact: true }).fill('İzmir')
  assert(await dialog.getByLabel('Alıcı adı', { exact: true }).inputValue() === 'Farklı Alıcı', `${name}: incomplete custom recipient loses entered name`)
  assert(await dialog.getByLabel('Adres', { exact: true }).inputValue() === 'Farklı Adres No:8', `${name}: incomplete custom recipient loses entered address`)
  await dialog.getByLabel('İlçe', { exact: true }).fill('Konak')
  partPreview = await waitForPreview(dialog, 'ALICI: Farklı Alıcı')
  assert((await text(partPreview)).includes('SEVK ADRESİ FARKLIDIR.'), `${name}: custom address preview omits different-address warning`)

  await partSearch.fill('Motor')
  const motor = dialog.getByTestId('mikro-part-search-results').getByRole('button', { name: /TS-PART-002/ })
  await motor.waitFor({ state: 'visible' })
  await motor.click()
  const partSerial = dialog.getByLabel('4. Parça seri numarası')
  assert(await partSerial.count() === 1, `${name}: serial-tracked item does not render a serial selector`)
  await partSerial.selectOption('TSP-2026-0001')
  partPreview = await waitForPreview(dialog, 'Akıllı Kilit Motor Modülü')
  assert((await text(partPreview)).includes('İLGİLİ ÜRÜN SERİ NO: SERI-DOM'), `${name}: part serial selection replaced the related product serial`)
  await dialog.getByRole('button', { name: 'Link oluştur', exact: true }).dblclick()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentCreateCount === 2)
  const paidPartSummary = dialog.getByTestId('payment-order-context-summary')
  await paidPartSummary.waitFor({ state: 'visible' })
  const paidPartText = await text(paidPartSummary)
  assert(paidPartText.includes('Ödeme alındı; Mikro yazımı bekliyor'), `${name}: paid fake EMAKS part does not render local order preparation`)
  assert(paidPartText.includes('Kargo hazırlığı bekliyor'), `${name}: paid fake EMAKS part omits future shipment preparation`)
  assert(paidPartText.includes('HepsiJet entegrasyonu çalıştırılmayacak'), `${name}: paid fake part does not keep HepsiJet closed`)
  await dialog.getByRole('button', { name: 'İptal', exact: true }).last().click()

  dialog = await openPaymentModal(page)
  await dialog.getByLabel('Tahsilat amacı').selectOption('part_charge')
  await dialog.getByRole('button', { name: 'Usta', exact: true }).click()
  await dialog.getByLabel('2. Parça adı').fill('Usta kilit gövdesi')
  await dialog.getByLabel('8. Tahsilat tutarı').fill('850')
  const technicianPartPreview = await waitForPreview(dialog, 'TEDARİK: USTA')
  const technicianPartText = await text(technicianPartPreview)
  assert(await dialog.getByLabel('Mikro stok parçası ara').count() === 0, `${name}: technician-supplied part renders Mikro search`)
  assert(await dialog.getByTestId('payment-order-shipping-context').count() === 0, `${name}: technician-supplied part renders shipment controls`)
  assert(!technicianPartText.includes('Hedef seri: S'), `${name}: technician-supplied part prepares a Mikro S order`)
  assert(technicianPartText.includes('Mikro siparişi gerekmiyor'), `${name}: technician-supplied part does not explain its no-Mikro state`)
  await dialog.getByRole('button', { name: 'Link oluştur', exact: true }).dblclick()
  await page.waitForFunction(() => window.__assignmentEarningDomState?.paymentCreateCount === 3)
  assert(await output(page, 'payment-order-create-count') === '3', `${name}: duplicate payment command was emitted`)
  const submittedPayload = JSON.parse(await output(page, 'payment-order-last-payload'))
  assert(submittedPayload.order_context.part_supplier === 'technician', `${name}: technician supplier decision is missing from submit payload`)
  assert(submittedPayload.order_context.expected_context_hash === 'c'.repeat(64), `${name}: submit payload omits canonical context hash`)
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
