# AMAN Responder

Flutter field client for the AMAN hackathon rehearsal. A responder selects a local profile, receives assigned missions, acknowledges dispatch, reports that they are heading there or on scene, and exchanges updates with the control room.

## Run

The Android emulator can use the default API URL:

```sh
flutter pub get
flutter run
```

Pass an explicit URL for iOS Simulator or a physical device:

```sh
flutter run --dart-define=API_URL=http://127.0.0.1:8000/api/v1/demo
```

Use the backend computer's LAN address on a physical phone. Laravel must listen on that interface and the phone must be on the same network.

## Prototype boundary

The app refreshes active missions every five seconds while it is open. It displays a foreground banner when a new assignment appears. Reliable background delivery will use FCM/APNs after the team supplies Firebase, Apple, signing, and distribution credentials.

```sh
flutter analyze
flutter test
```
