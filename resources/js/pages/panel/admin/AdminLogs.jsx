import { useEffect, useState } from 'react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

export default function AdminLogs() {
    const [data, setData] = useState({ logs: [] });

    useEffect(() => {
        apiRequest('/api/admin/logs').then(setData);
    }, []);

    return (
        <AdminFrame title="Sistem Kayıtları">
            <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                <table className="w-full min-w-[760px] text-sm">
                    <thead className="bg-slate-50 text-left text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                        <tr>
                            <th className="px-4 py-3">Tarih</th>
                            <th className="px-4 py-3">Kullanıcı</th>
                            <th className="px-4 py-3">İşlem</th>
                            <th className="px-4 py-3">Veri</th>
                        </tr>
                    </thead>
                    <tbody>
                        {data.logs.map((log) => (
                            <tr key={log.id} className="border-t border-slate-100">
                                <td className="px-4 py-3 text-slate-500">{log.created_at}</td>
                                <td className="px-4 py-3">{log.user_id ?? '-'}</td>
                                <td className="px-4 py-3 font-semibold text-slate-950">{log.action}</td>
                                <td className="px-4 py-3 font-mono text-xs text-slate-500">{JSON.stringify(log.payload ?? {})}</td>
                            </tr>
                        ))}
                    </tbody>
                </table>
            </div>
        </AdminFrame>
    );
}
