# DBFlow Core 1.0.0

First stable release of the DBFlow workflow runtime for Laravel.

## Highlights

- Public runtime API frozen since `1.0.0-rc.1` — no breaking changes from the RC tag
- Coordinated stable release with `dbflowlabs/filament:1.0.0` and `dbflowlabs/filament-pro:1.0.0`
- Additive since RC: `ActionFailed` event, optional `fail_on_exception` on action nodes, sequential approval edge-case fixes

## Upgrade

```bash
composer require dbflowlabs/core:^1.0
```

From `1.0.0-rc.1`: no code changes required for hosts on the documented public contract.

See [UPGRADE-1.0.md](UPGRADE-1.0.md) for the full migration path from `0.x`.

## Ecosystem pairing

| Package | Stable tag | Constraint |
| --- | --- | --- |
| `dbflowlabs/core` | `1.0.0` | — |
| `dbflowlabs/filament` | `1.0.0` | `^1.0` on core |
| `dbflowlabs/filament-pro` | `1.0.0` | `^1.0` on core + filament |

## Compare

https://github.com/dbflow-labs/dbflow-core/compare/1.0.0-rc.1...1.0.0
