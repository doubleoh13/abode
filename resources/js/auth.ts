import axios from 'axios';
import { reactive } from 'vue';

export type PermissionName = 'view-finances' | 'manage-finances';

export interface AuthenticatedUser {
    id: number;
    name: string;
    email: string;
    permissions: PermissionName[];
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
        auth.user = (await axios.get<{ data: AuthenticatedUser }>('/api/v1/user')).data.data;
    } catch {
        auth.user = null;
    } finally {
        auth.resolved = true;
    }
}

export function hasPermission(permission: PermissionName): boolean {
    return auth.user?.permissions.includes(permission) ?? false;
}

export async function login(credentials: LoginCredentials): Promise<void> {
    await axios.get('/sanctum/csrf-cookie');
    await axios.post('/login', credentials);
    await resolveAuthenticatedUser();
}

export async function loginAsDevelopmentUser(): Promise<void> {
    await axios.get('/sanctum/csrf-cookie');
    await axios.post('/dev/login');
    await resolveAuthenticatedUser();
}

export async function logout(): Promise<void> {
    await axios.post('/logout');
    auth.user = null;
}
