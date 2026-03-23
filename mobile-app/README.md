# BoardingFinder Mobile (Expo)

This is a simple Expo (React Native) app version of the BoardingFinder website, focused on the **tenant** experience:
- Browse listings
- View listing details
- Open location in Google Maps
- Send an inquiry (writes to `contact_messages`)

## 1) Requirements
- Node.js LTS (18+ recommended)
- Expo CLI (via `npx`)

## 2) Install & run
From the project root:

```bash
cd mobile-app
npm install
npx expo start
```

## 3) Connect to your PHP backend
The app reads the backend base URL from:
- `EXPO_PUBLIC_API_BASE_URL`

Examples:
- Android emulator: `http://10.0.2.2/bh_finder`
- iOS simulator: `http://localhost/bh_finder`
- Real phone: `http://<YOUR_PC_LAN_IP>/bh_finder` (phone + PC must be on same Wi‑Fi)

Set it like:

```bash
set EXPO_PUBLIC_API_BASE_URL=http://10.0.2.2/bh_finder
npx expo start
```

The app calls these endpoints (already added to this repo):
- `GET /api/listings.php`
- `GET /api/listing.php?id=123`
- `POST /api/contact.php`


## Notes
- If your backend URL is wrong, the app will show an error on the Listings screen.
- The app opens Google Maps for the location (it doesn't embed an interactive map yet).
