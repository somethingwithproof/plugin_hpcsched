# sshare, sacct, and slurmdbd load

Research for [#21](https://github.com/somethingwithproof/plugin_hpcsched/issues/21), run 2026-11-15. Question: what do `sshare` and `sacct` return, what do they cost, and how can accounting statistics be collected without hurting `slurmdbd`?

**Result.** What the documents and source can settle is settled: field availability, the call targets, and the default time windows. The cost of bounded `sacct` queries on a large history is a measurement and is *live*, so the bounded query set below is a proposal with a conservative shape, not a measured budget. Source confirms that `sshare` reads `slurmctld` (association data it already holds), while `sacct` calls `slurmdbd` ([sacct man page](https://slurm.schedmd.com/sacct.html)). `LevelFS` exists in `sshare` at every release checked, and Fair Tree is the default priority flag, so the plan's "only if research shows it is present" can be answered yes for 24.11 to 26.05 with the condition that Fair Tree is on. The default `sacct` window is midnight to now when neither `--jobs` nor `--state` is given, and epoch 0 to now with `--jobs`, so any query without explicit bounds can scan a long history. The recommendation on phase 3 is to ship fair-share first and keep `sacct` statistics off by default until the live check has a number.

## Method

Man pages for [sshare](https://slurm.schedmd.com/sshare.html), [sacct](https://slurm.schedmd.com/sacct.html), and [slurm.conf](https://slurm.schedmd.com/slurm.conf.html) fetched 2026-11-15; source at the tags in [slurm-releases.md](slurm-releases.md). Nothing was run, and no `slurmdbd` was reachable.

## sshare facts

| Fact | Evidence | Status |
|---|---|---|
| `sshare` is "only viable when running with the `priority/multifactor` plugin" | [sshare man page](https://slurm.schedmd.com/sshare.html) | confirmed (docs); what it prints without that plugin is *live* |
| It sends an RPC to `slurmctld`, which holds the association data read from the database | man page, PERFORMANCE section | confirmed |
| Fields: Account, User, Raw Shares, Norm Shares, Raw Usage, Norm Usage (`-l` only), Effectv Usage, FairShare, GrpTRESMins, GrpTRESRaw, TRESRunMins | man page | confirmed |
| With `PriorityFlags=FAIR_TREE` (the default) the man page adds `Level FS` under `-l` | man page; default set at [read_config.c line 4718](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/read_config.c#L4718) | confirmed |
| `LevelFS` is referenced in `src/sshare` at 24.11.7, 25.05.9, 25.11.9, and 26.05.6 | `git grep` (three hits each) | confirmed |
| `sshare --json` dumps the shares response; keys include `shares`, `shares_normalized`, `effective_usage`, `normalized_usage`, `raw_usage`, `fairshare` at v0.0.43 and v0.0.45; v0.0.42 also has `fairshare_level` and a `fairshare/level` member | [v0.0.43 parsers.c lines 7327, 7989 to 7990, 9794 to 9798](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/plugins/data_parser/v0.0.43/parsers.c) | confirmed for names; value types *live* |
| `GET /slurm/{parser}/shares` returns the same data over REST | [slurmrestd.md](slurmrestd.md) | confirmed |
| `PrivateData=usage` hides other users' usage from `sshare` | [slurm.conf](https://slurm.schedmd.com/slurm.conf.html) | confirmed (docs) |
| Per-release field differences other than the `LevelFS` reference | not diffed | unverified |

Accounts to graph are administrator-chosen (ADR 0010). `sshare -A <account>` limits the output to named accounts and `-U` limits to user rows, both documented, so the call can be scoped on the server side rather than filtered in PHP. Scoping to chosen accounts is also what keeps the output small, which matters because `sshare -a` lists every user.

## sacct facts

| Fact | Evidence | Status |
|---|---|---|
| Each call is an RPC to `slurmdbd`; enough concurrent calls can degrade it or deny service; do not run it from loops | [sacct man page, PERFORMANCE](https://slurm.schedmd.com/sacct.html) | confirmed (docs) |
| Without `--jobs` or `--state`: start defaults to midnight, end to now | man page, DEFAULT TIME WINDOW | confirmed |
| With `--jobs` and no `--state`: start defaults to epoch 0 | same | confirmed |
| With `--state` and no `--jobs`: start defaults to now (only currently running jobs) | same | confirmed |
| Output options: `--starttime`, `--endtime`, `-X` (allocations only), `--parsable2`, `--json` | man page | confirmed |
| `PrivateData` in `slurmdbd.conf` can limit what a non-privileged user sees through `sacct` | man page note | confirmed (docs) |
| Run time and `slurmdbd` load for a bounded window on a large history | not measurable here | *live* |

## Proposed bounded query set (for the plan)

Not measured; shaped to be cheap by construction.

| Statistic | Query | Bound |
|---|---|---|
| Jobs completed per interval | `sacct -X -n -P --state=CD,F,TO,NF,CA,OOM --starttime=<last_end> --endtime=<now> -o JobID,State,Partition,Submit,Start,End` aggregated in the reader | window equal to the collection interval plus overlap; hard maximum window set in the plugin; one query at a time per cluster |
| Queue wait | the same rows: `Start - Submit` per job | same query, no second call |
| Backfill after an outage | the next query starts at the last successful end time, capped at the maximum window | never more than the cap |

Rules from the man page that the plan already states and this keeps: explicit start and end on every call; a hard cap; one call at a time; separate failure state; the accounting section may be late without delaying queue or node data. No user or account in a series (ADR 0010). Job rows are aggregated in the reader and discarded, never stored.

Interval: with no measurement, 5 minutes with a 15 minute cap is the shape proposed. The live check below decides whether that is safe; until it has a number, accounting statistics ship off by default, as the plan already says.

## Decision input for phase 3 (#21)

| Question | Evidence-based answer |
|---|---|
| Ship accounting statistics in phase 3? | Fair-share (`sshare`, controller-side, scoped by account) can ship; `sacct` statistics have no load number, so keep them off by default and mark them experimental until the live measurement is done. The decision is the owner's. |
| `sshare` field availability | `LevelFS` is present 24.11 to 26.05 when Fair Tree is on; otherwise graph FairShare and Effectv Usage only. |

## Limits

- No `slurmdbd` or database was available. Nothing about the cost of `sacct` on a large history is established; the man page only says calls are RPCs that can degrade the daemon.
- The JSON value types for shares were not observed.
- Whether `sshare` with `--json` on 26.05 works against an older `slurmctld` was not checked.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #21 | On a `slurmdbd` with at least one million job records, time `sacct -X -P` with the proposed window at 5, 15, and 60 minutes; record `slurmdbd` CPU and `sdiag`-style RPC timing during the call | the interval and cap |
| #21 | Run ten parallel `sacct` calls and record effect on `slurmdbd` | the single-call rule's necessity |
| #21 | `sshare -l -A <account>` and `--json` on a cluster with Fair Tree on and off | `LevelFS` presence and JSON types |
| #21 | `sshare` on a cluster without `priority/multifactor` | the "not available" state text |
| #21 | `sacct` with `PrivateData` set in `slurmdbd.conf`, run as the plugin's account | whether the account sees all jobs |
