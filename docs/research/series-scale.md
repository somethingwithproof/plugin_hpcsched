# Data query and series count at scale

Research for [#25](https://github.com/somethingwithproof/plugin_hpcsched/issues/25), run 2026-11-15. Question: how do Cacti pages, the poller, and the script server behave with the plugin's expected series counts?

**Result.** Not measured: the issue asks for poller cycle time, page response time, and RRD file counts on fixture clusters, and those need a Cacti install with a populated database. What source and arithmetic settle is the shape of the cost: Cacti keeps one `poller_item` row per data source item, so a data source with four values is four rows and, through the script server, up to four script calls per cycle unless one script call can fill several items (unverified, *live*). The plan's series arithmetic does not reproduce from its own lists: with 11 reason families the per-partition value count is 25, with the 15 families the issue names it is 29, and the plan's "about 31" matches neither. The family count itself disagrees between the plan (11) and this issue (15). Supported series counts per cluster cannot be stated until the live rows are filled; a bounded proposal is given so milestone 3 can plan, marked as not measured.

## Method

Source at the Cacti refs in [cacti-core.md](cacti-core.md) (`cacti.sql`, `poller.php`, `lib/poller.php`, `lib/data_query.php`), plus arithmetic from the plan's own lists. Nothing was run. The issue is blocked by #22; the answers from #22 used here are the orphan and reindex behavior.

## Facts from source

| Fact | Evidence | Status |
|---|---|---|
| `poller_item` has a primary key `(local_data_id, rrd_name)`: one row per data source item (RRD data source), with `rrd_num` counting the items of the data source | `poller_item` in [cacti.sql at R](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cacti.sql) (`CREATE TABLE poller_item`) | confirmed |
| The poller loads `poller_item` rows each cycle and has a branch for `rrd_num > 1` | [poller.php:261](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/poller.php#L261) | confirmed that the branch exists; what it does for script server items is unverified |
| Script server polling is one request line per item: `<include file> <function> <params>` | [cacti-core.md](cacti-core.md), script server row | confirmed |
| Index sets come from the data query's index step (a CLI run of the plugin's script); disappeared indexes become orphans, and reappearing ones clear the flag | [lib/data_query.php:292-321](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/data_query.php#L292-L321) | confirmed |
| A data source name is at most 19 characters | [cacti.sql:1573](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/cacti.sql#L1573) | confirmed |
| One RRD file per data source (`local_data_id`), holding every data source item of that data source | Cacti data model; not re-read here | unverified in this pass |

## Series arithmetic

The plan's "Performance" section counts four RRD files per partition: jobs by state, pending by reason, nodes by state, utilization.

| Item | Plan lists | Values per partition |
|---|---|---|
| Jobs by state | pending (eligible), held, running, completing, other | 5 |
| Pending by reason family | 11 in the plan; 15 in this issue | 11 or 15 |
| Nodes by state | idle, allocated, mixed, drained, down, maintenance or reserved, other | 7 |
| Utilization | allocated over total CPUs, allocated over total nodes | 2 |
| **Total** | | **25 with 11 families, 29 with 15** |

The plan says "about 31 values in total" and "about 1,550 values for 50 partitions"; from its own lists the figures are 1,250 (11 families) or 1,450 (15 families) values for 50 partitions, with 200 RRD files either way. The correction matters little for capacity (values per RRD file are not the cost) and should be fixed for accuracy. The number of reason families needs a single value; that is decision #12.

Per-node series, for mapped nodes only: four values each in one data source. 10,000 mapped nodes are 10,000 RRD files and 40,000 `poller_item` rows (one per value). If each row costs one script server call per cycle, that is 40,000 calls per cycle, which at a 60 second Cacti poll interval needs about 670 calls per second. Whether a script call can return several values for one data source (so that it is 10,000 calls) is the first live check, because it is a factor of four.

## Proposed supported counts (not measured)

Bounded to what the arithmetic says is cheap. These are targets for the live run, not claims.

| Series class | Count per cluster | Why |
|---|---|---|
| Cluster and partition series | up to 50 partitions, 15 families: about 200 RRD files, 1,450 values | small next to a typical Cacti install |
| Per-node series | up to 1,000 mapped nodes without a warning; 1,000 to 10,000 shown with the setup-time count and a warning | each mapped node adds four poller items |
| Per-node series above 10,000 | refused | outside what the run will measure |

The alternative the issue asks about, one multi-index data source per partition instead of one per node, changes the unit from a node to an index of a data source. It reduces RRD files but not values; the per-node alert path (ADR 0002) needs a polled value per node, so Thold thresholds on a per-index data source need Thold's per-index support, which is not read here.

## Limits

Everything about elapsed time is unmeasured: poller cycle time, data source and graph page response times, the script server's per-call cost, and reindex time with 10,000 indexes. The "fixture clusters" in the issue were not built.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #25 | Install a data query script that returns 4 values per node for 1,000 and 10,000 indexes; see whether one script call can fill all four items or Cacti makes four calls | the factor of four |
| #25 | Poll 1,000 and 10,000 mapped-node data sources through the script server; record poller cycle time with and without the plugin | poller cost |
| #25 | Open the data source and graph pages with 10,000 mapped data sources, as an admin and as a restricted user | page response times |
| #25 | Run the reindex with 50 and 500 partition indexes, remove 5, add 5 | reindex time and orphan behavior |
| #25 | Count RRD files and bytes on disk for the fixtures | the stated supported counts |
| #12 | Owner decision on 11 or 15 families | one value for the arithmetic |
