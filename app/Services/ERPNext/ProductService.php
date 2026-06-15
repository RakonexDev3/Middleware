<?php

namespace App\Services\ERPNext;

class ProductService extends ERPNextService
{
    /**
     * Fetch items from ERPNext that have been updated recently.
     */
    public function getUpdatedItems(string $sinceTimestamp = null)
    {
        $filters = [];
        if ($sinceTimestamp) {
            $filters[] = ['modified', '>', $sinceTimestamp];
        }

        $response = $this->client()->get('/Item', [
            'fields' => json_encode(['name', 'item_code', 'item_name', 'description', 'standard_rate', 'modified']),
            'filters' => json_encode($filters),
            'limit_page_length' => 100
        ]);

        if ($response->failed()) {
            throw new \Exception("Failed to fetch ERPNext items: " . $response->body());
        }

        return $response->json('data') ?? [];
    }
}
