# Reservations and maintenance flags

Research for [#20](https://github.com/somethingwithproof/plugin_hpcsched/issues/20), run 2026-11-15. Question: what do reservation records contain, and how is a maintenance window derived from them?

**Result.** Source answers the central design question: a recurring reservation's record always carries its current or next window. After a window ends, the controller rewrites the record's start and end by one hour, day, weekday step, weekend step, or week, so the plugin can read `start_time` and `end_time` on every cycle without computing recurrence. Floating reservations (`TIME_FLOAT`) are never advanced and cannot repeat, so they carry no usable future window. The node `MAINT` flag is set only while a `MAINT` reservation is active (`start <= now < end`), so it cannot be used to plan a future window; the reservation record is the source for that. A one-time reservation is deleted after it ends. The fixture set, the exact JSON, and the `PrivateData=reservations` behavior for the plugin's account are *live*.

## Method

Source at the tags in [slurm-releases.md](slurm-releases.md), mainly `src/slurmctld/reservation.c` and `slurm/slurm.h` at 26.05.6 (`599e8ac5`); the [scontrol man page](https://slurm.schedmd.com/scontrol.html) fetched 2026-11-15. Nothing was run.

## Facts

| Fact | Evidence | Status |
|---|---|---|
| Reservation flags include `MAINT`, `IGNORE_JOBS` (`RESERVE_FLAG_IGN_JOBS`), `DAILY`, `WEEKLY`, `HOURLY`, `WEEKDAY`, `WEEKEND`, `TIME_FLOAT`, `FLEX`, `ANY_NODES`, `PART_NODES`, `ALL_NODES`, `STATIC`, `MAGNETIC`, `USER_DELETE`, `PURGE_COMP`, `REPLACE`, `SKIP`, and the negative forms | [slurm.h lines 2737 to 2810](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/slurm/slurm.h#L2737-L2810) | confirmed |
| "Recurring" is the union `HOURLY, DAILY, WEEKLY, WEEKDAY, WEEKEND` | `RESERVE_REOCCURRING` at [slurm.h line 2808](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/slurm/slurm.h#L2808) | confirmed |
| The plan lists "DAILY, WEEKLY, and similar flag forms"; the full recurring set is five flags, with hourly as the shortest period | same | confirmed, plan list was incomplete |
| After a recurring reservation's window ends, the controller advances `start_time` and `end_time` by 1 hour (HOURLY), 1 day (DAILY), a weekday step that skips the weekend (WEEKDAY), the step to the next Saturday (WEEKEND), or 7 days (WEEKLY), and gives the reservation a new internal id | [`_advance_resv_time()`, reservation.c lines 8372 to 8463](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/reservation.c#L8372-L8463) | confirmed |
| A floating reservation (`TIME_FLOAT`) is not advanced ("Not applicable") and repeating floating reservations are not supported | same function line 8381; [scontrol man page](https://slurm.schedmd.com/scontrol.html) | confirmed |
| A non-recurring reservation is removed after it ends (and after its jobs, per `PURGE_COMP`) | [reservation.c lines 8540 to 8595](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/reservation.c#L8540-L8595) | confirmed |
| The node `MAINT` flag is set only for nodes of reservations with the `MAINT` flag whose window is active now; it is cleared when the window ends | [`_set_node_maint_mode()`, lines 8656 to 8710](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/reservation.c#L8656-L8710) | confirmed |
| Every active reservation, maintenance or not, sets the node `RES` flag (shown as RESERVED on idle nodes) | same lines 8702 to 8707 | confirmed |
| The man page defines `MAINT` as maintenance mode with special accounting treatment | [scontrol](https://slurm.schedmd.com/scontrol.html) | confirmed |
| `IGNORE_JOBS` lets a reservation be created while jobs run on the nodes; it is independent of `MAINT` | scontrol man page | confirmed |
| Showing reservations needs "Privileged or Access" when `PrivateData` restricts them; non-operators get only reservations their association can use | [scontrol](https://slurm.schedmd.com/scontrol.html), [reservation.c lines 5004 to 5015](https://github.com/SchedMD/slurm/blob/599e8ac50eaff5547946f3f0687d9fe3bf44347f/src/slurmctld/reservation.c#L5004-L5015) | confirmed |
| JSON keys `name`, `flags`, `node_list`, `start_time`, `end_time` exist on the reservation type at v0.0.42, v0.0.43, and v0.0.45 | `parsers.c` key search | confirmed |
| `update_time` on `/reservations/` returns no 304 at any release | `git grep` over four tags, see [slurmrestd.md](slurmrestd.md) | confirmed |

## Rule for turning a reservation into maintenance windows

Proposed for the plan (replaces "recurring reservations are handled only as far as research shows"):

1. Read reservations once per cycle from `scontrol --json show reservation` or `GET /slurm/{parser}/reservations/`.
2. Keep records whose flags include `MAINT`. Ignore `TIME_FLOAT` records (no fixed window). Everything else, including recurring records, is handled the same way, because the record's `start_time` and `end_time` are the current or next window.
3. For each member node with a confirmed device mapping, the window is `start_time` to `end_time`. Re-read each cycle: when a recurring record advances, its next window replaces the old one, and the plugin ends or extends its Maint schedule accordingly.
4. Only a record whose `end_time` is in the future counts. A recurring record that was just advanced shows the next window, so its schedule sits in the future until it starts.
5. Resolve members in two ways. For a window that is active now, the node record's `reservation` key names the reservation (the `RES` flag is set only while a window is active, so it is empty before the start). For a window that has not started, expand the record's `node_list`, which can be a range such as `n[01-02]`, with `scontrol show hostnames` (one call per distinct list per cycle, bounded by the number of reservations) and match the resulting names exactly against `name` in the node records; the plugin does not parse hostlists in PHP. The match runs every cycle before the window starts, so the schedule exists in time. `scontrol show hostnames` is refused to non-operators when `PrivateData=nodes` is set, and the REST transport has no hostlist endpoint in the source read, so a REST-only site either needs the client command for this step or an in-plugin hostlist expander; that choice is open (owner).
6. When the reader cannot see reservations (`PrivateData=reservations`, no Operator), the health page says reservations are hidden, and no schedule is created.

Edge cases the source shows and the rule must handle: a recurring reservation may be advanced a moment after its end; between the end and the advance the record shows the old, past window (`end_time` in the past), so step 4 treats it as no window. An hourly recurring `MAINT` reservation produces up to 24 windows a day; the plugin should bound the number of schedules it creates per device. Whether a `FLEX` reservation's real start can precede its `start_time` matters only for jobs, not nodes.

## Limits

No reservation was created. The JSON value types for `flags` (array of strings, by the pattern used for other flag fields) and for times (an object with `set`, `infinite`, and `number`, as other timestamps use) are inferred and not observed. `duration`, `partition`, and the per-node reservation key (`reservation` on the node record) were not all searched.

## Live checks

| Issue | Check | What it settles |
|---|---|---|
| #20 | On each supported release, create one-time, DAILY, WEEKLY, HOURLY, WEEKDAY, WEEKEND, `MAINT`, `IGNORE_JOBS`, partition-wide, node-list, and floating reservations on a test cluster; capture `scontrol show reservation`, the JSON form, and the REST response | the fixture set and JSON types |
| #20 | Let a DAILY reservation expire and capture the record before and after the advance | the interval in which a past window is shown, and step 4 |
| #20 | Set `PrivateData=reservations` and read reservations as an ordinary user, an Operator, and `SlurmUser` | what the plugin's account sees |
| #20 | Capture `sinfo -N -o '%N %T'` and the node JSON `state` array for a node inside an active `MAINT` reservation | the `$` and `MAINTENANCE` forms |
