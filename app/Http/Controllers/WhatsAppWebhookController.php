<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
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

        if ($mode === 'subscribe' && $token === config('whatsapp.whatsapp_verify_token')) {
            return response($challenge, 200);
        }

        return response('Forbidden', 403);
    }

    /**
     * Handle the webhook.
     */
    public function handle(Request $request)
    {

        try {
            // Extract the webhook data
            $entry = $request->input('entry.0');

            if (! $entry) {
                return response('EVENT_RECEIVED', 200);
            }

            $changes = $entry['changes'][0] ?? null;

            if (! $changes) {
                return response('No changes data found in webhook', 200);
            }

            $field = $changes['field'];
            $value = $changes['value'];
            $messages = $value['messages'][0] ?? null;

            if (! $messages) {
                return response('EVENT_RECEIVED', 200);
            }

            $context = $messages['context'] ?? null;
            $from = $messages['from'];
            $id = $messages['id'];
            $type = $messages['type'];
            $timestamp = $messages['timestamp'];

            switch ($type) {
                case 'text':
                    switch ($messages['text']['body']) {
                        case '1':
                            $this->sendTextMessage($from, 'Option   1. Comming soon!');
                            break;
                        case '2':
                            $this->sendTextMessage($from, "Last 3 activities for Zoe Amani:\n\n- Boarded Bus at Junction Amboses Rd (Today, 8:15 AM)\n- Arrived at Kiota School (Today, 8:45 AM)\n- Departed for Home (Today, 3:10 PM)");

                            break;
                        case '3':
                            $this->sendTextMessage($from, 'Good news! There are no new critical alerts right now.');
                            break;
                        case '4':
                            $this->sendTextMessage($from, "Here is the live tracking link for Leo Mwangis current journey.\nThis link is valid for the next 30 minutes");
                            $this->sendTextMessage($from, 'https://www.terrasofthq.com/', true);
                            break;
                        case '5':
                            $this->sendTextMessage($from, 'Option 5. Comming soon!');
                            break;
                        case '6':
                            $this->sendTextMessage($from, 'Option 6. Comming soon!');
                            break;
                        default:
                            $this->sendTextMessage($from, 'I don\'t understand that.');
                            break;
                    }
                    break;

                case 'interactive':
                    $interactive = $messages['interactive'];
                    $interactive_type = $interactive['type'];

                    switch ($interactive_type) {
                        case 'nfm_reply':
                            $nfm_reply = $interactive['nfm_reply'];
                            $name = $nfm_reply['name'] ?? null;
                            $response_json_string = $nfm_reply['response_json'];

                            // Decode the JSON string to an associative array
                            $response_data = json_decode($response_json_string, true);

                            // Check if JSON decoding was successful
                            if (json_last_error() !== JSON_ERROR_NONE) {
                                Log::error('JSON decode error: '.json_last_error_msg());
                                break;
                            }

                            // Extract data
                            $guardian = $response_data['guardian'] ?? null;
                            $child = $response_data['child'] ?? null;
                            $school = $response_data['school'] ?? null;
                            $flow_token = $response_data['flow_token'] ?? null;

                            // Log the data (use json_encode to properly log arrays)
                            Log::info('Flow Response Received', [
                                'from' => $from,
                                'flow_name' => $name,
                                'flow_token' => $flow_token,
                                'guardian' => $guardian,
                                'child' => $child,
                                'school' => $school,
                            ]);

                            // Access individual fields from guardian
                            if ($guardian) {
                                $guardianFirstName = $guardian['first_name'] ?? null;
                                $guardianLastName = $guardian['last_name'] ?? null;
                                $guardianEmail = $guardian['email'] ?? null;
                                $guardianPhone = $guardian['phone'] ?? null;
                                $guardianIdDoc = $guardian['identification_document'] ?? null;
                                $guardianIdNumber = $guardian['identification_number'] ?? null;
                                $guardianDob = $guardian['dob'] ?? null;
                                $guardianGender = $guardian['gender'] ?? null;

                                Log::info('Guardian Details', [
                                    'name' => "$guardianFirstName $guardianLastName",
                                    'email' => $guardianEmail,
                                    'phone' => $guardianPhone,
                                    'id_type' => $guardianIdDoc,
                                    'id_number' => $guardianIdNumber,
                                    'dob' => $guardianDob,
                                    'gender' => $guardianGender,
                                ]);
                            }

                            // Access individual fields from child
                            if ($child) {
                                $childFirstName = $child['first_name'] ?? null;
                                $childMiddleName = $child['middle_name'] ?? null;
                                $childLastName = $child['last_name'] ?? null;
                                $childDob = $child['dob'] ?? null;
                                $childGender = $child['gender'] ?? null;

                                Log::info('Child Details', [
                                    'name' => trim("$childFirstName $childMiddleName $childLastName"),
                                    'dob' => $childDob,
                                    'gender' => $childGender,
                                ]);
                            }

                            // Access school selection
                            if ($school) {
                                $schoolId = $school['school_id'] ?? null;
                                Log::info('School Selection', ['school_id' => $schoolId]);
                            }

                            $message1 = "🎉Welcome aboard, $guardianFirstName!🎉 \nYour Amani account is all set up for $childFirstName $childLastName. You're now ready to receive real-time updates and have peace of mind.";
                            $this->sendTextMessage($from, $message1);

                            break;

                        default:
                            Log::warning('Unknown interactive type: '.$interactive_type);
                            break;
                    }
                    break;

                default:
                    Log::warning('Unknown message type: '.$type);
            }

        } catch (\Exception $e) {
            Log::error('Error processing WhatsApp webhook: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
        }

        $message2 = "Is there anything else I can help you with?\n\n1. Quick Status Update\n2. Last 3 Activities\n3. Critical Alerts\n4. Live Journey Link\n5. Authorize a Pickup\n6. Manage My Account\n";
        $this->sendTextMessage($from, $message2);

        return response('EVENT_RECEIVED', 200);
    }

    public function sendTextMessage($toNumber, $message, $previewUrl = false)
    {
        Log::info('Sending text message');
        try {
            $whatsappToken = config('whatsapp.whatsapp_access_token');
            $whatsappPhoneNumberId = config('whatsapp.whatsapp_phone_number_id');
            $whatsappGraphVersion = config('whatsapp.whatsapp_graph_version');

            Log::info('WhatsApp credentials', [
                'whatsapp_token' => $whatsappToken,
                'whatsapp_phone_number_id' => $whatsappPhoneNumberId,
                'whatsapp_graph_version' => $whatsappGraphVersion,
            ]);

            if (! $whatsappToken || ! $whatsappPhoneNumberId) {
                Log::error('WhatsApp credentials not configured');

                return [
                    'success' => false,
                    'error' => 'WhatsApp credentials not configured',
                ];
            }

            $response = Http::withToken($whatsappToken)
                ->post("https://graph.facebook.com/{$whatsappGraphVersion}/{$whatsappPhoneNumberId}/messages", [
                    'messaging_product' => 'whatsapp',
                    'recipient_type' => 'individual',
                    'to' => $toNumber,
                    'type' => 'text',
                    'text' => [
                        'preview_url' => $previewUrl,
                        'body' => $message,
                    ],
                ]);

            if ($response->successful()) {
                Log::info('Text message sent successfully', [
                    'to' => $toNumber,
                    'message_id' => $response->json()['messages'][0]['id'] ?? null,
                ]);

                return [
                    'success' => true,
                    'data' => $response->json(),
                ];
            } else {
                Log::error('Failed to send text message', [
                    'to' => $toNumber,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return [
                    'success' => false,
                    'error' => $response->json(),
                ];
            }

        } catch (\Exception $e) {
            Log::channel('stderr')->error('Exception sending text message', [
                'error' => $e->getMessage(),
                'to' => $toNumber,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle the webhook.
     */
    public function data_validation(Request $request)
    {
        Log::info('WhatsApp Webhook Received:', $request->all());

        try {

        } catch (\Exception $e) {
            Log::error('Error processing WhatsApp webhook: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response('EVENT_RECEIVED', 200);
    }
}
