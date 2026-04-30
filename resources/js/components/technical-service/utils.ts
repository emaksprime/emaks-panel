import type { ServiceRequest } from './types'

export function normalizeTechnicalServiceText(value: string | null | undefined): string {
  return String(value ?? '')
    .trim()
    .toLocaleLowerCase('tr-TR')
    .normalize('NFD')
    .replace(/\p{Diacritic}/gu, '')
    .replace(/ı/g, 'i')
}

const normalizeLocationCode = (value?: string) => {
  const mapped = normalizeTechnicalServiceText(value)
    .toUpperCase()
    .replace(/İ/g, 'I')
    .replace(/Ş/g, 'S')
    .replace(/Ğ/g, 'G')
    .replace(/Ü/g, 'U')
    .replace(/Ö/g, 'O')
    .replace(/Ç/g, 'C')
  const letters = mapped.replace(/[^A-Z]/g, '')
  return (letters.slice(0, 3) || 'XXX').padEnd(3, 'X')
}

export const formatTechnicalServiceMrn = (
  request: ServiceRequest & { province?: string }
): string => {
  const rawMrn = String(request.mrn ?? '')
  const match = rawMrn.match(/(\d{4})(\d{2})(\d{2})\D*(\d+)$/)

  if (!match) {
    return rawMrn
  }

  const [, year, month, day, sequenceRaw] = match
  const sequence = sequenceRaw.slice(-3).padStart(3, '0')
  const location = request.city || request.province || request.district || ''
  const locationCode = normalizeLocationCode(location)

  return `MRN-${year}${month}${locationCode}${day}-${sequence}`
}

const formatCurrency = (amount: number): string => `${amount.toLocaleString('tr-TR')} TL`

export function getServicePaymentInfo(serviceType?: string, travelKm?: number | null) {
  const normalized = normalizeTechnicalServiceText(serviceType)
  const hasTravelKm = typeof travelKm === 'number' && Number.isFinite(travelKm) && travelKm >= 0
  const travelAmount = hasTravelKm ? travelKm * 10 : null
  const travelAmountLabel = travelAmount === null
    ? 'Yol km bilgisi girilmedi'
    : `${travelKm} km x 10 TL = ${formatCurrency(travelAmount)}`

  if (normalized === 'montaj') {
    const technicianAmount = 3000
    return {
      serviceTypeLabel: 'Montaj',
      customerAmountLabel: '3.000 TL KDV dahil',
      technicianAmountLabel: formatCurrency(technicianAmount),
      travelAmountLabel,
      totalTechnicianCostLabel: travelAmount === null
        ? `${formatCurrency(technicianAmount)} + yol ücreti`
        : formatCurrency(technicianAmount + travelAmount),
    }
  }

  if (normalized === 'servis' || normalized === 'ariza') {
    const technicianAmount = 1800
    return {
      serviceTypeLabel: normalized === 'ariza' ? 'Arıza' : 'Servis',
      customerAmountLabel: '1.800 TL KDV dahil',
      technicianAmountLabel: formatCurrency(technicianAmount),
      travelAmountLabel,
      totalTechnicianCostLabel: travelAmount === null
        ? `${formatCurrency(technicianAmount)} + yol ücreti`
        : formatCurrency(technicianAmount + travelAmount),
    }
  }

  return {
    serviceTypeLabel: serviceType?.trim() || 'Belirlenmedi',
    customerAmountLabel: 'Belirlenmedi',
    technicianAmountLabel: 'Belirlenmedi',
    travelAmountLabel: 'Yol km bilgisi girilmedi',
    totalTechnicianCostLabel: 'Belirlenmedi',
  }
}
