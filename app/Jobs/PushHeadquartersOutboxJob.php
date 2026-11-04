<?php

namespace App\Jobs;

use App\Services\Headquarters\OutboxSender;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PushHeadquartersOutboxJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public int $timeout = 60;

    public function __construct()
    {
        // A sync queue would put HQ's HTTP request back in the business request.
        $this->onConnection('database');
    }

    public function handle(OutboxSender $sender): void
    {
        // The persistent outbox and hq:push own HTTP retries and claim recovery.
        $sender->send(1);
    }
}
