# 0001. Ship read-only observe mode first, in phases that each release

Status: Accepted (2026-11-14)

## Context

A scheduler plugin could grow into job submission, node control, and reporting. Each of those needs a write path into the scheduler, a larger security review, and overlaps with tools sites already run. The gap sites report is narrower: queue and node graphs, with the reason jobs are pending, next to the Cacti device graphs they already use.

## Decision

Release in phases that each ship on their own:

1. Cluster graphs: queue depth, pending and running jobs, node states, partition utilization, and pending reasons for Slurm, with Thold alerting on the resulting data sources.
2. Nodes and maintenance: map cluster nodes to existing Cacti devices, graph node state, and place drained nodes and maintenance reservations into Maint.
3. Accounting: fair-share usage, bounded accounting statistics, and remote data collectors.

PBS Pro, OpenPBS, and LSF backends are research behind the backend interface in ADR 0004. The plugin never submits, cancels, holds, drains, or resumes anything in the scheduler.

## Consequences

- Phase 1 needs only read access to the scheduler.
- No phase depends on a later one, so a stalled later phase does not strand earlier work.
- Operators who want Cacti to act on the scheduler get nothing from this plugin. That is intentional.

## Alternatives rejected

- **Single full-scope release.** Delays all value and carries the largest security surface.
- **Node control from Cacti.** A Cacti compromise would become a cluster compromise.
