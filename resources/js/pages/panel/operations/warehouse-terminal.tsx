import { Head } from '@inertiajs/react';
import {
    BadgeCheck,
    Boxes,
    ClipboardList,
    FileCheck2,
    Layers3,
    ReceiptText,
    RotateCcw,
    Search,
    ShieldCheck,
    Truck,
} from 'lucide-react';
import type { LucideIcon } from 'lucide-react';

type TerminalCard = {
    title: string;
    status: 'Pilot' | 'Hazırlanıyor';
    description: string;
    Icon: LucideIcon;
};

const terminalCards: TerminalCard[] = [
    {
        title: 'Raf Transferi',
        status: 'Pilot',
        description: 'Kaynak raf, ürün/seri ve hedef raf okutularak transfer yapılacak.',
        Icon: RotateCcw,
    },
    {
        title: 'Seri / Raf Sorgu',
        status: 'Hazırlanıyor',
        description: 'Seri numarasının güncel depo/raf konumu sorgulanacak.',
        Icon: Search,
    },
    {
        title: 'Sipariş Hazırlama',
        status: 'Hazırlanıyor',
        description: 'Sipariş satırları terminalden kontrollü okutma adımlarıyla hazırlanacak.',
        Icon: ClipboardList,
    },
    {
        title: 'İrsaliye Oluşturma',
        status: 'Hazırlanıyor',
        description: 'Hazırlanan sevk kayıtları onay sonrası irsaliye akışına taşınacak.',
        Icon: FileCheck2,
    },
    {
        title: 'Depolar Arası Sevk',
        status: 'Hazırlanıyor',
        description: 'Depo çıkış ve giriş adımları kontrollü sevk akışıyla izlenecek.',
        Icon: Truck,
    },
    {
        title: 'Mal Kabul',
        status: 'Hazırlanıyor',
        description: 'Gelen ürünler seri, miktar ve raf doğrulamasıyla kabul edilecek.',
        Icon: BadgeCheck,
    },
    {
        title: 'Sayım',
        status: 'Hazırlanıyor',
        description: 'Raf bazlı sayım oturumları terminal ekranından yönetilecek.',
        Icon: Layers3,
    },
    {
        title: 'İşlem Geçmişi',
        status: 'Hazırlanıyor',
        description: 'Terminal işlemlerinin audit izi ve ters işlem kayıtları izlenecek.',
        Icon: ReceiptText,
    },
];

const safeOperationRules = [
    'Mikro’ya direkt silme yapılmayacak.',
    'Raf transferi çıkış rafı HGRP 2, giriş rafı HGRP 3 kuralıyla kontrol edilecek.',
    'Onaysız işlem Mikro’ya yazılmayacak.',
    'Her işlem audit mantığıyla izlenecek.',
    'Hatalı işlemde ters işlem/iptal mantığı kurulacak.',
];

function StatusBadge({ status }: { status: TerminalCard['status'] }) {
    const className = status === 'Pilot'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-800'
        : 'border-slate-200 bg-slate-50 text-slate-600';

    return (
        <span className={`inline-flex items-center rounded-full border px-2.5 py-1 text-xs font-bold ${className}`}>
            {status}
        </span>
    );
}

export default function WarehouseTerminal() {
    return (
        <>
            <Head title="Depo Terminal" />

            <main className="min-h-[calc(100vh-4.5rem)] bg-slate-100 px-3 py-4 sm:px-4 md:px-6 md:py-6">
                <section className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-6">
                    <div className="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                        <div className="min-w-0">
                            <div className="inline-flex items-center gap-2 rounded-full border border-slate-200 bg-slate-50 px-3 py-1.5 text-xs font-bold text-slate-600">
                                <Boxes className="size-4 text-slate-700" />
                                Operasyon
                            </div>
                            <h1 className="mt-4 text-2xl font-semibold text-slate-950 [font-family:var(--font-display)] md:text-3xl">
                                Depo Terminal
                            </h1>
                            <p className="mt-2 max-w-3xl text-sm leading-6 text-slate-600 md:text-base">
                                Raf, seri, sipariş ve sevk işlemleri için kontrollü terminal ekranı.
                            </p>
                        </div>
                        <div className="rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm font-semibold text-slate-700">
                            Mobil el terminali odaklı iskelet
                        </div>
                    </div>
                </section>

                <section className="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                    {terminalCards.map(({ title, status, description, Icon }) => (
                        <article key={title} className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                            <div className="flex items-start justify-between gap-3">
                                <span className="grid size-10 shrink-0 place-items-center rounded-lg bg-slate-900 text-white">
                                    <Icon className="size-5" />
                                </span>
                                <StatusBadge status={status} />
                            </div>
                            <h2 className="mt-4 text-base font-bold text-slate-950">
                                {title}
                            </h2>
                            <p className="mt-2 text-sm leading-6 text-slate-600">
                                {description}
                            </p>
                        </article>
                    ))}
                </section>

                <section className="mt-4 rounded-lg border border-slate-200 bg-white p-4 shadow-sm md:p-5">
                    <div className="flex items-center gap-3">
                        <span className="grid size-10 place-items-center rounded-lg bg-blue-50 text-blue-700">
                            <ShieldCheck className="size-5" />
                        </span>
                        <div>
                            <h2 className="text-base font-bold text-slate-950">Güvenli işlem kuralları</h2>
                            <p className="text-sm text-slate-500">Bu ekran gerçek işlem yazmadan önce kontrollü akış kurallarını gösterir.</p>
                        </div>
                    </div>
                    <ul className="mt-4 grid gap-2 sm:grid-cols-2">
                        {safeOperationRules.map((rule) => (
                            <li key={rule} className="rounded-lg border border-slate-200 bg-slate-50 px-3 py-2 text-sm leading-6 text-slate-700">
                                {rule}
                            </li>
                        ))}
                    </ul>
                </section>
            </main>
        </>
    );
}
