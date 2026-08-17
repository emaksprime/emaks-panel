import path from 'node:path'
import process from 'node:process'
import { chromium } from 'file:///C:/Users/Emaks/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs'

const baseUrl = process.env.CONTEXT_CORRECTION_DOM_BASE_URL ?? 'http://127.0.0.1:5175/tests/Browser/assignment-earning-canonical.html'
const artifactDir = process.env.CONTEXT_CORRECTION_DOM_ARTIFACT_DIR ?? process.cwd()
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
  await page.getByTestId('load-context-correction-scenario').click()

  const summary = page.getByTestId('part-order-context-summary')
  await summary.waitFor({ state: 'visible' })
  const summaryText = await summary.innerText()

  assert(summaryText.includes('Parçalar (2)'), `${name}: corrected context does not show two lines`)
  assert(summaryText.includes('EE.BCK.STD.0010'), `${name}: first accepted line is missing`)
  assert(summaryText.includes('EP.YDP.002.015'), `${name}: second accepted line is missing`)
  assert(summaryText.includes('Tedarik: EMAKS Prime'), `${name}: supplier is not EMAKS Prime`)
  assert(summaryText.includes('Ticari durum: Ücretli'), `${name}: commercial mode is not paid`)
  assert(summaryText.includes('Teslim: Sevk'), `${name}: delivery mode is not shipment`)
  assert(summaryText.includes('Hedef seri: S'), `${name}: corrected context is not active as series S`)
  assert(!summaryText.includes('Hedef seri: Q'), `${name}: historical Q revision leaked into active authority`)
  assert(summaryText.includes('KDV: %20 · toplam fiyata dahil'), `${name}: VAT-inclusive state is missing`)
  assert(summaryText.includes('Tutar: 2.000,00 TL'), `${name}: gross collection total changed`)

  const historyPanel = page.locator('details').filter({ has: page.getByText('Operasyon Geçmişi', { exact: true }) })
  const historyLabel = page.getByText('Ödeme/sipariş bağlamı operasyon düzeltmesiyle yeniden yetkilendirildi.', { exact: true })
  assert(await historyPanel.getAttribute('open') === null, `${name}: operation history is not collapsed by default`)
  assert(!await historyLabel.isVisible(), `${name}: correction history leaked outside its collapsed panel`)
  await historyPanel.locator('summary').click()
  await historyLabel.waitFor({ state: 'visible' })
  assert(await historyLabel.count() === 1, `${name}: Turkish correction history is duplicated or missing`)

  assert(await page.getByRole('button', { name: 'Linki aç', exact: true }).count() === 0, `${name}: a payment-open action was fabricated`)
  assert(await page.getByRole('button', { name: 'Linki kopyala', exact: true }).count() === 0, `${name}: a payment-copy action was fabricated`)
  assert(await page.getByText('Mikro siparişi oluşturuldu', { exact: false }).count() === 0, `${name}: a Mikro order was fabricated`)
  assert(await page.getByText('Ödeme alındı', { exact: true }).count() === 0, `${name}: corrected pending context is falsely paid`)
  assert(await output(page, 'payment-order-create-count') === '0', `${name}: projection created a payment/context mutation`)
  assert(await output(page, 'financial-board-refetch-count') === '0', `${name}: board refetch count is not zero`)
  assert(await output(page, 'financial-modal-mount-count') === '1', `${name}: detail modal remounted`)
  assert(await output(page, 'assignment-scroll-reset-count') === '0', `${name}: scroll reset count is not zero`)

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  assert(overflow <= 1, `${name}: horizontal overflow is ${overflow}px`)

  await page.screenshot({ path: path.join(artifactDir, `payment-order-context-correction-${name}.png`), fullPage: true })
  await page.close()

  return {
    activeSeries: 'S',
    lineCount: 2,
    grossTotal: '2.000,00 TL',
    boardRefetch: 0,
    modalMount: 1,
    scrollReset: 0,
    overflow,
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
