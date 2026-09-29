import * as Device from 'expo-device';
import * as Notifications from 'expo-notifications';
import Constants from 'expo-constants';
import { Platform } from 'react-native';
import { router } from 'expo-router';
import { apiClient } from '../api/client';

Notifications.setNotificationHandler({
  handleNotification: async () => ({
    shouldShowAlert: true,
    shouldPlaySound: true,
    shouldSetBadge: true,
    shouldShowBanner: true,
    shouldShowList: true,
  }),
});

export async function registerForPushNotifications(): Promise<string | null> {
  if (!Device.isDevice) {
    console.log('Push notifications are only supported on physical devices');
    return null;
  }

  const { status: existingStatus } = await Notifications.getPermissionsAsync();
  let finalStatus = existingStatus;

  if (existingStatus !== 'granted') {
    const { status } = await Notifications.requestPermissionsAsync();
    finalStatus = status;
  }

  if (finalStatus !== 'granted') {
    console.log('Permission not granted for push notifications');
    return null;
  }

  const projectId = Constants.expoConfig?.extra?.eas?.projectId;

  if (!projectId) {
    console.log('EAS project ID not configured - push notifications unavailable');
    return null;
  }

  try {
    const token = await Notifications.getExpoPushTokenAsync({ projectId });
    return token.data;
  } catch (error) {
    console.error('Failed to get push token:', error);
    return null;
  }
}

export async function registerDeviceWithBackend(pushToken: string): Promise<boolean> {
  try {
    const platform = Platform.OS === 'ios' ? 'ios' : 'android';
    await apiClient.post('/devices', { token: pushToken, platform });
    return true;
  } catch (error) {
    console.error('Failed to register device with backend:', error);
    return false;
  }
}

export async function unregisterDeviceFromBackend(pushToken: string): Promise<boolean> {
  try {
    await apiClient.delete('/devices', { data: { token: pushToken } });
    return true;
  } catch (error) {
    console.error('Failed to unregister device from backend:', error);
    return false;
  }
}

export function setupNotificationListeners(): () => void {
  const responseSubscription = Notifications.addNotificationResponseReceivedListener(
    (response) => {
      const data = response.notification.request.content.data as {
        thread_id?: number;
        message_id?: string;
      } | undefined;

      if (data?.thread_id) {
        router.push(`/(app)/inbox/${data.thread_id}`);
      }
    }
  );

  return () => {
    responseSubscription.remove();
  };
}

export async function setupPushNotifications(): Promise<string | null> {
  if (Platform.OS === 'android') {
    await Notifications.setNotificationChannelAsync('default', {
      name: 'default',
      importance: Notifications.AndroidImportance.MAX,
      vibrationPattern: [0, 250, 250, 250],
      lightColor: '#0891b2',
    });
  }

  const token = await registerForPushNotifications();

  if (token) {
    await registerDeviceWithBackend(token);
  }

  return token;
}
