# Controller load and collection intervals

Research for [#18](https://github.com/somethingwithproof/plugin_hpcsched/issues/18), run 2026-11-15. Question: how much load do the plugin's calls put on `slurmctld`, and what default interval and floor are safe?

**Result.** Not done as a measurement: this issue is a load test on clusters of 1,000 and 10,000 nodes and needs a Slurm controller, so the per-call cost, the transport comparison, and the interval floor are all *live*. What documentation and source settle is the number of controller RPCs per cycle and the limits they meet. Reading the REST handlers shows `/nodes` makes two controller RPCs (nodes and partitions) and `/jobs`, `/partitions`, and `/reservations` make one each, so the plan's "2 to 4 per cycle" holds in count (see below). The rate limiter defaults (bucket 30, refill 2 per 1 second) are confirmed, and the limiter is per user and exempts `SlurmUser` and root. A provisional interval is proposed so milestone 2 can start; the live test replaces it. The OpenMetrics plugin cannot replace the reader for pending reasons: its source contains no reason metric except one per-partition counter for the node-limit reason.

## Method

Source at the tags in [slurm-releases.md](slurm-releases.md) and the documents [slurm.conf](https://slurm.schedmd.com/slurm.conf.html), [High throughput guide](https://slurm.schedmd.com/high_throughput.html), [Metrics guide](https://slurm.schedmd.com/metrics.html), [sinfo](https://slurm.schedmd.com/sinfo.html), fetched 2026-11-15. Nothing was run.

## Controller calls per cycle

| Call | Controller RPCs | Evidence | Status |
|---|---|---|---|
| REST `GET /jobs/` | 1 (`slurm_load_jobs`) | [jobs.c line 131](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/plugins/openapi/slurmctld/jobs.c#L131) | confirmed |
| REST `GET /nodes/` | 2 (`slurm_load_node`, then `slurm_load_partitions` to map nodes to partitions) | [nodes.c lines 117 and 155](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/plugins/openapi/slurmctld/nodes.c#L117) | confirmed for 26.05; 25.11 has the same two calls |
| REST `GET /partitions/`, `/reservations/`, `/shares` | 1 each | `partitions.c` line 218, `reservations.c` line 272 | confirmed |
| `sinfo` | the man page says one RPC to `slurmctld`; the exact count (nodes plus partitions) was not traced | [sinfo man page](https://slurm.schedmd.com/sinfo.html) | partly confirmed |
| `squeue` | one job-info RPC at minimum; whether it also loads partitions was not traced | [squeue man page](https://slurm.schedmd.com/squeue.html) | partly confirmed |
| `sacct` | one RPC to `slurmdbd`, not the controller | [sacct man page](https://slurm.schedmd.com/sacct.html) | confirmed (docs) |

Phase 1 cycle through REST: jobs, nodes, partitions = 4 controller RPCs. Phase 2 adds reservations (5). Phase 3 adds shares (6). The plan's "about 2 to 4" matches phase 1 only. If the node endpoint's second RPC is avoided by using `squeue` plus `sinfo`, the count is similar; the cost is the work per RPC, not the count.

## Limits the calls meet

| Setting | Value | Evidence | Status |
|---|---|---|---|
| RPC rate limiting | off unless `SlurmctldParameters=rl_enable`; bucket size 30, refill rate 2 per refill period of 1 second, table size 8192, counted per uid | [rate_limit.c lines 58 to 68](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/rate_limit.c#L58-L68) | confirmed |
| Who is exempt | `SlurmUser` and root, and RPC types marked `rl_exempt`; a limited caller is told to back off | rate_limit.c lines 164 to 173 and 246 | confirmed |
| Limit added in 23.02 | `rate_limit.c` exists at 23.02.8 and later tags | `git grep` at three tags | confirmed |
| `MaxJobCount` default | 10000 | [read_config.h line 123](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/read_config.h#L123) | confirmed |
| `MinJobAge` default | 300 seconds; completed job records stay that long; purging is deferred during a backfill cycle | read_config.h line 128; [slurm.conf](https://slurm.schedmd.com/slurm.conf.html) | confirmed |
| Message timeout default | 10 seconds; slurmctld timeout default 120 | read_config.h lines 131 and 152 | confirmed |
| `slurmrestd --max-connections` | default 124; each connection can cause one RPC | [slurmrestd.c line 125](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/slurmrestd.c#L125) | confirmed |

Effect on the plan: a pending-jobs figure is bounded by `MaxJobCount` (jobs beyond it cannot be submitted, so the queue the reader sees is at most that large unless the site raised it), and a finished job disappears after `MinJobAge`. With the limiter on, one user can spend 30 RPCs at once and 2 per second after; the plugin's four to six RPCs per cycle are far below that unless many Cacti pollers share one Slurm user. If they do, the shared bucket is the constraint, so the account recipe should name one account per poller.

## OpenMetrics as a lower-cost source

| Fact | Evidence | Status |
|---|---|---|
| Added in 25.11; configured by `MetricsType=metrics/openmetrics`; endpoints `/metrics/jobs`, `/nodes`, `/partitions`, `/scheduler`, `/jobs-users-accts` on the controller port | [Metrics guide](https://slurm.schedmd.com/metrics.html) | confirmed (docs) |
| `slurm_jobs_hold`, `slurm_jobs_pending`, per-partition pending and hold counts, and about forty node-state counts exist | [openmetrics.c](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/plugins/metrics/openmetrics/openmetrics.c#L440-L520) | confirmed |
| No pending-reason metric, except `slurm_partition_jobs_wait_part_node_limit` | `grep -i reason` over `src/plugins/metrics` returns nothing at 25.11 and 26.05; metric names in openmetrics.c | confirmed |
| Pending counts add the array task count, held jobs are counted separately | [statistics.c lines 305 to 311](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/statistics.c#L305-L311) | confirmed |
| Access: global unless `PrivateData` is set, then a JWT for `SlurmUser`, root, or a `MetricsAuthUsers` user; a standby controller answers 303 or 503 | metrics guide | confirmed (docs) |
| The guide advises 60 to 120 second scrape intervals and warns it takes controller locks | metrics guide | confirmed (docs) |

So metrics can supply queue depth, held count, and node-state counts at the cost of one GET per endpoint on 25.11 and later, but never the pending reason breakdown, which is the plugin's main feature. It stays research, as the plan has it, and could become an optional cheap source for the non-reason counts on 25.11 and later.

## Provisional interval (to be replaced by the live test)

| Setting | Proposal | Basis |
|---|---|---|
| Default collection interval | 60 seconds | matches the metrics guide's low end of 60 to 120 seconds and the plan's wording |
| Floor | 30 seconds, refused below | two samples per bucket refill budget, no measurement |
| Time budget | half the interval | leaves the single-instance rule room (`register_process_start()` default timeout is 300 seconds, see [cacti-core.md](cacti-core.md)) |
| Backoff | when the controller replies that the rate limit was exceeded, skip the next cycle and double the wait up to 10 minutes | the plan's "back off when rate limited" |

These are not evidence; they are defaults to start from. The exit criteria of #18 are not met until the live rows below are filled.

## Limits

No load was generated. The man pages say only that calls are RPCs that can degrade the daemon. Controller time per call at 10,000 nodes and 100,000 jobs, lock contention, the effect of a hung client, and slurmrestd concurrency are all unknown. The 100,000-job case also exceeds the default `MaxJobCount`, so the test must raise it.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #18 | On 1,000 and 10,000 simulated nodes with 10,000 and 100,000 jobs, record `sdiag` RPC time and `slurmctld` CPU for each call, both transports, three runs | per-call cost and the transport choice |
| #18 | Repeat with `rl_enable` and defaults; poll at 10, 30, and 60 seconds | the floor and backoff behavior |
| #18 | Start 124 concurrent REST clients and one slow client | `slurmrestd` saturation and the plugin timeout |
| #18 | Raise `MaxJobCount` and `MinJobAge` and observe what `squeue` shows | the queue-size bound |
| #18 | Scrape `/metrics/jobs` and `/metrics/nodes` on 25.11 and 26.05 and compare counts to `squeue` and `sinfo` | whether metrics can be an optional source |
