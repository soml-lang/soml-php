<?php

declare(strict_types=1);

/**
Run a development command without a shell or interactive input, with a wall-clock limit. Always reap the process, including when it times out.

@param non-empty-list<string> $command
*/
function runProcess(array $command, float $timeoutSeconds = 300): int
{
	if (!is_finite($timeoutSeconds) || $timeoutSeconds <= 0) {
		throw new InvalidArgumentException('Use a positive finite process timeout');
	}

	$process = proc_open($command, [0 => ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);

	if (!is_resource($process)) {
		throw new RuntimeException('Cannot start the development process');
	}

	try {
		$start = hrtime(true);

		while (proc_get_status($process)['running']) {
			if ((hrtime(true) - $start) / 1e9 >= $timeoutSeconds) {
				throw new RuntimeException('The development process timed out');
			}

			usleep(10_000);
		}
	} finally {
		if (proc_get_status($process)['running']) {
			proc_terminate($process, 9);
		}

		$status = proc_close($process);
	}

	return $status;
}
