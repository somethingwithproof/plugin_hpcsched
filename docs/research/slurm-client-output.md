# Client command output for squeue, sinfo, scontrol, and sshare

Research for [#16](https://github.com/somethingwithproof/plugin_hpcsched/issues/16), run 2026-11-15. Question: what exactly do the client commands emit, in JSON and in format-string form, on each release in the matrix, and which form is safe to parse?

**Result.** Everything that can be settled from documentation and source is settled below; captured fixtures and the JSON versus format-output measurement need a cluster and are *live*. Source shows five things the plan did not state. (1) Every client's `--json` is available from 23.11 to 26.05, and `--json=list` prints the parsers a client build knows, but an unpinned `--json` uses the client's own default parser (or the `DataParserParameters` setting), so output changes when the client package is upgraded. (2) `sinfo --json` is not the nodes endpoint's shape: it dumps a sinfo-specific response type. (3) `squeue --only-job-state --json` returns only job id, array ids, and state, so it cannot give partition or reason. (4) `squeue` collapses pending array elements onto one line unless `-r` is given, so counting lines undercounts array tasks; the metrics plugin counts tasks through an internal array count. (5) The node `%T` string loses the base state when a node is both DOWN and draining (it prints DRAINED), so drain and down cannot both be read from `%T`. Parsing rules are in the last section.

## Method

Source at the tags in [slurm-releases.md](slurm-releases.md) (commit `599e8ac5` for 26.05, `5f7be910` for 25.11, `420f74f5` for 25.05, `defaf214` for 24.11), man pages from `slurm.schedmd.com` fetched 2026-11-15, and the JSON field names read from `src/plugins/data_parser/<version>/parsers.c`. No command was run.

## JSON options

| Fact | Evidence | Status |
|---|---|---|
| `squeue`, `sinfo`, `scontrol`, `sshare`, `sacct` each define a `json` long option at 23.11, 24.05, 24.11, 25.05, 25.11, and 26.05 | option tables, for example [squeue/opts.c](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/squeue/opts.c#L167) | confirmed |
| `--json=list` prints the data parser plugins the client knows and exits successfully | [sercli.c lines 161 to 168](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/sercli.c#L161-L168); the list goes to standard error (`dprintf(STDERR_FILENO, ...)`) | confirmed |
| A named parser the client does not have fails with `ESLURM_DATA_INVALID_PARSER` and the message "output not supported" | [sercli.c lines 170 to 175](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/sercli.c#L170-L175) | confirmed |
| With no parser named, the client uses `DataParserParameters` from `slurm.conf` if set, else the parser compiled into the client | [sercli.c lines 212 to 222](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/common/sercli.c#L212-L222) | confirmed |
| The man pages say JSON dumps all information and ignores sorting and format options | [squeue](https://slurm.schedmd.com/squeue.html), [sinfo](https://slurm.schedmd.com/sinfo.html), [sshare](https://slurm.schedmd.com/sshare.html) | confirmed (docs) |
| `squeue --json` returns a job-info response; with `--only-job-state` it returns a job-state response | [squeue.c line 240](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/squeue/squeue.c#L240) and [line 374](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/squeue/squeue.c#L374) | confirmed |
| `sinfo --json` dumps `OPENAPI_SINFO_RESP`, a sinfo-specific type | [sinfo.c line 267](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/sinfo/sinfo.c#L267) | confirmed |
| `scontrol --json show node`, `show partition`, `show reservation` dump the same node, partition, and reservation response types as the REST endpoints | [info_node.c](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/scontrol/info_node.c#L225), [info_part.c](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/scontrol/info_part.c#L145), [info_res.c](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/scontrol/info_res.c#L137) | confirmed |
| `sshare --json` dumps `OPENAPI_SHARES_RESP` | [sshare.c line 285](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/sshare/sshare.c#L285) | confirmed |
| What a client prints when its parser exists but the daemon is a different release | not in source read | *live* |

Decision input: the plugin must always pass an explicit parser (`--json=v0.0.43` or whichever the supported-release decision fixes). An unpinned `--json` follows the client package.

## Fields the snapshot needs

Names are JSON keys in `parsers.c` of the parser shown. The nodes response (REST `/nodes`, `scontrol --json show node`) and the sinfo response differ, so each key below is for the node-info type only.

| Need | Key | v0.0.42 | v0.0.43 | v0.0.45 | Status |
|---|---|---|---|---|---|
| Job state | `job_state` | yes | yes | yes | confirmed |
| Pending reason | `state_reason` | yes | yes | yes | confirmed |
| Job partition | `partition` | yes | yes | yes | confirmed |
| Job account | `account` | yes | yes | yes | confirmed |
| Array task id, array job id, task expression | `array_task_id`, `array_job_id`, `array_task_string`, `array_max_tasks` | yes | yes | yes | confirmed |
| Node name, hostname, address | `name`, `hostname`, `address` | yes | yes | yes | confirmed (`name` is described as "NodeName" in all three) |
| Node state and flags | `state` (array of strings) | yes | yes | yes | confirmed |
| Node reason, time, user | `reason`, `reason_changed_at`, `reason_set_by_user` | yes | yes | yes (user key written through a custom parser, same key) | confirmed |
| Node partitions | `partitions` (comma-separated string type in the parser) | yes | yes | yes | confirmed |
| Node reservation name | `reservation` | yes | yes | yes | confirmed |
| Reservation name, flags, nodes, start, end | `name`, `flags`, `node_list`, `start_time`, `end_time` | yes | yes | yes | confirmed |

Whether a key is a number, a string, or an object with `set`, `infinite`, and `number` members (the parser uses that shape for values that can be unset) is not shown by the lines above; the exact JSON types need a captured response (*live*).

## Counting rules the source fixes

| Question | Answer | Evidence | Status |
|---|---|---|---|
| Does a node in several partitions repeat in `sinfo -N`? | yes, one line per node and partition pair; with `--partition`, one line per node in it | [sinfo man page](https://slurm.schedmd.com/sinfo.html) | confirmed (docs) |
| How does a pending array appear in `squeue` format output? | pending elements are combined on one line unless `-r` is given | [squeue man page](https://slurm.schedmd.com/squeue.html) | confirmed (docs) |
| How does the metrics plugin count pending array tasks? | adds `job_array_cnt` per record; held means reason `JobHeldAdmin` or `JobHeldUser` | [statistics.c lines 305 to 311](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/statistics.c#L305-L311) | confirmed |
| Is a held job pending? | yes (state PENDING, reason `JobHeldAdmin` or `JobHeldUser`) | same lines; [metrics guide](https://slurm.schedmd.com/metrics.html) | confirmed |
| How is a pending job submitted to several partitions shown? | not established; `%P` is documented as "Partition of the job" and the JSON `partition` key is a single string | [squeue](https://slurm.schedmd.com/squeue.html) | unverified, *live* |
| How many records does `squeue --json` give for a pending array? | one record with `array_task_string`, inferred from the parser keys | `parsers.c` | unverified, *live* |

Counting rule for the plan: a pending array contributes its task count, taken from the array expression, not one. If format output is used, pass `-r`, which costs one line per task.

## Delimiters and free text

The man pages define `sinfo -o` and `squeue -o` field codes but do not list which fields may contain the delimiter. What the source shows:

- `sinfo %E` (node reason) is free text set by an administrator ([sinfo man page](https://slurm.schedmd.com/sinfo.html): "the reason a node is unavailable"). Sanitization of that text when it is set was not found in the files read. Treat it as able to contain the delimiter, spaces, and quotes.
- Job pending reasons, job states, and partition names come from fixed lists or configuration; Slurm's partition naming rules were not read, so a delimiter in a partition name is not ruled out (*live*).
- `sinfo %C` is `allocated/idle/other/total` and the man page says not to combine it with a node-state field, because nodes then split by state.

Rule: where free text is needed (node reason), use JSON; where only counts are needed, format output with a delimiter that cannot occur in the fixed-list fields is acceptable, with the reason column last and the line split with a field limit.

## Parsing rule per command (proposed for the plan)

| Command | Form | Parser | Failure mode for an unknown field |
|---|---|---|---|
| `squeue` | JSON pinned to the chosen parser, or `-h -r -o '%T\|%r\|%P'` style format output when measurement shows JSON is too costly | explicit parser name, never unpinned | ignore unknown keys (the REST guide says clients must ignore unknown fields); an unknown state or reason maps to Other with the raw string kept |
| `sinfo -N` | JSON for node reason text; format output for counts | same | an unknown state flag is kept in the raw state and the normalized state falls back to Other |
| `scontrol show reservation` | JSON, pinned | same | a flag the plugin does not know is ignored; recurring or floating reservations follow [slurm-reservations.md](slurm-reservations.md) |
| `sshare` | JSON, pinned, only when the plugin is configured for fair-share | same | a missing field turns the series to `U`, not 0 |

## Limits

No output was captured. Payload size and run time of JSON versus format output at 10,000 and 100,000 jobs, the exact JSON value types, the multi-partition pending-job shape, and the delimiter question for partition names need a cluster. The man pages say only that `--json` dumps all information.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #16 | On each supported release, capture `squeue --json=<parser>`, `sinfo --json=<parser>`, `scontrol --json=<parser> show node`, `sshare --json=<parser>` on an idle, a busy, and a drained fixture cluster | the fixture set and exact JSON types |
| #16 | Submit one job to two partitions, one array of 100 tasks, and one held job; capture JSON and `-r` and non-`-r` format output | multi-partition and array counting rules |
| #16 | Time and size `--json` versus `-o` output at 10,000 and 100,000 jobs on simulated nodes | the format-output versus JSON choice |
| #16 | Set a node reason containing `\|`, a newline, and a quote; capture `sinfo -N -o` and JSON | whether `%E` is safe in delimited output |
| #16 | Create a partition named with each character Slurm allows | whether partition names can contain the delimiter |
| #16 | Run a newer client against an older daemon and the reverse, with an explicit parser | what a mismatched pairing prints |
