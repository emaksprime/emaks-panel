import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Database, ShieldCheck } from 'lucide-react';
import { createElement } from 'react';
import { panelIcon } from '@/lib/panel-icons';
import type {
    PanelButtonData,
    PanelDataSourceSummary,
    PanelExternalIntegration,
    PanelMetric,
    PanelPagePayload,
} from '@/types';

type PanelPageProps = {
    page: PanelPagePayload;
    metrics: PanelMetric[];
    dataSources: PanelDataSourceSummary[];
    permissions: {
        grantedResources: number;
        canExecuteButtons: number;
    };
    integration?: PanelExternalIntegration | null;
};

function ActionButton({ button }: { button: PanelButtonData }) {
    if (button.actionType === 'navigate' && button.actionTarget) {
        return (
            <Link
                href={button.actionTarget}
                className="inline-flex items-center gap-2 rounded-md bg-slate-900 px-3 py-2 text-sm font-semibold text-white"
            >
                {button.label}
                <ArrowRight className="size-4" />
            </Link>
        );
    }

    return (
        <button
            type="button"
            onClick={() => window.location.reload()}
            disabled={!button.canExecute}
            className="inline-flex items-center gap-2 rounded-md border border-slate-200 bg-white px-3 py-2 text-sm font-semibold text-slate-700 shadow-sm disabled:opacity-50"
        >
            {button.label}
        </button>
    );
}

function PanelPageIcon({ name }: { name?: string | null }) {
    return createElement(panelIcon(name), { className: 'size-5' });
}

export default function PanelPage({
    page,
    metrics,
    dataSources,
    permissions,
    integration,
}: PanelPageProps) {
    const isModule = page.layoutType === 'module';

    return (
        <>
            <Head title={page.title} />

            <main className="grid gap-5 p-4 md:p-6">
                <section className="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[1fr_auto]">
                    <div>
                        <div className="flex items-center gap-3">
                            <span className="grid size-11 place-items-center rounded-lg bg-slate-900 text-white">
                                <PanelPageIcon name={page.icon} />
                            </span>
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    {page.heroEyebrow ?? page.routePath}
                                </p>
                                <h1 className="text-2xl font-semibold text-slate-950 [font-family:var(--font-display)]">
                                    {page.title}
                                </h1>
                            </div>
                        </div>
                        <p className="mt-4 max-w-3xl text-sm leading-6 text-slate-600">
                            {page.description}
                        </p>
                        {isModule && (
                            <div className="mt-4 inline-flex rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                                Canlı veri kaynağı henüz bağlanmadı.
                            </div>
                        )}
                    </div>

                    <div className="flex flex-wrap items-start gap-2">
                        {page.buttons.map((button) => (
                            <ActionButton key={button.id} button={button} />
                        ))}
                    </div>
                </section>

                {!isModule && <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
                    {metrics.map((metric) => (
                        <article key={metric.label} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                {metric.label}
                            </p>
                            <strong className="mt-3 block text-2xl font-semibold text-slate-950">
                                {metric.value}
                            </strong>
                            <p className="mt-2 text-sm text-slate-500">{metric.hint}</p>
                        </article>
                    ))}
                </section>}

                {isModule && (
                    <>
                        {page.moduleTabs && page.moduleTabs.length > 0 && (
                            <section className="flex flex-wrap gap-2 rounded-lg border border-slate-200 bg-white p-3 shadow-sm">
                                {page.moduleTabs.map((tab) => {
                                    const active = tab.href === page.routePath;

                                    return (
                                        <Link
                                            key={`${tab.label}-${tab.href}`}
                                            href={tab.href}
                                            className={[
                                                'rounded-full border px-4 py-2 text-sm font-semibold transition',
                                                active
                                                    ? 'border-slate-950 bg-slate-950 text-white'
                                                    : 'border-slate-200 bg-slate-50 text-slate-600 hover:border-slate-400 hover:text-slate-950',
                                            ].join(' ')}
                                        >
                                            {tab.label}
                                        </Link>
                                    );
                                })}
                            </section>
                        )}

                        <section className="rounded-lg border border-slate-200 bg-white p-6 shadow-sm">
                            <div className="flex flex-wrap items-center gap-3">
                                <h2 className="text-lg font-semibold text-slate-950">Veri bağlantısı bekleniyor</h2>
                                <span className="rounded-full border border-amber-200 bg-amber-50 px-3 py-1 text-xs font-semibold text-amber-700">
                                    {page.previewNotice ?? 'Canlı veri kaynağı henüz bağlanmadı'}
                                </span>
                            </div>
                            <p className="mt-2 max-w-2xl text-sm leading-6 text-slate-600">
                                Bu modül için canlı veri kaynağı henüz bağlanmadı. Endpoint ve metadata tanımı
                                Yönetim Paneli altındaki Veri Kaynakları ekranından hazırlanacak.
                            </p>
                        </section>

                        {integration && (
                            <section className="grid gap-4 rounded-lg border border-slate-200 bg-white p-6 shadow-sm lg:grid-cols-[1fr_auto] lg:items-center">
                                <div>
                                    <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                        Harici Servis Entegrasyonu
                                    </p>
                                    <h2 className="mt-2 text-lg font-semibold text-slate-950">
                                        PrimeCRM bağlantısı
                                    </h2>
                                    <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600">
                                        {integration.message} Bu ekran Laravel içine gömülmedi; Coolify üzerindeki ayrı PrimeCRM servisine yönlendirme ve ileride API köprüsü için hazırlanmıştır.
                                    </p>
                                    <div className="mt-3 flex flex-wrap gap-2 text-xs font-semibold text-slate-600">
                                        <span className="rounded-full bg-slate-100 px-3 py-1">Sağlayıcı: {integration.provider}</span>
                                        <span className="rounded-full bg-slate-100 px-3 py-1">Yol: {integration.path}</span>
                                        {integration.capability && <span className="rounded-full bg-slate-100 px-3 py-1">Yetki: {integration.capability}</span>}
                                    </div>
                                </div>
                                {integration.enabled && integration.externalUrl ? (
                                    <a
                                        href={integration.externalUrl}
                                        target="_blank"
                                        rel="noreferrer"
                                        className="inline-flex justify-center rounded-xl bg-slate-950 px-4 py-3 text-sm font-semibold text-white"
                                    >
                                        PrimeCRM ekranını aç
                                    </a>
                                ) : (
                                    <span className="rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm font-semibold text-amber-700">
                                        {integration.externalUrl ? 'PRIMECRM_ENABLED bekleniyor' : 'PRIMECRM_BASE_URL bekleniyor'}
                                    </span>
                                )}
                            </section>
                        )}
                    </>
                )}

                {!isModule && <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="mb-4 flex items-center gap-2">
                            <Database className="size-5 text-slate-500" />
                            <h2 className="font-semibold text-slate-950">Veri Kaynakları</h2>
                        </div>
                        <div className="grid gap-2">
                            {dataSources.map((source) => (
                                <div key={source.id} className="grid gap-1 rounded-md border border-slate-200 p-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <strong className="text-slate-950">{source.name}</strong>
                                        <span className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">
                                            {source.status}
                                        </span>
                                    </div>
                                    <p className="text-sm text-slate-500">
                                        {source.driver} - {source.database ?? 'metadata'}
                                    </p>
                                </div>
                            ))}
                        </div>
                    </div>

                    <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="mb-4 flex items-center gap-2">
                            <ShieldCheck className="size-5 text-slate-500" />
                            <h2 className="font-semibold text-slate-950">Yetki özeti</h2>
                        </div>
                        <div className="grid gap-3">
                            <div>
                                <p className="text-sm text-slate-500">Tanımlı kaynaklar</p>
                                <strong className="text-3xl font-semibold text-slate-950">
                                    {permissions.grantedResources}
                                </strong>
                            </div>
                            <div>
                                <p className="text-sm text-slate-500">Çalıştırılabilir butonlar</p>
                                <strong className="text-3xl font-semibold text-slate-950">
                                    {permissions.canExecuteButtons}
                                </strong>
                            </div>
                        </div>
                    </div>
                </section>}
            </main>
        </>
    );
}
