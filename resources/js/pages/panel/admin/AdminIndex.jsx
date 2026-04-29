import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

export default function AdminIndex() {
    const [data, setData] = useState(null);

    useEffect(() => {
        apiRequest('/api/admin/overview').then(setData);
    }, []);

    const counts = data?.counts ?? {};
    const urls = data?.urls ?? {};
    const workflowUrls = urls.workflowUrls ?? {};
    const labels = {
        admin_panel: 'Yönetim',
        sales_main: 'Satış',
        sales_online: 'Online / Perakende',
        sales_bayi: 'Bayi / Proje',
        customers: 'Müşteri Yönetimi',
        orders_alinan: 'Alınan Siparişler',
        orders_verilen: 'Verilen Siparişler',
        finance_cari_durum: 'Müşteri Durumu',
        stock: 'Stok',
        admin_users: 'Kullanıcı Yönetimi',
        admin_pages: 'Sayfalar',
        admin_datasources: 'Veri Kaynakları',
        admin_logs: 'Sistem Kayıtları',
    };

    return (
        <AdminFrame title="Yönetim Paneli">
            <section className="grid gap-3 md:grid-cols-4">
                {Object.entries(counts).map(([key, value]) => (
                    <article key={key} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{labels[key] ?? key}</p>
                        <strong className="mt-3 block text-3xl font-semibold text-slate-950">{value}</strong>
                    </article>
                ))}
            </section>

            <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                        Üretim URL kayıtları
                    </p>
                    <h2 className="mt-1 text-xl font-semibold text-slate-950">
                        Workflow ve API adresleri
                    </h2>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                        Bu adresler Coolify üzerindeki HTTPS domain ile üretilir. İleride n8n/data workflow webhookları bu
                        registry üzerinden bağlanacak.
                    </p>
                </div>

                <div className="grid gap-3 lg:grid-cols-3">
                    {[
                        ['Açık URL', urls.publicUrl],
                        ['API Temel URL', urls.apiBaseUrl],
                        ['Webhook Temel URL', urls.webhookBaseUrl],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                            <code className="mt-2 block break-all text-sm font-semibold text-slate-800">
                                {value ?? 'Henüz ayarlanmadı'}
                            </code>
                        </div>
                    ))}
                </div>

                <div className="grid gap-2">
                    {Object.entries(workflowUrls).map(([key, value]) => (
                        <div key={key} className="grid gap-2 rounded-xl border border-slate-200 p-4 md:grid-cols-[180px_1fr]">
                            <span className="text-sm font-semibold text-slate-700">{key}</span>
                            <code className="break-all text-sm text-slate-600">{value}</code>
                        </div>
                    ))}
                </div>
            </section>
        </AdminFrame>
    );
}
