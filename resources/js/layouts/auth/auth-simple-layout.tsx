import { Link } from '@inertiajs/react';
import { home } from '@/routes';
import type { AuthLayoutProps } from '@/types';

export default function AuthSimpleLayout({
    children,
    title,
    description,
}: AuthLayoutProps) {
    return (
        <div className="relative flex min-h-svh flex-col items-center justify-center overflow-hidden bg-[#edf5fb] px-4 py-8 sm:px-6 md:p-10">
            <div className="absolute inset-0 bg-[radial-gradient(circle_at_top_left,rgba(18,116,193,0.2),transparent_34%),radial-gradient(circle_at_bottom_right,rgba(13,78,111,0.24),transparent_36%),linear-gradient(135deg,#f7fbff_0%,#e5f1fb_48%,#f8fafc_100%)]" />
            <div className="absolute top-8 left-8 hidden h-28 w-28 rounded-full bg-[#126ec9]/10 blur-2xl lg:block" />
            <div className="absolute right-10 bottom-10 hidden h-36 w-36 rounded-full bg-[#0d4e6f]/15 blur-3xl lg:block" />

            <div className="relative w-full max-w-md">
                <div className="flex flex-col gap-6">
                    <div className="flex flex-col items-center gap-5 text-center">
                        <Link
                            href={home()}
                            className="group flex flex-col items-center gap-3 rounded-[2rem] border border-white/80 bg-white/85 px-7 py-5 text-center shadow-xl shadow-slate-900/10 backdrop-blur transition hover:-translate-y-0.5 hover:shadow-2xl hover:shadow-[#126ec9]/15"
                        >
                            <img
                                src="/assets/primecrm/philips-logo.png"
                                alt="Philips"
                                className="h-16 w-auto object-contain drop-shadow-sm sm:h-20"
                            />
                            <p className="max-w-xs text-[0.72rem] leading-5 font-bold tracking-[0.22em] text-[#0d4e6f] uppercase">
                                PHILIPS AKILLI KİLİTLER TÜRKİYE RESMİ DİSTRİBÜTÖRÜ
                            </p>
                            <span className="sr-only">{title}</span>
                        </Link>

                        {(title || description) && (
                            <div className="space-y-2 text-center">
                                {title && (
                                    <h1 className="text-2xl font-semibold tracking-tight text-slate-950">
                                        {title}
                                    </h1>
                                )}
                                {description && (
                                    <p className="text-center text-sm text-slate-600">
                                        {description}
                                    </p>
                                )}
                            </div>
                        )}
                    </div>
                    {children}
                </div>
            </div>
        </div>
    );
}
