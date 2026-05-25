# Changelog

All notable changes to this project will be documented in this file.

## [2.3.5] — 2026-05-25

Prompt-only patch release: closes a false-negative footgun in the scan-with-Claude review pass.

### 🔒 Security

- **Scan prompt now forbids assumption / inference shortcuts during the review pass.** A real test run flagged only 1 critical security finding on a site that had 2 — when investigated, Claude reported that it had "assumed something when searching" rather than fetching and reading every file independently. The model had cleared a file because it looked similar to an already-cleared neighbour. The scan prompt now includes a **non-negotiable "No-assumptions rule"**: every flagged override must be fetched before any verdict is issued, and inference from filename / path / template / similar-looking-files-already-cleared is explicitly forbidden. Files that were not actually fetched (e.g. because of an API budget cap) cannot get a verdict — they must be listed as "not examined this run" with a ⏸️ icon.
- **Findings-table completeness audit added to step 4.** Before publishing the report, the model must verify that the table contains a row for **every** flagged id returned by `GET .../overrides`. Silent omissions read as "clear" and produce exactly the kind of false negative this release fixes; the audit catches the omission before the report goes out. A new ⏸️ severity icon distinguishes "not examined this run" from clean ⚪ INFO so the gap is visible to the user.

### Migration

In-place upgrade from 2.3.4. No schema changes; no settings changes. Existing sessions continue to use whatever prompt was in effect when their scan ran — re-run the scan to get a report under the new rules.

## [2.3.4] — 2026-05-21

Prompt-only patch release: closes the "act on ambiguous pronouns" footgun that has bitten test sessions since chat-with-Claude landed.

### 🔒 Security

- **Scan prompt now produces "Suggested next-turn prompts".** Every Claude-generated scan report ends with three copy-paste-able prompts the user can send back to drive the next chat turn. Each prompt names finding ids explicitly (e.g. `Fix #1, #3. Mark #2, #4, #5 as checked.`) instead of pronouns like "mark these" or "fix the critical ones". Reason: in real test sessions the chat agent has misread "mark these as checked" (referring to a small subset) as "clear every row in the tracker" — wiping 70 rows on one occasion. With explicit numbered ids the user retains precision and the model has no ambiguous pronoun to resolve.
- **Chat system prompt now refuses ambiguous-pronoun instructions.** The chat agent must STOP and ask which specific finding numbers the user means whenever the instruction uses pronouns or vague references ("them all", "those", "the critical ones") without concrete ids. It must also restate the plan and wait for explicit `confirm` before any destructive action that touches more than one row. Single-row actions with an explicit id can still proceed immediately. These guardrails are belt-and-braces alongside the v2.3.3 removal of the `dismiss_all` chat tool.

### Migration

In-place upgrade from 2.3.3. No schema changes; no settings changes. Existing sessions continue to use whatever prompt was in effect when their scan ran — re-run the scan to get a new report with the Suggested next-turn prompts section.

## [2.3.3] — 2026-05-21

Iteration release: chat-with-Claude stability, dashboard polish, and two new list views (Action log + File backups) with sortable headers and a Joomla-native filter bar.

### 🚀 New

- **Per-template rescan picker.** The dashboard's "Reset overrides for review" button now opens a modal with one checkbox per template that actually has an `html/` override directory on disk. Tick which templates to rescan, optionally Select all, submit only when at least one is chosen. The controller validates posted ids against the on-disk whitelist server-side so a hostile post can't reach templates the picker didn't surface.
- **File backups list — Restore button per row.** A new green Restore icon button on each backup row submits to the existing `backups.restore` task (auto-backs-up the live file first, then writes the saved bytes). JS `confirm()` shows the destination path before submitting. Detail-page restore modal still exists; the new per-row button is the express path from the list view.
- **Filter bar on Action log + File backups list.** Joomla-native `js-stools` markup with Choices.js-wrapped dropdowns:
  - **Action log:** Search (action + details), Action-type dropdown (auto-populated from distinct values), Session dropdown (auto-populated with `#N — name` labels and an optional `(no session)` filter for orphan rows).
  - **File backups:** Search (file path), Session dropdown.
  - Both: Limit dropdown (20/50/100/200/500), Filter Options collapse, Clear button.
- **Sortable column headers** on both list views. Action log sorts by Time / Action / Session; File backups sorts by Saved / File / Size / Session. Direction indicator on the active column, ascending on first click, flips on subsequent clicks. URL state preserves filter+sort+limit across navigation and back/forward.
- **Date + time** on both list views. `Saved` and `Time` columns now stack `YYYY-MM-DD` on top of `HH:MM:SS` in muted text — easier to sort visually within the same minute.
- **API tier note on the Method 2 card.** Explains that Anthropic's default Tier 1 (10,000 input tokens/min) is fine for one-shot scans but rate-limits during multi-turn chat fixes; Tier 2 (50,000 tokens/min, billed) is comfortable. Direct link to `console.anthropic.com/settings/limits`.
- **Dashboard "Open Options" button** appears below the no-API-key warning. Goes to the component's Options form with a base64-encoded `return=` param pointing back at the dashboard, so once the user saves a key they land back here automatically — no manual refresh, no hunting for the dashboard in the menu.

### 🔒 Security

- **API key field now uses native browser password masking.** The `<input>` element renders with `type="password"` so bytes are masked the instant they're typed or pasted. Previous v2.0–v2.3.2 used a CSS `filter: blur()` overlay that showed values briefly during paste and removed the blur on focus. The Reveal toggle now swaps the input type rather than toggling a CSS class. Underlying FormField PHP class is still `TextField` (extending `PasswordField` reproduced a save-truncation bug observed in v2.0.0), so values round-trip through Joomla's save path untouched — only the rendered HTML changes. Applies to both the Anthropic API key and Joomla API token fields.
- **`dismiss_all` tool removed from the chat agent.** A test session reproduced an unsafe model interpretation: when told to "mark these 10 as checked", Claude invoked `dismiss_all` and cleared all 70 tracker rows instead of the 10 it had just enumerated. The tool description's "ONLY do this if the user explicitly tells you to" guard was not reliably honored. The chat agent now only exposes per-row `dismiss_override`; bulk-clear lives in the dashboard's per-template rescan picker where each template has its own explicit checkbox. The underlying `MarkReviewedHelper::clearAllOverrides()` helper and the REST API's `dismiss-all` endpoint are unchanged.
- **Absolute server path removed from override-file / core-file API responses.** The `data.attributes.absolute` field (e.g. `/home/<user>/public_html/...`) was redundant with the already-relativized `path` field and leaked the hosting account's filesystem layout to anyone holding a valid Joomla API token. Field removed; clients should use `path`.

### 🐛 Fixes

- **Sortable column headers actually sort now.** First implementation passed the sort URL through `Route::_()` without the `xhtml=false` argument, so it returned `&amp;`-encoded URLs. `htmlspecialchars` then double-encoded `&amp;` to `&amp;amp;`, the browser decoded that once to `&amp;` and PHP parsed query keys as `amp;view`, `amp;sort`, etc. — with no real `view=` present, Joomla defaulted to the dashboard. Fixed in both Actions and Backups sort-link helpers (and on the Clear button).
- **Chat-with-Claude no longer 400s on "tool_use ids without tool_result blocks immediately after".** The chat loop's `MAX_TURNS` cap (was 12, now 24) could exit the conversation in an `[..., assistant tool_use, user tool_result]` state when Claude needed more rounds than the budget allowed. Appending a new user text turn on top created two consecutive user messages, breaking Anthropic's strict alternation requirement. New `repairToolUseChain()` runs both before adding a new user message and before persisting, and does three things in one pass: (1) synthesises "tool execution was interrupted" tool_result blocks for any orphan tool_use ids so Claude sees an explicit error rather than silent missing data; (2) reorders tool_result blocks to the front of mixed user content (Anthropic requires `tool_result` before any `text` in the same user turn); (3) merges consecutive same-role messages so the array always alternates strictly. Self-healing — sessions that got stuck on the old shape repair themselves on the next chat turn.
- **Restore-from-list no longer lands on the backup detail page.** Previous flow always redirected to `view=backup&id=...` after restore, where the page is still titled "Backup #N" and still shows the existing "Restore now" modal — looked unfinished. The new per-row restore button sends `return=` empty (falls through to the list); the detail-page restore modal sends `return=backup` to keep its current behavior.
- **Empty Anthropic API key field no longer rejected on save.** Component config XML now allows an empty string so users can clear a saved key from the Options form without form-validation errors.

### 🔧 Improvements

- **Chat-with-Claude rate-limit posture for the 10K TPM tier.**
  - **Older tool payloads compressed at API-send time.** `apply_fix` input bodies and `get_override_file` / `get_core_file` result contents over 1KB get stripped from old turns and replaced with `(N bytes — compressed from history)` markers plus a `history_compressed: true` flag. The most recent 4 messages stay untouched so the current turn has full fidelity. The database keeps the un-compressed audit trail — only the wire payload changes.
  - **Per-turn tool-result byte cap.** When Claude fan-out-calls many tools in one round (we saw 70+ `get_*` calls in one response on a real site), the loop now executes tools in order and stops calling once cumulative `tool_result` payload exceeds 12 KB. Skipped tools return `{"rate_limit_skipped": true, ...}` stubs so Claude sees explicit markers and re-issues them in the next turn.
  - **System prompt steers Claude toward small batches.** Explicit guidance to fetch at most 2 file pairs per turn on the low tier, plus terse reply directives (bullets not paragraphs, no preambles, one-line per-fix summaries).
- **Dashboard "Run automated scan now" button is now green** (`btn-success`) instead of blue (`btn-primary`). Blue stays for copy-the-prompt buttons; green is the action that actually runs something on your site.
- **API key field on paste:** browser-native password masking means no momentary visible flicker between paste and mask. The old CSS-blur behavior had a brief visible window before the focus-removed-blur kicked in.
- **`v2` review prompt rewrite** on the dashboard. Tightens the scan-with-Claude prompt with field-tested corrections: severity now judged relative to core (was absolute, falsely flagged every legacy override that mirrors core's intentionally-raw output), normalize-before-diff rule, alternative-layout handling (no core counterpart), mandatory code-execution indicator scan with a fixed pattern list, JSON:API response shape docs so Claude doesn't have to probe, dismiss-all guardrails (refuse while ALERT unresolved; restate count), STOP-on-write-failure rule, privilege precondition required on every ALERT, framework-generated overrides (TCK/Helix/Gantry/YOOtheme) routed to REVIEW not ALERT, token-hygiene paragraph. Fix prompt got the same write-side guardrails. Same goals, ~30% less false-positive yield on a real fix session.

### Migration

In-place upgrade from 2.3.2. No schema changes; no settings changes. Existing chat sessions stuck in the old shape repair themselves on the next chat turn.

## [2.3.2] — 2026-04-29

Single-purpose patch release: fixes the empty-changelog-modal in Joomla's Extension Manager.

### 🐛 Fixes

- **Extension Manager changelog modal now renders correctly.** Joomla's `<changelogurl>` expects a Joomla-format XML feed (`<changelogs>` root with versioned `<changelog>` entries containing `<addition>`/`<fix>`/`<security>`/`<language>` etc.), not human-readable HTML. From v2.0 onwards the manifest pointed at `CHANGELOG.html`, so clicking the version badge in *Extensions → Manage* opened an empty modal for the entire 2.x series. Added [`changelog.xml`](changelog.xml) at the repo root in the format Joomla actually parses, and switched both the package manifest and `updates.xml` to point at it. `CHANGELOG.html` and `CHANGELOG.md` remain as the human-readable pair, linked from the README.

### Migration

In-place upgrade from 2.3.1. No schema changes; no settings changes. The fix takes effect on this install — sites running 2.3.1 or earlier continue to see an empty changelog modal until they upgrade to 2.3.2 (the `<changelogurl>` is baked into `manifest_cache` at install time).

### Internal

Joomla Brain `JOOMLA5-CHECKLIST.md` corrected so future Cybersalt extensions don't repeat the mistake.

## [2.3.1] — 2026-04-29

Completes the multilingual rollout started in 2.3.0: all 14 non-English language packs are now fully translated (no more English fallback for any string).

### 🌍 New

- **Full translations for all 14 additional languages.** Every one of the 246 admin strings — plus the 5 system/API/package/plugin language files in each language — now ships translated. Previously only German (de-DE) was complete; the other 13 languages installed with English-fallback content. As of 2.3.1:
  - **Romance:** fr-FR (French), es-ES (Spanish), it-IT (Italian), pt-BR (Brazilian Portuguese)
  - **Germanic / Northern European:** nl-NL (Dutch), sv-SE (Swedish)
  - **Slavic:** ru-RU (Russian), pl-PL (Polish), cs-CZ (Czech)
  - **Other European:** tr-TR (Turkish), el-GR (Greek)
  - **East Asian:** ja-JP (Japanese), zh-CN (Simplified Chinese)
- All translations use the formal admin-tool register appropriate to each language (formal pronouns where applicable). Brand names ("Cybersalt Template Integrity", "Anthropic", "Claude", "Joomla", "Akeeba Backup"), technical acronyms (API, REST, XSS, SQL, CSRF, JSON, HTTP, PHP), code references (`#__template_overrides`, `JPATH_ROOT`, `sk-ant-…`, file paths, class names), URLs, and printf placeholders (`%s`, `%d`, `%1$d`) are preserved untranslated per Joomla extension convention.

### 🔧 Improvements

- The **Options &rarr; Keys & tokens** tab and every dashboard string render in the admin's chosen Joomla language out of the box — no further configuration needed.

### Migration

In-place upgrade from 2.3.0. No schema changes; no settings changes. Admin users whose Joomla language is set to any of the 14 supported tags will now see the full UI in their language. Native review by a fluent speaker is recommended for the East Asian (ja-JP, zh-CN) and Slavic / Eastern European (cs-CZ, el-GR, tr-TR) translations before depending on them in customer-facing scenarios — open a PR or email tim@cybersalt.com with corrections.

## [2.3.0] — 2026-04-29

Multilingual support: language pack registered for all 15 of Cybersalt's standard languages.

### 🌍 New

- **Language packs registered** for `en-GB`, `de-DE`, `fr-FR`, `es-ES`, `it-IT`, `pt-BR`, `nl-NL`, `ru-RU`, `pl-PL`, `ja-JP`, `zh-CN`, `tr-TR`, `el-GR`, `cs-CZ`, `sv-SE`. All 6 language files (admin .ini + .sys.ini, api .ini, package .sys.ini, plugin .ini + .sys.ini) ship in every language. Joomla picks the active language automatically based on the admin user's preference.
- **German (de-DE) fully translated** — every one of the 246 admin strings, plus all 5 system/API/package/plugin language files. Real translations, not machine output. Uses formal "Sie" register appropriate for admin tooling.
- **Other 13 languages** — language directories registered with English fallback content (so the language pack installs cleanly and Joomla resolves to English wherever a translation isn't yet shipped). Real translations rolling out in 2.3.x patch releases over the coming weeks.

### Migration

In-place upgrade from 2.2.x. No schema changes; no settings changes. After install, an admin user whose Joomla language is set to `de-DE` will see Cybersalt Template Integrity in German throughout. Other configured languages will continue to display English admin copy until their patch release lands.

## [2.2.0] — 2026-04-29

UX rework on the dashboard: clearer "what do you want to do today" path-finding with two action shortcuts and a Cybersalt orange + Bootstrap blue palette; nicer key/token Options UX with Reveal / Clear / Get-token buttons; brand pass on every user-facing title; full-component security review with eight findings closed.

### 🚀 New

- **Method 1 / Method 2 action shortcuts** at the top of the dashboard. *Method 1: Use Claude.ai or Code* (Cybersalt orange) anchor-jumps to the copy-paste prompt below; *Method 2: Run automated scan* (Bootstrap blue, or grey-outlined when no API key is saved) jumps to the auto-scan card. Two clearly-named paths replace the old "everything's at the bottom" layout.
- **Clear button** on every API-key / token field in *Options → Keys & tokens* (the old *AI provider* tab — renamed). Click to empty the field; save persists. Joomla API token field also sprouts a *Get token* button that opens the current admin's profile in a new tab; the button auto-hides once a token is entered and reappears the moment the field is cleared.
- **Empty-state blur skip** on key/token inputs — the input no longer renders blurred when it's empty, so admins can see where to paste on a fresh field.
- **Mode-aware Rescan button** — solid yellow with dark text in light mode, outline-yellow on near-black in dark mode. Reads as a "warning" intent in both modes without the contrast trap of `btn-outline-warning` in light mode.

### 🔧 Improvements

- **Dashboard restructure.** Removed the *API endpoint active* status alert, the *Recent sessions* card (the Sessions menu covers it), and the *Mark all as reviewed* shortcut (Claude's `dismiss_all` tool covers that path). The right-hand About sidebar moved to a full-width footer so the action methods get the full canvas. Section reading order is now Method 1 → Continue Previous Review → Method 2, so the Method 2 anchor lands at the bottom where the action button lives.
- **Cybersalt action-button palette.** Method 1 uses `#dc6b1a` Cybersalt orange with white text; Method 2 (with API key) uses Bootstrap blue. Navigation buttons (Sessions / File backups / Site Templates Management / Action log) are neutral grey — they're "go fetch data" buttons, not primary actions. Recipes documented in Joomla Brain § 12 for reuse across Cybersalt extensions.
- **Top nav reordered alphabetically** — Action log → File backups → Open Site Templates Management → Sessions → Rescan → Diagnostics. Site Templates Management opens in a new tab so the dashboard stays open behind it.
- **Side-menu reorder** — Dashboard → Action log → File backups → Sessions inside the component admin sidebar.
- **Brand pass.** Every user-facing page title now reads *Cybersalt Template Integrity* (was *CS Template Integrity*) — Sessions / Action log / File backups / extension manager / API component label / update server name. Code-side identifiers (namespaces, language string keys, file paths, DB tables) keep the `cs` short form. Convention recorded in Joomla Brain `company-info.md`.
- **Get token link** lands on the current admin's user-edit form (`view=user&layout=edit&id={current admin}`) instead of falling through to the user list. `{user_id}` substitution in the field's `helplink` attribute resolves to `Factory::getApplication()->getIdentity()->id` at render time. Route URLs are single-encoded (no more double `&amp;amp;` in the rendered href).
- **Dark-mode prompt cards** are now slightly elevated (`rgba(255,255,255,0.04)`) so the card edge reads as a real container instead of blending into the page background. Light mode unchanged.

### 🔍 Security

Eight findings from the v2.0–v2.2 retroactive review closed in this release:

- **H-1 — Joomla API token plaintext exposure (privilege escalation).** A user with `cstemplateintegrity.view` could read the higher-privileged admin's saved Joomla API token off the dashboard's prompt block, and then authenticate against the *entire* Joomla Web Services API as the issuer (full privilege escalation outside cstemplateintegrity's scope). The dashboard view now substitutes the token into the prompt only when the *current* user holds the write tier — view-tier users see the `<PASTE YOUR JOOMLA API TOKEN HERE>` placeholder, identical to a fresh install. New `PermissionHelper::hasWrite()` non-throwing check.
- **M-1 — Backup restore path-safety hardening.** v0.9.0 closed the live RCE primitive (free-form `file_path` on the backup POST endpoint) but the restore-side guard still permitted writes anywhere under `JPATH_ROOT` for any non-PHP extension — `.htaccess`, `.user.ini`, or template `.css`/`.js` referenced by the active layout could be overwritten via a write-tier user with seeded archival backup rows. `PathSafetyHelper::assertOverrideWriteAllowed()` (replacing `assertPhpWriteAllowed()`) is now a positive allow-list: every write must terminate inside `<root>/templates/<tpl>/html/` or `<root>/administrator/templates/<tpl>/html/`, regardless of file extension. Updated callers: `OverridesHelper::applyFix`, `BackupsHelper::restore`, `api/Controller/BackupsController::create`.
- **M-2 — Disk-fill primitive on apply_fix and restore.** Neither write path capped the size of the *new* contents being written (the previous 1 MB cap applied only to the pre-write *snapshot*). New `PathSafetyHelper::assertSizeAllowed()` enforces a 4 MB upper cap before `file_put_contents()` in both paths.
- **M-3 — apply_fix silently failed on >1 MB legitimate overrides.** `BackupsHelper::MAX_SIZE` raised from 1 MB to 4 MB, aligned with `PathSafetyHelper::MAX_WRITE_SIZE`, so apply_fix can both snapshot the current contents and write the new contents on a real-world large override.
- **L-1 — Anthropic API key fingerprint narrowed brute-force search.** The diagnostics fingerprint previously rendered `starts="<first 8>", ends="<last 4>"` of the saved key. The last 4 chars are real entropy — combined with any other channel that leaked middle bytes, the disclosure compounds. Replaced with `fingerprint=sha256:<12 hex>` — admins can still verify "this is the key I think it is" but no chars of the actual key leak.
- **L-2 — Web Services apply-fix accepted nonexistent session_id.** The `/v1/cstemplateintegrity/overrides/{id}/apply-fix` endpoint accepted any integer `session_id`; the resulting backup row and audit-log entry would reference a session that never existed. Audit-trail integrity bug. Added `SessionsHelper::find($sessionId)` validation; non-existent ids are rejected with 400 `INVALID_SESSION_ID`. 0 / negative / null are normalized to "no session".
- **L-3 — Defense-in-depth on `simplexml_load_file()`.** `Dashboard\HtmlView::resolveComponentVersion()` now passes `LIBXML_NONET` explicitly. Not currently exploitable on PHP 8.1+ (default disables external entity loading) — belt-and-braces for older PHP and future libxml default changes.
- **L-4 — Per-user soft cap on automated scans.** `runScan` now refuses if the current user has logged 12+ `auto_scan_run` actions in the past hour. Defends against accidental click-spam and against a CSRF-coerced admin (or compromised account) burning the saved Anthropic key's spend quota in a tight loop. The cap counts attempts, not successes — a failed scan still counts. New `ActionLogHelper::countActionsByCurrentUserSince()` helper.
- **L-5 — Session-download filename consistency.** A session named `..` or `.` produced cosmetically odd download filenames (`cstemplateintegrity-..md`). `SessionController::download` now strips leading/trailing dots after sanitization and falls back to `session-<id>` when empty, matching the existing `SessionsController::downloadSelected` behavior.

### 📝 Docs

- **Joomla Brain UI patterns** — added § 12 *"Cybersalt action-button palette (Tim's preferred colors)"* documenting the Cybersalt orange + Bootstrap blue combo, the role/class/color table, the "avoid" list, and CSS recipes for `.csti-method-1-btn` and the mode-aware `.csti-rescan-btn`. Future Cybersalt extensions can copy-paste from there.
- **Joomla Brain company-info** — added a *"Naming convention: `cs` prefix vs. 'Cybersalt' full name"* section so the next extension doesn't drift between the technical short form (in code) and the brand name (in user-facing strings).
- **Joomla Brain version-bump checklist** — security review is now a hard prerequisite before any version bump. New "Review depth by bump type" matrix: 0.7 confidence threshold for routine bumps (default `/security-review`); 0.5 threshold + PHPStan required for major releases.

### Migration

In-place upgrade from 2.1.x. No schema changes; no settings changes. `BackupsHelper::MAX_SIZE` bump from 1 MB to 4 MB doesn't affect existing rows. Side menu reorder takes effect on next admin cache clear. View-tier users who previously saw the saved Joomla API token in the dashboard's prompt block will now see the placeholder instead — restore the convenience by ensuring those admins also hold `cstemplateintegrity.write` (or `core.manage`).

## [2.1.0] — 2026-04-29

### 🚀 New

- **Choose your Claude model** for the automated workflow. Two new dropdowns under *Components → CS Template Integrity → Options → AI provider*:
  - **Scan model** — Claude model used for the one-shot *Run automated scan*. Default Sonnet 4.6 (good balance). Haiku 4.5 cuts the cost roughly 3× with no real quality loss for the alert/review/info classification job. Opus 4.7 is overkill for routine scans but useful if you want extra rigor on a security-sensitive site.
  - **Chat model** — Claude model for the chat-with-Claude tool-use loop on the session detail page. Default Sonnet 4.6. Tool use is harder than classification; Sonnet is the recommended floor here even if you set the scan model to Haiku — tool calls Claude gets wrong cost more than the model price difference.
- All three models (`claude-haiku-4-5-20251001`, `claude-sonnet-4-6`, `claude-opus-4-7`) selectable per slot. Both default to Sonnet.
- A clear **cost-warning note** below the model dropdowns gives rough per-scan dollar estimates and a link to Anthropic's pricing page and usage console, so users new to the API have a concrete number to anchor on.

### 🔧 Improvements

- *Diagnostics → Run test* now uses Haiku unconditionally (cheapest model), regardless of what's configured for scan or chat. The test is a 16-token round-trip purely to verify the saved key authenticates and the network path works — no reason to burn Sonnet/Opus tokens on a verify-only call.

### 🔍 Security

- Model-id values from `#__extensions.params` are validated against the three-entry whitelist before being passed to the Anthropic API. If a future config-form bypass somehow stored an unexpected model id, calls fall through to Sonnet rather than echoing arbitrary user-controlled strings into the request payload.

### Migration

In-place upgrade from 2.0.x. No schema changes. Existing installs default to Sonnet for both scan and chat (matches v2.0.x behavior); change either dropdown in Options to switch.

## [2.0.2] — 2026-04-29

Closes the v2.0.0 audit's I-9 trade-off (Anthropic API key visible on screen in component Options).

### 🔍 Security

- **Anthropic API key field is now blurred by default** in the Options form, with a *Reveal* toggle to unmask. Custom Joomla form field (`Cybersalt\Component\Cstemplateintegrity\Administrator\Field\ApikeyField`) extends the standard text field, applies a CSS `filter: blur(5px)` to the input, and adds a Bootstrap input-group toggle button. Focusing the field also temporarily reveals it, so an admin can click in to visually verify what they pasted without committing to a sticky reveal. The underlying input stays `type="text"` (which avoided the `type="password"` truncation that bit us in v1.x), so the key still round-trips at full length. Avoids accidental shoulder-surfing exposure during screen shares, livestreams, or pair-debugging sessions.

### Migration

In-place upgrade from 2.0.x. No schema or data changes.

## [2.0.1] — 2026-04-29

Closes the three findings from the v2.0.0 security review. No new features; tightening of existing controls.

### 🔍 Security

- **Granular `cstemplateintegrity.view` ACL action is now actually enforced for admin browsing.** Previously the ACL action was declared in `admin/access.xml` and checked by every API controller and every admin write controller, but admin HtmlViews relied solely on Joomla's outer `core.manage` gate — meaning a backend user with `core.manage` on a *different* component could navigate directly to `index.php?option=com_cstemplateintegrity&view=session&id=N` and read sessions, backups, and the action log without holding `cstemplateintegrity.view`. Every admin view (`Dashboard`, `Sessions`, `Session`, `Sessionform`, `Actions`, `Backups`, `Backup`) now calls `PermissionHelper::requireView()` at the top of `display()`. Closes L-2.
- **`BackupDescriber::tidy()` and `::titleCase()` now `htmlspecialchars` their output internally.** Templates render the helper's return value unescaped (it intentionally splices in literal `&mdash;` entities for layout), and the path-segment input could in theory contain HTML in a future code path or a hand-edited backup row. Today's data flow comes from `realpath()` of an actual on-disk file so the risk is theoretical, but defense in depth — the only way to safely mix variable text with literal HTML is to escape one side. Closes M-1.
- **`display.acknowledgeDisclaimer` now calls `PermissionHelper::requireView()`** for consistency with every other controller method. The previous reasoning ("anyone who can SEE the modal must be able to dismiss it; we gate appearance, not action") was correct in practice but implicit; the explicit gate matches the rest of the codebase. Closes L-1.

### Migration

In-place upgrade from 2.0.0. No schema or data changes.

## [2.0.0] — 2026-04-29

The whole automated-review-with-Claude path landed. v1.x exposed override data via REST and let your Claude session call back to apply fixes. v2.0 closes that loop: save your Anthropic API key, click *Run automated scan*, and the extension drives the entire review server-side — fetches every override, calls Claude, gets back a markdown report, saves it as a session. From the session view you can then chat with Claude inline and have it apply fixes, dismiss findings, or clear the tracker — Claude calls tool functions on the server, you watch the result render as the next chat bubble.

### 🚀 New

- **Run automated scan** button on the Dashboard (visible when an Anthropic API key is saved). One click drives the entire override review server-side: walks `#__template_overrides`, reads override + core file pairs from disk, sends them to Anthropic with a structured prompt, saves the markdown report as a new session, redirects to it. No copy-paste into Claude required. Caps at 60 overrides per call to stay within Anthropic's per-minute input-token budget; rerun for sites larger than that, or fall back to the manual claude.ai / Claude Code workflow.
- **Chat-with-Claude on the session detail view.** Below every report there's a textarea + Send button. Submit a follow-up like *"fix #1 and #3, dismiss the cosmetic ones"* — the server runs an Anthropic tool-use loop with six tools wired to existing helpers: `list_remaining_overrides`, `get_override_file`, `get_core_file`, `apply_fix`, `dismiss_override`, `dismiss_all`. Claude calls tools, the server executes them against the same code paths the Web Services API uses, results return to Claude, the loop runs to `end_turn`. Conversation persists turn-by-turn in a new `messages` LONGTEXT column on `#__cstemplateintegrity_sessions`. Auto-backups still apply; everything Claude does is reversible from the File backups view.
- **Component Options** dialog (Joomla's standard *Options* button on the toolbar). One field today — Anthropic API key — stored in `#__extensions.params`. Field is `type="text"` (NOT `type="password"`; that was truncating the saved value during testing). Exposes the same Permissions tab Joomla wires up for any component.
- **Diagnostics modal** opened from a green button in the dashboard nav row. Shows: API-key status (saved / not saved + non-secret fingerprint with length and start/end chars and an automatic flag if the length looks truncated), system info (component / Joomla / PHP versions, API base URL, auto-scan cap), and a *Run test* button that fires a tiny `say PONG` request to api.anthropic.com to verify the saved key works without running a full scan.
- **Loading-overlay system.** Vanilla CSS + JS overlay with spinner and elapsed-seconds counter, called from `window.csti.showLoading(title, body)`. Locks the page during the 30–90s synchronous Anthropic calls (Run automated scan, chat continue) so users don't navigate away or double-submit. Tracks Atum dark mode automatically.
- **First-run "Before you start" disclaimer modal.** Renders once per admin on the first visit to any view in the component. Walks through: take a backup before any changes, what the extension actually does, AI can make mistakes, and `tim@cybersalt.com` for site owners who'd rather hand the review off than run it themselves. Acknowledgment persists per-user as a row in the existing `#__cstemplateintegrity_actions` table — when you hand a site to a client, they still see the modal once.
- **Sessions bulk download.** Tick session rows in the list and click *Download selected* in the toolbar — get a single zip with one `<sanitized-name>.md` per session.
- **Plain-English file-purpose descriptions** under every backup row. *"Featured articles — 'more articles' links list"* under `templates/.../html/com_content/featured/default_links.php`. Three-tier resolution (whitelist of common Joomla layouts → segment-pattern fallback → generic) so unknown overrides still get a sensible label.

### 🔧 Improvements

- **Dashboard layout reworked.** Quick-nav button row at the top (New session / Sessions / Action log / File backups / Open Site Templates / Diagnostics) — one click to anywhere in the component. *Reset overrides for review* moved from the bottom to a prominent position near the top. Both prompt cards lead with an alert banner that names their audience (green *"Start here. This is the only card you need for a normal review."* on the scan card, yellow *"Most users do NOT need this card."* on the cross-session-fix card).
- **Prompt boxes** restyled with explicit dark-mode rules (`#0d1117` background, mirrors GitHub's code area), no-wrap (`white-space: pre`) so long lines scroll horizontally instead of leaving ragged hanging breaks, and a thicker border + slight inset shadow so the box reads as a distinct artifact.
- **Disclaimer modal CSS** rewritten with the dual `[data-bs-theme="dark"]` / `[data-color-scheme="dark"]` selector pattern the bundled `highlight-theme.css` uses, plus a `prefers-color-scheme: dark` media-query fallback. Card now reads correctly across every Joomla 5/6 admin template's flavor of dark mode.
- **Step 2 of the dashboard prompt instructions** explicitly walks claude.ai users through the *Settings → Capabilities → Code execution → Network access* allowlist, AND tells them to start a fresh chat after adding the domain (the running chat caches the old allowlist).
- **Dashboard version display** now reads from the on-disk component manifest at render time. No more hardcoded version strings going stale.
- **Top-level README** rewritten with a prominent download link at the top (`/releases/latest`) and a feature inventory of what's in 2.0.

### 📦 Build

- **UTF-8 BOM detection** in `build-package.ps1`. Scans every `.php` / `.xml` / `.ini` under `packages/` before zipping; fails the build with a list of offending files if any have a BOM. v1.0.2 shipped broken because PowerShell's `Set-Content -Encoding utf8` adds a BOM by default in Windows PowerShell 5.1, and the BOM bytes get emitted before `<?php` parses, breaking `declare(strict_types=1)`. Cannot ship a BOM regression again without going out of our way.
- **Schema migration in postflight.** Idempotent `ALTER TABLE … ADD COLUMN messages LONGTEXT NULL` runs on every install/update, gated by a `SHOW COLUMNS` check so it's a no-op on already-migrated databases. New installs get the column from `install.mysql.utf8.sql` directly.
- **Joomla 5 + Joomla 6 native** explicitly declared via `<targetplatform name="joomla" version="5\.[0-9]+|6\.[0-9]+" />` in every manifest.

### 🐛 Fixes

- **Disclaimer "don't show again" was failing silently.** Inline JS posted the form token as a URL query parameter, but `$this->checkToken()` defaults to checking `$_POST` — the token check failed, no row landed in the action log, and the modal reappeared on every page load. Switched to `$this->checkToken('get')`. Fetch errors now log to `console.warn` so any future regression surfaces in dev tools.
- **Anthropic API key was being truncated to 99 chars** at save time. Joomla's `password` field type was the culprit — switched to `type="text"` with explicit `maxlength="500"` and `filter="raw"`. The key is now visible in the Options form (which is fine — it's an admin-only screen and it's an API key, not a password); easier to verify what was pasted.
- **`Anthropic API returned HTTP 400: Input should be a valid dictionary`** when chatting with Claude. PHP's `json_decode($body, true)` converts JSON `{}` to an empty PHP array; re-encoding turns that into JSON `[]`, but Anthropic requires `tool_use.input` to be an object. `ConversationRunner::fixEmptyToolInputs()` walks every assistant turn and replaces empty arrays with `stdClass` instances before send. Fixes parameter-less tools (`dismiss_all`, `list_remaining_overrides`).
- **Chat blew Anthropic's 10K-tokens-per-minute rate limit** because the persisted scan messages carried the full ~60K-token override-file dump that gets resent on every turn. Replaced the persisted user prompt with a short summary referring Claude to its own report; existing oversized sessions get auto-rewritten on first chat. Also tightened the per-tool-result file cap from 60 KB to 16 KB so a single `get_override_file` call can't blow the budget on its own.
- **`sprintf` was dying on `%1\$d`** in the success language string — Joomla INI doesn't escape `$`. Now `%1$d` literal.
- **Diagnostic for 401s** now appends a non-secret fingerprint (`len=99, starts="sk-ant-a", ends="x9Mg"`) plus a "this looks truncated" flag if the key length is well under the typical ~108 chars. Catches paste-truncation failures without us logging the whole secret.
- **Dashboard prompt** now lists write endpoints (`apply-fix`, `dismiss`, `dismiss-all`) in the upfront *Endpoints* block, not just buried in the workflow steps. Earlier external-Claude attempts read the GET-only list and concluded the API was read-only, then tried PATCH / `/state` / `/mark` and gave up. Block now leads with *"Endpoints (read AND write — do NOT fall back to producing a file for SFTP upload)"* with explicit "THIS IS HOW YOU APPLY A PATCH" / "THIS IS HOW YOU 'MARK AS CHECKED'" annotations.
- **HTML entities in language strings** (`&mdash;`, `&ldquo;`, `&hellip;`) replaced with literal UTF-8 characters everywhere. Strings rendered through `htmlspecialchars()` (escaped) were displaying `&mdash;` literally on screen.
- **Postflight no longer renders the install-success card on uninstall.** Joomla calls `postflight()` on uninstall too; the package and component scripts now early-return for any `$type` that isn't `install` / `update` / `discover_install`.

### 🔍 Security

- **Every new write endpoint is CSRF + ACL gated.** `display.runScan`, `display.testApiConnection`, `session.continueChat` all run `$this->checkToken()` and `PermissionHelper::requireWrite()` (or `requireView` for the read-only test-connection task). Same protection profile as the existing apply-fix / dismiss-all admin actions.
- **Tool-use fixes from chat run through the same path-safety guards** as direct apply-fix calls — separator-anchored `str_starts_with` containment under `JPATH_ROOT`, PHP-extension whitelist (only `templates/<tpl>/html/`), `opcache_invalidate()` after every PHP write. A hostile Claude response asking to apply_fix on `/components/com_users/foo.php` gets refused at the helper layer, not the controller layer, so the same defense applies whether the request came from chat or the manual API.
- **Anthropic API key never leaves the server.** Read from `#__extensions.params` per call, sent as `x-api-key` header to `api.anthropic.com`, discarded. Not echoed in any URL, query string, or referer.

### Migration from 1.x

- 1.0.x → 2.0.0 is an in-place upgrade. The schema migration runs idempotently in postflight; existing rows just get NULL in the new `messages` column until they're touched. First chat against an older session auto-seeds a 2-message conversation from the existing `report_markdown`, so the chat works on every session you've ever created.
- Sessions previously saved by the v1.0.4 build of the auto-scan have an oversized first user message (~60K tokens of override-file dump). The first chat against one of those gets it rewritten to a short summary on the fly — no manual intervention needed.

## [1.0.4] — 2026-04-29

### Fixed

- **Disclaimer modal didn't render correctly in dark mode.** The original modal CSS leaned entirely on `var(--bs-body-bg)` / `var(--bs-body-color)` and assumed Bootstrap 5.3's `[data-bs-theme="dark"]` cascade would always reach it. On Atum installs that signal dark mode via `[data-color-scheme="dark"]` instead (or where the var isn't propagating into the inline `<style>` scope for whatever reason), the card stayed white-on-bright with poor contrast. Same dual-selector pattern the bundled `highlight-theme.css` already uses — explicit `[data-bs-theme="dark"]` AND `[data-color-scheme="dark"]` overrides with hard-coded dark fallback colors so the card is readable even when no var resolves. Added a `@media (prefers-color-scheme: dark)` block as a third-tier fallback for hosts whose admin template doesn't ship dark mode but whose user's OS prefers it.
- Link in the modal body (`tim@cybersalt.com`) now picks up `var(--bs-link-color)` and switches to `#6ea8fe` in dark mode rather than the default browser blue that becomes near-invisible against `#1a1d20`.

## [1.0.3] — 2026-04-29

### Fixed

- **Hotfix: 1.0.2 produced an instant `Compile Error: strict_types declaration must be the very first statement in the script` 500 on every component view** — the Windows PowerShell pass that fixed up namespace backslashes in seven template files (during the v1.0.2 build) saved them with a UTF-8 BOM (`Set-Content -Encoding utf8` in Windows PowerShell 5.1 writes BOM by default). The BOM was emitted as raw bytes before `<?php` parsed, which made `declare(strict_types=1)` no longer the first statement and PHP fatalled at parse time. **All seven templates rewritten without BOM.** Joomla Brain's `PACKAGE-BUILD-NOTES.md` calls this out explicitly: "All files must be UTF-8 **without BOM**." Adding a check to the build script for next time so this can't slip through silently.

## [1.0.2] — 2026-04-29

### Added

- **First-run "before you start" disclaimer modal.** When a user opens any view of the extension for the first time, they get a modal that:
  - Tells them to make a full-site backup before applying any changes (and recommends Akeeba Backup or their host's backup tool).
  - Explains in one paragraph that the extension uses Claude (an AI) to review every flagged override and apply the patches the user confirms — and that auto-backups make individual patches reversible.
  - Reminds them AI can make mistakes; always review proposals before saying "yes, apply" and test after.
  - Offers `tim@cybersalt.com` for users who aren't comfortable doing the review themselves.
  - Has a "Don't show this again" checkbox; when ticked and the user clicks "I understand — continue", the dismissal is persisted server-side per-user.
- The dismissal state is stored as a row in the existing `#__cstemplateintegrity_actions` table (action key `disclaimer_acknowledged`), so each admin acknowledges separately. When you hand a site to a client and they log in for the first time, they still see the warning. To reset for a single user (e.g. for testing), delete that row.
- New helper `DisclaimerHelper` and a single-line `<?php echo \…\DisclaimerHelper::renderModalIfNeeded(); ?>` call at the top of every component view template (dashboard, sessions list, session detail, sessionform, action log, backups list, backup detail).
- New `display.acknowledgeDisclaimer` controller task — POST + CSRF token; called by the modal's inline JS via `fetch()` when the user ticks the box and confirms.
- Modal is rendered with a vanilla CSS overlay + Bootstrap CSS variables (no Bootstrap modal asset required) so it works on every view without per-view JS asset wiring. Tracks Atum light/dark via `var(--bs-body-bg)` etc.

## [1.0.1] — 2026-04-29

### Improvements

- **Explicit Joomla 5 + Joomla 6 compatibility** declared in every manifest. All three (`com_cstemplateintegrity`, `plg_webservices_cstemplateintegrity`, `pkg_cstemplateintegrity`) now carry `<targetplatform name="joomla" version="5\.[0-9]+|6\.[0-9]+" />`. The code itself was already 5/6-native — services/provider.php DI containers, `ApiController` + `JsonapiView`, namespaced `Factory`/`DatabaseInterface`, zero `JFactory`/`JHtml`/`JLog`/`JLoader` legacy calls — but the manifests didn't say so out loud, which can show "version not compatible" warnings on Joomla 6 even when everything works.
- **Joomla update server wired up.** Package manifest now declares `<updateservers>` pointing at `updates.xml` on the main branch and `<changelogurl>` pointing at `CHANGELOG.html`. Once a site has 1.0.1 installed, future releases will surface in **System → Manage → Update** automatically — no manual zip download from GitHub.
- New `updates.xml` and `CHANGELOG.html` at the repo root, kept in sync with `CHANGELOG.md`. SHA256 checksum included on every release so Joomla doesn't show the "no checksum" warning.

### Why a 1.0.1 so soon after 1.0.0

The targetplatform + update-server metadata was the missing piece between "v1.0 ships" and "site admins can keep up to date in one click." 1.0.0 was the milestone; 1.0.1 is what makes 1.0+ deployable at scale.

## [1.0.0] — 2026-04-25

First stable release. The core flow — *site owner pastes one prompt → Claude lists every flagged override on the site, classifies each one, writes a plain-English report, asks which to fix, applies the fixes the owner confirms, marks the rest as checked* — has been live-tested end-to-end on cybersalt.org and on a real client site (fairviewterracehoa.com) running Joomla 6.1 on a third-party template.

### What's in 1.0

- **Component (`com_cstemplateintegrity`)** — admin dashboard with copy-paste scan + cross-session fix prompts, sessions log, action log, file backups view with restore, all gated by `cstemplateintegrity.view` / `cstemplateintegrity.write` ACL actions defined in `admin/access.xml`.
- **Web Services plugin (`plg_webservices_cstemplateintegrity`)** — registers the `/api/index.php/v1/cstemplateintegrity/...` routes; auto-enabled by the package installer.
- **API endpoints** — `GET /overrides`, `GET /overrides/{id}/override-file`, `GET /overrides/{id}/core-file`, `GET /sessions/{id}`, `POST /sessions`, `POST /overrides/{id}/apply-fix`, `POST /overrides/{id}/dismiss`, `POST /overrides/dismiss-all`, `POST /backups/{id}/restore`.
- **Auto-backup** before every file write — snapshot of current contents, sha256, full content stored in `#__cstemplateintegrity_backups`. Every patch reversible from the admin or via the API.
- **Hardened against the v0.9 security review** — separator-anchored path containment (no prefix-collision bypass), PHP-extension write whitelist (only `templates/<tpl>/html/` — no other path under `JPATH_ROOT` accepts `.php` writes), `opcache_invalidate()` after every PHP write, GET-form CSRF tokens on download links, CRLF/header-injection sanitization on `Content-Disposition`, `htmlspecialchars()` on every `Text::_()` rendered into the installer frame, no free-form `file_path` accepted in any request body — backup paths are server-side derived from `#__template_overrides` rows.
- **Same-chat conversational flow** — the scan prompt ends by asking the user which findings to fix and waits, then runs fetch → patch → apply-fix → dismiss inline. The cross-session fix prompt is opt-in for the rare case of starting a fresh chat to continue an earlier review.
- **Claude.ai sandbox guidance** built into the dashboard prompt instructions — *"Host not in allowlist"* error gets a one-paragraph fix path (Settings → Capabilities → Code execution → Network access; then close the current chat, start a new one to pick up the updated allowlist).
- **Plain-English descriptions** on backup rows so non-technical viewers can tell at a glance what each stored file actually is.
- **Unknown-finding catch-all** — if a finding doesn't fit "code change" or "configuration question" (needs a database tweak, plugin reinstall, contact the third-party developer), Claude stops and explains in plain English instead of attempting a partial fix.

### Cleanup before 1.0

- Removed the `v0.1 exposes the native …` early-MVP wording from XML descriptions and language strings.
- Dashboard's version display now reads from the on-disk component manifest (`cstemplateintegrity.xml`) at render time instead of being hardcoded — won't go stale on the next bump.
- README rewritten for a release-state audience (what's in 1.0, how to install, where the API endpoints live, what the security model is).

### Migration from 0.x

There was no in-place upgrade path between 0.9 (`csintegrity`) and 0.10 (`cstemplateintegrity`) due to the rename, and 0.10/0.11 were quick iterations on top of 0.10.0's fresh-install schema. The 0.11 → 1.0 step IS in-place: install `pkg_cstemplateintegrity_v1.0.0_*.zip` over an existing 0.11.x install and Joomla will upgrade in place.

If you're still on 0.9 (`csintegrity`): uninstall the old package first (drops the `#__csintegrity_*` tables), then install 1.0.

## [0.11.2] — 2026-04-25

### Added

- **Plain-English description column on the backups list and detail view.** Each row now shows a one-liner under the path explaining what the file actually is — e.g. *"Featured articles — 'more articles' links list"* under `templates/.../html/com_content/featured/default_links.php`, or *"Article previous / next navigation links"* under `templates/.../html/plg_content_pagenavigation/default.php`. Surfaced when Tim test-restored on Rocky's site and three rows looked superficially identical (two were the same logical file in two parallel template directories with different casing — the descriptions plus the path now make that obvious at a glance, instead of having to scan three near-identical paths character-by-character).
- New `BackupDescriber` helper does the mapping. Resolves in three tiers: exact-match whitelist of common Joomla core layouts (~30 entries), pattern-based derivation from the path's first segment after `/html/` (handles any `com_*`, `mod_*`, `plg_*`, `layouts/*` we don't have an explicit row for), and a generic fallback. No schema change, no API change — derived purely from the existing `file_path` column, so retroactive on every backup row.

## [0.11.1] — 2026-04-25

### Fixed

- **Scan prompt now lists the write endpoints in the upfront *Endpoints* block, not only inside the workflow steps.** Live test on fairviewterracehoa.com (running pre-v0.11) hit the old shape: when asked to apply fixes inline, Claude scanned the *Endpoints* list, saw three GETs and concluded "the cstemplateintegrity REST API is read-only," then tried PATCH/PUT and made-up paths (`/state`, `/mark`, `/check`, `/override-file` write) before giving up and producing patched files for the user to SFTP up by hand. v0.11.0's step 6 already documents `apply-fix` / `dismiss` / `dismiss-all` further down the prompt, but if the AI never read past the endpoints block it never reached that step. The block now lists all read AND write endpoints with explicit "THIS IS HOW YOU APPLY A PATCH" / "THIS IS HOW YOU 'MARK AS CHECKED'" annotations, and the section heading itself says: *"do NOT fall back to producing a file for SFTP upload"*. Same treatment applied to the cross-session fix prompt.
- Clarification added inline that *dismiss* is the canonical "mark as checked" action — there is no separate state flag, and dismissing the override row IS the reviewed-and-accepted decision. The fairview test had Claude trying to PATCH `state` on individual rows, which doesn't exist.

## [0.11.0] — 2026-04-25

### Changed

- **Reworked the two-prompt UX into a one-prompt-plus-conversation flow.** The old model assumed users would always paste two prompts (one to scan, one to fix), but in the same Claude chat the second prompt was redundant — Claude already had the session id, the diffs, the API base, and the report in context. v0.11.0 collapses the default flow into the scan prompt and reframes the fix prompt as the cross-session helper.
  - **Scan prompt** now ends with an explicit hand-off step (#6): after POSTing the report, Claude asks the user which findings to fix and waits, then for each confirmed finding runs the same fetch → patch → apply-fix → dismiss workflow that used to live in the fix prompt. **Critically, it now also handles the unknown-finding case** — if a finding doesn't fit "code change" or "configuration question" (e.g. it needs a database tweak, a plugin reinstall, or contacting a third-party developer), Claude stops and explains in plain English instead of applying a partial fix.
  - **Fix prompt** is now scoped to the cross-session case only: starting a fresh chat to continue a review (closed the original chat overnight, handing it to a teammate, switched from claude.ai to Claude Code mid-flow). It now begins by GET-ing the prior session report (`/sessions/{id}`) so a new Claude can rebuild the context, then asks the user which findings to fix. Same unknown-finding catch-all applies.
- **Dashboard reworked to make the two paths obvious.** Each card now leads with a Bootstrap alert banner stating who it's for:
  - Scan card: green *"Start here. This is the only card you need for a normal review."*
  - Fix card: yellow *"Most users do NOT need this card. Use only if you're starting a fresh chat to apply fixes from a previous review."*
  - Card titles renamed: *"Use with Claude"* → *"Run a review with Claude"*; *"Apply fixes"* → *"Continue a previous review in a new chat"*.
- New step 4 in the scan card's instructions: *"Tell Claude what to fix — in the same chat"* with a concrete example reply, so non-technical users see the conversational hand-off they'd otherwise have to infer.

### Why

A real client test on fairviewterracehoa.com triggered the question: the existing fix prompt was tightly coupled to the three classifier categories (ALERT/REVIEW/INFO), and a finding that didn't fit either of the prompt's two action buckets ("code change" or "configuration question") would have left Claude in an awkward spot. The conversational hand-off side-steps that — Claude can ask the user before doing anything ambiguous — and the catch-all branch makes it explicit even in the cross-session prompt.

## [0.10.2] — 2026-04-25

### Changed

- **Dashboard step 2 now also tells claude.ai users to start a new chat after adding the domain to the allowlist.** The running chat caches the allowlist it had at start; only a fresh chat picks up the newly-added domain. Without this hint, users add the domain, resend the prompt, get the same "Host not in allowlist" error, and assume the allowlist setting didn't take. Note covers both the Claude desktop app and the web version.

## [0.10.1] — 2026-04-24

### Fixed

- **Uninstall flashed the "installed, click here to open the dashboard" card.** Both `com_cstemplateintegrity/script.php` and `pkg_cstemplateintegrity/script.php` had postflight handlers that ran the install-message renderer on every postflight call — including uninstall. Joomla calls `postflight($type, …)` on install, update, **and uninstall**, so without an explicit `$type` filter the uninstall path rendered an "installed" success card with a link to a route that no longer existed. Both scripts now early-return from `postflight()` for any `$type` that isn't `install` / `update` / `discover_install`. The package script also gates its `enableWebservicesPlugin()` call on the same set, so it doesn't try to enable a plugin that's about to be removed.
- **Apply HTML-escape to `Text::_()` output in pkg_/script.php.** v0.9.0 fixed this in `com_/script.php` but missed the matching code in the package-level installer; now both wrap every `Text::_()` in `htmlspecialchars()` before echoing into Joomla's installer frame.

### Changed

- **Dashboard step 2 now documents the claude.ai code-execution sandbox allowlist.** Hitting the prompt for the first time on a fresh claude.ai account fails with *"Host not in allowlist"* — the sandbox blocks every domain not on its default list. The Step 2 body now points users at *Settings → Capabilities → Code execution → Network access* in claude.ai and notes that Claude Code in a terminal has no such restriction. (Cosmetic clarification only — no code change beyond the language string.)

## [0.10.0] — 2026-04-24

### Changed

- **Renamed everywhere from `csintegrity` to `cstemplateintegrity`.** "CS Template Integrity" matches Cybersalt's existing extension family naming (`cs-disk-usage` → `com_csdiskusage`, etc.) and also locks the scope: this tool stays focused on template-override integrity. MySites Guru already covers core-file integrity, extension-file integrity, and stowaway-file detection on Joomla, so we don't intend to expand into those.
- Component slug `com_csintegrity` → `com_cstemplateintegrity`. Plugin `plg_webservices_csintegrity` → `plg_webservices_cstemplateintegrity`. Package `pkg_csintegrity` → `pkg_cstemplateintegrity`. Library and system-plugin scaffolds renamed to match.
- Namespace `Cybersalt\Component\Csintegrity\…` → `Cybersalt\Component\Cstemplateintegrity\…`. Plugin namespace `Cybersalt\Plugin\WebServices\Csintegrity\…` → `Cybersalt\Plugin\WebServices\Cstemplateintegrity\…`.
- Database tables `#__csintegrity_sessions/_actions/_backups` → `#__cstemplateintegrity_sessions/_actions/_backups`.
- API routes `/v1/csintegrity/…` → `/v1/cstemplateintegrity/…`. ACL actions `csintegrity.view/.write` → `cstemplateintegrity.view/.write`. Language keys `COM_CSINTEGRITY_*` → `COM_CSTEMPLATEINTEGRITY_*`. Component option `option=com_csintegrity` → `option=com_cstemplateintegrity`. Media destination folder likewise.
- The old v0.6.0 update SQL (which targeted `#__csintegrity_*` tables) has been dropped. v0.10.0 is the new floor; there is no in-place upgrade from v0.9.x — uninstall v0.9 first (which drops the old tables) then install v0.10.

### Migration

There is no automatic upgrade path from v0.9.x. To move a site from v0.9 to v0.10:

1. Note any sessions/backups you want to preserve (download via the admin UI). On a fresh dev site, skip this.
2. Uninstall **CS Template Integrity** from the Joomla extension manager. This drops the `#__csintegrity_*` tables and the `csintegrity` web-services plugin.
3. Install `pkg_cstemplateintegrity_v0.10.0_*.zip`. The new `#__cstemplateintegrity_*` tables are created at install time.
4. Enable the **plg_webservices_cstemplateintegrity** plugin in System → Plugins (third-party plugins install disabled by default).
5. In **System → Permissions** for **CS Template Integrity**, grant *View* and *Modify* to whatever group should be able to use the API/admin (Super Users always pass).

## [0.9.0] — 2026-04-24

### Security

This release closes the high- and medium-severity findings from the v0.8.5 security review and adds defense-in-depth around any code path that writes files under `JPATH_ROOT`. **Upgrade is strongly recommended for any installation of v0.8.x; v0.8.x exposed write-side API endpoints with no permission gate, which on a production site is an authenticated arbitrary-write-under-webroot primitive.**

- **ACL gate on every endpoint.** New `admin/access.xml` defines two component-scoped actions, `csintegrity.view` and `csintegrity.write`, alongside the standard `core.admin` / `core.manage` / `core.options`. New `PermissionHelper` is consulted at the top of every API controller method and every admin write-side controller method. Read endpoints (overrides list, override-file, core-file, sessions list, sessions item, backups list, backups item) require `csintegrity.view` or `core.manage`. Write endpoints (`apply-fix`, `dismiss`, `dismiss-all`, `backups POST`, `backups/:id/restore`, `sessions POST`, the admin restore/delete/save/rescan/markReviewed actions) require `csintegrity.write` or `core.manage`. Until an admin grants those actions to a group, only Super Users can use the extension. Previously, any valid Joomla API token reached every endpoint.
- **Backups POST no longer accepts a free-form path or contents.** The new contract requires `override_id`; the helper resolves the file path server-side from `#__template_overrides` and snapshots the live file's bytes from disk. The previous `file_path` + `contents` body would, when paired with `restore`, write arbitrary bytes to any path under `JPATH_ROOT` — a clean RCE primitive once an attacker had a write token. The new shape rules out that bridge entirely.
- **Path-traversal guard rewritten.** The old `strpos($parentReal, $rootReal) !== 0` check passed when `JPATH_ROOT` had a sibling whose real path began with the same prefix — e.g. `/var/www/joomla` and `/var/www/joomla-bak`. New `PathSafetyHelper::assertWithinRoot()` uses `str_starts_with` with a trailing `DIRECTORY_SEPARATOR` so prefix collisions cannot bypass it. Both `OverridesHelper::applyFix()` and `BackupsHelper::restore()` now route through it, plus the new backup-create flow.
- **PHP-write whitelist.** `PathSafetyHelper::assertPhpWriteAllowed()` refuses any `.php` / `.phtml` / `.phar` / `.pht` write whose path is not under `templates/<tpl>/html/` (site) or `administrator/templates/<tpl>/html/` (admin). Belt-and-braces against a hostile `#__template_overrides` row whose `hash_id` decodes to a path that escapes `/html/`. Non-PHP extensions are unrestricted (still subject to within-root).
- **opcache invalidation.** Both write paths call `opcache_invalidate()` (when available) on the target after `file_put_contents()`, so a fix that changes a file already cached by OPcache takes effect on the next request rather than silently running stale bytes.
- **CRLF / response-splitting fix on backup download.** Admin `backups.download` previously reflected `basename($row->file_path)` into the `Content-Disposition` header after only stripping double-quotes. A backup row whose path contained CR/LF could inject arbitrary HTTP headers. The filename now passes through `preg_replace('/[^A-Za-z0-9._-]/', '-', ...)` before being reflected. (`session.download` already had this guard.)
- **CSRF on download endpoints.** Admin `backups.download` and `session.download` now require a session form-token query parameter via `checkToken('get')`, plus an explicit `PermissionHelper::requireView()`. Listing templates were updated to append `Session::getFormToken()`. Without this, a logged-in admin could be tricked by a crafted cross-site link into exfiltrating a backup or session report.
- **HTML-escape installer post-message.** `script.php::showPostInstallMessage()` now wraps every `Text::_()` call in `htmlspecialchars()`. Today's strings are static, but installer output should not template translation strings as raw HTML — a future translator's mistake would otherwise become an issue at install time.
- The README and the dashboard help string previously claimed `core.manage` was enforced; that claim is now actually true.

## [0.8.5] — 2026-04-24

### Fixed
- v0.8.4 didn't actually highlight backup contents when Tim opened a backup. Two reasons: (1) Joomla's `HTMLHelper::script()` silently drops options it doesn't recognize, including `defer`, so `defer => true` in the options array ended up nowhere — moved to the fourth parameter ($attribs) so it lands on the script tag as a real HTML attribute. (2) Even with defer, script timing can be funny across browsers and proxies; `wireSyntaxHighlight()` now polls for `window.hljs` for up to 5 seconds before giving up. Either fix on its own should be enough; both together cover the long tail of edge cases. If `hljs` never loads, a `console.warn` records the failure so it's diagnosable from the browser dev tools.

## [0.8.4] — 2026-04-24

### Added
- **Syntax highlighting** on the Backup detail view's contents block. Bundled `highlight.js` v11.9.0 (~120 KB minified) plus a small `highlight-theme.css` that picks token colors from Bootstrap CSS variables (`--bs-primary`, `--bs-success`, `--bs-warning`, etc.) so it adapts to Atum's light/dark mode without needing two separate stylesheets. Language is auto-detected from the file extension — `.php`, `.html`, `.css`, `.js`, `.json`, `.yaml`, `.md`, `.sh`, `.sql`, `.ini`, plus a few aliases. Falls back to plaintext for anything unknown. If the bundled JS fails to load for any reason, the codeblock still renders fine — just unhighlighted.

### Fixed
- **Resize handle on the session report and backup contents only allowed shrinking, not growing.** CSS specificity bug — `.csintegrity-dashboard .csintegrity-codeblock { max-height: 320px }` (the dashboard prompt-card scope) was beating `.csintegrity-report { max-height: none }`, so the 320 px cap leaked onto pages it shouldn't have. Fixed via `:not()` exclusion on the dashboard rule plus `!important` on the resizable-block rule for belt-and-braces. The same `.csintegrity-backup-contents` class added on the Backup view now also resizes properly.

## [0.8.3] — 2026-04-24

### Changed
- `BackupsHelper::createFromContents` now deduplicates on `(file_path, sha256)`. If a backup with that exact path and content already exists, the helper returns the existing id instead of inserting an identical row. Eliminates the noise Tim hit on cybersalt.org where one apply-fix run produced 5 backups of which 4 were byte-identical pre-action snapshots. Audit-log calls in callers (e.g. `fix_applied`, `backup_restored`) still reference the now-shared backup id, which is semantically correct — the snapshot of that state.

### Added
- Backups list grew a checkbox column and a standard "Delete" toolbar action so existing duplicates (or any stale backup) can be pruned. Backed by `BackupsHelper::delete(int $id)` and `BackupsController::delete()`.

## [0.8.2] — 2026-04-24

### Fixed
- POST endpoints with `:id` URL captures (`/overrides/{id}/apply-fix`, `/overrides/{id}/dismiss`, `/backups/{id}/restore`) now resolve the id reliably even when Joomla's API dispatcher doesn't populate `$this->input` from a POST URL capture (the dispatcher does this for GET routes but not POST in practice). New `resolveIdFromRequest()` helper checks three sources in order: the method argument, `$this->input->getInt('id')`, and a regex against `REQUEST_URI`. Same fallback inlined into `BackupsController::restore()`. Existing GET endpoints (`overrideFile` / `coreFile`) also routed through the helper for consistency.

## [0.8.1] — 2026-04-24

### Fixed
- v0.8.0's three new POST endpoints (`apply-fix`, `dismiss`, `backups/.../restore`) were rejecting requests with "A numeric override id is required" even when the id was clearly in the URL. Joomla's API dispatcher passes `:id` URL captures as a method parameter for POST endpoints, not via `$this->input`. Each method now accepts `$id = null` and falls back to `$this->input->getInt('id', 0)` as a safety net.

## [0.8.0] — 2026-04-24

### Added
- **Apply Fixes is now end-to-end automatable.** Three new write-side API endpoints round out the workflow so Claude can complete the loop without the user clicking through the admin between each step:
  - `POST /v1/csintegrity/overrides/{id}/apply-fix` — body `{ contents, session_id? }`. Auto-backs up the live file's current state, writes the patched contents to disk, returns the pre-fix backup id so the operation is reversible. Path-traversal guard refuses anything outside `JPATH_ROOT`. Backed by `OverridesHelper::applyFix()`.
  - `POST /v1/csintegrity/overrides/{id}/dismiss` (and `DELETE` on the same URL) — clears one override-tracker row. Backed by `OverridesHelper::dismissOne()`.
  - `POST /v1/csintegrity/overrides/dismiss-all` — clears every flagged row. Wraps the existing `MarkReviewedHelper::clearAllOverrides()`.
  - `POST /v1/csintegrity/backups/{id}/restore` — same effect as the admin Restore button, exposed via API. Wraps `BackupsHelper::restore()`.
- New action constants `ACTION_FIX_APPLIED` and `ACTION_OVERRIDE_DISMISSED` so the action log distinguishes write events from passive ones.
- New `Cybersalt\Component\Csintegrity\Administrator\Helper\OverridesHelper` for write-side operations (the existing `OverridesModel` is read-only).

### Changed
- Apply Fixes prompt rewritten for the new endpoints. Workflow is now: classify finding → fetch current contents → build patch → POST `/apply-fix` (one call: auto-backup + write) → POST `/dismiss` for that finding's tracker row. After all fixes are applied, an optional `/dismiss-all` clears bulk non-security warnings the user explicitly told Claude to mark checked. Reverse-a-fix instructions still point at the per-backup admin page.

### Notes
- These endpoints are gated by Joomla's standard API token auth — anyone with a valid `X-Joomla-Token` and `core.manage` on `com_csintegrity` can write. Auto-backups + path guards + action-log entries are the safety nets. The admin's existing modals (with confirmation checkboxes) are unchanged; this just makes the same operations available headlessly.

## [0.7.2] — 2026-04-24

### Fixed
- Restore-backup confirmation modal was too narrow — long file paths in the prompt body got crammed against the modal's left edge. Added `modal-lg` to the dialog (~800px on medium+ screens) so paths have room.
- Post-restore success message was wrapped in `<code>` tags. The default code styling inside an `alert-success` doesn't flip readably between light and dark mode (dark text on light-green vs light text on dark-green-ish — neither version is great). Dropped the wrap; plain text in the success bar is readable in both modes.

## [0.7.1] — 2026-04-24

### Fixed
- "Restore now…" button on the backup detail view did nothing on click. The button uses Bootstrap 5's `data-bs-toggle="modal"` to open the confirmation modal, but Joomla 5+ only loads the `bootstrap.modal` script asset when explicitly requested, and the Backup view wasn't doing that. Added `useScript('bootstrap.modal')` to both the Backup and Dashboard HtmlViews so the modal opens reliably on either page (the Mark-all-reviewed modal on the dashboard had been working incidentally because Atum was loading the asset for unrelated reasons; making it explicit so we don't depend on that).

## [0.7.0] — 2026-04-24

### Added
- **Backup detail view** at `index.php?option=com_csintegrity&view=backup&id=N`. Shows the backup's metadata (relative path, absolute path, size, sha256, created-at, linked review session, whether the live file at that path currently exists), the full backed-up contents in a resizable code block, and three action cards: restore-via-button, restore-manually-via-FTP, and where-this-backup-lives. Each row in the Backups list now links to this detail page (file path is clickable, plus an explicit "View" button next to "Download").
- **Restore action.** New `BackupsHelper::restore(int $id)` writes the backup contents back to the original file path. Before overwriting, it auto-creates a fresh backup of the live file's current state — so the restore is itself reversible. Path-traversal guard via `realpath()` refuses to write outside `JPATH_ROOT`. Restore is gated by a Bootstrap modal with a confirmation checkbox (same UX as Mark-all-reviewed). Logged to the action log as `backup_restored` with both the source backup id and the auto-created pre-restore backup id, linked to the original review session if any.
- **Manual-restore instructions** card on the backup detail page. For users who prefer FTP / their editor: download the backup, open their file client, replace the file at the destination path shown.
- **Storage explanation** card. Tells the user up-front: backups are in the `#__csintegrity_backups` table as base64-encoded contents, not files on disk; a database backup also captures them; uninstalling the component drops the table.

### Changed
- `dashboard.js` factored out the gated-checkbox-modal pattern into `wireGatedConfirmModal(modalId, checkboxId, btnId)` so the existing Mark-all-reviewed modal and the new Restore modal share one implementation.

## [0.6.11] — 2026-04-24

### Changed
- Apply Fixes prompt rewritten to fix three weaknesses found while testing it: (1) it now explicitly tells Claude to fetch original contents via `GET /overrides/{id}/override-file` before staging a backup — the previous wording said "save a backup of the original file" but didn't say *how* to obtain the original; (2) introduced a "Review session" placeholder that ties backups to the prior scan's session id rather than a new one — now the audit trail can answer "which scan surfaced the issue this backup is for?"; (3) explicit "classify first" step that distinguishes code-fix findings (XSS, missing escape, etc. → diff) from config/licensing findings (Web357-style overrides → ask, don't propose a code change). Final summary step asks the user to confirm before further action and reminds them to dismiss the relevant override-tracker warnings after applying the patched files.

## [0.6.10] — 2026-04-24

### Changed
- "New session" form intro rewritten to explain its actual purpose: it's the manual paste-in for browser-Claude users. The previous text was vague ("Save the report Claude produced…"). Now reads: "Manual paste-in for browser-Claude users. If you ran the scan prompt at claude.ai, copy Claude's reply and paste it here. If you used Claude Code in a terminal, you don't need this form — Claude posts the report back automatically."

## [0.6.9] — 2026-04-24

### Changed
- "Back" button on the session viewer is now context-aware. If you arrived from the Action log, the button reads "Back to action log" and returns there. From Backups → "Back to backups". From the dashboard's session-log card → "Back to dashboard". From the Sessions list (or any other entry point) → "Back to sessions" (the previous default). Implemented via a `&from=<view>` URL param appended to every session link in the admin; missing or unknown values fall through to the sessions list.

## [0.6.8] — 2026-04-24

### Fixed
- Resize handle on the session report block now actually works. v0.6.6 set `min-height: 600px` plus `resize: vertical`, but most browsers only render the resize handle reliably when the element has a concrete `height` and non-visible `overflow`. Switched to `height: 600px` + `overflow: auto` (with `min-height: 200px` so a user can shrink it but not collapse it).

### Changed
- Auto-generated session names now include seconds. Format went from `YYYY-MM-DD-HHMM` to `YYYY-MM-DD-HHMMSS` so two sessions created in the same minute (concurrent API POSTs, rapid manual saves) don't collide on names — and downloaded report filenames stay distinct. The Use-with-Claude prompt template and the new-session form's name-help text both updated to reflect the new format.

## [0.6.7] — 2026-04-24

### Added
- **Fullscreen toggle** on the session report viewer. Button next to the "Report" heading uses the HTML5 Fullscreen API to expand the report `<pre>` to the full viewport. Click again to exit. Auto-hides if the browser doesn't support `document.fullscreenEnabled`. CSS keeps the theme-aware background and adds generous padding when in fullscreen so it isn't a wall of monochrome text.
- **Download report button** on the session viewer. Streams the session's stored markdown as `csintegrity-<session-name>.md` (sanitized filename, fallback to `session-<id>.md` if the name has no safe characters). Implemented as a `session.download` task on `SessionController` — no extra view layer, just headers + echo.

## [0.6.6] — 2026-04-24

### Changed
- "Back to sessions" button switched from `btn-secondary` (grey) to `btn-info` (sky blue with white text). It's a navigation action; it should look like one.
- Session report block is now resizable. Default minimum height of 600px (was content-fitted, often too short for long reports), plus a vertical resize handle in the bottom-right corner so users can drag it taller as needed.
- Sessions and Action log views grew an intro paragraph that explains what each view actually shows. Sessions = read-only Claude reports. Action log = the audit trail of changes (rescans, mark-reviewed, session creation, backup saves). Tim found the two confusing on first look — the wording now distinguishes content from changes.

## [0.6.5] — 2026-04-24

### Fixed
- **Back button on the session viewer actually works now.** v0.6.4 used `Toolbar::linkButton()`, which still rendered the back arrow as a toolbar button that Joomla's submit-handler tried to wire up — silent no-op. Replaced with a plain in-page `<a class="btn btn-secondary">` link at the top of the session and new-session-form templates. Guaranteed to navigate.
- **Session report wraps inside the viewer.** The `.csintegrity-codeblock` styling (with `white-space: pre-wrap; word-break: break-word`) only applied on the dashboard because `dashboard.css` was only loaded by the Dashboard `HtmlView`. Now loaded by the Session and Sessionform views too. Also relaxed the CSS selector — base styling now applies anywhere `.csintegrity-codeblock` is used, with the 320px height cap scoped to dashboard cards only. The session-report block has its own `.csintegrity-report` class that lets the page scroll instead of the block.

## [0.6.4] — 2026-04-24

### Fixed
- "Back" toolbar button on session view (and "Cancel" on the new-session form) now actually navigates. Both were using `ToolbarHelper::cancel()` which submits an `adminForm` — those pages don't have one, so the button silently did nothing. Replaced with `Toolbar::linkButton()` so they're real navigation links.

### Changed
- Scan prompt rewritten to produce a client-facing report. Old prompt had Claude classify by pattern names (`escape-removed`, `logic-diverged-template-theming`) and produced developer-flavored output. New prompt explicitly tells Claude the audience is non-technical, asks for the report in this order — headline answer ("did anything bad happen?"), what-to-do-today action list, what-was-checked one-liner, findings table with 🔴/🟡/⚪ severity icons and plain-language "what it does" + "recommended action" columns, then technical detail at the bottom for developers. Voice guidance inlined: contractions OK, no boilerplate, ball-in-their-court close. Tim should now be able to forward the session report to a site owner directly without a translation pass.

## [0.6.3] — 2026-04-24

### Changed
- "Copy prompt" button renamed to "Copy scan prompt" for symmetry with "Copy fix prompt" — clearer pairing of the two prompts.

## [0.6.2] — 2026-04-24

### Fixed
- "Copy prompt" button now matches "Copy fix prompt" — both are `btn btn-primary` (blue with white text). Tim preferred the blue style; the previous `btn-info` was rendering as a near-black button with white text in Atum's dark mode.
- "View all sessions" button switched from `btn-outline-info` to `btn btn-secondary`. The outline variant rendered as a black box with thin blue edge and blue text in dark mode — unreadable. Recorded as a global feedback rule: avoid `btn-outline-*` classes in any Joomla admin UI; use solid `btn-*` variants instead.

## [0.6.1] — 2026-04-24

### Fixed
- Defensive pass on the v0.6.0 install that Tim hit "Unexpected token '<'... is not valid JSON" on. Removed three plausible variables: (1) submenu strings now live in `com_csintegrity.sys.ini` so Joomla's installer/menu rendering can resolve them; (2) `script.php` no longer calls `ActionLogHelper::log` during postflight — autoloading another component class from inside install was the most plausible failure point; (3) em-dash characters in SQL comments replaced with plain `--` to avoid any non-ASCII byte parsing edge case in Joomla's SQL splitter.

## [0.6.0] — 2026-04-24

### Added
- **Session log.** New table `#__csintegrity_sessions` and an admin "Sessions" view (Components → CS Template Integrity → Sessions submenu). Reports from Claude are stored as sessions named `YYYY-MM-DD-HHMM` by default. Two ways to add a session: paste-in via a form (claude.ai users) or POST to `/api/index.php/v1/csintegrity/sessions` (Claude Code / agentic users — the dashboard prompt template now tells Claude to do this automatically). Each session can be viewed individually with its full report markdown and the action-log entries that ran while it was active. Delete works via the standard admin checkbox toolbar.
- **Action log.** New table `#__csintegrity_actions` and an admin "Action log" view. Every notable event is recorded automatically: install, update, rescan, mark-reviewed, session created/deleted, backup created. `Cybersalt\Component\Csintegrity\Administrator\Helper\ActionLogHelper::log($action, $details, $sessionId)` is the single entry point; calls are wired into `RescanHelper`, `MarkReviewedHelper`, `SessionsHelper`, `BackupsHelper`, and `script.php`'s postflight. Logging failures are swallowed — the audit log can never crash the parent operation.
- **File backups.** New table `#__csintegrity_backups` and admin "File backups" view. Claude POSTs original file contents to `/api/index.php/v1/csintegrity/backups` before proposing a fix; the original is stored as base64 in the DB along with sha256 and size. Each backup is downloadable from the admin list. Restore-from-backup is intentionally deferred — destructive enough to deserve its own design pass.
- **Apply Fixes prompt card** on the dashboard. Second copy-paste prompt that asks Claude to back up each affected file (POST to `/backups`) before proposing diffs. Read-only by default; Claude proposes, the admin applies. Pairs with the existing Use-with-Claude card.
- **Session log preview card** on the dashboard. Lists the five most recent sessions with quick links, plus "New session" and "View all sessions" buttons.
- Component manifest grew `<install>`, `<uninstall>`, and `<update>` SQL blocks pointing at the new schema files in `admin/sql/`. Submenu entries for Dashboard / Sessions / Action log / File backups added under the Components menu.

### Changed
- Use-with-Claude prompt extended with a step 6 telling Claude to POST its report back to `/v1/csintegrity/sessions` so it's preserved in the audit trail.

## [0.5.2] — 2026-04-24

### Fixed
- All dashboard buttons are now the same default size (Bootstrap default). Previously "Copy prompt", "Open Site Templates", and "Mark all as reviewed" were `btn-sm` while "Reset all overrides for review" was the default — sizes were inconsistent.
- "Copy prompt" was `btn-outline-info` which renders as blue text on a transparent background; in Atum's dark mode that gave blue-on-near-black with poor contrast. Switched to `btn-info` (solid sky-blue with white text), which is readable in both light and dark mode. The transient "Copied!" success state still flips to `btn-success` then back.
- "Open Site Templates" similarly switched from `btn-outline-secondary` to solid `btn-secondary` for the same readability reason.

## [0.5.1] — 2026-04-24

### Changed
- Components-menu label is now "CS Template Integrity" (was "Cybersalt Template Integrity"). The full name still appears on the dashboard page title.
- "Open Site Templates" link in the After-review card now points at `view=templates&client_id=0` rather than `view=styles&client_id=0` — the templates list is where the per-template "Changes found" override review actually lives.
- Repositioned the rescan card. Previously framed as a testing utility; reframed as the user-facing remedy for "I (or someone before me) bulk-dismissed overrides without actually checking them." New title "Reset overrides for review", new button "Reset all overrides for review", description rewritten accordingly.

## [0.5.0] — 2026-04-24

### Added
- Dashboard "After review" card that closes the workflow loop. Two paths once Claude's review is acted on:
  1. **Recommended** — link to Joomla's own Site Templates list (`com_templates&view=styles&client_id=0`) for per-template, per-row dismissal with an audit trail.
  2. **Bulk** — "Mark all as reviewed" button that opens a Bootstrap modal. The modal includes a checkbox the user must tick ("I confirm I have reviewed every flagged override and I accept responsibility for any unaddressed issues") before the confirm button enables. On submit, every row in `#__template_overrides` is cleared in one shot. Inverse of the existing rebuild button.
- `Cybersalt\Component\Csintegrity\Administrator\Helper\MarkReviewedHelper::clearAllOverrides()` — implementation; returns count of rows cleared.
- `DisplayController::markReviewed()` task with CSRF guard, invoked by the modal's form.

## [0.4.1] — 2026-04-24

### Changed
- Dashboard layout simplified: removed the standalone "Endpoint" and "Smoke test" cards that duplicated information now in the "Use with Claude" prompt. Endpoint URL is preserved as a small reference in the About sidebar. API status is now a one-line alert at the top of the page instead of a full card.

### Fixed
- Dark-mode compatibility. The `<pre><code>` prompt block previously inherited a hardcoded white background, making it unreadable when Joomla's Atum admin template was in dark mode. The block now uses theme-aware Bootstrap CSS variables (`--bs-tertiary-bg`, `--bs-body-color`, `--bs-border-color`) via a small `media/css/dashboard.css` so it adapts to whichever theme is active. Inline copy-button JavaScript also moved out to `media/js/dashboard.js`.

## [0.4.0] — 2026-04-24

### Added
- Dashboard "Use with Claude" card: 3-step instructions for getting a security review of the site's overrides via Claude (claude.ai or Claude Code), plus a copy-to-clipboard prompt that's pre-filled with this site's URL and API base. The user just pastes their Joomla API token and sends.
- First-pass security review of cybersalt.org's 16 flagged overrides via the v0.3 endpoints. One MEDIUM XSS finding (`default_links.php` missing `$this->escape()` on article title), 14 theming-drift items, 1 cosmetic include-path bug. Same XSS pattern found earlier on Rocky Wall's `fairviewterracehoa.com` — the cross-client hypothesis is now supported by two independent Cybersalt-managed sites.

## [0.3.1] — 2026-04-24

### Fixed
- `filter[template]`, `filter[client_id]`, `filter[state]`, and `filter[extension_id]` on the list endpoint now actually filter results. v0.2 fix tried to do it via the model's `populateState()`, which is the wrong layer for Joomla's API — the JSON:API dispatcher reads filters in the controller's `displayList()` and pushes them into `modelState`. Confirmed against core `com_content`'s `ArticlesController` pattern. Tested live on cybersalt.org with 16 real overrides on the `cybersalt` template.

## [0.3.0] — 2026-04-24

### Added
- Dashboard "Rebuild override tracker" button (admin → Components → Cybersalt Template Integrity). Walks every enabled template's `html/` folder and inserts missing `#__template_overrides` rows so a previously-dismissed site can be reset to a usable test corpus. Joomla's own "Dismiss All" deletes rows; this is the inverse.
- `Cybersalt\Component\Csintegrity\Administrator\Helper\RescanHelper` — performs the walk + insert. Skips rows that already exist (matched on template + hash_id + client_id). New rows are written with `action = 'Joomla Update'` and `state = 0` to match what Joomla's own update flow produces.

## [0.2.0] — 2026-04-24

### Added
- `GET /api/index.php/v1/csintegrity/overrides/:id/override-file` — returns the contents, sha256 hash, size, and mtime of the override file on disk for the given override row.
- `GET /api/index.php/v1/csintegrity/overrides/:id/core-file` — same response shape, but for the core source file the override is shadowing.
- `Cybersalt\Component\Csintegrity\Administrator\Helper\PathResolver` — utility that decodes `hash_id` and resolves both override and core paths, with mappings for `layouts/`, `com_*`, `mod_*`, and `plg_<group>_<element>/` first segments.

### Fixed
- `OverridesModel::populateState()` now reads `filter[template]`, `filter[client_id]`, `filter[state]`, and `filter[extension_id]` from the request, so JSON:API filter params on the list endpoint actually filter results. Previously they were silently ignored.

### Documentation
- `CLAUDE.md` and `docs/MVP-v0.1-overrides-api.md` updated with the confirmed `#__template_overrides` schema and the `hash_id`-is-base64-path discovery from the live test on j53.basicjoomla.com.

## [Unreleased]

### Added
- Initial repo scaffold and planning documentation.
- `CLAUDE.md` at repo root — self-contained briefing for Claude Code / VS Code handoff.
- `docs/MVP-v0.1-overrides-api.md` — three-endpoint spec for the first read-only Web Services API exposing `#__template_overrides` data.
- `docs/CLASSIFIER-PATTERNS.md` — v1 classifier ruleset (auto-pass / flag-for-review / alert) derived from a real 11-override inspection.
- `docs/FIELDWORK-2026-04-24.md` — inspection notes from fairviewterracehoa.com including an open XSS finding in `default_links.php` that becomes the MVP's end-to-end test case.
- README links to the new docs.
- Minimal `com_csintegrity` v0.1.0 component — manifest, DI provider, component class, language strings, and the first Web Services endpoint (`GET /api/index.php/v1/csintegrity/overrides`) returning `#__template_overrides` rows via `ApiController` + `JsonApiView` + `ListModel`.
- `com_csintegrity` post-install Bootstrap-card link and admin dashboard view, per the Cybersalt extension checklist.
- `.joomla-brain` submodule pointing at cybersalt/Joomla-Brain.
- `plg_webservices_csintegrity` v0.1.0 — system plugin that registers the component's Web Services routes on `onBeforeApiRoute`. Without this plugin installed **and enabled**, the component's API folder is dead code (Joomla returns `404 Resource not found` for any `/v1/csintegrity/...` URL). Every core API-enabled component has an equivalent `plg_webservices_*`; we missed this on the first pass.

### Notes
- Confirmed Joomla Web Services API token auth requires `X-Joomla-Token: <token>`, not `Authorization: Bearer <token>`. Recorded in `CLAUDE.md`.
- Column list in `OverridesModel` / `JsonapiView` is provisional — sourced from Joomla 4.0's initial migration. Confirm against a live Joomla 5/6 install on first deploy.
