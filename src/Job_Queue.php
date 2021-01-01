<?php

namespace n0nag0n;
use PDO;
use Exception;
use Pheanstalk\Pheanstalk;

class Job_Queue {

	const QUEUE_TYPE_MYSQL = 'mysql';
	const QUEUE_TYPE_SQLITE = 'sqlite';
	const QUEUE_TYPE_BEANSTALKD = 'beanstalkd';

	/**
	 * The type of job queue to use
	 *
	 * @var string
	 */
	protected $queue_type;

	/**
	 * Generic Connection Holder
	 *
	 * @var mixed
	 */
	protected $connection; 

	/**
	 * Name of the pipeline to work with
	 *
	 * @var string
	 */
	protected $pipeline; 

	/**
	 * Set of options to pass into the job queue
	 *
	 * @var array
	 */
	protected $options = [];

	/**
	 * Array to store various variables and checks
	 *
	 * @var array
	 */
	protected static $cache = [];

	/**
	 * The construct
	 *
	 * @param string $queue_type - self::QUEUE_TYPE_MYSQL is default
	 * @param array $options
	 */
	public function __construct(string $queue_type = self::QUEUE_TYPE_MYSQL, array $options = []) {

		if(empty($queue_type)) {
			throw new Exception('Queue Type not defined (or defined properly...)');
		}

		$this->queue_type = $queue_type;

		// set defaults
		$this->setOptions($options + [
			'mysql' => [
				'use_compression' => true
			]
		]);
	}

	/**
	 * Sets the options from the construct (or otherwise...)
	 *
	 * @param array $options
	 * @return void
	 */
	public function setOptions(array $options = []): void {
		$this->options = $options;
	}

	/**
	 * Returns the options set in the construct (or by set options)
	 *
	 * @return array
	 */
	public function getOptions(): array {
		return $this->options;
	}

	/**
	 * Sets the pipeline to use
	 *
	 * @param string $pipeline
	 * @return void
	 */
	public function setPipeline(string $pipeline): void {
		$this->pipeline = $pipeline;
	}

	/**
	 * Gets the currently used pipeline.
	 *
	 * @return string
	 */
	public function getPipeline(): string {
		return $this->pipeline;
	}

	/**
	 * Gets the cache
	 *
	 * @return array
	 */
	public function getCache(): array {
		return self::$cache;
	}

	/**
	 * Drains the internal cache
	 *
	 * @return void
	 */
	public function flushCache(): void {
		self::$cache = [];
	}

	/**
	 * Adds a generic connection for the queue type selected
	 *
	 * @param mixed $db
	 * @return void
	 */
	public function addQueueConnection($connection) {
		$this->connection = $connection;
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
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				// do nothing
			break;

			case self::QUEUE_TYPE_BEANSTALKD:
				$this->connection->useTube($this->pipeline);
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
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				// do nothing
			break;

			case self::QUEUE_TYPE_BEANSTALKD:
				$this->connection->watch($this->pipeline)->ignore('default');
			break;
		}
	}

	/**
	 * Runs necessary checks to make sure the queue will work properly
	 *
	 * @return void
	 */
	protected function runPreChecks() {

		if(empty($this->pipeline)) {
			throw new Exception('Pipeline/Tube needs to be defined first');
		}

		if(empty($this->connection)) {
			throw new Exception('You need to add the connection for this queue type via the addQueueConnection() method first.');
		}

		if($this->isMysqlQueueType() || $this->isSqliteQueueType()) {
			$this->checkAndIfNecessaryCreateJobQueueTable();
		}

	}

	/**
	 * Adds a new job to the job queue
	 *
	 * @param string $payload
	 * @param integer $delay
	 * @param integer $priority
	 * @return void
	 */
	public function addJob(string $payload, int $delay = 0, int $priority = 1024, int $time_to_retry = 60) {
		$this->runPreChecks();

		switch($this->queue_type) {
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				$table_name = $this->getSqlTableName();
				$field_value = $this->isMysqlQueueType() && $this->options['mysql']['use_compression'] === true ? 'COMPRESS(?)' : '?';
				$delay_date_time = gmdate('Y-m-d H:i:s', strtotime('now +'.$delay.' seconds UTC'));
				$added_dt = gmdate('Y-m-d H:i:s');
				$time_to_retry_dt = gmdate('Y-m-d H:i:s', strtotime('now +'.$time_to_retry.' seconds UTC'));
				$statement = $this->connection->prepare("INSERT INTO {$table_name} (pipeline, payload, added_dt, send_dt, priority, is_reserved, reserved_dt, is_buried, attempts, time_to_retry_dt) VALUES (?, {$field_value}, ?, ?, ?, 0, NULL, 0, 0, ?)");
				$statement->execute([
					$this->pipeline,
					$payload,
					$added_dt,
					$delay_date_time,
					$priority,
					$time_to_retry_dt
				]);
				
				$job = [];
				$job['id'] = intval($this->connection->lastInsertId());
				$job['payload'] = $payload;
			break;

			case self::QUEUE_TYPE_BEANSTALKD:
				$job = $this->connection->put($payload, $priority, $delay, $time_to_retry);

			break;
		}

		return $job;
	}

	/**
	 * Gets the next available job and reserves it. Sorted by delay and priority
	 *
	 * @return mixed 
	 */
	public function getNextJobAndReserve() {
		$this->runPreChecks();
		$job = [];
		switch($this->queue_type) {
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				$table_name = $this->getSqlTableName();
				$field = $this->isMysqlQueueType() && $this->options['mysql']['use_compression'] === true ? 'UNCOMPRESS(payload) payload' : 'payload';
				$send_dt = gmdate('Y-m-d H:i:s');
				$reserved_dt = gmdate('Y-m-d H:i:s', strtotime('now -5 minutes UTC'));
				$statement = $this->connection->prepare("SELECT id, {$field}, added_dt, send_dt, priority, is_reserved, reserved_dt, is_buried, buried_dt
					FROM {$table_name} 
					WHERE pipeline = ? AND send_dt <= ? AND is_buried = 0 AND (is_reserved = 0 OR (is_reserved = 1 AND reserved_dt <= ? ) ) AND (attempts = 0 OR (attempts >= 1 AND time_to_retry_dt <= ?) )
					ORDER BY priority ASC LIMIT 1");
				$statement->execute([ $this->pipeline, $send_dt, $reserved_dt, $send_dt ]);
				$result = $statement->fetchAll(PDO::FETCH_ASSOC);
				if(count($result)) {
					$result = $result[0];
					$job = [
						'id' => intval($result['id']),
						'payload' => $result['payload']
					];
					$reserved_dt = gmdate('Y-m-d H:i:s');
					$statement = $this->connection->prepare("UPDATE {$table_name} SET is_reserved = 1, reserved_dt = ?, attempts = attempts + 1 WHERE id = ?");
					$statement->execute([ $reserved_dt, $job['id'] ]);
				}
			break;

			case self::QUEUE_TYPE_BEANSTALKD:
				$job = $this->connection->reserve();
			break;
		}

		return $job;
	}

	/**
	 * Gets the next available job. Sorted by delay and priority
	 * Requires `selectPipeline()` to be set.
	 *
	 * @return mixed 
	 */
	public function getNextBuriedJob() {
		$this->runPreChecks();
		$job = [];
		switch($this->queue_type) {
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				$table_name = $this->getSqlTableName();
				$field = $this->isMysqlQueueType() && $this->options['mysql']['use_compression'] === true ? 'UNCOMPRESS(payload) payload' : 'payload';
				$send_dt = gmdate('Y-m-d H:i:s');
				$reserved_dt = gmdate('Y-m-d H:i:s', strtotime('now -5 minutes UTC'));
				$statement = $this->connection->prepare("SELECT id, {$field}, added_dt, send_dt, priority, is_reserved, reserved_dt, is_buried, buried_dt
					FROM {$table_name} 
					WHERE pipeline = ? AND send_dt <= ? AND is_buried = 1
					ORDER BY priority ASC LIMIT 1");
				$statement->execute([ $this->pipeline, $send_dt, $reserved_dt, $send_dt ]);
				$result = $statement->fetchAll(PDO::FETCH_ASSOC);
				if(count($result)) {
					$result = $result[0];
					$job = [
						'id' => intval($result['id']),
						'payload' => $result['payload']
					];
				}
			break;

			case self::QUEUE_TYPE_BEANSTALKD:
				$job = $this->connection->peekBuried();
			break;
		}

		return $job;
	}

	/**
	 * Deletes a job
	 *
	 * @param mixed $job
	 * @return void
	 */
	public function deleteJob($job): void {
		$this->runPreChecks();
		switch($this->queue_type) {
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				$table_name = $this->getSqlTableName();
				$statement = $this->connection->prepare("DELETE FROM {$table_name} WHERE id = ?");
				$statement->execute([ $job['id'] ]);
			break;

			case self::QUEUE_TYPE_BEANSTALKD:
				$this->connection->delete($job);
			break;
		}
	}

	/**
	 * Buries (hides) a job
	 *
	 * @param mixed $job
	 * @return void
	 */
	public function buryJob($job): void {
		$this->runPreChecks();
		switch($this->queue_type) {
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				$table_name = $this->getSqlTableName();
				$buried_dt = gmdate('Y-m-d H:i:s');
				$statement = $this->connection->prepare("UPDATE {$table_name} SET is_buried = 1, buried_dt = ?, is_reserved = 0, reserved_dt = NULL WHERE id = ?");
				$statement->execute([ $buried_dt, $job['id'] ]);
			break;

			case self::QUEUE_TYPE_BEANSTALKD:
				$this->connection->bury($job);
			break;
		}
	}

	/**
	 * Kicks (releases, unburies) job
	 *
	 * @param mixed $job
	 * @return void
	 */
	public function kickJob($job): void {
		$this->runPreChecks();
		switch($this->queue_type) {
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				$table_name = $this->getSqlTableName();
				$statement = $this->connection->prepare("UPDATE {$table_name} SET is_buried = 0, buried_dt = NULL WHERE id = ?");
				$statement->execute([ $job['id'] ]);
			break;

			case self::QUEUE_TYPE_BEANSTALKD:
				$this->connection->kickJob($job);
			break;
		}
	}

	/**
	 * Gets the job id from given job
	 *
	 * @param mixed $job
	 * @return mixed
	 */
	public function getJobId($job) {
		switch($this->queue_type) {
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				return $job['id'];

			case self::QUEUE_TYPE_BEANSTALKD:
				return $job->getId();
			break;
		}
	}

	/**
	 * Gets the job payload from given job
	 *
	 * @param mixed $job
	 * @return string
	 */
	public function getJobPayload($job): string {
		switch($this->queue_type) {
			case self::QUEUE_TYPE_MYSQL:
			case self::QUEUE_TYPE_SQLITE:
				return $job['payload'];

			case self::QUEUE_TYPE_BEANSTALKD:
				return $job->getData();
			break;
		}
	}

	/**
	*	Return quoted identifier name
	*	@return string
	*	@param $key
	*	@param bool $split
	 **/
	protected function quoteDatabaseKey(string $key, bool $split = true): string {
		$delims = [
			'sqlite2?|mysql'=>'``',
			'pgsql|oci'=>'""',
			'mssql|sqlsrv|odbc|sybase|dblib'=>'[]'
		];
		$use='';
		foreach($delims as $engine=>$delim) {
			if(preg_match('/'.$engine.'/',$this->queue_type)) {
				$use = $delim;
				break;
			}
		}
		return $use[0].($split ? implode($use[1].'.'.$use[0],explode('.',$key))
			: $key).$use[1];
	}

	public function isMysqlQueueType(): bool {
		return $this->queue_type === self::QUEUE_TYPE_MYSQL;
	}

	public function isSqliteQueueType(): bool {
		return $this->queue_type === self::QUEUE_TYPE_SQLITE;
	}

	public function isBeanstalkdQueueType(): bool {
		return $this->queue_type === self::QUEUE_TYPE_BEANSTALKD;
	}

	protected function getSqlTableName(): string {
		$table_name = 'job_queue_jobs';
		if($this->isMysqlQueueType() && isset($this->options['sqlite']['table_name'])) {
			$table_name = $this->options['mysql']['table_name'];
		} else if($this->isSqliteQueueType() && isset($this->options['sqlite']['table_name'])) {
			$table_name = $this->options['sqlite']['table_name'];
		}
		return $this->quoteDatabaseKey($table_name);
	}

	protected function checkAndIfNecessaryCreateJobQueueTable(): void {
		$cache =& self::$cache;
		$exists = isset($cache['job-queue-table-check']);
		if(empty($exists)) {
			$table_name = $this->getSqlTableName();
			if($this->isMysqlQueueType()) {
				// Doesn't like this in a prepared statement...
				$escaped_table_name = $this->connection->quote($table_name);
				$statement = $this->connection->query("SHOW TABLES LIKE {$escaped_table_name}");
			} else {
				$statement = $this->connection->prepare("SELECT name FROM sqlite_master WHERE type='table' AND name = ?");
				$statement->execute([ $table_name ]);
			}
			
			$has_table = !!count($statement->fetchAll(PDO::FETCH_ASSOC));
			if(!$has_table) {
				if($this->isMysqlQueueType()) {
					$field_type = $this->options['mysql']['use_compression'] ? 'longblob' : 'longtext';
					$this->connection->exec("CREATE TABLE {$table_name} (
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
						`attempts` tinyint(4) NOT NULL,
						`time_to_retry_dt` datetime NULL,
						PRIMARY KEY (`id`),
						KEY `pipeline_send_dt_is_buried_is_reserved` (`pipeline`(75), `send_dt`, `is_buried`, `is_reserved`)
					);");
				} else {
					$this->connection->exec("CREATE TABLE {$table_name} (
						'id' INTEGER PRIMARY KEY AUTOINCREMENT NOT NULL,
						'pipeline' TEXT NOT NULL,
						'payload' TEXT NOT NULL,
						'added_dt' TEXT NOT NULL, -- COMMENT 'In UTC'
						'send_dt' TEXT NOT NULL, -- COMMENT 'In UTC'
						'priority' INTEGER NOT NULL,
						'is_reserved' INTEGER NOT NULL,
						'reserved_dt' TEXT NULL, -- COMMENT 'In UTC'
						'is_buried' INTEGER NOT NULL,
						'buried_dt' TEXT NULL, -- COMMENT 'In UTC'
						'time_to_retry_dt' TEXT NOT NULL,
						'attempts' INTEGER NOT NULL
					);");
					
					$this->connection->exec("CREATE INDEX pipeline_send_dt_is_buried_is_reserved ON {$table_name} ('pipeline', 'send_dt', 'is_buried', 'is_reserved'");
				}
			}
			$cache['job-queue-table-check'] = true;
		}
	}
}
