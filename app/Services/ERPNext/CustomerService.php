<?php

namespace App\Services\ERPNext;

use App\Models\CustomerMap;
use App\Models\SyncLog;
use App\Services\ERPNext\ErpnextApiService;
use Illuminate\Support\Facades\Log;

class CustomerService
{
    protected ErpnextApiService $erpnextService;

    public function __construct(ErpnextApiService $erpnextService)
    {
        $this->erpnextService = $erpnextService;
    }

    /**
     * Sync Shopify customer to ERPNext.
     *
     * @param array $payload Shopify customer payload
     * @return string|null ERPNext Customer ID
     * @throws \Exception
     */
    public function syncCustomer(array $payload): ?string
    {
        $shopifyCustomerId = (string) ($payload['id'] ?? '');

        if (empty($shopifyCustomerId)) {
            Log::error('Shopify customer ID is missing in payload');
            return null;
        }

        // Check if customer mapping already exists
        $customerMap = CustomerMap::where('shopify_customer_id', $shopifyCustomerId)->first();
        if ($customerMap) {
            Log::info('Customer already synced to ERPNext', [
                'shopify_customer_id' => $shopifyCustomerId,
                'erp_customer_id' => $customerMap->erp_customer_id
            ]);
            return $customerMap->erp_customer_id;
        }

        try {
            // Determine Customer Name
            $firstName = $payload['first_name'] ?? '';
            $lastName = $payload['last_name'] ?? '';
            $customerName = trim($firstName . ' ' . $lastName);
            if (empty($customerName)) {
                $customerName = $payload['email'] ?? ('Shopify Customer ' . $shopifyCustomerId);
            }

            // Determine Customer Type (Company vs Individual)
            $companyName = null;
            if (!empty($payload['default_address']['company'])) {
                $companyName = $payload['default_address']['company'];
            } elseif (!empty($payload['addresses']) && is_array($payload['addresses'])) {
                foreach ($payload['addresses'] as $address) {
                    if (!empty($address['company'])) {
                        $companyName = $address['company'];
                        break;
                    }
                }
            }
            $customerType = $companyName ? 'Company' : 'Individual';

            // Construct ERPNext Customer Payload
            $customerData = [
                'customer_name' => $customerName,
                'customer_type' => $customerType,
                // 'customer_group' => env('ERPNEXT_CUSTOMER_GROUP', 'Individual'),
                // 'territory' => env('ERPNEXT_TERRITORY', 'All Territories'),
            ];

            // if (!empty($payload['email'])) {
            //     $customerData['email_id'] = $payload['email'];
            // }
            // if (!empty($payload['phone'])) {
            //     $customerData['mobile_no'] = $payload['phone'];
            // }

            // Call ERPNext API Service to create Customer
            $response = $this->erpnextService->createCustomer($customerData);

            $erpCustomerId = $response['data']['name'] ?? $response['name'] ?? null;

            if (!$erpCustomerId) {
                throw new \Exception("Failed to retrieve ERPNext customer ID from response: " . json_encode($response));
            }

            // Save mapping
            CustomerMap::create([
                'shopify_customer_id' => $shopifyCustomerId,
                'erp_customer_id' => $erpCustomerId,
            ]);

            // Save SyncLog
            SyncLog::create([
                'entity_type' => 'Customer',
                'entity_id' => $shopifyCustomerId,
                'direction' => 'Shopify -> ERPNext',
                'status' => 'success',
                'payload' => json_encode($customerData),
                'error_message' => null
            ]);

            Log::info('Successfully synced Shopify Customer to ERPNext', [
                'shopify_customer_id' => $shopifyCustomerId,
                'erp_customer_id' => $erpCustomerId
            ]);

            return $erpCustomerId;

        } catch (\Exception $e) {
            SyncLog::create([
                'entity_type' => 'Customer',
                'entity_id' => $shopifyCustomerId,
                'direction' => 'Shopify -> ERPNext',
                'status' => 'failed',
                'payload' => json_encode($payload),
                'error_message' => $e->getMessage()
            ]);

            Log::error('Failed to sync Shopify Customer to ERPNext', [
                'shopify_customer_id' => $shopifyCustomerId,
                'error' => $e->getMessage()
            ]);

            throw $e;
        }
    }
}
