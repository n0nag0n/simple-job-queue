# Simple PHP Job Queue
I wanted/needed a simple job queue that could be used on a database, but also used with other adapters like beanstalkd/redis/etc if needed. Didn't really see a good option for a standalone job queue for a database.

## Install
```bash
composer require n0nag0n/simple-job-queue
```

## Usage
### Adding a new job
#### MySQL
```php
<?php

use n0nag0n\Job_Queue

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

use n0nag0n\Job_Queue

// default is mysql based job queue
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

use n0nag0n\Job_Queue

// default is mysql based job queue
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

use n0nag0n\Job_Queue

// default is mysql based job queue
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

### Testing
PHPUnit Tests with sqlite3 examples for the time being.
```
vendor/bin/phpunit
```
