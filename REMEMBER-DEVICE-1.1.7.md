# Remember me — 1.1.7

Login checkbox is off by default. On a private HTTPS browser, checking it during successful login creates a hashed device credential valid for up to 30 days. The password itself is never stored on that browser by CMS.

On a subsequent signed-out visit, use Continue as… on the login page. This is an explicit, CSRF-protected password-step shortcut. Authenticator/email two-step remains required whenever enabled. An existing authenticated session behaves as before.

Each remembered login credential is one-use and replaced only after sign-in completes. Returning sign-ins retain the original expiration, not an unlimited sliding lifetime. Logout clears this browser credential. Password hash/email/auth_version/two-step method or revision changes invalidate previous credentials. Clearing browser cookies removes access on that browser. Other devices are independent.

Uses Secure, HttpOnly, SameSite=Lax cookies, a maximum of 10 retained credentials per account, and additive migration 022. HTTP sites do not offer this feature. Update using the CMS Core ZIP upload so pending migration 022 is applied; do not copy only the PHP view.

Verification: 84 security/notification/remembered-login checks, 33 dashboard checks, 10 native publication checks, old-Database migration compatibility and six responsive browser UI cases passed locally. Mail is mocked; real hosting SMTP/MySQL/HTTPS-cookie behavior needs hosting smoke verification. Previous final fixes retained. Core version remains 1.1.7.
