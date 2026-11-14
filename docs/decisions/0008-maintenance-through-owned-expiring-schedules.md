# 0008. Create maintenance through plugin-owned, expiring Maint schedules

Status: Proposed, pending maintainer decision (2026-11-14)

## Context

Maint stores schedules in `plugin_maint_schedules` (`enabled`, `name`, `mtype`, `stime`, `etime`, `minterval`) and device assignments in `plugin_maint_hosts` (`type`, `host`, `schedule`); type 1 is a Cacti device (plugin_maint `develop`, setup and database files). Maint registers an `is_device_in_maintenance` hook, but Thold on `develop` does not call that hook. It calls `plugin_maint_check_cacti_host()` directly when Maint is enabled, so a second plugin cannot join suppression by registering its own hook callback. Maint has no function for creating a schedule; its own page code writes the rows.

## Decision

Propose that the plugin create ordinary one-time Maint schedules (`mtype` 1) for devices mapped to drained or down nodes and for nodes in reservations flagged `MAINT`. Each schedule is named with a fixed prefix and tracked in a plugin table so the plugin changes only its own rows. A schedule for a node state with no known end is written with a short `etime` that the reader extends each cycle, so a stopped reader lets suppression lapse instead of silencing alerts indefinitely. The mechanism, direct row writes or a Maint-provided function, is decided with Maint's maintainers (issue tracked in M5).

## Consequences

- Thold suppression works unchanged.
- Direct row writes couple the plugin to Maint's schema, which can change between Maint releases. A Maint-provided function would remove that coupling.
- Fail-safe expiry means a brief reader outage can end suppression early and let alerts fire.

## Alternatives rejected

- **Own hook callback.** Thold does not consult it.
- **Open-ended schedules.** A stopped reader would leave alerts silenced.
- **Suppress inside the plugin's own data sources only.** Other thresholds on the same device would still alert.
