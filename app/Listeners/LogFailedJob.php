<?php

namespace App\Listeners;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Facades\Log;

/**
 * این listener زمانی فراخوانی می‌شود که یک جاب ناموفق شود
 * می‌تواند برای logging و analytics استفاده شود
 */
class LogFailedJob
{
    public function handle(JobFailed $event)
    {
        $jobName = $event->job->resolveName();
        $exception = $event->exception;

        Log::error('جاب ناموفق شد', [
            'job_name' => $jobName,
            'job_id' => $event->job->getJobId(),
            'queue' => $event->job->getQueue(),
            'exception' => $exception->getMessage(),
            'attempts' => $event->job->attempts(),
            'max_tries' => $event->job->maxTries(),
        ]);

        // اگر خطا "Incomplete Class" است
        if (strpos($exception->getMessage(), 'incomplete class') !== false) {
            Log::warning('Incomplete Class Exception - جاب احتمالا retry شود', [
                'job_id' => $event->job->getJobId(),
                'message' => 'کلاس جاب پیدا نشد یا namespace تغییر کرده است'
            ]);
        }
    }
}
