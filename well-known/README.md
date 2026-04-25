# well-known/apple-app-site-association

This file is the Apple App Site Association (AASA) document that enables
**Universal Links** for the BKC iOS app. It must be deployed to:

```
https://bkc.org/.well-known/apple-app-site-association
```

The file currently in this folder is a **placeholder** — `YOUR_TEAM_ID` must be
replaced with the real Apple Developer Team ID before deployment (see
"Before Deploying" below).

## Deployment Requirements

1. **Content-Type**: Must be served as `application/json`. Do NOT serve as
   `application/pkcs7-mime` or any other type. Modern AASA does not need to be
   signed (legacy iOS 8 requirement).

2. **No redirects**: Apple's CDN fetches the file directly. Any HTTP redirect
   (301, 302, 307, 308) will cause Universal Links to fail silently.

3. **HTTPS only**: The file must be reachable over HTTPS with a valid cert.
   HTTP is not accepted.

4. **File size**: Must be ≤ 128 KB. This file is well under that limit.

5. **No authentication**: The path must be publicly readable (no Basic Auth,
   no IP allowlist, no Cloudflare Access). Apple's CDN identifies itself
   anonymously.

6. **Cloudflare WAF / Bot Fight Mode**:
   - Apple fetches the file from `app-site-association.cdn-apple.com`. The
     bkc.org origin must allow Apple's crawler user agent.
   - Apple user-agent pattern includes `aasa-fetcher` (current as of 2026; see
     Apple Developer Forums for the canonical UA strings).
   - Recommended Cloudflare WAF rule:
     `URI Path contains "/.well-known/"` AND `User-Agent contains "aasa"`
     → **Skip Bot Fight Mode + rate limiting**.

7. **Apple CDN verification** (run after deployment):
   ```bash
   # 1) Verify direct serving from bkc.org
   curl -sv https://bkc.org/.well-known/apple-app-site-association
   #    Check: HTTP/2 200, Content-Type=application/json, no Location header

   # 2) Verify Apple CDN has fetched and cached the file
   #    (iOS 14+ devices fetch via CDN, not directly from origin)
   curl -sv https://app-site-association.cdn-apple.com/a/v1/bkc.org
   #    Check: returns the JSON body (may take a few minutes after first deploy)
   ```

## Before Deploying: Replace Placeholder

Replace `YOUR_TEAM_ID` in `apple-app-site-association` with the actual Apple
Developer Team ID — a 10-character alphanumeric string visible in the Apple
Developer portal under **Membership → Team ID**.

Example after substitution:

```json
{
  "applinks": {
    "apps": [],
    "details": [
      {
        "appID": "A1B2C3D4E5.org.bkc.churchapp",
        "paths": ["/sermon/*", "/news/*", "/event/*"]
      }
    ]
  }
}
```

Note: The bundle ID `org.bkc.churchapp` matches
`ios/BKC/project.yml → PRODUCT_BUNDLE_IDENTIFIER`. The companion NSE bundle
(`org.bkc.churchapp.NotificationService`) does **not** need to be listed in the
AASA file — only the main app handles incoming Universal Links.

## Paths Covered

| Path pattern | Deep link target                               |
|--------------|------------------------------------------------|
| `/sermon/*`  | Sermon WebView tab                              |
| `/news/*`    | Notifications tab (native list)                 |
| `/event/*`   | Events WebView tab                              |

These patterns are also reflected in the iOS-side router
(`ios/BKC/BKC/Services/DeepLinkRouter.swift`) and in the entitlement file
(`ios/BKC/BKC/Resources/BKC.entitlements` → `com.apple.developer.associated-domains`
= `applinks:bkc.org`). Adding a new path requires updating **all three**.

## See Also

- Root [`README.md`](../README.md) — project overview
- [`doc/ios-app-plan.md`](../doc/ios-app-plan.md) → "보안 아키텍처 → Universal Links"
