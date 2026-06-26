# Cybersalt Override Checker

A Joomla 5/6 integrity monitor that pairs with Claude to review every flagged template override on your site, and apply the patches you confirm — auto-backed-up, reversible, no SFTP needed.

## 📥 Download

**[➡ Download the latest release (always points at the newest version)](https://github.com/cybersalt/cs-override-checker/releases/latest)**

Or grab a specific version directly:

- Always-latest zip: <https://github.com/cybersalt/cs-override-checker/releases/latest>
- Browse all versions: <https://github.com/cybersalt/cs-override-checker/releases>

Once installed, future versions show up under **System → Manage → Update** in your Joomla admin — no need to re-download.

---

## What it does

Joomla's native "X Changes found" badge in Template Manager tells you that an override file's core ancestor has changed after a core update. It doesn't tell you whether anything bad happened — and after every Joomla point release, every template with overrides lights up at once. Signal-to-noise is zero when a site owner actually needs to know whether their site is compromised.

This extension is template-literate. It pairs your site with Claude to do the work:

1. **List** every flagged override on the site.
2. **Fetch** both sides of each one — the override file and the stock core file it's shadowing.
3. **Classify** each pair as **alert** (security regression — missing escape, removed CSRF token, etc.), **review** (legitimate template customisation that's drifted), or **info** (cosmetic — copyright year, whitespace).
4. **Report** in plain English, audience-appropriate for the site owner, not the developer.
5. **Apply patches** in place for findings the owner confirms — auto-backed-up, fully reversible from the admin.
6. **Mark the rest as checked** so Joomla's "Changes found" badges go away for everything that's been reviewed.

**Two ways to run it.** The manual workflow ships with the extension since v1.0: copy a prompt from the dashboard, paste into Claude, Claude calls back to the site's Web Services API to read overrides and apply fixes. **v2.0 added the automated workflow**: save your Anthropic API key in Options, click *Run automated scan*, and the extension drives the whole review server-side — no copy-paste, no second window. Once Claude has produced the report, a chat box on the session detail view lets you ask for actions (*"fix #1 and #3, dismiss the cosmetic ones"*) and Claude applies them via tool calls without you leaving the Joomla admin.

For per-version details, see the full [CHANGELOG.md](CHANGELOG.md). The most recent release is always at the [Releases page](https://github.com/cybersalt/cs-override-checker/releases/latest).

---

## What's in the box

| Extension | Role |
|---|---|
| `com_csoverridechecker` | Admin component — dashboard, sessions log, action log, file backups (with restore), Diagnostics modal, Run automated scan, chat-with-Claude on the session detail view, Options dialog (Anthropic API key + model selection + Joomla API token), Web Services API endpoints |
| `plg_webservices_csoverridechecker` | Registers the `/api/index.php/v1/csoverridechecker/...` routes for the component. Auto-enabled by the package installer. |

Shipped together as `pkg_csoverridechecker`.

**Multilingual since v2.3.** UI ships in 15 languages: en-GB, de-DE, fr-FR, es-ES, it-IT, pt-BR, nl-NL, ru-RU, pl-PL, ja-JP, zh-CN, tr-TR, el-GR, cs-CZ, sv-SE. Joomla picks the active language automatically based on the admin user's profile.

### Two workflows

**Manual (no API key required, no extra cost beyond your Claude subscription):** Dashboard → Copy scan prompt → paste into claude.ai or Claude Code → Claude calls back to the Joomla Web Services API using your Joomla API token → produces a report → asks what to fix → applies fixes back through the API.

**Automated (requires Anthropic API key — pay-per-token):** Options → save Anthropic API key → Dashboard → Run automated scan. Server walks the override tracker, calls Anthropic, saves the report as a session. From the session view, chat box lets you ask Claude to apply fixes / dismiss findings — Claude calls server-side tool functions, you watch each turn render as a chat bubble. Auto-backups still apply; everything is reversible from File backups.

### API endpoints

Auth on every request: `X-Joomla-Token: <token>` + `Accept: application/vnd.api+json`. (NOT `Authorization: Bearer` — Joomla rejects that.)

**Read:**
- `GET /v1/csoverridechecker/overrides` — list flagged overrides
- `GET /v1/csoverridechecker/overrides/{id}/override-file` — override contents
- `GET /v1/csoverridechecker/overrides/{id}/core-file` — stock core file the override is shadowing
- `GET /v1/csoverridechecker/sessions/{id}` — fetch a previous review report

**Write:**
- `POST /v1/csoverridechecker/overrides/{id}/apply-fix` — patch the override file in place; auto-snapshots first
- `POST /v1/csoverridechecker/overrides/{id}/dismiss` — clear one tracker row
- `POST /v1/csoverridechecker/overrides/dismiss-all` — clear every remaining row
- `POST /v1/csoverridechecker/sessions` — save a review report
- `POST /v1/csoverridechecker/backups/{id}/restore` — roll back any patch

### Security

Every endpoint is gated by ACL actions defined in `admin/access.xml` (`csoverridechecker.view` for reads, `csoverridechecker.write` for mutations). Until an admin grants those actions to a group in **System → Permissions → Cybersalt Override Checker**, only Super Users can use the extension. Path-traversal containment is separator-anchored; the v2.2 hardening replaced the PHP-extension blocklist with a positive allow-list — every write must terminate inside `templates/<tpl>/html/` or `administrator/templates/<tpl>/html/` regardless of file extension. `opcache_invalidate()` runs after every PHP write so patches take effect on the next request. v2.2 also added a 4 MB cap on apply-fix / restore writes, a per-user 12-scans-per-hour soft cap on automated scans, and switched the Anthropic key fingerprint to a hash so no chars of the key leak in diagnostics. Run the `security-review` skill before tagging any future release.

---

## Install

1. Download the latest `pkg_csoverridechecker_v*.zip` from [Releases](https://github.com/cybersalt/cs-override-checker/releases).
2. **Extensions → Manage → Install** in your Joomla admin, upload the zip.
3. The package script auto-enables the Web Services plugin — no extra step.
4. Open **Components → Cybersalt Override Checker** for the dashboard, copy the prompt, and paste it into Claude.

Requires Joomla 5.0+ or Joomla 6.0+ (native to both), and PHP 8.1+.

### Updates

The package manifest registers a Joomla update server, so once 1.0.1 or later is installed, future releases show up under **System → Manage → Update** like any first-party Joomla extension. No need to re-download from GitHub each time. The updater verifies a SHA256 checksum on every download.

If you're upgrading from a release earlier than 1.0.1, install the latest zip manually once — the update server kicks in from then on.

---

## License

GPL v2 or later. See [LICENSE](LICENSE).
