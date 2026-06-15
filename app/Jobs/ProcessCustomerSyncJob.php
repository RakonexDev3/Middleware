<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

use App\Services\ERPNext\CustomerService;

class ProcessCustomerSyncJob implements ShouldQueue
{
    use Queueable;

    protected array $shopifyCustomerPayload;

    /**
     * Create a new job instance.
     */
    public function __construct(array $shopifyCustomerPayload)
    {
        $this->shopifyCustomerPayload = $shopifyCustomerPayload;
    }

    /**
     * Execute the job.
     */
    public function handle(CustomerService $customerService): void
    {
        $customerService->syncCustomer($this->shopifyCustomerPayload);
    }
}
