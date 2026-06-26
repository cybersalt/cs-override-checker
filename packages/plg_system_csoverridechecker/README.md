# plg_system_csoverridechecker

System plugin. Placeholder — scaffold to follow in Phase 0.

Planned responsibilities:

- Installer hooks (fire baseline snapshot on install, migrate on update)
- Scheduler integration for periodic scans
- Admin dashboard widget (quick status + latest findings count)
- Email alert dispatcher

Planned structure (Joomla 5 namespaced plugin):

```
plg_system_csoverridechecker/
├── csoverridechecker.xml                   # plugin manifest
├── services/provider.php
├── src/Extension/CsIntegrity.php
└── language/en-GB/
```
