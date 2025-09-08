<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\Api\DetrackApiService;
use App\Services\Api\LightspeedApiService;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;

class ManageDetrackJobs extends Controller
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    private DetrackApiService $detrackApiService;
    private LightspeedApiService $lightspeedApiService;

    public function __construct(DetrackApiService $detrackApiService, LightspeedApiService $lightspeedApiService){
        $this->detrackApiService  = $detrackApiService;
        $this->lightspeedApiService  = $lightspeedApiService;
    }

    public function handleSaleCompleted(Request $request)
    {
        DB::beginTransaction();
        try {
            // Decode JSON payload as array
            $payload = $request->json()->all();

            Log::info('NEW >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>', []);
            Log::info('Initial payload >>>>>', $payload);

            //Process base payload into structured bundle
            $bundle = $this->lightspeedApiService->processSalePayload($payload);

            //Always log extracted sale ID
            Log::info('Lightspeed sales id', ['sale_id' => $bundle['sale']['id'] ?? null]);

            if (!empty($bundle['sale'])) {
                // Fetch full sale from Lightspeed API
                $saleId = $bundle['sale']['id'];
                $bundle['sale'] = $this->lightspeedApiService->fetchSalesDetails($saleId);
            }

            Log::info('updated data::', ['updated bundle data' => $bundle]);

            // Extract attributes from full sale data
            $attributes = $bundle['sale']['data']['attributes'] ?? [];

            if ($this->lightspeedApiService->verifyFulFillmentIsDeliveryType($attributes)) {
                // Build detrack payload safely using bundle data

                $bundle['detrack_payload'] = [
                    'data' => [
                        'do_number'                 => $bundle['sale']['data']['id'], // Sale ID as unique delivery order number
                        'date'                      => now()->format('Y-m-d'),
                        'type'                      => 'Delivery',
                        'address'                   => $bundle['customer']['address'] ?? 'Not Set',
                        'phone_number'              => $bundle['customer']['phone'] ?: ($bundle['customer']['mobile'] ?: null),
                        'instructions'              => $bundle['sale']['data']['note'] ?? null,
                        'company_name'              => $bundle['customer']['company_name'] ?? null,
                        'postal_code'               => $bundle['customer']['postal_code'] ?? null,
                        'customer'                  => ($bundle['customer']['first_name'] ?? '') . ' ' . ($bundle['customer']['last_name'] ?? ''),
                        'job_price'                 => $bundle['sale']['data']['total_price'] ?? '',
                        'total_price'               => $bundle['sale']['data']['total_price_incl'] ?? '',
                        'invoice_number'            => $bundle['sale']['data']['invoice_number'] ?? '',
                        'notify_email'              => $bundle['customer']['email'] ?? '',
                        'items'                     => collect($bundle['line_items'] ?? [])->map(function ($item) {
                            return [
                            'sku'                   => $item['sku'] ?? null,
                            'description'           => $item['name'] ?? $item['description'] ?? '',
                            'quantity'              => $item['quantity'] ?? 0
                            ];
                        })->toArray()
                    ]
                ];


                $this->detrackApiService->sendDetrackPayloadData($bundle['detrack_payload'] ?? null);
            }

            DB::commit();
        } catch (\Exception $e) {
            Log::info('Detrack Exception::something went wrong', [
                'error message >>>>>' => $e->getMessage(),
                '<<<< error code >>>>>' => $e->getCode(),
                '<<<<< Full exception >>>>>>' => $e,
            ]);
            DB::rollBack();
        }

        return response()->json(['status' => 'ok']);
    }

    public function handleSaleUpdated(Request $request)
    {
        DB::beginTransaction();
        try {
            // Decode JSON payload as array
            $payload = $request->json()->all();

            Log::info('UPDATED >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>', []);
            Log::info('Initial UPDATE payload >>>>>', $payload);

            //Process base payload into structured bundle
            $bundle = $this->lightspeedApiService->processSalePayload($payload);

            //Always log extracted sale ID
            Log::info('Lightspeed UPDATED sales id', ['sale_id' => $bundle['sale']['id'] ?? null]);
            if (!empty($bundle['sale'])) {
                // Fetch full sale from Lightspeed API
                $saleId = $bundle['sale']['id'];
                $bundle['sale'] = $this->lightspeedApiService->fetchSalesDetails($saleId);
            }

            Log::info('updated data::', ['UPDATED sales bundle data' => $bundle]);


            DB::commit();
        } catch (\Exception $e) {
            Log::info('Detrack Exception::something went wrong', [
                'error message >>>>>' => $e->getMessage(),
                '<<<< error code >>>>>' => $e->getCode(),
                '<<<<< Full exception >>>>>>' => $e,
            ]);
            DB::rollBack();
        }

        return response()->json(['status' => 'ok']);
    }

}
