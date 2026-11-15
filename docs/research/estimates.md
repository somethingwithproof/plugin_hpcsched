# Estimates for phases 1 to 3

Research for [#27](https://github.com/somethingwithproof/plugin_hpcsched/issues/27), made 2026-11-15 after the documentation and source pass of milestone 1. Question: how long will each phase take, given what milestone 1 learned?

**Result.** About 12,000 to 17,500 lines of production PHP across milestones 2 to 6, with phase 1 (milestones 2 to 4) about 8,500 to 12,000 of it. Size is the better-grounded figure; it comes from two plugins by the same author with the same layout and tooling. Elapsed time is not estimated as a number: the commit spans of the two comparison plugins are days to weeks, but they measure assisted writing against live services the author could reach, and this plugin's longest steps are gates outside the code (a Slurm test cluster, a Maint decision, two security reviews). Those gates are listed per milestone. The live items of milestone 1 are not done, so any estimate that depends on them is provisional, as marked. Tests ran at 0.8 to 1.9 times production lines in the two plugins.

## Basis, measured 2026-11-15

| Plugin | Ref | Production lines | Test lines | Commit span | Scope |
|---|---|---|---|---|---|
| [plugin_smokeping](https://github.com/somethingwithproof/plugin_smokeping) | `main` at `481547c` | 14,932 | 11,328 | 2026-11-14 (first commit and last on that day in the clone) | Observe mode, devices, data sources, graphs, release qualification |
| [plugin_cloudflare](https://github.com/somethingwithproof/plugin_cloudflare) | `main` at `c08c99b` | 10,479 | 20,347 | 2026-10-21 to 2026-11-14 | One provider: accounts, vault, HTTP transport, discovery, metrics, script server, templates |

Lines are `.php`, `.sh`, `.pl`, and `.py` files from `git ls-tree` (`git ls-files`), excluding vendored code; production excludes `tests/` and `features/`. Counts are physical lines including comments and blanks. The commit span is when commits landed, not effort, and both were written with AI assistance. plugin_cloud's [estimates.md](https://github.com/somethingwithproof/plugin_cloud/blob/main/docs/research/estimates.md) measured the same plugins a day earlier at 13,249 and 10,324 production lines; plugin_smokeping and plugin_cloudflare have grown since. The ratio of test to production lines is 0.76 (smokeping) and 1.94 (cloudflare).

plugin_cloudflare's component sizes, as reported in that earlier document (not re-counted): pages and lifecycle about 3,300, accounts 1,050, HTTP 610, storage and migrations 800, secrets 410, jobs 380, discovery 980, metrics 850.

## Per milestone

| Milestone | Production lines | Grounding | What milestone 1 changed | Gates outside the code |
|---|---|---|---|---|
| 2. Foundation | 5,500 to 7,500 | plugin_cloudflare's lifecycle, storage, secrets, jobs, and HTTP areas (about 5,550 together), plus a second transport and the parsers | Two transports, plus a format-output parser for sites on packaged Slurm older than the REST parsers ([slurm-releases.md](slurm-releases.md)); an exact-path REST allowlist; the reader's single-instance rule inside the child ([cacti-core.md](cacti-core.md)) | harness choice with maintainers (#11); decisions #7 and #8 |
| 3. Cluster graphs and pending reasons | 2,500 to 3,500, plus template XML | plugin_cloudflare discovery, metrics, and script server areas (about 1,830), plus the reason grouper and counting rules | A reason table of 216 names that varies by release ([slurm-states-reasons.md](slurm-states-reasons.md)); counting rules for arrays, held jobs, multi-partition nodes; a reindex trigger for new partitions | decision #12; the live series-count run (#25) |
| 4. Phase 1 release | 500 to 1,000, mostly tests and docs | plugin_smokeping's release qualification (per the earlier estimate) | none | a Slurm test cluster for pilots (#50) and the failure scenarios |
| 5. Nodes and maintenance | 2,000 to 3,000 | the matcher prototype is about 160 lines with its harness ([node-naming.md](node-naming.md)); plugin_cloudflare accounts (1,050) and page code for the mapping and audit UI | One-time `mtype` 1 schedules with `enabled = 'on'`, direct row writes validated against Maint's page rules ([maint-thold.md](maint-thold.md)); reservations read as current or next windows ([slurm-reservations.md](slurm-reservations.md)) | decision #13 with the Maint maintainers; phase 2 security review (#56) |
| 6. Accounting, fair-share, remote collectors | 1,500 to 2,500 | the earlier estimate's collector range of 1,000 to 1,500, plus fair-share and bounded accounting | Maint tables are not replicated to collectors, so remote maintenance is out until verified; `remote_poller:1` is required | a Cacti remote collector test bed; a `slurmdbd` with history for the `sacct` measurement (#21); phase 3 security review (#61) |
| **Total, milestones 2 to 6** | **12,000 to 17,500** | | | |
| **Phase 1 (2 to 4)** | **8,500 to 12,000** | | | |

Tests: 0.8 to 1.9 times production lines, as measured above.

## Uncertainty

- Milestone 2 carries the most design work: two Slurm transports and a backend interface that must hold PBS and LSF later.
- Milestone 3's size depends on whether per-node series use one script call per value or per node (a live check in #25); that is a factor of four in script server calls, not in lines.
- Milestone 5 has no direct precedent in either comparison plugin for writing into another plugin's tables.
- Milestone 6 remote collector work has the least precedent and depends on a test bed that does not exist yet.
- Elapsed time is not given because the comparison commit spans reflect assisted writing against reachable services, and the live items in the other research files (controller load, REST visibility, fixtures from real output, accounting load) need a cluster that has not been provisioned. If the live items change a fact, the milestone that depends on it changes: most likely 2 (transport), 3 (series counts), or 6 (accounting).

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #27 | Re-measure lines of the two comparison plugins when the estimates are published | the basis figures |
| #27 | Review the per-milestone ranges after the live rows of #16, #17, #18, #21, and #25 are filled | whether any range moves |
