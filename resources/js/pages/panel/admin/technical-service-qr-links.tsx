import { Head } from '@inertiajs/react';
import { useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

type SerialContext = {
    serial_number: string;
    product_name: string | null;
    product_model: string | null;
    brand: string | null;
    activation_code: string | null;
    sale_mount_status: string;
    suggested_link_type: string;
};

type CreatedLink = {
    public_url: string;
    path: string;
    token: string;
    context: SerialContext;
    link: {
        id: number;
        serial_number: string;
        product_name: string;
        product_model?: string | null;
        brand?: string | null;
        link_type: string;
        status: string;
    };
};

const linkTypeLabels: Record<string, string> = {
    pre_sale_product: 'Satılmamış ürün / ön baskı',
    sold_product: 'Satılmış ürün',
    manual_test: 'Test linki',
};

const saleMountStatusLabels: Record<string, string> = {
    unknown: 'Bilinmiyor',
    not_found: 'Seri bulunamadı',
    montaj_dahil: 'Montaj dahil',
    montaj_sonradan_dahil: 'Montaj sonradan dahil',
    montaj_haric: 'Montaj hariç',
    check_failed: 'Kontrol tamamlanamadı',
};

function ReadOnlyField({ label, value }: { label: string; value?: string | null }) {
    return (
        <div className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className="mt-1 min-h-5 text-sm font-semibold text-slate-950">{value || '-'}</p>
        </div>
    );
}

export default function TechnicalServiceQrLinks() {
    const [serialNumber, setSerialNumber] = useState('');
    const [context, setContext] = useState<SerialContext | null>(null);
    const [created, setCreated] = useState<CreatedLink | null>(null);
    const [status, setStatus] = useState<{ type: 'idle' | 'loading' | 'success' | 'error'; message: string }>({
        type: 'idle',
        message: '',
    });

    const resolveContext = async () => {
        const serial = serialNumber.trim();

        if (!serial) {
            setStatus({ type: 'error', message: 'Seri No zorunludur.' });
            setContext(null);
            setCreated(null);

            return;
        }

        setStatus({ type: 'loading', message: 'Seri bağlamı çözülüyor...' });
        setCreated(null);

        try {
            const params = new URLSearchParams({ serial_number: serial });
            const response = await apiRequest(`/api/admin/technical-service/serial-context?${params.toString()}`) as { context: SerialContext };

            setContext(response.context);
            setStatus({ type: 'success', message: 'Seri bağlamı çözüldü.' });
        } catch (error) {
            setContext(null);
            setStatus({
                type: 'error',
                message: error instanceof Error ? error.message : 'Seri bağlamı çözülemedi. Ürün bilgisi alınamadı.',
            });
        }
    };

    const createLink = async () => {
        if (!context?.product_name) {
            setStatus({ type: 'error', message: 'Seri bağlamı çözülmeden QR link oluşturulamaz.' });

            return;
        }

        setStatus({ type: 'loading', message: 'QR link oluşturuluyor...' });

        try {
            const response = await apiRequest('/api/admin/technical-service/qr-links', {
                method: 'POST',
                body: JSON.stringify({ serial_number: context.serial_number }),
            }) as CreatedLink;

            setCreated(response);
            setContext(response.context);
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
                        Seri No girilir; ürün adı, model, marka, aktivasyon kodu, montaj durumu ve link tipi sistem tarafından çözülür.
                    </p>
                </div>

                <div className="grid gap-3 lg:grid-cols-[minmax(0,1fr)_auto]">
                    <label className="grid gap-1 text-sm font-semibold text-slate-700">
                        Seri No
                        <input
                            value={serialNumber}
                            onChange={(event) => {
                                setSerialNumber(event.target.value);
                                setContext(null);
                                setCreated(null);
                            }}
                            className="rounded-lg border border-slate-200 px-3 py-2 font-normal text-slate-900 shadow-sm"
                        />
                    </label>

                    <div className="flex items-end">
                        <button
                            type="button"
                            onClick={resolveContext}
                            disabled={status.type === 'loading'}
                            className="inline-flex rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            Seri bağlamını çöz
                        </button>
                    </div>
                </div>

                {context && (
                    <div className="grid gap-3 rounded-xl border border-blue-100 bg-blue-50 p-4">
                        <p className="text-sm font-semibold text-blue-950">Çözülen seri bağlamı</p>
                        <div className="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                            <ReadOnlyField label="Ürün adı" value={context.product_name} />
                            <ReadOnlyField label="Model" value={context.product_model} />
                            <ReadOnlyField label="Marka" value={context.brand} />
                            <ReadOnlyField label="Aktivasyon kodu" value={context.activation_code} />
                            <ReadOnlyField label="Montaj durumu" value={saleMountStatusLabels[context.sale_mount_status] ?? context.sale_mount_status} />
                            <ReadOnlyField label="Link tipi" value={linkTypeLabels[context.suggested_link_type] ?? context.suggested_link_type} />
                        </div>
                        <button
                            type="button"
                            onClick={createLink}
                            disabled={status.type === 'loading' || !context.product_name}
                            className="w-fit rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white shadow-sm transition hover:bg-blue-800 disabled:cursor-not-allowed disabled:opacity-60"
                        >
                            QR Link Oluştur
                        </button>
                    </div>
                )}

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
