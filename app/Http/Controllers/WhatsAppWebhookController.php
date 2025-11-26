<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    /**
     * Verify the webhook.
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === env('WHATSAPP_VERIFY_TOKEN')) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle the webhook.
     */
    public function handle(Request $request)
    {
        Log::info('WhatsApp Webhook Received:', $request->all());

        // Process the webhook payload here

        return response('EVENT_RECEIVED', 200);
    }
}
