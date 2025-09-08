<?php

namespace App\Services\Api;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class DetrackApiService {

    /**
     * @param $payload
     * @return void
     * @throws ConnectionException
     */
    public function sendDetrackPayloadData($payload){
        Log::info('Detrack payload', ['$payload' =>$payload]);

        if ($payload) {
            // Call Detrack API
            $response = Http::withOptions([
        'verify' => false,
    ])->withHeaders([
                'X-API-Key'    => env('DETRACK_API_KEY'),
                'Content-Type' => 'application/json',
            ])->post('https://app.detrack.com/api/v2/dn/jobs', $payload);

            if ($response->failed()) {
                Log::error('Detrack job creation failed', [
                    'payload'  => $payload,
                    'status'   => $response->status(),
                    'response' => $response->body(), // <-- raw response from Detrack
                ]);
            }else if ($response->successful()){
                Log::info('success', ['$response' => $response->json() ]);
            }

        }
    }
}
