import axios, { AxiosInstance, AxiosError, InternalAxiosRequestConfig } from 'axios';
import { API_BASE_URL, API_VERSION } from '../utils/config';
import { secureStorage, asyncStorage } from '../utils/storage';

let authToken: string | null = null;
let currentWorkspaceId: number | null = null;

export interface PaymentRequiredError {
  message: string;
  payment_required: boolean;
}

export const isPaymentRequiredError = (error: unknown): error is AxiosError<PaymentRequiredError> => {
  if (axios.isAxiosError(error)) {
    return error.response?.status === 402 && error.response?.data?.payment_required === true;
  }
  return false;
};

export const getPaymentRequiredMessage = (error: AxiosError<PaymentRequiredError>): string => {
  return error.response?.data?.message || 'Your subscription has expired. Please renew your plan to continue.';
};

export const setAuthToken = (token: string | null): void => {
  authToken = token;
};

export const getAuthToken = (): string | null => authToken;

export const setCurrentWorkspaceId = (id: number | null): void => {
  currentWorkspaceId = id;
};

export const getCurrentWorkspaceId = (): number | null => currentWorkspaceId;

const createApiClient = (): AxiosInstance => {
  const client = axios.create({
    baseURL: `${API_BASE_URL}/api/${API_VERSION}/mobile`,
    headers: {
      'Content-Type': 'application/json',
      'Accept': 'application/json',
    },
    timeout: 30000,
  });

  client.interceptors.request.use(
    async (config: InternalAxiosRequestConfig) => {
      if (authToken) {
        config.headers.Authorization = `Bearer ${authToken}`;
      }
      if (currentWorkspaceId) {
        config.headers['X-Workspace-Id'] = String(currentWorkspaceId);
      }
      return config;
    },
    (error) => Promise.reject(error)
  );

  client.interceptors.response.use(
    (response) => response,
    async (error: AxiosError) => {
      if (error.response?.status === 401) {
        await secureStorage.removeToken();
        await asyncStorage.removeWorkspaceId();
        setAuthToken(null);
        setCurrentWorkspaceId(null);
      }
      return Promise.reject(error);
    }
  );

  return client;
};

export const apiClient = createApiClient();

export const initializeAuth = async (): Promise<boolean> => {
  const token = await secureStorage.getToken();
  const workspaceId = await asyncStorage.getWorkspaceId();
  
  if (token) {
    setAuthToken(token);
    if (workspaceId) {
      setCurrentWorkspaceId(workspaceId);
    }
    return true;
  }
  return false;
};
