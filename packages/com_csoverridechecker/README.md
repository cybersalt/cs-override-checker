# com_csoverridechecker

Joomla 5+ admin component surfacing the native `#__template_overrides` tracker via a Joomla Web Services API. Pairs with Claude to review each flagged override, apply patches in place (auto-backed-up, reversible), and clear "checked" rows.

## Current scope (v0.1)

One endpoint:

```
GET /api/index.php/v1/csoverridechecker/overrides
```

Auth: `X-Joomla-Token: <token>` header. `Authorization: Bearer <token>` is rejected by Joomla (see [../../CLAUDE.md](../../CLAUDE.md)).

Returns the raw rows from `#__template_overrides` in JSON:API format. No custom tables, no file-contents resolution, no admin UI beyond what Joomla's extensions manager gives the component for free. Everything else — pagination tuning, `{id}/override-file` and `{id}/core-file` endpoints, classifier output, admin dashboard — is deferred to later sprints. Full roadmap in [../../docs/MVP-v0.1-overrides-api.md](../../docs/MVP-v0.1-overrides-api.md).

## Layout

```
com_csoverridechecker/
├── csoverridechecker.xml                                      # manifest
├── admin/
│   ├── services/provider.php                            # DI
│   ├── src/Extension/CsoverridecheckerComponent.php           # component class
│   └── language/en-GB/
│       ├── com_csoverridechecker.ini
│       └── com_csoverridechecker.sys.ini
└── api/
    ├── src/
    │   ├── Controller/OverridesController.php           # ApiController
    │   ├── Model/OverridesModel.php                     # ListModel → #__template_overrides
    │   └── View/Overrides/JsonapiView.php               # JsonApiView
    └── language/en-GB/com_csoverridechecker.ini
```

## Install / test

Until there's a build script, zip the folder contents (everything inside `com_csoverridechecker/`, *not* the folder itself) and install via **System → Install → Upload Package File**.

Smoke test:

```bash
curl -H "X-Joomla-Token: $TOKEN" \
     -H "Accept: application/vnd.api+json" \
     https://your-site.example/api/index.php/v1/csoverridechecker/overrides
```

Expected: JSON:API `data[]` array, one object per row currently in `#__template_overrides`. On a site with no flagged overrides, `data` is empty.

## Known deferred items

- **Schema assumption.** The column list in the view and model (`id, template, hash_id, extension_id, state, action, client_id, created_date, modified_date`) came from the Joomla 4.0 initial migration. Confirm against a live Joomla 5/6 install; adjust if it drifts.
- **Pagination.** Uses `ListModel` defaults. If the response overflows on a site with many overrides, set reasonable caps (see MVP spec).
- **File contents.** `{id}/override-file` and `{id}/core-file` endpoints aren't here yet — they need path-resolution logic once the real schema and file layout are confirmed on a live site.
- **ACL.** Component-level `core.manage` is implicitly required to hit the route. Finer-grained `csoverridechecker.view` permission comes in v1.0.
