<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Bus\Queueable;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ManageDetrackJobs extends Controller
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;
    protected $jobPayload;
    protected $saleId;

//    public function __construct(array $jobPayload, $saleId = null)
//    {
//        $this->jobPayload = $jobPayload;
//        $this->saleId = $saleId;
//    }

    public function handleSaleCompleted(Request $request)
    {
        $sale = $request->input('sale');
        $eventType = $request->input('event_type');

        // Log everything for debugging
        Log::info('Lightspeed Event Received', $request->all());

        // Check if sale is completed
        if (isset($sale['status']) && strtolower($sale['status']) === 'closed') {
            Log::info('Sale Completed!', [
                'sale_id' => $sale['id'],
                'total' => $sale['total_price'] ?? null,
                'customer' => $sale['customer']['id'] ?? null,
            ]);
        }
        Log::info('No matching status found');

        return response()->json(['status' => 'ok']);
    }

//    public function handleSaleCompleted(Request $request)
//    {
//        // Log raw payload for inspection
//        Log::info('>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>>Lightspeed Webhook Received', $request->all());
//
//        // Return a success response so Lightspeed knows we accepted it
//        return response()->json(['status' => 'ok'], 200);
//    }
}
