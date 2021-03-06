<?php

namespace Leo\lib;

class Combiner
{
	private $id;
	private $opts;

	private $currentRecord;
	private $batch;

	private $uploader;
	private $massUploader;
	private $upgradeable = false;
	private $checkpointer;

	private $startTime;
	private $totalSize;
	private $totalCount = 100;

	public function __construct($id, $opts, $uploader, $massUploader, $checkpointer)
	{
		$this->uploader = $uploader;
		$this->massUploader = $massUploader;
		$this->checkpointer = $checkpointer;

		$this->opts = array_merge(
			[
				"batch_size" => $uploader->batch_size,
				"record_size" => $uploader->record_size,
				"max_records" => $uploader->max_records,
				"duration" => $uploader->duration,
				"bytesPerSecond" => !empty($uploader->bytesPerSecond) ? $uploader->bytesPerSecond : 1024 * 1024 * 1.5,
			],
			$opts
		);
		$this->id = $id;
		$this->reset();
	}

	private function reset()
	{
		$this->resetCurrent();
		$this->resetBatch();
	}

	private function resetCurrent()
	{
		$this->currentRecord = [
			"data" => "",
			"correlation" => null,
			"length" => 0,
			"cnt" => 0
		];
	}

	private function resetBatch()
	{
		gc_collect_cycles(); //Without this I am getting a memory leak, must have some cyclic memory somewhere
		$this->batch = [
			"records" => [],
			"length" => 0,
			"cnt" => 0
		];
	}

	private function addCurrentRecord()
	{
		if ($this->currentRecord['length']) {
			if (
				$this->currentRecord['length'] + $this->batch['length'] >= $this->opts['batch_size'] ||
				count($this->batch['records']) >= $this->opts['max_records']
			) {
				$this->submitBatch();
				$this->resetBatch();
			}
			$this->batch['records'][] = $this->currentRecord;
			$this->batch['length'] += $this->currentRecord['length'];
			$this->batch['cnt'] += $this->currentRecord['cnt'];
			$this->resetCurrent();
		}
	}

	private function submitBatch()
	{
		if ($this->batch['length']) {
			$result = $this->uploader->sendRecords($this->batch);
			if (!$result['success']) {
				throw new \Exception(!empty($result['errorMessage']) ? $result['errorMessage'] : 'Unable to write events to the bus.');
			} else if (!empty($result['eid'])) {
				Utils::info($result);
				call_user_func($this->checkpointer, $result);
			} else {
				// it was successful. Log the result.
				Utils::info($result);
			}
		}
	}

	public function write($event, $obj, $correlation, $opts = [])
	{
		if (!$this->startTime) {
			$this->startTime = time();
		}

		// Not supported
//		if ($this->upgradeable && !(--$this->totalCount)) {
//			$this->totalCount = 100;
//			$seconds = max(1, (time() - $this->startTime));
//
//
//			$rate = $this->totalSize / $seconds;
//			if ($rate > $this->opts['bytesPerSecond']) {
//				Utils::log("Switching to mass upload");
//				$this->upgradeable = false;
//				$this->uploader = $this->massUploader;
//			}
//		}

		$record = [
			"id" => $this->id,
			"event" => $event,
			"payload" => $obj,
			"correlation_id" => $correlation,
			"event_source_timestamp" => isset($opts['timestamp']) ? $opts['timestamp'] : Utils::milliseconds(),
		];
		if (isset($opts['schedule'])) {
			$record['schedule'] = $opts['schedule'];
		}
		if (isset($opts['units'])) {
			$record['units'] = $opts['units'];
		}
		$append = null;

		if ($this->uploader->combine) {
			$string = json_encode($record) . "\n";
			$len = strlen($string);
			if ($len > $this->opts['record_size']) {
				Utils::error("Record size: $len > Max record size: {$this->opts['record_size']}" . PHP_EOL);
				Utils::info($record);
				throw new \Exception("record size is too large");
			}

			if ($len + $this->currentRecord['length'] >= $this->opts['record_size']) {
				$this->addCurrentRecord();
			}

			$this->totalSize += $len;

			$this->currentRecord['data'] .= $string;
			$this->currentRecord['length'] += $len;
			$this->currentRecord['cnt']++;
			$this->currentRecord['correlation'] = [
				"source" => isset($correlation['source']) ? $correlation['source'] : basename($_SERVER["SCRIPT_FILENAME"], '.php'),
				"start" => isset($correlation['id']) ? $correlation['id'] : $correlation['start'],
			];
			if (isset($correlation['end'])) {
				$this->currentRecord['correlation']['end'];
			}
		} else {
			$string = json_encode($record) . "\n";;
			$len = strlen($string);
			$this->totalSize += $len;

			$this->currentRecord = [
				"data" => $string,
				"length" => $len,
				"cnt" => 1,
				"correlation" => [
					"source" => isset($correlation['source']) ? $correlation['source'] : basename($_SERVER["SCRIPT_FILENAME"], '.php'),
					"start" => isset($correlation['id']) ? $correlation['id'] : $correlation['start'],
				]
			];
			$this->addCurrentRecord();
		}
	}

	public function end()
	{
		$this->addCurrentRecord();
		$this->submitBatch();
		$this->reset();
		$this->uploader->end();
	}
}
