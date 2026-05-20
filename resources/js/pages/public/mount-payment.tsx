import { Head, Link } from '@inertiajs/react'

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

export default function MountPayment({ payment, requestSummary }: MountPaymentPageProps) {
  const paid = payment.status === 'paid'

  return (
    <>
      <Head title="Montaj Ödemesi" />
      <main className="min-h-screen bg-slate-100 px-4 py-8 text-slate-950">
        <section className="mx-auto grid max-w-xl gap-4 rounded-3xl border border-slate-200 bg-white p-6 shadow-sm">
          <div>
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">Montaj Ödemesi</p>
            <h1 className="mt-2 text-2xl font-bold">{paid ? 'Ödeme alındı' : 'Ödeme bekleniyor'}</h1>
          </div>

          <div className="grid gap-3 rounded-2xl border border-slate-200 bg-slate-50 p-4 text-sm">
            <div className="flex items-center justify-between gap-3">
              <span className="text-slate-500">MRN</span>
              <span className="font-semibold">{requestSummary?.mrn ?? '-'}</span>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-slate-500">Müşteri</span>
              <span className="font-semibold">{requestSummary?.customer ?? '-'}</span>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-slate-500">Telefon</span>
              <span className="font-semibold">{requestSummary?.phone ?? '-'}</span>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-slate-500">Tutar</span>
              <span className="text-lg font-bold">{formatMoney(payment.amount, payment.currency)}</span>
            </div>
            <div className="flex items-center justify-between gap-3">
              <span className="text-slate-500">Durum</span>
              <span className={paid ? 'font-semibold text-emerald-700' : 'font-semibold text-amber-700'}>
                {paid ? 'Ödendi' : 'Bekliyor'}
              </span>
            </div>
          </div>

          {payment.note ? (
            <p className="rounded-2xl border border-blue-100 bg-blue-50 p-3 text-sm text-blue-950">{payment.note}</p>
          ) : null}

          {!paid && payment.fake_approve_url ? (
            <Link
              href={payment.fake_approve_url}
              className="inline-flex h-11 items-center justify-center rounded-xl bg-slate-950 px-4 text-sm font-semibold text-white transition hover:bg-slate-800"
            >
              Ödeme yap
            </Link>
          ) : null}
        </section>
      </main>
    </>
  )
}
