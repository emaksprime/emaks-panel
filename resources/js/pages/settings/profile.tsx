import { Form, Head, Link, usePage } from '@inertiajs/react';
import ProfileController from '@/actions/App/Http/Controllers/Settings/ProfileController';
import DeleteUser from '@/components/delete-user';
import Heading from '@/components/heading';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { edit } from '@/routes/profile';

export default function Profile({
    mustVerifyEmail,
    status,
}: {
    mustVerifyEmail: boolean;
    status?: string;
}) {
    const { auth } = usePage().props;

    return (
        <>
            <Head title="Profil Ayarları" />

            <h1 className="sr-only">Profil Ayarları</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Profil Bilgileri"
                    description="Panel kimlik bilgilerinizi güncelleyin"
                />

                <Form
                    {...ProfileController.update.form()}
                    options={{
                        preserveScroll: true,
                    }}
                    className="space-y-6"
                >
                    {({ processing, errors }) => (
                        <>
                            <div className="grid gap-2">
                                <Label htmlFor="full_name">Ad Soyad</Label>

                                <Input
                                    id="full_name"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.name}
                                    name="full_name"
                                    required
                                    autoComplete="name"
                                    placeholder="Ad Soyad"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.full_name}
                                />
                            </div>

                            <div className="grid gap-2">
                                <Label htmlFor="username">Kullanıcı adı</Label>

                                <Input
                                    id="username"
                                    type="text"
                                    className="mt-1 block w-full"
                                    defaultValue={auth.user.username}
                                    name="username"
                                    required
                                    autoComplete="username"
                                    placeholder="Kullanıcı adı"
                                />

                                <InputError
                                    className="mt-2"
                                    message={errors.username}
                                />
                            </div>

                            {mustVerifyEmail &&
                                auth.user.email_verified_at === null && (
                                    <div>
                                        <p className="-mt-4 text-sm text-muted-foreground">
                                            E-posta adresiniz doğrulanmamış.{' '}
                                            <Link
                                                href="/email/verification-notification"
                                                method="post"
                                                as="button"
                                                className="text-foreground underline decoration-neutral-300 underline-offset-4 transition-colors duration-300 ease-out hover:decoration-current! dark:decoration-neutral-500"
                                            >
                                                Doğrulama e-postasını yeniden
                                                göndermek için tıklayın.
                                            </Link>
                                        </p>

                                        {status ===
                                            'verification-link-sent' && (
                                            <div className="mt-2 text-sm font-medium text-green-600">
                                                Yeni doğrulama bağlantısı
                                                e-posta adresinize gönderildi.
                                            </div>
                                        )}
                                    </div>
                                )}

                            <div className="flex items-center gap-4">
                                <Button
                                    disabled={processing}
                                    data-test="update-profile-button"
                                >
                                    Kaydet
                                </Button>
                            </div>
                        </>
                    )}
                </Form>
            </div>

            <DeleteUser />
        </>
    );
}

Profile.layout = {
    breadcrumbs: [
        {
            title: 'Profil Ayarları',
            href: edit(),
        },
    ],
};
