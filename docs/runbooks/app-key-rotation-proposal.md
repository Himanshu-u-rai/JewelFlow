# APP_KEY rotation — proposal, not yet approved

**Status, 8 October 2026: prepared. Nothing has been rotated.** The measured
facts are in `tests/Feature/Security/AppKeyRotationTest.php`.

Why rotate at all: copies of an older environment file existed outside the
controlled `.env` until 8 October. They are gone; the database, mail and
payment values in them had already been changed, the application key had not.

## What the key is used for

| Use | Where | After a rotation with the old key kept in `APP_PREVIOUS_KEYS` |
|---|---|---|
| Encrypted cookies: the session cookie and `XSRF-TOKEN` | framework | keep working (measured) |
| Session payloads in the database (`SESSION_ENCRYPT=true` on production) | framework | keep working: same encrypter |
| Signed links: scan sessions, repair photos (15 minutes), report downloads, catalogue shares, email verification | framework | links already sent keep working (measured) |
| Password-reset tokens | `RealmPasswordBrokerManager` | unaffected: the key is used when a token is made, not when it is checked |
| POS quote signatures | `PricingEngine::sign()` / `verify()` | **break**: signed with the current key only (measured). A quote open during the rotation must be made again. Quotes are short-lived |
| Recognition proofs between the two products | `ProductPromotionService::proof()` | **break**: an unchanged owner looks changed (measured), so established recognitions would be treated as invalid |
| Mobile API tokens, remember-me tokens, two-factor secrets | — | not derived from the key |

No model uses an `encrypted` cast and nothing calls `Crypt` directly, so no
stored business column needs re-encrypting.

`REDIS_PASSWORD`: there is nothing to rotate. No Redis server is installed
on the host, nothing listens on its port, PHP has no Redis client, and the
value in both environment files is the placeholder `null`. Cache is `file`,
sessions and the queue are in the database. If Redis is ever introduced, it
gets a credential then, and nothing here needs flushing.

## Proposed order

1. **Code first, released ahead of the rotation** (its own small change,
   test first): `ProductPromotionService::proofMatches()` and
   `PricingEngine::verify()` accept a value made under the current key or any
   key in `app.previous_keys`; new values are always made under the current
   key. The two "break" tests above then flip to "keeps working".
2. **Rotation, in a maintenance window of the usual kind**: generate a new
   key; set `APP_KEY` to it and `APP_PREVIOUS_KEYS` to the old one; rebuild
   the config cache; reload PHP. Check: an existing signed-in browser stays
   signed in; a new log-in works on both products; a report link sent before
   the window still downloads; a recognition established before the window
   still shows on both products.
3. **Retire the old key** after the longest-lived thing signed with it has
   expired: sessions (their lifetime), catalogue share links (their expiry).
   Remove `APP_PREVIOUS_KEYS`, rebuild the cache. From then on the old key
   opens nothing.
4. Staging has its own key and is rotated separately, first, as the
   rehearsal of steps 1 to 3.

Recovery: until step 3, putting the old key back as `APP_KEY` (and the new
one in `APP_PREVIOUS_KEYS`) restores everything. The pre-release `.env` copy
taken by the release script is the reference.

## Decisions for the owner

- Approve step 1 as the next small change.
- Whether, if step 1 is not wanted, losing established recognitions (none
  active on 8 October) and open POS quotes at rotation is acceptable.
