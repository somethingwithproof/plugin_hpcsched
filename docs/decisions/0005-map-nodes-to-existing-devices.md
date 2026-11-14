# 0005. Map cluster nodes to existing Cacti devices, never create them implicitly

Status: Accepted (2026-11-14)

## Context

Compute nodes are usually already Cacti devices with SNMP, IPMI, or agent graphs. A scheduler's node name need not match the Cacti device. In Slurm, `NodeName` can be an arbitrary string when `NodeHostname` is set, `NodeHostname` defaults to `NodeName`, and `NodeAddr` defaults to `NodeHostname` (slurm.conf). Creating a second device per node would split graphs and alerts.

## Decision

The plugin links each scheduler node to an existing Cacti device through an explicit mapping table. It proposes matches by node name, hostname, and address, shows ambiguous and unmatched nodes to administrators, and applies a mapping only after confirmation or under an administrator-set rule. The cluster itself is one plugin-marked Cacti device that holds cluster-wide graphs. Unmapped nodes appear only on plugin pages for administrators.

## Consequences

- Node state graphs and maintenance land on the device operators already watch.
- Operators must review mappings once per cluster, and again when nodes are renamed.
- Duplicate or conflicting matches are never resolved silently.

## Alternatives rejected

- **Create a device per node.** Duplicates devices and breaks existing graphs and thresholds.
- **Match only by hostname string.** Fails where `NodeName` differs from the DNS name.
