import Constants from 'expo-constants';

export const API_BASE_URL = 
  process.env.EXPO_PUBLIC_API_URL || 
  Constants.expoConfig?.extra?.apiUrl || 
  'https://maildesk.ng';

export const API_VERSION = 'v1';

export const getApiUrl = (path: string): string => {
  const cleanPath = path.startsWith('/') ? path.slice(1) : path;
  return `${API_BASE_URL}/api/${API_VERSION}/${cleanPath}`;
};
