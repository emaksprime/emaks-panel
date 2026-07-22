import { Power, ShieldCheck, TriangleAlert } from 'lucide-react';
import { useCallback, useEffect, useRef, useState } from 'react';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

async function responseErrorMessage(response, fallback) {
    try {
        const payload = await response.json();
        const validation = Object.values(payload.errors ?? {}).flatMap((value) => (
            Array.isArray(value) ? value : [value]
        ));

        return validation[0] ?? payload.message ?? fallback;
    } catch {
        return fallback;
    }
}

const formatChangedAt = (value) => {
    if (!value) {
        return 'Henüz değiştirilmedi';
    }

    const date = new Date(value);

    return Number.isNaN(date.getTime())
        ? 'Tarih doğrulanamadı'
        : date.toLocaleString('tr-TR');
};

export default function AdminIndex() {
    const [data, setData] = useState(null);
    const [executionControl, setExecutionControl] = useState(null);
    const [executionControlLoading, setExecutionControlLoading] = useState(true);
    const [executionControlSaving, setExecutionControlSaving] = useState(false);
    const [executionControlMessage, setExecutionControlMessage] = useState(null);
    const [executionControlDialogOpen, setExecutionControlDialogOpen] = useState(false);
    const [executionControlTarget, setExecutionControlTarget] = useState('live');
    const [executionControlReason, setExecutionControlReason] = useState('');
    const [executionControlConfirmation, setExecutionControlConfirmation] = useState('');
    const [executionControlExpectedRevision, setExecutionControlExpectedRevision] = useState(null);
    const executionControlBusyRef = useRef(false);

    const loadExecutionControl = useCallback(async (clearMessage = true) => {
        setExecutionControlLoading(true);

        if (clearMessage) {
            setExecutionControlMessage(null);
        }

        try {
            const response = await fetch('/api/technical-service/execution-control', {
                method: 'GET',
                headers: { Accept: 'application/json' },
                credentials: 'same-origin',
            });

            if (!response.ok) {
                setExecutionControlMessage(await responseErrorMessage(response, 'Sistem çalışma modu alınamadı.'));

                return null;
            }

            const payload = await response.json();
            setExecutionControl(payload.execution_control);

            return payload.execution_control;
        } catch {
            setExecutionControlMessage('Sistem çalışma modu alınamadı; otomatik tekrar yapılmadı.');

            return null;
        } finally {
            setExecutionControlLoading(false);
        }
    }, []);

    useEffect(() => {
        apiRequest('/api/admin/overview').then(setData);
        void Promise.resolve().then(() => loadExecutionControl());
    }, [loadExecutionControl]);

    const resetExecutionControlDialog = useCallback(() => {
        setExecutionControlDialogOpen(false);
        setExecutionControlExpectedRevision(null);
        setExecutionControlReason('');
        setExecutionControlConfirmation('');
    }, []);

    const openExecutionControlDialog = async () => {
        if (executionControlBusyRef.current || !executionControl?.can_transition) {
            return;
        }

        const target = executionControl.mode === 'live' ? 'local' : 'live';
        setExecutionControlTarget(target);
        setExecutionControlReason('');
        setExecutionControlConfirmation('');
        setExecutionControlExpectedRevision(null);
        setExecutionControlDialogOpen(true);
        const current = await loadExecutionControl();

        if (current !== null) {
            setExecutionControlTarget(current.mode === 'live' ? 'local' : 'live');
            setExecutionControlExpectedRevision(current.revision);
        }
    };

    const transitionExecutionControl = async () => {
        if (executionControlBusyRef.current || executionControlExpectedRevision === null) {
            return;
        }

        executionControlBusyRef.current = true;
        setExecutionControlSaving(true);
        setExecutionControlMessage(null);

        try {
            const token = document.querySelector('meta[name="csrf-token"]')?.content ?? '';
            const response = await fetch('/api/technical-service/execution-control', {
                method: 'POST',
                headers: {
                    Accept: 'application/json',
                    'Content-Type': 'application/json',
                    ...(token ? { 'X-CSRF-TOKEN': token } : {}),
                },
                credentials: 'same-origin',
                body: JSON.stringify({
                    mode: executionControlTarget,
                    reason: executionControlReason.trim(),
                    confirmation: executionControlTarget === 'live' ? executionControlConfirmation : undefined,
                    expected_revision: executionControlExpectedRevision,
                }),
            });

            if (response.status === 409) {
                await loadExecutionControl(false);
                resetExecutionControlDialog();
                setExecutionControlMessage('Sistem çalışma modu başka bir yönetici tarafından değiştirildi. Güncel durum yüklendi; kararınızı yeniden verin.');

                return;
            }

            if (!response.ok) {
                setExecutionControlMessage(await responseErrorMessage(response, 'Sistem çalışma modu değiştirilemedi.'));

                return;
            }

            const payload = await response.json();
            setExecutionControl(payload.execution_control);
            resetExecutionControlDialog();
            setExecutionControlMessage(payload.message ?? 'Sistem çalışma modu güncellendi.');
        } catch {
            setExecutionControlMessage('Sistem çalışma modu değiştirilemedi; otomatik tekrar yapılmadı.');
        } finally {
            executionControlBusyRef.current = false;
            setExecutionControlSaving(false);
        }
    };

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
    const executionState = executionControl?.state ?? 'local';
    const executionIsLive = executionControl?.mode === 'live';
    const transitionBlocked = ['activating', 'freezing', 'blocked'].includes(executionState);

    return (
        <AdminFrame title="Yönetim Paneli">
            <section
                data-testid="global-execution-control"
                className="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[1fr_auto] lg:items-center"
            >
                <div className="min-w-0">
                    <div className="flex flex-wrap items-center gap-2">
                        <ShieldCheck className="size-5 text-slate-800" />
                        <h2 className="text-lg font-semibold text-slate-950">Sistem Çalışma Modu</h2>
                        <span
                            className={[
                                'inline-flex min-w-16 items-center justify-center rounded-full border px-2.5 py-1 text-xs font-semibold',
                                executionIsLive
                                    ? 'border-emerald-300 bg-emerald-50 text-emerald-800'
                                    : 'border-slate-300 bg-slate-50 text-slate-700',
                            ].join(' ')}
                        >
                            {executionControlLoading ? 'YÜKLENİYOR' : executionState.toLocaleUpperCase('tr-TR')}
                        </span>
                    </div>

                    <dl className="mt-4 grid gap-3 text-sm sm:grid-cols-2 xl:grid-cols-5">
                        <div>
                            <dt className="text-xs font-medium text-slate-500">Runtime</dt>
                            <dd className="mt-1 font-semibold text-slate-900">
                                {executionControl?.runtime_environment_label ?? 'Doğrulanıyor'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-slate-500">Epoch / revision</dt>
                            <dd className="mt-1 font-semibold text-slate-900">
                                {executionControl?.epoch ?? '—'} / {executionControl?.revision ?? '—'}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-slate-500">Required capability</dt>
                            <dd className="mt-1 font-semibold text-slate-900">
                                {executionControl?.readiness.required_ready_count ?? 0}/{executionControl?.readiness.required_count ?? 0} hazır
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-slate-500">Blocker</dt>
                            <dd className="mt-1 font-semibold text-slate-900">
                                {executionControl?.readiness.blocker_count ?? 0}
                            </dd>
                        </div>
                        <div>
                            <dt className="text-xs font-medium text-slate-500">Son değişiklik</dt>
                            <dd className="mt-1 font-semibold text-slate-900">
                                {formatChangedAt(executionControl?.changed_at)}
                            </dd>
                        </div>
                    </dl>

                    {executionControlMessage ? (
                        <p className="mt-3 flex items-start gap-2 text-sm font-medium text-amber-800" role="status">
                            <TriangleAlert className="mt-0.5 size-4 shrink-0" />
                            <span>{executionControlMessage}</span>
                        </p>
                    ) : null}
                </div>

                <div className="flex flex-col items-stretch gap-2 sm:flex-row lg:flex-col">
                    {executionControl?.can_transition ? (
                        <Button
                            type="button"
                            data-testid="global-execution-control-action"
                            variant={executionIsLive ? 'outline' : 'default'}
                            disabled={executionControlLoading || executionControlSaving || transitionBlocked}
                            onClick={() => {
                                void openExecutionControlDialog();
                            }}
                        >
                            <Power className="mr-2 size-4" />
                            {executionIsLive ? 'Lokale Al' : 'Canlıya Geç'}
                        </Button>
                    ) : (
                        <p className="max-w-64 text-xs leading-5 text-slate-500">
                            Değişiklik için Teknik Servis Admin yetkisi gerekir.
                        </p>
                    )}
                </div>
            </section>

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

            <Dialog
                open={executionControlDialogOpen}
                onOpenChange={(open) => {
                    if (executionControlSaving) {
                        return;
                    }

                    if (open) {
                        setExecutionControlDialogOpen(true);
                    } else {
                        resetExecutionControlDialog();
                    }
                }}
            >
                <DialogContent data-testid="global-execution-control-dialog" className="max-h-[90vh] overflow-y-auto sm:max-w-xl">
                    <DialogHeader>
                        <DialogTitle>
                            {executionControlTarget === 'live' ? 'Sistemi canlı etkilere hazırla' : 'Sistemi Lokal moda al'}
                        </DialogTitle>
                        <DialogDescription>
                            Bu karar bütün dış etki capability’lerinin global otoritesini değiştirir. Provider profilleri,
                            credential veya APP_ENV bu işlemle değiştirilmez.
                        </DialogDescription>
                    </DialogHeader>

                    <div className="grid gap-4">
                        <dl className="grid grid-cols-2 gap-x-4 gap-y-3 border-y border-slate-200 py-3 text-sm">
                            <div>
                                <dt className="text-xs font-medium text-slate-500">Runtime</dt>
                                <dd className="mt-1 font-semibold text-slate-900">
                                    {executionControl?.runtime_environment_label ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-slate-500">Epoch / revision</dt>
                                <dd className="mt-1 font-semibold text-slate-900">
                                    {executionControl?.epoch ?? '—'} / {executionControlExpectedRevision ?? '—'}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-slate-500">Required readiness</dt>
                                <dd className="mt-1 font-semibold text-slate-900">
                                    {executionControl?.readiness.required_ready_count ?? 0}/{executionControl?.readiness.required_count ?? 0}
                                </dd>
                            </div>
                            <div>
                                <dt className="text-xs font-medium text-slate-500">Exact blocker</dt>
                                <dd className="mt-1 font-semibold text-slate-900">
                                    {executionControl?.readiness.blocker_count ?? 0}
                                </dd>
                            </div>
                        </dl>

                        {executionControlTarget === 'live' && (executionControl?.readiness.blockers.length ?? 0) > 0 ? (
                            <div className="border-l-4 border-amber-600 bg-amber-50 px-4 py-3 text-sm text-amber-950">
                                <p className="font-semibold">Canlı aktivasyon fail-closed durumda</p>
                                <ul className="mt-2 space-y-1.5">
                                    {executionControl?.readiness.blockers.map((blocker) => (
                                        <li key={blocker.code}>
                                            <span className="font-mono text-xs">{blocker.code}</span>: {blocker.message}
                                        </li>
                                    ))}
                                </ul>
                            </div>
                        ) : null}

                        <p className="border-l-4 border-slate-400 bg-slate-50 px-4 py-3 text-sm leading-6 text-slate-700">
                            {executionControlTarget === 'live'
                                ? 'Canlı geçiş yalnız bütün REQUIRED capability’ler adapted ve ready ise atomik tamamlanır. Non-production ortamında broad provider trafiği yine kapalıdır.'
                                : 'Lokal geçiş yeni enqueue, claim ve transport yetkisini keser; eski veya belirsiz işleri yeniden göndermez.'}
                        </p>

                        <label className="grid gap-1.5 text-sm font-semibold text-slate-800">
                            Değişiklik gerekçesi
                            <textarea
                                value={executionControlReason}
                                onChange={(event) => setExecutionControlReason(event.target.value)}
                                rows={3}
                                maxLength={500}
                                disabled={executionControlSaving}
                                placeholder="En az 10 karakterlik operasyon gerekçesi"
                                className="min-h-24 resize-y rounded-md border border-slate-300 bg-white px-3 py-2 text-sm font-normal text-slate-900 focus:border-slate-500 focus:ring-2 focus:ring-slate-200 focus:outline-none disabled:opacity-60"
                            />
                        </label>

                        {executionControlTarget === 'live' ? (
                            <label className="grid gap-1.5 text-sm font-semibold text-slate-800">
                                Onay metni
                                <Input
                                    value={executionControlConfirmation}
                                    onChange={(event) => setExecutionControlConfirmation(event.target.value)}
                                    disabled={executionControlSaving}
                                    autoComplete="off"
                                    placeholder="CANLI MODU AÇ"
                                />
                            </label>
                        ) : null}
                    </div>

                    <DialogFooter>
                        <Button type="button" variant="outline" disabled={executionControlSaving} onClick={resetExecutionControlDialog}>
                            Vazgeç
                        </Button>
                        <Button
                            type="button"
                            data-testid="global-execution-control-confirm"
                            disabled={
                                executionControlSaving
                                || executionControlExpectedRevision === null
                                || executionControlReason.trim().length < 10
                                || (executionControlTarget === 'live'
                                    && (!executionControl?.readiness.eligible || executionControlConfirmation !== 'CANLI MODU AÇ'))
                            }
                            onClick={() => {
                                void transitionExecutionControl();
                            }}
                        >
                            {executionControlSaving ? 'Uygulanıyor' : executionControlTarget === 'live' ? 'Canlıya geç' : 'Lokale al'}
                        </Button>
                    </DialogFooter>
                </DialogContent>
            </Dialog>
        </AdminFrame>
    );
}
