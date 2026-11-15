# Slurm release and distribution matrix

Research for [#15](https://github.com/somethingwithproof/plugin_hpcsched/issues/15), run 2026-11-15. Question: which Slurm releases are in use and packaged, which data parser versions does each provide, and does each ship `slurmrestd`, the JSON options, and the metrics plugin?

**Result.** The plan's data parser lifecycle table matches the source of every release checked. Three facts change the plan. First, `v0.0.42` is not "the only parser common to all four releases" for long: the 26.11 release candidate has already dropped it, so the parser common to 25.05, 25.11, 26.05, and the 26.11 candidate is `v0.0.43`. Second, the 26.05 end-of-support date on SchedMD's upgrade page ("November 2028 (18 months)") contradicts its own 18-month rule and the dates of its neighbors, so it is treated as a probable typo and listed as unverified. Third, the 304 response on `/nodes` exists only from 26.05 (see [slurmrestd.md](slurmrestd.md)). Package versions in distributions lag far behind: the Enterprise Linux 9 package is 22.05, older than every release in the lifecycle table. Which releases to support is the owner's decision; the evidence for it is below. Nothing here ran a Slurm daemon, so every "ships" statement is about source at a tag, not about a built package.

## Method

- Slurm source: `https://github.com/SchedMD/slurm`, read with `git show` and `git grep` on release tags (blobless bare clone in a scratch directory; no file was built or run).
- Documentation: `slurm.schedmd.com` pages fetched 2026-11-15 (the site presents 26.05).
- Package versions: Debian `sources.debian.org` API, Ubuntu Launchpad API, Fedora `mdapi.fedoraproject.org`, OpenHPC GitHub releases.

## Refs used

| Release | Tag | Commit |
|---|---|---|
| 23.11 | `slurm-23-11-11-1` | `8a22252e33d444bbb918a476985e0ff746a19fde` |
| 24.05 | `slurm-24-05-8-1` | `46879dc2d172fd362745d21730a6846888d10157` |
| 24.11 | `slurm-24-11-7-1` | `defaf214db2e55326f45ab08f1bde5b2036d9f5d` |
| 25.05 | `slurm-25-05-9-1` | `420f74f5c427c4d507168f7994eba62115484609` |
| 25.11 | `slurm-25-11-9-1` | `5f7be9109e151bc59099786d7e3faccbca105869` |
| 26.05 | `slurm-26-05-6-1` | `599e8ac50eaff5547946f3f0687d9fe3bf44347f` |
| 26.11 (not released) | `slurm-26-11-0-0rc1` | `a202eca2bfaf2f17f945fa73b185a542bcc54d6a` |

These are the newest maintenance tags in each series that the clone listed on 2026-11-15.

## Release matrix

Data parsers are the directories under `src/plugins/data_parser/` at the tag. `slurmrestd` lives under `src/slurmrestd/`; the `--json` column is whether `"json"` appears in the option tables of `squeue`, `sinfo`, `scontrol`, `sshare`, and `sacct`.

| Release | Data parsers present | `--json` on all five clients | `slurmrestd` source | Metrics plugin | Native TLS in `slurmrestd` | End of support (SchedMD) | Status |
|---|---|---|---|---|---|---|---|
| 23.11 | v0.0.39, v0.0.40 | yes | yes | no | no | May 2025 | confirmed (source), end date confirmed (docs) |
| 24.05 | v0.0.39, v0.0.40, v0.0.41 | yes | yes | no | no (`tls_g_fini` only) | November 2025 | confirmed |
| 24.11 | v0.0.40, v0.0.41, v0.0.42 | yes | yes | no | no | May 2026 | confirmed |
| 25.05 | v0.0.40 to v0.0.43 | yes | yes | no | yes (`https://` listeners, certificate load at startup) | November 2026 | confirmed |
| 25.11 | v0.0.41 to v0.0.44 | yes | yes | yes (`src/plugins/metrics`) | yes | May 2027 | confirmed |
| 26.05 | v0.0.42 to v0.0.45 | yes | yes | yes | yes | "November 2028 (18 months)" on the page | confirmed in source; date unverified, see below |
| 26.11 candidate | v0.0.43 to v0.0.46 | not checked | yes | yes | yes | May 2029 (docs) | rc1 only |

Sources: data parser directories ([24.11](https://github.com/SchedMD/slurm/tree/defaf214db2e55326f45ab08f1bde5b2036d9f5d/src/plugins/data_parser), [25.05](https://github.com/SchedMD/slurm/tree/420f74f5c427c4d507168f7994eba62115484609/src/plugins/data_parser), [25.11](https://github.com/SchedMD/slurm/tree/5f7be9109e151bc59099786d7e3faccbca105869/src/plugins/data_parser), [26.05](https://github.com/SchedMD/slurm/tree/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/plugins/data_parser), [26.11 rc1](https://github.com/SchedMD/slurm/tree/a202eca2bfaf2f17f945fa73b185a542bcc54d6a/src/plugins/data_parser)); TLS: `slurmrestd.c` calls `tls_g_load_own_cert()` at [25.05 line 735](https://github.com/SchedMD/slurm/blob/420f74f5c427c4d507168f7994eba62115484609/src/slurmrestd/slurmrestd.c#L735) and [26.05 line 776](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmrestd/slurmrestd.c#L776), and the `https://` text is in the 25.05 man page but not in 24.11's; metrics: `MetricsType` is in the 25.11 and 26.05 `slurm.conf` man pages and absent from 25.05 and earlier; end-of-support dates: [upgrade guide](https://slurm.schedmd.com/upgrades.html).

TLS in `slurmrestd` also needs the optional s2n library at build time (`--with-s2n`, [auxdir/x_ac_s2n.m4](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/auxdir/x_ac_s2n.m4)); whether a given package was built with it is *live* (check `slurmrestd` startup on the packaged build). This settles the open item "which releases support native TLS": 25.05 and later, when built with s2n.

## Data parser lifecycle against the plan

| Plan statement | Source evidence | Status |
|---|---|---|
| v0.0.40 added 23.11, removed 25.11 | present in 23.11 to 25.05, absent in 25.11 | confirmed |
| v0.0.41 added 24.05, removed 26.05 | present 24.05 to 25.11, absent in 26.05 | confirmed |
| v0.0.42 added 24.11, removed 26.11 | present 24.11 to 26.05, absent in the 26.11 candidate | confirmed |
| v0.0.43 added 25.05, removed 27.05 | present 25.05 to 26.11 candidate | confirmed |
| v0.0.44 added 25.11 | present 25.11 onward | confirmed |
| v0.0.45 added 26.05 | present 26.05 onward | confirmed |
| v0.0.46 | not in the lifecycle table; present in the 26.11 candidate and in the GitHub release notes text for it | differs (table is not yet updated) |
| "`v0.0.42` is present in 24.11, 25.05, 25.11, and 26.05 and is the only parser common to all four" | true for those four; it is also in 24.11 only because 24.11 is past support | confirmed, with the 26.11 caveat below |

Consequence for the supported-release decision (#8). SchedMD's own dates put 24.11 out of support (May 2026) and 25.05 at the end of support this month (November 2026). Three choices, each with the parser that works across it:

| Supported set | Common parser | Lasts until (docs table) | Notes |
|---|---|---|---|
| 25.05, 25.11, 26.05 | v0.0.43 | 27.05 | the three newest releases at the time of writing, as the plan proposes; `v0.0.42` would stop working at 26.11 |
| 25.11, 26.05 (and 26.11 when released) | v0.0.43 or v0.0.44 | 27.05 or 27.11 | smallest matrix; every release in it is in support |
| 24.11 to 26.05 | v0.0.42 | 26.11 | includes unsupported 24.11; the parser is removed by the next release |

The plan should pin `v0.0.43` if 25.05 stays in scope, because it is the one parser that survives 26.11. This is a correction to the plan's wording, not a decision; the choice of releases is the owner's.

## Distribution packages

| Source | Version found | Date checked | Source | Status |
|---|---|---|---|---|
| Debian trixie | 24.11.5-4 | 2026-11-15 | [sources.debian.org](https://sources.debian.org/src/slurm-wlm/) | confirmed |
| Debian bookworm | 22.05.8-4+deb12u3 | 2026-11-15 | same | confirmed |
| Debian sid and forky | 26.05.5-1 | 2026-11-15 | same | confirmed |
| Ubuntu noble (24.04) | 23.11.4-1.2ubuntu5 | 2026-11-15 | [Launchpad](https://launchpad.net/ubuntu/+source/slurm-wlm) | confirmed |
| Ubuntu jammy (22.04) | 21.08.5-2ubuntu1 | 2026-11-15 | same | confirmed |
| Ubuntu resolute, stonking, tenacious | 25.11.2, 26.05.4, 26.05.5 | 2026-11-15 | same | confirmed |
| Enterprise Linux 10 (EPEL) | 26.05.6-1 | 2026-11-15 | [mdapi](https://mdapi.fedoraproject.org/epel10/pkg/slurm) | confirmed |
| Enterprise Linux 9 (EPEL) | 22.05.11-2 | 2026-11-15 | [mdapi](https://mdapi.fedoraproject.org/epel9/pkg/slurm) | confirmed |
| Enterprise Linux 8 (EPEL) | 20.11.9-1 | 2026-11-15 | [mdapi](https://mdapi.fedoraproject.org/epel8/pkg/slurm) | confirmed |
| OpenHPC 4.2 | Slurm 26.05 series, adds `slurm-slurmrestd` | 2026-11-01 release | [release notes](https://github.com/openhpc/ohpc/releases/tag/v4.2.GA) | confirmed |
| OpenHPC 3.6 | 25.11.8 | 2026-09-13 release | [release notes](https://github.com/openhpc/ohpc/releases/tag/v3.6.GA) | confirmed |
| OpenHPC 2.11 | 25.05.9 | 2026-09-13 release | [release notes](https://github.com/openhpc/ohpc/releases/tag/v2.11.GA) | confirmed |
| SchedMD own packages (RPM, `slurm-smd` Debian) | not listed by SchedMD in a page this run could read | | | unverified |

Fedora `mdapi` reports the repository metadata of the base release repository; it may not show later updates. SchedMD says the EPEL package is not provided or supported by SchedMD ([upgrade guide](https://slurm.schedmd.com/upgrades.html)). The effect for the plugin: a site on Enterprise Linux 9 or Ubuntu 22.04 or 24.04 runs a release that predates every data parser the plan can pin, so for those sites only the format-output path of the client commands is usable. 22.05 and 23.02 data parsers are not in the lifecycle table; whether `--json` works at all on such releases is not established (23.11 is the oldest tag checked). That makes a strong case for format output as the fallback transport, but the choice is the owner's (#7).

## Limits

- No Slurm binary was built or run; the matrix is source at tags. A distribution package may carry patches.
- The per-release `slurmrestd -d list` output was not captured; the lists above are directory contents, which is the same set by construction but not an observed run.
- `slurmrestd` present in source does not mean a package ships it: Debian and EPEL package it separately or not at all.
- The 26.11 row is a release candidate and will change.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #15 | On one host per distribution package (EL9, Ubuntu 24.04, Debian 13, OpenHPC), run `slurmrestd -d list`, `squeue --json=list`, and `sinfo --json=list` | whether each package ships `slurmrestd` and which parsers it lists |
| #15 | `slurmrestd https://...` startup on a packaged build | whether the package was built with s2n |
| #15 | Ask SchedMD or read their support page for the 26.05 end-of-support date | whether "November 2028" is a typo (the pattern implies November 2027) |
| #8 | Owner decision on the supported set | which row of the supported-set table applies |
