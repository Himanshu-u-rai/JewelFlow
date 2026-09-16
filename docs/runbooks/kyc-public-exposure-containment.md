# KYC public-exposure containment (S3-01)

**Status: PROPOSED — awaiting explicit approval. Nothing in this file has been executed.**

This procedure is deliberately independent of the application release on
`security/multi-tenant-audit`. It contains the exposure at the edge and at the
origin without waiting for a deploy, and it does not depend on any commit from
that branch.

Impact statement, stated at the accuracy the evidence supports:
**no identified first-party viewing regression.** Both first-party read paths
already stream through authenticated controllers —
`kyc-documents.show` (web, `can:customers.view`) and
`GET /api/mobile/customers/{customer}/kyc-documents/{kycDocument}` (mobile).
Neither builds a `/storage/kyc/` URL. **External consumers remain unverified:**
nothing here proves that no third party, bookmark, cached PDF, or partner
integration fetches these paths directly.

---

## 1. Verified facts

All production checks are read-only. Timestamps are the server's own clock.

| Fact | Value | How checked |
|---|---|---|
| Deployed baseline | `018b3d810e37d534f498033ab582ee41f3197c27` | `git rev-parse HEAD` in `/var/www/jewelflow`, 2026-09-16T17:29:11+00:00 — **no drift** from the reported baseline |
| nginx version | 1.18.0 (Ubuntu) | `nginx -v`, same session |
| Enabled vhosts | `jewelflow`, `staging.jewelflows.com` | `ls -l /etc/nginx/sites-enabled/` |
| Serving hostnames, production vhost | **`jewelflows.com`, `www.jewelflows.com`, `dhiran.jewelflows.com`** | `/etc/nginx/sites-available/jewelflow` line 24 |
| Production docroot | `/var/www/jewelflow/public` | line 26 |
| Public storage symlink | `public/storage -> /var/www/jewelflow/storage/app/public` | `ls -ld` |
| KYC files on the public tree | 2 (count only; filenames deliberately not recorded) | `find … -type f \| wc -l` |
| `default_server` anywhere in `/etc/nginx/` | **none** | `grep -rn default_server /etc/nginx/` |
| Staging hostname | `staging.jewelflows.com`, separate docroot `/var/www/jewelflow-staging/public` | `/etc/nginx/sites-available/staging.jewelflows.com` |
| KYC files on the staging public tree | 0 | `find … \| wc -l` |
| Cloudflare in front | authoritative NS `stephane.ns.cloudflare.com.`, `josh.ns.cloudflare.com.` | `dig NS jewelflows.com` |

### Consequences of "no `default_server`"

`nginx.conf` line 60 is `include /etc/nginx/sites-enabled/*;`. The glob sorts
`jewelflow` before `staging.jewelflows.com`, so **the production vhost is the
implicit default server for both `:80` and `:443`.** Confirmed empirically from
the origin itself, using a path that does not exist so no real document was
requested or cached:

```
--resolve jewelflows.com:443:127.0.0.1        /storage/kyc/__containment-probe-does-not-exist -> 404
--resolve dhiran.jewelflows.com:443:127.0.0.1 /storage/kyc/__containment-probe-does-not-exist -> 404
--resolve unknown.example:443:127.0.0.1       /storage/kyc/__containment-probe-does-not-exist -> 404
```

An *unknown* Host was served by the production block. So:

* **Any Host header** presented to the origin on 443 reaches
  `/var/www/jewelflow/public`. A hostname allow-list alone cannot contain this.
* The nginx rule must therefore be **host-independent**, and it — not the
  Cloudflare rule — is the authoritative control.
* `dhiran.jewelflows.com` is absent from the `:80` `server_name` (line 13), so
  plain HTTP to that host falls through to the default `:80` block and is
  `301`-ed to `https://jewelflows.com$request_uri`. An HTTP KYC request is thus
  redirected *into* a hostname the rules already cover. No separate HTTP rule is
  needed, but the redirect target must stay covered.

`dhiran.jewelflows.com` is in scope here **only** as a name that resolves to this
docroot. This procedure does not touch, audit, or change anything else about the
Dhiran business.

---

## 2. Execution order

**Edge block → origin deny → cache purge.** Rationale, given the checks above:

1. **Edge first.** A Cloudflare custom rule with action Block evaluates before
   cache lookup, so it stops both new origin fetches *and* hits that would
   otherwise be answered from an already-cached copy. Doing this first means the
   window between steps is not an open window.
2. **Origin second.** This is the layer that actually holds, because the edge is
   bypassable by direct-IP requests with a forged Host header (proven above).
3. **Purge last.** Purging before the block would let the very next request
   re-populate the cache from the origin.

---

## 3. Layer 1 — Cloudflare custom rule

**Corrected expression.** An earlier draft used `matches`, which is a
Business/Enterprise-only operator. The portable form is `starts_with`:

```
(starts_with(http.request.uri.path, "/storage/kyc/") and http.host in {"jewelflows.com" "www.jewelflows.com" "dhiran.jewelflows.com"})
```

* Action: **Block**
* Phase: HTTP Request Firewall Custom Rules
* Placement: **first** in the custom-rules list, so no earlier Skip/Allow rule
  can short-circuit it. Existing rule order must be read before insertion.
* `http.request.uri.path` is already normalized and percent-decoded by
  Cloudflare, so `%6Byc` and `//storage//kyc//` forms are covered.
* The hostname set is the exact verified set from line 24 — not a wildcard.

**BLOCKED dependency, named exactly:** I have no Cloudflare dashboard or API
credentials, so I cannot (a) read the existing custom-rule order, (b) read the
zone plan, or (c) create this rule. **Purge by prefix is an Enterprise feature**;
the zone's plan is unconfirmed. If the zone is not Enterprise, step 5 must fall
back to purge-by-URL or a full zone purge (see §5). Steps 4 (nginx) and the
first-party evidence are unaffected by this block.

Optional hardening for the same rule set, not required for containment: a
Cache Rule setting *Bypass cache* on the same expression, so the path can never
be cached again even if the block is later removed.

---

## 4. Layer 2 — origin deny (nginx)

### 4.1 File and insertion point

File: `/etc/nginx/sites-available/jewelflow` (symlinked from `sites-enabled/`).
Insert **inside the `:443` server block, immediately before `location / {` on
line 35.**

### 4.2 Exact proposed diff

```diff
--- a/etc/nginx/sites-available/jewelflow
+++ b/etc/nginx/sites-available/jewelflow
@@ -32,6 +32,15 @@
     ssl_certificate_key /etc/letsencrypt/live/jewelflows.com/privkey.pem; # managed by Certbot
 
 
+    # S3-01 containment: customer KYC documents must never be served from the
+    # public tree. Authorized reads go through kyc-documents.show (web) and the
+    # mobile KYC endpoint, neither of which touches this prefix.
+    #
+    # ^~ is load-bearing: a plain prefix match would still lose to the
+    # `location ~ \.php$` regex below for a .php upload, and to any regex
+    # location added later. ^~ stops regex evaluation once this prefix wins.
+    location ^~ /storage/kyc/ {
+        deny all;
+    }
+
     location / {
         try_files $uri $uri/ /index.php?$query_string;
     }
```

Notes on matching, each one checked against nginx 1.18 behaviour:

* **Normalization happens before location matching.** nginx decodes `%XX`,
  collapses `..`, and (with the default `merge_slashes on`) collapses `//`
  before choosing a location. So `/storage/%6Byc/x`, `/storage/a/../kyc/x` and
  `/storage//kyc/x` all match.
* **Prefix matching is case-sensitive.** `/storage/KYC/x` does not match this
  location — and also does not exist, because the filesystem is case-sensitive,
  so it is a 404 either way. Recorded as a known non-issue, not a gap.
* **Host-independent by construction.** It lives in the block that is also the
  implicit default server, so a forged Host at the origin IP hits it too.
* **`deny all` returns 403 uniformly for every path under the prefix**,
  whether or not the file exists. It therefore discloses nothing about
  individual documents, while giving verification an unambiguous signal
  (200 → 403) that a 404-based rule would not.
* **Access logging is left on deliberately.** The log is the only instrument
  that can turn "external consumers unverified" into evidence; 403 lines under
  this prefix after cutover are exactly the signal that a real consumer exists.

### 4.3 Backup, outside every served directory

The docroots are `/var/www/jewelflow/public` and
`/var/www/jewelflow-staging/public`. `/etc/nginx/` is not served by any vhost,
but to keep the backup away from the config tree as well:

```bash
sudo install -d -m 0700 -o root -g root /root/nginx-backups
sudo cp -a /etc/nginx/sites-available/jewelflow \
  /root/nginx-backups/jewelflow.$(date -u +%Y%m%dT%H%M%SZ).pre-s3-01.conf
sudo sha256sum /root/nginx-backups/jewelflow.*.pre-s3-01.conf
```

`/root` is mode 0700 and is not under any `root` directive. Record the sha256 in
the change log; the rollback in §6 verifies against it.

### 4.4 Apply, test, reload

```bash
sudo nginx -t                    # MUST print "syntax is ok" AND "test is successful"
sudo systemctl reload nginx      # reload, not restart: no dropped connections
sudo systemctl is-active nginx
```

If `nginx -t` fails, **stop** and restore from §4.3 before doing anything else.
A failed `-t` means the reload would have been refused anyway, so the site is
still up on the old config.

---

## 5. Layer 3 — cache purge

Purge entries must be **hostname-qualified**. A bare `/storage/kyc/` is not a
valid prefix-purge entry and silently covers nothing:

```
jewelflows.com/storage/kyc/
www.jewelflows.com/storage/kyc/
dhiran.jewelflows.com/storage/kyc/
```

One entry per serving hostname, because Cloudflare keys the cache per hostname.

**Before purging, check URL transformations.** If the zone has a Transform Rule
or Page Rule rewriting URLs, the cached key may not be the request path.
Unverified — requires dashboard access.

**If the zone is not Enterprise** (prefix purge unavailable), the fallback in
descending order of preference:

1. Purge by single URL, all three hostnames × each known KYC URL. This requires
   enumerating filenames, which conflicts with the "do not expose sensitive
   filenames" constraint — so it must be done by the operator from the
   dashboard, not produced in an audit artefact.
2. Purge everything for the zone. Blunt, causes a transient origin load spike,
   but leaks nothing and needs no filename list.

Note for anyone verifying afterwards: **Cloudflare can turn a cacheable HEAD
request into an origin GET and cache the full response.** Do not "check whether
it's still cached" by issuing HEAD requests against real KYC URLs — that can
create the very cached copy you are trying to remove.

---

## 6. Verification

Run after each layer. **Use only the nonexistent probe path** for the negative
checks; the only request that may touch a real document is the authenticated
first-party check, which does not go through `/storage/`.

```bash
P=/storage/kyc/__containment-probe-does-not-exist

# Origin, host-independent (from the server itself; bypasses Cloudflare)
for h in jewelflows.com www.jewelflows.com dhiran.jewelflows.com unknown.example; do
  curl -sS -o /dev/null -w "$h %{http_code}\n" -k --resolve "$h:443:127.0.0.1" "https://$h$P"
done
# Expected after §4: 403 403 403 403   (baseline today: 404 404 404 404)

# Edge
for h in jewelflows.com www.jewelflows.com dhiran.jewelflows.com; do
  curl -sS -o /dev/null -w "$h %{http_code}\n" "https://$h$P"
done
# Expected after §3: Cloudflare block response (403), not a 404 from origin

# HTTP path still funnels into a covered hostname
curl -sSI http://dhiran.jewelflows.com$P | head -2
# Expected: 301 -> https://jewelflows.com/...
```

**First-party positive control — the check that matters most.** After §4, an
authorized user must still be able to view a KYC document through the
authenticated route. This is a manual browser check by the operator:
open a customer with a KYC document as a user holding `customers.view`, and
confirm the document renders. Equivalent mobile check: an authenticated
`GET /api/mobile/customers/{customer}/kyc-documents/{kycDocument}` returns 200.

A 403 on `/storage/kyc/` combined with a working authenticated view is the
whole success condition. If the authenticated view breaks, that is a real
regression and §7 applies.

**Post-cutover monitoring, 7 days:** count 403s under the prefix.

```bash
sudo awk '$7 ~ "^/storage/kyc/" {print $1, $7, $9}' /var/log/nginx/access.log | wc -l
```

A nonzero count with non-first-party user agents is the evidence that an
external consumer exists — the thing this package currently cannot rule out.

---

## 7. Rollback

**Rollback must not silently restore public document access.** Removing the
nginx location reopens 2 customer identity documents to the internet. So:

* The **only** rollback trigger is a verified first-party regression from §6 —
  i.e. an authorized user can no longer view a KYC document *through the
  authenticated route*. A third party losing access to `/storage/kyc/` is the
  intended effect, not a regression.
* The **prescribed** remedy for a regression is to move the affected consumer to
  the authenticated route, not to reopen the prefix.
* If reopening is genuinely unavoidable, it is a **time-boxed, logged, named
  decision** with an expiry, recorded in the change log with who approved it —
  never a quiet `cp` back.

Config rollback, when approved:

```bash
sudo sha256sum /root/nginx-backups/jewelflow.<stamp>.pre-s3-01.conf   # match the recorded value
sudo cp -a /root/nginx-backups/jewelflow.<stamp>.pre-s3-01.conf /etc/nginx/sites-available/jewelflow
sudo nginx -t && sudo systemctl reload nginx
```

**Order matters on the way back too.** Roll back the origin deny *only*; leave
the Cloudflare block in place unless it is the thing that caused the regression.
Rolling back both at once returns the system to the fully exposed baseline.
After any rollback, re-run §6 and record in the change log that the prefix is
publicly reachable again.

---

## 8. Optional, not part of containment

* **Staging parity.** `staging.jewelflows.com` serves its own public tree and
  currently holds **0** KYC files. The same `location ^~ /storage/kyc/` block
  can be added to `/etc/nginx/sites-available/staging.jewelflows.com` for
  parity. Not required, and not blocking.
* **Broader prefix.** KYC is not the only sensitive material on the public tree
  (see the audit handoff: purchases, karigar invoices, repairs, signatures). A
  wider deny is *not* proposed here, because those prefixes still have live
  first-party consumers whose migration to authenticated routes is still OPEN.
  Denying them now would be a real regression. They are tracked separately.
