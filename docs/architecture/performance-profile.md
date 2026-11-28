<!--
SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
SPDX-License-Identifier: GPL-2.0-or-later
-->

# Performance Profile and Algorithmic Analysis

## Scope and Measurement Environment

All timings below were measured with PHP 8.4.25 (`Darwin arm64`, `memory_limit=1G`) using `hrtime(true)` and `memory_get_peak_usage(true)` across 3 iterations (median reported; 1 iteration for the unoptimized maintenance-sync baseline). Benchmarks exercise synthetic Slurm snapshots at 10,000 to 50,000 jobs and 10,000 to 20,000 compute nodes.

## Algorithmic Audit Findings and Fixes

| Component | Before | After | Root Cause and Fix |
| --- | --- | --- | --- |
| `MaintSynchronizer::plan()` | $O(N + L \cdot N)$ | $O(N + L)$ | Cleanup of disappeared owned Maint links called `nodePresent($nodes, $name)`, scanning all $N$ cluster nodes linearly for each of $L$ owned links. Replaced with an `$presentNodes` hash set populated during the existing node pass. |
| `BackfillMetrics::summarize()` | $3 \cdot O(J \log J)$ | $O(J \log J)$ | `summarize()` called `self::percentile()` three times (`p50`, `p95`, `p99`), copying and sorting the wait-time vector three times per partition and three times for the cluster, then scanning again for `max()`. Now sorts once and evaluates all three percentiles plus max in $O(1)$. |
| `SlurmParser::jobs()`, `JobState`, `JobTally::add()` | $O(J)$ with redundant regex/copies | $O(J)$ single-pass | `JobState::finished()` and `JobState::of()` each mapped and intersected `$states`, `JobTally::add()` ran `Reasons::name()` and `ReasonFamily::of()` even for running jobs and called `array_merge`/`array_unique` per job, and `SlurmParser::names()` re-ran regex validation on every job. Replaced with single-pass state scans, pending-only reason evaluation, per-snapshot partition validation caching, and fast-path single-partition counting. |
| `SlurmParser::nodes()`, `NodeState::fromFlags()` | $O(N)$ with double normalize | $O(N)$ single-pass | `SlurmParser::nodes()` normalized state flags and passed them to `NodeState::fromFlags()`, which normalized again and ran up to 5 `array_intersect()` calls per node. Added `NodeState::fromNormalizedFlags()` single-pass classification, `isset` guards for optional GRES/TRES fields, and a byte-length guard before `mb_substr()` in `clip()`. |
| `NodeTally::rows()` | $O(N \cdot P)$ with temp arrays | $O(N \cdot P)$ direct update | Eliminated per-node `array_merge([''], array_values(array_unique(...)))` and temporary 7-key `$values` array allocations by accumulating directly into partition buckets. |
| `NodeMatcher::match()` | $O(N + D)$ eager 4-tier | $O(N + D)$ lazy tiers | Previously computed all 4 matching tiers (including description `array_merge` and `filter_var` IP validation) even when Tier 1 hostname matched immediately. Lazy tier evaluation short-circuits on the first matching tier and avoids double normalization in `shortLabel()`. |
| `DominantResourceFairness::indexShareRecords()` | $O(S)$ list retention | $O(A)$ streaming | Previously stored per-record factor and effective-usage lists for every `ShareRecord` before summing. Replaced with running sums and counts per account. |

## Measured Benchmark Results (Before vs. After)

| Workload | Before Median (ms) | After Median (ms) | Speedup | Peak RSS Before (MiB) | Peak RSS After (MiB) |
| --- | ---: | ---: | ---: | ---: | ---: |
| `MaintSynchronizer::plan` (10,000 nodes + 2,000 owned links) | 538.04 | 39.64 | 13.6x | 98.00 | 88.00 |
| `BackfillMetrics::analyze` (50,000 jobs, 8 partitions) | 34.21 | 20.41 | 1.68x | 76.50 | 66.50 |
| `SlurmParser::jobs` (50,000 jobs, 8 partitions) | 143.03 | 86.42 | 1.66x | 22.00 | 22.00 |
| `NodeTally::rows` (20,000 nodes, 8 partitions) | 31.36 | 23.83 | 1.32x | 72.00 | 62.00 |
| `NodeMatcher::match` (20,000 nodes x 20,000 devices) | 51.06 | 43.16 | 1.18x | 92.00 | 80.00 |
| `SlurmParser::nodes` (20,000 nodes, with GRES/TRES support) | 69.40 | 60.74 | 1.14x | 70.00 | 58.00 |
| `DominantResourceFairness::evaluate` (5,000 accounts, 20,000 shares) | 16.41 | 16.56 | 0.99x | 104.00 | 94.00 |
| `HostList::expand` (10,000 nodes) | 2.92 | 3.00 | 0.97x | 96.00 | 86.00 |

Note: `DominantResourceFairness::evaluate` latency stayed flat (~16.5 ms) while peak RSS dropped by 10 MiB due to streaming share accumulators; `SlurmParser::nodes` improved by 1.14x while gaining GPU/GRES and RAM TRES parsing.

## Complexity of New Telemetry Modules

- `TresParser::parseGpuCount()` and `parseMemMib()`: $O(L)$ in the length of the node GRES/TRES string with zero regex backtracking in GPU parsing.
- `NodeTally::resourceSummary()`: $O(N \cdot P)$ single linear pass over deduplicated nodes exposing CPU, GPU (`alloc_gpus`, `idle_gpus`, `drained_gpus`, `total_gpus`), and RAM (`alloc_mem_mib`, `total_mem_mib`, `free_mem_mib`) gauges per partition and cluster-wide.
- `DiagnosticsParser::parseRestDiag()` and `parseSdiagText()`: $O(1)$ / $O(L)$ over controller diagnostics output.
- `SlurmParser::jobExits()` and `JobExitTally`: $O(J)$ single pass over job records with $O(P)$ memory in partition count (at most 7 terminal outcome buckets per partition), retaining no job name, user, or account.
