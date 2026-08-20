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

const inspectSearchGeometry = async (page, surface, name) => {
  const wrapper = page.getByTestId(`technician-search-${surface}`)
  const icon = page.getByTestId(`technician-search-icon-${surface}`)
  const input = page.getByTestId(`technician-search-input-${surface}`)
  const [wrapperBox, iconBox, inputBox, iconStyle] = await Promise.all([
    wrapper.boundingBox(),
    icon.boundingBox(),
    input.boundingBox(),
    icon.evaluate((element) => {
      const style = window.getComputedStyle(element)

      return {
        pointerEvents: style.pointerEvents,
        borderWidth: style.borderWidth,
      }
    }),
  ])

  assert(wrapperBox && iconBox && inputBox, `${name}: ${surface} search geometry is unavailable`)

  if (!wrapperBox || !iconBox || !inputBox) {
    return
  }

  const iconCenterY = iconBox.y + (iconBox.height / 2)
  const inputCenterY = inputBox.y + (inputBox.height / 2)
  const centerDelta = Math.abs(iconCenterY - inputCenterY)
  assert(iconBox.x >= inputBox.x && iconBox.x + iconBox.width <= inputBox.x + inputBox.width, `${name}: ${surface} search icon is outside the input`)
  assert(centerDelta <= 1, `${name}: ${surface} search icon is not vertically centered (${centerDelta}px)`)
  assert(inputBox.x >= wrapperBox.x && inputBox.x + inputBox.width <= wrapperBox.x + wrapperBox.width + 1, `${name}: ${surface} search input exceeds its wrapper`)
  assert(iconStyle.pointerEvents === 'none', `${name}: ${surface} search icon captures pointer events`)
  assert(iconStyle.borderWidth === '0px', `${name}: ${surface} search icon renders as a separate bordered cell`)

  return {
    wrapper: wrapperBox,
    icon: iconBox,
    input: inputBox,
    centerDelta,
    iconStyle,
  }
}

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

  await page.getByTestId('load-payment-impact-scenario').click()
  const financialCards = page.getByTestId('financial-primary-cards')
  const financialCardsText = await financialCards.innerText()
  assert(await financialCards.getByText('Müşteriden alınan', { exact: true }).count() === 1, `${name}: customer collection card is missing`)
  assert(await financialCards.getByText('Usta toplam hakedişi', { exact: true }).count() === 1, `${name}: total technician earning card is missing`)
  assert(await financialCards.getByText('5.000,00 TL', { exact: true }).count() === 2, `${name}: customer and technician totals are not both 5.000 TL`)
  assert(await financialCards.getByText('Hakediş ödeme kaynağı', { exact: true }).count() === 1, `${name}: payment-source card is missing`)
  assert(await financialCards.getByText('EMAKS Prime', { exact: true }).count() === 1, `${name}: EMAKS Prime payment source is missing`)
  assert(!financialCardsText.includes('Şirket ödemesi'), `${name}: misleading company-payment primary card remains`)
  const basePaymentSource = page.getByTestId('included-collection-source-197')
  const extraPaymentSource = page.getByTestId('included-collection-source-198')
  const basePaymentText = await basePaymentSource.innerText()
  const extraPaymentText = await extraPaymentSource.innerText()
  assert(basePaymentText.includes('İşçilik 3.000,00 TL + Yol 1.400,00 TL ile eşleşti.'), `${name}: base payment does not explain labor and route coverage`)
  assert(basePaymentText.includes('Ek hakediş oluşturmadı.'), `${name}: base payment does not explain that no extra earning was created`)
  assert(basePaymentText.includes('Karar: Karar gerekmiyor'), `${name}: base payment incorrectly requires a decision`)
  assert(extraPaymentText.includes('Test Usta’ya Ek servis olarak eklendi.'), `${name}: extra-service earning impact is missing`)
  assert(extraPaymentText.includes('Karar: Ustaya ödenecek — Tamamlandı'), `${name}: completed technician-pay decision is missing from its payment row`)
  assert(await page.getByTestId('company-payment-decision-completed').count() === 0, `${name}: completed allocation still renders a large standalone block`)
  assert(await page.getByText('Dağıtım kararı', { exact: true }).count() === 0, `${name}: completed allocation title remains outside the payment row`)
  assert(await page.getByText('Şirket ödemesi', { exact: true }).count() === 0, `${name}: generic company-payment label remains visible`)
  const earningBreakdownSummary = page.locator('summary').filter({ hasText: /^Hakediş kırılımı$/ }).first()
  const earningBreakdown = earningBreakdownSummary.locator('..')
  await earningBreakdownSummary.click()
  const earningBreakdownText = await earningBreakdown.innerText()
  assert(earningBreakdownText.includes('İşçilik: 3.000,00 TL'), `${name}: earning breakdown labor is missing`)
  assert(earningBreakdownText.includes('Yol: 1.400,00 TL'), `${name}: earning breakdown route is missing`)
  assert(earningBreakdownText.includes('Ek servis: 600,00 TL'), `${name}: earning breakdown extra service is missing`)
  assert(earningBreakdownText.includes('Toplam: 5.000,00 TL'), `${name}: earning breakdown total is missing`)
  assert(!earningBreakdownText.includes('Şirket ödemesi:'), `${name}: generic company-payment line remains in earning breakdown`)
  const historicalPayments = page.getByTestId('payment-history-historical')
  assert(await historicalPayments.evaluate((element) => !element.hasAttribute('open')), `${name}: cancelled payment history is open by default`)
  await historicalPayments.locator('summary').first().click()
  await historicalPayments.locator('details summary').first().click()
  const cancelledPaymentText = await page.getByTestId('financial-payment-row-1960').innerText()
  assert(cancelledPaymentText.includes('İptal/başarısız kayıt — Hakedişe etkisi yok.'), `${name}: cancelled payment does not explain its zero earning contribution`)
  assert(await page.getByTestId('technical-service-financial-workspace').evaluate((element) => element.scrollWidth <= element.clientWidth + 1), `${name}: payment impact workspace overflows horizontally`)
  assert(await outputText(page, 'financial-board-refetch-count') === '0', `${name}: payment impact projection refetched the board`)
  assert(await outputText(page, 'financial-modal-mount-count') === '1', `${name}: payment impact projection remounted the detail modal`)
  assert(await outputText(page, 'assignment-scroll-reset-count') === '0', `${name}: payment impact projection reset scroll`)
  await page.screenshot({
    path: path.join(artifactDir, `financial-payment-impact-${name}.png`),
    fullPage: true,
  })

  await page.getByTestId('load-company-payment-scenario').click()
  assert(await page.getByText('1 tahsilat için dağıtım kararı bekliyor.', { exact: true }).count() === 1, `${name}: pending decision warning is missing or duplicated`)
  const pendingPaymentSource = page.getByTestId('included-collection-source-196')
  assert((await pendingPaymentSource.innerText()).includes('Dağıtıma kalan tutar: 1.000,00 TL'), `${name}: pending payment row omits the unmatched residual`)
  assert(await pendingPaymentSource.getByRole('button', { name: 'Karar ver', exact: true }).count() === 1, `${name}: pending payment row action is missing`)

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
  const reassignmentMapLink = page.getByTestId('assignment-preview-map-link')
  assert(await reassignmentMapLink.count() === 1, `${name}: assignment preview map link is missing`)
  assert((await reassignmentMapLink.getAttribute('href')) === 'https://www.google.com/maps/search/?api=1&query=37.8980452%2C29.1855785', `${name}: assignment preview map link is not canonical`)
  assert(!(await page.getByTestId('assignment-final-popup').innerText()).includes('maps_url'), `${name}: raw maps_url variable is user-facing`)
  await page.screenshot({
    path: path.join(artifactDir, `assignment-maps-preview-${name}.png`),
    fullPage: true,
  })
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
  const mainSearch = page.getByTestId('technician-search-input-main')
  await mainSearch.waitFor({ state: 'visible' })
  const mainSearchGeometry = await inspectSearchGeometry(page, 'main', name)
  await mainSearch.fill('Usta 49')
  assert(await page.getByText('Usta 49', { exact: true }).count() > 0, `${name}: main search cannot find a technician outside the first group`)
  assert(await outputText(page, 'assignment-route-request-count') === '0', `${name}: main search typing triggered route calculation`)
  await mainSearch.fill('')
  assert(await outputText(page, 'assignment-route-request-count') === '0', `${name}: clearing main search triggered route calculation`)

  await page.getByRole('button', { name: /Diğer ustalar \(\d+\)/ }).click()
  const modalSearchGeometry = await inspectSearchGeometry(page, 'modal', name)
  const modalSearch = page.getByTestId('technician-search-input-modal')
  await modalSearch.fill('90532000048')
  assert(await page.getByText('Usta 49', { exact: true }).count() > 0, `${name}: modal search cannot filter by phone`)
  assert(await outputText(page, 'assignment-route-request-count') === '0', `${name}: modal search typing triggered route calculation`)
  await page.getByRole('button', { name: 'Kapat', exact: true }).click()

  const initialOpenStartedAt = performance.now()
  const initialAssignButton = page.getByRole('button', { name: 'Servis Ata', exact: true }).first()
  assert(!(await initialAssignButton.isDisabled()), `${name}: unresolved collection/source blocker prevents opening assignment source decision`)
  await initialAssignButton.click()
  await page.getByTestId('assignment-final-popup').waitFor({ state: 'visible' })
  const initialAssignmentMapLink = page.getByTestId('assignment-preview-map-link')
  assert(await initialAssignmentMapLink.count() === 1, `${name}: initial assignment preview map link is missing`)
  assert((await initialAssignmentMapLink.getAttribute('href')) === 'https://www.google.com/maps/search/?api=1&query=37.8980452%2C29.1855785', `${name}: initial assignment preview map link is not canonical`)
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

  return {
    reassignmentOpenMs,
    reassignmentCloseMs,
    initialOpenMs,
    initialCloseMs,
    mainSearchGeometry,
    modalSearchGeometry,
  }
}

const pageSource = fs.readFileSync(path.join(process.cwd(), 'resources/js/pages/panel/technical-service.tsx'), 'utf8')
const detailsSource = fs.readFileSync(path.join(process.cwd(), 'resources/js/components/technical-service/ServiceRequestDetails.tsx'), 'utf8')
const cardSource = fs.readFileSync(path.join(process.cwd(), 'resources/js/components/technical-service/TechnicalServiceKanbanCard.tsx'), 'utf8')
assert(pageSource.includes('modalAssignmentPaymentModel?.mount_included'), 'source: mount-included payment model is not authoritative')
assert(pageSource.includes('Müşterinin ustaya ödeyeceği tutar'), 'source: corrected customer-direct-payment label is missing')
assert(pageSource.includes('Hakediş ödeme kaynağı'), 'source: technician payment source is missing')
assert(pageSource.includes("type AssignmentEarningPaymentSource = 'company' | 'customer_direct'"), 'source: payment-source decision is not typed')
assert(pageSource.includes('earning_payment_source: effectiveAssignmentPaymentSource'), 'source: assignment submit omits the explicit payment-source decision')
assert(pageSource.includes("effectiveAssignmentPaymentSource === 'company'"), 'source: company-paid assignment state is missing')
assert(pageSource.includes('customerDirectAmount = assignmentCompanyPaysTechnician'), 'source: customer-direct amount is not separated from technician earning')
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
assert(detailsSource.includes('label="Hakediş ödeme kaynağı"'), 'source: canonical technician payment source is missing from the top summary')
assert(!detailsSource.includes('label="Şirket ödemesi" value={selectedFinancialPayload.company_payment_amount_label'), 'source: numeric company-payment line still owns the top summary')
assert(detailsSource.includes('renderPaymentEarningImpact(source.earning_impact, source.payment_id)'), 'source: payment impact is not rendered from the server projection')
assert(!detailsSource.includes('data-testid="company-payment-decision-completed"'), 'source: completed allocation still owns a standalone decision block')

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
