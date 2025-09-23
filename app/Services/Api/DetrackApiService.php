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

    /**
     * @param $doNumber
     * @param $payload
     * @param string $type
     * @return void
     * @throws ConnectionException
     */
    public function updateDetrackJob($doNumber, $payload, string $type = 'Delivery')
    {
        $url = "https://app.detrack.com/api/v2/dn/jobs/{$doNumber}?type={$type}";

        $response = Http::withOptions(['verify' => false])
            ->withHeaders([
                'X-API-Key'    => env('DETRACK_API_KEY'),
                'Content-Type' => 'application/json',
            ])
            ->put($url, $payload);

        if ($response->failed()) {
            Log::error('Detrack job update failed', [
                'do_number' => $doNumber,
                'payload'   => $payload,
                'status'    => $response->status(),
                'response'  => $response->body(),
            ]);
        } else {
            Log::info('Detrack job updated successfully', [
                'do_number' => $doNumber,
                'response'  => $response->json(),
            ]);
        }
    }

    /**
     * Delete a job from Detrack by do_number
     *
     * @param $doNumber
     * @param string $type
     * @throws ConnectionException
     */
    public function deleteDetrackJob($doNumber, string $type = 'Delivery')
    {
        $response = Http::withHeaders([
            'X-API-KEY' => env('DETRACK_API_KEY'),
            'Content-Type' => 'application/json',
        ])->delete("https://app.detrack.com/api/v2/dn/jobs/{$doNumber}?type={$type}");

        if ($response->status() === 404) {
            Log::warning("Detrack job not found (already deleted)", ['do_number' => $doNumber]);
            return;
        }

        if ($response->failed()) {
            Log::error("Failed to delete Detrack job", [
                'do_number' => $doNumber,
                'status' => $response->status(),
                'body'   => $response->body(),
            ]);
        } else {
            Log::info('Detrack job deleted successfully', [
                'do_number' => $doNumber,
                'response'  => $response->json(),
            ]);
        }
    }
}