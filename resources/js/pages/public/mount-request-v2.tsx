import { Head, Link, useForm } from '@inertiajs/react';
import { useState } from 'react';
import type { FormEvent } from 'react';

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
    submit_url?: string;
};

type Payment = {
    amount: string;
    currency: string;
    fake_approve_url?: string | null;
};

type Submitted = {
    mrn: string;
};

type CustomerForm = {
    first_name: string;
    last_name: string;
    phone: string;
    city: string;
    district: string;
    address: string;
    installation_consent: boolean;
    kvkk_consent: boolean;
};

type Props = {
    viewState:
        | 'invalid_link'
        | 'form_ready'
        | 'payment_required'
        | 'multi_product_ready'
        | 'check_pending'
        | 'submitted'
        | 'unknown_error';
    message: string;
    product?: Product;
    statusLabel?: string;
    actions?: Actions;
    payment?: Payment | null;
    submitted?: Submitted | null;
};

const PHONE_ERROR = 'Telefon numarası +90 sonrası 10 hane olmalıdır.';

const INSTALLATION_CONSENT_TEXT = `Değerli Müşterimiz,
Akıllı kilidin kapınıza kurulumu öncesinde, teknik ekibimiz tarafından kapı üzerinde geri dönüşümü olmayan değişiklikler yapılması gerektiğini size bildirmek isteriz. Bu değişiklikler arasında delik açma veya kapıda onarımlar bulunabilir. Bu değişikliklerin, akıllı kilidin uygun bir şekilde kurulması ve çalışması için gerekli olduğunu bilmeniz gerekmektedir. Bu mesajdaki kuralları ve bilgileri kabul ederseniz şartları onaylamış olursunuz.
*Satın aldığım ürünün bütün özelliklerini inceledim bilgim dahilindedir ve kurulumu yapılan cihazın hiç bir şekilde iade ve değişimi söz konusu değildir.*`;

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

function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="mt-1 text-xs font-semibold text-red-600">{message}</p>;
}

function normalizePhoneDigits(value: string): string {
    let digits = value.replace(/\D+/g, '');

    if (digits.startsWith('90') && digits.length >= 12) {
        digits = digits.slice(2);
    }

    if (digits.startsWith('0')) {
        digits = digits.slice(1);
    }

    return digits.slice(0, 10);
}

export default function MountRequestV2({
    viewState,
    message,
    product,
    statusLabel,
    actions,
    payment,
    submitted,
}: Props) {
    const invalid = viewState === 'invalid_link';
    const paymentRequired = viewState === 'payment_required';
    const submittedSuccessfully = viewState === 'submitted';
    const formReady = viewState === 'form_ready' || viewState === 'check_pending' || viewState === 'multi_product_ready';
    const [submitStatus, setSubmitStatus] = useState('');
    const [submitError, setSubmitError] = useState('');
    const form = useForm<CustomerForm>({
        first_name: '',
        last_name: '',
        phone: '',
        city: '',
        district: '',
        address: '',
        installation_consent: false,
        kvkk_consent: false,
    });

    const submitUrl = actions?.submit_url;

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!submitUrl) {
            setSubmitError('Form gönderim adresi hazırlanamadı.');

            return;
        }

        const normalizedPhone = normalizePhoneDigits(form.data.phone);

        if (normalizedPhone.length !== 10) {
            setSubmitError(PHONE_ERROR);
            form.setError('phone', PHONE_ERROR);

            return;
        }

        setSubmitError('');
        form.clearErrors('phone');
        form.transform((data) => ({
            ...data,
            phone: normalizedPhone,
        })).post(submitUrl, {
            preserveScroll: true,
            onStart: () => {
                setSubmitError('');
                setSubmitStatus('Talebiniz gönderiliyor...');
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                setSubmitError(typeof firstError === 'string' ? firstError : 'Form gönderilemedi. Lütfen alanları kontrol edin.');
            },
            onFinish: () => {
                setSubmitStatus('');
            },
        });
    };

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
                    ) : submittedSuccessfully ? (
                        <div className="grid gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                            <p className="text-lg font-semibold text-emerald-950">
                                Montaj talebiniz alınmıştır.
                            </p>
                            {submitted?.mrn && (
                                <p className="text-sm font-semibold text-emerald-800">
                                    Talep numaranız: {submitted.mrn}
                                </p>
                            )}
                        </div>
                    ) : (
                        <>
                            <div className="grid gap-3 sm:grid-cols-2">
                                <Detail label="Ürün adı" value={product?.product_name} />
                                <Detail label="Model" value={product?.product_model} />
                                <Detail label="Seri no" value={product?.serial_number} />
                                <Detail label="Marka" value={product?.brand} />
                            </div>

                            {paymentRequired && (
                                <div className="grid gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                    <div>
                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-amber-700">
                                            Montaj durumu
                                        </p>
                                        <p className="mt-1 text-lg font-semibold text-amber-950">{statusLabel}</p>
                                    </div>
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
                                <form onSubmit={handleSubmit} className="grid gap-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                                    {viewState === 'multi_product_ready' && (
                                        <p className="rounded-lg border border-emerald-200 bg-white p-3 text-sm font-semibold text-emerald-900">
                                            Birden fazla ürün için montaj talebiniz alınmaya hazır. Operasyon ekibi sizinle iletişime geçecektir.
                                        </p>
                                    )}

                                    {(submitError || form.errors.form) && (
                                        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">
                                            {submitError || form.errors.form}
                                        </p>
                                    )}

                                    {submitStatus && (
                                        <p className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm font-semibold text-blue-800">
                                            {submitStatus}
                                        </p>
                                    )}

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <label className="block text-sm font-semibold text-slate-800">
                                            İsim
                                            <input
                                                type="text"
                                                value={form.data.first_name}
                                                onChange={(event) => form.setData('first_name', event.target.value)}
                                                className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                                required
                                            />
                                            <FieldError message={form.errors.first_name} />
                                        </label>

                                        <label className="block text-sm font-semibold text-slate-800">
                                            Soyisim
                                            <input
                                                type="text"
                                                value={form.data.last_name}
                                                onChange={(event) => form.setData('last_name', event.target.value)}
                                                className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                                required
                                            />
                                            <FieldError message={form.errors.last_name} />
                                        </label>
                                    </div>

                                    <label className="block text-sm font-semibold text-slate-800">
                                        Telefon Numarası
                                        <div className="mt-1 flex rounded-lg border border-slate-200 bg-white shadow-sm focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-100">
                                            <span className="flex items-center border-r border-slate-200 px-3 text-sm font-semibold text-slate-600">
                                                +90
                                            </span>
                                            <input
                                                type="tel"
                                                value={form.data.phone}
                                                onChange={(event) => form.setData('phone', normalizePhoneDigits(event.target.value))}
                                                placeholder="5xxxxxxxxx"
                                                inputMode="numeric"
                                                pattern="[0-9]*"
                                                maxLength={10}
                                                className="w-full rounded-r-lg border-0 bg-transparent px-3 py-2 text-sm text-slate-950 focus:outline-none focus:ring-0"
                                                required
                                            />
                                        </div>
                                        <p className="mt-1 text-xs font-medium text-slate-500">
                                            +90 sonrası 10 hane girin.
                                        </p>
                                        <FieldError message={form.errors.phone} />
                                    </label>

                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <label className="block text-sm font-semibold text-slate-800">
                                            İl
                                            <input
                                                type="text"
                                                value={form.data.city}
                                                onChange={(event) => form.setData('city', event.target.value)}
                                                className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                                required
                                            />
                                            <FieldError message={form.errors.city} />
                                        </label>

                                        <label className="block text-sm font-semibold text-slate-800">
                                            İlçe
                                            <input
                                                type="text"
                                                value={form.data.district}
                                                onChange={(event) => form.setData('district', event.target.value)}
                                                className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                                required
                                            />
                                            <FieldError message={form.errors.district} />
                                        </label>
                                    </div>

                                    <label className="block text-sm font-semibold text-slate-800">
                                        Adres
                                        <textarea
                                            value={form.data.address}
                                            onChange={(event) => form.setData('address', event.target.value)}
                                            className="mt-1 min-h-28 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                            required
                                        />
                                        <FieldError message={form.errors.address} />
                                    </label>

                                    <label className="grid gap-2 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700">
                                        <span className="flex items-start gap-2 font-semibold text-slate-900">
                                            <input
                                                type="checkbox"
                                                checked={form.data.installation_consent}
                                                onChange={(event) => form.setData('installation_consent', event.target.checked)}
                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-500"
                                                required
                                            />
                                            Montaj şartlarını kabul ediyorum
                                        </span>
                                        <span className="whitespace-pre-line text-xs leading-5 text-slate-600">
                                            {INSTALLATION_CONSENT_TEXT}
                                        </span>
                                        <FieldError message={form.errors.installation_consent} />
                                    </label>

                                    <label className="grid gap-2 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700">
                                        <span className="flex items-start gap-2 font-semibold text-slate-900">
                                            <input
                                                type="checkbox"
                                                checked={form.data.kvkk_consent}
                                                onChange={(event) => form.setData('kvkk_consent', event.target.checked)}
                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-500"
                                                required
                                            />
                                            KVKK / Aydınlatma ve Açık Rıza Onayı
                                        </span>
                                        <a
                                            href="https://emaksprime.com/kvkk-on-bilgilendirme/"
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-xs font-semibold text-blue-700 underline-offset-4 hover:underline"
                                        >
                                            KVKK ön bilgilendirme metnini aç
                                        </a>
                                        <FieldError message={form.errors.kvkk_consent} />
                                    </label>

                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="w-fit rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {form.processing ? 'Gönderiliyor...' : 'Montaj Talebini Gönder'}
                                    </button>
                                </form>
                            )}
                        </>
                    )}
                </section>
            </main>
        </>
    );
}
