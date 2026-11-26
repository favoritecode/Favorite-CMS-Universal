# Favorite CMS v1.2.1 — Registration Hosting Compatibility

- Free license enrollment remains automatic at install and on later requests.
- Legacy `/.well-known/favorite-central.json` registration proof is preserved.
- New `GET /favorite-central-verify` returns exactly the short-lived proof while registration is pending, for hosts that block `.well-known` URLs. No connection secret is exposed.
- Central Server v1.0.1 first verifies the original proof URL, then the fallback. Both require HTTPS, matching identity, public key, timestamp and nonce.
- Existing installation IDs, sessions, database tables, site design, SMTP and admin tools are unchanged.
- Public accessibility of the dynamic route still depends on the site's front-controller routing and correctly configured canonical HTTPS address.
- Release validation does not replace real two-host registration tests. Do not assume that every hosting provider is compatible.
