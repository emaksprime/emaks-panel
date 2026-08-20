import assert from 'node:assert/strict'
import fs from 'node:fs/promises'
import path from 'node:path'
import { pathToFileURL } from 'node:url'
import { transformWithOxc } from 'vite'

const sourcePath = path.resolve('resources/js/components/technical-service/utils.ts')
const source = await fs.readFile(sourcePath, 'utf8')
const transformed = await transformWithOxc(source, sourcePath, { lang: 'ts' })
const modulePath = path.resolve('.rel4h-global-mrn-utils.mjs')

try {
  await fs.writeFile(modulePath, transformed.code, 'utf8')
  const { formatTechnicalServiceMrn } = await import(`${pathToFileURL(modulePath).href}?nonce=${Date.now()}`)

  assert.equal(
    formatTechnicalServiceMrn({ mrn: 'MRN-2608DD1810000', city: 'Istanbul' }),
    'MRN-2608DD1810000',
    'Canonical long MRN was rewritten or truncated',
  )
  assert.equal(
    formatTechnicalServiceMrn({ mrn: 'MRN-20260814-10000', city: 'Istanbul' }),
    'MRN-202608IST14-10000',
    'Legacy display formatter truncated the five-digit suffix',
  )
  assert.equal(
    formatTechnicalServiceMrn({ mrn: 'MRN-20260814-0001', city: 'Istanbul' }),
    'MRN-202608IST14-0001',
    'Legacy four-digit display behavior changed',
  )
} finally {
  await fs.rm(modulePath, { force: true })
}

console.log('Global MRN formatter checks passed: 3')
