import { Form, Head } from '@inertiajs/react';
import InputError from '@/components/input-error';
import PasswordInput from '@/components/password-input';
import TextLink from '@/components/text-link';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/login';

type Props = {
    status?: string;
    canResetPassword: boolean;
    canRegister: boolean;
};

export default function Login({
    status,
    canResetPassword,
    canRegister,
}: Props) {
    return (
        <>
            <Head title="Giriş" />

            <Form
                {...store.form()}
                resetOnSuccess={['password']}
                className="relative overflow-hidden rounded-[2rem] border border-white/80 bg-white/95 p-6 shadow-2xl shadow-slate-950/12 backdrop-blur-xl sm:p-7"
            >
                {({ processing, errors }) => (
                    <>
                        <div className="pointer-events-none absolute inset-x-0 top-0 h-1 bg-gradient-to-r from-[#126ec9] via-[#0d4e6f] to-[#39a7df]" />

                        <div className="grid justify-items-center gap-4 text-center">
                            <div className="rounded-2xl border border-slate-100 bg-slate-50/80 px-5 py-4 shadow-inner shadow-white">
                                <img
                                    src="/assets/primecrm/emaks-prime.png"
                                    alt="Emaks Prime"
                                    className="h-14 w-auto object-contain sm:h-16"
                                />
                            </div>
                            <div className="space-y-2">
                                <p className="text-xs font-semibold tracking-[0.24em] text-[#126ec9] uppercase">
                                    Operasyon Paneli
                                </p>
                                <h2 className="text-2xl font-semibold tracking-tight text-slate-950">
                                    Emaks Prime Panel Girişi
                                </h2>
                                <p className="mx-auto max-w-xs text-sm leading-6 text-slate-500">
                                    Satış, stok, sipariş, servis ve proforma
                                    yönetimi için güvenli panel erişimi.
                                </p>
                            </div>
                        </div>

                        {status && (
                            <div className="rounded-2xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-center text-sm font-medium text-emerald-700">
                                {status}
                            </div>
                        )}

                        <div className="grid gap-6">
                            <div className="grid gap-2">
                                <Label
                                    htmlFor="username"
                                    className="text-slate-700"
                                >
                                    Kullanıcı adı
                                </Label>
                                <Input
                                    id="username"
                                    type="text"
                                    name="username"
                                    required
                                    autoFocus
                                    tabIndex={1}
                                    autoComplete="username"
                                    placeholder="ornek.kullanici"
                                    className="h-11 rounded-xl border-slate-200 bg-slate-50/80 focus-visible:ring-[#126ec9]"
                                />
                                <InputError message={errors.username} />
                            </div>

                            <div className="grid gap-2">
                                <div className="flex items-center">
                                    <Label
                                        htmlFor="password"
                                        className="text-slate-700"
                                    >
                                        Şifre
                                    </Label>
                                    {canResetPassword && (
                                        <TextLink
                                            href="/forgot-password"
                                            className="ml-auto text-sm text-[#126ec9] hover:text-[#0d4e6f]"
                                            tabIndex={5}
                                        >
                                            Şifremi unuttum
                                        </TextLink>
                                    )}
                                </div>
                                <PasswordInput
                                    id="password"
                                    name="password"
                                    required
                                    tabIndex={2}
                                    autoComplete="current-password"
                                    placeholder="Şifre"
                                    className="h-11 rounded-xl border-slate-200 bg-slate-50/80 focus-visible:ring-[#126ec9]"
                                />
                                <InputError message={errors.password} />
                            </div>

                            <div className="flex items-center space-x-3">
                                <Checkbox
                                    id="remember"
                                    name="remember"
                                    tabIndex={3}
                                />
                                <Label
                                    htmlFor="remember"
                                    className="text-slate-600"
                                >
                                    Beni hatırla
                                </Label>
                            </div>

                            <Button
                                type="submit"
                                className="mt-2 h-11 w-full rounded-xl bg-[#0d4e6f] font-semibold shadow-lg shadow-[#0d4e6f]/25 transition hover:bg-[#126ec9]"
                                tabIndex={4}
                                disabled={processing}
                                data-test="login-button"
                            >
                                {processing && <Spinner />}
                                Giriş yap
                            </Button>
                        </div>

                        {canRegister && (
                            <div className="text-center text-sm text-muted-foreground">
                                Hesabınız yok mu?{' '}
                                <TextLink href="/register" tabIndex={5}>
                                    Kayıt ol
                                </TextLink>
                            </div>
                        )}
                    </>
                )}
            </Form>
        </>
    );
}

Login.layout = {
    title: '',
    description: '',
};
