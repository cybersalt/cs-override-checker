# Classifier patterns — v1 ruleset

The classifier is the value add of `cs-override-checker`. It takes a flagged override (path + override contents + core contents + diff) and emits a verdict: **auto-pass** / **flag for review** / **alert**.

v1 ruleset is data-driven — a YAML or PHP array consumed by `lib_csintegrity\Classifier`. New patterns can be added without code changes.

The ruleset below was derived from inspecting 11 flagged overrides on a real site (fairviewterracehoa.com) on 2026-04-24. See [FIELDWORK-2026-04-24.md](FIELDWORK-2026-04-24.md) for the sample.

---

## Severity buckets

### Auto-pass — no alert, no entry in summary email

These are cosmetic or upstream-refinement diffs. They tell a developer "upstream moved a little; your override is safe to ignore." The classifier should recognize and silence them.

| Pattern id | What it detects |
|---|---|
| `copyright-year-only` | Only the copyright header year changed between override and core. |
| `phpdoc-type-hint-added` | Upstream added `/** @var ClassName $var */` above a variable, no logic delta. |
| `api-signature-refinement` | Upstream refined a helper call signature — e.g., `HTMLHelper::_('string.truncate', $s, 300, false)` → `HTMLHelper::_('string.truncate', $s, 300)` (default param dropped). No behavioral change. |
| `redundant-var-removed` | Upstream removed an unused local variable. |

### Flag for review — low-to-medium severity, summarize in report, no email alert

These aren't security events, but they tell the site owner something is drifting and the override will probably need refreshing eventually. They go into the findings dashboard and the weekly summary email, not the instant-alert email.

| Pattern id | What it detects |
|---|---|
| `logic-diverged-template-theming` | Override adds framework includes (`settings_*.php`), defines TCK / Helix / Gantry constants, or adds Schema.org markup / custom CSS classes. Legitimate theming — the flag exists to remind the developer that upstream has moved on, so future core-security patches to this layout won't take effect until the override is refreshed. |
| `accessibility-regression` | Override removes `<span class="visually-hidden">…</span>`, `aria-*` attributes, or other accessibility affordances that upstream added. Observed live on Rocky's site in `plg_content_pagenavigation/default.php`. |
| `modernization-skew` | Upstream moved to newer markup (e.g., `<span class="pagination">` → `<nav>`, plain HTML → Bootstrap 5 components); override is still on the older structure. Not security, but a framework-drift signal. |

### Alert — genuine security event, email immediately

These trigger the instant-alert email. Each has a severity tag that shows up in the email subject line and the findings dashboard.

| Pattern id | Severity | What it detects |
|---|---|---|
| `escape-removed` | **MEDIUM** | Override strips `$this->escape()`, `htmlspecialchars()`, `HTMLHelper::_('escape', ...)`, or equivalent from the output of a user-supplied or DB-sourced field. Potential stored XSS. **Confirmed live on Rocky's site** in `html/com_content/featured/default_links.php` — see fieldwork doc. |
| `csrf-token-removed` | **HIGH** | Override drops the `<?php echo HTMLHelper::_('form.token'); ?>` (or `JHtml::_('form.token')`) call from a form. |
| `session-check-removed` | **HIGH** | Override removes or bypasses a `Session::checkToken()` call. |
| `raw-output-of-db-field` | **MEDIUM** | Any unescaped `echo $item->*`, `echo $row->*`, or similar from a model-populated variable. Superset of `escape-removed`; catches cases where escape was never there to begin with. |

---

## Rule data shape (sketch)

```yaml
# packages/lib_csintegrity/data/classifier-rules.yml
version: 1
rules:
  - id: escape-removed
    severity: alert
    level: medium
    match:
      mode: diff
      removed_tokens:
        - '$this->escape('
        - 'htmlspecialchars('
      near_tokens:
        - '$item->'
        - '$row->'
    message: |
      Override strips output-escaping on a model-populated field. Potential stored XSS.

  - id: copyright-year-only
    severity: auto-pass
    match:
      mode: diff
      diff_only_affects_lines_matching: '^\s*\*\s*(@copyright|Copyright\s*\(c\))'
    message: |
      Only the copyright header year changed.
```

Exact schema to be nailed down in v0.2. The intent above is that each rule has a `severity` bucket, a `match` block describing what to look for in the diff, and a human-readable `message` used in the findings dashboard and email.

---

## False-positive discipline

The whole pitch of this product is *low noise*. Two rules:

1. **A rule is only worth shipping if it has a test case from a real site.** The 10 rules above each come from a flagged override on fairviewterracehoa.com or a documented Joomla security advisory. Don't add speculative rules.
2. **Every rule must be tested in both directions.** If `escape-removed` is the pattern, we need a diff where escape was present in core and absent in override (true positive) *and* a diff where both sides have escape (true negative) *and* a diff where the override legitimately shadows a different field with its own escape (another true negative).

When the scanner produces its first 100 findings on real sites, each bucket gets a manual review. Any rule producing more than ~5% false-positive rate gets tightened or dropped.
