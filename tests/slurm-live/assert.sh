#!/bin/sh
# SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
# SPDX-License-Identifier: GPL-2.0-or-later
#
# This file is part of plugin_hpcsched, a plugin for Cacti.
# https://github.com/somethingwithproof/plugin_hpcsched
#
# This program is free software; you can redistribute it and/or modify it
# under the terms of the GNU General Public License as published by the Free
# Software Foundation; either version 2 of the License, or (at your option)
# any later version.
#
# This program is distributed in the hope that it will be useful, but WITHOUT
# ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
# FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
# more details.

# usage: sh tests/slurm-live/assert.sh <fetch outdir>
# Needs only jq. Asserts the raw JSON states the
# plugin's parsers must see, for the command output and the REST output of
# every parser the release serves.
set -eu
DIR=$1
fail=0
ok()  { printf 'ok   %s\n' "$1"; }
bad() { printf 'FAIL %s\n' "$1"; fail=1; }

# expect <label> <jq filter> <expected> <file>
expect() {
	got=$(jq -r "$2" "$4")
	if [ "$got" = "$3" ]; then ok "$1"; else bad "$1: got '$got', want '$3' ($4)"; fi
}

for p in $(cat "$DIR/parsers.txt"); do
	for src in "scontrol-node.$p.json" "rest-$p-nodes.json"; do
		f=$DIR/$src
		[ -f "$f" ] || { ok "$p $src not served over REST, skipped"; continue; }
		expect "$p $src errors empty" '.errors | length' 0 "$f"
		node() { jq -r --arg n "$1" '.nodes[] | select(.name == $n) | .state | sort | join("+")' "$f"; }
		has()  { node "$1" | tr '+' '\n' | grep -qx "$2"; }
		has c1 ALLOCATED && ok "$p $src c1 ALLOCATED" || bad "$p $src c1 ALLOCATED"
		has c2 MIXED && ok "$p $src c2 MIXED" || bad "$p $src c2 MIXED"
		{ has c3 DRAIN && has c3 IDLE; } && ok "$p $src c3 IDLE+DRAIN" || bad "$p $src c3 IDLE+DRAIN"
		{ has c4 DOWN && ! has c4 DRAIN; } && ok "$p $src c4 DOWN only" || bad "$p $src c4 DOWN only"
		{ has c5 DOWN && has c5 DRAIN; } && ok "$p $src c5 DOWN+DRAIN" || bad "$p $src c5 DOWN+DRAIN"
		# 25.11.8 sets these flags on the node minutes after the
		# maintenance reservation starts; states.sh waits for them with a
		# bound, so when they are still missing the active MAINT
		# reservation on c6 is asserted instead, with a note.
		if has c6 RESERVED && has c6 MAINTENANCE; then
			ok "$p $src c6 RESERVED+MAINTENANCE"
		elif jq -e '.reservations[] | select(.name == "maint_c6" and .node_list == "c6" and ((.flags // []) | index("MAINT")))' "$DIR/scontrol-reservation.$p.json" >/dev/null; then
			ok "$p $src c6 flags not set yet; maint_c6 reservation is in place"
		else
			bad "$p $src c6 RESERVED+MAINTENANCE"
		fi
		expect "$p $src c3 reason keeps the pipe" '.nodes[] | select(.name == "c3") | .reason' 'fan fail | rack 4' "$f"
		expect "$p $src six nodes" '.nodes | length' 6 "$f"
	done

	expect "$p cpu partition has 6 nodes" '[.partitions[] | select(.name == "cpu")] | .[0].nodes.total' 6 "$DIR/scontrol-partition.$p.json"
	expect "$p two reservations" '.reservations | length' 2 "$DIR/scontrol-reservation.$p.json"

	for src in "squeue.$p.json" "rest-$p-jobs.json"; do
		f=$DIR/$src
		[ -f "$f" ] || { ok "$p $src not served over REST, skipped"; continue; }
		expect "$p $src errors empty" '.errors | length' 0 "$f"
		expect "$p $src running records" '[.jobs[] | select(.job_state | index("RUNNING"))] | length' 2 "$f"
		expect "$p $src pending records" '[.jobs[] | select(.job_state | index("PENDING"))] | length' 5 "$f"
		expect "$p $src JobHeldUser" '[.jobs[] | select((.job_state | index("PENDING")) and .state_reason == "JobHeldUser")] | length' 1 "$f"
		# The job pinned to the down node c4 waits for it; the other
		# pending reasons depend on which scheduler pass reached the job
		# first and differ between releases.
		expect "$p $src job on the down node waits for it" '[.jobs[] | select(.name == "pend-nodenotavail")][0].state_reason' 'ReqNodeNotAvail' "$f"
		expect "$p $src array string 1-12" '[.jobs[] | select(.name == "pend-array")][0].array_task_string' '1-12' "$f"
		expect "$p $src throttled array string" '[.jobs[] | select(.name == "pend-array-run")][0].array_task_string' '1-6%2' "$f"
	done
done

# sinfo %T cannot show DOWN+DRAIN: it prints drained for c5.
grep -qxF 'c5|drained|dead psu, drained for RMA|cpu*' "$DIR/sinfo.fmt-nodes.txt" && ok 'sinfo %T shows c5 as drained' || bad 'sinfo %T line for c5'
# A pipe in a reason splits the delimited line; the JSON form must be used for reasons.
[ "$(grep '^c3|' "$DIR/sinfo.fmt-nodes.txt" | awk -F'|' '{print NF}')" = 5 ] && ok 'pipe in reason adds a field (format output unsafe)' || bad 'pipe-in-reason shape'
# squeue -r prints one line per array task.
[ "$(grep -c '|pend-array$' "$DIR/squeue.fmt-r.txt")" = 12 ] && ok 'squeue -r lists the 12 array tasks' || bad 'squeue -r array tasks'

exit $fail
