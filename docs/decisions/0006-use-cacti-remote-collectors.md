# 0006. Reach clusters through Cacti pollers and remote data collectors, not a custom bridge

Status: Accepted (2026-11-14)

## Context

The reader needs a host that can reach the scheduler: the Slurm client tools with a working munge setup, or a `slurmrestd` endpoint. Cacti remote data collectors already run plugin jobs from `poller_bottom` and replicate configuration with the `replicate_out` hook. Core 1.2.31 provides `call_remote_data_collector()` and a `remote_agent` hook for plugin actions.

## Decision

Each cluster is assigned to one Cacti poller. That poller runs the reader and serves the script server input. Clusters reachable only from a remote data collector run the reader there. The main server calls plugin actions through `remote_agent`. No new daemon, socket, or credential store is introduced.

## Consequences

- No plugin-specific network service.
- A cluster behind a separate network needs a full Cacti remote collector on that network.
- Remote CLI jobs must state which database they write and handle the `offline` and `recovery` connection modes.
- `remote_agent` authorizes callers by address, not cryptographically. Documentation requires https and a restricted poller network.
- The plugin's `INFO` capabilities must match the hooks it needs on remote pollers; milestone 1 confirms which capabilities `poller_bottom` requires at 1.2.31.

## Alternatives rejected

- **Custom bridge service.** A new daemon, credential store, and protocol to secure.
- **Direct SSH from the main server.** Needs key management and a privileged remote command path.
