import { Head, Link } from '@inertiajs/react';
import { useState } from 'react';

type Product = {
    product_name?: string | null;
    product_model?: string | null;
    serial_number?: string | null;
    brand?: string | null;
};

type Actions = {
    payment_label?: string;
    multi_product_label?: string;
    continue_label?: string;
    create_payment_url?: string;
    multi_product_url?: string;
};

type Payment = {
    amount: string;
    currency: string;
    fake_approve_url?: string | null;
};

type Props = {
    viewState: 'invalid_link' | 'form_ready' | 'payment_required' | 'multi_product_ready' | 'check_pending' | 'unknown_error';
    message: string;
    product?: Product;
    statusLabel?: string;
    actions?: Actions;
    payment?: Payment | null;
};

function Detail({ label, value }: { label: string; value?: string | null }) {
    if (!value) {
        return null;
    }

    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className="mt-1 text-base font-semibold text-slate-950">{value}</p>
        </div>
    );
}

export default function MountRequestV2({
    viewState,
    message,
    product,
    statusLabel,
    actions,
    payment,
}: Props) {
    const [showPlaceholder, setShowPlaceholder] = useState(false);
    const invalid = viewState === 'invalid_link';
    const paymentRequired = viewState === 'payment_required';
    const formReady = viewState === 'form_ready' || viewState === 'check_pending' || viewState === 'multi_product_ready';

    return (
        <>
            <Head title="Montaj Talep Formu" />
            <main className="min-h-screen bg-slate-100 px-4 py-8">
                <section className="mx-auto grid max-w-3xl gap-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-blue-700">
                            Emaks Prime Teknik Servis
                        </p>
                        <h1 className="mt-2 text-2xl font-semibold text-slate-950">
                            Montaj Talep Formu
                        </h1>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            {message}
                        </p>
                    </div>

                    {invalid ? (
                        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">
                            Montaj talep linki geçersiz veya süresi dolmuş.
                        </div>
                    ) : (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Detail label="Ürün adı" value={product?.product_name} />
                                <Detail label="Model" value={product?.product_model} />
                                <Detail label="Seri no" value={product?.serial_number} />
                                <Detail label="Marka" value={product?.brand} />
                            </div>

                            <div className="rounded-xl border border-blue-100 bg-blue-50 p-4">
                                <p className="text-xs font-semibold uppercase tracking-[0.12em] text-blue-700">
                                    Montaj durumu
                                </p>
                                <p className="mt-1 text-lg font-semibold text-blue-950">{statusLabel}</p>
                            </div>

                            {paymentRequired && (
                                <div className="grid gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                    <p className="text-sm font-semibold text-amber-900">
                                        Bu ürün için montaj ödemesi gereklidir.
                                    </p>
                                    {payment && (
                                        <p className="text-sm text-amber-800">
                                            Tutar: {payment.amount} {payment.currency}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        {actions?.create_payment_url && (
                                            <Link
                                                href={actions.create_payment_url}
                                                method="post"
                                                as="button"
                                                className="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                                            >
                                                {actions.payment_label ?? 'Montaj ödemesi yap'}
                                            </Link>
                                        )}
                                        {actions?.multi_product_url && (
                                            <Link
                                                href={actions.multi_product_url}
                                                method="post"
                                                as="button"
                                                className="rounded-lg border border-amber-200 bg-white px-4 py-2 text-sm font-semibold text-amber-900 shadow-sm hover:bg-amber-100"
                                            >
                                                {actions.multi_product_label ?? 'Birden fazla ürün için montaj talebim var'}
                                            </Link>
                                        )}
                                        {payment?.fake_approve_url && (
                                            <a
                                                href={payment.fake_approve_url}
                                                className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800"
                                            >
                                                Fake ödeme onayla
                                            </a>
                                        )}
                                    </div>
                                </div>
                            )}

                            {formReady && (
                                <div className="grid gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                                    <p className="text-sm font-semibold text-emerald-900">
                                        {viewState === 'multi_product_ready'
                                            ? 'Çoklu ürün montaj talebiniz için form açılmaya hazır. Operasyon ekibi sizinle iletişime geçecektir.'
                                            : message}
                                    </p>
                                    <button
                                        type="button"
                                        onClick={() => setShowPlaceholder(true)}
                                        className="w-fit rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800"
                                    >
                                        {actions?.continue_label ?? 'Forma Devam Et'}
                                    </button>
                                    {showPlaceholder && (
                                        <p className="rounded-lg border border-emerald-200 bg-white p-3 text-sm font-semibold text-slate-700">
                                            Form adımı bir sonraki geliştirme işinde bağlanacak.
                                        </p>
                                    )}
                                </div>
                            )}
                        </>
                    )}
                </section>
            </main>
        </>
    );
}
