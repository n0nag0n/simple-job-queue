# Simple PHP Job Queue
I wanted/needed a simple job queue that could be used on a database, but also used with other adapters like beanstalkd/redis/etc if needed. Didn't really see a good option for a standalone job queue for a database.

## Install
```bash
composer require n0nag0n/simple-job-queue
```

**Requirements:** PHP 7.4 or higher.

> **Note on Beanstalkd:** The `pda/pheanstalk` package is only needed if you use the Beanstalkd adapter. It is listed under `suggest` in `composer.json` (not a hard requirement) so pure database users are not forced to install it.

## Usage

In order for this to work, you need a way to add jobs to the queue and a way to process the jobs (a worker). Below are examples of how to add a job to the queue and how to process the job.

### Adding a new job
#### MySQL
```php
<?php

use n0nag0n\Job_Queue;

// default is mysql based job queue
$Job_Queue = new Job_Queue('mysql', [
	'mysql' => [
		'table_name' => 'new_table_name', // default is job_queue_jobs
		'use_compression' => false // default is true to use COMPRESS() and UNCOMPRESS() for payload
	]
]);

$PDO = new PDO('mysql:dbname=testdb;host=127.0.0.1', 'user', 'pass');
$Job_Queue->addQueueConnection($PDO);

$Job_Queue->selectPipeline('send_important_emails');
$Job_Queue->addJob(json_encode([ 'something' => 'that', 'ends' => 'up', 'a' => 'string' ]));
```

#### PostgreSQL
```php
<?php

use n0nag0n\Job_Queue;

// default is pgsql based job queue
$Job_Queue = new Job_Queue('pgsql', [
	'pgsql' => [
		'table_name' => 'new_table_name', // default is job_queue_jobs
	]
]);

$PDO = new PDO('pgsql:dbname=testdb;host=127.0.0.1', 'user', 'pass');
$Job_Queue->addQueueConnection($PDO);

$Job_Queue->selectPipeline('send_important_emails');
$Job_Queue->addJob(json_encode([ 'something' => 'that', 'ends' => 'up', 'a' => 'string' ]));
```

#### SQLite3
```php
<?php

use n0nag0n\Job_Queue;

// default is sqlite based job queue
$Job_Queue = new Job_Queue('sqlite', [
	'sqlite' => [
		'table_name' => 'new_table_name' // default is job_queue_jobs
	]
]);

$PDO = new PDO('sqlite:example.db');
$Job_Queue->addQueueConnection($PDO);

$Job_Queue->selectPipeline('send_important_emails');
$Job_Queue->addJob(json_encode([ 'something' => 'that', 'ends' => 'up', 'a' => 'string' ]));
```

#### Beanstalkd
```php
<?php

use n0nag0n\Job_Queue;

// default is beanstalkd based job queue
$Job_Queue = new Job_Queue('beanstalkd');

$pheanstalk = Pheanstalk\Pheanstalk::create('127.0.0.1');
$Job_Queue->addQueueConnection($pheanstalk);

$Job_Queue->selectPipeline('send_important_emails');
$Job_Queue->addJob(json_encode([ 'something' => 'that', 'ends' => 'up', 'a' => 'string' ]));
```

### Running a worker
See `example_worker.php` for file or see below:
```php
<?php
	$Job_Queue = new n0nag0n\Job_Queue('mysql');
	$PDO = new PDO('mysql:dbname=testdb;host=127.0.0.1', 'user', 'pass');
	$Job_Queue->addQueueConnection($PDO);
	$Job_Queue->watchPipeline('send_important_emails');
	while(true) {
		$job = $Job_Queue->getNextJobAndReserve();

		// adjust to whatever makes you sleep better at night (for database queues only, beanstalkd does not need this if statement)
		if(empty($job)) {
			usleep(500000);
			continue;
		}

		echo "Processing {$job['id']}\n";
		$payload = json_decode($job['payload'], true);

		try {
			// NOTE: doSomethingThatDoesSomething is an example placeholder for your own job handler.
			$result = doSomethingThatDoesSomething($payload);

			if($result === true) {
				$Job_Queue->deleteJob($job);
			} else {
				// this takes it out of the ready queue and puts it in another queue that can be picked up and "kicked" later.
				$Job_Queue->buryJob($job);
			}
		} catch(Exception $e) {
			$Job_Queue->buryJob($job);
		}
	}
```

### Handling long processes
Supervisord is going to be your jam. Look up the many, many articles on how to implement this.

### Configurable options
Several behaviors can be tuned via the `$options` array passed to the constructor (or `setOptions()`):

- `stale_timeout` (int, seconds): How long to wait before a reserved job is considered "stale" and can be re-claimed by another worker (DB backends only). Default: `300` (5 minutes). This default preserves the historical behavior exactly.

Example:
```php
$Job_Queue = new Job_Queue('mysql', [
    'stale_timeout' => 120,           // 2 minutes
    'mysql' => [ 'use_compression' => true ]
]);
```

### Additional methods (additive, non-breaking)
New methods have been added that do not change any existing behavior:

- `releaseJob($job)` — Un-reserves a job that was previously obtained via `getNextJobAndReserve()`, making it available again immediately (useful if processing was abandoned).
- `getReadyCount(?string $pipeline = null): int`
- `getBuriedCount(?string $pipeline = null): int`

These work for all supported backends. Pass a pipeline name to query a specific one, or omit to use the currently selected pipeline.

See the source or tests for usage examples. All new functionality follows the same "select/watch pipeline + connection" contract as the rest of the library.

### Testing
PHPUnit Tests with sqlite3 examples for the time being.
```
vendor/bin/phpunit
# or via composer
composer test
composer test-coverage   # requires Xdebug; enforces 100% coverage
```
