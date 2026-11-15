# 0013. Support the three latest Slurm releases

Status: Accepted (2026-11-15). Decides [#8](https://github.com/somethingwithproof/plugin_hpcsched/issues/8).

## Context

Slurm releases every six months and SchedMD supports each for about 18 months ([upgrade guide](https://slurm.schedmd.com/upgrades.html)). The data parsers change with each release ([slurm-releases.md](../research/slurm-releases.md)):

| Release | Data parsers | End of support (SchedMD) |
|---|---|---|
| 25.05 | v0.0.40 to v0.0.43 | November 2026 |
| 25.11 | v0.0.41 to v0.0.44 | May 2027 |
| 26.05 | v0.0.42 to v0.0.45 | listed as November 2028, which contradicts the 18 month rule |

Milestone 3's live job runs a real cluster for 26.05.5 (published image) and 25.11.8 (built from source). No matching cluster configuration exists for 25.05, so it is covered by synthetic fixtures only.

## Decision

The plugin supports the three latest Slurm releases: 25.05, 25.11, and 26.05. It reads data parsers v0.0.43, v0.0.44, and v0.0.45, so every supported release has at least one parser the plugin reads, and v0.0.43 is common to all three.

- 26.05 and 25.11 are tested against a live cluster through both transports.
- 25.05 is tested against fixtures only, and the README says so.
- When a new release is published, support moves to the three latest: the oldest release leaves the set, and the parser list follows.

## Consequences

- A cluster on 24.11 or earlier is not read: its client commands do not accept the pinned parser, and discovery finds no supported parser on its `slurmrestd`. Each run fails with an error class, never with a shape the plugin does not know.
- 25.05 reaches SchedMD's end of support this month and leaves the set when 26.11 is released. Until then a 25.05 regression shows only as a fixture change.
- SchedMD removes v0.0.43 in 27.05, so the client command default (v0.0.43) must move before the plugin supports 27.05.

## Alternatives rejected

- **SchedMD's supported releases only.** It would drop 25.05 this month while sites still run it, and the date for 26.05 is not reliable.
- **Every release that ships a parser the plugin reads.** It ties support to parser lifetimes, which are longer than SchedMD's support and are not tested.
