# APP_KEY rotation — proposal, not yet approved

**Status, 8 October 2026 (second version): prepared and tested end to end on
generated keys. No real key has been rotated.**

The whole lifecycle below, from the old key to the day it opens nothing, is
`tests/Feature/Security/AppKeyRotationLifecycleTest.php`. The single facts
are `tests/Feature/Security/AppKeyRotationTest.php`.

Why rotate: copies of an older environment file existed outside the
controlled `.env` until 8 October. They are gone; the database, mail and
payment values in them had already been changed, the application key had not.

**The first version of this proposal was wrong about recognitions.** It said
they would "be treated as invalid". They would be destroyed: the page that
lists recognitions revokes any whose proof no longer matches, so the first
owner to open it after a rotation would revoke theirs for good, and putting
the old key back would not restore it (tested). This version migrates them.

## What the key is used for, and what happens to each

| Use | During the window (new key, old kept as previous) | After the old key is removed |
|---|---|---|
| Encrypted cookies (session, `XSRF-TOKEN`) and the encrypted session payloads | keep working | a cookie sealed under the old key is refused: that browser signs in again |
| Signed links: scan sessions, repair photos, report downloads, catalogue shares, email verification | links already sent keep working | a link signed under the old key is refused |
| Password-reset tokens | unaffected (the key is used when a token is made, not when it is checked) | unaffected |
| POS quote signatures | **an open quote stops verifying at once**; the cashier prices the sale again. Previous keys do not help and are not meant to | the same |
| Recognition proofs between the two products | **re-stamped under the new key inside the window**, before any page can read them | they are new-key proofs: nothing changes |
| Mobile API tokens, remember-me tokens, two-factor secrets | not derived from the key | — |

No model uses an `encrypted` cast and nothing calls `Crypt` directly, so no
stored business column is re-encrypted.

`REDIS_PASSWORD`: nothing to rotate. No Redis server is installed, nothing
listens on its port, PHP has no Redis client, and the value in both
environment files is the placeholder `null`.

## The recognition proofs

A proof is an HMAC of the owner's identity (user, shop, product, role,
password hash, creation time) under the application key. It exists so that a
recognition dies when its owner changes.

`php artisan promotion:restamp-proofs` (added with this proposal; it does
nothing unless a previous key is configured, and changes nothing without
`--write`):

- for every recognition that is not revoked, and each of its two sides, it
  recomputes the proof from the owner's **current** identity;
- a proof that matches under the current key is left;
- a proof that matches under a **previous** key is rewritten under the
  current key: the owner is provably unchanged, only the key moved;
- a proof that matches under **no** key is left exactly as it is. Its owner
  changed, it was already invalid, and the normal page revokes it. The
  re-stamp cannot revive it (tested with a changed password).

Verification itself never accepts a previous key, before, during or after.
Old-key acceptance for proofs therefore ends inside the window, the moment
the command has run.

Requests in progress (ten-minute life) are not migrated: one started before
the window is started again.

## Order

1. **Release the command** with an ordinary release. It is inert until a
   previous key exists.
2. **Rotation, in a maintenance window**, through the release script as one
   more named setting:
   1. site in maintenance, worker stopped;
   2. `APP_KEY` = new key, `APP_PREVIOUS_KEYS` = old key; config cache rebuilt;
   3. `promotion:restamp-proofs` (reports how many would move);
      `promotion:restamp-proofs --write`; `promotion:restamp-proofs` again
      must report **0 would be re-stamped** and exit 0;
   4. PHP reloaded, site up, worker started.
   Checks: a browser signed in before the window is still signed in; a report
   link sent before the window still downloads; a recognition established
   before the window is still listed on both products and its row is not
   revoked; a new POS quote verifies.
3. **Retirement: remove `APP_PREVIOUS_KEYS`**, not earlier than the longest
   life of anything the old key signed:

   | Signed under the old key | Longest life |
   |---|---|
   | Session cookie | 120 minutes idle |
   | Catalogue share link | `catalog.share_link_ttl_days`, 30 days by default |
   | Report download link | the export's own expiry |
   | Email verification, password reset | 60 minutes |

   So: **31 days after the window**, unless the owner accepts that catalogue
   links shared in the last days before the rotation stop working sooner.
   Immediately before removing it, run `promotion:restamp-proofs` once more:
   it must still report 0 and exit 0. (Once the previous key is gone the
   command refuses to run: with nothing to compare against it could not say
   honestly that nothing is left.)
   Then remove the line, rebuild the config cache, reload PHP.
   From that moment the old key opens nothing: cookies, links, quotes and
   proofs made under it are all refused (tested).
4. Staging has its own key and goes through steps 2 and 3 first, as the
   rehearsal, with its retirement after a shortened wait.

## Recovery

- During the window, before the re-stamp: put the old `APP_KEY` back and
  remove `APP_PREVIOUS_KEYS`. Nothing was rewritten.
- After the re-stamp: the proofs are under the new key. Going back to the old
  key now needs the same command the other way (old key as `APP_KEY`, new key
  as `APP_PREVIOUS_KEYS`, `--write`) **before the site comes up**, or the
  recognitions are revoked on first read. The window's database dump is the
  last resort.
- After retirement there is no way back to the old key, by design.

## Decisions for the owner

- Approve the rotation and its date; the retirement date follows from it.
- 31 days, or sooner with the catalogue-link consequence above.
