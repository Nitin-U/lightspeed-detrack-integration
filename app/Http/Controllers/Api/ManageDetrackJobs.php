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

            Log::info('NEW >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>', ['Date' => now()]);
            Log::info('Initial payload >>>>>', $payload);

            //Process base payload into structured bundle
            $bundle = $this->lightspeedApiService->processSalePayload($payload);

            //Always log extracted sale ID
            Log::info('Lightspeed sales id', ['sale_id' => $bundle['sale']['id'] ?? null]);

            if (!empty($bundle['sale'])) {
                // Fetch full sale from Lightspeed API
                $saleId = $bundle['sale']['id'];
                $bundle['sale'] = $this->lightspeedApiService->fetchSalesDetails($saleId);
                $bundle['fulfillments'] = $this->lightspeedApiService->fetchFulfillmentDetails($saleId);
            }

            Log::info('updated data::', ['updated bundle data' => $bundle]);

            // Try to extract delivery date from fulfillment note
            $fulfillmentNote = $bundle['fulfillments']['data'][0]['note'] ?? null;

            $bundle['delivery_date'] = null;

            // Try to extract delivery date from fulfillment note
            $fulfillmentNote = $bundle['fulfillments']['data'][0]['note'] ?? null;
            $bundle['delivery_date'] = null;

            if (!empty($fulfillmentNote)) {
                $note = trim($fulfillmentNote);

                // Match date (YYYY-MM-DD, DD/MM/YYYY, YYYY/MM/DD, DD-MM-YYYY)
                if (preg_match('/(\d{4}-\d{2}-\d{2})|(\d{2}\/\d{2}\/\d{4})|(\d{4}\/\d{2}\/\d{2})|(\d{2}-\d{2}-\d{4})/', $note, $matches)) {
                    $dateStr = $matches[0];
                    $afterDateText = trim(str_replace($dateStr, '', $note)); // 🟢 everything after the date

                    // Convert DD/MM/YYYY → YYYY-MM-DD
                    if (strpos($dateStr, '/') !== false && substr_count($dateStr, '/') === 2) {
                        [$d, $m, $y] = explode('/', $dateStr);
                        $dateStr = "$y-$m-$d";
                    }

                    // Convert DD-MM-YYYY → YYYY-MM-DD
                    if (strpos($dateStr, '-') !== false && substr_count($dateStr, '-') === 2 && strlen(explode('-', $dateStr)[0]) === 2) {
                        [$d, $m, $y] = explode('-', $dateStr);
                        $dateStr = "$y-$m-$d";
                    }

                    $bundle['delivery_date'] = $dateStr;

                    // Map remaining text to address if provided
                    if (!empty($afterDateText)) {
                        $bundle['address_from_note'] = $afterDateText;
                    }
                }
            }

            // If no date was found in the note, set today's date
            if ($bundle['delivery_date'] === null) {
                $bundle['delivery_date'] = now()->toDateString();
            }

            // Extract attributes from full sale data
            $attributes = $bundle['sale']['data']['attributes'] ?? [];

            if ($this->lightspeedApiService->verifyFulFillmentIsDeliveryType($attributes) || ($request['create_job'] && $request->has('create_job'))) {

                /** Map per-item comments from sale.data.line_items */
                $lineItems = collect($bundle['line_items'] ?? []);
                $saleLineItems = collect(data_get($bundle, 'sale.data.line_items', []));

                $items = $lineItems->map(function ($item) use ($saleLineItems) {
                    $saleItem = $saleLineItems->firstWhere('id', $item['id']);
                    return [
                        'sku'         => $item['sku'] ?? null,
                        'description' => $item['name'] ?? $item['description'] ?? '',
                        'quantity'    => $item['quantity'] ?? null,
                        'weight'      => $item['weight'] ?? null,
                        'comments'    => $saleItem['note'] ?? null, // ✅ Item-level note mapping
                    ];
                })->toArray();

                // Build detrack payload safely using bundle data
                $bundle['detrack_payload'] = [
                    'data' => [
                        'do_number'                 => $bundle['sale']['data']['id'], // Sale ID as unique delivery order number
                        'date'                      => $bundle['delivery_date'],
                        'type'                      => 'Delivery',
                        'address'                   => $bundle['address_from_note'] ?? ($bundle['customer']['address'] ?? 'Not Set'),
                        'phone_number'              => $bundle['customer']['phone'] ?: ($bundle['customer']['mobile'] ?: null),
                        'instructions'              => $bundle['sale']['data']['note'] ?? null,
                        'company_name'              => $bundle['customer']['company_name'] ?? null,
                        'postal_code'               => $bundle['customer']['postal_code'] ?? null,
                        'customer'                  => ($bundle['customer']['first_name'] ?? '') . ' ' . ($bundle['customer']['last_name'] ?? ''),
                        'job_price'                 => $bundle['sale']['data']['total_price'] ?? '',
                        'total_price'               => $bundle['sale']['data']['total_price_incl'] ?? '',
                        'invoice_number'            => $bundle['sale']['data']['invoice_number'] ?? '',
                        'notify_email'              => $bundle['customer']['email'] ?? '',
                        'items'                     => $items,
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
            Log::info('UPDATE >>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>', ['Date' => now()]);
            // Decode JSON payload as array
            $payload = $request->json()->all();
            Log::info('Initial UPDATE payload >>>>>', $payload);

            //Process base payload into structured bundle
            $bundle = $this->lightspeedApiService->processUpdateSalesPayload($payload);
            $note   = strtolower($bundle['sale']['note'] ?? '');
            $status = strtolower($bundle['sale']['status'] ?? '');

            if (!empty($bundle['sale']) && (str_contains($note, '*cd') || $status == 'voided')){
                Log::info('Delete conditions for update matched:',['note'=> $note,'status' =>$status]);
                //if the note contains cancel delivery anywhere or if status is voided then remove the detrack job
                $this->detrackApiService->deleteDetrackJob($bundle['sale']['id']);
            }else if(!empty($bundle['sale']) && str_contains($note, '*ud')) {
                Log::info('Create New Job conditions for update matched:',['note'=> $note,'status' =>$status]);
                $request->request->add(['create_job' => true]);
                //if the keyword matches *ud, then create a new job through sale completed function
                $this->handleSaleCompleted($request);
            }else{
                Log::info('Delete/update for new job conditions for update did not match:',['note'=> $note,'status' =>$status]);
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

}