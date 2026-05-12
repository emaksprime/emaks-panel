import { Head } from '@inertiajs/react';
import AppearanceTabs from '@/components/appearance-tabs';
import Heading from '@/components/heading';
import { edit as editAppearance } from '@/routes/appearance';

export default function Appearance() {
    return (
        <>
            <Head title="Görünüm Ayarları" />

            <h1 className="sr-only">Görünüm Ayarları</h1>

            <div className="space-y-6">
                <Heading
                    variant="small"
                    title="Görünüm Ayarları"
                    description="Panel görünüm tercihlerinizi güncelleyin"
                />
                <AppearanceTabs />
            </div>
        </>
    );
}

Appearance.layout = {
    breadcrumbs: [
        {
            title: 'Görünüm Ayarları',
            href: editAppearance(),
        },
    ],
};
