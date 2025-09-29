<?php

namespace App\Services\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class LightspeedApiService {

    /**
     * @param $payload
     * @return array
     */
    public function processSalePayload($payload): array
    {
        $bundle = [];

        // Extract customer info
        if (isset($payload['customer'])) {
            $customer = $this->fetchCustomerDetails($payload['customer']['id']);
            Log::info('Lightspeed customer >>>>', ['fetched customer' => $customer ?? null]);
            // Build full physical address (ignore empty fields)
            $physicalAddress = collect([
                $customer['data']['physical_address_1'] ?? null,
                $customer['data']['physical_address_2'] ?? null,
                $customer['data']['physical_suburb'] ?? null,
                $customer['data']['physical_city'] ?? null,
                $customer['data']['physical_state'] ?? null,
                $customer['data']['physical_postcode'] ?? null,
                $customer['data']['physical_country_id'] ?? null,
            ])->filter()->implode(', ');
            $bundle['customer'] = [
                'id'                => $customer['data']['id'] ?? null,
                'first_name'        => $customer['data']['first_name'] ?? null,
                'last_name'         => $customer['data']['last_name'] ?? null,
                'customer_code'     => $customer['data']['customer_code'] ?? null,
                'email'             => $customer['data']['email'] ?? null,
                'balance'           => $customer['data']['balance'] ?? null,
                'loyalty_balance'   => $customer['data']['loyalty_balance'] ?? null,
                'note'              => $customer['data']['note'] ?? null,
                'created_at'        => $customer['data']['created_at'] ?? null,
                // Optionally include address/phone if present in payload
                'phone'             => $customer['data']['phone'] ?? null,
                'mobile'            => $customer['data']['mobile'] ?? null,
                'postal_postcode'   => $customer['data']['postal_postcode'] ?? null,
                'company_name'      => $customer['data']['company_name'] ?? null,
            ];
            $bundle['customer']['address'] = $physicalAddress;
        }

        // Extract sale info
        if (isset($payload['sale'])) {
            $sale = $payload['sale'];

            $bundle['sale'] = [
                'id'                => $sale['id'] ?? null,
                'status'            => $sale['status'] ?? null,
                'state'             => $sale['state'] ?? null,
                'note'              => $sale['note'] ?? null,
                'job_price'         => $sale['total_price'] ?? null,
                'total_price'       => $sale['total_price_incl'] ?? null,
                'sale_date'         => $sale['sale_date'] ?? null,
            ];

            // Extract line items into array
            $bundle['line_items'] = collect($sale['line_items'] ?? [])->map(function ($item) {

                $sku = $item['product']['sku'] ?? null;
                $name = $item['product']['name'] ?? null;
                $retailPrice = $item['product']['default_price']['amount'] ?? ($item['product']['prices'][0]['amount'] ?? null);
                $quantity = $item['quantity'] ?? 0;
                // Separate integer and decimal parts
                $integerQuantity = floor($quantity);
                $decimalPart = $quantity - $integerQuantity;

                // Fallback: fetch product details by ID if SKU still null
                if ((!$sku || !$name || !$retailPrice) && isset($item['product_id'])) {
                    $productDetails = $this->fetchProductById($item['product_id']);
                    $sku = $sku ?: ($productDetails['sku'] ?? null);
                    $name = $name ?: ($productDetails['name'] ?? null);
                    $retailPrice = $retailPrice ?: ($productDetails['default_price']['amount'] ?? ($productDetails['prices'][0]['amount'] ?? null));
                }

                return [
                    'id'             => $item['id'] ?? null,
                    'product_id'     => $item['product_id'] ?? null,
                    'sku'            => $sku,
                    'name'           => $name,
                    'retail_price'   => $retailPrice,
                    'quantity'       => $decimalPart > 0 ? null : $quantity,
                    'weight'         => $decimalPart > 0 ? $quantity : null,
                    'tax_total'      => $item['tax_total'] ?? null,
                    'tax_components' => $item['tax_components'] ?? [],
                    'fulfilment_type'=> $item['fulfilment_type'] ?? null,
                    'description'    => trim(($sku ? "{$sku} - " : '') . ($name ?? '') . ($retailPrice ? " - $" . number_format($retailPrice, 16) : '')),
                ];
            })->toArray();
        }

        return $bundle;
    }

    /**
     * @param $saleId
     * @return array|mixed
     * @throws ConnectionException
     */
    public function fetchSalesDetails($saleId): mixed
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('LIGHTSPEED_ACCESS_TOKEN'),
        ])->get("https://nicebackyard.retail.lightspeed.app/api/2.0/sales/{$saleId}?expand=line_items,product");

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        Log::error('Failed to fetch Lightspeed sale details.', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        return [];
    }

    /**
     * @param $saleInfo
     * @return bool
     */
    public function verifyFulFillmentIsDeliveryType($saleInfo): bool
    {
        // Ensure it's always an array
        if (!is_array($saleInfo)) {
            return false;
        }

        // Check if "delivery" is present in attributes array
        return in_array('delivery', $saleInfo, true);
    }

    public function fetchCustomerDetails($customerId)
    {
        $response = Http::withHeaders([
            'Authorization' => 'Bearer ' . env('LIGHTSPEED_ACCESS_TOKEN'),
        ])->get("https://nicebackyard.retail.lightspeed.app/api/2.0/customers/{$customerId}?expand=addresses,phones");

        if ($response->successful()) {
            return $response->json() ?? [];
        }

        Log::error('Failed to fetch Lightspeed customer details.', [
            'status' => $response->status(),
            'body'   => $response->body(),
        ]);

        return [];
    }

    protected function fetchProductById(string $productId): array
    {
        try {
            $response = Http::withHeaders(['Authorization' => 'Bearer ' . env('LIGHTSPEED_ACCESS_TOKEN')])
                ->get("https://nicebackyard.retail.lightspeed.app/api/2.0/products/{$productId}?expand=prices,default_price");

            if ($response->successful()) {
                return $response->json()['data'] ?? [];
            }

            Log::error("Failed to fetch product {$productId}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error("Exception fetching product {$productId}: " . $e->getMessage());
        }

        return [];
    }

    public function processUpdateSalesPayload($payload): array
    {
        $bundle = [];
        // Extract sale info
        if (isset($payload['sale'])) {
            $sale = $payload['sale'];
            $bundle['sale'] = [
                'id'                => $sale['id'] ?? null,
                'status'            => $sale['status'] ?? null,
                'state'             => $sale['state'] ?? null,
                'note'              => $sale['note'] ?? null
            ];
        }

        return $bundle;
    }

    public function fetchFulfillmentDetails($saleId): array
    {
        try {
            $response = Http::withOptions(['verify' => false])
                ->withHeaders([
                    'Authorization' => 'Bearer ' . env('LIGHTSPEED_ACCESS_TOKEN'),
                    'Accept'        => 'application/json',
                ])
                ->get("https://nicebackyard.retail.lightspeed.app/api/2.0/fulfillments", [
                    'sale_id'   => $saleId,
                    'page_size' => 50,
                ]);

            if ($response->successful()) {
                $data = $response->json() ?? [];
                Log::info("Fulfillment details for sale {$saleId}", $data);
                return $data;
            }

            Log::error("Failed to fetch fulfillment details for sale {$saleId}", [
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } catch (\Exception $e) {
            Log::error("Exception fetching fulfillment details for sale {$saleId}: " . $e->getMessage());
        }

        return [];
    }

}