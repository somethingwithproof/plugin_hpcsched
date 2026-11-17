# 0012. Read Slurm through its client commands by default

Status: Accepted (2026-11-15). Decides [#7](https://github.com/somethingwithproof/plugin_hpcsched/issues/7).

## Context

The plugin reads Slurm through one of two transports that produce the same snapshot ([ADR 0004](0004-scheduler-backend-interface.md)): the client commands (`squeue`, `scontrol`, `sshare`) run on the poller host, or HTTP GET requests to `slurmrestd`. A new cluster record needs one of them as its default.

From milestone 1's source reading ([slurmrestd.md](../research/slurmrestd.md), [slurm-releases.md](../research/slurm-releases.md)):

- The client commands authenticate with the poller host's own munge or auth/slurm setup, so the plugin handles no credential. They need the Slurm client package and configuration on every poller that reads a cluster.
- `slurmrestd` needs a token, which the plugin reads from a file and never stores ([ADR 0009](0009-credentials-by-file-or-munge-never-minted.md)), and an exact path allowlist, because some GET paths change state (`reconfigure/`, and `job/{id}/requeue` on 26.05). Native TLS exists only from 25.05 and only when the build includes s2n. Many sites do not run `slurmrestd` at all.

Milestone 3's live job read 26.05.5 and 25.11.8 clusters through both transports with matching snapshots. It did not compare their load on the controller.

## Decision

Client commands are the default transport for a new cluster. `slurmrestd` stays a per-cluster choice for sites that run it, cannot install the client on the poller, or want to keep munge keys off it.

The data parser setting applies to client commands. A `slurmrestd` cluster discovers the newest parser its daemon serves among those the plugin supports (PLAN, Transports).

## Consequences

- A default cluster needs no token, TLS material, or allowlist review.
- The poller host must have a working Slurm client and configuration for each cluster read through client commands, and the documentation says so.
- Controller load from the two transports is unmeasured. If a site shows `slurmrestd` is cheaper, that changes the recommendation for large clusters, not this default.

## Alternatives rejected

- **`slurmrestd` by default.** It needs a token on the poller and a daemon many sites do not run, and its GET surface includes state-changing paths.
- **No default (require a choice).** Every new cluster would need a decision before it is read, with no difference in the snapshot to justify one.
