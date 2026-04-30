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
const formatKm = (value: number): string => `${value.toLocaleString('tr-TR')} km`

const serviceBaseAmount = (serviceType?: string) => {
  const normalized = normalizeTechnicalServiceText(serviceType)

  if (normalized === 'montaj') {
    return {
      serviceTypeLabel: 'Montaj',
      amount: 3000,
    }
  }

  if (normalized === 'servis' || normalized === 'ariza') {
    return {
      serviceTypeLabel: normalized === 'ariza' ? 'Arıza' : 'Servis',
      amount: 1800,
    }
  }

  return {
    serviceTypeLabel: serviceType?.trim() || 'Belirlenmedi',
    amount: null,
  }
}

export function calculateTravelPreview(roundTripKm: number | null | undefined) {
  const hasRoundTripKm = typeof roundTripKm === 'number' && Number.isFinite(roundTripKm) && roundTripKm >= 0
  const normalizedRoundTripKm = hasRoundTripKm ? Math.round(roundTripKm * 100) / 100 : null
  const billableKm = normalizedRoundTripKm === null ? null : Math.max(normalizedRoundTripKm - 30, 0)
  const travelFeeAmount = billableKm === null ? null : billableKm * 10

  return {
    roundTripKm: normalizedRoundTripKm,
    freeKm: 30,
    billableKm,
    travelFeeAmount,
  }
}

export function getServicePaymentInfo(
  serviceType?: string,
  travelKm?: number | null,
  persistedTravelFeeAmount?: number | null,
  persistedBillableKm?: number | null,
) {
  const base = serviceBaseAmount(serviceType)
  const travel = calculateTravelPreview(travelKm)
  const billableKm = typeof persistedBillableKm === 'number' && Number.isFinite(persistedBillableKm)
    ? persistedBillableKm
    : travel.billableKm
  const travelAmount = typeof persistedTravelFeeAmount === 'number' && Number.isFinite(persistedTravelFeeAmount)
    ? persistedTravelFeeAmount
    : travel.travelFeeAmount

  if (base.amount !== null) {
    return {
      serviceTypeLabel: base.serviceTypeLabel,
      customerAmountLabel: `${formatCurrency(base.amount)} KDV dahil`,
      technicianAmountLabel: formatCurrency(base.amount),
      roundTripKmLabel: travel.roundTripKm === null ? 'Yol km bilgisi girilmedi' : formatKm(travel.roundTripKm),
      freeKmLabel: formatKm(travel.freeKm),
      billableKmLabel: billableKm === null ? 'Yol km bilgisi girilmedi' : formatKm(billableKm),
      travelAmountLabel: travelAmount === null
        ? 'Yol km bilgisi girilmedi'
        : `${billableKm?.toLocaleString('tr-TR') ?? 0} km x 10 TL = ${formatCurrency(travelAmount)}`,
      totalTechnicianCostLabel: travelAmount === null
        ? `${formatCurrency(base.amount)} + yol ücreti`
        : formatCurrency(base.amount + travelAmount),
      totalTechnicianCostAmount: travelAmount === null ? null : base.amount + travelAmount,
    }
  }

  return {
    serviceTypeLabel: base.serviceTypeLabel,
    customerAmountLabel: 'Belirlenmedi',
    technicianAmountLabel: 'Belirlenmedi',
    roundTripKmLabel: travel.roundTripKm === null ? 'Yol km bilgisi girilmedi' : formatKm(travel.roundTripKm),
    freeKmLabel: formatKm(travel.freeKm),
    billableKmLabel: billableKm === null ? 'Yol km bilgisi girilmedi' : formatKm(billableKm),
    travelAmountLabel: 'Yol km bilgisi girilmedi',
    totalTechnicianCostLabel: 'Belirlenmedi',
    totalTechnicianCostAmount: null,
  }
}
