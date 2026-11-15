# Prior art in the Cacti organization and community

Research for [#26](https://github.com/somethingwithproof/plugin_hpcsched/issues/26), run 2026-11-15. Question: what existing plugins or templates cover Slurm, PBS, or LSF monitoring in Cacti, and where would this plugin overlap?

**Result.** No Slurm, PBS, or Torque plugin, template, or script for Cacti was found in the Cacti organization or by search. The three LSF-related plugins in the organization all attach to the LSF RTM product's `grid` plugin and its database tables (`grid_jobs`, `grid_jobs_finished`, `grid_clusters`), and none of them graphs queue depth, pending reasons, or node state. Overlap with this plugin's Slurm scope is none. Overlap with a later LSF backend is small and is about naming and the RTM dependency: those plugins need RTM, while this plugin's LSF backend would read LSF output directly. One of the three, `plugin_cjobs`, has a write path to the scheduler (it kills jobs), which this plugin's design rules out. Nothing here argues for changing the repository name. A search for a name collision found only this repository.

## Method

- Cacti organization: `gh api orgs/Cacti/repos` listed 61 repositories on 2026-11-15; names and descriptions filtered for slurm, pbs, torque, lsf, grid, hpc, cluster, batch, sge, and job. Three matched.
- Read each matched repository at `HEAD` through a bare blobless clone: `INFO`, `README.md`, `setup.php`, and the files that name tables or commands.
- GitHub search (`search/repositories`, `q=cacti slurm`, `q=cacti slurm template`, `q=cacti sinfo`, `q=org:Cacti slurm`) and two web searches, one aimed at the Cacti forums.

## The three organization plugins

| Plugin | Commit (date) | What it does | Needs | Writes to the scheduler | Source |
|---|---|---|---|---|---|
| `plugin_lsflog` | `96442a9` (2026-11-09) | Shows LSF job logs (standard output, error, script, history) for finished jobs, joined to RTM's `grid_jobs_finished`; an LSF post-exec script drops files into an inbox and a cron job files them | RTM's `grid` plugin (hooks `grid_jobs_tabs`, `grid_jobs_show_tab`); requires Cacti 1.2.16 or later per README; `INFO` compat 1.2.29; `remote_collect:0` | no | [README](https://github.com/Cacti/plugin_lsflog/blob/96442a99297ddb646b53a8800752fb3fca598c4a/README.md), [setup.php](https://github.com/Cacti/plugin_lsflog/blob/96442a99297ddb646b53a8800752fb3fca598c4a/setup.php#L49-L57) |
| `plugin_cjobs` | `322ce6d` (2026-11-09) | Custom actions for LSF jobs from the RTM UI, such as a site kill script; creates `grid_job_actions`; hooks `job_actions`, `job_actions_execute`, `job_actions_question` | RTM 10.2 (`depends = grid:10.2`); `remote_collect:1`; a sudo rule or an LSF admin web user (README warning) | **yes**: runs administrator-defined commands with `exec()` ([setup.php line 474](https://github.com/Cacti/plugin_cjobs/blob/322ce6db795783ee163a71562794b0c3566ff4a0/setup.php#L474)) | [README](https://github.com/Cacti/plugin_cjobs/blob/322ce6db795783ee163a71562794b0c3566ff4a0/README.md) |
| `plugin_gridgmgmt` | `ff2841a` (2026-09-29) | Nothing yet: the repository holds only `README.md` (one heading), a license, and issue and PR templates | n/a | n/a | [tree](https://github.com/Cacti/plugin_gridgmgmt/tree/ff2841ac727b173cac07ba51803b4cd3c8093354) |

Realms: lsflog registers one realm through `api_plugin_register_realm('lsflog', 'grid_lsflog.php', 'Plugin -> LSF Job Log Viewer', 1)`; cjobs registers `cjobs.php` as an admin realm.

## Community search

| Source | Result |
|---|---|
| GitHub repository search `cacti slurm`, `cacti slurm template`, `cacti sinfo`, `org:Cacti slurm` | zero results other than this proposal repository |
| Web search for a Slurm template for Cacti on the Cacti forums | no Slurm template; the closest result is a forum thread asking about Sun Grid Engine whose reply suggests Ganglia ([forum thread](https://forums.cacti.net/viewtopic.php?p=110887)) |
| Web search for a GitHub Slurm plugin or script for Cacti | none; results are for other stacks: [slurm-monitoring-public](https://github.imc.re/bethgelab/slurm-monitoring-public) (Telegraf, InfluxDB, Grafana), [ganglia-slurm](https://awesome.ecosyste.ms/projects/github.com%2Faaronjackson%2Fganglia-slurm), [SlurmUtil](https://github.com/flatironinstitute/SlurmUtil) |

Absence from search is not proof; private repositories, mailing-list attachments, and forum archives were not searched in depth. Status: no prior art found, unverified beyond the listed searches.

## Comparison

| Aspect | This plugin | `plugin_lsflog` | `plugin_cjobs` |
|---|---|---|---|
| Scheduler | Slurm first; PBS, LSF later | LSF through RTM | LSF through RTM |
| Data | queue, pending reasons, node state, utilization as graphs | job logs | job actions |
| Access to scheduler | read-only | none (reads RTM tables and files) | writes (kills, custom actions) |
| Product dependency | none | LSF RTM `grid` plugin | RTM |
| Cacti objects | script server data queries, graphs, Thold, Maint | pages and tabs | pages and hooks |

Consequences for the plan:

- The comparison paragraph the issue asks for can say: no Slurm plugin exists in the organization; the LSF plugins serve RTM sites and are not graph plugins.
- ADR 0004's statement that overlap with an LSF backend is unconfirmed can be narrowed to "no overlap in function; the RTM dependency means an LSF backend here would not conflict with them and would not reuse their tables".
- The repository name (`plugin_hpcsched`) shows no collision in the organization. Whether `plugin_slurm` would be clearer is a naming judgment for the maintainers, not a research result.

## Limits

- `plugin_gridgmgmt` is an empty shell; its intended scope is unknown.
- Private RTM code and its tables were not available; table names come from the plugins' own queries.
- The search did not cover mailing lists, Cacti documentation pages for individual templates, or vendor sites.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #26 | Ask the Cacti maintainers whether `plugin_gridgmgmt` has a planned scope and whether a Slurm plugin is already planned | overlap and name |
| #26 | Ask pilot sites whether they use RTM or any home-made Slurm Cacti scripts | prior art outside public search |
