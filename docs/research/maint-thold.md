# Maint and Thold behavior the design depends on

Research for [#23](https://github.com/somethingwithproof/plugin_hpcsched/issues/23), run 2026-11-15. Question: do Maint and Thold behave as the plan states, and where does suppression run when Thold runs on a remote data collector?

**Result.** Every row of the plan's Maint and Thold table holds, with five additions. (1) The Maint check reads the database on every call with no cache, so rows written outside Maint's pages take effect at once, provided `enabled` is the string `on`. (2) Released Thold 1.8.2 calls `plugin_maint_check_cacti_host()` without the `function_exists()` guard that `develop` has, so the plan's wording must name which Thold it means. (3) Core itself calls the same function directly from the SNMP agent code; the `is_device_in_maintenance` hook has no caller anywhere in core at either ref. (4) Maint's recurring schedules are advanced by a database write inside the check, so a plugin must create one-time schedules (`mtype` 1) and never touch recurring ones. (5) Neither plugin registers `replicate_out`, so no code read replicates Maint's tables to a remote collector; whether suppression works for a Thold run on a collector is *live* and probably does not. Suppression stops notifications, not data collection, as the plan says. A draft of the Maint function proposal is at the end.

## Refs used

| Name | Ref | Commit |
|---|---|---|
| plugin_maint | `develop` (default branch, 2026-11-11), version 1.3 | `9422c7a4ce9916d0f0edf88b93afb194e0675b4a` |
| plugin_maint | tag `v1.3` (2026-01-28), on the `develop` line | `cd62de9` |
| plugin_thold | `develop` (2026-11-09), version 1.8.3 | `dc76952ee52e8b4877b2d5b830caab1f7c43dde9` |
| plugin_thold | tag `v1.8.2` (2024-10-16), latest tag | `1eb406b` |
| Cacti core | `release/1.2.31`, `1.2.x` | `1e8eaca26b84`, `e2d5c2589322` |

Both plugins' `main` branches are old (Maint 1.0, Thold 1.0.3) and are not the live line. Read through bare blobless clones in a scratch directory.

## Results

| Item | Plan statement | Evidence | Status |
|---|---|---|---|
| Maint tables | `plugin_maint_schedules` (`enabled`, `name`, `mtype`, `stime`, `etime`, `minterval`), `plugin_maint_hosts` (`type`, `host`, `schedule`) | [includes/database.php lines 33 to 70](https://github.com/Cacti/plugin_maint/blob/9422c7a4ce9916d0f0edf88b93afb194e0675b4a/includes/database.php#L33-L70): `enabled varchar(3) default 'on'`, `mtype int`, `stime int(22)`, `etime int(22)`, `minterval int`; hosts primary key `(type, schedule, host)` | confirmed |
| `type` 1 is a Cacti device; `host` 0 is "all" | [functions.php lines 90 to 100](https://github.com/Cacti/plugin_maint/blob/9422c7a4ce9916d0f0edf88b93afb194e0675b4a/includes/functions.php#L94-L100) (query `WHERE type = ? AND (host = ? OR host = 0)`) and `plugin_maint_check_cacti_host()` calls type 1; types 2 and 3 are WebSeer and Servcheck | confirmed |
| `mtype` 1 one-time, 2 recurring with whole-day `minterval` | [functions.php `plugin_maint_check_schedule()` from line 122](https://github.com/Cacti/plugin_maint/blob/9422c7a4ce9916d0f0edf88b93afb194e0675b4a/includes/functions.php#L122): case 1 is `stime <= now < etime`; case 2 requires `minterval` positive and a multiple of 86400, else logs a warning and stays inactive | confirmed |
| Schedule must have `enabled = 'on'` | same function: `WHERE enabled = 'on' AND id = ?` | **added**: a row written with `enabled = ''` or `1` never matches |
| A recurring schedule's times are rewritten by the check | same function, `UPDATE plugin_maint_schedules SET stime, etime` when `etime < now` | **added**: only `mtype` 1 rows are safe for another plugin to own |
| The check has no cache | no static or option cache in `plugin_maint_check_host()` or `_check_schedule()`; two prepared queries per call | confirmed by reading; behavior under load *live* |
| Maint's own pages write rows with `sql_save()` on schedules and `REPLACE INTO plugin_maint_hosts` | [maint.php lines 234, 297 to 300](https://github.com/Cacti/plugin_maint/blob/9422c7a4ce9916d0f0edf88b93afb194e0675b4a/maint.php#L234); a form check rejects `stime >= etime` (lines 225 to 231) | confirmed |
| Maint has no function for creating a schedule | the only `sql_save` and `REPLACE INTO` sites are in `maint.php`; `includes/functions.php` has only check functions | confirmed |
| Maint registers `is_device_in_maintenance` | [setup.php line 83](https://github.com/Cacti/plugin_maint/blob/9422c7a4ce9916d0f0edf88b93afb194e0675b4a/setup.php#L83) | confirmed |
| No caller of that hook in core | `git grep is_device_in_maintenance` returns no matches at either core ref | confirmed |
| Core calls the function directly | [lib/snmpagent.php:436 (R)](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/snmpagent.php#L436), [501 (B)](https://github.com/Cacti/cacti/blob/e2d5c25893221c3cd54ceb8293fba6ca38c936e6/lib/snmpagent.php#L501), guarded by `function_exists()` | **added** |
| Thold develop calls it directly after loading Maint's functions file | [includes/functions.php lines 3421 to 3435](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/functions.php#L3421-L3435) (requires `plugins/maint/functions.php` or `plugins/maint/includes/functions.php`), [polling.php:640 and 783](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/polling.php#L640) | confirmed |
| Thold v1.8.2 calls it with only `api_plugin_is_enabled('maint')` | [v1.8.2 polling.php:535 and 658](https://github.com/Cacti/plugin_thold/blob/1eb406b/includes/polling.php#L535) | **added**: no `function_exists()` guard in the release |
| What maintenance suppresses | with `$maint_dev` true, threshold alert and warning notifications are skipped and the event is logged "Only logging, maint device"; device down and recovering mail is skipped likewise; values are still polled and written | [Thold functions.php lines 3508 to 3545 and 3617](https://github.com/Cacti/plugin_thold/blob/dc76952ee52e8b4877b2d5b830caab1f7c43dde9/includes/functions.php#L3508-L3545) | confirmed |
| Where Maint's file lives | Maint 1.3 tag: `functions.php`; develop: `includes/functions.php`; Thold's loader tries both | tags and develop | confirmed |
| Maint declares `remote_collect:0`; Thold develop `remote_collect:1`; Thold `main` and Maint `main` `0` | both `INFO` files | confirmed |
| Neither plugin registers `replicate_out` or `remote_agent` | `git grep` over both `setup.php` at `develop`: only Thold's `poller_output` and `poller_bottom` hooks | confirmed |
| Core replicates only `plugin_config`, `plugin_hooks`, `plugin_realms` | [lib/poller.php lines 1604 to 1611](https://github.com/Cacti/cacti/blob/1e8eaca26b84b128c39ce8cc8ece42d7ff76aac1/lib/poller.php#L1604-L1611) | confirmed |
| Where suppression runs for a Thold run on a collector | Thold's `thold_poller_output` runs on the collector; its call to Maint reads the collector's local `plugin_maint_*` tables; no source read creates or replicates those tables there | *live*; expected: the call errors or returns false, so suppression does not apply |
| Tests in Maint claim core passes the device id "via the `is_device_in_maintenance` hook" | [tests/Unit/CheckHostTest.php line 27](https://github.com/Cacti/plugin_maint/blob/9422c7a4ce9916d0f0edf88b93afb194e0675b4a/tests/Unit/CheckHostTest.php) | **differs** from core: the claim in the test comment is not supported by any core caller |

## What this means for ADR 0008

- The Context statement that Thold "calls `plugin_maint_check_cacti_host()` directly when Maint is enabled" is right for `develop` and for v1.8.2; the `function_exists()` guard exists only in `develop`. The ADR is corrected to say so.
- Direct row writes work today. The fields a plugin must write: a `plugin_maint_schedules` row with `enabled = 'on'`, `mtype = 1`, `stime < etime` as Unix times, `minterval = 0`, and one `plugin_maint_hosts` row `(type 1, host = device id, schedule = id)`. Delete both rows to end it.
- Expiry as designed works: after `etime` the check returns false with no further write.
- A plugin-owned schedule needs an ownership key. Maint has no owner column, so ownership is the plugin's own table plus a name prefix; Maint's page would let an administrator edit or delete the row, so the plugin must tolerate that.
- `maint.php` rejects a schedule whose `stime >= etime`; direct writes bypass that check and must apply it.

## Draft proposal for the Maint maintainers

Not filed. For the owner to post on the Maint repository after the maintainers agree to look at it.

> Title: Provide a function for other plugins to create and end one-time maintenance schedules
>
> A plugin that knows a device is out of service on purpose (a scheduler drain, for example) can only suppress Thold today by inserting rows into `plugin_maint_schedules` and `plugin_maint_hosts` itself, which couples it to Maint's schema. Proposal: add two functions next to `plugin_maint_check_cacti_host()`, with Maint owning validation: `plugin_maint_schedule_create(string $owner, string $name, int $stime, int $etime, array $device_ids): int|false` creating a one-time schedule marked with an owner string, rejecting `stime >= etime`, and `plugin_maint_schedule_end(int $id, string $owner): bool` that ends it only if `$owner` matches. An `owner` column (nullable) would let the Maint page show and protect such schedules. No change to Thold is needed. Please say if you prefer a different shape.

## Limits

- Nothing was run. Maint and Thold were not installed.
- The remote collector behavior is inferred from the absence of replication code.
- The Maint check cost under 10,000 devices was not measured.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #23 | Insert a one-time schedule and host row directly, run a Thold threshold breach on the device, confirm notification is suppressed and logged | direct row writes end to end |
| #23 | Let the schedule's `etime` pass and breach again | expiry restores alerts |
| #23 | Insert a row with `enabled = ''` | confirms `on` is required |
| #23 | On a remote collector with Thold and Maint enabled, create a schedule on the main server and breach a threshold on a collector-polled device | whether suppression works on a collector |
| #23 | Time `plugin_maint_check_cacti_host()` with 10,000 `plugin_maint_hosts` rows | per-check cost |
