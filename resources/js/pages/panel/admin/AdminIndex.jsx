import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

export default function AdminIndex() {
    const [data, setData] = useState(null);

    useEffect(() => {
        apiRequest('/api/admin/overview').then(setData);
    }, []);

    const counts = data?.counts ?? {};

    return (
        <AdminFrame title="Admin Panel">
            <section className="grid gap-3 md:grid-cols-4">
                {Object.entries(counts).map(([key, value]) => (
                    <article key={key} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{key}</p>
                        <strong className="mt-3 block text-3xl font-semibold text-slate-950">{value}</strong>
                    </article>
                ))}
            </section>
        </AdminFrame>
    );
}
