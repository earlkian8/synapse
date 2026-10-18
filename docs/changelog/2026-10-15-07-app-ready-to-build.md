# The app is ready to build with EAS

The mobile app can now be built as an installable app, not just run in Expo Go. It
has a permanent identity, two EAS build profiles, and the deployed server's address
built in. Leftovers from the Expo template are gone. See
[ADR 0093](../decisions/0093-the-app-ships-through-eas-as-com-synapsehr-app.md).

## Highlights

- **`mobile/eas.json`** has two profiles:
  - `preview` builds an APK for direct installs and for the landing page's download
    link.
  - `production` builds the store formats, and EAS raises the build number each
    time.
- **Identity**: `com.synapsehr.app` on Android and iOS, the slug `synapse` (was
  `mobile`), and the link scheme `synapse://` (was `mobile://`).
- **The API address is in the build.** Both profiles set `EXPO_PUBLIC_API_URL` to
  `https://synapse-lzsdm.ondigitalocean.app/api`. `.env` is git-ignored, so a cloud
  build never saw it and would have fallen back to `localhost`.
- **Fewer permissions.** The app only takes a selfie with the camera, so the image
  picker no longer asks for the microphone or the photo library. Android's
  `RECORD_AUDIO` is blocked. iOS declares that the app uses no non-exempt
  encryption, so the App Store won't ask about it on every upload.
- **The logo in every slot.** The launcher icon (light, dark and tinted on iOS), the
  adaptive and monochrome icons on Android, and the splash screen are all the new
  mark from 2026-10-12. This change didn't need to redraw any of them.

## Mobile

- `app.json`: `ios.bundleIdentifier`, `ios.config.usesNonExemptEncryption: false`,
  `android.package`, the new slug and scheme, and the image picker's
  `photosPermission` and `microphonePermission` set to `false`.
- Expo patch updates, so `expo-doctor` passes 21 of 21 checks: `expo` 57.0.30,
  `expo-constants`, `expo-linking`, `expo-location`, `expo-router` and
  `expo-splash-screen`.
- Removed from the template: `scripts/reset-project.js` and its npm script. The
  script would move `app/` aside if anybody ran it. Also removed: the four unused
  React logo images.

## Docs

- [Mobile app](../modules/mobile-app.md) has a new *Building it* section, and
  `mobile/README.md` has *Building the app*.

## Notes

- Before the first build, run `npx eas-cli@latest init`. It links an EAS project and
  writes `extra.eas.projectId` into `app.json`, and that line should be committed.
- No build was run here. EAS builds in the cloud on the user's account. The config
  was checked by an Android prebuild in a scratch copy (package, scheme and
  permissions in the manifest) and by bundling for Android and iOS.
