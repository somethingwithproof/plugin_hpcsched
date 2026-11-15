# 0004. Put scheduler access behind a backend interface, with Slurm first

Status: Accepted (2026-11-14)

## Context

Sites run Slurm, PBS Pro, OpenPBS, and LSF. The Cacti organization hosts LSF-related plugins (plugin_lsflog, plugin_cjobs). Their overlap with this plugin was unconfirmed when this record was written; milestone 1 read them (2026-11-15) and found no overlap in function: both attach to LSF RTM's `grid` plugin and tables, and plugin_cjobs has a write path to the scheduler ([prior-art.md](../research/prior-art.md)). Writing the first backend against Slurm output would bake Slurm's vocabulary into storage, templates, and pages.

## Decision

The plugin defines a normalized snapshot: queues or partitions, jobs aggregated by state and pending reason family, nodes with state and reason, reservations, and optional share usage. A backend turns one scheduler's output into that snapshot. Slurm is the only backend until phase 1 ships. Within the Slurm backend, a transport interface separates how data is fetched (client commands or `slurmrestd`) from how it is parsed.

## Consequences

- PBS and LSF work adds a backend and its templates without changing the storage model.
- The normalized model must not expose scheduler-specific names that other schedulers lack. Raw reason and state strings are kept alongside the normalized values.
- The interface is justified only by the planned backends. It is not extended speculatively.

## Alternatives rejected

- **Slurm-specific tables and templates.** Forces a rewrite for every later scheduler.
- **Implementing all backends at once.** Verification effort for PBS and LSF behavior is not yet done.
