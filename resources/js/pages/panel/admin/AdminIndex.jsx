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

    return (
        <AdminFrame title="Admin Panel">
            <section className="grid gap-3 md:grid-cols-4">
                {Object.entries(counts).map(([key, value]) => (
                    <article key={key} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                        <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">{key}</p>
                        <strong className="mt-3 block text-3xl font-semibold text-slate-950">{value}</strong>
                    </article>
                ))}
            </section>

            <section className="grid gap-4 rounded-2xl border border-slate-200 bg-white p-5 shadow-sm">
                <div>
                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                        Production URL registry
                    </p>
                    <h2 className="mt-1 text-xl font-semibold text-slate-950">
                        Workflow ve API adresleri
                    </h2>
                    <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-500">
                        Bu adresler Coolify uzerindeki HTTPS domain ile uretilir. Ileride n8n/data workflow webhooklari bu
                        registry uzerinden baglanacak.
                    </p>
                </div>

                <div className="grid gap-3 lg:grid-cols-3">
                    {[
                        ['Public URL', urls.publicUrl],
                        ['API Base', urls.apiBaseUrl],
                        ['Webhook Base', urls.webhookBaseUrl],
                    ].map(([label, value]) => (
                        <div key={label} className="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
                            <code className="mt-2 block break-all text-sm font-semibold text-slate-800">
                                {value ?? 'not configured'}
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
