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

# Runs inside the slurmctld container (docker exec). Creates the states the
# live check asserts: six dynamic nodes c1..c6 in partition cpu, with
#   c1 ALLOCATED   c2 MIXED   c3 IDLE+DRAIN   c4 DOWN   c5 DOWN+DRAIN
#   c6 IDLE+RESERVED+MAINTENANCE (maintenance reservation)
# and jobs: 2 running, pending with reasons Resources, ReqNodeNotAvail and
# JobHeldUser, a 12-task pending array and a 6-task throttled array.
set -u

i=0
while [ "$(sinfo -h -N -o %N | sort -u | wc -l)" -lt 6 ]; do
	i=$((i + 1))
	[ "$i" -le 40 ] || { echo "only $(sinfo -h -N -o %N | sort -u | wc -l) nodes registered" >&2; exit 1; }
	sleep 3
done

cd /data
sacctmgr -i add account research Description=research Organization=lab fairshare=10 >/dev/null
sacctmgr -i add user root account=research >/dev/null
sacctmgr -i add account physics Description=physics Organization=lab fairshare=30 parent=root >/dev/null

sbatch -J run-excl -w c1 --exclusive --wrap 'sleep 900'
sbatch -J run-part -w c2 -n 2 --wrap 'sleep 900'
sbatch -J quick-ok --wrap 'sleep 2'
sbatch -J quick-fail --wrap 'sleep 2; exit 3'
sleep 8

scontrol update nodename=c3 state=drain reason='fan fail | rack 4'
scontrol update nodename=c4 state=down reason='not responding'
scontrol update nodename=c5 state=down reason='dead psu'
scontrol update nodename=c5 state=drain reason='dead psu, drained for RMA'
scontrol create reservation reservationname=maint_c6 starttime=now duration=120 nodes=c6 users=root flags=maint
scontrol create reservation reservationname=future_c3 starttime=now+1day duration=60 nodes=c3 users=root

sbatch -J pend-resources -w c1 --exclusive --wrap 'sleep 60'
sbatch -J pend-nodenotavail -w c4 --wrap 'sleep 60'
sbatch -J pend-held -H --wrap 'sleep 60'
sbatch -J pend-array --array=1-12 -w c1 --exclusive --wrap 'sleep 60'
sbatch -J pend-array-run --array=1-6%2 -n 1 --wrap 'sleep 300'

# Pending reasons are Priority until the scheduler has looked at the job.
i=0
while squeue -h -t PD -o %r | grep -qx Priority; do
	i=$((i + 1))
	[ "$i" -le 40 ] || { echo "jobs still on Priority" >&2; break; }
	sleep 3
done

# Observed on 25.11.8: the maintenance reservation is ACTIVE at once but the
# node only gains RESERVED+MAINTENANCE later (not seen within about six
# minutes in the first capture, present at 15 minutes). 26.05.5 had it at
# capture time. Wait for it so the node assertions do not race.
i=0
until scontrol show node c6 | grep -q 'State=.*MAINTENANCE'; do
	i=$((i + 1))
	[ "$i" -le 200 ] || { echo "c6 never gained the MAINTENANCE flag" >&2; break; }
	sleep 3
done
squeue --all -h -o '%i|%T|%r|%P|%j'
sinfo -N -h -o '%N|%T|%E|%P'
