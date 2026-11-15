# Cacti 1.2 HPC Scheduler Plugin Plan

Status: in development, plan revision 2 (2026-11-15). Revision 2 adds the documentation and source results of milestone 1 ([research index](#milestone-1-research-status)); items that need a cluster, accounts, or a remote collector are marked *live* and are not done. Milestone 2 (foundation) is merged on `main`: the lifecycle and PHP guard, realms and settings, the cluster registry, both Slurm transports, the per-poller reader, and the overview and health pages; where the build differs from this plan, the text says "Milestone 2 diverges". Milestone 3 (cluster graphs and pending reasons) adds the templates, the cluster device, the cluster and partition graphs, the pending reasons view, device permissions, and synthetic performance measurements; where it differs from this plan, the text says so. Milestone 4 and later are not done: Thold has not been tested end to end, no Maint integration exists, and there is no release. Statements about Slurm, Cacti core, Maint, and Thold describe upstream behavior and cite the document or source file that was checked, with the checked version or commit. Anything not checked is listed as an assumption and tracked as a research issue. Owner and estimates are not assigned; milestone 1 produces the estimates.

`plugin_hpcsched` brings the state of an HPC batch scheduler into Cacti 1.2: queue depth, pending versus running jobs, why jobs are pending, node states, and partition utilization, as ordinary Cacti devices, data sources, and graphs. Slurm is the first backend. PBS Pro, OpenPBS, and LSF are later backends behind the same interface. The plugin is read-only toward the scheduler.

## Why this plugin

Cluster administrators who run Cacti already graph the nodes: CPU, memory, network, temperature, and power. The scheduler's own view is missing from those graphs, so two questions need two tools:

1. **Why is work not starting?** A queue can grow because resources are busy, because association or QOS limits are reached, because jobs are held, because of a dependency, or because nodes are drained. Slurm records a reason per pending job. Without it next to the node graphs, an operator sees full queues and idle nodes and cannot tell which is the cause.
2. **Which nodes are out of service on purpose?** Drained nodes and maintenance reservations are planned absences. Thold does not know that, so it alerts on devices an administrator has already taken out of the queue, and administrators then create Maint schedules by hand.

The Cacti organization hosts LSF-related plugins (`plugin_lsflog`, `plugin_cjobs`, `plugin_gridgmgmt`), and no Slurm plugin was found there (checked 2026-11-14). Milestone 1 read all three: they attach to LSF RTM's `grid` plugin and tables, none graphs queue or node state, `plugin_cjobs` kills jobs (a write path this plugin does not have), and `plugin_gridgmgmt` is an empty repository. No overlap with the Slurm scope, and none that argues for a different name ([prior-art.md](research/prior-art.md)).

The plugin closes these gaps by collecting one cached snapshot per cluster, graphing it through Cacti's own graph engine, and publishing values as polled data sources so Thold works unchanged. It maps scheduler nodes onto the Cacti devices that already exist, and it can place drained nodes and maintenance reservations into Maint. It reuses Cacti's permissions, remote data collectors, Thold, and Maint rather than building parallel versions.

## Goals and non-goals

**Goals**

- Cluster, partition, and node-state graphs that match the scheduler's own counts, rendered by Cacti.
- A pending reason breakdown that stays within a bounded number of series.
- Read-only operation. The plugin cannot submit, cancel, hold, drain, resume, or reconfigure anything.
- Explicit, reviewable mapping of scheduler nodes to existing Cacti devices.
- Alerting and maintenance through Thold and Maint, so one notification path covers SNMP and scheduler data.
- A controller load that does not grow with the number of Cacti series.
- No required Cacti core patches.

**Non-goals**

- Job submission, job control, node control, or any scheduler write path.
- A plugin-owned incident, escalation, or notification engine.
- A reporting or chargeback engine.
- Per-user or per-job series.
- Replacing the scheduler's own accounting, `sreport`, or dashboards.
- A public HTTP API or CLI in the first releases.

## Release phases

Each phase ships to users. Later phases are re-estimated after the phase before them.

| Phase | Label | Scope | Depends on |
|---|---|---|---|
| 0 | Research | Slurm release matrix, upstream behavior confirmation, controller load, node naming fixtures, prior art (milestone 1) | Nothing |
| 1 | Planned | Cluster graphs for Slurm: queue depth, pending and running jobs, pending reasons, node states, partition utilization, Thold alerting, health page (milestones 2 to 4) | Phase 0 |
| 2 | Planned | Node-to-device mapping, per-node state, automatic Maint windows for drained nodes and maintenance reservations (milestone 5) | Phase 1, security review |
| 3 | Planned | Fair-share usage, bounded accounting statistics, remote data collectors (milestone 6) | Phase 2, security review |
| - | Research | OpenMetrics transport, PBS Pro and OpenPBS backend, LSF backend, GPU and TRES utilization, federation, per-job views | Demand after phase 1 |

## Compatibility

**Cacti.** Minimum Cacti release is 1.2.31, with no core patches. The plugin uses only APIs present in that release (all checked in the `release/1.2.31` tag, commit `1e8eaca`):

- Plugin lifecycle and hooks: `api_plugin_register_hook()`, `api_plugin_register_realm()`, `api_plugin_db_table_create()`, `api_plugin_db_add_column()` ([lib/plugins.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/plugins.php)).
- Devices and permissions: `api_device_save()` ([lib/api_device.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/api_device.php)), `is_device_allowed()` and `get_allowed_devices()` ([lib/auth.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/auth.php)).
- Templates: `import_xml_data()` ([lib/import.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/import.php)).
- Background work: `exec_background()` and `register_process_start()` ([lib/poller.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/poller.php)).
- Remote collectors: `call_remote_data_collector()` ([lib/functions.php](https://github.com/Cacti/cacti/blob/release/1.2.31/lib/functions.php)) and the `remote_agent` hook ([remote_agent.php](https://github.com/Cacti/cacti/blob/release/1.2.31/remote_agent.php)).

Milestone 1 re-checked each signature at `release/1.2.31` and at `1.2.x` commit `e2d5c25` (2026-11-11): all match ([cacti-core.md](research/cacti-core.md)). Differences that matter:

- `api_plugin_register_hook()` and `api_plugin_register_realm()` work only when the calling function's name contains `install`, `upgrade`, or `setup`, so call them from `plugin_hpcsched_install()` and `_upgrade()` directly, not from a class method.
- `api_plugin_db_table_create()` skips an existing table on 1.2.31 and refreshes its schema on `1.2.x`; migrations do not depend on either.
- `exec_background()` takes one executable path (the PHP binary) and an argument array; `1.2.x` shell-quotes the path, 1.2.31 does not; it reports no error when it cannot start. `register_process_start()` records the calling process, so the reader child calls it; it kills a holder past its timeout and returns true without mutual exclusion when the `processes` table is missing.

**PHP.** The plugin requires PHP 8.2 or later, matching the Cacti 1.2.x floor. The `1.2.x` branch declares `"php": ">=8.2"` and pins Composer resolution to `platform.php: 8.2.0` ([branch composer.json](https://github.com/Cacti/cacti/blob/1.2.x/composer.json), [Cacti/cacti#8569](https://github.com/Cacti/cacti/pull/8569)). Release 1.2.31 declares `"php": ">=8.0"` ([release composer.json](https://github.com/Cacti/cacti/blob/release/1.2.31/composer.json)), so a 1.2.31 site can run PHP 8.0 or 8.1. Because Cacti includes plugin files on every request, a parse error in a plugin breaks the whole Cacti UI. Therefore ([ADR 0007](decisions/0007-php-82-floor-with-80-parse-guard.md)):

- `setup.php`, `INFO`, and every file Cacti includes before the version check use only PHP 8.0 syntax.
- `plugin_hpcsched_check_config()` and `plugin_hpcsched_install()` refuse to proceed when `version_compare(PHP_VERSION, '8.2.0', '<')`, naming the required version.
- Hook shims return early under the same condition, before the autoloader is registered.
- CI lints `setup.php` and the shims under PHP 8.0.

Code loaded after the check may use PHP language features through 8.2: `readonly` classes, enums, first-class callable syntax, DNF types, and the standalone `null`, `true`, and `false` types. Features from 8.3 and later are not used. Properties are always declared, because PHP 8.2 deprecates dynamic properties. PHPStan runs at `phpVersion` 8.2.

**Slurm.** Slurm 26.05 is the current release in SchedMD's documentation as checked on 2026-11-14, and its release notes state that it supports upgrading directly from 25.11, 25.05, and 24.11 ([release notes](https://slurm.schedmd.com/news.html)). Which releases the plugin supports is an open decision; the proposal is the three most recent. The REST data parser lifecycle constrains the choice ([REST client guide](https://slurm.schedmd.com/rest_clients.html#data_parser_lifecycle)):

| Data parser | Added | Scheduled for removal |
|---|---|---|
| v0.0.40 | 23.11 | 25.11 |
| v0.0.41 | 24.05 | 26.05 |
| v0.0.42 | 24.11 | 26.11 |
| v0.0.43 | 25.05 | 27.05 |
| v0.0.44 | 25.11 | 27.11 |
| v0.0.45 | 26.05 | 28.05 |

The guide states that a parser's content stays the same between releases and that a new version is added for each major release. Milestone 1 read the plugin directories at the newest tag of each release (24.11.7, 25.05.9, 25.11.9, 26.05.6) and the 26.11 release candidate: the table above matches source ([slurm-releases.md](research/slurm-releases.md)). Two corrections follow. `v0.0.42` is present in 24.11 to 26.05 but already absent from the 26.11 candidate, so the parser common to 25.05, 25.11, 26.05, and 26.11 is `v0.0.43` (removal scheduled 27.05); the plugin pins `v0.0.43` if 25.05 stays supported. The 26.11 candidate adds `v0.0.46`, which the lifecycle table does not list yet. All five client commands accept `--json` from 23.11 to 26.05, and `--json=list` prints the parsers a client has; an unpinned `--json` follows the client's own default, so the plugin always names a parser ([slurm-client-output.md](research/slurm-client-output.md)).

SchedMD's upgrade page gives end-of-support dates of May 2026 (24.11), November 2026 (25.05), May 2027 (25.11), and, for 26.05, "November 2028 (18 months)", which contradicts its own 18-month rule and is unverified. Distribution packages lag: Enterprise Linux 9 carries 22.05, Ubuntu 24.04 carries 23.11, Debian 13 carries 24.11, Enterprise Linux 10 and OpenHPC 4.2 carry 26.05. For those sites the format-output path is the only one that does not depend on a parser version; whether `--json` works at all on 22.05 was not checked. Format output with `%T` cannot show DOWN together with DRAIN (it prints DRAINED); a JSON-free source that can is `scontrol show node`, whose `State=` field is built from the base state plus every flag (for example `DOWN+DRAIN`, from `node_state_string_complete()` in `src/api/node_info.c`), so the fallback parses that text and the DOWN-precedence rule below still applies.

**Plugin metadata.** `INFO` declares `compat = 1.2.31`. Its `capabilities` line must include whatever the plugin's hooks need on remote pollers. In `lib/plugins.php` at 1.2.31, the table of hooks for remote pollers lists `poller_bottom` as requiring `remote_poller` and `poller_output` as requiring `remote_collect`, so the capability set is settled by milestone 1: `capabilities = online_view:1, online_mgmt:1, offline_view:0, offline_mgmt:0, remote_collect:0, remote_poller:1`. `remote_poller:1` lets `poller_bottom` run the reader on a remote collector; `remote_collect:1` is needed only for `poller_output` and `poller_top`, which the plugin does not hook. **Milestone 2 diverges:** `INFO` declares `remote_collect:1` as well, as plugin_pfs does. It changes nothing for the hooks registered (core gates `poller_bottom` on `remote_poller` alone at 1.2.31 and 1.2.x) and states the collector role either way; it can return to 0 if a later hook makes the difference matter. The `remote_agent` and `replicate_out` hooks are not capability-gated. Whether a collector runs the hook as described is *live* ([cacti-core.md](research/cacti-core.md)). `INFO` names the project's own homepage and author; it does not take its fields from other plugins.

## Upstream behavior the design depends on

Each row is a milestone 1 test, because a change in any of them changes the design. "Checked" means the source was read on 2026-11-14 or 2026-11-15 at the version shown. Slurm documentation was read from slurm.schedmd.com, which presents release 26.05; Slurm source was read at release tags (see [slurm-releases.md](research/slurm-releases.md)). Revision 2 adds a **Milestone 1 result** note after each table. No row is a test result for a running Slurm release; what needs a cluster is marked *live* in the research files.

**Slurm client commands**

| Fact | Consequence | Source |
|---|---|---|
| `sinfo`, `sacct`, and `sshare` each send an RPC (to `slurmctld`, or `slurmdbd` for `sacct`). Enough concurrent calls can degrade or deny service, and the man pages say not to run them from loops in programs and to keep calls to the minimum necessary. | One snapshot per cluster per cycle, with a fixed set of calls ([ADR 0003](decisions/0003-collect-one-cached-snapshot-per-cluster.md)). | [sinfo](https://slurm.schedmd.com/sinfo.html), [sacct](https://slurm.schedmd.com/sacct.html), [sshare](https://slurm.schedmd.com/sshare.html) |
| `squeue`, `sinfo`, `scontrol`, `sacct`, and `sshare` accept `--json`, `--json=list`, and `--json=<data_parser>`. JSON dumps all information; sorting and format options are ignored; most filters still apply. | Output can be pinned to a data parser. JSON carries more than the plugin needs, so its cost must be measured against format output. | man pages for each command |
| `squeue` shows pending, running, and completing jobs by default. `--only-job-state` queries a reduced RPC. `-o` format codes include `%T` (state), `%r` (reason), `%P` (partition), `%a` (account). | A light format-output path exists for job counts. | [squeue](https://slurm.schedmd.com/squeue.html) |
| `sinfo` format codes include `%T` (state), `%E` (node reason), `%H` (reason timestamp), `%u` (user who set it), `%N` (node names), `%n` (hostnames), `%o` (addresses), `%C` (CPUs as allocated/idle/other/total), `%R` (partition name), `%P` (partition name with `*` for the default). | Per-node and per-partition data can come from one `sinfo -N` call; `%P` must have its `*` stripped. | [sinfo](https://slurm.schedmd.com/sinfo.html) |
| Node states include ALLOCATED, MIXED, IDLE, DOWN, DRAINING, DRAINED, FAIL, FAILING, COMPLETING, MAINT, RESERVED, PLANNED, POWERED_DOWN, POWERING_UP, POWERING_DOWN, FUTURE, UNKNOWN, INVAL, BLOCKED, and reboot states, with suffix characters for not responding (`*`), powered off (`~`), maintenance reservation (`$`), and others. | The normalized state enum needs a fallback for unknown states. | [sinfo, node state codes](https://slurm.schedmd.com/sinfo.html) |
| The reason list is long, includes wildcard groups (`AssocGrp*`, `AssocMax*`, `QOSGrp*`, `QOSMax*`, `Max*PerAccount`), and a pending job shows only the reason found by the scheduling method that last examined it. | Reasons are grouped into families ([ADR 0010](decisions/0010-bound-series-cardinality.md)). | [job reason codes](https://slurm.schedmd.com/job_reason_codes.html), [squeue](https://slurm.schedmd.com/squeue.html) |
| `sshare` works only with the `priority/multifactor` plugin and reports `RawShares`, `NormShares`, `RawUsage`, `EffectvUsage`, and `FairShare`; with Fair Tree it adds `LevelFS` under `-l`. It sends an RPC to `slurmctld`. | Fair-share needs a "not available" state. | [sshare](https://slurm.schedmd.com/sshare.html) |
| `sacct` accepts `--starttime`, `--endtime`, `-X`, `--parsable2`, and `--json`; its default start and end depend on the other options given. | Every accounting query sets explicit bounds. | [sacct](https://slurm.schedmd.com/sacct.html) |
| `scontrol show hostnames` expands a hostlist expression to one name per line. | The plugin can avoid parsing hostlists in PHP. | [scontrol](https://slurm.schedmd.com/scontrol.html) |
| The `MAINT` reservation flag is "Maintenance mode, receives special accounting treatment" and lets a reservation use resources already in another reservation. Nodes in such a reservation show the MAINT state or `$` suffix. | A `MAINT` reservation is the explicit signal of planned maintenance. | [scontrol](https://slurm.schedmd.com/scontrol.html), [sinfo](https://slurm.schedmd.com/sinfo.html) |

**Milestone 1 result (Slurm client commands).**

- JSON options: confirmed for all five commands, 23.11 to 26.05. `sinfo --json` returns a sinfo-specific shape, not the nodes endpoint's; `squeue --only-job-state --json` returns only job id, array ids, and state, so it cannot give partition or reason ([slurm-client-output.md](research/slurm-client-output.md)).
- `%T` loses information: a node that is DOWN and drained prints DRAINED; MAINT replaces the base state on idle nodes. The plugin reads the JSON `state` array for the drain flag, the maintenance flag, and DOWN together with DRAIN ([slurm-states-reasons.md](research/slurm-states-reasons.md)).
- `squeue` combines pending array elements on one line unless `-r` is given; a pending array counts as its task count.
- Reasons: 218 enum entries and 216 distinct strings at 26.05; a prefix rule maps all of them to the eleven families below. The documentation page does not list 23 strings present in source by name; its wildcard `Max*PerAccount` covers nine of them (`Max*RunMinsPerAccount`) and no wildcard covers the other 14 (including the nine `Max*RunMinsPerUser`). Two enum values share the string `AssocMaxBBMinutesPerJob`. Match on the source list and prefix rules, not on the page.
- `sshare` `LevelFS` is present in source at 24.11, 25.05, 25.11, and 26.05; Fair Tree is the default priority flag ([accounting.md](research/accounting.md)).
- The reservation record of a recurring reservation always carries its current or next window; floating reservations never repeat; the node MAINT flag is set only while a `MAINT` reservation is active ([slurm-reservations.md](research/slurm-reservations.md)).
- Still *live*: captured fixtures per release, JSON value types, multi-partition pending-job shape, delimiter safety of node reasons, and JSON versus format-output cost at 10,000 and 100,000 jobs.

**Slurm configuration and visibility**

| Fact | Consequence | Source |
|---|---|---|
| `NodeName` may be an arbitrary string when `NodeHostname` is set; `NodeHostname` defaults to `NodeName`; `NodeAddr` defaults to `NodeHostname` and may be an IP address. | Matching needs several keys ([ADR 0005](decisions/0005-map-nodes-to-existing-devices.md)). | [slurm.conf](https://slurm.schedmd.com/slurm.conf.html) (node configuration) |
| `PrivateData` can hide jobs, nodes, partitions, reservations, usage, and accounts from regular users; `SlurmUser` and root always see everything. `scontrol show node` and `show partition` require Privileged and `show reservation` requires Privileged or Access when `PrivateData` restricts them. | The plugin's Slurm account may see less than it needs. The account recipe and health page must say what is hidden. | [slurm.conf](https://slurm.schedmd.com/slurm.conf.html), [scontrol](https://slurm.schedmd.com/scontrol.html) |
| Per-user RPC rate limiting is enabled by `SlurmctldParameters=rl_enable`, with defaults `rl_bucket_size=30`, `rl_refill_rate=2`, `rl_refill_period=1`. It was added in 23.02. Clients are told to back off. | The plugin must back off when told to, and its call count must stay far below the refill rate. | [slurm.conf](https://slurm.schedmd.com/slurm.conf.html), [high throughput guide](https://slurm.schedmd.com/high_throughput.html) |
| `MaxJobCount` defaults to 10000 jobs in memory, and completed jobs stay for `MinJobAge` (default 300 seconds). | The queue the plugin sees is bounded by the controller's memory settings. | [slurm.conf](https://slurm.schedmd.com/slurm.conf.html) |
| Slurm 25.11 added `MetricsType=metrics/openmetrics`, serving `/metrics/jobs`, `/metrics/nodes`, `/metrics/partitions`, `/metrics/scheduler`, and `/metrics/jobs-users-accts` from `slurmctld`. The guide advises 60 to 120 second scrape intervals and warns against storing the user and account endpoint. | A cheaper source for counts exists on 25.11 and later. Milestone 1 confirmed from source that it has no pending reason metric (only one per-partition counter for the node-limit reason), so it cannot replace the reader; it stays research ([controller-load.md](research/controller-load.md)). | [metrics guide](https://slurm.schedmd.com/metrics.html) |

**slurmrestd**

| Fact | Consequence | Source |
|---|---|---|
| `slurmrestd` is stateless and synchronous with the controller. The guide strongly encourages a caching proxy between it and clients. `--max-connections` defaults to 124, and each connection can cause one RPC. | The plugin calls it once per cycle and respects timeouts. | [REST API details](https://slurm.schedmd.com/rest.html), [slurmrestd](https://slurm.schedmd.com/slurmrestd.html) |
| JWT authentication uses the headers `X-SLURM-USER-NAME` and `X-SLURM-USER-TOKEN`, needs `AuthAltTypes=auth/jwt` in `slurm.conf` and `slurmdbd.conf`, and tokens from `scontrol token` last 1800 seconds unless `lifespan=` is given. | Token rotation is an operator task. | [JWT](https://slurm.schedmd.com/jwt.html), [REST quick start](https://slurm.schedmd.com/rest_quickstart.html) |
| "The power to create JWTs is the power of root on a cluster." `slurmrestd` must not run as root or `SlurmUser`. It listens on TCP (`:6820` in the quick start) or UNIX sockets. | The plugin never creates tokens ([ADR 0009](decisions/0009-credentials-by-file-or-munge-never-minted.md)). | [JWT](https://slurm.schedmd.com/jwt.html), [slurmrestd](https://slurm.schedmd.com/slurmrestd.html) |
| The API is not designed to be internet facing. The REST details page says only unencrypted HTTP is supported and recommends a TLS-wrapping proxy, while the slurmrestd man page describes native TLS through the `tls/s2n` plugin and `https://` listeners. | The two pages disagree. Source and the man page show native TLS from 25.05 (with the optional s2n library at build time); the REST details page is out of date. The plugin verifies TLS where used and documents network placement. | [REST API details](https://slurm.schedmd.com/rest.html), [slurmrestd](https://slurm.schedmd.com/slurmrestd.html) |
| Each cluster needs its own `slurmrestd`. | One cluster per endpoint setting. | [REST quick start](https://slurm.schedmd.com/rest_quickstart.html) |
| `GET /slurm/{version}/nodes` takes `update_time` and returns 304 with an empty body when nothing changed; other endpoints that accept `update_time` return 200 with a warning. **Milestone 1:** the 304 exists in source only from 26.05; on 24.11, 25.05, and 25.11 the nodes handler has no such branch, and jobs returns 200 with a warning at all releases checked. | The transport must not treat a 304 as an empty result, and the first release does not use `update_time`. | [REST API details](https://slurm.schedmd.com/rest.html), [slurmrestd.md](research/slurmrestd.md) |
| The v0.0.45 reference lists `GET` on `/slurm/v0.0.45/jobs/`, `jobs/state/`, `nodes/`, `partitions/`, `reservations/`, `shares`, `diag/`, `ping/`, and `GET /slurmdb/v0.0.45/jobs/`, and also lists POST and DELETE endpoints that change state. | **Milestone 1:** the reference also lists two GET routes that change state: `GET /slurm/v0.0.NN/reconfigure/` (calls `slurm_reconfigure()`, at all releases checked) and, on 26.05, `GET /slurm/v0.0.NN/job/{job_id}/requeue`. A method rule is not enough, so the plugin uses an exact path-and-method allowlist (the nine paths named in this row, GET only, parser fixed by configuration) and refuses everything else in code. A token for a user who can modify state is more power than the plugin needs. | [REST API reference](https://slurm.schedmd.com/rest_api.html), [slurmrestd.md](research/slurmrestd.md) |

**Cacti core 1.2.31**

| Fact | Consequence | Source |
|---|---|---|
| `exec_background()` joins an argument array after escaping each element with `cacti_escapeshellarg()`; a string argument has `&`, `;`, and `\|` stripped. `register_process_start()` registers a process by type, name, and id, and refuses when a live process holds it. **Milestone 1:** it records the calling process, kills a holder past its timeout, and returns true when the `processes` table is missing; `exec_background()` takes one executable path and reports no error when it cannot start. | One reader per cluster, started with an argument array from `poller_bottom`; the reader child calls `register_process_start()` with a timeout above its time budget. | `lib/poller.php`, [cacti-core.md](research/cacti-core.md) |
| `api_plugin_run_plugin_hook()` on a poller with `poller_id` above 1 runs a hook only when the plugin's `INFO` capabilities satisfy the hook's required capabilities. `poller_bottom` requires `remote_poller:1`; `poller_output` requires `remote_collect:1`. | **Milestone 1: confirmed in source.** A plugin declaring only `remote_collect:1` does not run `poller_bottom` on a remote collector. The plugin declares `remote_poller:1` and not `remote_collect:1` (see Plugin metadata). Hooks called through `api_plugin_hook_function`, including `remote_agent`, `replicate_out`, `device_remove`, and `api_device_save`, are not gated. Collector behavior is *live*. | `lib/plugins.php` |
| `remote_agent.php` dispatches unknown actions to the `remote_agent` plugin hook. Callers are authorized when their address matches an enabled poller's IP, or a poller hostname whose reverse and forward DNS both match the caller; at least two enabled pollers must exist. **Milestone 1:** on `1.2.x` the address is the TCP peer (`REMOTE_ADDR`), not a forwarded header, so a proxy must be registered as the poller. | Plugin actions on a collector use Cacti's existing, address-based authorization. | `remote_agent.php`, [cacti-core.md](research/cacti-core.md) |
| `call_remote_data_collector()` accepts only a relative path beginning with `/` and refuses `://`, `@`, and `../`. | Plugin actions are plain relative requests. | `lib/functions.php` |
| On a poller with `poller_id` above 1, `include/global.php` sets the connection mode to `recovery` while the local `poller_output_boost` table holds rows, `offline` when the main database cannot be reached, and `online` otherwise; web requests use the main database when online. | Remote jobs must state which database they write and work in both modes. | `include/global.php` |
| `api_device_save()` takes `$poller_id`, `$site_id`, and `$external_id`; the `host` table has `external_id varchar(40)` with an index. `AVAIL_NONE` is 0. **Milestone 1:** a device template has no availability field (`host_template` holds id, hash, name, class); availability is `host.availability_method`, set per device. `external_id` is not unique. | The cluster device is saved with `availability_method` 0 through `api_device_save()` and a `poller_id`. `external_id` is a candidate matching key but is short and not unique. | `lib/api_device.php`, `cacti.sql`, `include/global_constants.php` |
| Script server data queries are defined by XML in `resource/script_server/` with `\|path_cacti\|` script paths. | Query XML ships with the plugin and is installed with it. **Milestone 1:** the index and query steps run the script as a CLI process; only polling uses the persistent script server, so the script needs both entry points. A removed index marks its data sources orphaned and deletes them only if the query XML sets `index_orphan_removal`; new indexes appear on reindex. | `resource/script_server/host_disk.xml`, [cacti-core.md](research/cacti-core.md) |
| `device_remove` and `api_device_save` hooks run when devices are removed or saved. | Mapping consistency hooks exist. | `lib/api_device.php` |

**Maint and Thold** (plugin_maint `develop` at `9422c7a`, plugin_thold `develop` at `dc76952`)

| Fact | Consequence | Source |
|---|---|---|
| Maint stores schedules in `plugin_maint_schedules` (`enabled`, `name`, `mtype`, `stime`, `etime`, `minterval`) and device assignments in `plugin_maint_hosts` (`type`, `host`, `schedule`). Type 1 is a Cacti device. `mtype` 1 is one-time; 2 is recurring with a whole-day `minterval`. | Plugin-owned one-time schedules are expressible as plain rows. | [plugin_maint includes](https://github.com/Cacti/plugin_maint/blob/9422c7a4ce9916d0f0edf88b93afb194e0675b4a/includes/functions.php) |
| `plugin_maint_check_host()` matches `plugin_maint_hosts` rows for the type and the device id, or `host = 0`, then evaluates each schedule against `stime <= now < etime`. | An end time in the near future makes suppression lapse by default. | same |
| Thold calls `plugin_maint_check_cacti_host()` directly, guarded by `api_plugin_is_enabled('maint')` and `function_exists()`. It does not call the `is_device_in_maintenance` hook that Maint registers. | A second plugin cannot join suppression through that hook. **Milestone 1:** core calls the same function directly from `lib/snmpagent.php` (no caller of the hook exists in core at either ref); released Thold 1.8.2 calls it without the `function_exists()` guard; the check queries the database on every call with no cache, requires `enabled = 'on'`, and rewrites recurring (`mtype` 2) rows, so a plugin owns only `mtype` 1 rows ([maint-thold.md](research/maint-thold.md)). | plugin_thold `includes/polling.php` (lines 640 and 783) and `includes/functions.php` (line 3432) |
| Maint has no function for creating a schedule; its pages write the rows. | Automatic maintenance is either direct row writes or an upstream Maint change ([ADR 0008](decisions/0008-maintenance-through-owned-expiring-schedules.md)). | plugin_maint `maint.php` |
| Maint declares `remote_collect:0`; Thold declares `remote_collect:1`. | **Milestone 1:** neither plugin registers `replicate_out`, and core replicates only `plugin_config`, `plugin_hooks`, and `plugin_realms`, so no code read puts Maint's tables on a collector. Suppression for a Thold run on a collector is expected not to work and is *live*. | plugin `INFO` files, [maint-thold.md](research/maint-thold.md) |

**Assumptions to confirm in milestone 1.** These were not facts at revision 1. Status at revision 2:

- JSON field names and types for jobs, nodes, partitions, and reservations on each release, and how job state, node state, and flags are represented. *Names confirmed in source; types and captured output are live.*
- That `--json=<data_parser>` accepts the form the plugin will use on each command and release. *Confirmed in source for all five commands, 23.11 to 26.05.*
- That a node in several partitions repeats in per-node `sinfo` output, and how to count unique nodes. *Confirmed (docs); count by node name or use the nodes response.*
- Which Slurm releases support native TLS in `slurmrestd`. *25.05 and later, when built with s2n.*
- Payload size and run time of `--json` versus format output at 100,000 jobs. *Live.*
- What a token for a Slurm user with no association can and cannot do through `slurmrestd`. *Live.*
- `scontrol show reservation` output fields and how a recurring reservation reports its next window. *Next window confirmed in source; captured output is live.*
- Controller cost per call at the planned job and node counts. *Live; RPC counts per cycle are known.*
- `sacct` load on `slurmdbd` for bounded windows. *Live.*
- Whether the metrics plugin exposes pending reasons. *No (source).*
- Behavior of the plugin's `INFO` capabilities on a remote collector. *Settled in source; collector run is live.*
- Whether Maint's tables exist and are current on a remote collector. *No code replicates them; live.*
- Everything about PBS Pro, OpenPBS, and LSF output and authentication. *Not part of this pass.*

## Architecture

```mermaid
flowchart TB
    subgraph Main["Cacti main server"]
        UI[Plugin pages] --> PERM[Cacti realms and device permissions]
        GR[Cacti graphs] --> PERM
        DB[(Cacti database: devices, plugin tables, snapshot)]
        THOLD[Thold and Maint]
    end
    subgraph Poller["Cacti poller assigned to the cluster: main server or remote collector"]
        PB[poller_bottom] --> RD[Reader process]
        RD --> BE[Backend interface]
        BE --> T1[Client command transport]
        BE --> T2[slurmrestd transport]
        RD --> SNAP[(Snapshot tables)]
        SS[Script server inputs] --> SNAP
        RD --> MW[Maintenance writer, phase 2]
    end
    subgraph SL["Slurm cluster"]
        CTLD[slurmctld]
        REST[slurmrestd]
    end
    T1 -->|squeue, sinfo, scontrol, sshare| CTLD
    T2 -->|HTTP GET| REST --> CTLD
    SS -->|poller_output| THOLD
    MW -->|owned schedules| THOLD
    GR --> DB
```

The scheduler is reached only from the poller assigned to the cluster. Pages and graphs never call it.

| Component | Responsibility | Interfaces | Failure behavior |
|---|---|---|---|
| Reader | Collect one snapshot per cycle and store it | `poller_bottom`, `exec_background()`, `register_process_start()`, filtered by cluster and poller | Single instance per cluster; time budget; interrupted run leaves the previous snapshot |
| Backend | Turn one scheduler's output into the normalized snapshot | Backend interface ([ADR 0004](decisions/0004-scheduler-backend-interface.md)) | A failed section is marked failed, never empty |
| Transports | Fetch raw data from the scheduler | Argument-array process execution; HTTP GET to an allowlist | Timeouts, size limits, no shell, no writes |
| Snapshot tables | Hold the latest normalized snapshot and its ages | Prepared queries through repositories | Previous snapshot stays until a new one is complete |
| Script server inputs | Return values for data queries and Thold | Cacti script server | `U` when stale; `age` always numeric |
| Plugin pages | Clusters, mapping, pending reasons, health | Cacti page framework, csrf-magic, realms | Show the snapshot with its age; never call the scheduler |
| Maintenance writer (phase 2) | Create, extend, and end plugin-owned Maint schedules | Maint tables or a Maint function | Lets schedules expire when collection stops |

## Collection

One reader per cluster makes a fixed set of calls per interval and writes one snapshot ([ADR 0003](decisions/0003-collect-one-cached-snapshot-per-cluster.md)). The proposed set for phase 1:

1. Jobs: counts by state, partition, and reason, from `squeue` or the jobs endpoint.
2. Nodes and partitions: per-node state, reason, and partitions, from `sinfo` or the nodes and partitions endpoints.
3. Reservations (phase 2): from `scontrol show reservation` or the reservations endpoint.
4. Shares (phase 3): from `sshare` or the shares endpoint.

The number of calls does not depend on job, node, or series counts. Through `slurmrestd`, phase 1 is four controller RPCs per cycle (jobs; nodes, which makes two; partitions), phase 2 five, phase 3 six, from the handlers' source ([controller-load.md](research/controller-load.md)). At a 60 second interval that is 4 to 5 RPCs per minute in phases 1 and 2. That is far below the documented per-user token refill of 2 per second, but the cost of each call, which grows with the number of jobs and nodes, is what milestone 1 measures. The interval has a floor chosen from those measurements. Until the load test (*live*) replaces them, the provisional values are a 60 second default, a 30 second floor, and a time budget of half the interval; they are starting points, not findings. **Milestone 2:** the effective interval is the larger of the cluster's interval and the Cacti poller interval, since the reader starts only from `poller_bottom`; the budget is half the effective interval; after a rate limit the next attempt waits twice as long each time, up to 600 seconds.

**Transports.** The Slurm backend separates fetching from parsing.

- *Client commands.* The reader runs `squeue`, `sinfo`, `scontrol`, and `sshare` with argument arrays (no shell), a fixed option set, a timeout, and an output size limit. It relies on the poller user's Slurm client setup and does not handle credentials. Output is parsed from JSON pinned to a data parser, or from delimiter-separated format output where measurement shows JSON is too costly. Format output is fragile if a field can contain the delimiter, which research checks. **Milestone 2:** nodes come from `scontrol --json show node` rather than `sinfo`, so all sections share one JSON parser; there is no delimiter-format fallback. Each binary path is an absolute setting, the environment is reduced to `PATH`, `LC_ALL`, and an optional per-cluster `SLURM_CONF`, and when `prlimit` is configured each child runs under an address-space limit.
- *slurmrestd.* The reader sends HTTP GET requests to an allowlist of paths, discovers the highest data parser version the daemon offers among those the plugin supports, and authenticates with a token read from a file. It handles 304 on conditional requests as "no change". **Milestone 2 diverges:** there is no discovery request. The data parser is one setting for both transports, chosen from `v0.0.43`, `v0.0.44`, and `v0.0.45` with `v0.0.43` as the default, and the path allowlist is exact for the chosen version; the health page warns when a cluster reports a release that does not ship it. A 404 is reported as a configuration error. `update_time` is never sent, so a 304 is not expected; one is still recorded as a failed section, never as an empty result. **Milestone 3 (#35):** slurmrestd clusters discover their parser. The reader asks `GET /slurm/{parser}/ping/` for each supported parser, newest first: 200 chooses it, 404 tries the next, any other answer fails the run with its class, and no supported parser is a configuration error. The choice is cached per cluster as the parser of its last completed slurmrestd run, and is kept while the release that run reported ships no newer supported parser; a run that did not complete (a 404 among the causes) or an upgraded release discovers again. The health page shows the parser with "discovered", and the data parser setting now applies to client commands only. Discovery uses `ping/`, not the OpenAPI document, because `ping/` is already a read-only path of each parser and needs no second document parser. `update_time` is sent to `/nodes/` only, only on 26.05 and later (taken from the release of the previous run), only after a completed node read whose rows are at most 600 seconds old, and set to that read's observation time less the 120 second skew allowance. A 304 then keeps the stored node rows and counts the section as read now; any other answer is read whole. `/jobs/` answers an unchanged request with 200, a warning, and no jobs, which would read as an empty queue, so it is never conditional; partitions, reservations, and shares have no unchanged answer. A 304 to a request without `update_time` is still a failed section.

Client commands are the default transport ([ADR 0012](decisions/0012-client-commands-default-transport.md), decides #7). Both implement the same interface and produce the same snapshot. The plugin never uses `scontrol update`, `scancel`, `sbatch`, `srun`, `salloc`, or `sacctmgr`, and never uses `sudo`.

**Normalized snapshot.** One snapshot contains:

| Section | Content |
|---|---|
| Cluster | Name, scheduler and release, transport, collection start and end |
| Partitions | Name, state, total nodes, total CPUs (milestone 2 stores no allocated CPUs; partition utilization starts with the graphs) |
| Jobs | Counts by normalized state per partition; pending counts by reason family per partition; raw reason counts; held count |
| Nodes | Per node: name, hostname, address, partitions, normalized state, raw state and flags, reason, reason time |
| Reservations (phase 2) | Name, flags, node list, start, end, active |
| Shares (phase 3) | Per selected account: fair-share factor, effective usage |

Raw state and reason strings are kept beside the normalized values so a Slurm release that adds a state degrades to "Other" instead of failing.

## Cacti objects

| Object | Created from | Notes |
|---|---|---|
| Cluster device | "HPC Scheduler Cluster" device template; the device is saved with `availability_method` 0 (a device template has no availability field), the `poller_id` of the cluster, and a host name in the reserved `.invalid` domain, so nothing ever contacts it | Created, or an existing device mapped, from the cluster's device page after it shows what will be added; carries the cluster-wide graphs. Deleting the device unmaps the cluster and keeps it |
| Data query | One script server query, "HPC Scheduler Partitions", indexed by partition name | Reason families are values, not indexes ([ADR 0011](decisions/0011-pending-reason-families-and-counting.md)). The reader reindexes when the snapshot's partitions differ from the device's indexes, which catches a rename that Cacti's index count check misses. The query sets `index_orphan_removal`, so a removed partition stops being polled; its graphs stay |
| Data templates | "HPC Scheduler Cluster Values" (every cluster value plus `age`, from one script server input), and one per partition graph: Jobs, Pending Reasons, Nodes, CPUs, Utilization | Cacti creates an RRD with only the items a graph draws, so each graph has its own data source: six per cluster and five per partition |
| Graph templates | Cluster: Jobs, Pending Reasons, Nodes, CPUs, Utilization, Snapshot Age. Partition: the same five | Graph permissions apply as for any Cacti graph; partition graphs are created with the device, and partitions added later get theirs from New Graphs |
| Node data template (phase 2) | "HPC Scheduler Node" on mapped devices | State code, drain flag, down flag, `age` |

Templates ship as Cacti template XML (`templates/hpcsched-templates.xml`, rebuilt by `tests/bin/build-templates.php`) and are imported with `import_xml_data()` at install and upgrade, matched by hash so re-imports update rather than duplicate. Data query XML is copied to `resource/script_server/`, the location core uses for its own script server queries, with its sha256 recorded; a file there the plugin did not write, or one changed since, is never overwritten, and the health page says why the install stopped. Uninstall removes the file only if it is unchanged and keeps the templates and graphs. The sibling plugin `plugin_cloudflare` follows the same pattern, with one device per account and the tunnels as data query indexes.

Whether the cluster device is visible everywhere or hidden from device lists is an open decision for Cacti maintainers. The proposal is a template-marked device that appears like any other.

## Node mapping

Phase 2 links scheduler nodes to Cacti devices ([ADR 0005](decisions/0005-map-nodes-to-existing-devices.md)).

**Keys.** The scheduler side offers node name, node hostname, and node address. The Cacti side offers device `hostname`, `description`, and `external_id` (limited to 40 characters). The proposed order is hostname, then address, then description, then a low-confidence short-name tier, with an administrator-defined rule layer on top. An offline prototype and 11 fixture cases support the order ([node-naming.md](research/node-naming.md), `tests/node-matching/`); real site naming is *live*. The decision issue finalizes it.

**Rules.**

- A proposal is shown, never applied, until an administrator confirms it or defines a rule that covers it.
- A node with several candidate devices, or a device with several candidate nodes, is ambiguous and stays unmapped.
- Mappings are stored in a plugin table keyed by cluster and node name. They survive node removal from the scheduler (marked absent) and are updated by the device hooks when a device is renamed or deleted.
- The plugin never creates devices for nodes.
- Unmapped nodes are visible only to administrators.
- Hostlist expressions are not parsed in PHP. Per-node output (`sinfo -N`, the nodes endpoint) already lists nodes one at a time.

**Cost.** The matching step is quadratic if done naively. With devices indexed by each key, the prototype matched 10,000 nodes against 10,000 devices in about 17 milliseconds, and a case where all 10,000 nodes and devices share one hostname in about 10 milliseconds (in memory, PHP 8.5.12; each index bucket keeps at most two ids, because two already mean ambiguous); loading devices and rendering proposals are not included. Node names must be cast to strings where they key arrays, because PHP turns a name such as `10` into an integer key.

## Measurement state

| Dimension | States | Evidence |
|---|---|---|
| Freshness | Current, delayed, stale, never collected | Snapshot age against the collection interval; stale beyond a documented multiple of it |
| Execution | Completed, failed, not attempted | Per snapshot section |
| Scheduler condition | Normal, controller unreachable, credential error, access restricted | Transport error class |
| Administrative | Enabled, disabled | Plugin records |
| Maintenance | In maintenance, not | Maint, for the mapped device |

Each cached value carries observation time, ingestion time, and the interval it was collected on. A page refresh is never an observation. A stale or failed section reports `U` to Cacti, not zero: a controller that stopped answering must not look like an empty queue.

## Graphs

Graphs are Cacti graphs. Trees, zoom, time spans, permissions, and export work as for any Cacti graph.

**Cluster and partition graphs.**

- Jobs by state: pending (eligible), held, running, completing, other. Held jobs are shown apart from eligible pending jobs, because the Slurm metrics guide notes that its pending count includes held jobs.
- Pending jobs by reason family.
- Nodes by normalized state: idle, allocated, mixed, drained (drained and draining), down, maintenance or reserved, powered down, other.
- CPUs as allocated, idle, and other, following `sinfo`'s `%C`: other is the unallocated CPUs of nodes that cannot take work.
- Utilization as allocated over total CPUs, and as nodes with allocated CPUs over total nodes.

Each partition has 32 values in 5 RRD files; the cluster has the same 32 plus `age` in 6.

**Counting rules.** A node in several partitions is counted once in cluster totals and once per partition. A pending job submitted to several partitions is counted once in cluster totals and once per partition it targets. A pending array contributes its task count (`squeue` collapses pending array elements unless `-r` is given; the metrics plugin adds the array count); held jobs are pending jobs whose reason is `JobHeldAdmin` or `JobHeldUser`. Drained, down, and other unavailable nodes stay in the utilization denominator, so lost capacity shows as lower utilization; the Utilization graph template says so in a comment line.

**Plugin pages.** The cluster overview shows the latest snapshot and its age. The pending reason page lists raw reasons with their family, job counts, and affected partitions, up to 200 rows, with the snapshot time; it also lists nodes in states the plugin does not know by raw state, with node names for the Admin realm only. Milestone 3 shows no job IDs; per-job detail is left for a later milestone. Every plugin page and action follows Cacti's device permission for the cluster's device. A node page (phase 2) shows state, reason, reason time, and the mapped device.

## Pending reasons

Pending reasons are the plugin's main differentiator, and they are the place where series counts can grow without bound ([ADR 0010](decisions/0010-bound-series-cardinality.md)).

- The raw reason is stored per job count, not per job.
- Reasons are grouped into a fixed family set with an Other bucket. The set, confirmed against source at 24.11, 25.05, 25.11, 26.05, and the 26.11 candidate: Priority, Resources, Dependency, Association limits, QOS limits, Held, Reservation, Node availability, Partition limits, Begin time, Other. A draft prefix rule maps all 216 distinct reason strings at 26.05 (Association limits 61, QOS limits 109, Other 25; counts per family are in [slurm-states-reasons.md](research/slurm-states-reasons.md)). [ADR 0011](decisions/0011-pending-reason-families-and-counting.md) decides #12: the eleven families, Other unsplit, with the raw reasons listed on the pending reason page.
- The Slurm reason list changes between releases (two to four names differ across 24.11 to 26.11) and the documentation page omits 23 strings present in source. Families are matched by name pattern and prefix, with a fixture test that every reason in the source list at each supported release maps to a family and an unknown reason maps to Other.
- Reason attribution is the scheduler's: a job reports only the reason found by the last scheduling method that examined it. The documentation says so, so operators do not read the breakdown as a complete cause analysis.
- No series exist for users or jobs. Per-job detail is a plugin page for administrators.

## Alerting and maintenance

Every value an operator may alert on is a polled data source ([ADR 0002](decisions/0002-alert-through-cacti-data-sources.md)):

| Value | Meaning | Missing or stale data |
|---|---|---|
| Pending, held, running, completing counts | Latest snapshot, per cluster and partition | `U` |
| Pending by reason family | Latest snapshot | `U` |
| Node counts by state | Latest snapshot | `U` |
| `age` | Seconds since the cluster's last successful snapshot | Always numeric once collected |
| Node state code, drain flag, down flag (phase 2) | Per mapped node | `U` |

Thold thresholds and Maint device schedules work on these with no change to either plugin. Milestone 3 ships the data sources Thold reads, cluster and per partition; the end-to-end Thold test (fire and recover on a fixture cluster) is milestone 4.

Stated limits:

- **Sampling.** Cacti polls on its own interval. If it is shorter than the collection interval the same snapshot is reported repeatedly; if longer, snapshots are skipped. Thold durations should be at least one collection interval.
- **Sampled, not evented.** A job that starts and finishes between snapshots is invisible. The plugin graphs queue state, not job history.
- **Reader loss.** A stopped reader shows through `age`, and counts turn `U`. Alerts on counts do not fire then; an alert on `age` does.

## Maintenance automation

Phase 2 can put devices into Maint for two scheduler conditions: nodes in a drained state, and nodes in a reservation flagged `MAINT`. Both are administrator opt-in per cluster.

The constraint is that Thold calls Maint's check function directly, so the only supported way to suppress a device is a Maint schedule ([ADR 0008](decisions/0008-maintenance-through-owned-expiring-schedules.md)). The proposal:

- Create ordinary one-time schedules for devices with a confirmed mapping, named with a fixed prefix and recorded in a plugin table. The plugin changes only schedules it created.
- *Drained nodes.* The end time is a short window (for example two collection intervals) that the reader extends each cycle while the node stays drained. When the node returns, the schedule ends at the next cycle. If the reader stops, the schedule expires and alerts resume. This is deliberate: a dead reader must not silence alerts for a node indefinitely.
- *Reservations.* The window is the reservation's `start_time` and `end_time` for each member node's device, re-read each cycle and updated or ended when the reservation changes. Each schedule's `etime` is capped at a short renewable horizon (the same two collection intervals as for drains) and never set to a multi-day `end_time`; the reader extends it each cycle while the reservation is active or about to start, so a stopped reader lets suppression lapse. Member nodes of a window that has not started are found by expanding the reservation's `node_list` with `scontrol show hostnames` and matching the names against node records (the REST transport has no hostlist endpoint in the source read, so a REST-only site needs the client command for this step or an in-plugin expander; open) ([slurm-reservations.md](research/slurm-reservations.md)). Source shows that a recurring reservation's record carries its current or next window (it is advanced by an hour, day, weekday step, weekend step, or week after each window), so recurring `MAINT` reservations are handled by the same rule. Floating reservations (`TIME_FLOAT`) have no fixed window and are excluded and listed on the health page; the schedule count per device is bounded because an hourly reservation creates up to 24 windows a day ([slurm-reservations.md](research/slurm-reservations.md)). The node `MAINT` flag is set only while a window is active and is not used to plan ahead.
- *States.* DRAIN and DRAINED are the proposed default; the drain flag is read from the JSON `state` array, because `%T` prints DRAINED for a node that is both DOWN and drained. DOWN is off by default, because a node going down unplanned is what Thold should report; so a node whose state includes DOWN does not match the default drain rule even when it also carries DRAIN, and gets a schedule only when the administrator turns the DOWN rule on. Reason-text filters let administrators exclude drains that mean a fault.
- Every create, extend, and end is written to an audit table.

Whether the plugin writes Maint's rows or calls a Maint function is a decision for Maint's maintainers. Direct writes couple the plugin to Maint's schema. Direct writes work today if a schedule row has `enabled = 'on'`, `mtype` 1, `stime` before `etime`, and one `plugin_maint_hosts` row `(1, device id, schedule id)`; Maint's check reads the database on every call and rewrites only recurring rows ([maint-thold.md](research/maint-thold.md), which also holds a draft proposal for a Maint function). Measurements continue during maintenance; only notifications are suppressed.

## Accounting and fair-share

Phase 3.

- **Fair-share.** `sshare` or the shares endpoint, available only with `priority/multifactor`. Series exist for administrator-chosen accounts or a top-N, never for all accounts or for users ([ADR 0010](decisions/0010-bound-series-cardinality.md)). Fair-share factor and effective usage are graphed; Fair Tree's `LevelFS` is added only if research shows it is present on supported releases.
- **Accounting statistics.** Off by default. Completed jobs per interval and queue wait from `sacct`, each query with explicit start and end bounds, a fixed maximum window, one query at a time per cluster, and its own failure state, so a slow `slurmdbd` cannot delay queue or node collection. The `sacct` man page warns that each call is an RPC to `slurmdbd` and that calls must be kept to the minimum.

## Remote operation

Phase 3 ([ADR 0006](decisions/0006-use-cacti-remote-collectors.md)). A cluster reachable only from a remote data collector is assigned to that collector's poller. The collector runs the reader and serves the script server inputs, as follows:

- Plugin configuration tables replicate to remote collectors through the `replicate_out` hook.
- The reader on each poller handles only clusters assigned to it and writes its snapshot to the collector's local database.
- Values reach the main server through the normal poller output path. In `offline` and `recovery` modes the plugin reports stale data, not healthy data.

**Milestone 2: main poller only.** The repositories use Cacti's default database connection, which on a remote data collector is the collector's local database, and no plugin table is replicated yet. So the `poller_bottom` hook starts nothing on a poller other than 1, the reader refuses there with a logged reason before reading any plugin table, and the health page warns for every cluster assigned to another poller. A cluster can still be assigned to one, so the registry does not need editing when phase 3 adds remote collection.
- The main server reads collector health on demand through read-only `remote_agent` actions that take only a cluster ID.
- Maint declares `remote_collect:0` and no code read replicates its tables to a collector, so phase 2 maintenance on remote clusters is out of scope until a live run shows where Thold evaluates suppression ([maint-thold.md](research/maint-thold.md)).
- The plugin's `INFO` declares `remote_poller:1` so `poller_bottom` runs on the collector. `remote_agent` needs at least two enabled pollers, and on `1.2.x` it authorizes by TCP peer address.

`remote_agent` authorizes callers by address. The documentation requires https between pollers and a restricted network.

## Code structure

Plugin code is object-oriented wherever Cacti's plugin contract allows it. No maintained Cacti plugin ships a class layout to copy, so milestone 2 defines the layout.

**Procedural entry points.** Cacti calls plugins by function name, so these stay thin shims, parseable on PHP 8.0, that check the PHP version, validate input, and hand off to a class: the `setup.php` lifecycle functions, hook callbacks (`config_arrays`, `config_settings`, `draw_navigation_text`, `poller_bottom`, `replicate_out`, `remote_agent`, `api_device_save`, `device_remove`), page scripts, the script server input functions, and the reader CLI entry point.

**Layout.**

- Classes in `lib/*.php` under the `Somethingwithproof\Hpcsched` namespace (milestone 2; the plan earlier named `Cacti\HpcSched`, which would claim the Cacti project's vendor name for an independent plugin), one per file, loaded by a small autoloader registered after the PHP check. No runtime Composer dependency. Files stay at depth 2 or less because the gettext build scans only that deep (to be confirmed against current Cacti build scripts).
- Settings use the `config_settings` hook. Logging uses `cacti_log()` with an `HPCSCHED` tag. Forms rely on csrf-magic.
- Strings use the `hpcsched` text domain, with a `locales/` directory and POT file.
- Realms: "HPC Scheduler View" and "HPC Scheduler Admin".

| Kind | Examples | Notes |
|---|---|---|
| Value objects | `Snapshot`, `PartitionStats`, `NodeRecord`, `ReservationRecord`, `NodeMapping` | `readonly` classes built by named constructors such as `NodeMapping::fromRow()` |
| Enums | `Freshness`, `NodeState`, `JobState`, `ReasonFamily`, `MappingState` | String-backed; stored as `varchar`, never as list positions |
| Backends | `Backend` interface, `SlurmBackend` | The only classes that know scheduler vocabulary |
| Transports | `CommandTransport`, `RestTransport` | The only classes that touch processes or the network |
| Repositories | `SnapshotRepository`, `MappingRepository`, `MaintRepository` | The only classes that issue SQL; take an explicit connection |
| Services | `StateClassifier`, `ReasonGrouper`, `NodeMatcher`, `MaintenancePlanner` | Return decisions as objects; a separate runner applies side effects |
| Infrastructure | `Clock`, system and fixed implementations | Injected wherever time matters |

Dependencies come in through constructors. Interfaces exist only at real boundaries: the scheduler, the database, the clock, the filesystem, and Maint. PHPStan runs on the class tree with Cacti core on its scan path.

## Storage, jobs, upgrades, and recovery

Plugin tables hold cluster settings, the latest snapshot, mappings, maintenance ownership and audit, and health. They are created and upgraded with Cacti's tracked plugin table API, and the stored version changes only after a migration succeeds. The reader runs from `poller_bottom` through `exec_background()` as one `register_process_start()` process per cluster, with a time budget. A snapshot is written in one transaction; sections that failed keep their previous data marked stale.

Disabling the plugin stops jobs and leaves devices, graphs, and Maint schedules in place. Uninstalling offers an export, asks whether to drop plugin tables (milestone 2: a "keep tables on uninstall" setting, on by default, and a JSON export of the cluster registry on the clusters page) and whether to delete plugin-created devices, never deletes devices that existed before, and ends or expires the plugin's Maint schedules. The scheduler is never touched. A restore test is part of release qualification.

## Performance

Capacity is stated in clusters, nodes, jobs, and series. Fixtures cover 1,000 and 10,000 nodes and 10,000 and 100,000 jobs on documented hardware.

**Milestone 2: memory.** Decoding a JSON answer took about 10 times its text size in PHP arrays for synthetic job records with Slurm's shape (PHP 8.5, measured locally); with the text itself the reader budgets 12 times. The answer size the reader allows is the smaller of Maximum Answer Size and (Collection Memory - 64 MiB) / 12, and the health page warns when the configured size is larger. With the 1024 MiB default that is 80 MiB per answer, which a 100,000 job queue with full records may exceed; the section then fails as too large rather than reporting zero. A run that runs out of memory anyway is recorded as failed with the memory error class by a shutdown function.

**Milestone 3: measured on synthetic snapshots** (#47). `tests/bin/perf.php` builds snapshots with 8 partitions, the 25.11 fixture shape, and a fixed seed, sends them through the stand-in client commands and the full reader, creates a cluster device with every partition graphed, and times one script server poll cycle and the two plugin pages. Median of three runs, on one machine: an Apple M3 Max laptop running OrbStack (14 CPUs, 16 GiB for the Linux VM), PHP 8.2.35 in the `cloud-ci:8.2` container, MariaDB 11.8.9 in a second container, 2026-11-15. Other hardware, a remote database, or real `squeue` answers (which carry many more fields per job) will give different numbers; treat these as the shape of the cost, not a capacity guarantee.

| Nodes | Jobs | Answer MiB (jobs, nodes) | Parse ms | Reader run ms | Rows written | Reader peak MiB | Script server cycle ms (calls) | Overview ms | Reasons view ms |
|---|---|---|---|---|---|---|---|---|---|
| 1,000 | 10,000 | 2.2, 0.2 | 29 | 87 | 1,395 | 21 | 1 (262) | 1 | 1 |
| 1,000 | 100,000 | 22.1, 0.2 | 362 | 413 | 1,395 | 210 | 1 (262) | 1 | 1 |
| 10,000 | 10,000 | 2.2, 2.0 | 61 | 206 | 10,395 | 29 | 1 (262) | 6 | 2 |
| 10,000 | 100,000 | 22.1, 2.0 | 347 | 503 | 10,395 | 210 | 1 (262) | 10 | 2 |

What this shows: job count drives decode time and memory (about 9.5 times the answer size at 100,000 jobs, inside the 12 times budget), and node count drives the rows written, since every node is stored; the job tables hold counts, not jobs. The script server answers a full cycle (6 cluster calls and 32 per partition) from one snapshot read, so its cost does not grow with jobs or nodes; the cycle figure excludes Cacti's own script server process and poller overhead, which the plugin does not control. Both pages stay far below the two second target. Not measured: a real 100,000 job `squeue` answer, the Cacti poller cycle with many cluster devices, and Cacti's own pages at these series counts.

Series arithmetic, revised with the milestone 3 templates: 32 values in 5 RRD files per partition, plus 33 values (with `age`) in 6 files per cluster, so 50 partitions are 256 RRD files and 1,633 values per cluster, and 1,606 script server calls per poll.

Series arithmetic for one cluster with 50 partitions and 15 reason families, from the template design (revision 2, before milestone 3; kept for the record):

- Per partition, four RRD files (jobs by state, pending by reason, nodes by state, utilization) holding 25 values in total with 11 reason families (29 with 15; the family count is decision #12), so 200 RRD files and 1,250 (or 1,450) values for 50 partitions, plus a handful of cluster-level files. Revision 1 said about 31 values and 1,550, which the plan's own lists do not reproduce.
- Per-node series exist only for mapped nodes, with one RRD of 4 values each: 10,000 mapped nodes add 10,000 RRD files and 40,000 values to poll.
- Controller calls: 4 to 6 per cycle through `slurmrestd`, independent of the above.

Engineering targets, to be measured, not demonstrated:

- Plugin overview and pending reason pages under two seconds at the stated counts.
- Cacti's own pages stay within normal response time at the stated series counts.
- No page request calls the scheduler.
- The snapshot write finishes within a fixed share of the collection interval.
- Script server input reads of the snapshot stay fast enough that polling 10,000 mapped nodes does not lengthen the poller cycle noticeably (measured by the series count research).

The setup screen shows the number of data sources and RRD files an import will create before it runs.

## Security

| Boundary | Control |
|---|---|
| Browser to Cacti | Cacti session, csrf-magic, two realms, device and graph permissions, prepared queries, contextual escaping |
| Cacti poller to Slurm client commands | Argument arrays, no shell, fixed command and option allowlist, no operator input in commands, timeouts and size limits, no `sudo`, no elevated user |
| Cacti poller to slurmrestd | GET-only path allowlist, TLS verification on, token from a file with owner-only permissions, token never logged, timeouts and size limits |
| Scheduler account | A dedicated, least-privilege Slurm account; the plugin documents what it must see and what `PrivateData` hides |
| Cacti to Maint (phase 2) | Writes only to schedules the plugin owns, recorded and audited |
| Main server to remote collector (phase 3) | Cacti's `remote_agent` authorization and https; actions are read-only and take a cluster ID |

**Credentials.** The plugin never creates, stores, or logs scheduler credentials ([ADR 0009](decisions/0009-credentials-by-file-or-munge-never-minted.md)). Because creating a Slurm JWT is equivalent to root on the cluster, the token is created by the cluster administrator for a restricted account, with a short lifespan, and rotated by the administrator's own tooling. Authentication errors raise a health warning and leave data stale.

**Data exposure.** Job names, users, and accounts are visible only to the Admin realm unless a setting allows otherwise. No series carry user or job names. Fixtures and pilot reports use no real names.

Phase 2 needs a security review covering mapping input, writes to Maint's tables, ownership checks, and audit. Phase 3 needs a review of the `remote_agent` actions, replication, and accounting queries.

## Testing

- **Fixtures.** Captured command and REST output per Slurm release for: idle, busy, and drained clusters; held, array, and multi-partition jobs; every documented reason and an unknown one; every documented node state and an unknown one; reservations including `MAINT` and recurring; nodes in several partitions; shares with and without Fair Tree.
- **Matrix.** Containers for each supported Slurm release (`slurmctld`, and `slurmrestd` where provided) against Cacti 1.2.31 and current `1.2.x`, on every PHP minor in the CI matrix. `setup.php` and shims are also linted under PHP 8.0. **Milestone 3 diverges:** the CI job `slurm-live` runs a real cluster from slurm-docker-cluster 3.0.2 (pinned by commit, MariaDB pinned by digest) with six nodes, creates allocated, mixed, drained, down, down and drained, and maintenance nodes, a maintenance reservation, and held, array, and pending jobs, then checks the raw JSON and the plugin's parser for every data parser the release serves, through both client commands and `slurmrestd`. 26.05.5 uses the published image, pinned by digest, on every change; 25.11.8 has no published image and is built from source, which takes about eight minutes, so it runs on pushes to `main`, weekly, and on request. 25.05 has no matching cluster configuration and stays on the synthetic fixtures ([ADR 0013](decisions/0013-three-latest-slurm-releases.md)). It then collects a snapshot with the plugin's own transports, built from a cluster record as the reader builds them: the client commands through `docker exec` into the `slurmctld` container (so `prlimit` is off there, since it would limit `docker` rather than the client), and `slurmrestd` on loopback with a token file. Each snapshot must complete every section and match the captured parse. A slurmrestd collection with no previous run must discover the newest supported parser the daemon serves (v0.0.45 on 26.05, v0.0.44 on 25.11). A second nodes read after a whole one must answer 304 and keep the rows on 26.05, retried 30 seconds apart while the cluster's last node changes are inside the skew window, and must be read whole with no `update_time` on 25.11. The reader's database write is not part of the job; the Cacti replays cover it with stand-ins. Captures from it are in `tests/fixtures/slurm/live` and run in the unit suite.
- **Transport safety.** Hung command, output flood, malformed JSON, 304, 401, 403, 500, slow response, oversized response, and node names with shell metacharacters.
- **Counting.** Fixtures for each counting rule in the Graphs section, checked with `rrdtool xport` against expected values.
- **Permissions.** Every page, graph, and data source as an administrator, a scoped user, a user whose access was just revoked, and a user with no access.
- **Maintenance.** Idempotence, expiry on reader stop, resume ends the schedule, operator schedules untouched, Thold quiet end to end.
- **Remote.** Collector offline, recovery, and replication after reconnect.
- **Failure scenarios before each phase release:** controller unreachable, controller slow, expired token, restricted visibility, interrupted reader, duplicate reader start, clock skew, full disk, interrupted upgrade, a Slurm upgrade between supported releases.
- **Harness.** Use the harness maintained Cacti plugins use, and confirm the choice with maintainers before adding test or CI infrastructure.

## Milestones

| Milestone | Phase | Deliverables | Exit evidence |
|---|---|---|---|
| 1. Compatibility and research | 0 | Release matrix, every facts-table row confirmed, command and REST output fixtures, load measurements, naming fixtures, prior art, estimates | Facts tables confirmed or corrected; transport and supported-release decisions made with evidence |
| 2. Foundation | 1 | PHP guard, lifecycle, realms, backend interface, both Slurm transports, credential handling, reader, state model, health page, test harness | Fresh install and upgrade at 1.2.31; install refused on PHP 8.1; fixture cluster snapshotted through both transports |
| 3. Cluster graphs and pending reasons | 1 | Templates, cluster device, queue and reason graphs, node state and utilization graphs, Thold proof, permissions, performance | Graphs match fixtures; Thold fires and recovers; stopped reader alerts through `age` |
| 4. Phase 1 release | 1 | Documentation, packaging, restore test, failure scenarios, pilots | Pilots on a real cluster through each transport |
| 5. Nodes and maintenance | 2 | Mapping engine and UI, node data source, maintenance for drains and reservations, audit, security review | Drain and MAINT reservation suppress the right devices and lapse when collection stops; review complete |
| 6. Accounting, fair-share, and remote collectors | 3 | Fair-share series, bounded `sacct` statistics, remote collector support, `remote_agent` actions, security review | Offline, recovery, and slurmdbd-unavailable scenarios correct; review complete |

**Estimates for phases 1 to 3** (production lines of PHP, from the measured size of plugin_smokeping at 14,932 and plugin_cloudflare at 10,479 lines; tests ran at 0.8 to 1.9 times production lines in those two; details and gates in [estimates.md](research/estimates.md)):

| Milestone | Production lines | Gates outside the code |
|---|---|---|
| 2. Foundation | 5,500 to 7,500 | harness choice (#11); decisions #7 and #8 (made in ADRs 0012 and 0013) |
| 3. Cluster graphs and pending reasons | 2,500 to 3,500, plus template XML | decision #12; live series-count run (#25) |
| 4. Phase 1 release | 500 to 1,000 | a Slurm test cluster and pilots |
| 5. Nodes and maintenance | 2,000 to 3,000 | decision #13 with Maint's maintainers; security review |
| 6. Accounting, fair-share, remote collectors | 1,500 to 2,500 | remote collector test bed; `slurmdbd` with history; security review |
| Phase 1 (2 to 4) | 8,500 to 12,000 | |
| Milestones 2 to 6 | 12,000 to 17,500 | |

No calendar duration is claimed: the comparison plugins' commit spans measure assisted writing against reachable services, and this plugin's longest steps are the gates above.

## Open decisions

| Decision | Options | Needed before | Decided by |
|---|---|---|---|
| Default Slurm transport | Client commands (proposed); slurmrestd; both | Milestone 2 transport work | Milestone 1 load and REST results |
| Supported Slurm releases | Three most recent (proposed); newest only; all packaged | Milestone 2 | Milestone 1 matrix |
| Node matching keys | Hostname, address, description with rules (proposed); `external_id` only; rules only | Milestone 5 | Naming fixtures and core confirmation |
| Repository and name | `Cacti/plugin_hpcsched` (proposed), `Cacti/plugin_slurm`, community plugin | Before any code is published | Cacti maintainers |
| Test harness | Whatever maintained plugins use | Milestone 2 | Cacti maintainers |
| Pending reason families | Decided: eleven fixed families, Other unsplit ([ADR 0011](decisions/0011-pending-reason-families-and-counting.md)) | Milestone 3 | Reason research |
| Maintenance mechanism | Direct rows (proposed); Maint function; none | Milestone 5 | Maint maintainers |
| Fair-share account selection | Chosen list plus top-N (proposed); all; top-level only | Milestone 6 | sshare research |

Evidence that fed decisions #7 and #8, now made in [ADR 0012](decisions/0012-client-commands-default-transport.md) and [ADR 0013](decisions/0013-three-latest-slurm-releases.md), and #9:

- **Default transport (#7).** Both transports produce the snapshot. Source shows the REST path needs an exact-path allowlist and supports TLS only from 25.05; the client-command path needs no credential handling and works on packaged releases older than the REST parsers. Milestone 3's live job read 26.05.5 and 25.11.8 through both with the same snapshot; the load comparison was not measured, so no transport is shown to be cheaper.
- **Supported releases (#8).** SchedMD's own end-of-support dates leave 25.11 and 26.05 supported, 25.05 ending this month, and 24.11 unsupported. `v0.0.43` is the one parser common to 25.05, 25.11, 26.05, and the 26.11 candidate.
- **Node matching (#9).** Hostname, address, description, then a low-confidence short-name tier with ambiguity left unmapped passed the 11 fixture cases; `external_id` alone is not supported (40 characters, not unique).

## Risks

| Risk | Effect | Mitigation |
|---|---|---|
| Plugin calls load the controller | Scheduling latency for all users | One snapshot per cycle, fixed call set, interval floor from measurement, timeouts, back off when rate limited |
| Series count grows with reasons, accounts, nodes | Slow Cacti pages and poller | Fixed reason families, opt-in accounts, per-node series only for mapped nodes, setup-time counts |
| Monitoring account sees too little (`PrivateData`) | Graphs silently incomplete | Health page reports what the account cannot see; documented account recipe |
| Maintenance suppresses a real fault | Missed alert | Opt-in, DRAIN only by default, reason filters, expiring schedules, audit |
| Maint schema changes | Direct writes break | Version check, research, and a proposed Maint function |
| Token leakage | Cluster-wide compromise | File-based token, owner-only permissions, short lifespan, never logged, restricted account |
| Slurm output changes between releases | Parser breaks | Pinned data parser, fixtures per release, unknown-state fallback |
| The default parser is removed | The default `v0.0.43` is removed in 27.05 (SchedMD data parser lifecycle, checked 2026-11-15); a 27.05 cluster answers every call with an error until the setting changes | Known limit of milestone 2: before 27.05 is supported, move the default to a parser 27.05 ships and add its fixtures; sites on 25.11 or later can choose `v0.0.44` or `v0.0.45` now. Only `v0.0.43` and `v0.0.45` field names were checked in source, and no output of any version has been captured |
| Node names do not match devices | Wrong device suppressed | Explicit mapping, no silent resolution, ambiguity stays unmapped |
| Volunteer capacity | Later phases stall | Each phase ships on its own |

## Milestone 1 research status

Documentation and source work for milestone 1 was done on 2026-11-15. Nothing was run against a Slurm cluster, a Cacti remote collector, or any account. Each file lists its own *live* checks.

| Issue | Research file | Done from documents and source | Needs live work |
|---|---|---|---|
| [#15](https://github.com/somethingwithproof/plugin_hpcsched/issues/15) | [slurm-releases.md](research/slurm-releases.md) | release and parser matrix, TLS, metrics, packages, support dates | per-package `slurmrestd -d list`, s2n build |
| [#16](https://github.com/somethingwithproof/plugin_hpcsched/issues/16) | [slurm-client-output.md](research/slurm-client-output.md) | JSON options, field names, counting rules, parsing rules | captured fixtures, JSON types, JSON versus format cost |
| [#17](https://github.com/somethingwithproof/plugin_hpcsched/issues/17) | [slurmrestd.md](research/slurmrestd.md) | endpoints, allowlist, headers, 304, TLS, `PrivateData` code paths | visibility per role, no-association token, failure modes |
| [#18](https://github.com/somethingwithproof/plugin_hpcsched/issues/18) | [controller-load.md](research/controller-load.md) | RPC counts, limits, metrics content | all load measurements; interval and floor are provisional |
| [#19](https://github.com/somethingwithproof/plugin_hpcsched/issues/19) | [slurm-states-reasons.md](research/slurm-states-reasons.md) | state and reason mapping, family rules, drift across releases | captured output |
| [#20](https://github.com/somethingwithproof/plugin_hpcsched/issues/20) | [slurm-reservations.md](research/slurm-reservations.md) | recurrence behavior, window rule | fixture set, `PrivateData` |
| [#21](https://github.com/somethingwithproof/plugin_hpcsched/issues/21) | [accounting.md](research/accounting.md) | `sshare` fields, `sacct` windows | `slurmdbd` load |
| [#22](https://github.com/somethingwithproof/plugin_hpcsched/issues/22) | [cacti-core.md](research/cacti-core.md) | signatures, hooks, capability set, process and reindex behavior | collector run, reindex run |
| [#23](https://github.com/somethingwithproof/plugin_hpcsched/issues/23) | [maint-thold.md](research/maint-thold.md) | Maint and Thold behavior, row recipe, proposal draft | collector suppression, end to end |
| [#24](https://github.com/somethingwithproof/plugin_hpcsched/issues/24) | [node-naming.md](research/node-naming.md) | fixtures and prototype, timing | pilot site naming |
| [#25](https://github.com/somethingwithproof/plugin_hpcsched/issues/25) | [series-scale.md](research/series-scale.md) | arithmetic and data model | all timing |
| [#26](https://github.com/somethingwithproof/plugin_hpcsched/issues/26) | [prior-art.md](research/prior-art.md) | organization plugins read; searches | maintainer and pilot questions |
| [#27](https://github.com/somethingwithproof/plugin_hpcsched/issues/27) | [estimates.md](research/estimates.md) | ranges from measured lines | re-check after live work |

Decisions #7 (default transport) and #8 (supported releases) are made in [ADR 0012](decisions/0012-client-commands-default-transport.md) and [ADR 0013](decisions/0013-three-latest-slurm-releases.md). Decision #9 (node matching) is not made here; the evidence is in the files above and summarized under Open decisions.

## Research

Not scheduled; revisited after phase 1:

- OpenMetrics as a transport on Slurm 25.11 and later.
- PBS Pro and OpenPBS backend.
- LSF backend and its relation to the LSF plugins already in the Cacti organization.
- GPU and other TRES utilization graphs.
- Federation and several clusters per Cacti install.
- Per-job and per-user views and their privacy cost.

## Upstream process

This repository holds the plan and tracks its requirements as GitHub issues and milestones. Before code is proposed for the Cacti organization, open an issue there describing the problem, the phase plan, and the open decisions, and ask maintainers about the repository, name, test harness, and plugin-marked devices. Maint changes are proposed as issues on the Maint repository first, then as separate pull requests there. No Thold change is needed.
