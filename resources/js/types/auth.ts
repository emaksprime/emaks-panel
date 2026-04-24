export type UserRole = {
    name: string;
    slug: string;
    isSuperAdmin?: boolean;
};

export type User = {
    id: number;
    name: string;
    username: string;
    full_name: string;
    email: string;
    avatar?: string;
    email_verified_at?: string | null;
    role?: UserRole | null;
    is_active?: boolean;
    aktif?: boolean;
    temsilci_kodu?: string | null;
    last_login_at?: string | null;
    two_factor_enabled?: boolean;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User | null;
};

export type TwoFactorSetupData = {
    svg: string;
    url: string;
};

export type TwoFactorSecretKey = {
    secretKey: string;
};
