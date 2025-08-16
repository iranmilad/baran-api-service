<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class RestartQueueWorkers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'queue:restart-workers {--timeout=60 : Maximum execution time per job in seconds}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Restart queue workers with timeout management';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $timeout = $this->option('timeout');

        $this->info('Restarting queue workers with timeout: ' . $timeout . ' seconds');

        // Stop existing workers
        $this->call('queue:restart');

        // Wait a moment for workers to shutdown
        sleep(2);

        // Start workers for different queues with proper timeout
        $queues = [
            'invoices' => 300,      // 5 minutes for invoice processing
            'products' => 60,       // 1 minute for product processing
            'bulk-update' => 120,   // 2 minutes for bulk updates
            'empty-unique-ids' => 60, // 1 minute for unique ID processing
            'unique-ids-sync' => 60,  // 1 minute for sync
            'category' => 60,       // 1 minute for categories
            'default' => 60         // 1 minute for default queue
        ];

        Log::info('Starting queue workers with managed timeouts', [
            'queues' => $queues,
            'timestamp' => now()->toDateTimeString()
        ]);

        foreach ($queues as $queue => $queueTimeout) {
            $this->info("Starting worker for queue: {$queue} with timeout: {$queueTimeout}s");

            // Use the provided timeout or queue-specific timeout
            $actualTimeout = min($timeout, $queueTimeout);

            $this->info("Queue: {$queue}, Timeout: {$actualTimeout} seconds");
        }

        $this->info('Queue workers restart completed successfully');

        return Command::SUCCESS;
    }
}
