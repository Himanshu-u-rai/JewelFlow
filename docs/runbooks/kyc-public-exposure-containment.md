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

## 0. Two packages, decided independently

This file contains **two** proposals. They are separable and either can be
approved without the other. Nothing below has been executed.

| | **Option A — ORIGIN-ONLY** | **Option B — EDGE + ORIGIN** |
|---|---|---|
| What it changes | §4 nginx only | §3 Cloudflare rule, then §4 nginx, then §5 purge |
| Config diff | §4.2 | §3 expression + §4.2 |
| Verification | §6 origin block only | §6 in full |
| Rollback | §7 | §7 |
| Stops a fresh origin fetch | Yes | Yes |
| Stops a fetch served from Cloudflare's cache | **No** | Yes |
| Standing status | **PARTIAL** (§4a) — edge-cache exposure unresolved | Complete for the paths verified, **once** §3 and §5 are executed and §6 passes — none of which can be done from this session |
| Blocked dependency | none identified | **Cloudflare API/dashboard access, which I do not have.** §3 and §5 cannot be executed or verified by me |

**Option A is a real reduction and is offered on its own** precisely because
Option B's edge half is blocked. It stays labelled PARTIAL rather than being
promoted once it lands: an origin deny does nothing about a response Cloudflare
has already cached, and §5's purge is the only step that addresses that.

**Baseline staleness, stated rather than implied.** The deployed baseline in §1
was last *actually observed* on **2026-09-20T18:36:08+00:00**. This handoff is
written 2026-09-21. A commit existing as a local git object is not evidence of
what is deployed — the two are different facts about different machines. Re-read
`git rev-parse HEAD` on the server immediately before executing either option
and abort on drift.

---

## 1. Verified facts

All production checks are read-only. Timestamps are the server's own clock.

| Fact | Value | How checked |
|---|---|---|
| Deployed baseline | `018b3d810e37d534f498033ab582ee41f3197c27` | `git rev-parse HEAD` in `/var/www/jewelflow`. First checked 2026-09-16T17:29:11+00:00; **re-checked 2026-09-20T18:36:08+00:00, unchanged.** Must be re-read again immediately before any approved action |
| nginx version | 1.18.0 (Ubuntu) | `nginx -v`, same session |
| Enabled vhosts | `jewelflow`, `staging.jewelflows.com` | `ls -l /etc/nginx/sites-enabled/` |
| Serving hostnames, production vhost | **`jewelflows.com`, `www.jewelflows.com`, `dhiran.jewelflows.com`** | `/etc/nginx/sites-available/jewelflow` line 24, confirmed present in `nginx -T` output |
| Production docroot | `/var/www/jewelflow/public` | line 26 |
| Public storage symlink | `public/storage -> /var/www/jewelflow/storage/app/public` | `ls -ld` |
| KYC files on the public tree | 2 (count only; filenames deliberately not recorded) | `find … -type f \| wc -l` |
| Default server for `:80` and `:443` | the **production** block (see below) | `nginx -T` block order + TLS SNI probe |
| Staging hostname | `staging.jewelflows.com`, separate docroot `/var/www/jewelflow-staging/public` | `/etc/nginx/sites-available/staging.jewelflows.com` |
| KYC files on the staging public tree | 0 | `find … \| wc -l` |
| `set_real_ip_from` / `real_ip_header` | **absent everywhere** | `grep -rn` over `/etc/nginx/` |
| Cloudflare-only network restriction at origin | **none** | same grep: the only `deny all` directives are the dotfile rule in the staging vhost and one in the unlinked `sites-available/default` |
| Cloudflare in front | authoritative NS `stephane.ns.cloudflare.com.`, `josh.ns.cloudflare.com.` | `dig NS jewelflows.com` |

### Which server block is the default — corrected evidence

**Correction.** An earlier revision argued from the *absence* of a
`default_server` directive. That argument is invalid: nginx always has a default
server for each listening address:port, and when no block is explicitly
designated it uses the **first block declared for that address:port in
configuration order**. Absence of the directive tells you nothing by itself.
Matching 404s across hostnames told you nothing either — three identical status
codes are consistent with any number of document roots.

Effective-configuration evidence, from `nginx -T` (not the source file):

```
# configuration file /etc/nginx/sites-enabled/jewelflow:
196: server {            207: listen 80;        208: server_name jewelflows.com www.jewelflows.com;
217: server {            218: listen 443 ssl;   219: server_name jewelflows.com www.jewelflows.com dhiran.jewelflows.com;
                         221: root /var/www/jewelflow/public;
# configuration file /etc/nginx/sites-enabled/staging.jewelflows.com:
288: server {            310: listen 443 ssl;   289: server_name staging.jewelflows.com;
                         291: root /var/www/jewelflow-staging/public;
317: server {            323: listen 80;        324: server_name staging.jewelflows.com;
```

The production blocks are declared **first** for both `0.0.0.0:80` and
`0.0.0.0:443`, so they are the defaults for those addresses.

Confirmed by behaviour that actually distinguishes the two blocks, rather than by
matching status codes — the certificate served for an unrecognised SNI name is
the default server's certificate:

```
SNI unknown.example        -> subject CN = jewelflows.com
                              SAN: dhiran.jewelflows.com, jewelflows.com, www.jewelflows.com
SNI staging.jewelflows.com -> subject CN = staging.jewelflows.com      (control: name matching works)
Host unknown.example :80   -> 301 (production block; the staging :80 block returns 404)
```

So:

* **Any Host header, and any unrecognised SNI name**, presented to the origin on
  443 is served by the production block out of `/var/www/jewelflow/public`. A
  hostname allow-list at the edge therefore cannot contain this on its own.
* The nginx rule must be **host-independent**, and it — not the Cloudflare rule —
  is the authoritative control.
* `dhiran.jewelflows.com` is absent from the `:80` `server_name` (line 13), so
  plain HTTP to that host is handled by the default `:80` block and `301`-ed to
  `https://jewelflows.com$request_uri` — into a hostname the rules already cover.
  No separate HTTP rule is needed, but the redirect target must stay covered.

**`set_real_ip_from` is not a network filter.** It is absent here, and it would
not matter to containment if it were present: `ngx_http_realip_module` only
decides whether nginx *replaces the recorded client address* with one taken from
a trusted header. It grants and denies nothing. There is currently **no**
origin-level restriction limiting access to Cloudflare address ranges, which is
another reason the origin deny — not the edge rule — has to be the control.

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
credentials. I therefore cannot (a) read the existing custom-rule order, (b) read
whether any Transform Rule / Page Rule rewrites these URLs, (c) read the zone's
current cache configuration, or (d) create this rule. That is the whole blocker:
**unavailable account access and unverified account configuration.**

**Correction.** An earlier revision of this file claimed purge-by-prefix is an
Enterprise-only feature and proposed a whole-zone purge as the fallback. That is
wrong. Purge by prefix is available on **Free, Pro, Business and Enterprise**.
The Enterprise claim and the whole-zone fallback are withdrawn; §5 needs no
fallback tier, only account access.

Steps 4 (nginx) and the first-party evidence are unaffected by this block, which
is why §4a below is presented as an independently approvable option.

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
the change log; the rollback in §7 verifies against it.

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

## 4a. Option ORIGIN-ONLY — independently approvable, **PARTIAL**

The Cloudflare access gap must not hold up the part that is ready. §4 alone
(backup → insert location → `nginx -t` → reload → §6 verification → §7 rollback
rules) is a complete, self-contained change that needs no Cloudflare access and
can be approved on its own.

**Why this is labelled PARTIAL and not "contained":**

* **Cached edge copies remain unresolved.** Any KYC object Cloudflare already
  holds continues to be servable from the edge after the origin starts returning
  403, for as long as that object's TTL allows. Nothing in §4 evicts it.
* Whether any such copy exists **cannot be determined without account access**,
  and must not be probed — a HEAD against a real KYC URL can be converted by
  Cloudflare into an origin GET whose full response is then cached (§5), i.e.
  the check can create the very copy being looked for.
* With no edge rule, a request that hits a cached copy never reaches the origin
  and so never meets the deny.

What ORIGIN-ONLY *does* achieve, which is most of the value:

* Every **uncached** request — including every future one, and every
  direct-to-origin request with a forged Host or unrecognised SNI name — is
  refused. Per §1 that is the only layer that covers direct-origin access at all.
* It stops the exposure growing: no newly-uploaded KYC document can ever be
  fetched over `/storage/kyc/`, and no new edge cache entry can be created,
  because the origin will not serve one.

**Sequence if ORIGIN-ONLY is approved:** run §4, then §6, then record the
residual as an explicitly tracked open item — "edge cache state unverified,
purge outstanding" — and close it later by executing §3 and §5 once account
access exists. The residual does **not** expire on its own.

---

## 4b. Staging — separately scoped assessment, not part of this change

`staging.jewelflows.com` is a **different document root**
(`/var/www/jewelflow-staging/public`, its own `public/storage` symlink) served by
a **different server block** in a different file
(`/etc/nginx/sites-available/staging.jewelflows.com`). Nothing in §4 touches it,
and the §4 diff must not be applied to it by analogy.

Current state, metadata only: **0** files under the staging public KYC tree, so
there is nothing presently exposed there. The staging `:80` block returns 404 for
unknown hosts and the `:443` block is not the default server (§1), so it is
reached only by its own name.

If parity is wanted it is a **separate change with its own approval**, its own
backup under `/root/nginx-backups/`, its own `nginx -t`/reload, and its own
verification — the same `location ^~ /storage/kyc/ { deny all; }` inserted before
that file's `location / {` (line 10). It is **not** blocking, and it carries a
different risk profile: staging is where a first-party regression would surface
harmlessly, so applying it there first is defensible — but only if the two
changes are approved and logged separately, because a staging pass does **not**
constitute evidence about production's live consumers.

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

**Plan level is not a constraint here.** Purge by prefix is available on Free,
Pro, Business and Enterprise, so no tiered fallback and no whole-zone purge is
proposed. The only thing standing between this step and execution is account
access. (An earlier revision of this file said otherwise and proposed a
whole-zone purge; both are withdrawn.)

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

**Correction — the earlier rollback instruction was unsafe.** It said to roll
back the origin deny and leave the Cloudflare block in place. That reopens the
exposure: §1 establishes that the production block answers any Host header and
any unrecognised SNI name at the origin address, so an edge rule is not a
substitute for the origin deny. "Restore the file, keep the edge rule" is
precisely the silent reopening this section exists to forbid.

**A rollback must leave equivalent origin protection in place.** Restoring the
pre-change config file removes the only control that holds against direct-origin
requests, so a plain file restore is a *reopening*, not a rollback, and needs its
own explicit approval on the same footing as the original change.

Ordered by preference:

1. **Narrow the rule, keep an origin control.** If one specific consumer path
   regressed, replace `deny all;` with an equally host-independent rule that
   still refuses everything else — e.g. an `internal;` location plus an
   `X-Accel-Redirect` from the authenticated controller. Origin protection is
   retained; only the authorized path changes.
2. **Move the consumer to the authenticated route** (`kyc-documents.show` or the
   mobile endpoint) and keep `deny all;` untouched. This is the prescribed
   remedy for a genuine first-party regression.
3. **Full reopening.** Requires its **own explicit approval**, separately from
   the approval for applying this package. Time-boxed with a stated expiry,
   recorded in the change log with the approver's name, and §6 re-run afterwards
   with the result recorded as *"the prefix is publicly reachable again"*. Never
   a quiet `cp` back.

Mechanics for option 3 only, once separately approved:

```bash
sudo sha256sum /root/nginx-backups/jewelflow.<stamp>.pre-s3-01.conf   # match the recorded value
sudo cp -a /root/nginx-backups/jewelflow.<stamp>.pre-s3-01.conf /etc/nginx/sites-available/jewelflow
sudo nginx -t && sudo systemctl reload nginx
```

Leave the Cloudflare block in place through all three options. It is defence in
depth and removing it widens the reopening; it is **not** a stand-in for the
origin deny under any of them.

---

## 8. Optional, not part of containment

* **Staging parity** — moved to §4b, where it is scoped as its own change.
* **Broader prefix.** KYC is not the only sensitive material on the public tree
  (see the audit handoff: purchases, karigar invoices, repairs, signatures). A
  wider deny is *not* proposed here, because those prefixes still have live
  first-party consumers whose migration to authenticated routes is still OPEN.
  Denying them now would be a real regression. They are tracked separately.
