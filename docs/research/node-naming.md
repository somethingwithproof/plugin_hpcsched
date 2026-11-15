# Node naming against Cacti devices

Research for [#24](https://github.com/somethingwithproof/plugin_hpcsched/issues/24), run 2026-11-15. Question: which naming relationships between scheduler nodes and Cacti devices exist in practice, and what fixtures reproduce them?

**Result.** An offline prototype and a fixture set are committed at `tests/node-matching/` (`php run.php`, no database, no Cacti code, no network). It implements four matching tiers and two group rules, passes all 12 fixture expectations over 11 cases plus a collision-heavy scale case, and matched 10,000 nodes against 10,000 devices in about 17 milliseconds on PHP 8.5.12 (three runs, 16.2 to 17.2 ms; 1,000 by 1,000 took 1.4 ms), and a collision-heavy case in about 10 ms, so the quadratic-cost risk in the plan does not apply once devices are indexed by key. What the documents settle: the scheduler offers three names per node and Slurm permits all the divergences the plan lists. What no document or fixture can settle is how real sites name devices; the issue asks pilots about that, and it is *live*. The fixtures use synthetic names only.

## Method

Slurm: [slurm.conf node configuration](https://slurm.schedmd.com/slurm.conf.html) fetched 2026-11-15 and JSON key names in `data_parser/v0.0.43` and `v0.0.45` at 26.05.6 (`599e8ac5`). Cacti: `host.hostname`, `description`, `external_id` from `cacti.sql` at the refs in [cacti-core.md](cacti-core.md). The prototype is my own code, not Cacti's.

## What Slurm allows

| Fact | Evidence | Status |
|---|---|---|
| `NodeName` is usually `hostname -s`, may be an FQDN, and "may also be an arbitrary string if `NodeHostname` is specified" | [slurm.conf](https://slurm.schedmd.com/slurm.conf.html) NodeName | confirmed |
| `NodeHostname` defaults to `NodeName`; short or fully qualified | slurm.conf | confirmed |
| `NodeAddr` defaults to `NodeHostname`; may be an IP address | slurm.conf | confirmed |
| `NodeName` values `DEFAULT` (sets defaults for later lines) and `ALL` (reserved) are not real nodes | slurm.conf | confirmed |
| A node range expression requires the same count in `NodeName` and `NodeHostname` | slurm.conf | confirmed |
| Hostlist expressions need the numeric range at the end of a short hostname | slurm.conf | confirmed |
| JSON node keys: `name`, `hostname`, `address`, `partitions`, `reservation`, `state`, `reason` | `parsers.c` (v0.0.42, v0.0.43, v0.0.45) | confirmed |
| A node in several partitions repeats in `sinfo -N`, appears once in the nodes response with a `partitions` list | [slurm-states-reasons.md](slurm-states-reasons.md) | confirmed |

Cacti side: a device has `hostname` (name or IP, whatever polling uses), `description` (free text, often the display name), and `external_id varchar(40)` with a non-unique index ([cacti-core.md](cacti-core.md)). `description` is not unique either.

## Fixture catalog

All in `tests/node-matching/fixtures.json`; each has an expected proposal. "Tier" is the matching rule that fired: 1 node hostname equals device hostname, 2 node address equals device hostname, 3 node name or hostname equals device description, 4 same first DNS label (only when the node side has no dot and neither side is an IP address). Names are lowercased and a trailing dot is removed before comparing.

| Case id | Relationship | Expected |
|---|---|---|
| `equal-names` | node, hostname, address, and device hostname all `a1` | proposed, tier 1 |
| `device-has-fqdn` | node `n01`; device `n01.example.org` | proposed, tier 4 |
| `arbitrary-nodename` | `NodeName` `gpu-a`, `NodeHostname` `r1c2.example.org`; device `R1C2.example.org.` | proposed, tier 1 (case and trailing dot normalized) |
| `address-is-ip` | node `n02` with `NodeAddr` `10.0.0.5`; device hostname `10.0.0.5` | proposed, tier 2 |
| `description-carries-name` | device hostname is an unrelated IP, description `n05` | proposed, tier 3 |
| `duplicate-devices` | two devices with hostname `d1` | ambiguous, stays unmapped |
| `two-nodes-one-device` | two nodes resolve to one device | both ambiguous |
| `node-in-two-partitions` | the same node listed twice (as `sinfo -N` does) | proposed once |
| `no-device` | node with no device | unmatched |
| `short-label-collision` | `n1` against `n1.a.example.org` and `n1.b.example.org` | ambiguous, tier 4 |
| `ip-is-not-a-short-label` | node named `10`; device `10.0.0.5` | unmatched (an IP's first octet is not a name) |

A device with no node is not an output of the matcher; the page lists such devices separately.

Findings from building it:

- A node named with digits only (`10`) becomes an integer array key in PHP, which broke a typed function on the first run. Node names must be cast to string wherever they key an array. This is a real implementation hazard for the plugin and is now covered by a fixture.
- Tier 4 (short name against FQDN) is the only tier that crosses a domain boundary and is the one most likely to be wrong, as the `short-label-collision` case shows. The proposal stays "shown, never applied", as ADR 0005 says.
- Putting `description` before `hostname` would make a typo in a free-text field override a hostname match; the order is hostname, address, description, then short label.

Timing, from the prototype (hrtime around the matching call, one process, cold, no database):

| Nodes by devices | Time | Proposed |
|---|---|---|
| 1,000 by 1,000 | 1.4 ms | 1,000 proposed |
| 10,000 by 10,000 | 16.2 to 17.2 ms (three runs) | 10,000 proposed |
| 10,000 by 10,000, all sharing one hostname | 9.5 to 9.8 ms (three runs) | 10,000 ambiguous |

Each index bucket keeps at most two distinct device ids, since two already make a node ambiguous; without that bound a key shared by many devices would make the work grow with the number of duplicates (a review finding, fixed).

This measures only the in-memory step. Loading 10,000 devices from the Cacti database and rendering 10,000 proposals are not included.

## Matching decision input (#9)

The evidence supports the plan's proposal: hostname, then address, then description, with an administrator-defined rule layer on top, plus a final short-label tier that is off by default or clearly marked low confidence. `external_id` as the only key is not supported by anything found: it is 40 characters and not unique, and the plan's own text says so. Rules only (no automatic proposals) is the safe fallback but offers no help at 10,000 nodes. The choice is the owner's.

## Limits

- Fixtures are constructed from the documents, not from observed sites. Site naming practice is not established.
- No real node list was used or requested; the prompt asks pilots how they name devices without collecting lists, and that part has not happened.
- DNS resolution was not modeled: a node whose hostname resolves to the same IP as a device's hostname is not matched by this prototype. Whether to resolve names is a design question (resolution adds latency and trust in DNS).

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #24 | Ask pilot sites how their Cacti devices are named relative to `NodeName`, `NodeHostname`, and `NodeAddr` (no node lists) | the real distribution of cases |
| #24 | Load 10,000 devices from a Cacti database and run the matcher inside the plugin | end-to-end timing |
| #24 | Run the matcher against anonymized hostname patterns from two clusters | whether tier 4 produces false proposals |
