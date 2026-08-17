import path from 'node:path'
import process from 'node:process'
import { chromium } from 'file:///C:/Users/Emaks/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs'

const baseUrl = process.env.PART_ORDER_PROJECTION_DOM_BASE_URL ?? 'http://127.0.0.1:5175/tests/Browser/assignment-earning-canonical.html'
const artifactDir = process.env.PART_ORDER_PROJECTION_DOM_ARTIFACT_DIR ?? process.cwd()
const failures = []
const browserErrors = []

const assert = (condition, message) => {
  if (!condition) {
    failures.push(message)
  }
}

const output = async (page, id) => (await page.getByTestId(id).textContent() ?? '').trim()

const inspectViewport = async (browser, name, viewport) => {
  const page = await browser.newPage({ viewport })
  page.on('console', (message) => {
    if (message.type() === 'error') {
      const location = message.location()
      browserErrors.push(`${name}:console:${message.text()}:${location.url || 'unknown-url'}`)
    }
  })
  page.on('pageerror', (error) => browserErrors.push(`${name}:pageerror:${error.message}`))

  await page.goto(baseUrl, { waitUntil: 'networkidle' })
  await page.evaluate(() => window.sessionStorage.clear())
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByTestId('load-post-paid-part-projection-scenario').click()

  const sectionTitle = page.getByText('PARÇA / SİPARİŞ VE TESLİMAT', { exact: true })
  await sectionTitle.waitFor({ state: 'visible' })
  assert(await sectionTitle.count() === 1, `${name}: part/order/shipment section is duplicated`)

  const panel = page.locator('details').filter({ has: sectionTitle }).first()
  assert(await panel.getAttribute('open') !== null, `${name}: canonical part section is not visible by default`)
  const summary = page.getByTestId('part-order-shipment-summary')
  await summary.waitFor({ state: 'visible' })
  const summaryText = await summary.innerText()

  assert((await page.locator('body').innerText()).includes('Atanmadı'), `${name}: no-technician state is missing`)
  assert(summaryText.includes('Ödeme alındı'), `${name}: paid status is missing`)
  assert(summaryText.includes('3.000,00 TL'), `${name}: paid gross amount is missing`)
  assert(summaryText.includes('Iyzico Sandbox'), `${name}: provider mode is missing`)
  assert(summaryText.includes('Payment #205'), `${name}: canonical payment identity is missing`)
  assert(summaryText.includes('Sipariş S serisiyle oluşturulacaktır.'), `${name}: target series S is missing`)
  assert(summaryText.includes('Kargo hazırlığı bekliyor'), `${name}: shipment state is missing`)
  assert(summaryText.includes('Muhasebe maili gönderildi'), `${name}: finance mail status is missing`)

  const details = page.getByTestId('part-order-readonly-details')
  assert(await details.getAttribute('open') === null, `${name}: technical part details are expanded by default`)
  await details.getByText('Parça ve teslimat detayını aç', { exact: true }).click()
  await page.getByText('EE.BCK.STD.0010', { exact: false }).last().waitFor({ state: 'visible' })
  const detailText = await details.innerText()

  assert(detailText.includes('EE.BCK.STD.0010'), `${name}: first selected part is missing`)
  assert(detailText.includes('EP.YDP.002.015'), `${name}: second selected part is missing`)
  assert(detailText.includes('EMAKS Prime'), `${name}: supplier is missing`)
  assert(detailText.includes('Sevk'), `${name}: delivery mode is missing`)
  assert(detailText.includes('Deneme Müşteri'), `${name}: delivery recipient is missing`)
  assert(detailText.includes('W720FWS03E250621A00475'), `${name}: serviced-product serial is missing`)
  assert(detailText.includes('HepsiJet: Entegrasyon kapalı'), `${name}: HepsiJet state is missing`)
  assert(detailText.includes('Mikro test sipariş simülasyonu kaydedildi.'), `${name}: MSIM status is missing`)
  assert(detailText.includes('Gerçek Mikro siparişi oluşturulmadı.'), `${name}: real Mikro write guard is missing`)
  assert(detailText.includes('MSIM-01M07G2VG0PBWJ2RJSTXMN72SP'), `${name}: simulation reference is missing`)

  const whatsapp = page.getByTestId('part-order-message-whatsapp')
  const sms = page.getByTestId('part-order-message-sms')
  assert((await whatsapp.innerText()).includes("WhatsApp: UAT'ta gönderilmedi"), `${name}: WhatsApp suppression label is wrong`)
  assert((await sms.innerText()).includes("SMS: UAT'ta gönderilmedi"), `${name}: SMS suppression label is wrong`)
  assert((await whatsapp.innerText()).includes('Yerel/UAT çalışma modunda dış sağlayıcı çağrısı yapılmadı.'), `${name}: WhatsApp suppression detail is missing`)
  assert((await sms.innerText()).includes('Yerel/UAT çalışma modunda dış sağlayıcı çağrısı yapılmadı.'), `${name}: SMS suppression detail is missing`)

  const pageText = await page.locator('body').innerText()
  assert(!pageText.includes('Baskılandı'), `${name}: raw suppressed label remains visible`)
  assert(!pageText.includes('Bloklandı'), `${name}: raw blocked label remains visible`)
  assert(await page.getByRole('button', { name: 'Yeni ek ödeme al', exact: true }).count() === 1, `${name}: separate new-collection action is missing`)
  assert(await page.getByRole('button', { name: 'Linki aç', exact: true }).count() === 0, `${name}: paid history exposes link-open action`)
  assert(await page.getByRole('button', { name: 'Linki kopyala', exact: true }).count() === 0, `${name}: paid history exposes link-copy action`)

  await panel.locator('summary').first().click()
  await panel.locator('summary').first().click()
  await summary.waitFor({ state: 'visible' })

  assert(await output(page, 'payment-order-create-count') === '0', `${name}: detail projection created a payment/context POST`)
  assert(await output(page, 'payment-order-state-update-count') === '0', `${name}: detail projection changed shipment/context state`)
  assert(await output(page, 'finance-receipt-retry-count') === '0', `${name}: detail projection retried finance mail`)
  assert(await output(page, 'financial-board-refetch-count') === '0', `${name}: detail projection refetched the board`)
  assert(await output(page, 'financial-modal-mount-count') === '1', `${name}: detail modal remounted`)
  assert(await output(page, 'assignment-scroll-reset-count') === '0', `${name}: detail projection reset scroll`)
  assert(await output(page, 'request-detail-open') === 'true', `${name}: request detail closed`)

  const panelOverflow = await panel.evaluate((element) => element.scrollWidth - element.clientWidth)
  const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  assert(panelOverflow <= 1, `${name}: part panel horizontal overflow is ${panelOverflow}px`)
  assert(pageOverflow <= 1, `${name}: page horizontal overflow is ${pageOverflow}px`)

  await page.screenshot({ path: path.join(artifactDir, `part-order-shipment-projection-${name}.png`), fullPage: true })
  await page.close()

  return {
    paymentId: 205,
    amount: '3.000,00 TL',
    targetSeries: 'S',
    lineCount: 2,
    modalMount: 1,
    boardRefetch: 0,
    businessPost: 0,
    panelOverflow,
    pageOverflow,
  }
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
