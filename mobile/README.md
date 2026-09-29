# MailDesk Mobile App

React Native mobile app for MailDesk's shared inbox, built with Expo.

## Prerequisites

- Node.js 18+
- Expo Go app installed on your phone (or iOS Simulator / Android Emulator)
- The MailDesk backend running locally

## Running the App

### 1. Start the Backend

```bash
cd /path/to/maildesk
php artisan serve
```

This starts the Laravel backend at `http://127.0.0.1:8000`.

### 2. Configure the API URL

Set the `EXPO_PUBLIC_API_URL` environment variable:

**For iOS Simulator or Android Emulator:**
```bash
export EXPO_PUBLIC_API_URL=http://127.0.0.1:8000
```

**For a physical device on the same network:**
```bash
export EXPO_PUBLIC_API_URL=http://<YOUR_LAN_IP>:8000
```

Find your LAN IP with `ipconfig getifaddr en0` (macOS) or `hostname -I` (Linux).

### 3. Start Expo

```bash
cd mobile
npm install
npx expo start
```

Scan the QR code with Expo Go (Android) or the Camera app (iOS).

## Notes

- The production API at `https://maildesk.ng` does not have the mobile endpoints until this PR is deployed.
- The app requires Sanctum token authentication. Log in with your MailDesk credentials.
- Tokens expire after 60 days.

## Development

```bash
npm run typecheck  # Run TypeScript checks
```

## Push Notifications

Push notifications alert users when new inbound emails arrive in their mailbox.

### Development Build Required

Remote push notifications require an EAS development build. **Expo Go does not support remote push notifications** on current SDKs because it lacks the native push entitlements.

### Setup Steps

1. **Create an EAS project:**
   ```bash
   npx eas-cli login
   npx eas-cli init
   ```

2. **Add the project ID to app.json:**
   ```json
   {
     "expo": {
       "extra": {
         "eas": {
           "projectId": "your-eas-project-id"
         }
       }
     }
   }
   ```

3. **Configure Apple Push credentials (iOS):**
   ```bash
   eas credentials
   ```
   Follow the prompts to set up your Apple Push Notification key.

4. **Build a development client:**
   ```bash
   eas build --profile development --platform ios
   eas build --profile development --platform android
   ```

5. **Install and run:**
   Install the development build on your device, then run:
   ```bash
   npx expo start --dev-client
   ```

### How It Works

- After login, the app requests push notification permission
- If granted, it registers the Expo push token with the backend
- When a new inbound email arrives, the backend sends a push notification
- Tapping the notification opens the relevant conversation thread
- On logout, the device token is unregistered

### Without EAS Configuration

If no EAS project ID is configured, the app will gracefully skip push notification setup. All other features work normally.
