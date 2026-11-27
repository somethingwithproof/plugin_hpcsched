<?php
/*
 * SPDX-FileCopyrightText: 2026 Thomas Vincent <thomasvincent@gmail.com>
 * SPDX-License-Identifier: GPL-2.0-or-later
 *
 * This file is part of plugin_hpcsched, a plugin for Cacti.
 * https://github.com/somethingwithproof/plugin_hpcsched
 *
 * This program is free software; you can redistribute it and/or modify it
 * under the terms of the GNU General Public License as published by the Free
 * Software Foundation; either version 2 of the License, or (at your option)
 * any later version.
 *
 * This program is distributed in the hope that it will be useful, but WITHOUT
 * ANY WARRANTY; without even the implied warranty of MERCHANTABILITY or
 * FITNESS FOR A PARTICULAR PURPOSE. See the GNU General Public License for
 * more details.
 */

use Somethingwithproof\Hpcsched\ControllerDiagnostics;
use Somethingwithproof\Hpcsched\DiagnosticsParser;
use Somethingwithproof\Hpcsched\JobExitReason;
use Somethingwithproof\Hpcsched\JobExitTally;
use Somethingwithproof\Hpcsched\NodeTally;
use Somethingwithproof\Hpcsched\SlurmParser;
use Somethingwithproof\Hpcsched\TransportError;
use Somethingwithproof\Hpcsched\TresParser;

describe('GPU/GRES and RAM TRES parsing and aggregation', function () {
	it('parses Slurm GRES, gres_used, and TRES strings without double-counting model-specific GPUs', function () {
		expect(TresParser::parseGpuCount('gpu:h100:8(S:0-1)'))->toBe(8)
			->and(TresParser::parseGpuCount('gpu:h100:4(IDX:0,2-4),nvme:2'))->toBe(4)
			->and(TresParser::parseGpuCount('gpu:a100:2,gpu:l40s:4'))->toBe(6)
			->and(TresParser::parseGpuCount('gpu:8'))->toBe(8)
			->and(TresParser::parseGpuCount('(null)'))->toBe(0)
			->and(TresParser::parseGpuCount('cpu=128,mem=512000M,gres/gpu=8,gres/gpu:h100=8'))->toBe(8)
			->and(TresParser::parseGpuCount('cpu=64,gres/gpu:a100=4,gres/gpu:h100=2'))->toBe(6)
			->and(TresParser::parseMemMib('cpu=128,mem=512000M,gres/gpu=8'))->toBe(512000)
			->and(TresParser::parseMemMib('cpu=128,mem=1T'))->toBe(1048576)
			->and(TresParser::parseMemMib('cpu=128,mem=256G'))->toBe(262144)
			->and(TresParser::parseMemMib('cpu=128,mem=2048K'))->toBe(2)
			->and(TresParser::parseMemMib('cpu=128'))->toBe(0);
	});

	it('exposes alloc_gpus, idle_gpus, drained_gpus, total_gpus and RAM TRES per partition and cluster-wide alongside CPUs', function () {
		$doc = [
			'nodes' => [
				[
					'name'         => 'gpu01',
					'hostname'     => 'gpu01',
					'address'      => '10.0.1.1',
					'state'        => ['MIXED'],
					'partitions'   => ['gpu', 'all'],
					'cpus'         => 128,
					'alloc_cpus'   => 64,
					'gres'         => 'gpu:h100:8(S:0-1)',
					'gres_used'    => 'gpu:h100:5(IDX:0-4)',
					'real_memory'  => 1048576,
					'alloc_memory' => 524288,
					'free_mem'     => ['set' => true, 'infinite' => false, 'number' => 480000],
				],
				[
					'name'         => 'gpu02',
					'hostname'     => 'gpu02',
					'address'      => '10.0.1.2',
					'state'        => ['MIXED', 'DRAIN'],
					'partitions'   => ['gpu', 'all'],
					'cpus'         => 128,
					'alloc_cpus'   => 32,
					'tres_str'     => 'cpu=128,mem=1024G,gres/gpu=8,gres/gpu:h100=8',
					'tres_used'    => 'cpu=32,mem=256G,gres/gpu=2',
					'free_mem'     => ['set' => false, 'infinite' => false, 'number' => 0],
				],
				[
					'name'         => 'cpu01',
					'hostname'     => 'cpu01',
					'address'      => '10.0.2.1',
					'state'        => ['IDLE'],
					'partitions'   => ['batch', 'all'],
					'cpus'         => 64,
					'alloc_cpus'   => 0,
					'real_memory'  => 262144,
					'alloc_memory' => 0,
					'free_mem'     => 250000,
				],
			],
		];

		$nodes   = SlurmParser::nodes($doc);
		$summary = NodeTally::resourceSummary($nodes);

		// gpu01: 8 total, 5 alloc, 3 idle, 0 drained; mem 1048576 total, 524288 alloc, 480000 free
		// gpu02 (DRAINING -> !working): 8 total, 2 alloc, 0 idle, 6 drained; mem 1048576 total, 262144 alloc, 786432 free (fallback total - alloc)
		// cpu01: 0 GPUs; mem 262144 total, 0 alloc, 250000 free
		expect($summary['cluster'])->toBe([
			'nodes_alloc'   => 2,
			'nodes_total'   => 3,
			'cpus_alloc'    => 96,
			'cpus_idle'     => 128,
			'cpus_other'    => 96,
			'cpus_total'    => 320,
			'alloc_gpus'    => 7,
			'idle_gpus'     => 3,
			'drained_gpus'  => 6,
			'total_gpus'    => 16,
			'alloc_mem_mib' => 786432,
			'total_mem_mib' => 2359296,
			'free_mem_mib'  => 1516432,
		]);

		expect($summary['partitions']['gpu']['alloc_gpus'])->toBe(7)
			->and($summary['partitions']['gpu']['idle_gpus'])->toBe(3)
			->and($summary['partitions']['gpu']['drained_gpus'])->toBe(6)
			->and($summary['partitions']['gpu']['total_gpus'])->toBe(16)
			->and($summary['partitions']['batch']['total_gpus'])->toBe(0)
			->and($summary['partitions']['batch']['total_mem_mib'])->toBe(262144)
			->and($summary['partitions']['batch']['free_mem_mib'])->toBe(250000);
	});
});

describe('scheduler controller diagnostics (sdiag and REST /diag)', function () {
	it('parses slurmrestd /diag JSON with RPC queue, threads, backfill cycles, and slurmdbd rollup lag', function () {
		$now = 1800010000;
		$doc = [
			'statistics' => [
				'req_time'             => ['set' => true, 'infinite' => false, 'number' => $now],
				'server_thread_count'  => 42,
				'agent_queue_size'     => 120,
				'dbd_agent_queue_size' => 80,
				'bf_cycle_mean'        => 450000,
				'bf_cycle_max'         => 2100000,
				'bf_cycle_last'        => 390000,
				'bf_depth_mean'        => 850,
				'bf_queue_len'         => 1400,
				'rollups'              => [
					['type' => 'hourly', 'last_run' => $now - 900],
					['type' => 'daily', 'last_run' => $now - 7200],
				],
			],
		];

		$diag = SlurmParser::diagnostics($doc);

		expect($diag->rpcQueueDepth)->toBe(200)
			->and($diag->agentQueueSize)->toBe(120)
			->and($diag->dbdAgentQueueSize)->toBe(80)
			->and($diag->serverThreadCount)->toBe(42)
			->and($diag->bfCycleMeanUs)->toBe(450000)
			->and($diag->bfCycleMaxUs)->toBe(2100000)
			->and($diag->bfCycleLastUs)->toBe(390000)
			->and($diag->bfDepthMean)->toBe(850)
			->and($diag->bfQueueLen)->toBe(1400)
			->and($diag->slurmdbdRollupLagSec)->toBe(900)
			->and($diag->health)->toBe('healthy')
			->and($diag->warnings)->toBe([])
			->and($diag->toArray()['rpc_queue_depth'])->toBe(200);
	});

	it('parses classic sdiag text output and classifies degraded and critical controller states', function () {
		$sdiag = <<<'TEXT'
*******************************************************
sdiag output at Fri Nov 27 00:00:00 2026
Data since      Thu Nov 26 00:00:00 2026
*******************************************************
Server thread count:  245
Agent queue size:     4200
DBD Agent queue size: 1100

Main Schedule Statistics (microseconds):
	Last cycle:   8000
	Max cycle:    45000
	Mean cycle:   12000

Backfilling stats (WARNING: data obtained in the middle of backfilling execution.)
	Total backfilled jobs (since last slurm start): 12345
	Last cycle:   95000000
	Max cycle:    135000000
	Mean cycle:   24500000
	Last depth cycle: 4100
	Mean depth cycle: 3200
	Last queue length: 8900
	Slurmdbd rollup lag: 25000
TEXT;

		$diag = DiagnosticsParser::parseSdiagText($sdiag);

		expect($diag->serverThreadCount)->toBe(245)
			->and($diag->agentQueueSize)->toBe(4200)
			->and($diag->dbdAgentQueueSize)->toBe(1100)
			->and($diag->rpcQueueDepth)->toBe(5300)
			->and($diag->bfCycleMeanUs)->toBe(24500000)
			->and($diag->bfCycleMaxUs)->toBe(135000000)
			->and($diag->bfCycleLastUs)->toBe(95000000)
			->and($diag->bfDepthMean)->toBe(3200)
			->and($diag->bfQueueLen)->toBe(8900)
			->and($diag->slurmdbdRollupLagSec)->toBe(25000)
			->and($diag->health)->toBe('critical')
			->and($diag->warnings)->toBe([
				'backfill_cycle_critical',
				'rpc_queue_critical',
				'server_threads_critical',
				'slurmdbd_rollup_lag_critical',
			]);

		$warn = ControllerDiagnostics::classify(1500, 190, 40000000, 4000);
		expect($warn['health'])->toBe('degraded')
			->and($warn['warnings'])->toBe([
				'backfill_cycle_slow',
				'rpc_queue_high',
				'server_threads_high',
				'slurmdbd_rollup_lag_high',
			]);

		expect(fn () => DiagnosticsParser::parseRestDiag([]))->toThrow(TransportError::class)
			->and(fn () => DiagnosticsParser::parseSdiagText(''))->toThrow(TransportError::class)
			->and(fn () => DiagnosticsParser::parseSdiagText('not sdiag output'))->toThrow(TransportError::class);
	});
});

describe('job exit-reason breakdown', function () {
	it('classifies and aggregates OOM, TIMEOUT, NODE_FAIL, PREEMPTED, CANCELLED, FAILED, and COMPLETED outcomes per partition and cluster', function () {
		$doc = [
			'jobs' => [
				['job_state' => ['COMPLETED'], 'partition' => 'batch', 'user_name' => 'alice'],
				['job_state' => ['COMPLETED'], 'partition' => 'gpu', 'user_name' => 'bob'],
				['job_state' => ['OUT_OF_MEMORY'], 'partition' => 'gpu', 'user_name' => 'carol'],
				['job_state' => ['TIMEOUT'], 'partition' => 'batch,gpu'],
				['job_state' => ['NODE_FAIL'], 'partition' => 'gpu'],
				['job_state' => ['PREEMPTED'], 'partition' => 'preempt'],
				['job_state' => ['CANCELLED by 1000'], 'partition' => 'batch'],
				['job_state' => ['FAILED'], 'partition' => 'batch'],
				// Active and still-completing jobs must not be counted as terminal exits.
				['job_state' => ['RUNNING'], 'partition' => 'batch'],
				['job_state' => ['FAILED', 'COMPLETING'], 'partition' => 'batch'],
			],
		];

		$exits = SlurmParser::jobExits($doc);

		expect($exits->total())->toBe(8)
			->and($exits->breakdown())->toBe([
				'OOM'       => 1,
				'TIMEOUT'   => 1,
				'NODE_FAIL' => 1,
				'PREEMPTED' => 1,
				'CANCELLED' => 1,
				'FAILED'    => 1,
				'COMPLETED' => 2,
			])
			->and($exits->failureRate())->toBe(0.75)
			->and($exits->count('gpu', JobExitReason::Oom))->toBe(1)
			->and($exits->count('gpu', JobExitReason::Timeout))->toBe(1)
			->and($exits->count('gpu', JobExitReason::NodeFail))->toBe(1)
			->and($exits->count('batch', JobExitReason::Timeout))->toBe(1)
			->and($exits->count('batch', JobExitReason::Cancelled))->toBe(1)
			->and($exits->count('batch', JobExitReason::Failed))->toBe(1)
			->and($exits->count('preempt', JobExitReason::Preempted))->toBe(1)
			->and($exits->partitions())->toBe(['batch', 'gpu', 'preempt'])
			->and($exits->toArray()['cluster']['OOM'])->toBe(1)
			->and(json_encode($exits->rows()))->not->toContain('alice');

		// Empty tally returns 0.0 failure rate cleanly.
		expect((new JobExitTally())->failureRate())->toBe(0.0);
	});
});
