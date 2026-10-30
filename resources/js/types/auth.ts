export type User = {
    id: number;
    name: string;
    email: string;
    avatar_url: string | null;
    is_active: boolean;
    last_login_at: string | null;
    created_at: string;
    updated_at: string;
};

export type Impersonator = { id: number; name: string };

export type Auth = {
    user: User | null;
    roles: string[];
    permissions: string[];
    impersonator: Impersonator | null;
};
