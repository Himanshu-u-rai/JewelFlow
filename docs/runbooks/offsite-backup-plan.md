# Off-site backup — plan, waiting for a destination

**Status, 8 October 2026: no approved private destination exists, so nothing
was configured.** Backups are made nightly and kept on the server's own disk
only. A lost disk loses the application and every backup of it together.

## What exists today

- A nightly archive of the database and application files, written by the
  backup package to the application's private storage, pruned by the package.
- An older series under the application's previous name (June and July)
  that nothing prunes any more. Kept until its retention is decided.
- The release dumps under root's home (see the retention plan in
  `docs/handoffs/jewelflows-continuity-audit-2026-10-08.md`).

## What is needed from the owner

One private destination, with credentials created by the owner and placed on
the server by the owner:

| Option | Notes |
|---|---|
| An S3-compatible bucket (any provider), private, with versioning or object lock | fits the backup package as a second disk; the access key should be allowed to write and list, not to delete |
| A second server reachable over SSH | a restricted account, append-only if possible |

Not acceptable: a public bucket, this repository, or a personal drive shared
by link.

## How it will be done once a destination exists

1. Add the destination as a second disk of the backup package, credentials in
   `.env` only. Released through the usual script as one more named setting.
2. Encrypt the archive before it leaves (the package's archive password), with
   the password kept by the owner outside the server.
3. First run by hand; compare the remote object's size and SHA-256 with the
   local archive.
4. **Restore test**: download one archive to an isolated instance with no
   network, restore, and compare every table by row count and content hash
   with the live database, as the release script does for its own dump.
5. Monitoring: the existing backup health check covers both disks; a missed
   night raises an alert.
6. Retention off-site: 7 daily, 4 weekly, 6 monthly, unless the owner sets
   another.

Until step 4 has passed once, the release-window dump of 8 October stays
where it is: it is the only recovery copy that has been restored and checked.
