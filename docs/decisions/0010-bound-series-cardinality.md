# 0010. Bound the number of RRD series the plugin creates

Status: Accepted (2026-11-14)

## Context

Slurm's pending-reason list is long and includes wildcard families such as `AssocGrp*Limit` and `QOSGrp*Limit` (job reason codes page). Accounts, users, and nodes are unbounded. The Slurm metrics guide makes the same point for Prometheus: user and account metrics create one series per entity, which can dramatically increase stored data, and it advises against storing them. In Cacti every series is an RRD file, a data source row, and graph items.

## Decision

- Pending reasons are grouped into a fixed, documented set of families, with the raw reason kept in the snapshot and shown on plugin pages. An `Other` family catches unknown reasons.
- Partitions are indexes of one data query per cluster.
- Account and fair-share series are off by default and limited to an administrator-chosen list or a top-N.
- Per-node series exist only for nodes mapped to devices.
- User-level series are not created.

## Consequences

- Graph and RRD counts stay predictable and can be stated before an import.
- Rare reasons are visible on plugin pages but not graphed separately.

## Alternatives rejected

- **One series per raw reason.** Unbounded and changes between Slurm releases.
- **Per-user series.** Unbounded and privacy sensitive.
