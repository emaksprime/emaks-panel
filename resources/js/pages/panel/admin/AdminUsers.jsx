import { useEffect, useState } from 'react';
import { AlertTriangle, Building2, ChevronLeft, ChevronRight, Copy, KeyRound, Link2, Pencil, Plus, Save, Search, ShieldCheck, UserRound, Wrench, X } from 'lucide-react';
import { apiRequest } from '@/lib/api';
import { AdminFrame } from './AdminFrame.jsx';

const blank = {
    id: null,
    username: '',
    full_name: '',
    password: '',
    role_code: 'sales',
    temsilci_kodu: '',
    aktif: true,
    force_password_change: false,
    access: [],
    denied_access: [],
    strict_access: false,
};

const cloneBlank = {
    username: '',
    full_name: '',
    password: '',
    temsilci_kodu: '',
    aktif: true,
    force_password_change: true,
    strict_access: true,
};

const groupOrder = [
    'Satış Yönetimi',
    'Stok Yönetimi',
    'Sipariş Yönetimi',
    'Teknik Servis',
    'B2B',
    'Müşteri Yönetimi',
    'Proforma',
    'Muhasebe / Finans',
    'Destek',
    'Sistem Yönetimi',
    'Veri Kaynakları',
];

function groupResources(resources) {
    const uniqueResources = Array.from(
        new Map(resources.map((resource) => [resource.code, resource])).values(),
    );

    return uniqueResources.reduce((groups, resource) => {
        const key = resource.group || 'Sistem Yönetimi';
        return {
            ...groups,
            [key]: [...(groups[key] ?? []), resource],
        };
    }, {});
}

const typeLabels = {
    page: 'Ekranlar',
    action: 'Butonlar/Aksiyonlar',
    data_source: 'Veri kaynakları',
    scope: 'Kapsamlar/Scope’lar',
};

const typeOrder = ['page', 'action', 'data_source', 'scope', 'other'];

const salesScopeResourceCodes = new Set([
    'sales_main_all',
    'sales_online',
    'sales_bayi',
    'sales_rep_salih_cakir',
    'sales_rep_umit_yildiz',
    'sales_rep_bulent_saglam',
]);

const capabilityLabels = {
    dealer: 'Bayi',
    locksmith: 'Çilingir',
    manufacturer: 'Üretici',
    seller: 'Satıcı',
};

const partnerAssignmentLabels = {
    assigned: 'Partner atanmış',
    unassigned: 'Partner atanmamış',
    multiple: 'Birden fazla partner',
    inactive: 'Pasif üyeliği bulunan',
};

const capabilityMatchLabels = {
    any: 'Dahil',
    all: 'Tümü',
    exclude: 'Hariç',
};

const membershipScopeRows = [
    ['view', 'Genel görünüm'],
    ['manage', 'Yönetim'],
    ['orders', 'Siparişler'],
    ['stock', 'Stok'],
    ['finance', 'Finans'],
    ['technical_service', 'Teknik servis'],
    ['users', 'Kullanıcılar'],
];

const membershipAbilities = [
    ['can_view', 'Görüntüle'],
    ['can_create', 'Oluştur'],
    ['can_update', 'Güncelle'],
    ['can_approve', 'Onayla'],
];

const emptyMembershipScopes = () => Object.fromEntries(
    membershipScopeRows.map(([scope]) => [scope, {
        can_view: false,
        can_create: false,
        can_update: false,
        can_approve: false,
    }]),
);

const membershipPresets = {
    view: () => ({
        ...emptyMembershipScopes(),
        view: { can_view: true, can_create: false, can_update: false, can_approve: false },
    }),
    operations: () => ({
        ...emptyMembershipScopes(),
        view: { can_view: true, can_create: false, can_update: false, can_approve: false },
        orders: { can_view: true, can_create: true, can_update: true, can_approve: false },
        technical_service: { can_view: true, can_create: true, can_update: true, can_approve: false },
    }),
    manager: () => Object.fromEntries(
        membershipScopeRows.map(([scope]) => [scope, {
            can_view: true,
            can_create: scope !== 'finance',
            can_update: true,
            can_approve: ['manage', 'orders', 'finance', 'technical_service', 'users'].includes(scope),
        }]),
    ),
};

const blankMembership = () => ({
    partner_id: '',
    title: '',
    phone: '',
    active: true,
    technical_service_technician_id: '',
    preset: 'view',
    scopes: membershipPresets.view(),
});

const blankTechnicianLink = () => ({
    technical_service_technician_id: '',
    relationship_type: 'contracted_technician',
    is_primary: false,
    priority: 1,
    service_city: '',
    service_district: '',
    service_region_note: '',
});

function membershipToForm(membership) {
    const scopes = emptyMembershipScopes();

    Object.entries(membership.scopes ?? {}).forEach(([scope, abilities]) => {
        if (scopes[scope]) {
            scopes[scope] = { ...scopes[scope], ...abilities };
        }
    });

    return {
        partner_id: String(membership.partner_id),
        title: membership.title ?? '',
        phone: membership.phone ?? '',
        active: Boolean(membership.active),
        technical_service_technician_id: membership.technical_service_technician_id
            ? String(membership.technical_service_technician_id)
            : '',
        preset: 'custom',
        scopes,
    };
}

function membershipScopesPayload(scopes) {
    return membershipScopeRows.map(([access_scope]) => ({
        access_scope,
        ...scopes[access_scope],
    }));
}

function activeScopeSummary(scopes) {
    const labels = membershipScopeRows
        .filter(([scope]) => Object.values(scopes?.[scope] ?? {}).some(Boolean))
        .map(([, label]) => label);

    return labels.length > 0 ? labels.join(', ') : 'Yetki verilmemiş';
}

const defaultFilters = {
    search: '',
    active: '',
    role_code: '',
    partner_assignment: '',
    capabilities: [],
    capability_match: 'any',
    partner_id: '',
    page: 1,
};

function adminUsersUrl(filters) {
    const params = new URLSearchParams();

    Object.entries(filters).forEach(([key, value]) => {
        if (key === 'capabilities') {
            value.forEach((capability) => params.append('capabilities[]', capability));
        } else if (value !== '' && value !== null && value !== undefined) {
            params.set(key, String(value));
        }
    });

    params.set('per_page', '100');

    return `/api/admin/users?${params.toString()}`;
}

function resourceTypeLabel(type) {
    return typeLabels[type] ?? 'Diğer izinler';
}

function groupResourcesByType(resources) {
    return resources.reduce((groups, resource) => {
        const key = salesScopeResourceCodes.has(resource.code)
            ? 'scope'
            : typeLabels[resource.type]
                ? resource.type
                : 'other';
        return {
            ...groups,
            [key]: [...(groups[key] ?? []), resource],
        };
    }, {});
}

function userToForm(user) {
    return {
        ...blank,
        ...user,
        password: '',
        access: user.access ?? [],
        denied_access: user.denied_access ?? [],
        strict_access: false,
    };
}

function comparableForm(value) {
    return JSON.stringify({
        ...value,
        access: [...(value.access ?? [])].sort(),
        denied_access: [...(value.denied_access ?? [])].sort(),
    });
}

export default function AdminUsers() {
    const [data, setData] = useState({
        users: [],
        roles: [],
        resources: [],
        rolePermissions: {},
        partners: [],
        meta: { page: 1, last_page: 1, filtered_total: 0, total: 0 },
    });
    const [form, setForm] = useState(blank);
    const [formBaseline, setFormBaseline] = useState(blank);
    const [isEditorOpen, setIsEditorOpen] = useState(false);
    const [filters, setFilters] = useState(defaultFilters);
    const [partnerQuery, setPartnerQuery] = useState('');
    const [reloadKey, setReloadKey] = useState(0);
    const [permissionQuery, setPermissionQuery] = useState('');
    const [status, setStatus] = useState({ type: 'idle', message: '' });
    const [isLoading, setIsLoading] = useState(true);
    const [isSaving, setIsSaving] = useState(false);
    const [cloneSource, setCloneSource] = useState(null);
    const [cloneForm, setCloneForm] = useState(cloneBlank);
    const [isCloning, setIsCloning] = useState(false);
    const [membershipDialog, setMembershipDialog] = useState(null);
    const [membershipForm, setMembershipForm] = useState(blankMembership);
    const [membershipSaving, setMembershipSaving] = useState(false);
    const [membershipAdvanced, setMembershipAdvanced] = useState(false);
    const [membershipPartnerQuery, setMembershipPartnerQuery] = useState('');
    const [includeInactivePartners, setIncludeInactivePartners] = useState(false);
    const [membershipTechnicianLinks, setMembershipTechnicianLinks] = useState([]);
    const [membershipTechniciansLoading, setMembershipTechniciansLoading] = useState(false);
    const [technicianDialogPartner, setTechnicianDialogPartner] = useState(null);
    const [technicianLinks, setTechnicianLinks] = useState([]);
    const [technicianOptions, setTechnicianOptions] = useState([]);
    const [technicianQuery, setTechnicianQuery] = useState('');
    const [technicianLinkForm, setTechnicianLinkForm] = useState(blankTechnicianLink);
    const [technicianSaving, setTechnicianSaving] = useState(false);

    useEffect(() => {
        const controller = new AbortController();
        const delay = filters.search.trim() === '' ? 0 : 250;
        const timeout = window.setTimeout(async () => {
            setIsLoading(true);

            try {
                const next = await apiRequest(adminUsersUrl(filters), { signal: controller.signal });
                setData(next);
            } catch (error) {
                if (error.name !== 'AbortError') {
                    setStatus({ type: 'error', message: error.message });
                }
            } finally {
                if (!controller.signal.aborted) {
                    setIsLoading(false);
                }
            }
        }, delay);

        return () => {
            window.clearTimeout(timeout);
            controller.abort();
        };
    }, [filters, reloadKey]);

    const filteredUsers = data.users;
    const filteredPartnerOptions = data.partners.filter((partner) => [partner.display_name, partner.partner_code]
        .join(' ')
        .toLocaleLowerCase('tr-TR')
        .includes(partnerQuery.trim().toLocaleLowerCase('tr-TR')));
    const hasActiveFilters = Boolean(
        filters.search ||
        filters.active ||
        filters.role_code ||
        filters.partner_assignment ||
        filters.capabilities.length ||
        filters.partner_id,
    );

    const updateFilter = (key, value) => {
        setFilters((current) => ({ ...current, [key]: value, page: 1 }));
    };

    const toggleCapability = (capability) => {
        setFilters((current) => ({
            ...current,
            capabilities: current.capabilities.includes(capability)
                ? current.capabilities.filter((item) => item !== capability)
                : [...current.capabilities, capability],
            page: 1,
        }));
    };

    const clearFilters = () => {
        setFilters(defaultFilters);
        setPartnerQuery('');
    };

    const groupedResources = groupResources(data.resources);
    const groupedResourceEntries = Object.entries(groupedResources).sort(
        ([left], [right]) =>
            (groupOrder.indexOf(left) === -1 ? 999 : groupOrder.indexOf(left)) -
            (groupOrder.indexOf(right) === -1 ? 999 : groupOrder.indexOf(right)),
    );
    const selectedRole = data.roles.find((role) => role.code === form.role_code);
    const selectedRoleIsSuperAdmin = Boolean(selectedRole?.is_super_admin ?? selectedRole?.isSuperAdmin);
    const selectedUser = data.users.find((user) => user.id === form.id) ?? null;
    const selectedMemberships = selectedUser?.partner_memberships ?? [];
    const activeResourceCodes = data.resources.map((resource) => resource.code);
    const roleAllowedResources = new Set(data.rolePermissions?.[form.role_code] ?? []);
    const isDirty = comparableForm(form) !== comparableForm(formBaseline);
    const normalizedPermissionQuery = permissionQuery.trim().toLocaleLowerCase('tr-TR');
    const filteredGroupedResourceEntries = groupedResourceEntries
        .map(([groupName, resources]) => [
            groupName,
            resources.filter((resource) => {
                if (normalizedPermissionQuery === '') {
                    return true;
                }

                return [groupName, resource.name, resource.code, resource.type]
                    .join(' ')
                    .toLocaleLowerCase('tr-TR')
                    .includes(normalizedPermissionQuery);
            }),
            resources,
        ])
        .filter(([, visibleResources]) => visibleResources.length > 0);

    const canDiscardEditor = () =>
        !isDirty || window.confirm('Kaydedilmemiş değişiklikler silinsin mi?');

    const closeEditor = () => {
        if (!canDiscardEditor()) {
            return;
        }

        setIsEditorOpen(false);
        setForm(blank);
        setFormBaseline(blank);
        setPermissionQuery('');
        setStatus({ type: 'idle', message: '' });
    };

    const openNewUser = () => {
        if (!canDiscardEditor()) {
            return;
        }

        setForm(blank);
        setFormBaseline(blank);
        setPermissionQuery('');
        setStatus({ type: 'idle', message: '' });
        setIsEditorOpen(true);
    };

    const applyRoleDefaults = (roleCode) => {
        const activeCodes = new Set(activeResourceCodes);
        const defaults = (data.rolePermissions?.[roleCode] ?? []).filter((code) => activeCodes.has(code));

        setForm((current) => ({
            ...current,
            role_code: roleCode,
            access: defaults,
            denied_access: [],
            strict_access: false,
        }));
    };

    const save = async (event) => {
        event.preventDefault();
        setIsSaving(true);
        setStatus({ type: 'idle', message: '' });

        try {
            const next = await apiRequest('/api/admin/users', {
                method: 'POST',
                body: JSON.stringify(form),
            });
            setData(next);
            const savedUser = next.users.find((user) => user.username === form.username);
            const savedForm = savedUser ? userToForm(savedUser) : blank;
            setForm(savedForm);
            setFormBaseline(savedForm);
            setReloadKey((current) => current + 1);
            setStatus({
                type: 'success',
                message: 'Kullanıcı kaydedildi ve yetkileri güncellendi.',
            });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setIsSaving(false);
        }
    };

    const openClone = (user) => {
        setCloneSource(user);
        setCloneForm(cloneBlank);
        setStatus({ type: 'idle', message: '' });
    };

    const closeClone = () => {
        setCloneSource(null);
        setCloneForm(cloneBlank);
        setIsCloning(false);
    };

    const cloneUser = async (event) => {
        event.preventDefault();

        if (!cloneSource) {
            return;
        }

        setIsCloning(true);
        setStatus({ type: 'idle', message: '' });

        try {
            const next = await apiRequest(`/api/admin/users/${cloneSource.id}/clone`, {
                method: 'POST',
                body: JSON.stringify(cloneForm),
            });
            setData(next);
            setReloadKey((current) => current + 1);
            closeClone();
            setStatus({
                type: 'success',
                message: 'Kullanıcı kopyalandı. Rol ve izinler kaynak kullanıcıdan alındı.',
            });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
            setIsCloning(false);
        }
    };

    const editUser = (user) => {
        if (!canDiscardEditor()) {
            return;
        }

        const nextForm = userToForm(user);
        setForm(nextForm);
        setFormBaseline(nextForm);
        setPermissionQuery('');
        setStatus({ type: 'idle', message: '' });
        setIsEditorOpen(true);
    };

    const setAccessState = (code, state) => {
        setForm((current) => ({
            ...current,
            access:
                state === 'allow'
                    ? [...new Set([...current.access, code])]
                    : current.access.filter((item) => item !== code),
            denied_access:
                state === 'deny'
                    ? [...new Set([...(current.denied_access ?? []), code])]
                    : (current.denied_access ?? []).filter((item) => item !== code),
        }));
    };

    const accessState = (code) => {
        if (form.access.includes(code)) {
            return 'allow';
        }

        if ((form.denied_access ?? []).includes(code)) {
            return 'deny';
        }

        return 'inherit';
    };

    const effectiveAccess = (code) => {
        const state = accessState(code);

        if (state === 'allow') {
            return true;
        }

        if (state === 'deny') {
            return false;
        }

        return roleAllowedResources.has(code);
    };
    const effectiveB2BAccess = [
        'b2b.view',
        'b2b.manage',
        'b2b.partner_users.manage',
        'b2b.dealers.view',
        'b2b.locksmiths.view',
        'b2b.manufacturers.view',
        'b2b.sellers.view',
    ].some((code) => activeResourceCodes.includes(code) && effectiveAccess(code));

    const moduleAccessState = (resources) => {
        const enabledCount = resources.filter((resource) => effectiveAccess(resource.code)).length;

        if (enabledCount === 0) {
            return 'none';
        }

        return enabledCount === resources.length ? 'all' : 'partial';
    };

    const setModuleAccess = (groupName, resources, enabled) => {
        const codes = resources
            .filter((resource) => enabled === false || groupName !== 'Satış Yönetimi' || !salesScopeResourceCodes.has(resource.code))
            .map((resource) => resource.code);

        setForm((current) => ({
            ...current,
            access: enabled
                ? [...new Set([...(current.access ?? []), ...codes])]
                : (current.access ?? []).filter((code) => !codes.includes(code)),
            denied_access: enabled
                ? (current.denied_access ?? []).filter((code) => !codes.includes(code))
                : [...new Set([...(current.denied_access ?? []), ...codes])],
        }));
    };

    const selectAll = () => {
        setForm((current) => ({
            ...current,
            access: data.resources.map((resource) => resource.code),
            denied_access: [],
            strict_access: false,
        }));
    };

    const clearAccess = () => {
        setForm((current) => ({
            ...current,
            access: [],
            denied_access: [],
            strict_access: false,
        }));
    };

    const applyStrictAccess = () => {
        if (selectedRoleIsSuperAdmin) {
            setStatus({
                type: 'error',
                message: 'Super admin rolü sadece seçilen kaynaklarla sınırlandırılamaz.',
            });

            return;
        }

        setForm((current) => {
            const allowed = [...new Set([...(current.access ?? []), 'dashboard'])];

            return {
                ...current,
                access: allowed,
                denied_access: activeResourceCodes.filter((code) => !allowed.includes(code)),
                strict_access: true,
            };
        });
        setStatus({
            type: 'success',
            message: 'Sadece seçilen kaynaklar izinli, diğer aktif kaynaklar engelli olarak işaretlendi.',
        });
    };

    const partnerById = (partnerId) => data.partners.find((partner) => partner.id === Number(partnerId)) ?? null;
    const membershipPartnerOptions = data.partners.filter((partner) => {
        if (!partner.can_manage_memberships || (!partner.active && !includeInactivePartners)) {
            return false;
        }

        const query = membershipPartnerQuery.trim().toLocaleLowerCase('tr-TR');
        return query === '' || [partner.display_name, partner.partner_code, ...(partner.capabilities ?? [])]
            .join(' ')
            .toLocaleLowerCase('tr-TR')
            .includes(query);
    });

    const loadMembershipTechnicians = async (partnerId) => {
        if (!partnerId) {
            setMembershipTechnicianLinks([]);
            return;
        }

        setMembershipTechniciansLoading(true);
        try {
            const response = await apiRequest(`/api/b2b/partners/${partnerId}/technicians`);
            setMembershipTechnicianLinks((response.items ?? []).filter((link) => link.active && link.technician?.active));
        } catch (error) {
            setMembershipTechnicianLinks([]);
            setStatus({ type: 'error', message: error.message });
        } finally {
            setMembershipTechniciansLoading(false);
        }
    };

    const openMembership = (membership = null) => {
        if (!form.id) {
            setStatus({ type: 'error', message: 'Partner ataması için önce kullanıcı kaydını oluşturun.' });
            return;
        }

        const nextForm = membership ? membershipToForm(membership) : blankMembership();
        setMembershipDialog({ mode: membership ? 'edit' : 'create', membership });
        setMembershipForm(nextForm);
        setMembershipAdvanced(false);
        setMembershipPartnerQuery('');
        setIncludeInactivePartners(false);
        setMembershipTechnicianLinks([]);
        void loadMembershipTechnicians(nextForm.partner_id);
    };

    const selectMembershipPartner = (partnerId) => {
        setMembershipForm((current) => ({
            ...current,
            partner_id: partnerId,
            technical_service_technician_id: '',
        }));
        void loadMembershipTechnicians(partnerId);
    };

    const applyMembershipPreset = (preset) => {
        setMembershipForm((current) => ({
            ...current,
            preset,
            scopes: membershipPresets[preset]?.() ?? current.scopes,
        }));
    };

    const setMembershipAbility = (scope, ability, value) => {
        setMembershipForm((current) => ({
            ...current,
            preset: 'custom',
            scopes: {
                ...current.scopes,
                [scope]: { ...current.scopes[scope], [ability]: value },
            },
        }));
    };

    const saveMembership = async (event) => {
        event.preventDefault();
        if (!form.id || !membershipForm.partner_id) {
            setStatus({ type: 'error', message: 'Kullanıcı ve partner seçimi zorunludur.' });
            return;
        }

        const partnerId = Number(membershipForm.partner_id);
        const editing = membershipDialog?.mode === 'edit';
        const path = editing
            ? `/api/b2b/partners/${partnerId}/users/${form.id}`
            : `/api/b2b/partners/${partnerId}/users`;
        setMembershipSaving(true);

        try {
            await apiRequest(path, {
                method: editing ? 'PATCH' : 'POST',
                body: JSON.stringify({
                    user_id: form.id,
                    title: membershipForm.title,
                    phone: membershipForm.phone,
                    active: membershipForm.active,
                    technical_service_technician_id: membershipForm.technical_service_technician_id === ''
                        ? null
                        : Number(membershipForm.technical_service_technician_id),
                    scopes: membershipScopesPayload(membershipForm.scopes),
                }),
            });
            setMembershipDialog(null);
            setReloadKey((current) => current + 1);
            setStatus({ type: 'success', message: editing ? 'Partner üyeliği güncellendi.' : 'Partner üyeliği eklendi.' });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setMembershipSaving(false);
        }
    };

    const deactivateMembership = async (membership) => {
        if (!form.id || !window.confirm(`${membership.partner_name} üyeliği pasife alınsın mı?`)) {
            return;
        }

        setMembershipSaving(true);
        try {
            await apiRequest(`/api/b2b/partners/${membership.partner_id}/users/${form.id}`, { method: 'DELETE' });
            setMembershipDialog(null);
            setReloadKey((current) => current + 1);
            setStatus({ type: 'success', message: 'Partner üyeliği audit korunarak pasife alındı.' });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setMembershipSaving(false);
        }
    };

    const loadTechnicianManager = async (partner, search = technicianQuery) => {
        if (!partner) {
            return;
        }

        setTechnicianSaving(true);
        try {
            const params = new URLSearchParams({ partner_id: String(partner.id) });
            if (search.trim() !== '') {
                params.set('search', search.trim());
            }
            const [linksResponse, optionsResponse] = await Promise.all([
                apiRequest(`/api/b2b/partners/${partner.id}/technicians`),
                apiRequest(`/api/b2b/locksmith-technicians?${params.toString()}`),
            ]);
            setTechnicianLinks(linksResponse.items ?? []);
            setTechnicianOptions(optionsResponse.items ?? []);
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setTechnicianSaving(false);
        }
    };

    const openTechnicianManager = (partner) => {
        if (!partner?.can_manage_technicians) {
            setStatus({ type: 'error', message: 'Bu partnerin usta bağlantılarını yönetme yetkiniz yok.' });
            return;
        }

        setTechnicianDialogPartner(partner);
        setTechnicianLinks([]);
        setTechnicianOptions([]);
        setTechnicianQuery('');
        setTechnicianLinkForm(blankTechnicianLink());
        void loadTechnicianManager(partner, '');
    };

    const createTechnicianLink = async (event) => {
        event.preventDefault();
        if (!technicianDialogPartner || !technicianLinkForm.technical_service_technician_id) {
            setStatus({ type: 'error', message: 'Bağlanacak usta seçilmelidir.' });
            return;
        }

        setTechnicianSaving(true);
        try {
            await apiRequest(`/api/b2b/partners/${technicianDialogPartner.id}/technicians`, {
                method: 'POST',
                body: JSON.stringify({
                    ...technicianLinkForm,
                    technical_service_technician_id: Number(technicianLinkForm.technical_service_technician_id),
                    priority: Number(technicianLinkForm.priority || 0),
                }),
            });
            setTechnicianLinkForm(blankTechnicianLink());
            await loadTechnicianManager(technicianDialogPartner, technicianQuery);
            setStatus({ type: 'success', message: 'Usta partnera bağlandı.' });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setTechnicianSaving(false);
        }
    };

    const patchTechnicianLink = async (link, override = {}) => {
        if (!technicianDialogPartner) {
            return;
        }

        setTechnicianSaving(true);
        try {
            await apiRequest(`/api/b2b/partners/${technicianDialogPartner.id}/technicians/${link.id}`, {
                method: 'PATCH',
                body: JSON.stringify({
                    relationship_type: link.relationship_type,
                    is_primary: link.is_primary,
                    active: link.active,
                    service_city: link.service_city ?? '',
                    service_district: link.service_district ?? '',
                    service_region_note: link.service_region_note ?? '',
                    priority: Number(link.priority || 0),
                    ...override,
                }),
            });
            await loadTechnicianManager(technicianDialogPartner, technicianQuery);
            setReloadKey((current) => current + 1);
            setStatus({ type: 'success', message: 'Usta bağlantısı güncellendi.' });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setTechnicianSaving(false);
        }
    };

    const deactivateTechnicianLink = async (link) => {
        if (!technicianDialogPartner || !window.confirm(`${link.technician?.name ?? 'Usta'} bağlantısı pasife alınsın mı?`)) {
            return;
        }

        setTechnicianSaving(true);
        try {
            await apiRequest(`/api/b2b/partners/${technicianDialogPartner.id}/technicians/${link.id}`, { method: 'DELETE' });
            await loadTechnicianManager(technicianDialogPartner, technicianQuery);
            setReloadKey((current) => current + 1);
            setStatus({ type: 'success', message: 'Usta bağlantısı pasife alındı.' });
        } catch (error) {
            setStatus({ type: 'error', message: error.message });
        } finally {
            setTechnicianSaving(false);
        }
    };

    return (
        <AdminFrame title="Kullanıcı Yönetimi">
            <section
                data-testid="admin-users-workspace"
                className="relative grid min-w-0 items-start gap-4 xl:grid-cols-[minmax(0,1fr)_minmax(420px,min(460px,38vw))]"
            >
                <div
                    data-testid="admin-users-list-panel"
                    className="min-w-0 space-y-3 xl:max-h-[calc(100vh-18rem)] xl:overflow-y-auto xl:pr-1"
                >
                    <div className="rounded-lg border border-slate-200 bg-white p-4 shadow-sm">
                        <div className="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <p className="text-xs font-semibold uppercase text-slate-500">Panel kullanıcıları</p>
                                <h2 className="mt-1 text-lg font-semibold text-slate-950">
                                    {data.meta.filtered_total} / {data.meta.total} kayıt
                                </h2>
                            </div>
                            <button
                                type="button"
                                onClick={openNewUser}
                                className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-slate-950 px-3 text-sm font-semibold text-white transition hover:bg-slate-800"
                            >
                                <Plus aria-hidden="true" className="size-4" />
                                Yeni kullanıcı
                            </button>
                        </div>

                        <div className="mt-4 grid gap-2 sm:grid-cols-2 2xl:grid-cols-4">
                            <label className="relative block min-w-0 sm:col-span-2">
                                <span className="sr-only">Kullanıcı veya partner ara</span>
                                <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                <input
                                    className="h-10 w-full rounded-lg border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
                                    placeholder="Ad, kullanıcı, temsilci veya partner ara"
                                    value={filters.search}
                                    onChange={(event) => updateFilter('search', event.target.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-slate-500">
                                Hesap durumu
                                <select
                                    className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700"
                                    value={filters.active}
                                    onChange={(event) => updateFilter('active', event.target.value)}
                                >
                                    <option value="">Tümü</option>
                                    <option value="active">Aktif</option>
                                    <option value="inactive">Pasif</option>
                                </select>
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-slate-500">
                                Temel rol
                                <select
                                    className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700"
                                    value={filters.role_code}
                                    onChange={(event) => updateFilter('role_code', event.target.value)}
                                >
                                    <option value="">Tüm roller</option>
                                    {data.roles.map((role) => (
                                        <option key={role.code} value={role.code}>{role.name}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-slate-500">
                                Partner ataması
                                <select
                                    className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700"
                                    value={filters.partner_assignment}
                                    onChange={(event) => updateFilter('partner_assignment', event.target.value)}
                                >
                                    <option value="">Tümü</option>
                                    {Object.entries(partnerAssignmentLabels).map(([value, label]) => (
                                        <option key={value} value={value}>{label}</option>
                                    ))}
                                </select>
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-slate-500">
                                Partner ara
                                <input
                                    className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal text-slate-700"
                                    placeholder="Ad veya kod"
                                    value={partnerQuery}
                                    onChange={(event) => setPartnerQuery(event.target.value)}
                                />
                            </label>
                            <label className="grid gap-1 text-xs font-semibold text-slate-500 sm:col-span-2">
                                Belirli partner
                                <select
                                    className="h-10 min-w-0 rounded-lg border border-slate-200 bg-white px-3 text-sm font-medium text-slate-700"
                                    value={filters.partner_id}
                                    onChange={(event) => updateFilter('partner_id', event.target.value)}
                                >
                                    <option value="">Tüm görünür partnerler</option>
                                    {filteredPartnerOptions.map((partner) => (
                                        <option key={partner.id} value={partner.id}>
                                            {partner.partner_code} — {partner.display_name}
                                        </option>
                                    ))}
                                </select>
                            </label>
                        </div>

                        <div className="mt-3 flex flex-col gap-2 border-t border-slate-100 pt-3 2xl:flex-row 2xl:items-center 2xl:justify-between">
                            <div className="flex flex-wrap items-center gap-1.5" aria-label="Partner kabiliyeti filtresi">
                                {Object.entries(capabilityLabels).map(([capability, label]) => {
                                    const active = filters.capabilities.includes(capability);

                                    return (
                                        <button
                                            key={capability}
                                            type="button"
                                            aria-pressed={active}
                                            onClick={() => toggleCapability(capability)}
                                            className={`h-8 rounded-lg border px-2.5 text-xs font-semibold transition ${active ? 'border-blue-600 bg-blue-600 text-white' : 'border-slate-200 bg-white text-slate-600 hover:border-slate-400'}`}
                                        >
                                            {label}
                                        </button>
                                    );
                                })}
                            </div>
                            <div className="flex items-center gap-2">
                                <span className="text-xs font-semibold text-slate-500">Eşleşme</span>
                                <div className="inline-flex rounded-lg border border-slate-200 bg-slate-50 p-0.5">
                                    {Object.entries(capabilityMatchLabels).map(([value, label]) => (
                                        <button
                                            key={value}
                                            type="button"
                                            onClick={() => updateFilter('capability_match', value)}
                                            className={`h-7 rounded-md px-2 text-xs font-semibold transition ${filters.capability_match === value ? 'bg-white text-slate-950 shadow-sm' : 'text-slate-500'}`}
                                        >
                                            {label}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        </div>

                        {hasActiveFilters && (
                            <div className="mt-3 flex flex-wrap items-center gap-1.5 border-t border-slate-100 pt-3">
                                {filters.search && <span className="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600">Arama: {filters.search}</span>}
                                {filters.active && <span className="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600">Durum: {filters.active === 'active' ? 'Aktif' : 'Pasif'}</span>}
                                {filters.role_code && <span className="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600">Rol: {filters.role_code}</span>}
                                {filters.partner_assignment && <span className="rounded-md bg-slate-100 px-2 py-1 text-xs text-slate-600">{partnerAssignmentLabels[filters.partner_assignment]}</span>}
                                {filters.capabilities.map((capability) => (
                                    <span key={capability} className="rounded-md bg-blue-50 px-2 py-1 text-xs font-medium text-blue-700">
                                        {capabilityLabels[capability]}
                                    </span>
                                ))}
                                <button type="button" onClick={clearFilters} className="ml-auto inline-flex h-8 items-center gap-1 rounded-lg px-2 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                    <X aria-hidden="true" className="size-3.5" />
                                    Filtreleri temizle
                                </button>
                            </div>
                        )}
                    </div>

                    <div className="overflow-hidden rounded-lg border border-slate-200 bg-white shadow-sm">
                        <div className="overflow-x-auto">
                            <table className="w-full min-w-[560px] table-fixed text-sm 2xl:min-w-[650px]">
                                <thead className="bg-slate-50 text-left text-xs font-semibold uppercase text-slate-500">
                                    <tr>
                                        <th className="w-[34%] px-4 py-3">Kullanıcı</th>
                                        <th className="w-[19%] px-4 py-3">Rol</th>
                                        <th className="hidden w-[14%] px-4 py-3 2xl:table-cell">Temsilci</th>
                                        <th className="w-[15%] px-4 py-3">Yetki</th>
                                        <th className="w-[12%] px-4 py-3">Durum</th>
                                        <th className="w-[20%] px-4 py-3" />
                                    </tr>
                                </thead>
                                <tbody>
                                    {isLoading && (
                                        <tr>
                                            <td className="px-4 py-8 text-center text-slate-500" colSpan={6}>
                                                Kullanıcılar yükleniyor...
                                            </td>
                                        </tr>
                                    )}

                                    {!isLoading && filteredUsers.length === 0 && (
                                        <tr>
                                            <td className="px-4 py-8 text-center text-slate-500" colSpan={6}>
                                                Bu filtreyle kullanıcı bulunamadı.
                                            </td>
                                        </tr>
                                    )}

                                    {filteredUsers.map((user) => {
                                        const selected = isEditorOpen && form.id === user.id;

                                        return (
                                            <tr
                                                key={user.id}
                                                data-selected={selected ? 'true' : 'false'}
                                                className={`border-t border-slate-100 transition ${selected ? 'bg-blue-50/70 shadow-[inset_3px_0_0_#2563eb]' : 'hover:bg-slate-50'}`}
                                            >
                                                <td className="px-4 py-3">
                                                    <p className="truncate font-semibold text-slate-950">{user.full_name}</p>
                                                    <p className="truncate text-xs text-slate-500">{user.username}</p>
                                                    {(user.partner_memberships?.length ?? 0) > 0 && (
                                                        <div className="mt-1.5 flex min-w-0 flex-wrap gap-1" aria-label="Partner Atamaları">
                                                            {user.partner_memberships.slice(0, 2).map((membership) => (
                                                                <span
                                                                    key={membership.id}
                                                                    title={`${membership.partner_code} — ${membership.partner_name}`}
                                                                    className={`inline-flex max-w-full items-center gap-1 rounded-md px-1.5 py-0.5 text-[11px] font-medium ${membership.active ? 'bg-blue-50 text-blue-700' : 'bg-slate-100 text-slate-500'}`}
                                                                >
                                                                    <span className="max-w-28 truncate">{membership.partner_name}</span>
                                                                    <span className="shrink-0 text-[10px] opacity-70">
                                                                        {membership.capabilities.map((capability) => capabilityLabels[capability] ?? capability).join('/')}
                                                                    </span>
                                                                </span>
                                                            ))}
                                                            {user.partner_memberships.length > 2 && (
                                                                <span className="rounded-md bg-slate-100 px-1.5 py-0.5 text-[11px] font-semibold text-slate-500">
                                                                    +{user.partner_memberships.length - 2} daha
                                                                </span>
                                                            )}
                                                        </div>
                                                    )}
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className="inline-flex max-w-full rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-700">
                                                        <span className="truncate">{user.role_code}</span>
                                                    </span>
                                                </td>
                                                <td className="hidden truncate px-4 py-3 text-slate-600 2xl:table-cell">
                                                    {user.temsilci_kodu || '-'}
                                                </td>
                                                <td className="px-4 py-3 text-xs text-slate-600">
                                                    {(user.access?.length ?? 0)} izin
                                                    <span className="block text-slate-400">{(user.denied_access?.length ?? 0)} engel</span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <span className={`rounded-md px-2 py-1 text-xs font-semibold ${user.aktif ? 'bg-emerald-50 text-emerald-700' : 'bg-rose-50 text-rose-700'}`}>
                                                        {user.aktif ? 'Aktif' : 'Pasif'}
                                                    </span>
                                                </td>
                                                <td className="px-4 py-3">
                                                    <div className="flex justify-end gap-1.5">
                                                        <button
                                                            type="button"
                                                            title="Kullanıcıyı kopyala"
                                                            aria-label="Kopyala"
                                                            onClick={() => openClone(user)}
                                                            className="inline-flex size-9 shrink-0 items-center justify-center gap-1.5 rounded-lg border border-slate-200 text-xs font-semibold text-slate-600 transition hover:border-blue-200 hover:bg-blue-50 hover:text-blue-700 2xl:w-auto 2xl:px-2.5"
                                                        >
                                                            <Copy aria-hidden="true" className="size-3.5" />
                                                            <span className="hidden 2xl:inline">Kopyala</span>
                                                        </button>
                                                        <button
                                                            type="button"
                                                            title="Kullanıcıyı düzenle"
                                                            aria-label="Düzenle"
                                                            onClick={() => editUser(user)}
                                                            className="inline-flex size-9 shrink-0 items-center justify-center gap-1.5 rounded-lg border border-slate-200 text-xs font-semibold text-slate-700 transition hover:border-slate-400 hover:bg-white 2xl:w-auto 2xl:px-2.5"
                                                        >
                                                            <Pencil aria-hidden="true" className="size-3.5" />
                                                            <span className="hidden 2xl:inline">Düzenle</span>
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        );
                                    })}
                                </tbody>
                            </table>
                        </div>
                        {data.meta.last_page > 1 && (
                            <div className="flex items-center justify-between border-t border-slate-200 px-4 py-3 text-xs text-slate-500">
                                <span>Sayfa {data.meta.page} / {data.meta.last_page}</span>
                                <div className="flex gap-1.5">
                                    <button
                                        type="button"
                                        title="Önceki sayfa"
                                        aria-label="Önceki sayfa"
                                        disabled={data.meta.page <= 1}
                                        onClick={() => setFilters((current) => ({ ...current, page: current.page - 1 }))}
                                        className="inline-flex size-8 items-center justify-center rounded-lg border border-slate-200 text-slate-600 disabled:opacity-40"
                                    >
                                        <ChevronLeft aria-hidden="true" className="size-4" />
                                    </button>
                                    <button
                                        type="button"
                                        title="Sonraki sayfa"
                                        aria-label="Sonraki sayfa"
                                        disabled={data.meta.page >= data.meta.last_page}
                                        onClick={() => setFilters((current) => ({ ...current, page: current.page + 1 }))}
                                        className="inline-flex size-8 items-center justify-center rounded-lg border border-slate-200 text-slate-600 disabled:opacity-40"
                                    >
                                        <ChevronRight aria-hidden="true" className="size-4" />
                                    </button>
                                </div>
                            </div>
                        )}
                    </div>
                </div>

                {isEditorOpen ? (
                    <div className="fixed inset-0 z-50 bg-slate-950/35 xl:sticky xl:top-4 xl:z-auto xl:h-[calc(100vh-18rem)] xl:min-h-[480px] xl:bg-transparent">
                        <button
                            type="button"
                            aria-label="Kullanıcı editörünü kapat"
                            onClick={closeEditor}
                            className="absolute inset-0 xl:hidden"
                        />
                        <form
                            data-testid="admin-user-editor"
                            aria-labelledby="admin-user-editor-title"
                            aria-describedby="admin-user-editor-description"
                            onSubmit={save}
                            onKeyDown={(event) => {
                                if (event.key === 'Escape') {
                                    event.stopPropagation();
                                    closeEditor();
                                }
                            }}
                            className="absolute inset-y-0 right-0 flex w-full min-w-0 flex-col overflow-hidden bg-white shadow-2xl sm:w-[460px] xl:relative xl:h-full xl:w-full xl:rounded-lg xl:border xl:border-slate-200 xl:shadow-sm"
                        >
                            <header className="flex shrink-0 items-start justify-between gap-3 border-b border-slate-200 px-4 py-3">
                                <div className="min-w-0">
                                    <p id="admin-user-editor-description" className="text-xs font-semibold uppercase text-slate-500">
                                        {form.id ? 'Kullanıcı düzenle' : 'Yeni kullanıcı'}
                                    </p>
                                    <h2 id="admin-user-editor-title" className="mt-1 truncate text-lg font-semibold text-slate-950">
                                        {form.full_name || 'Kullanıcı bilgileri'}
                                    </h2>
                                    <div className="mt-2 flex flex-wrap items-center gap-1.5 text-xs">
                                        <span className="max-w-full truncate rounded-md bg-slate-100 px-2 py-1 font-medium text-slate-600">
                                            {form.username || 'Kullanıcı adı bekleniyor'}
                                        </span>
                                        <span className="rounded-md bg-blue-50 px-2 py-1 font-semibold text-blue-700">
                                            {form.role_code}
                                        </span>
                                        <span className={`rounded-md border px-2 py-1 font-semibold ${form.aktif ? 'border-emerald-200 bg-emerald-50 text-emerald-700' : 'border-rose-200 bg-rose-50 text-rose-700'}`}>
                                            Hesap: {form.aktif ? 'Aktif' : 'Pasif'}
                                        </span>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    title="Editörü kapat"
                                    aria-label="Editörü kapat"
                                    onClick={closeEditor}
                                    className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 transition hover:bg-slate-50 hover:text-slate-900"
                                >
                                    <X aria-hidden="true" className="size-4" />
                                </button>
                            </header>

                            <div
                                data-testid="admin-user-editor-scroll"
                                className="min-h-0 min-w-0 flex-1 space-y-5 overflow-x-hidden overflow-y-auto overscroll-contain px-4 py-4"
                            >
                                {status.message && (
                                    <div
                                        role="status"
                                        className={`rounded-lg px-3 py-2 text-sm font-medium ${status.type === 'error' ? 'bg-rose-50 text-rose-700' : 'bg-emerald-50 text-emerald-700'}`}
                                    >
                                        {status.message}
                                    </div>
                                )}

                                <section aria-labelledby="basic-info-heading" className="space-y-3 border-b border-slate-200 pb-5">
                                    <h3 id="basic-info-heading" className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                        <UserRound aria-hidden="true" className="size-4 text-slate-500" />
                                        Temel Bilgiler
                                    </h3>
                                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                        <label className="grid gap-1.5 text-sm font-medium text-slate-700">
                                            Kullanıcı adı
                                            <input
                                                required
                                                className="h-10 rounded-lg border border-slate-200 px-3 font-normal outline-none transition focus:border-slate-400"
                                                placeholder="ornek.kullanici"
                                                value={form.username}
                                                onChange={(event) => setForm({ ...form, username: event.target.value })}
                                            />
                                        </label>
                                        <label className="grid gap-1.5 text-sm font-medium text-slate-700">
                                            Ad soyad
                                            <input
                                                required
                                                className="h-10 rounded-lg border border-slate-200 px-3 font-normal outline-none transition focus:border-slate-400"
                                                placeholder="Ad Soyad"
                                                value={form.full_name}
                                                onChange={(event) => setForm({ ...form, full_name: event.target.value })}
                                            />
                                        </label>
                                    </div>
                                </section>

                                <section aria-labelledby="role-status-heading" className="space-y-3 border-b border-slate-200 pb-5">
                                    <h3 id="role-status-heading" className="text-sm font-semibold text-slate-900">Rol ve Durum</h3>
                                    <div className="grid gap-3 sm:grid-cols-2 xl:grid-cols-1">
                                        <label className="grid gap-1.5 text-sm font-medium text-slate-700">
                                            Rol
                                            <select
                                                className="h-10 rounded-lg border border-slate-200 bg-white px-3 font-normal outline-none transition focus:border-slate-400"
                                                value={form.role_code}
                                                onChange={(event) => applyRoleDefaults(event.target.value)}
                                            >
                                                {data.roles.map((role) => (
                                                    <option key={role.code} value={role.code}>{role.name}</option>
                                                ))}
                                            </select>
                                            <span className="text-xs font-normal leading-5 text-slate-500">
                                                Rol seçilince varsayılan izinler otomatik işaretlenir. B2B rolleri şirket içi satış/stok/sipariş ekranlarını açmaz; partner bazlı entity yetkileri Partner Kullanıcıları ekranından yönetilir.
                                            </span>
                                        </label>
                                        <label className="grid gap-1.5 text-sm font-medium text-slate-700">
                                            Temsilci kodu
                                            <input
                                                className="h-10 rounded-lg border border-slate-200 px-3 font-normal outline-none transition focus:border-slate-400"
                                                placeholder="0003"
                                                value={form.temsilci_kodu ?? ''}
                                                onChange={(event) => setForm({ ...form, temsilci_kodu: event.target.value })}
                                            />
                                        </label>
                                    </div>
                                    <fieldset className="rounded-lg border border-slate-200 bg-slate-50 p-3">
                                        <legend className="px-1 text-sm font-semibold text-slate-700">Hesap durumu</legend>
                                        <div className="grid grid-cols-2 gap-1 rounded-lg border border-slate-200 bg-white p-1" role="group" aria-label="Hesap durumu">
                                            <button
                                                type="button"
                                                aria-pressed={form.aktif}
                                                onClick={() => setForm({ ...form, aktif: true })}
                                                className={`h-9 rounded-md text-sm font-semibold transition ${form.aktif ? 'bg-emerald-600 text-white shadow-sm' : 'text-slate-500 hover:bg-slate-50'}`}
                                            >
                                                Aktif
                                            </button>
                                            <button
                                                type="button"
                                                aria-pressed={!form.aktif}
                                                onClick={() => setForm({ ...form, aktif: false })}
                                                className={`h-9 rounded-md text-sm font-semibold transition ${!form.aktif ? 'bg-rose-600 text-white shadow-sm' : 'text-slate-500 hover:bg-slate-50'}`}
                                            >
                                                Pasif
                                            </button>
                                        </div>
                                        <p className="mt-2 text-xs leading-5 text-slate-500">
                                            {form.aktif
                                                ? 'Aktif: Kullanıcı panele giriş yapabilir.'
                                                : 'Pasif: Kullanıcının tüm girişleri engellenir; partner üyelikleri audit için korunur.'}
                                        </p>
                                    </fieldset>
                                </section>

                                <section aria-labelledby="partner-link-heading" className="min-w-0 space-y-3 border-b border-slate-200 pb-5">
                                    <div className="flex flex-col items-stretch gap-3 sm:flex-row sm:items-start sm:justify-between">
                                        <div>
                                            <h3 id="partner-link-heading" className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                                <Building2 aria-hidden="true" className="size-4 text-slate-500" />
                                                Partner Atamaları
                                            </h3>
                                            <p className="mt-1 text-xs leading-5 text-slate-500">
                                                Temel rol menü ve varsayılan izinleri belirler. Partner atamaları kullanıcının erişebileceği şirket kayıtlarını belirler.
                                            </p>
                                        </div>
                                        <button
                                            type="button"
                                            onClick={() => openMembership()}
                                            disabled={!form.id || !data.partners.some((partner) => partner.can_manage_memberships)}
                                            className="inline-flex h-9 shrink-0 self-start items-center gap-1.5 rounded-lg border border-slate-200 px-2.5 text-xs font-semibold text-slate-700 transition hover:bg-slate-50 disabled:cursor-not-allowed disabled:opacity-45"
                                        >
                                            <Plus aria-hidden="true" className="size-3.5" />
                                            Partner ata
                                        </button>
                                    </div>

                                    {selectedMemberships.length > 0 && !effectiveB2BAccess && (
                                        <div className="flex gap-2 rounded-lg border border-amber-200 bg-amber-50 px-3 py-2 text-xs leading-5 text-amber-800">
                                            <AlertTriangle aria-hidden="true" className="mt-0.5 size-4 shrink-0" />
                                            <span>Aktif partner üyeliği var ancak seçili rol partner ekranı açmıyor. Uygun temel rolü veya açık bir kullanıcı iznini ayrıca seçin; sistem otomatik yetki vermedi.</span>
                                        </div>
                                    )}

                                    {form.id && selectedMemberships.length === 0 && (
                                        <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-center text-xs text-slate-500">
                                            Bu kullanıcıya partner atanmamış.
                                        </div>
                                    )}

                                    {!form.id && (
                                        <div className="rounded-lg border border-dashed border-slate-200 bg-slate-50 px-3 py-4 text-center text-xs text-slate-500">
                                            Partner ataması için önce kullanıcıyı kaydedin.
                                        </div>
                                    )}

                                    <div className="grid min-w-0 gap-2">
                                        {selectedMemberships.map((membership) => {
                                            const partner = partnerById(membership.partner_id);
                                            const canManageMembership = Boolean(partner?.can_manage_memberships);
                                            const canManageTechnicians = Boolean(partner?.can_manage_technicians);
                                            const isDealer = membership.capabilities?.includes('dealer');

                                            return (
                                                <article key={membership.id} className="min-w-0 rounded-lg border border-slate-200 bg-slate-50 p-3">
                                                    <div className="flex min-w-0 items-start justify-between gap-2">
                                                        <div className="min-w-0">
                                                            <p className="truncate text-sm font-semibold text-slate-900">{membership.partner_name}</p>
                                                            <p className="truncate text-xs text-slate-500">{membership.partner_code}</p>
                                                        </div>
                                                        <span className={`shrink-0 rounded-md px-2 py-1 text-[11px] font-semibold ${membership.active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}>
                                                            Üyelik: {membership.active ? 'Aktif' : 'Pasif'}
                                                        </span>
                                                    </div>
                                                    <div className="mt-2 flex flex-wrap gap-1">
                                                        {(membership.capabilities ?? []).map((capability) => (
                                                            <span key={capability} className="rounded-md bg-white px-1.5 py-0.5 text-[11px] font-semibold text-slate-600 ring-1 ring-slate-200">
                                                                {capabilityLabels[capability] ?? capability}
                                                            </span>
                                                        ))}
                                                    </div>
                                                    <dl className="mt-2 grid gap-1 text-xs text-slate-600">
                                                        <div><dt className="inline font-semibold text-slate-700">Profil: </dt><dd className="inline">{[membership.title, membership.phone].filter(Boolean).join(' · ') || '-'}</dd></div>
                                                        <div><dt className="inline font-semibold text-slate-700">Kapsam: </dt><dd className="inline">{activeScopeSummary(membership.scopes)}</dd></div>
                                                        <div>
                                                            <dt className="inline font-semibold text-slate-700">Usta profili: </dt>
                                                            <dd className={`inline ${membership.technical_service_technician_id && !membership.technician_mapping_valid ? 'font-semibold text-rose-700' : ''}`}>
                                                                {membership.linked_technician?.name ?? (membership.technical_service_technician_id ? 'Geçersiz veya pasif bağlantı' : 'Bağlı değil')}
                                                            </dd>
                                                        </div>
                                                    </dl>
                                                    <div className="mt-3 flex flex-wrap gap-2">
                                                        {canManageMembership && (
                                                            <button type="button" onClick={() => openMembership(membership)} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-slate-200 bg-white px-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-100">
                                                                <Pencil aria-hidden="true" className="size-3.5" />
                                                                Düzenle
                                                            </button>
                                                        )}
                                                        {canManageMembership && membership.active && (
                                                            <button type="button" onClick={() => void deactivateMembership(membership)} className="h-8 rounded-lg border border-rose-200 bg-white px-2.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">
                                                                Pasife al
                                                            </button>
                                                        )}
                                                        {canManageMembership && !membership.active && (
                                                            <button type="button" onClick={() => openMembership({ ...membership, active: true })} className="h-8 rounded-lg border border-emerald-200 bg-white px-2.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">
                                                                Yeniden etkinleştir
                                                            </button>
                                                        )}
                                                        {isDealer && canManageTechnicians && (
                                                            <button type="button" onClick={() => openTechnicianManager(partner)} className="inline-flex h-8 items-center gap-1.5 rounded-lg border border-blue-200 bg-white px-2.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">
                                                                <Wrench aria-hidden="true" className="size-3.5" />
                                                                Bağlı ustaları yönet
                                                            </button>
                                                        )}
                                                    </div>
                                                </article>
                                            );
                                        })}
                                    </div>
                                </section>

                                <section aria-labelledby="security-heading" className="space-y-3 border-b border-slate-200 pb-5">
                                    <h3 id="security-heading" className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                        <KeyRound aria-hidden="true" className="size-4 text-slate-500" />
                                        Güvenlik
                                    </h3>
                                    <label className="grid gap-1.5 text-sm font-medium text-slate-700">
                                        Şifre
                                        <input
                                            className="h-10 rounded-lg border border-slate-200 px-3 font-normal outline-none transition focus:border-slate-400"
                                            type="password"
                                            placeholder={form.id ? 'Değiştirmek için yeni şifre girin' : 'İlk şifre'}
                                            value={form.password}
                                            onChange={(event) => setForm({ ...form, password: event.target.value })}
                                            required={!form.id}
                                        />
                                    </label>
                                    <label className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-slate-50 px-3 py-2.5 text-sm font-medium text-slate-700">
                                        <span>
                                            İlk girişte şifre değiştirsin
                                            <small className="block font-normal text-slate-500">Sonraki girişte yeni şifre belirleme ekranı açılır.</small>
                                        </span>
                                        <input
                                            type="checkbox"
                                            className="size-4 accent-slate-950"
                                            checked={form.force_password_change}
                                            onChange={(event) => setForm({ ...form, force_password_change: event.target.checked })}
                                        />
                                    </label>
                                </section>

                                <section aria-labelledby="permissions-heading" className="space-y-3 pb-1">
                                    <div>
                                        <h3 id="permissions-heading" className="flex items-center gap-2 text-sm font-semibold text-slate-900">
                                            <ShieldCheck aria-hidden="true" className="size-4 text-slate-500" />
                                            İzinler
                                        </h3>
                                        <p className="mt-1 text-xs text-slate-500">
                                            Temel rol varsayılan erişimi belirler. Bu satırdaki kullanıcı kararı rolü açabilir veya engelleyebilir.
                                        </p>
                                    </div>
                                    <label className="relative block">
                                        <span className="sr-only">İzinlerde ara</span>
                                        <Search aria-hidden="true" className="pointer-events-none absolute left-3 top-1/2 size-4 -translate-y-1/2 text-slate-400" />
                                        <input
                                            className="h-10 w-full rounded-lg border border-slate-200 bg-slate-50 pl-9 pr-3 text-sm outline-none transition focus:border-slate-400 focus:bg-white"
                                            placeholder="İzin adı veya kodu ara"
                                            value={permissionQuery}
                                            onChange={(event) => setPermissionQuery(event.target.value)}
                                        />
                                    </label>
                                    <div className="flex flex-wrap gap-x-3 gap-y-2 text-xs font-semibold">
                                        <button type="button" onClick={selectAll} className="text-slate-700 hover:text-slate-950">Tümüne izin ver</button>
                                        <button
                                            type="button"
                                            onClick={applyStrictAccess}
                                            disabled={selectedRoleIsSuperAdmin}
                                            className="text-emerald-700 disabled:cursor-not-allowed disabled:text-slate-300"
                                        >
                                            Sadece seçilenlere izin ver
                                        </button>
                                        <button type="button" onClick={clearAccess} className="text-slate-500 hover:text-slate-800">Role bırak</button>
                                    </div>
                                    <div className="overflow-hidden rounded-lg border border-slate-200">
                                        {filteredGroupedResourceEntries.length === 0 && (
                                            <p className="px-3 py-6 text-center text-sm text-slate-500">Bu aramayla izin bulunamadı.</p>
                                        )}
                                        {filteredGroupedResourceEntries.map(([groupName, visibleResources, allResources]) => {
                                            const access = moduleAccessState(allResources);
                                            const resourcesByType = groupResourcesByType(visibleResources);

                                            return (
                                                <div key={groupName} className="border-b border-slate-200 last:border-b-0">
                                                    <div className="flex items-center justify-between gap-3 bg-slate-50 px-3 py-2.5">
                                                        <div className="min-w-0">
                                                            <p className="truncate text-xs font-semibold text-slate-700">{groupName}</p>
                                                            <p className="text-xs text-slate-500">
                                                                {access === 'all' ? 'Tümü açık' : access === 'partial' ? 'Kısmi erişim' : 'Kapalı'}
                                                            </p>
                                                        </div>
                                                        <label className="flex shrink-0 items-center gap-2 text-xs font-semibold text-slate-700">
                                                            Bu modüle erişim
                                                            <input
                                                                type="checkbox"
                                                                className="size-4 accent-slate-950"
                                                                checked={access !== 'none'}
                                                                onChange={(event) => setModuleAccess(groupName, allResources, event.target.checked)}
                                                            />
                                                        </label>
                                                    </div>

                                                    <div className="divide-y divide-slate-100">
                                                        {typeOrder
                                                            .filter((type) => resourcesByType[type]?.length)
                                                            .map((type) => (
                                                                <div key={`${groupName}-${type}`}>
                                                                    <p className="bg-white px-3 pb-1 pt-2 text-xs font-semibold text-slate-400">
                                                                        {resourceTypeLabel(type)}
                                                                    </p>
                                                                    {resourcesByType[type].map((resource) => {
                                                                        const state = accessState(resource.code);
                                                                        const effective = effectiveAccess(resource.code);
                                                                        const source = state === 'inherit'
                                                                            ? 'Rol'
                                                                            : state === 'allow'
                                                                                ? 'Kullanıcı izni'
                                                                                : 'Kullanıcı engeli';

                                                                        return (
                                                                            <div key={resource.code} className="grid gap-2 px-3 py-2.5 text-sm sm:grid-cols-[minmax(0,1fr)_172px] sm:items-center xl:grid-cols-1">
                                                                                <span className="min-w-0">
                                                                                    <span className="block truncate font-medium text-slate-800">{resource.name}</span>
                                                                                    <span className="block truncate text-xs text-slate-400">{resource.code}</span>
                                                                                    <span className="mt-1 flex flex-wrap gap-1">
                                                                                        <span className={`rounded-md px-1.5 py-0.5 text-[11px] font-semibold ${effective ? 'bg-emerald-50 text-emerald-700' : 'bg-slate-100 text-slate-600'}`}>
                                                                                            Efektif: {effective ? 'Açık' : 'Kapalı'}
                                                                                        </span>
                                                                                        <span className="rounded-md bg-blue-50 px-1.5 py-0.5 text-[11px] font-medium text-blue-700">
                                                                                            Kaynak: {source}
                                                                                        </span>
                                                                                    </span>
                                                                                </span>
                                                                                <select
                                                                                    aria-label={`${resource.name} erişimi`}
                                                                                    className="h-9 rounded-lg border border-slate-200 bg-white px-2 text-xs font-semibold text-slate-700 outline-none transition focus:border-slate-400"
                                                                                    value={state}
                                                                                    onChange={(event) => setAccessState(resource.code, event.target.value)}
                                                                                >
                                                                                    <option value="inherit">
                                                                                        Rol kararını kullan — {roleAllowedResources.has(resource.code) ? 'Açık' : 'Kapalı'}
                                                                                    </option>
                                                                                    <option value="allow">İzin ver</option>
                                                                                    <option value="deny">Engelle</option>
                                                                                </select>
                                                                            </div>
                                                                        );
                                                                    })}
                                                                </div>
                                                            ))}
                                                    </div>
                                                </div>
                                            );
                                        })}
                                    </div>
                                </section>
                            </div>

                            <footer
                                data-testid="admin-user-editor-actions"
                                className="sticky bottom-0 z-10 flex shrink-0 items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-3"
                            >
                                <span className={`text-xs font-medium ${isDirty ? 'text-amber-700' : 'text-slate-400'}`}>
                                    {isDirty ? 'Kaydedilmemiş değişiklik var' : 'Değişiklik yok'}
                                </span>
                                <div className="flex gap-2">
                                    <button
                                        type="button"
                                        onClick={closeEditor}
                                        className="inline-flex h-10 items-center justify-center rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-600 transition hover:bg-slate-50"
                                    >
                                        Vazgeç
                                    </button>
                                    <button
                                        type="submit"
                                        className="inline-flex h-10 items-center justify-center gap-2 rounded-lg bg-slate-950 px-3 text-sm font-semibold text-white transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                        disabled={isSaving}
                                    >
                                        <Save aria-hidden="true" className="size-4" />
                                        {isSaving ? 'Kaydediliyor...' : 'Kaydet'}
                                    </button>
                                </div>
                            </footer>
                        </form>
                    </div>
                ) : (
                    <aside
                        data-testid="admin-user-editor-empty"
                        className="hidden rounded-lg border border-dashed border-slate-300 bg-white px-6 py-10 text-center xl:sticky xl:top-4 xl:block"
                    >
                        <UserRound aria-hidden="true" className="mx-auto size-8 text-slate-300" />
                        <p className="mt-3 text-sm font-semibold text-slate-800">Kullanıcı seçilmedi</p>
                        <p className="mt-1 text-sm text-slate-500">Bir kullanıcıyı düzenleyin veya yeni kayıt açın.</p>
                        <button
                            type="button"
                            onClick={openNewUser}
                            className="mt-4 inline-flex h-10 items-center gap-2 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-700 transition hover:bg-slate-50"
                        >
                            <Plus aria-hidden="true" className="size-4" />
                            Yeni kullanıcı
                        </button>
                    </aside>
                )}
            </section>

            {membershipDialog && (
                <div className="fixed inset-0 z-[60] grid place-items-center bg-slate-950/55 p-2 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="membership-dialog-title">
                    <form onSubmit={saveMembership} className="flex max-h-[calc(100vh-1rem)] w-full max-w-4xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl sm:max-h-[calc(100vh-2rem)]">
                        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-5">
                            <div className="min-w-0">
                                <p className="text-xs font-semibold uppercase text-slate-500">Partner üyeliği</p>
                                <h2 id="membership-dialog-title" className="mt-1 truncate text-lg font-semibold text-slate-950">
                                    {membershipDialog.mode === 'edit' ? 'Partner atamasını düzenle' : 'Kullanıcıya partner ata'}
                                </h2>
                                <p className="mt-1 text-xs text-slate-500">{form.full_name} · Üyelik ve kullanıcı kaydı ayrı audit edilir.</p>
                            </div>
                            <button type="button" aria-label="Partner atama penceresini kapat" onClick={() => setMembershipDialog(null)} className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50">
                                <X aria-hidden="true" className="size-4" />
                            </button>
                        </header>

                        <div className="min-h-0 flex-1 space-y-5 overflow-y-auto px-4 py-4 sm:px-5">
                            <section className="grid gap-3 rounded-lg border border-slate-200 bg-slate-50 p-3 sm:grid-cols-2">
                                {membershipDialog.mode === 'create' ? (
                                    <>
                                        <label className="grid gap-1.5 text-sm font-semibold text-slate-700 sm:col-span-2">
                                            Partner ara
                                            <input className="h-10 rounded-lg border border-slate-200 bg-white px-3 font-normal" value={membershipPartnerQuery} onChange={(event) => setMembershipPartnerQuery(event.target.value)} placeholder="Partner adı, kodu veya tipi" />
                                        </label>
                                        <label className="grid gap-1.5 text-sm font-semibold text-slate-700 sm:col-span-2">
                                            Partner
                                            <select required className="h-10 rounded-lg border border-slate-200 bg-white px-3 font-normal" value={membershipForm.partner_id} onChange={(event) => selectMembershipPartner(event.target.value)}>
                                                <option value="">Partner seçin</option>
                                                {membershipPartnerOptions
                                                    .filter((partner) => !selectedMemberships.some((membership) => membership.partner_id === partner.id))
                                                    .map((partner) => (
                                                        <option key={partner.id} value={partner.id}>{partner.display_name} · {partner.partner_code} · {(partner.capabilities ?? []).map((capability) => capabilityLabels[capability] ?? capability).join(' + ')}</option>
                                                    ))}
                                            </select>
                                        </label>
                                        <label className="flex items-center gap-2 text-xs font-medium text-slate-600 sm:col-span-2">
                                            <input type="checkbox" checked={includeInactivePartners} onChange={(event) => setIncludeInactivePartners(event.target.checked)} />
                                            Pasif partnerları da göster
                                        </label>
                                    </>
                                ) : (
                                    <div className="sm:col-span-2">
                                        <p className="text-xs font-semibold uppercase text-slate-500">Partner</p>
                                        <p className="mt-1 text-sm font-semibold text-slate-900">{partnerById(membershipForm.partner_id)?.display_name ?? membershipDialog.membership?.partner_name}</p>
                                        <div className="mt-2 flex flex-wrap gap-1">
                                            {(partnerById(membershipForm.partner_id)?.capabilities ?? membershipDialog.membership?.capabilities ?? []).map((capability) => (
                                                <span key={capability} className="rounded-md bg-white px-2 py-1 text-xs font-semibold text-slate-600 ring-1 ring-slate-200">{capabilityLabels[capability] ?? capability}</span>
                                            ))}
                                        </div>
                                    </div>
                                )}

                                <label className="grid gap-1.5 text-sm font-semibold text-slate-700">
                                    Ünvan
                                    <input className="h-10 rounded-lg border border-slate-200 bg-white px-3 font-normal" value={membershipForm.title} onChange={(event) => setMembershipForm((current) => ({ ...current, title: event.target.value }))} placeholder="Örn. Servis sorumlusu" />
                                </label>
                                <label className="grid gap-1.5 text-sm font-semibold text-slate-700">
                                    Üyelik telefonu
                                    <input className="h-10 rounded-lg border border-slate-200 bg-white px-3 font-normal" value={membershipForm.phone} onChange={(event) => setMembershipForm((current) => ({ ...current, phone: event.target.value }))} placeholder="Opsiyonel" />
                                </label>
                                <fieldset className="sm:col-span-2">
                                    <legend className="text-sm font-semibold text-slate-700">Üyelik durumu</legend>
                                    <div className="mt-1.5 grid grid-cols-2 gap-1 rounded-lg border border-slate-200 bg-white p-1">
                                        <button type="button" aria-pressed={membershipForm.active} onClick={() => setMembershipForm((current) => ({ ...current, active: true }))} className={`h-9 rounded-md text-sm font-semibold ${membershipForm.active ? 'bg-emerald-600 text-white' : 'text-slate-500'}`}>Aktif</button>
                                        <button type="button" aria-pressed={!membershipForm.active} onClick={() => setMembershipForm((current) => ({ ...current, active: false }))} className={`h-9 rounded-md text-sm font-semibold ${!membershipForm.active ? 'bg-rose-600 text-white' : 'text-slate-500'}`}>Pasif</button>
                                    </div>
                                </fieldset>
                            </section>

                            <section className="space-y-3">
                                <div>
                                    <h3 className="text-sm font-semibold text-slate-900">Partner kapsamı</h3>
                                    <p className="mt-1 text-xs text-slate-500">Hazır kapsamı açıkça seçin veya gelişmiş matrisi düzenleyin. Temel rol otomatik değişmez.</p>
                                </div>
                                <div className="grid gap-2 sm:grid-cols-3">
                                    {[['view', 'Sadece görüntüleme'], ['operations', 'Operasyon'], ['manager', 'Partner yöneticisi']].map(([value, label]) => (
                                        <button key={value} type="button" aria-pressed={membershipForm.preset === value} onClick={() => applyMembershipPreset(value)} className={`h-10 rounded-lg border px-3 text-sm font-semibold ${membershipForm.preset === value ? 'border-blue-500 bg-blue-50 text-blue-700' : 'border-slate-200 text-slate-600 hover:bg-slate-50'}`}>
                                            {label}
                                        </button>
                                    ))}
                                </div>
                                <button type="button" onClick={() => setMembershipAdvanced((current) => !current)} className="text-xs font-semibold text-blue-700 hover:text-blue-900">
                                    {membershipAdvanced ? 'Gelişmiş kapsamı gizle' : 'Gelişmiş kapsamı göster'}
                                </button>
                                {membershipAdvanced && (
                                    <div className="overflow-x-auto rounded-lg border border-slate-200">
                                        <table className="w-full min-w-[620px] text-left text-xs">
                                            <thead className="bg-slate-50 text-slate-500">
                                                <tr>
                                                    <th className="px-3 py-2 font-semibold">Kapsam</th>
                                                    {membershipAbilities.map(([, label]) => <th key={label} className="px-2 py-2 text-center font-semibold">{label}</th>)}
                                                </tr>
                                            </thead>
                                            <tbody className="divide-y divide-slate-100">
                                                {membershipScopeRows.map(([scope, label]) => (
                                                    <tr key={scope}>
                                                        <td className="px-3 py-2 font-semibold text-slate-700">{label}</td>
                                                        {membershipAbilities.map(([ability]) => (
                                                            <td key={ability} className="px-2 py-2 text-center">
                                                                <input type="checkbox" aria-label={`${label} ${ability}`} checked={Boolean(membershipForm.scopes[scope]?.[ability])} onChange={(event) => setMembershipAbility(scope, ability, event.target.checked)} />
                                                            </td>
                                                        ))}
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>
                                )}
                            </section>

                            <section className="space-y-3 rounded-lg border border-emerald-200 bg-emerald-50/60 p-3">
                                <div>
                                    <h3 className="flex items-center gap-2 text-sm font-semibold text-emerald-950"><Link2 aria-hidden="true" className="size-4" />Kullanıcıyı usta profiline bağla</h3>
                                    <p className="mt-1 text-xs leading-5 text-emerald-800">Yalnız bu partnere aktif bağlı ustalar seçilebilir. Birincil veya tek usta otomatik atanmaz.</p>
                                </div>
                                <select className="h-10 w-full rounded-lg border border-emerald-200 bg-white px-3 text-sm" value={membershipForm.technical_service_technician_id} onChange={(event) => setMembershipForm((current) => ({ ...current, technical_service_technician_id: event.target.value }))} disabled={membershipTechniciansLoading || !membershipForm.partner_id}>
                                    <option value="">Usta profili bağlama</option>
                                    {membershipTechnicianLinks.map((link) => (
                                        <option key={link.id} value={link.technical_service_technician_id}>{link.technician?.name} · {link.relationship_type}{link.is_primary ? ' · Birincil' : ''}</option>
                                    ))}
                                </select>
                                {membershipForm.partner_id && membershipTechnicianLinks.length === 0 && !membershipTechniciansLoading && (
                                    <p className="text-xs font-medium text-amber-700">Bu partnere bağlı aktif usta yok. Önce ayrı usta bağlantısı işlemini tamamlayın.</p>
                                )}
                                {partnerById(membershipForm.partner_id)?.capabilities?.includes('dealer') && partnerById(membershipForm.partner_id)?.can_manage_technicians && (
                                    <button type="button" onClick={() => { const partner = partnerById(membershipForm.partner_id); setMembershipDialog(null); openTechnicianManager(partner); }} className="inline-flex h-9 items-center gap-2 self-start rounded-lg border border-emerald-200 bg-white px-3 text-xs font-semibold text-emerald-800 hover:bg-emerald-100">
                                        <Wrench aria-hidden="true" className="size-3.5" />
                                        Bayinin ustalarını bağla
                                    </button>
                                )}
                            </section>
                        </div>

                        <footer className="flex shrink-0 items-center justify-between gap-3 border-t border-slate-200 bg-white px-4 py-3 sm:px-5">
                            <span className="hidden text-xs text-slate-500 sm:block">Kullanıcı temel kaydı bu işlemden etkilenmez.</span>
                            <div className="ml-auto flex gap-2">
                                <button type="button" onClick={() => setMembershipDialog(null)} className="h-10 rounded-lg border border-slate-200 px-3 text-sm font-semibold text-slate-600 hover:bg-slate-50">Vazgeç</button>
                                <button type="submit" disabled={membershipSaving || !membershipForm.partner_id} className="h-10 rounded-lg bg-slate-950 px-4 text-sm font-semibold text-white disabled:opacity-50">{membershipSaving ? 'Kaydediliyor...' : 'Üyeliği kaydet'}</button>
                            </div>
                        </footer>
                    </form>
                </div>
            )}

            {technicianDialogPartner && (
                <div className="fixed inset-0 z-[70] grid place-items-center bg-slate-950/55 p-2 sm:p-4" role="dialog" aria-modal="true" aria-labelledby="technician-dialog-title">
                    <div className="flex max-h-[calc(100vh-1rem)] w-full max-w-6xl flex-col overflow-hidden rounded-lg border border-slate-200 bg-white shadow-2xl sm:max-h-[calc(100vh-2rem)]">
                        <header className="flex shrink-0 items-start justify-between gap-4 border-b border-slate-200 px-4 py-3 sm:px-5">
                            <div className="min-w-0">
                                <p className="text-xs font-semibold uppercase text-slate-500">Bayinin ustalarını bağla</p>
                                <h2 id="technician-dialog-title" className="mt-1 truncate text-lg font-semibold text-slate-950">{technicianDialogPartner.display_name}</h2>
                                <p className="mt-1 text-xs text-slate-500">Partner ↔ Usta bağlantısıdır; kullanıcı üyeliği ayrıca bir usta profiline bağlanır.</p>
                            </div>
                            <button type="button" aria-label="Usta yönetimini kapat" onClick={() => setTechnicianDialogPartner(null)} className="inline-flex size-9 shrink-0 items-center justify-center rounded-lg border border-slate-200 text-slate-500 hover:bg-slate-50"><X aria-hidden="true" className="size-4" /></button>
                        </header>

                        <div className="min-h-0 flex-1 overflow-x-hidden overflow-y-auto p-4 sm:p-5">
                            <div className="grid min-w-0 gap-5 lg:grid-cols-[minmax(0,1.25fr)_minmax(320px,.75fr)]">
                                <section className="min-w-0 space-y-3">
                                    <div className="flex items-center justify-between gap-3">
                                        <div>
                                            <h3 className="text-sm font-semibold text-slate-900">Bağlı Ustalar / Çilingirler</h3>
                                            <p className="mt-1 text-xs text-slate-500">Bir partnerda yalnız bir aktif birincil usta olabilir.</p>
                                        </div>
                                        <span className="rounded-md bg-slate-100 px-2 py-1 text-xs font-semibold text-slate-600">{technicianLinks.length} bağlantı</span>
                                    </div>
                                    {technicianLinks.length === 0 && <div className="rounded-lg border border-dashed border-slate-200 px-3 py-6 text-center text-sm text-slate-500">Henüz bağlı usta yok.</div>}
                                    {technicianLinks.map((link, index) => (
                                        <article key={link.id} className={`rounded-lg border p-3 ${link.active ? 'border-slate-200 bg-white' : 'border-rose-200 bg-rose-50/50'}`}>
                                            <div className="flex items-start justify-between gap-3">
                                                <div className="min-w-0">
                                                    <p className="truncate text-sm font-semibold text-slate-900">{link.technician?.name ?? `Usta #${link.technical_service_technician_id}`}</p>
                                                    <p className="mt-1 text-xs text-slate-500">{[link.technician?.phone, link.technician?.city, link.technician?.district].filter(Boolean).join(' · ') || '-'}</p>
                                                </div>
                                                <div className="flex shrink-0 gap-1">
                                                    {link.is_primary && <span className="rounded-md bg-blue-100 px-2 py-1 text-[11px] font-semibold text-blue-700">Birincil</span>}
                                                    <span className={`rounded-md px-2 py-1 text-[11px] font-semibold ${link.active ? 'bg-emerald-100 text-emerald-700' : 'bg-rose-100 text-rose-700'}`}>{link.active ? 'Aktif' : 'Pasif'}</span>
                                                </div>
                                            </div>
                                            <div className="mt-3 grid gap-2 sm:grid-cols-2">
                                                <select className="h-9 rounded-lg border border-slate-200 bg-white px-2 text-xs" value={link.relationship_type} onChange={(event) => setTechnicianLinks((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, relationship_type: event.target.value } : item))}>
                                                    <option value="owner">Sahip</option><option value="field_technician">Saha ustası</option><option value="contracted_technician">Anlaşmalı usta</option><option value="branch_technician">Şube ustası</option><option value="contact">İletişim</option>
                                                </select>
                                                <input type="number" min="0" max="9999" aria-label="Usta önceliği" className="h-9 rounded-lg border border-slate-200 px-2 text-xs" value={link.priority ?? 0} onChange={(event) => setTechnicianLinks((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, priority: event.target.value } : item))} />
                                                <input aria-label="Servis şehri" className="h-9 rounded-lg border border-slate-200 px-2 text-xs" value={link.service_city ?? ''} onChange={(event) => setTechnicianLinks((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, service_city: event.target.value } : item))} placeholder="Servis şehri" />
                                                <input aria-label="Servis ilçesi" className="h-9 rounded-lg border border-slate-200 px-2 text-xs" value={link.service_district ?? ''} onChange={(event) => setTechnicianLinks((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, service_district: event.target.value } : item))} placeholder="Servis ilçesi" />
                                                <input aria-label="Servis bölge notu" className="h-9 rounded-lg border border-slate-200 px-2 text-xs sm:col-span-2" value={link.service_region_note ?? ''} onChange={(event) => setTechnicianLinks((current) => current.map((item, itemIndex) => itemIndex === index ? { ...item, service_region_note: event.target.value } : item))} placeholder="Servis bölgesi notu" />
                                            </div>
                                            <div className="mt-3 flex flex-wrap gap-2">
                                                <button type="button" onClick={() => void patchTechnicianLink(link)} disabled={technicianSaving} className="h-8 rounded-lg border border-slate-200 px-2.5 text-xs font-semibold text-slate-700 hover:bg-slate-50">Kaydet</button>
                                                {!link.is_primary && link.active && <button type="button" onClick={() => void patchTechnicianLink(link, { is_primary: true, active: true })} disabled={technicianSaving} className="h-8 rounded-lg border border-blue-200 px-2.5 text-xs font-semibold text-blue-700 hover:bg-blue-50">Birincil yap</button>}
                                                {!link.active && <button type="button" onClick={() => void patchTechnicianLink(link, { active: true })} disabled={technicianSaving} className="h-8 rounded-lg border border-emerald-200 px-2.5 text-xs font-semibold text-emerald-700 hover:bg-emerald-50">Etkinleştir</button>}
                                                {link.active && <button type="button" onClick={() => void deactivateTechnicianLink(link)} disabled={technicianSaving} className="h-8 rounded-lg border border-rose-200 px-2.5 text-xs font-semibold text-rose-700 hover:bg-rose-50">Pasife al</button>}
                                            </div>
                                        </article>
                                    ))}
                                </section>

                                <section className="min-w-0 space-y-3 rounded-lg border border-slate-200 bg-slate-50 p-3 lg:sticky lg:top-0 lg:self-start">
                                    <div>
                                        <h3 className="text-sm font-semibold text-slate-900">Yeni usta bağlantısı</h3>
                                        <p className="mt-1 text-xs text-slate-500">Mevcut Teknik Servis ustalarından seçin.</p>
                                    </div>
                                    <div className="flex gap-2">
                                        <input className="h-10 min-w-0 flex-1 rounded-lg border border-slate-200 bg-white px-3 text-sm" value={technicianQuery} onChange={(event) => setTechnicianQuery(event.target.value)} placeholder="Ad, telefon veya şehir" />
                                        <button type="button" onClick={() => void loadTechnicianManager(technicianDialogPartner, technicianQuery)} disabled={technicianSaving} className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-semibold text-slate-700">Ara</button>
                                    </div>
                                    <form onSubmit={createTechnicianLink} className="grid min-w-0 gap-3">
                                        <label className="grid min-w-0 gap-1.5 text-xs font-semibold text-slate-700">Usta
                                            <select required className="h-10 w-full min-w-0 rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal" value={technicianLinkForm.technical_service_technician_id} onChange={(event) => setTechnicianLinkForm((current) => ({ ...current, technical_service_technician_id: event.target.value }))}>
                                                <option value="">Usta seçin</option>
                                                {technicianOptions.filter((technician) => !technician.linked_to_current_partner && technician.can_link !== false).map((technician) => (
                                                    <option key={technician.id} value={technician.id}>{technician.name} · {[technician.phone, technician.city, technician.district].filter(Boolean).join(' / ')}</option>
                                                ))}
                                            </select>
                                        </label>
                                        <div className="grid gap-2 sm:grid-cols-2 lg:grid-cols-1 xl:grid-cols-2">
                                            <label className="grid gap-1.5 text-xs font-semibold text-slate-700">İlişki
                                                <select className="h-10 rounded-lg border border-slate-200 bg-white px-2 text-sm font-normal" value={technicianLinkForm.relationship_type} onChange={(event) => setTechnicianLinkForm((current) => ({ ...current, relationship_type: event.target.value }))}><option value="contracted_technician">Anlaşmalı usta</option><option value="field_technician">Saha ustası</option><option value="owner">Sahip</option><option value="branch_technician">Şube ustası</option><option value="contact">İletişim</option></select>
                                            </label>
                                            <label className="grid gap-1.5 text-xs font-semibold text-slate-700">Öncelik<input type="number" min="0" max="9999" className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal" value={technicianLinkForm.priority} onChange={(event) => setTechnicianLinkForm((current) => ({ ...current, priority: event.target.value }))} /></label>
                                            <label className="grid gap-1.5 text-xs font-semibold text-slate-700">Şehir<input className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal" value={technicianLinkForm.service_city} onChange={(event) => setTechnicianLinkForm((current) => ({ ...current, service_city: event.target.value }))} /></label>
                                            <label className="grid gap-1.5 text-xs font-semibold text-slate-700">İlçe<input className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal" value={technicianLinkForm.service_district} onChange={(event) => setTechnicianLinkForm((current) => ({ ...current, service_district: event.target.value }))} /></label>
                                        </div>
                                        <label className="grid gap-1.5 text-xs font-semibold text-slate-700">Bölge notu<input className="h-10 rounded-lg border border-slate-200 bg-white px-3 text-sm font-normal" value={technicianLinkForm.service_region_note} onChange={(event) => setTechnicianLinkForm((current) => ({ ...current, service_region_note: event.target.value }))} /></label>
                                        <label className="flex items-center justify-between gap-3 rounded-lg border border-slate-200 bg-white px-3 py-2 text-xs font-semibold text-slate-700">Birincil usta yap<input type="checkbox" checked={technicianLinkForm.is_primary} onChange={(event) => setTechnicianLinkForm((current) => ({ ...current, is_primary: event.target.checked }))} /></label>
                                        <button type="submit" disabled={technicianSaving || !technicianLinkForm.technical_service_technician_id} className="h-10 rounded-lg bg-slate-950 px-3 text-sm font-semibold text-white disabled:opacity-50"><Plus aria-hidden="true" className="mr-1 inline size-4" />Ustayı bağla</button>
                                    </form>
                                </section>
                            </div>
                        </div>
                        <footer className="flex shrink-0 justify-end border-t border-slate-200 bg-white px-4 py-3 sm:px-5"><button type="button" onClick={() => setTechnicianDialogPartner(null)} className="h-10 rounded-lg border border-slate-200 px-4 text-sm font-semibold text-slate-700">Kapat</button></footer>
                    </div>
                </div>
            )}

            {cloneSource && (
                <div className="fixed inset-0 z-50 grid place-items-center bg-slate-950/50 px-4 py-6">
                    <form onSubmit={cloneUser} className="w-full max-w-xl rounded-2xl border border-slate-200 bg-white p-6 shadow-2xl">
                        <div className="flex items-start justify-between gap-4">
                            <div>
                                <p className="text-xs font-semibold uppercase tracking-[0.14em] text-slate-500">
                                    Kullanıcı kopyala
                                </p>
                                <h3 className="mt-1 text-xl font-semibold text-slate-950">
                                    {cloneSource.full_name || cloneSource.username}
                                </h3>
                                <p className="mt-2 text-sm text-slate-500">
                                    Rol ve izinler kaynak kullanıcıdan kopyalanır. Temsilci kodu yeni kullanıcı için ayrı girilir.
                                </p>
                            </div>
                            <button
                                type="button"
                                onClick={closeClone}
                                className="rounded-xl border border-slate-200 px-3 py-2 text-sm font-semibold text-slate-600 transition hover:border-slate-400"
                            >
                                Kapat
                            </button>
                        </div>

                        <div className="mt-5 grid gap-4">
                            <label className="grid gap-2 text-sm font-semibold text-slate-700">
                                Yeni kullanıcı adı
                                <input
                                    required
                                    className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                    placeholder="yeni.kullanici"
                                    value={cloneForm.username}
                                    onChange={(event) => setCloneForm({ ...cloneForm, username: event.target.value })}
                                />
                            </label>

                            <label className="grid gap-2 text-sm font-semibold text-slate-700">
                                Yeni tam ad
                                <input
                                    required
                                    className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                    placeholder="Ad Soyad"
                                    value={cloneForm.full_name}
                                    onChange={(event) => setCloneForm({ ...cloneForm, full_name: event.target.value })}
                                />
                            </label>

                            <label className="grid gap-2 text-sm font-semibold text-slate-700">
                                Yeni parola
                                <input
                                    required
                                    type="password"
                                    className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                    placeholder="İlk parola"
                                    value={cloneForm.password}
                                    onChange={(event) => setCloneForm({ ...cloneForm, password: event.target.value })}
                                />
                            </label>

                            <label className="grid gap-2 text-sm font-semibold text-slate-700">
                                Temsilci kodu
                                <input
                                    className="rounded-xl border border-slate-200 px-4 py-3 font-normal outline-none transition focus:border-slate-400"
                                    placeholder="0035"
                                    value={cloneForm.temsilci_kodu ?? ''}
                                    onChange={(event) => setCloneForm({ ...cloneForm, temsilci_kodu: event.target.value })}
                                />
                            </label>

                            <div className="grid gap-3 rounded-xl border border-slate-200 bg-slate-50 p-4">
                                <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
                                    Aktif
                                    <input
                                        type="checkbox"
                                        checked={cloneForm.aktif}
                                        onChange={(event) => setCloneForm({ ...cloneForm, aktif: event.target.checked })}
                                    />
                                </label>
                                <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
                                    İlk girişte şifre değiştir
                                    <input
                                        type="checkbox"
                                        checked={cloneForm.force_password_change}
                                        onChange={(event) => setCloneForm({ ...cloneForm, force_password_change: event.target.checked })}
                                    />
                                </label>
                                <label className="flex items-center justify-between gap-3 text-sm font-semibold text-slate-700">
                                    <span>
                                        Dar yetkiyi sabitle
                                        <small className="block font-normal text-slate-500">
                                            Kaynak kullanıcının etkin izinleri yeni kullanıcıya explicit allow/deny olarak yazılır; rol fallback fazladan alan açamaz.
                                        </small>
                                    </span>
                                    <input
                                        type="checkbox"
                                        checked={cloneForm.strict_access}
                                        onChange={(event) => setCloneForm({ ...cloneForm, strict_access: event.target.checked })}
                                    />
                                </label>
                            </div>
                        </div>

                        <div className="mt-6 flex justify-end gap-3">
                            <button
                                type="button"
                                onClick={closeClone}
                                className="rounded-xl border border-slate-200 px-4 py-3 font-semibold text-slate-600 transition hover:border-slate-400"
                            >
                                Vazgeç
                            </button>
                            <button
                                className="rounded-xl bg-slate-950 px-4 py-3 font-semibold text-white shadow-sm transition hover:bg-slate-800 disabled:cursor-not-allowed disabled:opacity-60"
                                disabled={isCloning}
                            >
                                {isCloning ? 'Kopyalanıyor...' : 'Kullanıcıyı kopyala'}
                            </button>
                        </div>
                    </form>
                </div>
            )}
        </AdminFrame>
    );
}
