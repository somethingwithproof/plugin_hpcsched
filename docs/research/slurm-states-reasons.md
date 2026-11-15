# Node and job states, flags, and reasons

Research for [#19](https://github.com/somethingwithproof/plugin_hpcsched/issues/19), run 2026-11-15. Question: how do node states, state flags, node reasons, job states, and pending reasons appear in each output form, and how do they map to the plugin's enums? The reason-family mapping here feeds the decision in #12.

**Result.** The reason list can be mapped completely from source. At 26.05 the enum has 218 entries and 216 distinct strings; a prefix rule puts every one in the plan's eleven families, and the same rule maps every reason at 24.11, 25.05, 25.11, and the 26.11 candidate with no unmapped name (the difference between releases is two to four names, listed below). SchedMD's own job reason page does not list 23 of the strings in source (including the whole `Max*RunMinsPer(Account|User)` group) and its wildcard `Max*PerAccount` does not match them, so the plan must match on the source list and prefix rules, not on the documentation page. Node states are a base state plus flags; the JSON `state` array keeps both, while the `%T` string collapses them, and a node that is DOWN and draining prints as DRAINED, losing DOWN. The plan's unique-node count, the held-job rule, and the DOWN-off-by-default maintenance rule all depend on reading JSON. Captured output per release is *live*.

## Method

Reasons: `src/common/job_state_reason.c` at 24.11.7, 25.05.9, 25.11.9, 26.05.6, and 26.11.0rc1 (commits in [slurm-releases.md](slurm-releases.md)), parsed for the `[WAIT_...] = { .flags, .str }` table. Compared with the [job reason codes page](https://slurm.schedmd.com/job_reason_codes.html) fetched 2026-11-15. Nodes: `slurm/slurm.h` and `src/common/slurm_protocol_defs.c` at 26.05.6, and the [sinfo man page](https://slurm.schedmd.com/sinfo.html). Nothing was run.

## Node states and flags

Base states (`enum node_states`, [slurm.h](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/slurm/slurm.h)): UNKNOWN, DOWN, IDLE, ALLOCATED, ERROR (documented as unused), MIXED, FUTURE. Flags are separate bits ([slurm.h lines 999 to 1037](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/slurm/slurm.h#L999-L1037)): DRAIN, COMPLETING, NO_RESPOND, POWERED_DOWN, FAIL, POWERING_UP, MAINT, REBOOT_REQUESTED, REBOOT_ISSUED, POWERING_DOWN, PLANNED, INVALID_REG, POWER_DOWN, POWER_UP, POWER_DRAIN, BLOCKED, RES (reserved), CLOUD, EXTERNAL, DYNAMIC_FUTURE, DYNAMIC_NORM, UNDRAIN.

| Output form | What it carries | Evidence | Status |
|---|---|---|---|
| JSON `state` | an array of strings, base state plus each flag name (`DRAIN`, `MAINTENANCE`, `NOT_RESPONDING`, `RESERVED`, and so on), built from the `node_states` and `node_state_flags` tables | [slurm_protocol_defs.c lines 121 to 151](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/slurm_protocol_defs.c#L121-L151) | confirmed (source); exact JSON captured *live* |
| `sinfo %T` | one string built by `node_state_string()`: a base name with a suffix, or a replacement name such as DRAINING, DRAINED, FAILING, MAINT, RESERVED, PLANNED, BLOCKED, REBOOT | [`node_state_string` from line 3676](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/slurm_protocol_defs.c#L3676) | confirmed |
| `sinfo %t` | the compact form from `node_state_string_compact()`: `drng`, `drain`, `boot`, and so on | [line 3933](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/slurm_protocol_defs.c#L3933) | confirmed |

What `%T` loses, from the function body:

| Node condition | `%T` prints | Lost |
|---|---|---|
| drain flag, base ALLOCATED or MIXED or COMPLETING | `DRAINING` (suffix when maint, reboot, power, or no-respond flags are also set: `DRAINING$`, `DRAINING*`, ...) | none |
| drain flag, any other base (IDLE, DOWN, UNKNOWN) | `DRAINED` (same suffix rule) | the base state: a node that is both DOWN and drained reads as DRAINED |
| MAINT flag with base IDLE, UNKNOWN, FUTURE | `MAINT` or `MAINT*`, replacing the base | the base state (IDLE versus others) |
| MAINT flag with base ALLOCATED, MIXED, DOWN | the base name with a `$` suffix | none |
| fail flag | `FAILING` (allocated, mixed, or completing) or `FAIL` | the base state |
| RES flag on IDLE | `RESERVED` | the word IDLE |
| several suffix flags together | only the first matching suffix is printed (the function returns at the first match) | the others |

Therefore the plugin reads the JSON `state` array, not `%T`, for anything that needs the drain flag, the maintenance flag, or DOWN together with DRAIN. Format output with `%T` is acceptable only for the plain counts the graphs show (idle, allocated, mixed, drained, down, maintenance, other), and for the drained count it counts DOWN-and-drained nodes as drained.

Suffix characters in the man page (confirmed, [sinfo](https://slurm.schedmd.com/sinfo.html)): `*` not responding, `~` powered off, `#` powering up or configuring, `!` pending power down, `%` powering down, `$` in a maintenance reservation, `@` pending reboot, `^` reboot issued, `-` planned for a higher priority job.

Proposed normalized states (plugin enum, with the fallback): Idle, Allocated, Mixed, Drained (drain flag, no running work), Draining (drain flag with running work), Down, Maintenance (MAINT flag), Reserved (RES flag on idle), Powered down, Other. Order of precedence when several apply: Maintenance and Down are shown beside the drain flag, not instead of it; the snapshot keeps the raw state array so a state added by a later release maps to Other and nothing is lost.

Node counting rule fixed by source: a node in several partitions appears once per partition in `sinfo -N` ([sinfo man page](https://slurm.schedmd.com/sinfo.html)), so unique nodes are counted by node name. The nodes endpoint and `scontrol show node` list each node once with a comma-separated `partitions` value ([parsers.c](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/plugins/data_parser/v0.0.45/parsers.c), key `partitions`), which is the cheaper source for unique counts.

Node reason text, time, and user: JSON keys `reason`, `reason_changed_at`, `reason_set_by_user` (all three parsers); format codes `%E`, `%H`, `%u` ([sinfo man page](https://slurm.schedmd.com/sinfo.html)). The man page says the reason is shown for down, drained, and draining states.

## Job states

Base job states ([`enum job_states`](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/slurm/slurm.h)): PENDING, RUNNING, SUSPENDED, COMPLETE, CANCELLED, FAILED, TIMEOUT, NODE_FAIL, PREEMPTED, BOOT_FAIL, DEADLINE, OOM. Flags include COMPLETING, CONFIGURING, STAGE_OUT, SIGNALING, REQUEUE, REQUEUE_HOLD, and others ([slurm.h lines 287 to 314](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/slurm/slurm.h#L287-L314)).

| Plan class | Rule | Status |
|---|---|---|
| Pending (eligible) | state PENDING and reason not `JobHeldAdmin`, `JobHeldUser` | confirmed: the metrics plugin defines held exactly this way ([statistics.c lines 305 to 311](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/statistics.c#L305-L311)) |
| Held | state PENDING and reason `JobHeldAdmin` or `JobHeldUser` | confirmed; `JobHoldMaxRequeue` is a third hold reason that this rule also treats as held in the family table |
| Running | state RUNNING | confirmed |
| Completing | COMPLETING flag | confirmed |
| Other | everything else | by fallback |

Whether a job already finished appears in `squeue` depends on `MinJobAge` (default 300 seconds, [read_config.h](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/read_config.h#L128)); counts of completed, failed, and cancelled jobs from the queue are therefore not history and the plan should not graph them as such.

## Pending reasons

Counts at each release (distinct strings; one string is shared by two enum values at 26.05, see below):

| Release | Enum entries | Distinct strings | Names not in 26.05 | Names only in 26.05 |
|---|---|---|---|---|
| 24.11.7 | 218 | 216 | `FrontEndDown` (`WAIT_FRONT_END`) | `NvidiaImexChannels` |
| 25.05.9 | 217 | 215 | none | `NvidiaImexChannels` |
| 25.11.9 | 218 | 216 | none | none |
| 26.05.6 | 218 | 216 | none | none |
| 26.11.0rc1 | 220 | 218 | `HRESDisabled`, `PartitionCpuLimit` | none |

Upstream groups the reasons itself with four flags in the same table: `JSR_QOS_ASSOC` (139 entries at 26.05), `JSR_QOS_GRP | JSR_QOS_ASSOC` (30), `JSR_PART` (4), `JSR_MISC` (4), `JSR_QOS_ASSOC | JSR_PART` (1), and 40 with no flag ([job_state_reason.h](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/job_state_reason.h#L37-L43), [job_state_reason.c](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/job_state_reason.c)). Those flags are not exposed in any output the plugin reads, so they only inform the family rules.

**Differences between source and the documentation page:**

| Item | Detail | Status |
|---|---|---|
| In source, not on the page | 23 strings: `MaxBBRunMinsPerAccount`, `MaxBBRunMinsPerUser`, and the same pair for Billing, Cpu, Energy, GRES, License, Memory, Node, Unknown; plus `MaxPoweredUpNodes`, `MpiPortsBusy`, `NvidiaImexChannels`, `RaisedSignal`, `ReservationInvalid` | **differs** (page is incomplete) |
| On the page, not in source | `QOSMaxBBMinutesPerJob`: both `WAIT_ASSOC_MAX_BB_MINS_PER_JOB` and `WAIT_QOS_MAX_BB_MINS_PER_JOB` carry the string `AssocMaxBBMinutesPerJob` ([job_state_reason.c lines 471 to 473 and 671 to 673](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/job_state_reason.c#L671-L673)) | **differs**: a QOS limit can appear under an association name; the family rule cannot tell them apart by name |
| Wildcards on the page | `AssocGrp*`, `AssocMax*`, `Max*PerAccount`, `QOSGrp*`, `QOSMax*` | confirmed; `Max*PerAccount` does not cover `Max*RunMinsPerAccount` or `Max*RunMinsPerUser` |
| `QOSMin*` | nine names in source (`QOSMinBB`, `QOSMinBilling`, `QOSMinEnergy`, `QOSMinGRES`, `QOSMinLicense`, `QOSMinMemory`, `QOSMinNode`, `QOSMinUnknown`, `QOSMinCpuNotSatisfied`) are not covered by the page's wildcards | **differs** |

**Draft family rules** (applied to the distinct string, first match wins; this reproduces the counts below at every release checked):

| Family | Rule | Count at 26.05 |
|---|---|---|
| Priority | `Priority` | 1 |
| Resources | `Resources`, `Licenses`, `NvidiaImexChannels` | 3 |
| Dependency | `Dependency`, `DependencyNeverSatisfied` | 2 |
| Held | `JobHeldAdmin`, `JobHeldUser`, `JobHoldMaxRequeue` | 3 |
| Begin time | `BeginTime` | 1 |
| Reservation | prefix `Reservation` | 3 |
| Association limits | prefix `Assoc` | 61 |
| QOS limits | prefix `QOS`, or `Max*PerAccount`, `Max*PerUser`, `Max*RunMinsPerAccount`, `Max*RunMinsPerUser` | 109 |
| Partition limits | prefix `Partition` (includes `PartitionDown`, `PartitionInactive`, `PartitionConfig`) | 5 |
| Node availability | `ReqNodeNotAvail`, `NodeDown`, `MaxPoweredUpNodes` | 3 |
| Other | everything else, including failure reasons such as `NonZeroExitCode`, `TimeLimit`, `BadConstraints`, `AccountNotAllowed`, `JobArrayTaskLimit`, and `None` | 25 |

The 25 in Other include several that are more useful than their bucket suggests (`JobArrayTaskLimit`, `AccountNotAllowed`, `BurstBuffer*`); the decision issue #12 can split them without changing the unknown-reason rule. `MaxMemPerLimit` falls into Other because no rule above matches it. Any unknown name goes to Other with the raw string kept, which is the plan's fallback and matches how 26.11 adds two names.

A reason name is the scheduler's last examined reason only ([job reason codes page](https://slurm.schedmd.com/job_reason_codes.html): "only the reason that was encountered by the attempted scheduling method will be displayed").

## Limits

No output was captured. The exact spelling of reason strings in JSON versus `%r` (the same table is used by both, by source) and the exact JSON enum spelling of node flags were not seen in a response. Reasons that appear only on a running or failed job (for example `NonZeroExitCode`) are in the enum but never on a pending job, so the Other bucket is smaller in practice than 25.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #19 | Capture `sinfo --json` and `scontrol --json show node` for a node that is DOWN and drained, one in a MAINT reservation, one powered down | the JSON `state` array contents and the `%T` loss rows |
| #19 | Capture pending jobs for each reason family on a test cluster (held, dependency, association limit, QOS limit, begin time, reservation, partition down) | the family mapping against real reasons |
| #19 | Compare `squeue %r` with the JSON `state_reason` for the same jobs | that both forms use the same strings |
| #12 | Owner decision on whether Other is split (array limit, account not allowed, burst buffer) | the final family set |
