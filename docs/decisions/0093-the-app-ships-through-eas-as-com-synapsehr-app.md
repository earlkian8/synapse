# 0093 — The app ships through EAS, as com.synapsehr.app

- **Status:** Accepted
- **Date:** 2026-10-15
- **Related:**
  - [0020 — Mobile companion app](./0020-mobile-companion-app.md);
  - [0064 — The mobile app is designed as an iOS app](./0064-the-mobile-app-is-designed-as-an-ios-app.md);
  - [Mobile app module](../modules/mobile-app.md).

## Context

Until now the app ran only in Expo Go. It had no package name or bundle identifier,
and the template's `mobile` slug and `mobile://` scheme were still in place. Nothing
said how to make an installable build. The landing page already links an APK, but none
had been built.

The API's address comes from `EXPO_PUBLIC_API_URL` in `mobile/.env`. That file is
git-ignored, so a cloud build would never see it. The app would fall back to
`localhost` and fail to sign anybody in.

## Decision

- **EAS Build** makes every installable build. There are two profiles in
  `mobile/eas.json`:
  - `preview`: an APK with internal distribution, for direct installs and the
    landing page.
  - `production`: the store formats (an Android App Bundle and an iOS build).
- **Identity**: `com.synapsehr.app` for both the Android package and the iOS bundle
  identifier. The slug is `synapse`, and the link scheme is `synapse://`.
- **The API address is part of the build profile.** `eas.json` sets
  `EXPO_PUBLIC_API_URL` to the deployed server for both profiles. `.env` stays the
  local-development override.
- **EAS owns the build number** (`appVersionSource: remote`), and production builds
  raise it automatically. `expo.version` in `app.json` remains the version people
  see.
- **Only the permissions the app uses.** The image picker's microphone and photo
  library permissions are turned off, because the app only takes a selfie with the
  camera. Android's `RECORD_AUDIO` is blocked.

## Consequences

- Once a build is in the Play Store or App Store, its package name and bundle
  identifier cannot change. Changing them later means publishing a new app.
- A build made for a different server must have its URL changed in `eas.json`, not
  in `.env`.
- `eas init` writes the EAS project's ID into `app.json` (`extra.eas.projectId`) the
  first time it runs. That change is committed like any other.
