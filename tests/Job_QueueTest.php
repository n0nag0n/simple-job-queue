<?php

use PHPUnit\Framework\TestCase;
use n0nag0n\Job_Queue;

class Job_QueueTest extends TestCase {
	/**
	 * PDO Connection
	 *
	 * @var PDO
	 */
	protected $pdo;

	/**
	 * Job Queue placeholder
	 *
	 * @var Job_Queue
	 */
	protected $jq;

	public function setUp(): void {
		$this->pdo = new PDO('sqlite::memory:', 'test', 'test');
		$this->jq = new Job_Queue('sqlite');
		$this->jq->addQueueConnection($this->pdo);
	}

	public function tearDown(): void {
		$this->pdo->exec('DROP TABLE job_queue_jobs;');
		$this->jq->flushCache();
		unset($this->jq);
	}

	public function testMysqlConstruct(): void {
		$Job_Queue = new Job_Queue();
		$options = $Job_Queue->getOptions();
		$this->assertTrue($options['mysql']['use_compression']);
		$this->assertTrue($Job_Queue->isMysqlQueueType());

		$Job_Queue = new Job_Queue('mysql', [ 'mysql' => [ 'table_name' => 'new_table', 'use_compression' => false ]]);
		$options = $Job_Queue->getOptions();
		$this->assertFalse($options['mysql']['use_compression']);
		$this->assertSame('new_table', $options['mysql']['table_name']);

		try {
			$jq = new Job_Queue('');
			$this->fail('Shouldn\'t have failed');
		} catch(Exception $e) {
			$this->assertStringContainsString('Queue Type not defined (or defined properly...)', $e->getMessage());
		}
	}

	public function testSqliteConstruct(): void {
		$Job_Queue = new Job_Queue('sqlite');
		$this->assertSame(true, $Job_Queue->isSqliteQueueType());

		$Job_Queue = new Job_Queue('sqlite', [ 'sqlite' => [ 'table_name' => 'new_table' ]]);
		$options = $Job_Queue->getOptions();
		$this->assertSame(true, $Job_Queue->isSqliteQueueType());
		$this->assertSame('new_table', $options['sqlite']['table_name']);
	}

	public function testSqliteSelectPipeline(): void {
		$this->jq->selectPipeline('new_pipeline');
		$this->assertSame('new_pipeline', $this->jq->getPipeline());
	}

	public function testSqliteWatchPipeline(): void {
		$this->jq->watchPipeline('new_pipeline');
		$this->assertSame('new_pipeline', $this->jq->getPipeline());
	}

	public function testRunPreChecks():void {
		try {
			$this->jq->addJob('');
			$this->fail('Shouldn\'t have failed');
		} catch(Exception $e) {
			$this->assertStringContainsString('Pipeline/Tube needs to be defined first', $e->getMessage());
		}

		try {
			$jq = new Job_Queue;
			$jq->selectPipeline('pipe');
			$jq->addJob('');
			$this->fail('Shouldn\'t have failed');
		} catch(Exception $e) {
			$this->assertStringContainsString('You need to add the connection for this queue type via the addQueueConnection() method first.', $e->getMessage());
		}

		$this->jq->selectPipeline('pipeline');
		$this->jq->addJob('{"somethingcool":true}');

		$cache = $this->jq->getCache();
		$this->assertArrayHasKey('job-queue-table-check', $cache);
		$this->assertTrue($cache['job-queue-table-check']);
	}

	public function testSqliteAddJob():void {
		$this->jq->selectPipeline('pipeline');
		$result = $this->jq->addJob('{"somethingcool":true}');
		$this->assertSame('{"somethingcool":true}', $result['payload']);
		$this->assertGreaterThan(0, $result['id']);
		$this->assertCount(2, $result);
		$this->assertIsInt($result['id']);

		$result2 = $this->jq->addJob('{"somethingcool":false,"whateverkey":null}');
		$this->assertSame('{"somethingcool":false,"whateverkey":null}', $result2['payload']);
		$this->assertGreaterThan(0, $result2['id']);
		$this->assertCount(2, $result2);
		$this->assertIsInt($result2['id']);
		$this->assertGreaterThan($result['id'], $result2['id']);
	}

	public function testSqliteGetNextJob():void {
		$this->jq->selectPipeline('pipeline');
		$result = $this->jq->addJob('{"somethingcool":true}');

		$job = $this->jq->getNextJob();
		$this->assertSame($result, $job);

		// The other job is already reserved
		$new_job = $this->jq->getNextJob();
		$this->assertSame([], $new_job);

		// test priority
		$this->jq->addJob('{"priority":20}', 0, 20);
		$this->jq->addJob('{"priority":10}', 0, 10);
		$this->jq->addJob('{"priority":30}', 0, 30);
		$priority_job = $this->jq->getNextJob();
		$this->assertSame('{"priority":10}', $priority_job['payload']);
		$priority_job = $this->jq->getNextJob();
		$this->assertSame('{"priority":20}', $priority_job['payload']);
		$priority_job = $this->jq->getNextJob();
		$this->assertSame('{"priority":30}', $priority_job['payload']);
	}

	public function testSqliteDeleteJob() {
		$this->jq->selectPipeline('pipeline');
		$job = $this->jq->addJob('{"somethingcool":true}');
		$this->jq->deleteJob($job);
		$next_job = $this->jq->getNextJob();
		$this->assertSame([], $next_job);
	}

	public function testSqliteBuryJob() {
		$this->jq->selectPipeline('pipeline');
		$job = $this->jq->addJob('{"somethingcool":true}');
		$this->jq->buryJob($job);
		$next_job = $this->jq->getNextJob();
		$this->assertSame([], $next_job);
	}

	public function testSqliteKickJob() {
		$this->jq->selectPipeline('pipeline');
		$job = $this->jq->addJob('{"somethingcool":true}');
		$this->jq->buryJob($job);
		$next_job = $this->jq->getNextJob();
		$this->assertSame([], $next_job);

		$this->jq->kickJob($job);
		$next_job = $this->jq->getNextJob();
		$this->assertSame('{"somethingcool":true}', $next_job['payload']);
	}

	public function testSqliteGetJobId() {
		$this->jq->selectPipeline('pipeline');
		$job = $this->jq->addJob('{"somethingcool":true}');
		$job_id = $this->jq->getJobId($job);
		$this->assertSame($job['id'], $job_id);
	}

	public function testSqliteGetJobPayload() {
		$this->jq->selectPipeline('pipeline');
		$job = $this->jq->addJob('{"somethingcool":true}');
		$payload = $this->jq->getJobPayload($job);
		$this->assertSame($job['payload'], $payload);
	}
}