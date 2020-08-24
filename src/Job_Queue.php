<?php

namespace n0nag0n;
use PDO;
use Exception;

class Job_Queue {

	protected $queue_type, $db, $pipeline, $options;

	public function __construct(string $queue_type = 'mysql', array $options = []) {
		$this->queue_type = $queue_type;

		// set defaults
		$this->options = $options + [
			'mysql' => [
				'use_compression' => true,
				'table_name' => 'job_queue_jobs'
			]
		];
	}

	public function addDbConnection(PDO $db) {
		$this->db = $db;
	}

	/**
	 * This method is for adding/putting jobs into a queue
	 *
	 * @param string $pipeline
	 * @return Job_Queue
	 */
	public function selectPipeline(string $pipeline): Job_Queue {
		$this->pipeline = $pipeline;
		switch($this->queue_type) {
			case 'mysql':
				// do nothing
			break;
		}

		return $this;
	}

	/**
	 * This method is for workers that are processing jobs
	 *
	 * @param string $pipeline
	 * @return void
	 */
	public function watchPipeline(string $pipeline) {
		$this->pipeline = $pipeline;
		switch($this->queue_type) {
			case 'mysql':
				// do nothing
			break;
		}
	}

	protected function runPreChecks() {
		if(empty($this->pipeline)) {
			throw new Exception('Pipeline/Tube needs to be defined first');
		}
		if(empty($this->queue_type)) {
			throw new Exception('Queue Type not defined (or defined properly...)');
		}

		if($this->queue_type === 'mysql' && empty($this->db)) {
			throw new Exception('You need to add the database connection first friend.');
		}

		if($this->queue_type === 'mysql') {
			$Cache = \Cache::instance();
			$Cache->exists('job-queue-table-check', $exists);
			if(!empty($exists)) {
				$table_name = $this->db->quotekey($this->options['mysql']['table_name']);
				$has_table = !!count($this->db->exec("SHOW COLUMNS FROM {$table_name}"));
				if(!$has_table) {
					$field_type = $this->options['mysql']['use_compression'] ? 'longblob' : 'longtext';
					$this->db->exec("CREATE TABLE {$table_name}} (
						`id` int(11) NOT NULL AUTO_INCREMENT,
						`pipeline` varchar(500) NOT NULL,
						`payload` {$field_type} NOT NULL,
						`added_dt` datetime NOT NULL COMMENT 'In UTC',
						`send_dt` datetime NOT NULL COMMENT 'In UTC',
						`priority` int(11) NOT NULL,
						`is_reserved` tinyint(1) NOT NULL,
						`reserved_dt` datetime NULL COMMENT 'In UTC',
						`is_buried` tinyint(1) NOT NULL,
						`buried_dt` datetime NULL COMMENT 'In UTC',
						PRIMARY KEY (`id`),
						KEY `pipeline_send_dt_is_buried_is_reserved` (`pipeline`(75), `send_dt`, `is_buried`, `is_reserved`)
					);");
				}
				$Cache->set('job-queue-table-check', true, 86400);
			}
		}
	}

	public function addJob(string $payload, int $delay = 0, int $priority = 1024) {
		$this->runPreChecks();

		switch($this->queue_type) {
			case 'mysql':
				$table_name = $this->db->quotekey($this->options['mysql']['table_name']);
				$field_value = $this->options['mysql']['use_compression'] ? 'COMPRESS(?)' : '?';
				$delay_date_time = gmdate('Y-m-d H:i:s', strtotime('now +'.$delay.' seconds UTC'));
				$this->db->exec("INSERT INTO {$table_name} SET
					pipeline = ?,
					payload = {$field_value},
					added_dt = UTC_TIMESTAMP(),
					send_dt = ?,
					priority = ?,
					is_reserved = 0,
					reserved_dt = NULL,
					is_buried = 0
					", [
						$this->pipeline,
						$payload,
						$delay_date_time,
						$priority
					]);
				$job = [];
				$job['id'] = $this->db->lastInsertId();
				$job['payload'] = $payload;
			break;
		}

		return $job;
	}

	public function getNextJob() {
		$this->runPreChecks();
		switch($this->queue_type) {
			case 'mysql':
				$table_name = $this->db->quotekey($this->options['mysql']['table_name']);
				$field = $this->options['mysql']['use_compression'] ? 'UNCOMPRESS(payload) payload' : 'payload';
				$result = $this->db->exec("SELECT id, {$field}, added_dt, send_dt, priority, is_reserved, reserved_dt, is_buried, buried_dt FROM {$table_name} WHERE pipeline = ? AND send_dt <= UTC_TIMESTAMP() AND is_buried = 0 AND (is_reserved = 0 OR (is_reserved = 1 AND reserved_dt <= DATE_SUB(UTC_TIMESTAMP(), INTERVAL 5 MINUTE) ) ) ORDER BY priority ASC LIMIT 1", [ $this->pipeline ]);
				$job = [];
				if(count($result)) {
					$job = $result[0];
					$this->db->exec("UPDATE {$table_name} SET is_reserved = 1, reserved_dt = UTC_TIMESTAMP() WHERE id = ?", [ $job['id'] ]);
				}
			break;
		}

		return $job;
	}

	public function deleteJob($job): void {
		$this->runPreChecks();
		switch($this->queue_type) {
			case 'mysql':
				$table_name = $this->db->quotekey($this->options['mysql']['table_name']);
				$this->db->exec("DELETE FROM {$table_name} WHERE id = ?", [ $job['id'] ]);
			break;
		}
	}

	public function buryJob($job): void {
		$this->runPreChecks();
		switch($this->queue_type) {
			case 'mysql':
				$table_name = $this->db->quotekey($this->options['mysql']['table_name']);
				$this->db->exec("UPDATE {$table_name} SET is_buried = 1, buried_dt = UTC_TIMESTAMP(), is_reserved = 0, reserved_dt = NULL WHERE id = ?", [ $job['id'] ]);
			break;
		}
	}

	public function kickJob($job): void {
		$this->runPreChecks();
		switch($this->queue_type) {
			case 'mysql':
				$table_name = $this->db->quotekey($this->options['mysql']['table_name']);
				$this->db->exec("UPDATE {$table_name} SET is_buried = 0, buried_dt = NULL WHERE id = ?", [ $job['id'] ]);
			break;
		}
	}

	public function getJobId($job): int {
		switch($this->queue_type) {
			case 'mysql':
				return $job['id'];
		}
	}

	public function getJobPayload($job): string {
		switch($this->queue_type) {
			case 'mysql':
				return $job['payload'];
		}
	}
}