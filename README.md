# Simple PHP Job Queue
Here's the basics (will fill out later......ha!)

## Install
```bash
composer require n0nag0n/simple-job-queue
```

## Usage
### Adding a new job
```php
<?php

use n0nag0n\Job_Queue

// default is mysql based job queue
$Job_Queue = new Job_Queue;

$Job_Queue->selectPipeline('send_important_emails');
$Job_Queue->addJob(json_encode([ 'something' => 'that', 'ends' => 'up', 'a' => 'string' ]));
```

### Running a worker
See `example_worker.php`