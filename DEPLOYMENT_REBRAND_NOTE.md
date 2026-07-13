# Deployment note — JewelFlows rebrand

The display brand is now **JewelFlows** (was "JewelFlow"). Source defaults
(`.env.example`) were updated, but the real staging/production `.env` files
were **not** touched by this branch. Before/at deploy, ensure the live
environment has:

```
APP_NAME=JewelFlows
MAIL_FROM_NAME=JewelFlows
MAIL_FROM_ADDRESS=jewelflows@gmail.com
```

Notes:

- `MAIL_FROM_NAME` in `.env.example` derives from `${APP_NAME}`, so setting
  `APP_NAME=JewelFlows` is sufficient there; set it explicitly in prod if the
  live `.env` hard-codes a value.
- Do **not** commit SMTP passwords / Gmail app-password / credentials to source.
  Only the non-secret display name and from-address are managed here.
- Canonical domain: `jewelflows.com`. Old `jewelflow.in` visible references
  were repointed to `jewelflows.com`.
- Internal identifiers intentionally unchanged: DB name (`jewelflow`), PHP
  namespaces, route names, CSS/JS identifiers, the lowercase reporting
  `generator_tag`. These are not user-visible.
- Tenant/shop legal identity (name, GSTIN, address, invoice issuer) is
  tenant-supplied and unchanged.
