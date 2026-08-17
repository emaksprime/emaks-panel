import path from 'node:path'
import process from 'node:process'
import { chromium } from 'file:///C:/Users/Emaks/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs'

const baseUrl = process.env.IYZICO_SIMULATION_DOM_BASE_URL ?? 'http://127.0.0.1:5175/tests/Browser/assignment-earning-canonical.html'
const artifactDir = process.env.IYZICO_SIMULATION_DOM_ARTIFACT_DIR ?? process.cwd()
const failures = []
const browserErrors = []

const assert = (condition, message) => {
  if (!condition) {
    failures.push(message)
  }
}

const pendingContextResponse = {
  ok: true,
  order_context: {
    id: 9806,
    payment_id: null,
    request_id: 9001,
    root_request_id: 9001,
    srv_request_id: null,
    payment_purpose: 'part_charge',
    purpose_label: 'Parça ödemesi',
    context_type: 'part_sale',
    state: 'draft',
    state_label: 'Ödeme bağlantısı hazırlanabilir',
    desired_mikro_series: 'S',
    tax_mode: 'standard_from_mikro',
    tax_label: 'Satır bazında · toplam fiyata dahil',
    tax_status: 'verified',
    tax_source: 'mikro_api',
    tax_source_label: 'Mikro API',
    future_mikro_write_state: 'not_authorized',
    future_mikro_write_label: 'Mikro yazımı bu aşamada kapalı',
    billing: { source: 'mrn_customer', billing_type: 'individual', name_or_title: 'Test Müşteri', phone: '9053****633', address: 'Test adresi', city: 'İstanbul', district: 'Kadıköy' },
    shipping_same_as_billing: true,
    delivery_target: 'billing_address',
    delivery_target_label: 'Fatura adresi',
    shipping: { recipient_name: 'Test Müşteri', recipient_phone: '9053****633', address: 'Test adresi', city: 'İstanbul', district: 'Kadıköy' },
    part_supplier: 'emaks_prime',
    part_supplier_label: 'EMAKS Prime',
    collection_allocation: 'retain_company',
    collection_allocation_label: 'Şirkette bırakılacak',
    commercial_mode: 'paid',
    commercial_mode_label: 'Ücretli',
    delivery_mode: 'shipment',
    delivery_mode_label: 'Sevk',
    shipment_required: true,
    payment_collection_mode: 'payment_link',
    payment_status: 'pending',
    payment_status_label: 'Ödeme bekleniyor',
    payment_status_source: 'system',
    payment_status_source_label: 'Sistem',
    payment_link_required: true,
    collection_required: true,
    order_line_unit_price: 0,
    order_line_unit_price_label: '0,00 TL',
    order_line_total: 2000,
    order_line_total_label: '2.000,00 TL',
    order_reference_total: 2000,
    order_reference_total_label: '2.000,00 TL',
    gross_total: 2000,
    gross_total_label: '2.000,00 TL',
    net_total: 1742.42,
    net_total_label: '1.742,42 TL',
    vat_total: 257.58,
    vat_total_label: '257,58 TL',
    collection_amount: 2000,
    collection_amount_label: '2.000,00 TL',
    future_order_trigger: 'payment_paid',
    related_product_serial: 'SERI-DOM',
    currency: 'TRY',
    lines: [
      {
        line_key: 'EE.BCK.STD.0010', selection_token: 'stand-one-token', item_code: 'EE.BCK.STD.0010', item_name: 'PHILIPS SUNUM STANDI',
        item_kind: 'accessory', classification_source: 'mikro_stock_type', classification_contract_version: 'technical-service-part-classification-v2',
        quantity: 1, unit_code: 'ADET', unit_price: 1000, unit_price_label: '1.000,00 TL', line_total: 1000, line_total_label: '1.000,00 TL',
        gross_line_total: 1000, gross_line_total_label: '1.000,00 TL', selected_tax_rate: 20, selected_tax_rate_label: '%20',
        stock_source: 'mikro', stock_source_label: 'Mikro API', stock_freshness_at: '2026-08-16T19:20:00+03:00',
        physical_stock_verified: true, physical_stock_state: 'positive', physical_stock_total: 83, physical_stock_total_label: '83',
        physical_stock_contract_version: 'technical-service-part-physical-stock-v1', stock_status_label: 'Stokta: 83 ADET', serial_tracking_state: 'not_required', availability_verified: true,
      },
      {
        line_key: 'EP.YDP.002.015', selection_token: 'part-two-token', item_code: 'EP.YDP.002.015', item_name: 'YEDEK PARÇA',
        item_kind: 'part', classification_source: 'mikro_stock_type', classification_contract_version: 'technical-service-part-classification-v2',
        quantity: 1, unit_code: 'ADET', unit_price: 1000, unit_price_label: '1.000,00 TL', line_total: 1000, line_total_label: '1.000,00 TL',
        gross_line_total: 1000, gross_line_total_label: '1.000,00 TL', selected_tax_rate: 10, selected_tax_rate_label: '%10',
        stock_source: 'mikro', stock_source_label: 'Mikro API', stock_freshness_at: '2026-08-16T19:20:00+03:00',
        physical_stock_verified: true, physical_stock_state: 'positive', physical_stock_total: 12, physical_stock_total_label: '12',
        physical_stock_contract_version: 'technical-service-part-physical-stock-v1', stock_status_label: 'Stokta: 12 ADET', serial_tracking_state: 'not_required', availability_verified: true,
      },
    ],
    line_count: 2,
    total_quantity: 2,
    total_quantity_label: '2',
    context_hash: 'e'.repeat(64),
    revision: 6,
    description2_preview: 'MÜŞTERİDEN TAHSİL EDİLECEK: 2.000,00 TL\nKDV TOPLAMA DAHİLDİR.\nHEDEF SERİ: S',
    readiness: { ready: true, order_ready: true, payment_ready: true, blocker_codes: [], blockers: [] },
    payment_retry: { state: 'none', fresh_link_required: false, reason_required: false, action_label: null, message: null, authoritative_counts: { paid: 0, pending: 0, cancelled: 0, failed: 0, expired: 0 } },
    mikro_write_execution_count: 0,
    carrier_execution_count: 0,
  },
  external_execution: { mikro_read: 0, mikro_write: 0, hepsijet: 0 },
}

const inspectViewport = async (browser, name, viewport) => {
  const page = await browser.newPage({ viewport })
  page.on('console', (message) => {
    if (message.type() === 'error') {
      const location = message.location()
      browserErrors.push(`${name}:console:${message.text()}:${location.url || 'unknown-url'}`)
    }
  })
  page.on('pageerror', (error) => browserErrors.push(`${name}:pageerror:${error.message}`))
  await page.route('**/api/technical-service/requests/*/payments/order-context/preview', async (route) => {
    await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify(pendingContextResponse) })
  })
  await page.goto(baseUrl, { waitUntil: 'networkidle' })
  await page.evaluate(() => window.sessionStorage.clear())
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByTestId('load-sandbox-paid-simulation-scenario').click()
  await page.locator('button').filter({ hasText: /^(Ödeme Al|Yeni ek ödeme al)$/ }).last().click()
  const dialog = page.getByRole('dialog', { name: /^(Ödeme Al|Yeni ek ödeme al)$/ })
  await dialog.waitFor({ state: 'visible' })

  const outcome = dialog.getByTestId('paid-payment-outcome-9906')
  await outcome.waitFor({ state: 'visible' })
  const outcomeText = (await outcome.innerText()).trim()
  assert(outcomeText.includes('Ödeme alındı'), `${name}: paid state is missing`)
  assert(outcomeText.includes('Iyzico Sandbox'), `${name}: sandbox badge is missing`)
  assert(outcomeText.includes('Sipariş S serisiyle oluşturulacaktır.'), `${name}: S-series decision is missing`)
  assert(outcomeText.includes('Mikro test sipariş simülasyonu kaydedildi'), `${name}: simulation state is missing`)
  assert(outcomeText.includes('Gerçek Mikro siparişi oluşturulmadı.'), `${name}: real-order guard is missing`)
  assert(outcomeText.includes('MSIM-01DOMSANDBOXSIMULATION1'), `${name}: simulation reference is missing`)
  assert(outcomeText.includes('Muhasebe maili gönderilemedi'), `${name}: failed receipt state is missing`)

  const details = dialog.getByTestId('payment-technical-details')
  assert(await details.count() === 1, `${name}: accounting/technical details are missing`)
  assert(await details.getAttribute('open') === null, `${name}: accounting/technical details are expanded by default`)
  assert((await details.locator('summary').innerText()).includes('Muhasebe / Teknik Detaylar'), `${name}: collapsed details label is wrong`)

  const dialogText = (await dialog.innerText()).trim()
  assert(dialogText.includes('2.000,00 TL'), `${name}: canonical gross total is missing`)
  assert(!dialogText.includes('2.000,00 TL + KDV'), `${name}: VAT was added to gross total`)
  const pageText = (await page.locator('body').innerText()).trim()
  assert(pageText.includes('EE.BCK.STD.0010'), `${name}: first local line is missing`)
  assert(pageText.includes('EP.YDP.002.015'), `${name}: second local line is missing`)
  assert(!/Sipariş No:\s*S\d+/i.test(pageText), `${name}: fake Mikro order number is visible`)

  const retry = outcome.getByRole('button', { name: 'Muhasebe mailini tekrar dene', exact: true })
  await retry.click()
  await outcome.getByText('Muhasebe maili gönderildi', { exact: true }).waitFor({ state: 'visible' })
  assert(await page.getByTestId('finance-receipt-retry-count').textContent() === '1', `${name}: receipt retry count is not one`)
  assert(await outcome.getByRole('button', { name: 'Muhasebe mailini tekrar dene', exact: true }).count() === 0, `${name}: successful receipt remains retryable`)
  assert(await page.getByTestId('financial-board-refetch-count').textContent() === '0', `${name}: board refetch count is not zero`)
  assert(await page.getByTestId('financial-modal-mount-count').textContent() === '1', `${name}: detail modal remounted`)
  assert(await page.getByTestId('assignment-scroll-reset-count').textContent() === '0', `${name}: scroll reset count is not zero`)
  assert(await page.getByTestId('request-detail-open').textContent() === 'true', `${name}: request detail closed`)
  assert(await dialog.evaluate((element) => element.scrollWidth <= element.clientWidth + 1), `${name}: payment dialog has horizontal overflow`)
  assert(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1), `${name}: page has horizontal overflow`)

  await dialog.getByRole('button', { name: 'Kapat', exact: true }).click()
  await page.getByTestId('load-sandbox-pending-create-scenario').click()
  await page.locator('button').filter({ hasText: /^(Ödeme Al|Yeni ek ödeme al)$/ }).last().click()
  const pendingDialog = page.getByRole('dialog', { name: /^(Ödeme Al|Yeni ek ödeme al)$/ })
  await pendingDialog.waitFor({ state: 'visible' })
  await pendingDialog.getByLabel('Tahsilat amacı').selectOption('part_charge')
  await pendingDialog.getByRole('button', { name: 'EMAKS Prime', exact: true }).click()
  const selectedLines = pendingDialog.getByTestId('selected-payment-part-line')
  await selectedLines.first().waitFor({ state: 'visible', timeout: 5000 })
  assert(await selectedLines.count() === 2, `${name}: canonical two-line context did not hydrate`)
  const preview = pendingDialog.getByTestId('payment-order-context-preview')
  await preview.getByText('Sipariş S serisiyle oluşturulacaktır.', { exact: true }).waitFor({ state: 'visible', timeout: 5000 })
  assert((await preview.innerText()).includes('2.000,00 TL'), `${name}: gross 2.000 TL preview is missing`)

  const submit = pendingDialog.getByRole('button', { name: 'Link oluştur', exact: true })
  await submit.waitFor({ state: 'visible' })
  assert(!(await submit.isDisabled()), `${name}: canonical link create is disabled`)
  const scrollBeforeCreate = await pendingDialog.evaluate((element) => element.scrollTop)
  await submit.dblclick()
  await pendingDialog.getByText('Sandbox ödeme bağlantısı hazırlanıyor...', { exact: true }).waitFor({ state: 'visible', timeout: 3000 })
  await pendingDialog.getByTestId('sandbox-payment-pending-status').waitFor({ state: 'visible', timeout: 5000 })
  assert(await page.getByTestId('payment-order-create-count').textContent() === '1', `${name}: duplicate click created more than one request`)
  const createPayload = JSON.parse(await page.getByTestId('payment-order-last-payload').textContent() ?? '{}')
  assert(createPayload.order_context?.expected_context_id === 9806, `${name}: expected context ID changed`)
  assert(createPayload.order_context?.expected_revision === 6, `${name}: context revision changed`)
  assert(createPayload.order_context?.expected_context_hash === 'e'.repeat(64), `${name}: context hash changed`)
  assert(await selectedLines.count() === 2, `${name}: selected lines changed after create`)
  const pendingActions = pendingDialog.getByTestId('pending-payment-actions-payment-modal')
  await pendingActions.waitFor({ state: 'visible' })
  const openAction = pendingActions.getByTestId('pending-payment-actions-payment-modal-open')
  const copyAction = pendingActions.getByTestId('pending-payment-actions-payment-modal-copy')
  assert(await openAction.getAttribute('href') === 'https://sandbox.iyzi.link/canonical-dom-pending-token', `${name}: Linki aç does not own canonical URL`)
  assert(!(await copyAction.isDisabled()), `${name}: Linki kopyala is disabled`)
  assert((await pendingDialog.innerText()).includes('Ödeme bekleniyor'), `${name}: pending state is missing`)
  assert((await pendingDialog.innerText()).includes('Sipariş S serisiyle oluşturulacaktır.'), `${name}: S series changed after create`)
  assert(await page.getByTestId('financial-board-refetch-count').textContent() === '0', `${name}: pending create refetched board`)
  assert(await page.getByTestId('financial-modal-mount-count').textContent() === '1', `${name}: pending create remounted detail`)
  assert(await page.getByTestId('assignment-scroll-reset-count').textContent() === '0', `${name}: pending create reset scroll`)
  assert(
    await pendingDialog.evaluate((element, expectedScrollTop) => element.scrollTop === expectedScrollTop, scrollBeforeCreate),
    `${name}: pending create changed modal scroll`,
  )

  await pendingDialog.getByRole('button', { name: 'Kapat', exact: true }).click()
  await page.getByTestId('load-sandbox-ambiguous-create-scenario').click()
  await page.locator('button').filter({ hasText: /^(Ödeme Al|Yeni ek ödeme al)$/ }).last().click()
  const ambiguousDialog = page.getByRole('dialog', { name: /^(Ödeme Al|Yeni ek ödeme al)$/ })
  await ambiguousDialog.waitFor({ state: 'visible' })
  await ambiguousDialog.getByLabel('Tahsilat amacı').selectOption('part_charge')
  await ambiguousDialog.getByRole('button', { name: 'EMAKS Prime', exact: true }).click()
  const operationsWarning = ambiguousDialog.getByText('Bu kayıt için önceki sahipsiz sandbox bağlantıları tespit edildi. Yeni dış işlem için operasyon onayı gerekir.', { exact: true })
  await operationsWarning.waitFor({ state: 'visible', timeout: 5000 })
  assert(await operationsWarning.count() === 1, `${name}: operations-review warning is duplicated`)
  assert(await ambiguousDialog.getByText('Sağlayıcı bağlantıyı oluşturdu ancak Panel kaydı kesinleştirilemedi. Yeni işlem başlatmadan önce operasyon kontrolü gerekir.', { exact: true }).count() === 1, `${name}: typed ambiguous error is duplicated or missing`)
  assert(await ambiguousDialog.getByRole('button', { name: 'Link oluştur', exact: true }).isDisabled(), `${name}: ambiguous provider effect permits fresh create`)
  assert(await ambiguousDialog.getByTestId('pending-payment-actions-payment-modal').count() === 0, `${name}: ambiguous provider effect exposes pending link actions`)
  assert(await page.getByTestId('payment-order-create-count').textContent() === '0', `${name}: ambiguous scenario created a request`)
  assert(await ambiguousDialog.evaluate((element) => element.scrollWidth <= element.clientWidth + 1), `${name}: ambiguous dialog has horizontal overflow`)

  await page.screenshot({ path: path.join(artifactDir, `iyzico-sandbox-simulation-${name}.png`), fullPage: true })
  await page.close()
}

const browser = await chromium.launch({ headless: true, executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe' })
await inspectViewport(browser, 'desktop', { width: 1440, height: 1000 })
await inspectViewport(browser, 'mobile', { width: 390, height: 844 })
await browser.close()

assert(browserErrors.length === 0, `browser errors: ${browserErrors.join(' | ')}`)
console.log(JSON.stringify({ result: failures.length === 0 ? 'passed' : 'failed', failures, browserErrors }, null, 2))

if (failures.length > 0) {
  process.exitCode = 1
}
