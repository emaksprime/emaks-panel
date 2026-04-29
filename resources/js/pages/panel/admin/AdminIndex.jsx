import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

const labels = {
    dashboard: 'Genel',
    sales_main: 'Satış',
    sales_online: 'Online / Perakende',
    sales_bayi: 'Bayi / Proje',
    stock: 'Stok',
    finance_cari_durum: 'Müşteri Durumu',
    orders: 'Siparişler',
    admin_panel: 'Yönetim',
    admin_users: 'Kullanıcı Yönetimi',
    admin_pages: 'Sayfalar',
    admin_datasources: 'Veri Kaynakları',
    admin_logs: 'Sistem Kayıtları',
};

export default function AdminIndex() {
    const [data, setData] = useState(null);

    useEffect(() => {
        apiRequest('/api/admin/overview').then(setData);
    }, []);

    const counts = data?.counts ?? {};

    return (
        <AdminFrame title="Yönetim Paneli">
            <section className="grid gap-3 md:grid-cols-4">
                {Object.entries(counts).map(([key, value]) => (
                    <article key={key} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{labels[key] ?? key}</p>
                        <strong className="mt-3 block text-3xl font-semibold text-slate-950">{value}</strong>
                    </article>
                ))}
            </section>
        </AdminFrame>
    );
}
