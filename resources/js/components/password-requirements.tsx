import { CheckCircle2, Circle } from 'lucide-react';

type Props = {
    password?: string;
};

const rules = [
    {
        label: 'En az 8 karakter',
        test: (value: string) => value.length >= 8,
    },
    {
        label: 'En az 1 büyük harf',
        test: (value: string) => /[A-ZÇĞİÖŞÜ]/.test(value),
    },
    {
        label: 'En az 1 küçük harf',
        test: (value: string) => /[a-zçğıöşü]/.test(value),
    },
    {
        label: 'En az 1 rakam',
        test: (value: string) => /\d/.test(value),
    },
    {
        label: 'En az 1 sembol',
        test: (value: string) => /[^\p{L}\p{N}]/u.test(value),
    },
];

export default function PasswordRequirements({ password = '' }: Props) {
    return (
        <div className="rounded-xl border border-blue-100 bg-blue-50/70 p-4 text-sm text-slate-700 dark:border-blue-400/20 dark:bg-blue-950/20 dark:text-slate-200">
            <p className="font-semibold text-slate-900 dark:text-slate-100">
                Şifre kuralları
            </p>

            <ul className="mt-3 grid gap-2">
                {rules.map((rule) => {
                    const passed = rule.test(password);
                    const Icon = passed ? CheckCircle2 : Circle;

                    return (
                        <li
                            key={rule.label}
                            className="flex items-center gap-2"
                        >
                            <Icon
                                className={
                                    passed
                                        ? 'size-4 text-emerald-600'
                                        : 'size-4 text-slate-400'
                                }
                                aria-hidden="true"
                            />
                            <span>{rule.label}</span>
                        </li>
                    );
                })}
            </ul>

            <p className="mt-3 text-xs text-slate-500 dark:text-slate-400">
                Sistem ayrıca sızdırılmış veya çok yaygın şifreleri kontrol
                eder.
            </p>
        </div>
    );
}
