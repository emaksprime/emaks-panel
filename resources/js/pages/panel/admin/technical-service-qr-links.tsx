import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

const linkTypes = [
    ['pre_sale_product', 'Satılmamış ürün'],
    ['sold_product', 'Satılmış ürün'],
    ['manual_test', 'Manuel test'],
];

type CreatedLink = {
    public_url: string;
    path: string;
    token: string;
    link: {
        id: number;
        serial_number: string;
        product_name: string;
        product_model?: string | null;
        brand: string;
        link_type: string;
        status: string;
    };
};

export default function TechnicalServiceQrLinks() {
    const [form, setForm] = useState({
        serial_number: '',
        product_name: '',
        product_model: '',
        brand: '',
        link_type: 'pre_sale_product',
    });
    const [created, setCreated] = useState<CreatedLink | null>(null);
    const [status, setStatus] = useState<{ type: 'idle' | 'loading' | 'success' | 'error'; message: string }>({
        type: 'idle',
        message: '',
    });

    const update = (key: string, value: string) => {
        setForm((current) => ({ ...current, [key]: value }));
    };

    const createLink = async (event: React.FormEvent<HTMLFormElement>) => {
        event.preventDefault();
        setStatus({ type: 'loading', message: 'QR link oluşturuluyor...' });

        try {
            const response = await apiRequest('/api/admin/technical-service/qr-links', {
                method: 'POST',
                body: JSON.stringify(form),
            }) as CreatedLink;

            setCreated(response);
            setStatus({ type: 'success', message: 'QR link oluşturuldu.' });
        } catch (error) {
            setStatus({
                type: 'error',
                message: error instanceof Error ? error.message : 'QR link oluşturulamadı.',
            });
        }
    };

    const copyUrl = async () => {
        if (!created?.public_url) {
            return;
        }

        await navigator.clipboard?.writeText(created.public_url);
        setStatus({ type: 'success', message: 'Public URL kopyalandı.' });
    };

    return (
        <AdminFrame title="Teknik Servis QR Linkleri">
            <Head title="Teknik Servis QR Linkleri" />

            <section className="grid gap-5 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                        QR / Link Üretimi
                    </p>
                    <h2 className="mt-1 text-xl font-semibold text-slate-950">
                        Teknik Servis QR Linkleri
                    </h2>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                        Satılmamış ürün kutu linkleri için ürün adı, seri no, marka ve isteğe bağlı model bilgisi girilir.
                        Token sadece bu ekranda gösterilir; veritabanında hash tutulur.
                    </p>
                </div>

                <form onSubmit={createLink} className="grid gap-4 lg:grid-cols-2">
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Seri No
                        <input
                            value={form.serial_number}
                            onChange={(event) => update('serial_number', event.target.value)}
                            required
                            className="rounded-lg border border-slate-200 px-3 py-2 font-normal text-slate-900 shadow-sm"
                        />
                    </label>

                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Ürün Adı
                        <input
                            value={form.product_name}
                            onChange={(event) => update('product_name', event.target.value)}
                            required
                            className="rounded-lg border border-slate-200 px-3 py-2 font-normal text-slate-900 shadow-sm"
                        />
                    </label>

                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Model
                        <input
                            value={form.product_model}
                            onChange={(event) => update('product_model', event.target.value)}
                            className="rounded-lg border border-slate-200 px-3 py-2 font-normal text-slate-900 shadow-sm"
                        />
                    </label>

                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Marka
                        <input
                            value={form.brand}
                            onChange={(event) => update('brand', event.target.value)}
                            required
                            className="rounded-lg border border-slate-200 px-3 py-2 font-normal text-slate-900 shadow-sm"
                        />
                    </label>

                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Link tipi
                        <select
                            value={form.link_type}
                            onChange={(event) => update('link_type', event.target.value)}
                            className="rounded-lg border border-slate-200 px-3 py-2 font-normal text-slate-900 shadow-sm"
                        >
                            {linkTypes.map(([value, label]) => (
                                <option key={value} value={value}>
                                    {label}
                                </option>
                            ))}
                        </select>
                    </label>

                    <div className="flex items-end">
                        <button
                            type="submit"
                            disabled={status.type === 'loading'}
                            className="inline-flex rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            QR Link Oluştur
                        </button>
                    </div>
                </form>

                {status.message && (
                    <p className={status.type === 'error' ? 'text-sm font-semibold text-red-700' : 'text-sm font-semibold text-emerald-700'}>
                        {status.message}
                    </p>
                )}
            </section>

            {created && (
                <section className="grid gap-4 rounded-2xl border border-emerald-200 bg-emerald-50 p-5 shadow-sm">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-emerald-700">
                            Oluşturulan public URL
                        </p>
                        <code className="mt-2 block break-all rounded-lg border border-emerald-200 bg-white px-3 py-2 text-sm font-semibold text-slate-900">
                            {created.public_url}
                        </code>
                    </div>

                    <div className="flex flex-wrap gap-2">
                        <button
                            type="button"
                            onClick={copyUrl}
                            className="rounded-lg border border-emerald-200 bg-white px-4 py-2 text-sm font-semibold text-emerald-800 shadow-sm hover:bg-emerald-100"
                        >
                            Kopyala
                        </button>
                        <a
                            href={created.path}
                            target="_blank"
                            rel="noreferrer"
                            className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800"
                        >
                            Müşteri gibi aç
                        </a>
                    </div>
                </section>
            )}
        </AdminFrame>
    );
}
