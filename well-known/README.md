# well-known/apple-app-site-association

This file must be deployed to `https://bkc.org/.well-known/apple-app-site-association` by web ops.

## Deployment Requirements

1. **Content-Type**: Must be served as `application/json`. Do NOT serve as
   `application/pkcs7-mime` or any other type.

2. **No redirects**: Apple's CDN fetches the file directly. Any HTTP redirect
   (301, 302, 307, 308) will cause Universal Links to fail silently.

3. **HTTPS only**: The file must be reachable over HTTPS. HTTP is not accepted.

4. **File size**: Must be ≤ 128 KB. This file is well under that limit.

5. **Cloudflare WAF / Bot Fight Mode**:
   - The domain `app-site-association.cdn-apple.com` must be allowed.
   - Cloudflare's Bot Fight Mode and rate-limit rules must permit requests to
     `/.well-known/apple-app-site-association` from Apple's crawler user agent.
   - Apple user agent pattern: `aasa-fetcher` or similar (see Apple Developer
     Forums for current UA strings).
   - Create a Cloudflare WAF skip rule: `URI Path contains /.well-known/` +
     `User-Agent contains aasa` → Skip Bot Fight Mode.

6. **Apple CDN verification** (run after deployment):
   ```bash
   # Verify direct serving: Content-Type=application/json, no redirects, HTTPS
   curl -sv https://bkc.org/.well-known/apple-app-site-association

   # Verify Apple CDN has fetched and cached the file (iOS 14+ uses CDN, not device-direct)
   curl -sv https://app-site-association.cdn-apple.com/a/v1/bkc.org
   ```

## Before Deploying: Replace Placeholder

Replace `YOUR_TEAM_ID` in `apple-app-site-association` with the actual Apple
Developer Team ID (10-character string, visible in Apple Developer portal under
Membership → Team ID).

Example: `A1B2C3D4E5.org.bkc.churchapp`

## Paths Covered

| Path pattern    | Deep link target          |
|-----------------|--------------------------|
| `/sermon/*`     | Sermon WebView tab        |
| `/news/*`       | Notifications/News tab    |
| `/event/*`      | Events WebView tab        |
