import axios from 'axios';
import { reactive } from 'vue';

export interface AuthenticatedUser {
    id: number;
    name: string;
    email: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface LoginCredentials {
    email: string;
    password: string;
    remember: boolean;
}

export const auth = reactive<{
    user: AuthenticatedUser | null;
    resolved: boolean;
}>({
    user: null,
    resolved: false,
});

export async function resolveAuthenticatedUser(): Promise<void> {
    try {
        auth.user = (await axios.get<AuthenticatedUser>('/api/v1/user')).data;
    } catch {
        auth.user = null;
    } finally {
        auth.resolved = true;
    }
}

export async function login(credentials: LoginCredentials): Promise<void> {
    await axios.get('/sanctum/csrf-cookie');
    await axios.post('/login', credentials);
    await resolveAuthenticatedUser();
}

export async function logout(): Promise<void> {
    await axios.post('/logout');
    auth.user = null;
}
