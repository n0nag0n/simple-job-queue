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
		$this->jq->addDbConnection($this->pdo);
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
}