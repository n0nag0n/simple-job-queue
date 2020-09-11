<?php
	$Job_Queue = new Job_Queue('mysql');
	$Job_Queue->addDbConnection($f3->db);
	$Job_Queue = $f3->job_queue;
	$Job_Queue->watchPipeline('some_cool_pipeline_name');
	while(true) {
		$job = $Job_Queue->getNextJob();
		if(empty($job)) {
			// adjust to whatever makes you sleep better at night
			usleep(500000);
			continue;
		}

		echo "Processing {$job['id']}\n";
		$payload = json_decode($job['payload'], true);
		$new_payload = $payload;
		unset($new_payload['webhook_url']);

		try {
			$result = doSomethingThatDoesSomething($payload);

			if($result === true) {
				$Job_Queue->deleteJob($job);
			} else {
				$Job_Queue->buryJob($job);
			}
		} catch(Exception $e) {
			$Job_Queue->buryJob($job);
		}
	}