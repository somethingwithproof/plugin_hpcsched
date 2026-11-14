# 0003. Collect one cached snapshot per cluster per cycle, not one scheduler call per data source

Status: Accepted (2026-11-14)

## Context

The Slurm man pages for `sinfo`, `sacct`, and `sshare` state that each call is an RPC to `slurmctld` or `slurmdbd`, that enough concurrent calls can degrade or deny service, and that client commands must not be run from loops in programs. The REST API documentation says `slurmrestd` is stateless, holds every request open until the controller answers, and recommends a caching proxy between it and clients. A Cacti data source per partition, reason, and node, each calling the scheduler during polling, would multiply controller load by the number of series.

## Decision

One reader process per cluster runs on a poller, makes a small fixed set of scheduler calls per collection interval, normalizes the results into a snapshot, and writes it to plugin tables. Script server inputs read only that snapshot during polling and never call the scheduler. Collection has a time budget, a floor on its interval, and a single running instance per cluster.

## Consequences

- Controller load is independent of the number of Cacti series.
- Data is as old as the last snapshot; every value carries its age.
- A stopped reader shows as stale data, not as zero jobs.

## Alternatives rejected

- **Scheduler call per data source.** Load scales with series count.
- **Reading scheduler state files directly.** Unsupported and unreadable by an unprivileged user.
