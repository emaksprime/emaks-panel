import { Head, Link, usePage } from '@inertiajs/react';
import {
    ArrowRight,
    Boxes,
    Calculator,
    ChartColumn,
    ClipboardList,
    ExternalLink,
    FileText,
    Headset,
    LifeBuoy,
    UsersRound,
} from 'lucide-react';

const moduleCards = [
    {
        title: 'Satış Yönetimi',
        description: 'Satış kapsamları, müşteri ve ürün performansını takip edin.',
        candidates: ['/sales/main', '/sales/online', '/sales/bayi'],
        icon: ChartColumn,
        tone: 'from-blue-600 to-sky-500',
    },
    {
        title: 'Stok Yönetimi',
        description: 'Pozitif stokları, kritik modelleri ve ürün görünürlüğünü yönetin.',
        candidates: ['/stock', '/stock/critical'],
        icon: Boxes,
        tone: 'from-slate-800 to-slate-600',
    },
    {
        title: 'Sipariş Yönetimi',
        description: 'Alınan ve verilen açık siparişleri operasyonel sırada izleyin.',
        candidates: ['/orders/alinan', '/orders/verilen', '/orders'],
        icon: ClipboardList,
        tone: 'from-cyan-700 to-blue-500',
    },
    {
        title: 'Teknik Servis',
        description: 'Servis talepleri, teknisyenler ve operasyon dashboarduna geçin.',
        candidates: [
            '/technical-service',
            '/technical-service/dashboard',
            '/technical-service/serial-query',
            '/technical-service/technicians',
            '/technical-service/earnings',
            '/technical-service/admin',
        ],
        icon: Headset,
        tone: 'from-emerald-700 to-teal-500',
    },
    {
        title: 'Müşteri Yönetimi',
        description: 'Cari bilgi, bakiye ve ekstre ekranlarına hızlı erişim sağlayın.',
        candidates: ['/cari', '/cari/balance'],
        icon: UsersRound,
        tone: 'from-indigo-700 to-blue-500',
    },
    {
        title: 'Proforma',
        description: 'Proforma teklif, taslak ve detay akışlarını açın.',
        candidates: ['/proforma', '/proforma/create', '/proforma/detail', '/proforma/edit'],
        icon: FileText,
        tone: 'from-amber-600 to-orange-500',
    },
    {
        title: 'Muhasebe / Finans',
        description:
            'Resmi stok, fiili stok ve muhasebe kontrol farklarını izleyin.',
        candidates: ['/accounting-finance/resmi-stok-kontrol'],
        icon: Calculator,
        tone: 'from-teal-700 to-emerald-500',
    },
    {
        title: 'Destek',
        description: 'Kurulum, tuşlama ve aktivasyon bilgilerine hızlı erişin.',
        candidates: ['/support', '/support/keypad-guide', '/support/activation'],
        icon: LifeBuoy,
        tone: 'from-rose-700 to-pink-500',
    },
];

function firstVisibleHref(candidates, visibleHrefs) {
    return candidates.find((href) => visibleHrefs.has(href)) ?? null;
}

export default function DashboardHome() {
    const { auth, panelNavigation } = usePage().props;
    const visibleHrefs = new Set(
        (panelNavigation?.groups ?? []).flatMap((group) => (
            group.items ?? []
        ).map((item) => item.href)),
    );
    const visibleCards = moduleCards
        .map((card) => ({
            ...card,
            href: firstVisibleHref(card.candidates, visibleHrefs),
        }))
        .filter((card) => card.href !== null);

    return (
        <>
            <Head title="Emaks Prime Operasyon Paneli" />
            <main className="min-h-[calc(100vh-9rem)] bg-[#f3f7fb] px-4 py-5 md:px-6 md:py-8">
                <section className="relative overflow-hidden rounded-3xl border border-blue-100 bg-white shadow-sm">
                    <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_right,rgba(14,165,233,0.24),transparent_34%),radial-gradient(circle_at_bottom_left,rgba(30,64,175,0.16),transparent_30%),linear-gradient(135deg,rgba(255,255,255,0.98),rgba(239,246,255,0.94))]" />
                    <div className="absolute right-10 top-8 hidden h-28 w-28 rounded-full bg-blue-200/30 blur-2xl lg:block" />
                    <div className="absolute bottom-0 left-1/2 hidden h-20 w-72 -translate-x-1/2 rounded-full bg-sky-300/20 blur-3xl lg:block" />
                    <div className="relative p-6 md:p-8 xl:p-10">
                        <div className="min-w-0">
                            <div className="inline-flex flex-col items-center gap-2 rounded-2xl border border-blue-100 bg-white/85 px-4 py-3 shadow-sm">
                                <img
                                    src="/assets/primecrm/emaks-prime.png"
                                    alt="Emaks Prime logo"
                                    className="h-9 w-auto object-contain"
                                />
                                <span className="whitespace-nowrap text-xs font-bold tracking-[0.18em] text-blue-700">
                                    Operasyon Paneli
                                </span>
                            </div>
                            <h1 className="mt-5 max-w-4xl text-3xl font-semibold tracking-tight text-slate-950 [font-family:var(--font-display)] md:text-5xl">
                                Hoş geldiniz{auth?.user?.name ? `, ${auth.user.name}` : ''}
                            </h1>
                            <p className="mt-4 max-w-3xl text-base leading-7 text-slate-600">
                                Operasyon ekranlarına buradan hızlıca ulaşabilirsiniz.
                            </p>
                            <a
                                href="https://www.emaksprime.com"
                                target="_blank"
                                rel="noopener noreferrer"
                                className="mt-7 inline-flex items-center gap-2 rounded-full bg-slate-950 px-5 py-3 text-sm font-bold text-white shadow-lg shadow-slate-200 transition hover:-translate-y-0.5 hover:bg-blue-700 hover:shadow-blue-200"
                            >
                                Web Sitesine Git
                                <ExternalLink className="size-4" />
                            </a>
                        </div>
                    </div>
                </section>

                <section className="mt-6 grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    {visibleCards.map((card) => {
                        const Icon = card.icon;

                        return (
                            <Link
                                key={card.title}
                                href={card.href}
                                className="group relative overflow-hidden rounded-2xl border border-slate-200 bg-white p-5 shadow-sm transition hover:-translate-y-0.5 hover:border-blue-200 hover:shadow-lg hover:shadow-blue-100/70"
                            >
                                <div className={`absolute inset-x-0 top-0 h-1 bg-gradient-to-r ${card.tone}`} />
                                <div className="flex items-start justify-between gap-4">
                                    <span className={`grid size-12 shrink-0 place-items-center rounded-2xl bg-gradient-to-br ${card.tone} text-white shadow-sm`}>
                                        <Icon className="size-6" />
                                    </span>
                                    <span className="rounded-full border border-slate-200 bg-slate-50 px-3 py-1 text-xs font-bold text-slate-500 transition group-hover:border-blue-200 group-hover:bg-blue-50 group-hover:text-blue-700">
                                        Aç
                                    </span>
                                </div>
                                <h2 className="mt-5 text-xl font-bold text-slate-950">
                                    {card.title}
                                </h2>
                                <p className="mt-2 min-h-12 text-sm leading-6 text-slate-600">
                                    {card.description}
                                </p>
                                <div className="mt-5 inline-flex items-center gap-2 text-sm font-bold text-blue-700">
                                    Modüle git
                                    <ArrowRight className="size-4 transition group-hover:translate-x-1" />
                                </div>
                            </Link>
                        );
                    })}
                </section>

                {visibleCards.length === 0 && (
                    <section className="mt-6 rounded-2xl border border-amber-200 bg-amber-50 p-5 text-sm font-semibold text-amber-800">
                        Yetkili modül bulunamadı. Kullanıcı Yönetimi üzerinden erişim tanımı yapılmalıdır.
                    </section>
                )}
            </main>
        </>
    );
}
