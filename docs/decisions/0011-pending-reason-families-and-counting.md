# 0011. Group pending reasons into eleven fixed families and fix the counting rules

Status: Accepted (2026-11-15). Decides [#12](https://github.com/somethingwithproof/plugin_hpcsched/issues/12).

## Context

[ADR 0010](0010-bound-series-cardinality.md) bounds series by grouping pending reasons into families. The reason list lives in `src/common/job_state_reason.c` and changes between releases: 216 distinct strings at 24.11, 25.11, and 26.05, 215 at 25.05, and 218 at the 26.11 candidate. SchedMD's job reason page omits 23 of them. [slurm-states-reasons.md](../research/slurm-states-reasons.md) proposed prefix rules that place every string in one of eleven families, and left open whether the Other family should be split.

The graphs also need counting rules that Slurm does not settle for us: how a pending array counts, which pending jobs are held, and how jobs and nodes in several partitions count.

## Decision

**Families.** Eleven fixed families, matched on the leading identifier of the reason, first rule wins (`ReasonFamily::of()`):

| Family | Rule | Distinct strings at 26.05 |
|---|---|---|
| Priority | `Priority` | 1 |
| Resources | `Resources`, `Licenses`, `NvidiaImexChannels` | 3 |
| Dependency | `Dependency`, `DependencyNeverSatisfied` | 2 |
| Held | `JobHeldAdmin`, `JobHeldUser`, `JobHoldMaxRequeue` | 3 |
| Begin time | `BeginTime` | 1 |
| Reservation | prefix `Reservation` | 3 |
| Association limits | prefix `Assoc` | 61 |
| QOS limits | prefix `QOS`, or `Max…PerAccount`, `Max…PerUser` | 109 |
| Partition limits | prefix `Partition` | 5 |
| Node availability | `ReqNodeNotAvail`, `NodeDown`, `MaxPoweredUpNodes` | 3 |
| Other | everything else, and any name a later release adds | 25 |

Other is not split. Its 25 strings at 26.05 are mostly failure reasons that a pending job does not carry (`NonZeroExitCode`, `TimeLimit`, `OutOfMemory`) or rare ones; `JobArrayTaskLimit`, `AccountNotAllowed`, and the three `BurstBuffer*` reasons are the candidates for a family of their own, and adding one later changes neither the unknown-reason rule nor the other families. The raw reason of every pending job is counted beside its family, and the pending reasons view of the HPC Scheduler page lists it, so a reason in Other is visible by name.

`tests/fixtures/slurm/reasons.json` holds the distinct strings of each release from source (24.11.7, 25.05.9, 25.11.9, 26.05.6, 26.11.0rc1), and a unit test checks the family counts at each release, so a rule change that moves a reason fails the build.

**Counting rules.**

- Held is a pending job whose reason is in the Held family. The pending series counts eligible jobs only; held jobs have their own series. This matches the Slurm metrics plugin's definition, plus `JobHoldMaxRequeue`.
- A pending array that has not split into tasks counts as its number of remaining tasks, read from `array_task_string` (the metrics plugin also counts tasks). Array tasks that already run are separate job records and count once each.
- A job submitted to several partitions counts once in the cluster totals and once in each partition it names.
- A node in several partitions counts once in the cluster totals and once in each of its partitions, by node name.
- Pending reason families are values of one data source per partition, not data query indexes, so a partition has one RRD file for its eleven families.

**Reindex.** A partition is an index of the partition data query. Cacti's "Index Count Changed" check sees a partition added or removed, but not one renamed. The reader therefore compares the partitions in a fresh snapshot with the device's indexes and runs Cacti's reindex when they differ. The data query sets `index_orphan_removal`, so a removed partition's data sources stop being polled through Cacti's own reindex path; its graphs stay, with their history, until an administrator deletes them.

## Consequences

- The series count per partition is fixed: eleven family values, whatever the release.
- A reason added by a release lands in Other until the rules name it. The unit test shows the change when the fixture gains the release.
- The family breakdown reports the scheduler's last examined reason only; the documentation says so.
- Cluster totals are not sums of partition series when jobs or nodes span partitions.

## Alternatives rejected

- **One series per raw reason.** Unbounded, release dependent, and rejected by ADR 0010.
- **Operator-defined families.** Every site would need configuration before the graphs mean anything.
- **Families as data query indexes.** Eleven RRD files per partition instead of one, and a pending reason graph could not stack them.
- **Splitting Other now.** Possible later without changing the rules above; no captured queue yet shows which reasons in Other matter in practice.
