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

# usage: sh tests/slurm-live/fetch.sh <outdir> <jwt header file>
# Captures, for every data parser the client build lists, exactly the commands
# CommandTransport::COMMANDS runs and the paths RestTransport::PATHS requests.
# The token comes in as a curl header file and is never printed.
set -eu
OUT=$1
HDR=$2
mkdir -p "$OUT"
: > "$OUT/rest-parsers.txt"
x() { docker exec slurmctld "$@"; }

x squeue --json=list > "$OUT/list-squeue.txt" 2>&1 || true
PARSERS=$(sed -n 's|^data_parser/||p' "$OUT/list-squeue.txt" | sort)
[ -n "$PARSERS" ] || { echo "no data parsers listed" >&2; exit 1; }
echo "$PARSERS" > "$OUT/parsers.txt"

for p in $PARSERS; do
	x squeue --all --json="$p" > "$OUT/squeue.$p.json"
	x scontrol --json="$p" show node > "$OUT/scontrol-node.$p.json"
	x scontrol --json="$p" show partition > "$OUT/scontrol-partition.$p.json"
	x scontrol --json="$p" show reservation > "$OUT/scontrol-reservation.$p.json"
	x sshare --json="$p" > "$OUT/sshare.$p.json"
	# slurmrestd may serve fewer parsers than the client lists (25.11.8
	# lists v0.0.41 but serves v0.0.42 and later); only those it answers
	# are captured, and the checks skip REST for the others.
	if curl -fsS -o /dev/null -H "@$HDR" -H 'X-SLURM-USER-NAME: root' "http://127.0.0.1:6820/slurm/$p/ping"; then
		echo "$p" >> "$OUT/rest-parsers.txt"
		for s in jobs nodes partitions reservations; do
			curl -fsS -H "@$HDR" -H 'X-SLURM-USER-NAME: root' -o "$OUT/rest-$p-$s.json" "http://127.0.0.1:6820/slurm/$p/$s/"
		done
		curl -fsS -H "@$HDR" -H 'X-SLURM-USER-NAME: root' -o "$OUT/rest-$p-shares.json" "http://127.0.0.1:6820/slurm/$p/shares"
	else
		echo "$p is not served by slurmrestd; client commands only"
	fi
done
x sinfo -N -h -o '%N|%T|%E|%P' > "$OUT/sinfo.fmt-nodes.txt"
x squeue --all -h -r -o '%i|%T|%r|%P|%j' > "$OUT/squeue.fmt-r.txt"
