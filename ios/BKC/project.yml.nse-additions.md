# XcodeGen project.yml — NSE Target Additions

This file documents the additions needed for the `BKCNotificationServiceExtension` target.
The iOS agent should merge these snippets into `ios/BKC/project.yml` manually.

> **Do NOT edit `project.yml` directly from this file** — it is owned by the ios-app-builder agent.

## Additions to `targets:` section

```yaml
targets:
  BKCNotificationServiceExtension:
    type: app-extension
    platform: iOS
    sources: [BKCNotificationServiceExtension]
    settings:
      base:
        PRODUCT_BUNDLE_IDENTIFIER: org.bkc.churchapp.NotificationService
        CODE_SIGN_ENTITLEMENTS: BKCNotificationServiceExtension/BKCNotificationServiceExtension.entitlements
        INFOPLIST_FILE: BKCNotificationServiceExtension/Info.plist
        SWIFT_VERSION: 5.9
        IPHONEOS_DEPLOYMENT_TARGET: 16.0
    dependencies: []

  # Also add to the existing BKC target's dependencies list:
  BKC:
    dependencies:
      - target: BKCNotificationServiceExtension
        embed: true
        codeSign: true
```

## Test Target Addition

The `NotificationServiceTests.swift` file lives in
`BKCNotificationServiceExtension/NotificationServiceTests.swift`. Add a test
target:

```yaml
targets:
  BKCNotificationServiceExtensionTests:
    type: bundle.unit-test
    platform: iOS
    sources: [BKCNotificationServiceExtension/NotificationServiceTests.swift]
    settings:
      base:
        PRODUCT_BUNDLE_IDENTIFIER: org.bkc.churchapp.NotificationServiceTests
        INFOPLIST_FILE: BKCNotificationServiceExtension/Info.plist
        SWIFT_VERSION: 5.9
        IPHONEOS_DEPLOYMENT_TARGET: 16.0
    dependencies:
      - target: BKCNotificationServiceExtension
```

## Notes

- `embed: true` + `codeSign: true` on the parent BKC target ensures the NSE
  binary is embedded in the app bundle and re-signed at build time.
- `PRODUCT_BUNDLE_IDENTIFIER` for the NSE must be a sub-bundle of the main app:
  `org.bkc.churchapp.NotificationService`.
- The App Group (`group.org.bkc.churchapp`) in the entitlements file is
  scaffolded for v1.1; the capability must be enabled in Xcode / Apple Developer
  portal before it takes effect.
- `mutable-content: 1` must be present in every FCM push payload (set by the WP
  plugin dispatcher) so APNs invokes the NSE even when the app is terminated.
