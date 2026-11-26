# Favorite CMS 1.2.1 — Identity Monitoring

- New installations auto-register where HTTPS and server connectivity work.
- Each installation retains its unique ID. Database/content, themes, plugins and uploads are not covered by core file hashes.
- Signed core manifest protects only listed PHP code; Admin and Installer remain recoverable.
- The server receives authenticated, **self-reported** core-integrity states during normal policy updates. A hosting owner can modify PHP including the reporter: not a proof of unmodified source.
- Public identity challenge at `/.well-known/favorite-central-live?challenge=<32-64 hex>` responds only for registered installations and signs the nonce with the installation secret.
- Central owner can manually check site identity; unreachable/replaced observations never automatically delete identity or block visitors.
- Suspensions typically propagate within 45 seconds of a live request when Central is reachable; not instantaneous.
- Signing public key rotated for this generated release. Store the publisher private key securely offline. Do not place it in webroot, CMS ZIP, Git, or Server ZIP.
- Avoid replacing this signed release with an unsigned ZIP. Staging tests required before public rollout.
