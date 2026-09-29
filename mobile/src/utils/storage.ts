import * as SecureStore from 'expo-secure-store';
import AsyncStorage from '@react-native-async-storage/async-storage';

const TOKEN_KEY = 'auth_token';
const WORKSPACE_KEY = 'current_workspace_id';

export const secureStorage = {
  async setToken(token: string): Promise<void> {
    await SecureStore.setItemAsync(TOKEN_KEY, token);
  },

  async getToken(): Promise<string | null> {
    return SecureStore.getItemAsync(TOKEN_KEY);
  },

  async removeToken(): Promise<void> {
    await SecureStore.deleteItemAsync(TOKEN_KEY);
  },
};

export const asyncStorage = {
  async setWorkspaceId(id: number): Promise<void> {
    await AsyncStorage.setItem(WORKSPACE_KEY, String(id));
  },

  async getWorkspaceId(): Promise<number | null> {
    const value = await AsyncStorage.getItem(WORKSPACE_KEY);
    return value ? parseInt(value, 10) : null;
  },

  async removeWorkspaceId(): Promise<void> {
    await AsyncStorage.removeItem(WORKSPACE_KEY);
  },
};
