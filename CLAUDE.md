# CLAUDE.md — VS Code briefing for cs-override-checker

This file is the self-contained briefing for Claude Code (or any AI pair) working on this repo inside Visual Studio Code. Read it first. All the context you need to start coding is here or linked from here.

---

## What we're building

A Joomla 5+ **template-literate integrity monitor**. It reads Joomla's own override-diff data and classifies each flagged override as cosmetic / review / security — so site owners only get alerted on drift that actually matters. See [README.md](README.md) for the product pitch, competitive landscape, and tier model.

**Packaging:** three extensions shipped together as `pkg_csoverridechecker`.

| Extension | Role |
|---|---|
| `com_csoverridechecker` | Admin UI — dashboard, findings browser, baseline management, settings, API key config, cost caps. Also exposes the Web Services API routes (see MVP v0.1 spec below). |
| `plg_system_csoverridechecker` | Installer hooks, scheduler, admin-module-style dashboard widget, email alert dispatcher. |
| `lib_csoverridechecker` | Shared baseline, hashing, classifier ruleset, Anthropic client. Consumed by the component, plugin, and CLI. |

Placeholder scaffolds exist in `packages/`. Phase 0 fills them in.

---

## Decisions locked in

- **Joomla 5+ only.** No Joomla 4 back-compat.
- **v1 is read-only.** Detects and reports drift. Never reverts, quarantines, or modifies site files.
- **AI triage is pay-per-token.** Users supply their own Anthropic API key. The extension calls Claude directly from the site and enforces hard cost caps. No OAuth to Claude consumer subscriptions (Anthropic disallows this for third-party apps as of 2026-04-04).
- **Free tier:** scan + baseline + findings list + email alerts.
- **Paid tier:** Claude-assisted review of findings. License gating via Cybersalt's existing `cs-download-id-manager`.
- **License:** GPL v2 or later.

---

## Current sprint: MVP v0.1 — Overrides API

**Goal.** Ship three read-only REST endpoints on `com_csoverridechecker` that expose Joomla's native `#__template_overrides` data to an external consumer (primarily Claude / Claude Code, eventually any MCP client). Everything else — scanner, classifier, email alerts, hosted dashboard — builds on top of this.

Full spec: [docs/MVP-v0.1-overrides-api.md](docs/MVP-v0.1-overrides-api.md)

Supporting docs:
- [docs/CLASSIFIER-PATTERNS.md](docs/CLASSIFIER-PATTERNS.md) — the v1 classifier ruleset (auto-pass / flag-for-review / alert). Data-driven YAML or PHP array. Feeds the future scanner; referenced by the Overrides API so clients can sort responses by severity.
- [docs/FIELDWORK-2026-04-24.md](docs/FIELDWORK-2026-04-24.md) — the concrete 11-override inspection on Rocky Wall's site (fairviewterracehoa.com) that drove the classifier ruleset. Includes a real, open **XSS finding** (`$item->title` rendered raw) that the v0.1 API has to surface correctly on its first real site.

---

## Auth gotcha (learned the hard way, 2026-04-24)

Joomla's Web Services API token auth does **not** accept `Authorization: Bearer <token>`. Use:

```
X-Joomla-Token: <token>
Accept: application/vnd.api+json
```

A `Bearer`-formatted header returns `401 Forbidden`. When `lib_csoverridechecker` eventually has its own HTTP client for calling out to other Joomla sites (e.g., a hosted multi-site dashboard), hard-code the `X-Joomla-Token` format.

## `#__template_overrides` schema and the `hash_id` field (confirmed 2026-04-24)

Live test against Joomla 6.1 at j53.basicjoomla.com. Schema is exactly the columns first written in 2018 and unchanged since:

```
id, template, hash_id, extension_id, state, action, client_id, created_date, modified_date
```

The column called `hash_id` is **not a hash** — it is a **base64-encoded relative path** of the override file, beginning with `/html/`. Examples decoded:

- `L2h0bWwvbGF5b3V0cy9qb29tbGEvc3lzdGVtL21lc3NhZ2UucGhw` → `/html/layouts/joomla/system/message.php`
- `L2h0bWwvY29tX2NvbnRlbnQvZmVhdHVyZWQvZGVmYXVsdF9saW5rcy5waHA=` → `/html/com_content/featured/default_links.php`

Path resolution rules for the `{id}/override-file` and `{id}/core-file` endpoints:

| Source | Formula |
|---|---|
| Override file (client_id=0, site) | `JPATH_SITE/templates/<template><decoded hash_id>` |
| Override file (client_id=1, admin) | `JPATH_ADMINISTRATOR/templates/<template><decoded hash_id>` |
| Core file — strip leading `/html/`, then map first segment: |  |
| `layouts/…` | `JPATH_SITE/layouts/…` (or `JPATH_ADMINISTRATOR/layouts/…` for client_id=1) |
| `com_<comp>/<view>/<file>` | `JPATH_SITE/components/com_<comp>/tmpl/<view>/<file>` (or admin) |
| `mod_<module>/<file>` | `JPATH_SITE/modules/mod_<module>/tmpl/<file>` (or admin) |
| `plg_<group>_<element>/<file>` | `JPATH_PLUGINS/<group>/<element>/tmpl/<file>` |

`JPATH_PLUGINS` resolves to `JPATH_SITE/plugins` for `client_id=0` and is the same regardless of side (plugins live in one place).

## Routing gotcha (learned the hard way, 2026-04-24)

A Joomla 5 component with an `api/src/Controller/*Controller.php` + `api/src/View/*/JsonapiView.php` + `api/src/Model/*Model.php` is **not enough** on its own. The URL `/api/index.php/v1/<component>/<view>` will return `404 Resource not found` until a matching `plg_webservices_<component>` plugin exists, is installed, and is enabled.

The plugin has one job: listen to the `onBeforeApiRoute` event and call `$router->createCRUDRoutes(...)` for each route the component exposes. Every core component that has an API route (`com_content`, `com_banners`, `com_templates`, …) ships with a corresponding `plg_webservices_*`. See [packages/plg_webservices_csoverridechecker/src/Extension/Csoverridechecker.php](packages/plg_webservices_csoverridechecker/src/Extension/Csoverridechecker.php) for our implementation.

The plugin **must be enabled** after install — Joomla installs third-party plugins disabled by default. Until it's enabled, the route 404s silently.

---

## Test targets

1. **cybersalt.org** — Tim's own site. Primary dev/test target. Safe to break.
2. **fairviewterracehoa.com** — Rocky Wall's HOA site. Joomla 6.1 on a TCCK-built `fairviewtha_2025` template. **First beta install target** and the source of the v1 classifier's pattern library. Has a confirmed open XSS finding that the MVP needs to surface correctly end-to-end. Credentials live in the vault client note; Tim will hand them over when we're ready to deploy.

Proposed dev flow: build locally → test on cybersalt.org via SFTP or one-shot install zip → install on Rocky's site for end-to-end proof once stable.

---

## Reference material

- **Joomla Web Services API endpoint docs** — `E:\github\joomla-mcp-php\http\*.http`. These are Nicholas Dionysopoulos's HTTP-client collections for the Akeeba Joomla MCP; they're the cleanest route-listing reference for the core `v1/templates/styles`, `v1/extensions`, `v1/joomlaupdate` endpoints. Mirror their routing conventions for `v1/csoverridechecker/...`.
- **Existing Cybersalt component to model on** — https://github.com/cybersalt/cs-disk-usage (PHP-only Joomla 5 component, similar scope, same naming convention).
- **`#__template_overrides` schema** — read straight from Joomla core source until we confirm. Needed for the `TemplateOverride` model in `com_csoverridechecker`.

---

## Open questions (mostly for Tim)

1. **`#__template_overrides` schema** — prefer `SHOW CREATE TABLE` output from a live Joomla 5/6 site, or is it acceptable to read the CREATE TABLE statement out of the Joomla core `.sql` files? (Tim offered cPanel API access to any Cybersalt-maintained Joomla site for this.)
2. **Endpoint granularity** — split `override-file` and `core-file` into two endpoints per the current spec, or fold into one `{id}/files` endpoint returning both? Two-endpoint is easier to cache; one-endpoint is one round trip. Leaning two.
3. **Local PHP install** — VS Code side currently has no PHP. Options: (a) `winget install PHP.PHP` to run local smoke tests; (b) commit + deploy over SFTP to cybersalt.org and run from there. (b) is slower per iteration but matches production.
4. **API key gating on the MVP routes** — require a specific `csoverridechecker.view` ACL permission, or accept any valid Joomla API token with read access to `com_csoverridechecker`? Leaning the latter for v0.1, the former for v1.0.

---

## Coding conventions

- Namespaced Joomla 5 component. `Cybersalt\Component\Csoverridechecker\Administrator\...` for admin-side classes.
- PSR-12. Declare strict types.
- No direct DB access outside of model classes. Use `$this->getDatabase()` via DI, not the deprecated `Factory::getDbo()`.
- All user-visible strings go through `Text::_()` with keys under `COM_CSOVERRIDECHECKER_*`.
- Escape every echo. Yes, even in admin views. See the XSS finding in fieldwork doc for why we care.
- Follow `cs-disk-usage`'s directory structure and manifest style unless there's a specific reason to deviate.

---

## Workflow

- `main` is the default branch. Feature branches are encouraged for anything larger than a one-file tweak.
- Update `CHANGELOG.md` (under `## [Unreleased]`) in the same commit as any user-visible change.
- Keep docs in `docs/` in sync with reality. If a fieldwork finding gets closed, update the fieldwork doc — don't delete it.
- The project brief in Tim's vault (`01.projects/cs-override-checker.md`) is the long-form scoping doc. This `CLAUDE.md` is the short VS-Code-side mirror. If they diverge, the vault note is the source of truth for *strategy*; this repo is the source of truth for *implementation*.
