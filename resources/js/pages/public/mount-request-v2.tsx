import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import type { FormEvent, ReactNode } from 'react';
import {
    getDistrictOptionsForProvince,
    normalizeDistrictName,
    normalizeProvinceName,
    normalizeTurkishLocation,
    TURKEY_PROVINCES,
} from '@/components/technical-service/turkey-locations';

declare global {
    interface Window {
        google?: any;
    }
}

const GOOGLE_MAPS_API_KEY = (import.meta.env.VITE_GOOGLE_MAPS_API_KEY ?? '') as string;
const GOOGLE_MAPS_LANGUAGE = ((import.meta.env.VITE_GOOGLE_MAPS_LANGUAGE ?? 'tr') as string) || 'tr';
const GOOGLE_MAPS_REGION = ((import.meta.env.VITE_GOOGLE_MAPS_REGION ?? 'TR') as string) || 'TR';
const DOOR_PHOTO_ACCEPT = 'image/jpeg,image/png,image/webp';
const MAX_DOOR_PHOTO_BYTES = 8 * 1024 * 1024;
const TARGET_DOOR_PHOTO_BYTES = 2.5 * 1024 * 1024;
const DOOR_PHOTO_FIELDS = [
    { code: 'door_front_photo', label: 'Kapı Ön Yüzü' },
    { code: 'door_side_photo', label: 'Kapı Yan Yüzü' },
    { code: 'door_back_photo', label: 'Kapı Arka Yüzü' },
] as const;
const DOOR_PHOTO_GUIDANCE: Record<DoorPhotoFieldCode, { title: string; helper: string }> = {
    door_front_photo: {
        title: 'Örnek çekim',
        helper: 'Kapıyı tamamen kadraja al.',
    },
    door_side_photo: {
        title: 'Örnek çekim',
        helper: 'Kilit/yan profil net görünsün.',
    },
    door_back_photo: {
        title: 'Örnek çekim',
        helper: 'Bulanık fotoğraf yükleme.',
    },
};

type Product = {
    product_name?: string | null;
    product_model?: string | null;
    serial_number?: string | null;
    brand?: string | null;
};

type Actions = {
    payment_label?: string;
    multi_product_label?: string;
    continue_label?: string;
    create_payment_url?: string;
    multi_product_url?: string;
    multi_product_lookup_url?: string;
    submit_url?: string;
};

type Payment = {
    amount: string;
    currency: string;
    fake_approve_url?: string | null;
};

type Submitted = {
    mrn: string;
};

type CustomerForm = {
    first_name: string;
    last_name: string;
    phone: string;
    city: string;
    district: string;
    address: string;
    location_latitude: string;
    location_longitude: string;
    location_place_id: string;
    location_formatted_address: string;
    location_map_url: string;
    building_no: string;
    apartment_no: string;
    door_no: string;
    floor_no: string;
    site_name: string;
    door_front_photo: File | null;
    door_side_photo: File | null;
    door_back_photo: File | null;
    installation_consent: boolean;
    kvkk_consent: boolean;
    multiple_products: boolean;
    selected_invoice_serials: string[];
};

type DoorPhotoFieldCode = (typeof DOOR_PHOTO_FIELDS)[number]['code'];

type DoorPhotoPreview = {
    name: string;
    sizeLabel: string;
    url: string;
    error?: string;
};

type MultiProductOption = {
    serial_number?: string | null;
    product_name?: string | null;
    product_model?: string | null;
};

type MultiProductLookupResponse = {
    selectable_serials?: MultiProductOption[];
    items?: MultiProductOption[];
    has_selectable_serials?: boolean;
    blocked_count?: number;
    returned_count?: number;
    total_count?: number;
    operation_only_count?: number;
    message?: string | null;
};

type Props = {
    viewState:
        | 'invalid_link'
        | 'form_ready'
        | 'payment_required'
        | 'multi_product_ready'
        | 'check_pending'
        | 'submitted'
        | 'unknown_error';
    message: string;
    product?: Product;
    statusLabel?: string;
    actions?: Actions;
    payment?: Payment | null;
    submitted?: Submitted | null;
    allowMultiProductRequest?: boolean;
};

const PHONE_ERROR = 'Telefon numarası +90 sonrası 10 hane olmalıdır.';

const INSTALLATION_CONSENT_TEXT = `Değerli Müşterimiz,
Akıllı kilidin kapınıza kurulumu öncesinde, teknik ekibimiz tarafından kapı üzerinde geri dönüşümü olmayan değişiklikler yapılması gerektiğini size bildirmek isteriz. Bu değişiklikler arasında delik açma veya kapıda onarımlar bulunabilir. Bu değişikliklerin, akıllı kilidin uygun bir şekilde kurulması ve çalışması için gerekli olduğunu bilmeniz gerekmektedir. Bu mesajdaki kuralları ve bilgileri kabul ederseniz şartları onaylamış olursunuz.
*Satın aldığım ürünün bütün özelliklerini inceledim bilgim dahilindedir ve kurulumu yapılan cihazın hiç bir şekilde iade ve değişimi söz konusu değildir.*`;

function Detail({ label, value }: { label: string; value?: string | null }) {
    if (!value) {
        return null;
    }

    return (
        <div className="rounded-xl border border-slate-200 bg-white p-4">
            <p className="text-xs font-semibold uppercase tracking-[0.12em] text-slate-500">{label}</p>
            <p className="mt-1 text-base font-semibold text-slate-950">{value}</p>
        </div>
    );
}

type FormSectionTone = 'product' | 'location' | 'customer' | 'address' | 'door' | 'serial' | 'slate'

const formSectionToneClass = (tone: FormSectionTone = 'slate'): string => {
    switch (tone) {
        case 'product':
            return 'border-blue-100 bg-blue-50/70';
        case 'location':
            return 'border-emerald-100 bg-emerald-50/80';
        case 'customer':
            return 'border-slate-200 bg-white';
        case 'address':
            return 'border-cyan-100 bg-cyan-50/70';
        case 'door':
            return 'border-amber-100 bg-amber-50/70';
        case 'serial':
            return 'border-violet-100 bg-violet-50/70';
        default:
            return 'border-slate-200 bg-slate-50';
    }
};

function FormAccordionCard({
    title,
    summary,
    tone = 'slate',
    defaultOpen = true,
    children,
}: {
    title: string;
    summary?: ReactNode;
    tone?: FormSectionTone;
    defaultOpen?: boolean;
    children: ReactNode;
}) {
    const [open, setOpen] = useState(defaultOpen);

    return (
        <section className={`rounded-2xl border p-4 shadow-sm transition-colors ${formSectionToneClass(tone)}`}>
            <button
                type="button"
                onClick={() => setOpen((current) => !current)}
                className="flex w-full items-start justify-between gap-3 text-left"
            >
                <span className="min-w-0">
                    <span className="block text-sm font-semibold uppercase tracking-[0.08em] text-slate-700 sm:text-base">{title}</span>
                    {summary ? <span className="mt-1 block text-sm leading-5 text-slate-600">{summary}</span> : null}
                </span>
                <span className={`mt-0.5 text-base font-semibold text-slate-500 transition-transform ${open ? 'rotate-180' : ''}`}>⌄</span>
            </button>
            {open ? <div className="mt-4 grid gap-3 motion-safe:transition-all">{children}</div> : null}
        </section>
    );
}

function FieldError({ message }: { message?: string }) {
    if (!message) {
        return null;
    }

    return <p className="mt-1 text-xs font-semibold text-red-600">{message}</p>;
}

function DoorPhotoPlaceholder({ fieldCode }: { fieldCode: DoorPhotoFieldCode }) {
    const guidance = DOOR_PHOTO_GUIDANCE[fieldCode];

    return (
        <div className="grid aspect-[4/3] max-w-full gap-2 overflow-hidden rounded-lg border border-dashed border-slate-300 bg-white p-3 text-xs text-slate-600">
            <div className="relative mx-auto mt-1 h-24 w-16 rounded-t-full border-2 border-slate-300 bg-slate-50">
                <div className="absolute inset-x-3 top-6 h-10 rounded border border-slate-200 bg-white" />
                <div className="absolute right-3 top-12 h-2 w-2 rounded-full bg-slate-400" />
                <div className="absolute inset-x-4 bottom-3 h-5 rounded-sm border border-slate-200 bg-white" />
            </div>
            <div>
                <p className="font-semibold text-slate-900">{guidance.title}</p>
                <p className="mt-1">{guidance.helper}</p>
                <p className="mt-1 font-semibold text-rose-700">Bulanık fotoğraf yükleme.</p>
            </div>
        </div>
    );
}

function normalizePhoneDigits(value: string): string {
    let digits = value.replace(/\D+/g, '');

    if (digits.startsWith('90') && digits.length >= 12) {
        digits = digits.slice(2);
    }

    if (digits.startsWith('0')) {
        digits = digits.slice(1);
    }

    return digits.slice(0, 10);
}

function normalizeCityForForm(value: string | null | undefined): string {
    return normalizeProvinceName(value) ?? String(value ?? '').trim();
}

function normalizeDistrictForForm(city: string | null | undefined, value: string | null | undefined): string {
    return normalizeDistrictName(city, value) ?? String(value ?? '').trim();
}

function draftKey(): string | null {
    if (typeof window === 'undefined') {
        return null;
    }

    return `mount-request-v2:draft:${window.location.pathname}`;
}

function csrfToken(): string {
    if (typeof document === 'undefined') {
        return '';
    }

    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')?.content ?? '';
}

function formatFileSize(bytes: number): string {
    if (bytes >= 1024 * 1024) {
        return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
    }

    return `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

async function compressDoorPhoto(file: File, fieldCode: DoorPhotoFieldCode): Promise<File> {
    if (file.type === 'image/webp' && file.size <= TARGET_DOOR_PHOTO_BYTES) {
        return file;
    }

    const bitmap = await createImageBitmap(file);
    const scale = Math.min(1, 1920 / Math.max(bitmap.width, bitmap.height));
    const canvas = document.createElement('canvas');
    canvas.width = Math.max(1, Math.round(bitmap.width * scale));
    canvas.height = Math.max(1, Math.round(bitmap.height * scale));
    const context = canvas.getContext('2d');

    if (!context) {
        bitmap.close();

        return file;
    }

    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();

    const blob = await new Promise<Blob | null>((resolve) => {
        canvas.toBlob(resolve, 'image/jpeg', 0.78);
    });

    if (!blob) {
        return file;
    }

    return new File([blob], `${fieldCode}-compressed.jpg`, {
        type: 'image/jpeg',
        lastModified: Date.now(),
    });
}

function loadGoogleMapsScript(): Promise<void> {
    if (typeof window === 'undefined' || window.google?.maps?.places) {
        return Promise.resolve();
    }

    return new Promise((resolve, reject) => {
        const existing = document.getElementById('google-maps-places-script') as HTMLScriptElement | null;

        if (existing) {
            existing.addEventListener('load', () => resolve(), { once: true });
            existing.addEventListener('error', () => reject(new Error('load_failed')), { once: true });

            return;
        }

        const script = document.createElement('script');
        script.id = 'google-maps-places-script';
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(GOOGLE_MAPS_API_KEY)}&libraries=places&language=${encodeURIComponent(GOOGLE_MAPS_LANGUAGE)}&region=${encodeURIComponent(GOOGLE_MAPS_REGION)}`;
        script.async = true;
        script.defer = true;
        script.addEventListener('load', () => resolve(), { once: true });
        script.addEventListener('error', () => reject(new Error('load_failed')), { once: true });
        document.head.appendChild(script);
    });
}

function addressComponent(components: any[] | undefined, types: string[]): string {
    const component = components?.find((item) => types.some((type) => item.types?.includes(type)));

    return String(component?.long_name ?? '').trim();
}

export default function MountRequestV2({
    viewState,
    message,
    product,
    statusLabel,
    actions,
    payment,
    submitted,
    allowMultiProductRequest = false,
}: Props) {
    const invalid = viewState === 'invalid_link';
    const paymentRequired = viewState === 'payment_required';
    const submittedSuccessfully = viewState === 'submitted';
    const formReady = viewState === 'form_ready' || viewState === 'check_pending' || viewState === 'multi_product_ready';
    const [submitStatus, setSubmitStatus] = useState('');
    const [submitError, setSubmitError] = useState('');
    const [draftRestored, setDraftRestored] = useState(false);
    const [multiProductOptions, setMultiProductOptions] = useState<MultiProductOption[]>([]);
    const [multiProductLoading, setMultiProductLoading] = useState(false);
    const [multiProductModalOpen, setMultiProductModalOpen] = useState(false);
    const [multiProductNotice, setMultiProductNotice] = useState('');
    const [multiProductOperationOnlyCount, setMultiProductOperationOnlyCount] = useState(0);
    const [multiProductTotalCount, setMultiProductTotalCount] = useState(0);
    const [locationModalOpen, setLocationModalOpen] = useState(false);
    const [locationStatus, setLocationStatus] = useState('');
    const [googleMapsLoaded, setGoogleMapsLoaded] = useState(false);
    const [districtSearch, setDistrictSearch] = useState('');
    const [districtDropdownOpen, setDistrictDropdownOpen] = useState(false);
    const [doorPhotoPreviews, setDoorPhotoPreviews] = useState<Partial<Record<DoorPhotoFieldCode, DoorPhotoPreview>>>({});
    const mapRef = useRef<HTMLDivElement | null>(null);
    const searchInputRef = useRef<HTMLInputElement | null>(null);
    const form = useForm<CustomerForm>({
        first_name: '',
        last_name: '',
        phone: '',
        city: '',
        district: '',
        address: '',
        location_latitude: '',
        location_longitude: '',
        location_place_id: '',
        location_formatted_address: '',
        location_map_url: '',
        building_no: '',
        apartment_no: '',
        door_no: '',
        floor_no: '',
        site_name: '',
        door_front_photo: null,
        door_side_photo: null,
        door_back_photo: null,
        installation_consent: false,
        kvkk_consent: false,
        multiple_products: viewState === 'multi_product_ready',
        selected_invoice_serials: [],
    });

    const submitUrl = actions?.submit_url;
    const storageKey = draftKey();
    const districtOptions = getDistrictOptionsForProvince(form.data.city);
    const cityHasFallback = form.data.city.trim() !== ''
        && !TURKEY_PROVINCES.some((province) => province.name === form.data.city);
    const districtHasFallback = form.data.district.trim() !== ''
        && !districtOptions.some((district) => district.name === form.data.district);
    const districtSearchTerm = normalizeTurkishLocation(districtSearch);
    const filteredDistrictOptions = districtSearchTerm
        ? districtOptions.filter((district) => district.normalizedName.includes(districtSearchTerm))
        : districtOptions;

    const handleCityChange = (value: string) => {
        setDistrictSearch('');
        setDistrictDropdownOpen(false);
        form.setData({
            ...form.data,
            city: normalizeCityForForm(value),
            district: '',
        });
    };

    const handleDistrictChange = (value: string) => {
        const district = normalizeDistrictForForm(form.data.city, value);

        setDistrictSearch(district);
        setDistrictDropdownOpen(false);
        form.setData('district', district);
    };

    const applyLocationSelection = (payload: {
        city?: string;
        district?: string;
        address?: string;
        lat: number;
        lng: number;
        placeId?: string;
    }) => {
        const mapUrl = `https://www.google.com/maps?q=${payload.lat},${payload.lng}`;
        const city = normalizeCityForForm(payload.city || form.data.city);
        const district = normalizeDistrictForForm(city, payload.district || form.data.district);

        setDistrictSearch(district);
        form.setData({
            ...form.data,
            city,
            district,
            address: payload.address || `Konum paylaşıldı: ${payload.lat}, ${payload.lng}`,
            location_latitude: String(payload.lat),
            location_longitude: String(payload.lng),
            location_place_id: payload.placeId ?? '',
            location_formatted_address: payload.address ?? '',
            location_map_url: mapUrl,
        });
        setLocationStatus(
            payload.city && payload.district
                ? 'Konum eklendi. Haritada aç bağlantısı hazır.'
                : 'Adres bilgisi tam çözümlenemedi. Lütfen İl ve İlçe alanlarını kontrol edin.',
        );
    };

    const applyGooglePlace = (place: any) => {
        const location = place?.geometry?.location;

        if (!location) {
            setLocationStatus('Konum seçilemedi. Lütfen tekrar deneyin veya adresi elle girin.');

            return;
        }

        const lat = typeof location.lat === 'function' ? location.lat() : Number(location.lat);
        const lng = typeof location.lng === 'function' ? location.lng() : Number(location.lng);
        const components = place.address_components as any[] | undefined;
        const city = addressComponent(components, ['administrative_area_level_1']);
        const district = addressComponent(components, ['administrative_area_level_2', 'sublocality_level_1', 'sublocality']);

        applyLocationSelection({
            city,
            district,
            address: String(place.formatted_address ?? '').trim(),
            lat,
            lng,
            placeId: String(place.place_id ?? ''),
        });
    };

    const reverseGeocodeLocation = (lat: number, lng: number) => {
        if (!window.google?.maps?.Geocoder) {
            setLocationStatus('Konum seçimi şu anda kullanılamıyor. Adresi elle girebilirsiniz.');

            return;
        }

        const geocoder = new window.google.maps.Geocoder();
        geocoder.geocode({ location: { lat, lng } }, (results: any[], status: string) => {
            if (status !== 'OK' || !results?.[0]) {
                setLocationStatus('Adres bilgisi tam çözümlenemedi. Lütfen İl ve İlçe alanlarını kontrol edin.');
                applyLocationSelection({ lat, lng, address: `Konum paylaşıldı: ${lat}, ${lng}` });

                return;
            }

            applyGooglePlace(results[0]);
        });
    };

    const openLocationModal = () => {
        setLocationModalOpen(true);
        setLocationStatus(
            GOOGLE_MAPS_API_KEY
                ? 'Konum seçildiğinde adres bilgileri otomatik doldurulur. Eksik alan varsa tamamlayabilirsiniz.'
                : 'Konum seçimi şu anda kullanılamıyor. Adresi elle girebilirsiniz.',
        );
    };

    const useCurrentLocation = () => {
        if (!navigator.geolocation || !GOOGLE_MAPS_API_KEY) {
            setLocationStatus('Konum seçimi şu anda kullanılamıyor. Adresi elle girebilirsiniz.');

            return;
        }

        setLocationStatus('Mevcut konumunuz alınıyor...');
        navigator.geolocation.getCurrentPosition(
            (position) => reverseGeocodeLocation(position.coords.latitude, position.coords.longitude),
            () => setLocationStatus('Konum alınamadı. Adresi elle girebilirsiniz.'),
            { enableHighAccuracy: true, timeout: 10000 },
        );
    };

    const handleDoorPhotoChange = async (fieldCode: DoorPhotoFieldCode, file?: File | null) => {
        if (!file) {
            return;
        }

        if (!DOOR_PHOTO_ACCEPT.split(',').includes(file.type)) {
            setDoorPhotoPreviews((current) => ({
                ...current,
                [fieldCode]: {
                    name: file.name,
                    sizeLabel: formatFileSize(file.size),
                    url: '',
                    error: 'Fotoğraf çok büyük. Lütfen daha küçük bir görsel seçin.',
                },
            }));

            return;
        }

        const compressed = await compressDoorPhoto(file, fieldCode);

        if (compressed.size > MAX_DOOR_PHOTO_BYTES) {
            form.setData(fieldCode, null);
            setDoorPhotoPreviews((current) => ({
                ...current,
                [fieldCode]: {
                    name: compressed.name,
                    sizeLabel: formatFileSize(compressed.size),
                    url: '',
                    error: 'Fotoğraf çok büyük. Lütfen daha küçük bir görsel seçin.',
                },
            }));

            return;
        }

        form.setData(fieldCode, compressed);
        setDoorPhotoPreviews((current) => {
            const previous = current[fieldCode]?.url;

            if (previous) {
                URL.revokeObjectURL(previous);
            }

            return {
                ...current,
                [fieldCode]: {
                    name: compressed.name,
                    sizeLabel: formatFileSize(compressed.size),
                    url: URL.createObjectURL(compressed),
                },
            };
        });
    };

    const removeDoorPhoto = (fieldCode: DoorPhotoFieldCode) => {
        form.setData(fieldCode, null);
        setDoorPhotoPreviews((current) => {
            const next = { ...current };
            const previous = next[fieldCode]?.url;

            if (previous) {
                URL.revokeObjectURL(previous);
            }

            delete next[fieldCode];

            return next;
        });
    };

    useEffect(() => {
        if (!storageKey || submittedSuccessfully) {
            return;
        }

        const rawDraft = window.localStorage.getItem(storageKey);

        if (!rawDraft) {
            return;
        }

        try {
            const parsed = JSON.parse(rawDraft) as Partial<CustomerForm>;
            const restoredDistrict = typeof parsed.district === 'string' ? parsed.district : '';

            form.setData({
                ...form.data,
                first_name: typeof parsed.first_name === 'string' ? parsed.first_name : '',
                last_name: typeof parsed.last_name === 'string' ? parsed.last_name : '',
                phone: typeof parsed.phone === 'string' ? normalizePhoneDigits(parsed.phone) : '',
                city: typeof parsed.city === 'string' ? parsed.city : '',
                district: restoredDistrict,
                address: typeof parsed.address === 'string' ? parsed.address : '',
                location_latitude: typeof parsed.location_latitude === 'string' ? parsed.location_latitude : '',
                location_longitude: typeof parsed.location_longitude === 'string' ? parsed.location_longitude : '',
                location_place_id: typeof parsed.location_place_id === 'string' ? parsed.location_place_id : '',
                location_formatted_address: typeof parsed.location_formatted_address === 'string' ? parsed.location_formatted_address : '',
                location_map_url: typeof parsed.location_map_url === 'string' ? parsed.location_map_url : '',
                building_no: typeof parsed.building_no === 'string' ? parsed.building_no : '',
                apartment_no: typeof parsed.apartment_no === 'string' ? parsed.apartment_no : '',
                door_no: typeof parsed.door_no === 'string' ? parsed.door_no : '',
                floor_no: typeof parsed.floor_no === 'string' ? parsed.floor_no : '',
                site_name: typeof parsed.site_name === 'string' ? parsed.site_name : '',
                door_front_photo: null,
                door_side_photo: null,
                door_back_photo: null,
                installation_consent: Boolean(parsed.installation_consent),
                kvkk_consent: Boolean(parsed.kvkk_consent),
                multiple_products: viewState === 'multi_product_ready' || Boolean(parsed.multiple_products),
                selected_invoice_serials: Array.isArray(parsed.selected_invoice_serials)
                    ? parsed.selected_invoice_serials.filter((value): value is string => typeof value === 'string')
                    : [],
            });
            queueMicrotask(() => {
                setDistrictSearch(restoredDistrict);
                setDraftRestored(true);
            });
        } catch {
            window.localStorage.removeItem(storageKey);
        }
        // Restore should run once for the current token path.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [storageKey, submittedSuccessfully]);

    useEffect(() => {
        if (viewState === 'multi_product_ready' && !form.data.multiple_products) {
            form.setData('multiple_products', true);
        }
        // This is a one-way correction for the payment-skipped multi product flow.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [viewState]);

    useEffect(() => {
        if (!storageKey || submittedSuccessfully) {
            return;
        }

        window.localStorage.setItem(storageKey, JSON.stringify({
            first_name: form.data.first_name,
            last_name: form.data.last_name,
            phone: form.data.phone,
            city: form.data.city,
            district: form.data.district,
            address: form.data.address,
            location_latitude: form.data.location_latitude,
            location_longitude: form.data.location_longitude,
            location_place_id: form.data.location_place_id,
            location_formatted_address: form.data.location_formatted_address,
            location_map_url: form.data.location_map_url,
            building_no: form.data.building_no,
            apartment_no: form.data.apartment_no,
            door_no: form.data.door_no,
            floor_no: form.data.floor_no,
            site_name: form.data.site_name,
            installation_consent: form.data.installation_consent,
            kvkk_consent: form.data.kvkk_consent,
            multiple_products: form.data.multiple_products,
            selected_invoice_serials: form.data.selected_invoice_serials,
        }));
    }, [form.data, storageKey, submittedSuccessfully]);

    useEffect(() => {
        if (!form.data.multiple_products) {
            return;
        }

        let ignore = false;
        const runSoon = (callback: () => void) => window.setTimeout(() => {
            if (!ignore) {
                callback();
            }
        }, 0);

        if (!actions?.multi_product_lookup_url) {
            const timer = runSoon(() => {
                setMultiProductLoading(false);
                setMultiProductOptions([]);
                setMultiProductModalOpen(false);
                setMultiProductNotice('Ek ürün talebiniz operasyon ekibine iletilecek.');
            });

            return () => {
                ignore = true;
                window.clearTimeout(timer);
            };
        }

        const loadingTimer = runSoon(() => {
            setMultiProductLoading(true);
            setMultiProductNotice('');
        });

        fetch(actions.multi_product_lookup_url, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
            },
            credentials: 'same-origin',
        })
            .then((response) => response.ok ? response.json() : { selectable_serials: [], items: [] })
            .then((payload: MultiProductLookupResponse) => {
                if (!ignore) {
                    const sourceItems = Array.isArray(payload.selectable_serials)
                        ? payload.selectable_serials
                        : Array.isArray(payload.items)
                            ? payload.items
                            : [];
                    const items = sourceItems.filter((item) => typeof item?.serial_number === 'string' && item.serial_number.trim() !== '');
                    const hasSelectableSerials = items.length > 0 || Boolean(payload.has_selectable_serials);
                    setMultiProductOptions(items);
                    setMultiProductOperationOnlyCount(typeof payload.operation_only_count === 'number' ? payload.operation_only_count : 0);
                    setMultiProductTotalCount(typeof payload.total_count === 'number' ? payload.total_count : 0);
                    setMultiProductModalOpen(hasSelectableSerials);
                    setMultiProductNotice(hasSelectableSerials ? '' : (payload.message || 'Ek ürün talebiniz operasyon ekibine iletilecek.'));
                }
            })
            .catch(() => {
                if (!ignore) {
                    setMultiProductOptions([]);
                    setMultiProductOperationOnlyCount(0);
                    setMultiProductTotalCount(0);
                    setMultiProductModalOpen(false);
                    setMultiProductNotice('Ek ürün talebiniz operasyon ekibine iletilecek.');
                }
            })
            .finally(() => {
                if (!ignore) {
                    setMultiProductLoading(false);
                }
            });

        return () => {
            ignore = true;
            window.clearTimeout(loadingTimer);
        };
    }, [form.data.multiple_products, actions?.multi_product_lookup_url]);

    useEffect(() => {
        if (!locationModalOpen) {
            return;
        }

        if (!GOOGLE_MAPS_API_KEY) {
            queueMicrotask(() => setLocationStatus('Konum seçimi şu anda kullanılamıyor. Adresi elle girebilirsiniz.'));

            return;
        }

        let ignore = false;

        loadGoogleMapsScript()
            .then(() => {
                if (!ignore) {
                    setGoogleMapsLoaded(true);
                }
            })
            .catch(() => {
                if (!ignore) {
                    setLocationStatus('Konum seçimi şu anda kullanılamıyor. Adresi elle girebilirsiniz.');
                }
            });

        return () => {
            ignore = true;
        };
    }, [locationModalOpen]);

    useEffect(() => {
        if (!locationModalOpen || !googleMapsLoaded || !window.google?.maps || !mapRef.current || !searchInputRef.current) {
            return;
        }

        const center = {
            lat: Number(form.data.location_latitude || 41.0082),
            lng: Number(form.data.location_longitude || 28.9784),
        };
        const map = new window.google.maps.Map(mapRef.current, {
            center,
            zoom: 13,
            streetViewControl: false,
            mapTypeControl: false,
        });
        const marker = new window.google.maps.Marker({ map, position: center });
        const autocomplete = new window.google.maps.places.Autocomplete(searchInputRef.current, {
            componentRestrictions: { country: 'tr' },
            fields: ['formatted_address', 'place_id', 'geometry', 'address_components'],
        });
        autocomplete.addListener('place_changed', () => {
            const place = autocomplete.getPlace();
            applyGooglePlace(place);

            if (place.geometry?.location) {
                marker.setPosition(place.geometry.location);
                map.panTo(place.geometry.location);
            }
        });
        map.addListener('click', (event: any) => {
            const lat = event.latLng.lat();
            const lng = event.latLng.lng();
            marker.setPosition(event.latLng);
            reverseGeocodeLocation(lat, lng);
        });
        // Map setup should rerun only when the modal opens after the Google script is ready.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [googleMapsLoaded, locationModalOpen]);

    const clearDraft = () => {
        if (storageKey) {
            window.localStorage.removeItem(storageKey);
        }

        form.reset();
        setMultiProductOptions([]);
        setMultiProductModalOpen(false);
        setMultiProductNotice('');
        setDoorPhotoPreviews({});
        setDraftRestored(false);
    };

    const toggleSelectedInvoiceSerial = (serialNumber: string, selected: boolean) => {
        const current = new Set(form.data.selected_invoice_serials);

        if (selected) {
            current.add(serialNumber);
        } else {
            current.delete(serialNumber);
        }

        form.setData('selected_invoice_serials', Array.from(current));
    };

    const handleSubmit = (event: FormEvent<HTMLFormElement>) => {
        event.preventDefault();

        if (!submitUrl) {
            setSubmitError('Form gönderim adresi hazırlanamadı.');

            return;
        }

        const normalizedPhone = normalizePhoneDigits(form.data.phone);

        if (normalizedPhone.length !== 10) {
            setSubmitError(PHONE_ERROR);
            form.setError('phone', PHONE_ERROR);

            return;
        }

        if (form.data.address.trim() === '' && form.data.location_formatted_address.trim() === '') {
            setSubmitError('Adres veya konum bilgisi zorunludur.');
            form.setError('address', 'Adres veya konum bilgisi zorunludur.');

            return;
        }

        const missingDoorPhotoFields = DOOR_PHOTO_FIELDS.filter((field) => !form.data[field.code]);

        if (missingDoorPhotoFields.length > 0) {
            const message = 'Kapı ön, yan ve arka yüz fotoğrafları zorunludur.';
            setSubmitError(message);
            missingDoorPhotoFields.forEach((field) => form.setError(field.code, message));

            return;
        }

        if (Object.values(doorPhotoPreviews).some((photo) => photo?.error)) {
            setSubmitError('Fotoğraf çok büyük. Lütfen daha küçük bir görsel seçin.');

            return;
        }

        setSubmitError('');
        form.clearErrors('phone', 'address', 'door_front_photo', 'door_side_photo', 'door_back_photo');
        form.transform((data) => ({
            ...data,
            phone: normalizedPhone,
        }));
        form.post(submitUrl, {
            forceFormData: true,
            preserveScroll: true,
            onStart: () => {
                setSubmitError('');
                setSubmitStatus('Talebiniz gönderiliyor...');
            },
            onSuccess: () => {
                if (storageKey) {
                    window.localStorage.removeItem(storageKey);
                }

                setDoorPhotoPreviews({});
            },
            onError: (errors) => {
                const firstError = Object.values(errors)[0];
                setSubmitError(typeof firstError === 'string' ? firstError : 'Form gönderilemedi. Lütfen alanları kontrol edin.');
            },
            onFinish: () => {
                setSubmitStatus('');
            },
        });
    };

    return (
        <>
            <Head title="Montaj Talep Formu" />
            <main className="min-h-screen bg-slate-100 px-4 py-8">
                <section className="mx-auto grid max-w-3xl gap-5 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
                    <div>
                        <p className="text-xs font-semibold uppercase tracking-[0.16em] text-blue-700">
                            Emaks Prime Teknik Servis
                        </p>
                        <h1 className="mt-2 text-2xl font-semibold text-slate-950">
                            Montaj Talep Formu
                        </h1>
                        <p className="mt-3 text-sm leading-6 text-slate-600">
                            {message}
                        </p>
                    </div>

                    {invalid ? (
                        <div className="rounded-xl border border-red-200 bg-red-50 p-4 text-sm font-semibold text-red-700">
                            Montaj talep linki geçersiz veya süresi dolmuş.
                        </div>
                    ) : submittedSuccessfully ? (
                        <div className="grid gap-3 rounded-xl border border-emerald-200 bg-emerald-50 p-5">
                            <p className="text-lg font-semibold text-emerald-950">
                                Montaj talebiniz alınmıştır.
                            </p>
                            {submitted?.mrn && (
                                <p className="text-sm font-semibold text-emerald-800">
                                    Talep numaranız: {submitted.mrn}
                                </p>
                            )}
                        </div>
                    ) : (
                        <>
                            <FormAccordionCard
                                title="Ürün bilgisi"
                                summary="Ürün, model, seri no ve marka bilgileri"
                                tone="product"
                                defaultOpen
                            >
                                <div className="grid gap-3 sm:grid-cols-2">
                                <Detail label="Ürün adı" value={product?.product_name} />
                                <Detail label="Model" value={product?.product_model} />
                                <Detail label="Seri no" value={product?.serial_number} />
                                <Detail label="Marka" value={product?.brand} />
                                </div>
                            </FormAccordionCard>

                            {paymentRequired && (
                                <div className="grid gap-3 rounded-xl border border-amber-200 bg-amber-50 p-4">
                                    <div>
                                        <p className="text-xs font-semibold uppercase tracking-[0.12em] text-amber-700">
                                            Montaj durumu
                                        </p>
                                        <p className="mt-1 text-lg font-semibold text-amber-950">{statusLabel}</p>
                                    </div>
                                    <p className="text-sm font-semibold text-amber-900">
                                        Bu ürün için montaj ödemesi gereklidir.
                                    </p>
                                    {payment && (
                                        <p className="text-sm text-amber-800">
                                            Tutar: {payment.amount} {payment.currency}
                                        </p>
                                    )}
                                    <div className="flex flex-wrap gap-2">
                                        {actions?.create_payment_url && (
                                            <Link
                                                href={actions.create_payment_url}
                                                method="post"
                                                as="button"
                                                className="rounded-lg bg-slate-950 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-slate-800"
                                            >
                                                {actions.payment_label ?? 'Montaj ödemesi yap'}
                                            </Link>
                                        )}
                                        {actions?.multi_product_url && (
                                            <Link
                                                href={actions.multi_product_url}
                                                method="post"
                                                as="button"
                                                className="rounded-lg border border-amber-200 bg-white px-4 py-2 text-sm font-semibold text-amber-900 shadow-sm hover:bg-amber-100"
                                            >
                                                {actions.multi_product_label ?? 'Birden fazla ürün için montaj talebim var'}
                                            </Link>
                                        )}
                                        {payment?.fake_approve_url && (
                                            <a
                                                href={payment.fake_approve_url}
                                                className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800"
                                            >
                                                Fake ödeme onayla
                                            </a>
                                        )}
                                    </div>
                                </div>
                            )}

                            {formReady && (
                                <form onSubmit={handleSubmit} className="grid gap-5 rounded-xl border border-emerald-200 bg-emerald-50 p-4">
                                    {viewState === 'multi_product_ready' && (
                                        <p className="rounded-lg border border-emerald-200 bg-white p-3 text-sm font-semibold text-emerald-900">
                                            Birden fazla ürün için montaj talebiniz alınmaya hazır. Operasyon ekibi sizinle iletişime geçecektir.
                                        </p>
                                    )}

                                    {draftRestored && (
                                        <div className="flex flex-wrap items-center justify-between gap-3 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm font-semibold text-blue-800">
                                            <span>Kaldığınız yerden devam edebilirsiniz.</span>
                                            <button
                                                type="button"
                                                onClick={clearDraft}
                                                className="rounded-md border border-blue-200 bg-white px-3 py-1 text-xs font-semibold text-blue-800 hover:bg-blue-100"
                                            >
                                                Formu temizle
                                            </button>
                                        </div>
                                    )}

                                    {(submitError || form.errors.form) && (
                                        <p className="rounded-lg border border-red-200 bg-red-50 p-3 text-sm font-semibold text-red-700">
                                            {submitError || form.errors.form}
                                        </p>
                                    )}

                                    {submitStatus && (
                                        <p className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm font-semibold text-blue-800">
                                            {submitStatus}
                                        </p>
                                    )}

                                    <FormAccordionCard
                                        title="Konumunu ekle"
                                        summary="Konum paylaşırsan usta seçimi ve yol ücreti daha doğru hesaplanır."
                                        tone="location"
                                        defaultOpen
                                    >
                                        <div className="flex flex-wrap items-center justify-between gap-3">
                                            <div>
                                                <p className="text-sm font-semibold text-emerald-950">Konumunu ekle</p>
                                                <p className="mt-1 text-xs text-emerald-800">
                                                    Konum paylaşırsan usta seçimi ve yol ücreti daha doğru hesaplanır.
                                                </p>
                                            </div>
                                            <button
                                                type="button"
                                                onClick={openLocationModal}
                                                className="rounded-lg bg-emerald-700 px-4 py-2 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800"
                                            >
                                                Konumumu kullan
                                            </button>
                                        </div>
                                        {form.data.location_map_url ? (
                                            <div className="flex flex-wrap items-center gap-3 rounded-lg border border-emerald-200 bg-white px-3 py-2 text-xs font-semibold text-emerald-900">
                                                <span>Konum alındı</span>
                                                <span className="text-emerald-700">
                                                    {form.data.location_latitude}, {form.data.location_longitude}
                                                </span>
                                                <a
                                                    href={form.data.location_map_url}
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-blue-700 underline-offset-4 hover:underline"
                                                >
                                                    Haritada aç
                                                </a>
                                            </div>
                                        ) : null}
                                        {locationStatus ? (
                                            <p className="rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900">
                                                {locationStatus}
                                            </p>
                                        ) : null}
                                    </FormAccordionCard>

                                    <FormAccordionCard
                                        title="İletişim bilgileri"
                                        summary="Ad, soyad ve ulaşılabilir telefon"
                                        tone="customer"
                                        defaultOpen
                                    >
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <label className="block text-sm font-semibold text-slate-800">
                                            İsim
                                            <input
                                                type="text"
                                                value={form.data.first_name}
                                                onChange={(event) => form.setData('first_name', event.target.value)}
                                                className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                                required
                                            />
                                            <FieldError message={form.errors.first_name} />
                                        </label>

                                        <label className="block text-sm font-semibold text-slate-800">
                                            Soyisim
                                            <input
                                                type="text"
                                                value={form.data.last_name}
                                                onChange={(event) => form.setData('last_name', event.target.value)}
                                                className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                                required
                                            />
                                            <FieldError message={form.errors.last_name} />
                                        </label>
                                    </div>

                                    <label className="block text-sm font-semibold text-slate-800">
                                        Telefon Numarası
                                        <div className="mt-1 flex rounded-lg border border-slate-200 bg-white shadow-sm focus-within:border-blue-500 focus-within:ring-2 focus-within:ring-blue-100">
                                            <span className="flex items-center border-r border-slate-200 px-3 text-sm font-semibold text-slate-600">
                                                +90
                                            </span>
                                            <input
                                                type="tel"
                                                value={form.data.phone}
                                                onChange={(event) => form.setData('phone', normalizePhoneDigits(event.target.value))}
                                                placeholder="5xxxxxxxxx"
                                                inputMode="numeric"
                                                pattern="[0-9]*"
                                                maxLength={10}
                                                className="w-full rounded-r-lg border-0 bg-transparent px-3 py-2 text-sm text-slate-950 focus:outline-none focus:ring-0"
                                                required
                                            />
                                        </div>
                                        <p className="mt-1 text-xs font-medium text-slate-500">
                                            +90 sonrası 10 hane girin.
                                        </p>
                                        <FieldError message={form.errors.phone} />
                                    </label>
                                    </FormAccordionCard>

                                    <FormAccordionCard
                                        title="Adres bilgileri"
                                        summary="İl, ilçe, açık adres ve kapı detayları"
                                        tone="address"
                                        defaultOpen
                                    >
                                    <div className="grid gap-3 sm:grid-cols-2">
                                        <label className="block text-sm font-semibold text-slate-800">
                                            İl
                                            <select
                                                value={form.data.city}
                                                onChange={(event) => handleCityChange(event.target.value)}
                                                className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                                required
                                            >
                                                <option value="">İl seçin</option>
                                                {cityHasFallback ? <option value={form.data.city}>Mevcut değer: {form.data.city}</option> : null}
                                                {TURKEY_PROVINCES.map((province) => (
                                                    <option key={province.plateCode} value={province.name}>
                                                        {province.name}
                                                    </option>
                                                ))}
                                            </select>
                                            <FieldError message={form.errors.city} />
                                        </label>

                                        <label className="block text-sm font-semibold text-slate-800">
                                            İlçe
                                            <div className="relative mt-1">
                                                <input
                                                    type="search"
                                                    value={districtSearch}
                                                    onChange={(event) => {
                                                        const value = event.target.value;

                                                        setDistrictSearch(value);
                                                        setDistrictDropdownOpen(Boolean(form.data.city));
                                                        form.setData('district', value === '' ? '' : normalizeDistrictForForm(form.data.city, value));
                                                    }}
                                                    onFocus={() => setDistrictDropdownOpen(Boolean(form.data.city))}
                                                    onBlur={() => window.setTimeout(() => setDistrictDropdownOpen(false), 120)}
                                                    disabled={!form.data.city}
                                                    placeholder={form.data.city ? 'İlçe ara' : 'Önce il seçin'}
                                                    role="combobox"
                                                    aria-expanded={districtDropdownOpen && Boolean(form.data.city)}
                                                    aria-controls="district-options"
                                                    aria-autocomplete="list"
                                                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 pr-10 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100 disabled:cursor-not-allowed disabled:bg-slate-100 disabled:text-slate-500"
                                                    required
                                                />
                                                <button
                                                    type="button"
                                                    onMouseDown={(event) => event.preventDefault()}
                                                    onClick={() => setDistrictDropdownOpen((open) => (form.data.city ? !open : false))}
                                                    disabled={!form.data.city}
                                                    className="absolute inset-y-1 right-1 rounded-md px-2 text-xs font-semibold text-slate-500 transition hover:bg-slate-100 disabled:cursor-not-allowed disabled:text-slate-300"
                                                    aria-label="İlçe listesini aç"
                                                >
                                                    ⌄
                                                </button>
                                                {form.data.city && districtDropdownOpen ? (
                                                    <div
                                                        id="district-options"
                                                        role="listbox"
                                                        className="absolute z-20 mt-2 max-h-60 w-full overflow-auto rounded-lg border border-slate-200 bg-white py-1 text-sm shadow-xl"
                                                    >
                                                        {districtHasFallback ? (
                                                            <button
                                                                type="button"
                                                                role="option"
                                                                aria-selected={form.data.district === districtSearch}
                                                                onMouseDown={(event) => event.preventDefault()}
                                                                onClick={() => handleDistrictChange(form.data.district)}
                                                                className="block w-full px-3 py-2 text-left text-slate-700 transition hover:bg-blue-50"
                                                            >
                                                                Mevcut değer: {form.data.district}
                                                            </button>
                                                        ) : null}
                                                        {filteredDistrictOptions.length > 0 ? filteredDistrictOptions.map((district) => (
                                                            <button
                                                                key={district.normalizedName}
                                                                type="button"
                                                                role="option"
                                                                aria-selected={form.data.district === district.name}
                                                                onMouseDown={(event) => event.preventDefault()}
                                                                onClick={() => handleDistrictChange(district.name)}
                                                                className={`block w-full px-3 py-2 text-left transition hover:bg-blue-50 ${
                                                                    form.data.district === district.name
                                                                        ? 'bg-blue-50 font-semibold text-blue-700'
                                                                        : 'text-slate-700'
                                                                }`}
                                                            >
                                                                {district.name}
                                                            </button>
                                                        )) : (
                                                            <p className="px-3 py-2 text-sm text-slate-500">
                                                                Sonuç bulunamadı.
                                                            </p>
                                                        )}
                                                    </div>
                                                ) : null}
                                            </div>
                                            <FieldError message={form.errors.district} />
                                        </label>
                                    </div>

                                    <label className="block text-sm font-semibold text-slate-800">
                                        Adres
                                        <textarea
                                            value={form.data.address}
                                            onChange={(event) => form.setData('address', event.target.value)}
                                            className="mt-1 min-h-28 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                            required
                                        />
                                        <FieldError message={form.errors.address} />
                                    </label>

                                    <div className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4">
                                        <p className="text-sm font-semibold text-slate-900">Adres detayları</p>
                                        <div className="grid gap-3 sm:grid-cols-5">
                                            {[
                                                ['building_no', 'Bina No'],
                                                ['apartment_no', 'Daire No'],
                                                ['door_no', 'Kapı No'],
                                                ['floor_no', 'Kat'],
                                                ['site_name', 'Site/Apartman adı'],
                                            ].map(([field, label]) => (
                                                <label key={field} className="block text-xs font-semibold text-slate-700 sm:col-span-1">
                                                    {label}
                                                    <input
                                                        type="text"
                                                        value={String(form.data[field as keyof CustomerForm] ?? '')}
                                                        onChange={(event) => form.setData(field as keyof CustomerForm, event.target.value)}
                                                        className="mt-1 w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                                    />
                                                </label>
                                            ))}
                                        </div>
                                    </div>
                                    </FormAccordionCard>

                                    <input type="hidden" name="location_latitude" value={form.data.location_latitude} />
                                    <input type="hidden" name="location_longitude" value={form.data.location_longitude} />
                                    <input type="hidden" name="location_place_id" value={form.data.location_place_id} />
                                    <input type="hidden" name="location_formatted_address" value={form.data.location_formatted_address} />
                                    <input type="hidden" name="location_map_url" value={form.data.location_map_url} />

                                    {(allowMultiProductRequest || form.data.multiple_products) && (
                                        <FormAccordionCard
                                            title="Ek ürün / seri"
                                            summary="Aynı faturadaki diğer ürünler için montaj seçimi"
                                            tone="serial"
                                            defaultOpen={form.data.multiple_products || viewState === 'multi_product_ready'}
                                        >
                                    {allowMultiProductRequest && (
                                        <label className="flex items-start gap-2 rounded-xl border border-slate-200 bg-white p-4 text-sm font-semibold text-slate-900">
                                            <input
                                                type="checkbox"
                                                checked={form.data.multiple_products}
                                                onChange={(event) => {
                                                    form.setData('multiple_products', event.target.checked);

                                                    if (!event.target.checked) {
                                                        setMultiProductOptions([]);
                                                        setMultiProductOperationOnlyCount(0);
                                                        setMultiProductTotalCount(0);
                                                        setMultiProductModalOpen(false);
                                                        setMultiProductNotice('');
                                                        form.setData('selected_invoice_serials', []);
                                                    } else {
                                                        setMultiProductLoading(Boolean(actions?.multi_product_lookup_url));
                                                        setMultiProductNotice(actions?.multi_product_lookup_url ? '' : 'Ek ürün talebiniz operasyon ekibine iletilecek.');
                                                    }
                                                }}
                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-500"
                                            />
                                            Bu adres için birden fazla ürün montaj talebim var
                                        </label>
                                    )}

                                    {form.data.multiple_products && multiProductLoading && (
                                        <p className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm font-semibold text-blue-800">
                                            Aynı faturadaki ürünler kontrol ediliyor...
                                        </p>
                                    )}

                                    {form.data.multiple_products && !multiProductLoading && multiProductNotice && (
                                        <p className="rounded-lg border border-amber-200 bg-amber-50 p-3 text-sm font-semibold text-amber-900">
                                            {multiProductNotice}
                                        </p>
                                    )}

                                    {form.data.multiple_products && multiProductOptions.length > 0 && !multiProductModalOpen && (
                                        <div className="grid gap-3 rounded-xl border border-slate-200 bg-white p-4">
                                            {multiProductTotalCount > 1 ? (
                                                <div className="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-900">
                                                    <p>Bu faturada birden fazla ürün görünüyor. Montaj istediğiniz ürünleri seçebilirsiniz.</p>
                                                    {multiProductOperationOnlyCount > multiProductOptions.length ? (
                                                        <p className="mt-1 text-xs text-emerald-800">
                                                            Operasyon ekibi diğer ürünleri ayrıca kontrol edebilir.
                                                        </p>
                                                    ) : null}
                                                </div>
                                            ) : null}
                                            <p className="text-sm font-semibold text-slate-900">
                                                Bu adreste montajını istediğiniz diğer ürünleri seçin.
                                            </p>
                                            <button
                                                type="button"
                                                onClick={() => setMultiProductModalOpen(true)}
                                                className="w-fit rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs font-semibold text-amber-900 hover:bg-amber-100"
                                            >
                                                Ürün listesini aç
                                            </button>
                                            <div className="grid gap-2">
                                                {multiProductOptions.map((item) => {
                                                    const serialNumber = item.serial_number ?? '';

                                                    if (!serialNumber) {
                                                        return null;
                                                    }

                                                    return (
                                                        <label key={serialNumber} className="flex items-start gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                                            <input
                                                                type="checkbox"
                                                                checked={form.data.selected_invoice_serials.includes(serialNumber)}
                                                                onChange={(event) => toggleSelectedInvoiceSerial(serialNumber, event.target.checked)}
                                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-500"
                                                            />
                                                            <span>
                                                                <span className="block font-semibold text-slate-950">{item.product_name || 'Ürün bilgisi'}</span>
                                                                <span className="block text-xs text-slate-500">
                                                                    Seri no: {serialNumber}
                                                                    {item.product_model ? ` · Model: ${item.product_model}` : ''}
                                                                </span>
                                                            </span>
                                                        </label>
                                                    );
                                                })}
                                            </div>
                                        </div>
                                    )}
                                        </FormAccordionCard>
                                    )}

                                    <FormAccordionCard
                                        title="Görseller"
                                        summary="Kapı ön yüzü, yan yüzü ve arka yüzü fotoğrafları"
                                        tone="door"
                                        defaultOpen
                                    >
                                        <div>
                                            <p className="text-sm font-semibold text-slate-900">Kapı fotoğrafları</p>
                                            <p className="mt-1 text-xs text-slate-500">Kapının ön, yan ve arka yüzünü ekleyin.</p>
                                        </div>
                                        <div className="grid min-w-0 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                                            {DOOR_PHOTO_FIELDS.map((field) => {
                                                const preview = doorPhotoPreviews[field.code];

                                                return (
                                                    <div key={field.code} className="grid min-w-0 max-w-full gap-3 overflow-hidden rounded-xl border border-slate-200 bg-slate-50 p-3">
                                                        <p className="text-sm font-semibold text-slate-900">{field.label}</p>
                                                        {preview?.url ? (
                                                            <img src={preview.url} alt={field.label} className="aspect-[4/3] w-full max-w-full rounded-lg object-cover" />
                                                        ) : (
                                                            <DoorPhotoPlaceholder fieldCode={field.code} />
                                                        )}
                                                        {preview ? (
                                                            <div className="min-w-0 rounded-lg border border-slate-200 bg-white p-2 text-xs text-slate-600">
                                                                <p className="truncate font-semibold text-slate-900" title={preview.name}>{preview.name}</p>
                                                                <p>{preview.sizeLabel}</p>
                                                                {preview.error ? <p className="mt-1 font-semibold text-red-600">{preview.error}</p> : <p className="mt-1 font-semibold text-emerald-700">Seçildi</p>}
                                                            </div>
                                                        ) : null}
                                                        <div className="flex min-w-0 flex-wrap gap-2">
                                                            <label className="cursor-pointer rounded-lg bg-slate-950 px-3 py-2 text-xs font-semibold text-white hover:bg-slate-800">
                                                                Kameradan çek
                                                                <input
                                                                    type="file"
                                                                    accept={DOOR_PHOTO_ACCEPT}
                                                                    capture="environment"
                                                                    className="hidden"
                                                                    onChange={(event) => {
                                                                        void handleDoorPhotoChange(field.code, event.target.files?.[0]);
                                                                        event.currentTarget.value = '';
                                                                    }}
                                                                />
                                                            </label>
                                                            <label className="cursor-pointer rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                                                Dosyalardan seç
                                                                <input
                                                                    type="file"
                                                                    accept={DOOR_PHOTO_ACCEPT}
                                                                    className="hidden"
                                                                    onChange={(event) => {
                                                                        void handleDoorPhotoChange(field.code, event.target.files?.[0]);
                                                                        event.currentTarget.value = '';
                                                                    }}
                                                                />
                                                            </label>
                                                            {preview ? (
                                                                <button
                                                                    type="button"
                                                                    onClick={() => removeDoorPhoto(field.code)}
                                                                    className="rounded-lg border border-red-200 bg-red-50 px-3 py-2 text-xs font-semibold text-red-700 hover:bg-red-100"
                                                                >
                                                                    Kaldır/değiştir
                                                                </button>
                                                            ) : null}
                                                        </div>
                                                        <FieldError message={form.errors[field.code]} />
                                                    </div>
                                                );
                                            })}
                                        </div>
                                    </FormAccordionCard>

                                    <label className="grid gap-2 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700">
                                        <span className="flex items-start gap-2 font-semibold text-slate-900">
                                            <input
                                                type="checkbox"
                                                checked={form.data.installation_consent}
                                                onChange={(event) => form.setData('installation_consent', event.target.checked)}
                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-500"
                                                required
                                            />
                                            Montaj şartlarını kabul ediyorum
                                        </span>
                                        <span className="whitespace-pre-line text-xs leading-5 text-slate-600">
                                            {INSTALLATION_CONSENT_TEXT}
                                        </span>
                                        <FieldError message={form.errors.installation_consent} />
                                    </label>

                                    <label className="grid gap-2 rounded-xl border border-slate-200 bg-white p-4 text-sm text-slate-700">
                                        <span className="flex items-start gap-2 font-semibold text-slate-900">
                                            <input
                                                type="checkbox"
                                                checked={form.data.kvkk_consent}
                                                onChange={(event) => form.setData('kvkk_consent', event.target.checked)}
                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-500"
                                                required
                                            />
                                            KVKK / Aydınlatma ve Açık Rıza Onayı
                                        </span>
                                        <a
                                            href="https://emaksprime.com/kvkk-on-bilgilendirme/"
                                            target="_blank"
                                            rel="noreferrer"
                                            className="text-xs font-semibold text-blue-700 underline-offset-4 hover:underline"
                                        >
                                            KVKK ön bilgilendirme metnini aç
                                        </a>
                                        <FieldError message={form.errors.kvkk_consent} />
                                    </label>

                                    <button
                                        type="submit"
                                        disabled={form.processing}
                                        className="w-fit rounded-lg bg-emerald-700 px-5 py-2.5 text-sm font-semibold text-white shadow-sm hover:bg-emerald-800 disabled:cursor-not-allowed disabled:opacity-60"
                                    >
                                        {form.processing ? 'Gönderiliyor...' : 'Montaj Talebini Gönder'}
                                    </button>
                                </form>
                            )}
                        </>
                    )}
                </section>
                {multiProductModalOpen && (
                    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 px-4 py-6">
                        <div className="grid max-h-[90vh] w-full max-w-2xl gap-4 overflow-auto rounded-2xl bg-white p-5 shadow-2xl">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold text-slate-950">Bu adreste montaj istediğiniz diğer ürünleri seçin</h2>
                                    <p className="mt-1 text-sm text-slate-600">Sadece bu talep için seçilebilir ürünler gösterilir.</p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setMultiProductModalOpen(false)}
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                                >
                                    Kapat
                                </button>
                            </div>
                            {multiProductTotalCount > 1 ? (
                                <div className="rounded-xl border border-emerald-200 bg-emerald-50 p-3 text-sm font-semibold text-emerald-900">
                                    <p>Bu faturada birden fazla ürün görünüyor. Montaj istediğiniz ürünleri seçebilirsiniz.</p>
                                    {multiProductOperationOnlyCount > multiProductOptions.length ? (
                                        <p className="mt-1 text-xs text-emerald-800">
                                            Operasyon ekibi diğer ürünleri ayrıca kontrol edebilir.
                                        </p>
                                    ) : null}
                                </div>
                            ) : null}
                            <div className="grid gap-2">
                                {multiProductOptions.map((item) => {
                                    const serialNumber = item.serial_number ?? '';

                                    if (!serialNumber) {
                                        return null;
                                    }

                                    return (
                                        <label key={serialNumber} className="flex items-start gap-3 rounded-xl border border-slate-200 bg-slate-50 p-3 text-sm text-slate-700">
                                            <input
                                                type="checkbox"
                                                checked={form.data.selected_invoice_serials.includes(serialNumber)}
                                                onChange={(event) => toggleSelectedInvoiceSerial(serialNumber, event.target.checked)}
                                                className="mt-1 h-4 w-4 rounded border-slate-300 text-blue-700 focus:ring-blue-500"
                                            />
                                            <span>
                                                <span className="block font-semibold text-slate-950">{item.product_name || 'Ürün bilgisi'}</span>
                                                <span className="block text-xs text-slate-500">
                                                    Seri no: {serialNumber}
                                                    {item.product_model ? ` · Model: ${item.product_model}` : ''}
                                                </span>
                                            </span>
                                        </label>
                                    );
                                })}
                            </div>
                            <button
                                type="button"
                                onClick={() => setMultiProductModalOpen(false)}
                                className="w-fit rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800"
                            >
                                Seçimi tamamla
                            </button>
                        </div>
                    </div>
                )}
                {locationModalOpen && (
                    <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/60 px-4 py-6">
                        <div className="grid max-h-[90vh] w-full max-w-3xl gap-4 overflow-auto rounded-2xl bg-white p-5 shadow-2xl">
                            <div className="flex items-start justify-between gap-3">
                                <div>
                                    <h2 className="text-lg font-semibold text-slate-950">Konumumu ekle</h2>
                                    <p className="mt-1 text-sm text-slate-600">
                                        Adres arayabilir, haritadan seçebilir veya mevcut konumunuzu kullanabilirsiniz.
                                    </p>
                                </div>
                                <button
                                    type="button"
                                    onClick={() => setLocationModalOpen(false)}
                                    className="rounded-lg border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-100"
                                >
                                    Kapat
                                </button>
                            </div>
                            {locationStatus ? (
                                <p className="rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm font-semibold text-blue-800">
                                    {locationStatus}
                                </p>
                            ) : null}
                            <div className="grid gap-3">
                                <input
                                    ref={searchInputRef}
                                    type="text"
                                    placeholder="Adres ara"
                                    className="w-full rounded-lg border border-slate-200 bg-white px-3 py-2 text-sm text-slate-950 shadow-sm focus:border-blue-500 focus:outline-none focus:ring-2 focus:ring-blue-100"
                                    disabled={!GOOGLE_MAPS_API_KEY}
                                />
                                <div ref={mapRef} className="h-72 rounded-xl border border-slate-200 bg-slate-100" />
                                <div className="flex flex-wrap gap-2">
                                    <button
                                        type="button"
                                        onClick={useCurrentLocation}
                                        className="rounded-lg bg-blue-700 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-800"
                                    >
                                        Mevcut konumumu kullan
                                    </button>
                                    {form.data.location_map_url ? (
                                        <a
                                            href={form.data.location_map_url}
                                            target="_blank"
                                            rel="noreferrer"
                                            className="rounded-lg border border-blue-200 bg-blue-50 px-4 py-2 text-sm font-semibold text-blue-800 hover:bg-blue-100"
                                        >
                                            Haritada aç
                                        </a>
                                    ) : null}
                                </div>
                            </div>
                        </div>
                    </div>
                )}
            </main>
        </>
    );
}
