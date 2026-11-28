# Enterprise Hardening Architecture

## Multi-Resource Fairness and Queue Backfill Analytics

`Somethingwithproof\Hpcsched\DominantResourceFairness` and
`Somethingwithproof\Hpcsched\BackfillMetrics` provide deterministic, read-only
analytics over account allocations, Slurm Fair-Share records, and job wait
timings.

### Academic Grounding

- Ghodsi et al., *Dominant Resource Fairness: Fair Allocation of Multiple
  Resource Types*, USENIX NSDI 2011: evaluates normalized per-account resource
  shares across heterogeneous CPU, GPU, and memory pools, computes dominant
  resource shares, Jain's fairness index, and progressive-filling priority
  ordering, and flags accounts whose multi-resource dominance diverges from
  Slurm's scalar Fair-Share factor.
- Yoo, Jette, and Grondona, *SLURM: Simple Linux Utility for Resource
  Management*, JSSPP 2003: computes per-partition and cluster-wide queue
  wait-time percentiles (`p50`, `p95`, `p99`) using Hyndman-Fan type-7 linear
  interpolation, backfill job ratios, idle-hole backfill CPU-second efficiency,
  and head-of-line starvation counts.

## Decoupled Plugin Integration and Config/State Separation

- `schema/hpcsched.schema.yaml` defines strict separation between declarative
  configuration (`config`) and ephemeral runtime state (`state`), keeping
  `token_file` redacted and never storing credentials in database tables.
- Optional hooks `doctor_register_debug_info` and `api_register_resources`
  return plain PHP arrays (redacted diagnostic fields and Google AIP resource
  descriptors) without importing any external plugin classes.
