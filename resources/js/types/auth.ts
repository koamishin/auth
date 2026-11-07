export type User = {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
    [key: string]: unknown;
};

export type Auth = {
    user: User;
    accounts?: SwitchableAccount[];
};

/** An account this browser is signed in to and can switch to. */
export type SwitchableAccount = {
    id: number;
    name: string;
    email: string;
    current: boolean;
};

export type TwoFactorConfigContent = {
    title: string;
    description: string;
    buttonText: string;
};
