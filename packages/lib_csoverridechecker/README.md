# lib_csoverridechecker

Shared library. Placeholder — scaffold to follow in Phase 0.

Intended consumers: `com_csoverridechecker`, `plg_system_csoverridechecker`, the `integrity:scan` CLI command.

Planned responsibilities:

- Baseline snapshot + hash store
- File classification (core / framework / override / unknown)
- Scanner and drift detection
- Anthropic API client with hard cost caps
- Finding / verdict data model

Planned structure:

```
lib_csoverridechecker/
├── lib_csoverridechecker.xml               # library manifest
├── services/provider.php
└── src/
    ├── Baseline/
    ├── Classifier/
    ├── Scanner/
    ├── Claude/
    └── Finding/
```
