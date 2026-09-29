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
