# 0009. Read scheduler credentials from the host, never store or mint them

Status: Accepted (2026-11-14)

## Context

Slurm's JWT documentation states that the power to create JWTs is the power of root on a cluster, and that tokens default to a 1800 second lifespan. `slurmrestd` documents that it must not run as root or `SlurmUser`. The Slurm client commands authenticate through the host's munge setup. Storing a long-lived token in Cacti's database would put cluster-wide trust in a web application's data.

## Decision

The plugin never creates scheduler tokens. The Slurm CLI transport relies on the poller user's existing Slurm client configuration. The `slurmrestd` transport reads a token from a file path outside the web root, and the health page checks that the file is readable only by the poller user. The administrator rotates the token with their own tooling. Credentials are never written to plugin tables, logs, or debug output. The scheduler account should have the least visibility that still shows the data the plugin graphs.

## Consequences

- A Cacti database leak exposes no scheduler credential.
- Token rotation is an operator task, documented with an example.
- An expired token shows as a collection error and stale data, not as an empty queue.

## Alternatives rejected

- **Token in settings.** Readable by anyone with database access.
- **Plugin mints tokens with `scontrol token`.** Requires privileges the plugin should not have.
