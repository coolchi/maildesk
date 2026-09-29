import React, { createContext, useContext, useState, useEffect, useCallback, useRef, ReactNode } from 'react';
import { authApi, LoginCredentials } from '../api/auth';
import { initializeAuth, setCurrentWorkspaceId, getAuthToken } from '../api/client';
import { asyncStorage } from '../utils/storage';
import { setupPushNotifications, unregisterDeviceFromBackend, setupNotificationListeners } from '../utils/notifications';
import type { User, Workspace } from '../api/types';

interface AuthContextType {
  user: User | null;
  workspaces: Workspace[];
  currentWorkspace: Workspace | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  login: (credentials: LoginCredentials) => Promise<void>;
  logout: () => Promise<void>;
  switchWorkspace: (workspaceId: number) => Promise<void>;
  refreshUser: () => Promise<void>;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const AuthProvider: React.FC<{ children: ReactNode }> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [workspaces, setWorkspaces] = useState<Workspace[]>([]);
  const [currentWorkspaceId, setCurrentWorkspaceIdState] = useState<number | null>(null);
  const [isLoading, setIsLoading] = useState(true);
  const pushTokenRef = useRef<string | null>(null);

  const currentWorkspace = workspaces.find(w => w.id === currentWorkspaceId) || null;
  const isAuthenticated = !!user && !!getAuthToken();

  const refreshUser = useCallback(async () => {
    try {
      const response = await authApi.me();
      setUser(response.user);
      setWorkspaces(response.workspaces);
      
      if (response.workspaces.length > 0 && !currentWorkspaceId) {
        const savedId = await asyncStorage.getWorkspaceId();
        const id = savedId && response.workspaces.some(w => w.id === savedId) 
          ? savedId 
          : response.workspaces[0].id;
        setCurrentWorkspaceIdState(id);
        setCurrentWorkspaceId(id);
      }
    } catch {
      setUser(null);
      setWorkspaces([]);
    }
  }, [currentWorkspaceId]);

  useEffect(() => {
    const initialize = async () => {
      setIsLoading(true);
      try {
        const hasToken = await initializeAuth();
        if (hasToken) {
          await refreshUser();
        }
      } finally {
        setIsLoading(false);
      }
    };
    initialize();
  }, []);

  useEffect(() => {
    if (!isAuthenticated || isLoading) {
      return;
    }

    const registerPush = async () => {
      const token = await setupPushNotifications();
      pushTokenRef.current = token;
    };

    registerPush();

    const cleanup = setupNotificationListeners();
    return cleanup;
  }, [isAuthenticated, isLoading]);

  const login = async (credentials: LoginCredentials) => {
    const response = await authApi.login(credentials);
    setUser(response.user);
    setWorkspaces(response.workspaces);
    if (response.workspaces.length > 0) {
      setCurrentWorkspaceIdState(response.workspaces[0].id);
    }
  };

  const logout = async () => {
    if (pushTokenRef.current) {
      await unregisterDeviceFromBackend(pushTokenRef.current);
      pushTokenRef.current = null;
    }
    await authApi.logout();
    setUser(null);
    setWorkspaces([]);
    setCurrentWorkspaceIdState(null);
  };

  const switchWorkspace = async (workspaceId: number) => {
    await authApi.switchWorkspace(workspaceId);
    setCurrentWorkspaceIdState(workspaceId);
  };

  return (
    <AuthContext.Provider
      value={{
        user,
        workspaces,
        currentWorkspace,
        isLoading,
        isAuthenticated,
        login,
        logout,
        switchWorkspace,
        refreshUser,
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};

export const useAuth = (): AuthContextType => {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};
