import { Head, Link } from '@inertiajs/react'
import type { ReactNode } from 'react'

type MountPaymentPageProps = {
  payment: {
    id: number | string
    status: string
    amount: number
    currency: string
    purpose?: string | null
    note?: string | null
    payment_url?: string | null
    fake_approve_url?: string | null
  }
  requestSummary?: {
    mrn?: string | null
    customer?: string | null
    phone?: string | null
  } | null
}

const formatMoney = (amount: number, currency: string): string => (
  `${amount.toLocaleString('tr-TR', { minimumFractionDigits: 2, maximumFractionDigits: 2 })} ${currency}`
)

function InfoRow({ label, value, strong = true }: { label: string; value: ReactNode; strong?: boolean }) {
  return (
    <div className="grid min-w-0 grid-cols-1 gap-1 sm:grid-cols-[minmax(0,1fr)_minmax(0,auto)] sm:items-center sm:gap-3">
      <span className="min-w-0 text-slate-500">{label}</span>
      <span className={`${strong ? 'font-semibold' : ''} min-w-0 break-words sm:text-right`}>{value}</span>
    </div>
  )
}

export default function MountPayment({ payment, requestSummary }: MountPaymentPageProps) {
  const paid = payment.status === 'paid'

  return (
    <>
      <Head title="Montaj Ödemesi" />
      <main className="min-h-screen w-full max-w-full overflow-x-hidden bg-slate-100 px-4 py-8 text-slate-950">
        <section className="mx-auto grid w-full max-w-xl min-w-0 gap-4 overflow-hidden rounded-3xl border border-slate-200 bg-white p-5 shadow-sm sm:p-6">
          <div className="min-w-0">
            <p className="min-w-0 break-words text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Montaj Ödemesi</p>
            <h1 className="mt-2 min-w-0 break-words text-2xl font-bold">{paid ? 'Ödeme alındı' : 'Ödeme bekleniyor'}</h1>
          </div>

          <div className="grid min-w-0 max-w-full gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
            <InfoRow label="MRN" value={requestSummary?.mrn ?? '-'} />
            <InfoRow label="Müşteri" value={requestSummary?.customer ?? '-'} />
            <InfoRow label="Telefon" value={requestSummary?.phone ?? '-'} />
            <InfoRow label="Tutar" value={<span className="text-lg font-bold">{formatMoney(payment.amount, payment.currency)}</span>} strong={false} />
            <InfoRow
              label="Durum"
              value={(
                <span className={paid ? 'font-semibold text-emerald-700' : 'font-semibold text-amber-700'}>
                  {paid ? 'Ödendi' : 'Bekliyor'}
                </span>
              )}
              strong={false}
            />
          </div>

          {payment.note ? (
            <p className="min-w-0 whitespace-pre-wrap break-words rounded-2xl border border-blue-100 bg-blue-50 p-3 text-sm text-blue-950">{payment.note}</p>
          ) : null}

          {!paid && payment.fake_approve_url ? (
            <Link
              href={payment.fake_approve_url}
              method="post"
              as="button"
              type="button"
              className="inline-flex min-h-11 w-full min-w-0 items-center justify-center rounded-xl bg-slate-950 px-4 py-3 text-center text-sm font-semibold text-white transition hover:bg-slate-800 sm:w-auto"
            >
              Ödeme yap
            </Link>
          ) : null}
        </section>
      </main>
    </>
  )
}
