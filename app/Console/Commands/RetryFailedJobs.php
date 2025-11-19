<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Queue\Jobs\Job;

class RetryFailedJobs extends Command
{
    /**
     * نام و توضیح Command
     *
     * @var string
     */
    protected $signature = 'queue:retry-failed {--limit=50 : تعداد جاب‌هایی که باید retry شوند} {--queue= : نام صف خاص (مثلا woocommerce-sync)}';

    protected $description = 'مجدد اجرا کردن جاب‌های ناموفق - برای صفی خاص استفاده کنید';

    /**
     * اجرای Command
     *
     * @return int
     */
    public function handle()
    {
        $limit = $this->option('limit');
        $queueFilter = $this->option('queue');

        Log::info('شروع مجدد اجرای جاب‌های ناموفق', [
            'limit' => $limit,
            'queue_filter' => $queueFilter,
            'timestamp' => now()->toDateTimeString()
        ]);

        // دریافت جاب‌های ناموفق
        $query = DB::table('failed_jobs');

        if ($queueFilter) {
            $query->where('queue', $queueFilter);
        }

        $failedJobs = $query->limit($limit)->get();

        if ($failedJobs->isEmpty()) {
            $this->info('❌ جاب‌های ناموفقی برای retry پیدا نشد');
            Log::info('جاب‌های ناموفقی برای retry پیدا نشد', ['queue' => $queueFilter]);
            return 0;
        }

        $count = 0;
        $errors = 0;

        foreach ($failedJobs as $failedJob) {
            try {
                // محتوای جاب را decode کنید
                $payload = json_decode($failedJob->payload, true);

                // استخراج اطلاعات
                $jobName = $payload['displayName'] ?? 'Unknown';
                $queue = $failedJob->queue ?? 'default';

                if (!$payload) {
                    Log::error('خطا در decode کردن payload - Incomplete Class', [
                        'job_id' => $failedJob->id,
                        'queue' => $queue,
                        'exception' => $failedJob->exception ?? 'No exception info'
                    ]);
                    $errors++;
                    continue;
                }

                // درج جاب دوباره در صف
                // مهم: payload را بدون تغییر دوباره درج می‌کنیم
                DB::table('jobs')->insert([
                    'queue' => $queue,
                    'payload' => $failedJob->payload,
                    'attempts' => 0,
                    'reserved_at' => null,
                    'available_at' => now()->timestamp,
                    'created_at' => now()->timestamp,
                ]);

                // حذف از failed_jobs
                DB::table('failed_jobs')->where('id', $failedJob->id)->delete();

                $count++;

                Log::info('جاب ناموفق مجدد اجرا شد', [
                    'job_id' => $failedJob->id,
                    'job_name' => $jobName,
                    'queue' => $queue,
                    'uuid' => $failedJob->uuid ?? 'N/A'
                ]);

            } catch (\Exception $e) {
                $errors++;
                Log::error('خطا در retry کردن جاب', [
                    'job_id' => $failedJob->id,
                    'error' => $e->getMessage(),
                    'exception_type' => get_class($e)
                ]);
            }
        }

        // خلاصه
        $this->info('✅ ' . $count . ' جاب مجدد اجرا شد');
        if ($errors > 0) {
            $this->error('❌ ' . $errors . ' خطا در حین retry');
        }

        Log::info('پایان مجدد اجرای جاب‌های ناموفق', [
            'successful' => $count,
            'errors' => $errors,
            'queue_filter' => $queueFilter,
            'timestamp' => now()->toDateTimeString()
        ]);

        return $count > 0 ? 0 : 1;
    }
}
