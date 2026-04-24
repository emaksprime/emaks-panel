import { Head, Link } from '@inertiajs/react';
import { ArrowRight, Database, ShieldCheck } from 'lucide-react';
import { panelIcon } from '@/lib/panel-icons';
import type {
    PanelButtonData,
    PanelDataSourceSummary,
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

export default function PanelPage({
    page,
    metrics,
    dataSources,
    permissions,
}: PanelPageProps) {
    const PageIcon = panelIcon(page.icon);

    return (
        <>
            <Head title={page.title} />

            <main className="grid gap-5 p-4 md:p-6">
                <section className="grid gap-4 rounded-lg border border-slate-200 bg-white p-5 shadow-sm lg:grid-cols-[1fr_auto]">
                    <div>
                        <div className="flex items-center gap-3">
                            <span className="grid size-11 place-items-center rounded-lg bg-slate-900 text-white">
                                <PageIcon className="size-5" />
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
                    </div>

                    <div className="flex flex-wrap items-start gap-2">
                        {page.buttons.map((button) => (
                            <ActionButton key={button.id} button={button} />
                        ))}
                    </div>
                </section>

                <section className="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
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
                </section>

                <section className="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px]">
                    <div className="rounded-lg border border-slate-200 bg-white p-5 shadow-sm">
                        <div className="mb-4 flex items-center gap-2">
                            <Database className="size-5 text-slate-500" />
                            <h2 className="font-semibold text-slate-950">Datasource registry</h2>
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
                            <h2 className="font-semibold text-slate-950">Yetki ozeti</h2>
                        </div>
                        <div className="grid gap-3">
                            <div>
                                <p className="text-sm text-slate-500">Granted resources</p>
                                <strong className="text-3xl font-semibold text-slate-950">
                                    {permissions.grantedResources}
                                </strong>
                            </div>
                            <div>
                                <p className="text-sm text-slate-500">Executable buttons</p>
                                <strong className="text-3xl font-semibold text-slate-950">
                                    {permissions.canExecuteButtons}
                                </strong>
                            </div>
                        </div>
                    </div>
                </section>
            </main>
        </>
    );
}
