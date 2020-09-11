# Simple PHP Job Queue
Here's the basics (will fill out later......ha!)

## Install
```bash
composer require n0nag0n/simple-job-queue
```

## Usage#
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
$Job_Queue->addDbConnection($PDO);

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
$Job_Queue->addDbConnection($PDO);

$Job_Queue->selectPipeline('send_important_emails');
$Job_Queue->addJob(json_encode([ 'something' => 'that', 'ends' => 'up', 'a' => 'string' ]));
```

### Running a worker
See `example_worker.php`