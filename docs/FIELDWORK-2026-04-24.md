# Fieldwork — 2026-04-24 — Rocky Wall's site

First live inspection of a real flagged-override set. Subject site: fairviewterracehoa.com (Joomla 6.1, TCCK-built `fairviewtha_2025` template). Trigger: Rocky emailed Tim 2026-04-23 worried that a Joomla auto-update had overwritten his custom template.

This doc captures what was found and what it means for the extension's design.

---

## Setup

- Site runs Joomla 6.1 (recently upgraded from J4).
- Template: `fairviewtha_2025`, two registered styles (`id=14`). Two extension records back the style — `10263` and `10361` — with slightly different copyright-year variants but otherwise identical file trees.
- Joomla's admin "Styles" page showed "6 Changes found" on one extension record and "5 Changes found" on the other — 11 flagged overrides total.
- Inspection method: Tim ran a browser-automation Claude against the admin UI, clicked through each "Changes found" link, and saved the diffs. The vault's project note (section 14) summarizes what came back.

Joomla's REST API does **not** expose this data. Everything visible in the admin UI that powers the "X Changes found" flag lives in the `#__template_overrides` table. The entire motivation for `com_csintegrity`'s first three endpoints is to surface that table to external tools.

---

## Confirmed hook point — `#__template_overrides`

Joomla's own admin UI renders the override-diff from `#__template_overrides`. The extension's scanner reads that table directly, pairs `override_path` with `core_path`, runs a PHP diff, and classifies by pattern. **No need to reinvent the diff** — just classify Joomla's existing output.

One consequence: the extension is meaningless on a Joomla install that doesn't keep `#__template_overrides` populated. Confirm during install that the override tracker is healthy.

---

## Classifier patterns (derived from the 11 findings)

Moved to its own doc: [CLASSIFIER-PATTERNS.md](CLASSIFIER-PATTERNS.md). That's the canonical location. This doc keeps the *source* findings that drove each pattern.

| Override file | Pattern matched | Severity |
|---|---|---|
| `html/com_content/featured/default_links.php` (extension 10263) | `escape-removed` + `raw-output-of-db-field` | **alert — MEDIUM (XSS)** |
| `html/com_content/featured/default_links.php` (extension 10361) | same | **alert — MEDIUM (XSS)** |
| `html/plg_content_pagenavigation/default.php` | `accessibility-regression` | flag for review |
| `html/layouts/joomla/pagination/*` (several) | `modernization-skew` | flag for review |
| `index.php`, `component.php`, `error.php` | `logic-diverged-template-theming` | flag for review |
| Remaining overrides | `copyright-year-only` / `phpdoc-type-hint-added` / `api-signature-refinement` | auto-pass |

Exact per-file breakdown is in Tim's vault project note, section 14.2. Migrate when we encode the rules.

---

## Concrete open finding — XSS in `default_links.php`

**File:** `templates/fairviewtha_2025/html/com_content/featured/default_links.php`

**Issue:** The override outputs `$item->title` raw — no `$this->escape()` wrap, no `htmlspecialchars()`. Present on **both** extension records (10263 and 10361). `$item->title` is user-supplied content sourced from `#__content`, reachable by anyone with article-edit permission — classic stored XSS vector.

**Fix:** Restore `$this->escape($item->title)` on every output of the field.

**Scope concern — potentially cross-client.** The override uses generalized `TCK_*` constants, which strongly suggests it's Tim's reusable base template rather than a one-off customization for Fairview. **Every other Cybersalt-maintained Joomla site running a fairviewtha-family TCK template likely has the same XSS.**

**Action items:**
- Fix on Rocky's site as part of the reply-to-Rocky workflow.
- Log a separate ClickUp task: audit all Cybersalt-maintained Joomla sites for `default_links.php` overrides containing unescaped `$item->title`. Depends on client-site inventory.
- **This finding is the primary end-to-end test case for `com_csintegrity` v0.1.** When the MVP is installed on Rocky's site, endpoint 1 must list this override; endpoints 2 and 3 must return the override contents (no escape) and core contents (with escape), and a manual diff must show the dropped `$this->escape()` call exactly where the classifier's `escape-removed` rule expects to find it. If any of that fails, v0.1 isn't ready.

---

## Report-tone calibration for the auto-generated email

The browser-Claude output was client-ready in *structure* but too formal for Cybersalt's voice — bullet-heavy, passive ("We have completed a review…"), no contractions, no explanatory warmth. For the extension's auto-generated client email:

- **Voice source of truth:** `~/.claude/skills/humanizer/voice-tim.md` — contractions, asterisk emphasis on key words, patient/explanatory tone, ball-in-their-court close.
- **Signature source of truth:** `~/.claude/skills/humanizer/signature-tim.md` — long signature on longer emails.
- **Borrow browser-Claude's structure, not its prose.** Findings grouped by file, severity flag, plain-language "what this file does" + "why it was flagged." Feed the structure into Tim's voice.

**Implementation tier split:**
- Free tier → static string-template library with a handful of variants per severity category. Good enough for the vast majority of findings because the patterns are narrow.
- Paid tier → route the report through the admin's configured Anthropic API key with a prompt that references the voice/signature files. Pays for itself on nuanced or client-sensitive findings.

---

## Sprint impact

This fieldwork feeds directly into the existing sprint outline in [README.md](../README.md):

- **Sprint 4 (TCK `.tck3z` parser):** parser must handle **template variants** — one template source, two registered styles (10263 and 10361), same underlying files with minor copyright-year variants. Don't naively treat the two extension records as "two unrelated templates."
- **Sprint 5 (unclassified detection + email):** section above is the seed classifier ruleset. Encode in `lib_csintegrity`.
- **New Sprint 5.5 (add to plan):** humanizer pass on generated report text. Free tier → static templates; paid tier → BYO-Anthropic-key route.

---

## Reference

- Vault project note: `01.projects/cs-override-checker.md` section 14 has the longer-form write-up with Tim's inline commentary. This doc is a condensed, source-of-truth-for-implementation version.
- Rocky's client note (credentials, relationship context): `03.team/clients/Rocky-Wall-Fairview.md` in Tim's vault.
- Session log for the 2026-04-24 REST probe and browser-Claude inspection: recorded in the daily note.
