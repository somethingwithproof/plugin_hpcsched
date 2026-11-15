# Decision records

Each record captures one architecture decision: its context, the decision, its consequences, and the alternatives rejected. Records are numbered and never renumbered. A reversed decision gets a new record that supersedes the old one.

| Record | Decision | Status |
|---|---|---|
| [0001](0001-read-only-observe-mode-first.md) | Ship read-only observe mode first, in phases that each release | Accepted |
| [0002](0002-alert-through-cacti-data-sources.md) | Alert through polled Cacti data sources, not a plugin alert engine | Accepted |
| [0003](0003-collect-one-cached-snapshot-per-cluster.md) | Collect one cached snapshot per cluster per cycle | Accepted |
| [0004](0004-scheduler-backend-interface.md) | Put scheduler access behind a backend interface, with Slurm first | Accepted |
| [0005](0005-map-nodes-to-existing-devices.md) | Map cluster nodes to existing Cacti devices, never create them implicitly | Accepted |
| [0006](0006-use-cacti-remote-collectors.md) | Reach clusters through Cacti pollers and remote data collectors | Accepted |
| [0007](0007-php-82-floor-with-80-parse-guard.md) | Require PHP 8.2, keep entry points parseable on PHP 8.0 | Accepted |
| [0008](0008-maintenance-through-owned-expiring-schedules.md) | Create maintenance through plugin-owned, expiring Maint schedules | Proposed |
| [0009](0009-credentials-by-file-or-munge-never-minted.md) | Read scheduler credentials from the host, never store or mint them | Accepted |
| [0010](0010-bound-series-cardinality.md) | Bound the number of RRD series the plugin creates | Accepted |
| [0011](0011-pending-reason-families-and-counting.md) | Group pending reasons into eleven fixed families and fix the counting rules | Accepted |

Open decisions are tracked as issues labelled `type: decision`.
