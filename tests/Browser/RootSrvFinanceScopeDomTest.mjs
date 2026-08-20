import path from 'node:path'
import process from 'node:process'
import { chromium } from 'file:///C:/Users/Emaks/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs'

const baseUrl = process.env.ROOT_SRV_FINANCE_DOM_BASE_URL ?? 'http://127.0.0.1:5175/tests/Browser/assignment-earning-canonical.html'
const artifactDir = process.env.ROOT_SRV_FINANCE_DOM_ARTIFACT_DIR ?? process.cwd()
const failures = []
const browserErrors = []

const assert = (condition, message) => {
  if (!condition) {
    failures.push(message)
  }
}

const output = async (page, id) => (await page.getByTestId(id).textContent() ?? '').trim()

const metricText = async (cards, label) => {
  const metric = cards.locator(':scope > div').filter({ hasText: label }).first()

  return (await metric.innerText()).trim()
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

  await page.goto(baseUrl, { waitUntil: 'networkidle' })
  await page.evaluate(() => window.sessionStorage.clear())
  await page.reload({ waitUntil: 'networkidle' })
  await page.getByTestId('load-root-srv-finance-correction-scenario').click()

  const workspace = page.getByTestId('technical-service-financial-workspace')
  await workspace.waitFor({ state: 'visible' })
  const currentCards = page.getByTestId('financial-primary-cards')
  assert((await metricText(currentCards, 'Müşteriden alınan')).includes('1.000,00 TL'), `${name}: child service collection is not 1.000 TL`)
  assert((await metricText(currentCards, 'Usta toplam hakedişi')).includes('1.500,00 TL'), `${name}: child payout is not 1.500 TL`)
  assert((await metricText(currentCards, 'Hakediş ödeme kaynağı')).includes('EMAKS Prime'), `${name}: child payer source is not EMAKS Prime`)
  assert((await metricText(currentCards, 'Operasyon farkı')).includes('-500,00 TL'), `${name}: child operation difference is not -500 TL`)

  const relatedPart = page.getByTestId('current-srv-related-part-collection')
  const relatedPartText = await relatedPart.innerText()
  assert(relatedPartText.includes('500,00 TL'), `${name}: related part collection is missing`)
  assert(relatedPartText.includes('#38'), `${name}: Part Request #38 is missing`)
  assert(relatedPartText.includes('usta hakedişine ve operasyon farkına dahil değildir'), `${name}: part exclusion explanation is missing`)

  const payoutBreakdown = workspace.locator('details').filter({ hasText: 'Hakediş kırılımı' }).first()
  await payoutBreakdown.locator('summary').click()
  const payoutText = await payoutBreakdown.innerText()
  assert(payoutText.includes('İşçilik: 1.000,00 TL'), `${name}: OPS labor amount is not visible`)
  assert(payoutText.includes('Yol: 500,00 TL'), `${name}: explicit route amount is not visible`)
  assert(payoutText.includes('Yol kaynağı: OPS tarafından manuel belirlendi'), `${name}: explicit OPS route source is missing`)

  const pageTextBeforeRoot = await page.locator('body').innerText()
  assert(!pageTextBeforeRoot.includes('1.800,00 TL'), `${name}: synthetic 1.800 TL fallback is visible`)
  assert(!pageTextBeforeRoot.includes('1.800 TL'), `${name}: synthetic 1.800 TL shorthand is visible`)
  assert(!pageTextBeforeRoot.includes('1.800,00 TL → 1.000,00 TL'), `${name}: fake 1.800 to 1.000 comparison is visible`)

  await page.getByRole('button', { name: 'Kök MRN toplamı', exact: true }).click()
  assert((await metricText(currentCards, 'Müşteriden alınan toplam')).includes('5.000,00 TL'), `${name}: root collection is not 5.000 TL`)
  assert((await metricText(currentCards, 'Usta toplam hakedişi')).includes('5.000,00 TL'), `${name}: root payout is not 5.000 TL`)
  assert((await metricText(currentCards, 'Hakediş ödeme kaynağı')).includes('EMAKS Prime'), `${name}: root payer source is not EMAKS Prime`)
  assert((await metricText(currentCards, 'Operasyon farkı')).includes('-500,00 TL'), `${name}: root operation difference is not -500 TL`)
  const rootBreakdown = await page.getByTestId('root-customer-collection-breakdown').innerText()
  assert(rootBreakdown.includes('Hizmet tahsilatı: 4.500,00 TL'), `${name}: root service collection split is wrong`)
  assert(rootBreakdown.includes('Parça tahsilatı: 500,00 TL'), `${name}: root part collection split is wrong`)

  await page.getByRole('button', { name: 'Bu SRV', exact: true }).click()
  const historyPanel = page.locator('details').filter({ has: page.getByText('Kayıt geçmişi', { exact: true }) }).first()
  await historyPanel.locator('summary').first().click()
  await historyPanel.getByRole('button', { name: 'Detayı aç', exact: true }).click()
  const historyModal = page.getByRole('dialog', { name: 'SRV ve ana MRN geçmiş detayı' })
  await historyModal.waitFor({ state: 'visible' })
  const historyText = await historyModal.innerText()
  assert(historyText.includes('SRV-2608DD180009-001'), `${name}: child code is missing from history modal`)
  assert(historyText.includes('Kök MRN: MRN-2608DD180009'), `${name}: root code is missing from history modal`)
  assert(historyText.includes('Kök MRN kapı görselleri'), `${name}: root door image group is missing`)
  assert(historyText.includes('Önceki ziyaret saha belgeleri'), `${name}: previous visit document group is missing`)
  assert(historyText.includes('Bu SRV’ye ait belgeler'), `${name}: current SRV document group is missing`)
  assert(historyText.includes('Kaynak: Kök MRN'), `${name}: root source label is missing`)
  assert(historyText.includes('Kaynak: Önceki ziyaret'), `${name}: previous source label is missing`)
  assert(historyText.includes('Kaynak: Bu SRV'), `${name}: current source label is missing`)

  const imageState = await historyModal.locator('img').evaluateAll((images) => images.map((image) => ({
    complete: image.complete,
    naturalWidth: image.naturalWidth,
    source: image.currentSrc,
  })))
  assert(imageState.length === 3, `${name}: expected three projected images, found ${imageState.length}`)
  assert(imageState.every((image) => image.complete && image.naturalWidth > 0), `${name}: one or more projected images did not render`)

  assert(await output(page, 'financial-modal-mount-count') === '1', `${name}: detail modal remounted`)
  assert(await output(page, 'financial-board-refetch-count') === '0', `${name}: board refetched`)
  assert(await output(page, 'assignment-scroll-reset-count') === '0', `${name}: scroll reset occurred`)
  assert(await output(page, 'payment-order-create-count') === '0', `${name}: payment/context POST occurred`)

  const modalOverflow = await historyModal.evaluate((element) => element.scrollWidth - element.clientWidth)
  const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)
  assert(modalOverflow <= 1, `${name}: history modal horizontal overflow is ${modalOverflow}px`)
  assert(pageOverflow <= 1, `${name}: page horizontal overflow is ${pageOverflow}px`)

  await page.screenshot({ path: path.join(artifactDir, `root-srv-finance-scope-${name}.png`), fullPage: true })
  await page.close()

  return {
    childCollection: 1000,
    childPayout: 1500,
    rootCollection: 5000,
    rootPayout: 5000,
    operationDifference: -500,
    projectedImages: imageState.length,
    modalOverflow,
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
