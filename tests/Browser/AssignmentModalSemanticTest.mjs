import fs from 'node:fs'
import path from 'node:path'
import process from 'node:process'
import { chromium } from 'file:///C:/Users/Emaks/.cache/codex-runtimes/codex-primary-runtime/dependencies/node/node_modules/playwright/index.mjs'

const baseUrl = process.env.ASSIGNMENT_DOM_BASE_URL ?? 'http://127.0.0.1:5175'
const artifactDir = process.env.ASSIGNMENT_DOM_ARTIFACT_DIR ?? process.cwd()
const failures = []
const browserErrors = []

const assert = (condition, message) => {
  if (!condition) {
    failures.push(message)
  }
}

const outputText = async (page, testId) => (await page.getByTestId(testId).textContent() ?? '').trim()

const inspectViewport = async (browser, name, viewport) => {
  const page = await browser.newPage({ viewport })
  page.on('pageerror', (error) => browserErrors.push(`${name}:page:${error.message}`))
  page.on('response', (response) => {
    if (response.status() >= 400 && !response.url().endsWith('/favicon.ico')) {
      browserErrors.push(`${name}:http:${response.status()}:${response.url()}`)
    }
  })

  await page.goto(`${baseUrl}/tests/Browser/assignment-earning-canonical.html`, { waitUntil: 'networkidle' })
  await page.waitForFunction(() => window.__assignmentEarningDomReady === true)

  const canonicalBoardBadge = page.getByTestId('canonical-technician-board-card').getByTestId('technical-service-technician-badge')
  const missingCityBoardBadge = page.getByTestId('missing-technician-city-board-card').getByTestId('technical-service-technician-badge')
  assert(await canonicalBoardBadge.innerText() === 'TS - Test Usta - Denizli', `${name}: board badge does not use the canonical technician city`)
  assert(await missingCityBoardBadge.innerText() === 'TS - Test Usta', `${name}: missing technician city uses a false fallback suffix`)

  await page.getByTestId('load-matched-route-collection-scenario').click()
  assert(await page.getByText(/Residual:/).count() === 0, `${name}: internal Residual label is visible`)
  assert(await page.getByText('Dağıtıma kalan tutar', { exact: true }).count() === 0, `${name}: zero residual is rendered`)
  assert(await page.getByText(/0\/1 karar/).count() === 0, `${name}: zero residual decision blocker is rendered`)
  assert(await page.getByText('Atanan usta özeti', { exact: true }).count() === 0, `${name}: duplicate assigned-technician summary is rendered`)
  assert(await page.getByText('Denizli / Pamukkale', { exact: true }).count() > 0, `${name}: canonical selected-technician location is missing`)
  assert(await page.getByText('Ankara', { exact: true }).count() === 0, `${name}: old/customer city leaked into assigned-technician projection`)
  assert(await page.getByText('İşçilik', { exact: true }).count() > 0, `${name}: assigned labor is missing`)
  assert(await page.getByText('Yol hakedişi', { exact: true }).count() > 0, `${name}: assigned route earning is missing`)
  assert(await page.getByText('Tahmini usta yol hakedişi', { exact: true }).count() === 0, `${name}: saved route is still labelled estimated`)

  await page.getByTestId('load-paid-part-scenario').click()
  assert(await page.getByText('Gateway — 2.000,00 TL', { exact: true }).count() > 0, `${name}: paid part name/amount is missing`)
  assert(await page.getByText('Ödeme ref: 37164237 ile online alındı.', { exact: true }).count() > 0, `${name}: real paid-part reference is missing`)

  await page.getByTestId('load-unpaid-part-scenario').click()
  assert(await page.getByText('Ödeme alınmadı.', { exact: true }).count() > 0, `${name}: unpaid part is not labelled truthfully`)

  await page.getByTestId('load-free-part-scenario').click()
  assert(await page.getByText('Gateway — Ücretsiz', { exact: true }).count() > 0, `${name}: free part label is missing`)

  await page.getByTestId('load-no-part-scenario').click()
  assert(await page.getByText(/Gateway —/).count() === 0, `${name}: no-part request renders a part row`)

  await page.getByTestId('load-reassignment-eligibility-scenario').click()
  const dynamicBoardBadge = page.getByTestId('assignment-dynamic-board-card').getByTestId('technical-service-technician-badge')
  assert(await dynamicBoardBadge.innerText() === 'TS - BAHATTİN ÖZBEK - Ankara', `${name}: reassignment fixture does not expose the old canonical technician location`)
  const reassignmentOpenStartedAt = performance.now()
  await page.getByRole('button', { name: 'Atamayı Güncelle', exact: true }).first().click()
  await page.getByTestId('assignment-final-popup').waitFor({ state: 'visible' })
  const reassignmentOpenMs = Math.round(performance.now() - reassignmentOpenStartedAt)
  assert(reassignmentOpenMs <= 500, `${name}: reassignment popup render took ${reassignmentOpenMs} ms`)
  await page.getByTestId('assignment-final-popup-confirm').click()
  assert(await page.getByTestId('assignment-reason-error').textContent() === 'Yeniden atama nedeni yazınız.', `${name}: reassignment inline reason validation is missing`)
  assert(await page.evaluate(() => document.activeElement?.getAttribute('data-testid')) === 'assignment-reason-input', `${name}: invalid reason does not focus its field`)
  await page.getByTestId('assignment-reason-input').fill('Önceki usta işi tamamlayamadı')
  const reassignmentStartedAt = performance.now()
  await page.getByTestId('assignment-final-popup-confirm').dblclick()
  await page.getByTestId('assignment-final-popup').waitFor({ state: 'detached' })
  const reassignmentCloseMs = Math.round(performance.now() - reassignmentStartedAt)
  assert(reassignmentCloseMs <= 800, `${name}: reassignment popup close took ${reassignmentCloseMs} ms`)
  assert(await outputText(page, 'assignment-confirm-count') === '1', `${name}: reassignment double-click created duplicate confirmation`)
  assert(await outputText(page, 'request-detail-open') === 'true', `${name}: main detail closed after reassignment`)
  assert(await outputText(page, 'financial-board-refetch-count') === '0', `${name}: reassignment refetched the board`)
  assert(await outputText(page, 'financial-modal-mount-count') === '1', `${name}: reassignment remounted the detail modal`)
  assert(await outputText(page, 'assignment-scroll-reset-count') === '0', `${name}: reassignment reset scroll`)
  assert(await dynamicBoardBadge.innerText() === 'TS - Test Usta - Denizli', `${name}: canonical assignment delta did not update the board technician location in place`)
  assert(await page.getByText('Atama Test Usta olarak güncellendi.', { exact: true }).count() > 0, `${name}: reassignment success notice is missing`)
  assert(await page.getByText('Denizli / Pamukkale', { exact: true }).count() > 0, `${name}: reassignment did not apply canonical location`)

  await page.getByTestId('load-initial-assignment-scenario').click()
  const initialOpenStartedAt = performance.now()
  await page.getByRole('button', { name: 'Servis Ata', exact: true }).first().click()
  await page.getByTestId('assignment-final-popup').waitFor({ state: 'visible' })
  const initialOpenMs = Math.round(performance.now() - initialOpenStartedAt)
  assert(initialOpenMs <= 500, `${name}: initial assignment popup render took ${initialOpenMs} ms`)
  assert(await page.getByTestId('assignment-reason-input').count() === 0, `${name}: initial assignment incorrectly requires a reassignment reason`)
  const initialStartedAt = performance.now()
  await page.getByTestId('assignment-final-popup-confirm').dblclick()
  await page.getByTestId('assignment-final-popup').waitFor({ state: 'detached' })
  const initialCloseMs = Math.round(performance.now() - initialStartedAt)
  assert(initialCloseMs <= 800, `${name}: initial assignment popup close took ${initialCloseMs} ms`)
  assert(await outputText(page, 'assignment-confirm-count') === '1', `${name}: initial assignment double-click created duplicate confirmation`)
  assert(await outputText(page, 'request-detail-open') === 'true', `${name}: main detail closed after initial assignment`)
  assert(await outputText(page, 'financial-board-refetch-count') === '0', `${name}: initial assignment refetched the board`)
  assert(await outputText(page, 'financial-modal-mount-count') === '1', `${name}: initial assignment remounted the detail modal`)
  assert(await outputText(page, 'assignment-scroll-reset-count') === '0', `${name}: initial assignment reset scroll`)

  await page.screenshot({
    path: path.join(artifactDir, `assignment-modal-semantic-${name}.png`),
    fullPage: true,
  })
  await page.close()

  return { reassignmentOpenMs, reassignmentCloseMs, initialOpenMs, initialCloseMs }
}

const pageSource = fs.readFileSync(path.join(process.cwd(), 'resources/js/pages/panel/technical-service.tsx'), 'utf8')
const detailsSource = fs.readFileSync(path.join(process.cwd(), 'resources/js/components/technical-service/ServiceRequestDetails.tsx'), 'utf8')
const cardSource = fs.readFileSync(path.join(process.cwd(), 'resources/js/components/technical-service/TechnicalServiceKanbanCard.tsx'), 'utf8')
assert(pageSource.includes('modalAssignmentPaymentModel?.mount_included'), 'source: mount-included payment model is not authoritative')
assert(pageSource.includes('Müşterinin ustaya ödeyeceği tutar'), 'source: corrected customer-direct-payment label is missing')
assert(pageSource.includes('Hakediş ödeme kaynağı:'), 'source: technician payment source is missing')
assert(!pageSource.includes('Önerilen slotlar'), 'source: proposed slots remain in assignment modal')
assert(!pageSource.includes('Gidiş-geliş km'), 'source: raw round-trip kilometre input remains in assignment modal')
assert(!pageSource.includes('Mesajdaki canonical iş kartı bağlantısı bu açık atama kapsamından üretilir.'), 'source: internal canonical-link explanation is user-facing')
assert(pageSource.includes('data-testid="assignment-single-partner-scope"'), 'source: single partner scope is not rendered as read-only')
assert(pageSource.includes('selectedAssignPartnerLinks.length > 1'), 'source: multiple partner scopes do not render a functional selector')
assert(pageSource.includes('Seçili usta'), 'source: selected technician is not pinned')
assert(pageSource.includes('Aynı şehirdeki ustalar'), 'source: same-city technician section is missing')
assert(pageSource.includes('Diğer / Yakın İlleri Göster') && pageSource.includes('Diğer / Yakın İlleri Gizle'), 'source: other technicians do not use progressive disclosure')
assert(pageSource.includes('showOtherTechnicians') && pageSource.includes('assignmentTechnicianSearchActive'), 'source: collapsed and search-all state owners are missing')
assert(pageSource.includes('data-testid="assignment-other-technicians-toggle"'), 'source: other-technician toggle is not testable')
assert(pageSource.includes('data-testid="assignment-technician-search-results"'), 'source: search cannot expose matches from collapsed groups')
assert(pageSource.includes('data-testid="assignment-technician-search-wrapper"') && pageSource.includes('data-testid="assignment-technician-search-icon"'), 'source: search icon/input wrapper contract is missing')
assert(pageSource.includes('grid-cols-1 gap-3 sm:grid-cols-2'), 'source: earning layout does not step from one to two readable columns')
assert(!pageSource.includes('assignment-earning-field-grid" className="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4'), 'source: viewport breakpoint can still compress the narrow modal into four columns')
assert(pageSource.includes('data-testid="assignment-earning-field-labor"') && pageSource.includes('data-testid="assignment-earning-field-total"'), 'source: earning field semantic order is not testable')
assert(cardSource.includes('request.technicianProfile?.city?.trim()'), 'source: board badge does not use canonical technician profile city')
assert(!cardSource.includes('request.technician} - ${request.city'), 'source: board badge still uses customer/request city')
assert(!detailsSource.includes('Atanan usta özeti'), 'source: duplicate assigned-technician summary remains')

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
