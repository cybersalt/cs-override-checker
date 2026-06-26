# Logo — cs-override-checker

This extension has a brand logo. Two variants are checked into `media/`:

- **`media/logo.svg`** — duotone (cobalt #0102E1 template frame, orange #FE9904 shield-check badge in the top-right corner = "integrity verified")
- **`media/logo-mono.svg`** — single-cobalt fallback for places where duotone won't reproduce

Both are SVG, scale to any size, transparent background.

## What this is

Page-template icon (Tabler Icons `template`, MIT licensed) — header bar across the top, main content box on the left, three sidebar lines on the right — composited with a small orange shield-check badge in the top-right corner. The template body reads "Joomla template overrides"; the shield-check reads "integrity verified / not tampered." The icon as a whole says "your template overrides, monitored for tampering" — exactly what the extension does.

The corner-badge construction (small orange semantic accent in the top-right with a white knockout for clarity) is shared with cs-articles-module-maxxed (`bolt` badge there); it's an established cs-* family-language move for "the twist on the base icon."

This is the canonical logo for the cs-override-checker extension. Use it on:

- The Joomla Extension Directory listing (when submitted)
- cybersalt.com sales / product page
- This repo's GitHub social preview image (optional — would need a 1280×640 PNG export)
- This README — at the top, to give the repo a brand face

## Source of truth

The canonical version lives in Tim's Obsidian vault at:

```
04.knowledge/cybersalt-com/branding/extension-logos/cs-override-checker.svg
04.knowledge/cybersalt-com/branding/extension-logos/cs-override-checker-mono.svg
```

If you ever need to update the logo, update it **in the vault first**, then re-copy to this repo's `media/` folder. The vault has the full family-wide convention, brand-color reference, render-check against multiple backgrounds, and the rationale for the design choices — see the README at:

```
04.knowledge/cybersalt-com/branding/extension-logos/README.md
```

## Brand colors

- Cybersalt cobalt: `#0102E1`
- Cybersalt orange: `#FE9904`

## Repo layout heads-up

This repo packages **multiple sub-extensions** under one `pkg_csoverridechecker` installer:

- `packages/com_csoverridechecker/` — the user-facing component (admin UI lives here)
- `packages/lib_csoverridechecker/` — shared library code
- `packages/plg_system_csoverridechecker/` — system plugin (the actual integrity-check trigger)
- `packages/plg_webservices_csoverridechecker/` — REST API endpoints
- `packages/pkg_csoverridechecker/` — the installer manifest that wires all of the above together

Each sub-extension has its own `media/` subfolder where applicable. The repo-root `media/` (where `logo.svg` and `logo-mono.svg` live) is the **brand-asset folder** — used for README, JED listing, GitHub social preview, sales page. It is *not* automatically installed by any of the sub-manifests.

## TODO — wiring it into the package

These are the integration points to consider next time you're editing this extension. None are blocking for the logo simply existing in `media/`:

- [ ] **Component manifest** (`packages/com_csoverridechecker/csoverridechecker.xml`): if you want the logo to install with the component, copy `logo.svg` into `packages/com_csoverridechecker/media/` and add a `<filename>logo.svg</filename>` entry to the existing `<media>` block. After install it'll be at `JPATH_ROOT/media/com_csoverridechecker/logo.svg` and you can reference it from the admin dashboard view.
- [ ] **README.md**: add the logo at the top:
  ```markdown
  <img src="media/logo.svg" width="128" alt="cs-override-checker logo">
  ```
- [ ] **JED listing**: upload `logo.svg` as the extension icon when submitting / updating the JED listing.
- [ ] **Component admin sidebar / dashboard view**: cs-override-checker has an admin UI (the override-scan results page) — consider rendering the logo at the top of the dashboard for brand consistency. If the logo lives at `JPATH_ROOT/media/com_csoverridechecker/logo.svg`, reference it via `Uri::root() . 'media/com_csoverridechecker/logo.svg'`.

Logo added: 2026-05-07.
