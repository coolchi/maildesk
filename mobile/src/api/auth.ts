import { apiClient, setAuthToken, setCurrentWorkspaceId } from './client';
import { secureStorage, asyncStorage } from '../utils/storage';
import type { LoginResponse, MeResponse } from './types';

export interface LoginCredentials {
  email: string;
  password: string;
  device_name?: string;
}

export const authApi = {
  async login(credentials: LoginCredentials): Promise<LoginResponse> {
    const response = await apiClient.post<LoginResponse>('/auth/login', {
      ...credentials,
      device_name: credentials.device_name || 'Mobile App',
    });

    const { token, user, workspaces } = response.data;
    
    await secureStorage.setToken(token);
    setAuthToken(token);

    if (workspaces.length > 0) {
      await asyncStorage.setWorkspaceId(workspaces[0].id);
      setCurrentWorkspaceId(workspaces[0].id);
    }

    return response.data;
  },

  async logout(): Promise<void> {
    try {
      await apiClient.post('/auth/logout');
    } finally {
      await secureStorage.removeToken();
      await asyncStorage.removeWorkspaceId();
      setAuthToken(null);
      setCurrentWorkspaceId(null);
    }
  },

  async me(): Promise<MeResponse> {
    const response = await apiClient.get<MeResponse>('/auth/me');
    return response.data;
  },

  async switchWorkspace(workspaceId: number): Promise<void> {
    await asyncStorage.setWorkspaceId(workspaceId);
    setCurrentWorkspaceId(workspaceId);
  },
};
