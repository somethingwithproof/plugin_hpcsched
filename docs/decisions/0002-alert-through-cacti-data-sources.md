# 0002. Alert through polled Cacti data sources, not a plugin alert engine

Status: Accepted (2026-11-14)

## Context

Operators want alerts on pending queue growth, node loss, and stale collection through the same path as their other Cacti alerts, with Maint suppression. Thold receives values through Cacti's `poller_output` hook, so it only sees data sources that the Cacti poller polls.

## Decision

Every value an operator may alert on is a polled Cacti data source: aggregate job counts, node-state counts, a per-node state code for mapped devices, and a numeric `age` for each cluster that stays numeric when the scheduler is unreachable. Thold thresholds and Maint schedules apply to them with no change to either plugin. Values the plugin cannot vouch for are `U`, never `0`.

## Consequences

- One notification path for SNMP and scheduler data.
- Alerts see sampled values at Cacti's poll interval, not scheduler events.
- Cacti's poll interval and the reader's collection interval are independent. A snapshot can be reported twice or skipped; Thold durations should be at least one collection interval.

## Alternatives rejected

- **Plugin-owned incident engine.** Duplicates Thold and Maint and adds a third notification path.
- **Scheduler-side alerting hooks.** Needs write access to scheduler configuration.
