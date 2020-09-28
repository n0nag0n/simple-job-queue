<?php
	$Job_Queue = new n0nag0n\Job_Queue('mysql');
	$PDO = new PDO('mysql:dbname=testdb;host=127.0.0.1', 'user', 'pass');
	$Job_Queue->addQueueConnection($PDO);
	$Job_Queue->watchPipeline('some_cool_pipeline_name');
	while(true) {
		$job = $Job_Queue->getNextJobAndReserve();
		if(empty($job)) {
			// adjust to whatever makes you sleep better at night
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