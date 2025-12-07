<?php

namespace App\Http\Controllers;

use App\Services\WhatsAppFlowEncryption;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class WhatsAppWebhookController extends Controller
{
    private $encryption;

    public function __construct(WhatsAppFlowEncryption $encryption)
    {
        $this->encryption = $encryption;
    }

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
            Log::info('WhatsApp Webhook Request', $request->all());
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

            $userName = '';

            switch ($type) {
                case 'text':
                    // Check if user exists in Terrago - THIS IS THE KEY CHECK
                    $userCheck = $this->getTerragoUserByPhone($from);

                    if (! $userCheck['found']) {
                        // User not found - send registration flow
                        Log::info('User not found, sending registration flow', ['phone' => $from]);

                        $this->sendTextMessage($from, "👋 Welcome to Terra Go! Let's get you started.");

                        // Get schools data for the flow
                        $schoolsData = $this->getSchoolsData();

                        $this->sendFlowMessage(
                            $from,
                            '2933855406810730',
                            'Complete your account setup in just a few quick steps.',
                            'Complete Registration',
                            'guardian-'.uniqid().'-session',
                            [
                                'screen' => 'GUARDIAN_DETAILS',
                                'data' => [
                                    'schools' => $schoolsData,
                                ],
                            ],
                            'Welcome to Terra Go',
                            'Powered by terrasofthq.com',
                            'navigate'
                        );

                        return response('EVENT_RECEIVED', 200);
                    }

                    // User exists - proceed with normal flow
                    $user = $userCheck['data'];
                    $userName = $user['first_name'];

                    $this->handleTextMessage($messages, $user);
                    break;

                case 'interactive':
                    $userCheck = $this->getTerragoUserByPhone($from);

                    if ($userCheck['found']) {
                        return response('EVENT_RECEIVED', 200);
                    }

                    $this->handleInteractiveMessage($messages, $from);
                    break;

                default:
                    Log::warning('Unknown message type: '.$type);
            }

            $menuMessage = "Is there anything else I can help you with, {$userName}?\n\n";
            $menuMessage .= "1. Quick Status Update\n";
            $menuMessage .= "2. Last 3 Activities\n";
            $menuMessage .= "3. Critical Alerts\n";
            $menuMessage .= "4. Live Journey Link\n";
            $menuMessage .= "5. Authorize a Pickup\n";
            $menuMessage .= "6. Manage My Account\n";

            $this->sendTextMessage($from, $menuMessage);

        } catch (\Exception $e) {
            Log::error('Error processing WhatsApp webhook: '.$e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString(),
            ]);
        }

        return response('EVENT_RECEIVED', 200);
    }

    /**
     * Get schools data for the registration flow
     *
     * @return array
     */
    private function getSchoolsData()
    {
        return [
            [
                'id' => 'school_001',
                'title' => 'Nairobi Academy',
                'description' => 'Premier international school in Nairobi with IB curriculum',
            ],
            [
                'id' => 'school_002',
                'title' => 'Braeburn School',
                'description' => 'British curriculum school with excellent facilities',
            ],
            [
                'id' => 'school_003',
                'title' => 'International School of Kenya',
                'description' => 'Diverse international community, IB World School',
            ],
            [
                'id' => 'school_004',
                'title' => 'Brookhouse School',
                'description' => 'Modern campus with strong academic programs',
            ],
            [
                'id' => 'school_005',
                'title' => 'Peponi School',
                'description' => 'British curriculum boarding and day school',
            ],
            [
                'id' => 'school_006',
                'title' => 'Hillcrest International Schools',
                'description' => 'American curriculum with AP courses',
            ],
            [
                'id' => 'school_007',
                'title' => 'St. Mary\'s School',
                'description' => 'Top-rated 8-4-4 curriculum school',
            ],
            [
                'id' => 'school_008',
                'title' => 'Riara Springs School',
                'description' => 'Holistic education in a serene environment',
            ],
        ];
    }

    /**
     * Handle text messages
     */
    private function handleTextMessage($messages, $user)
    {
        $from = $messages['from'];
        $userName = $user['first_name'];
        $textBody = $messages['text']['body'];

        switch ($textBody) {
            case '1':
                $this->sendTextMessage($from, "Hi {$userName}! Option 1. Coming soon!");
                break;

            case '2':
                $this->sendTextMessage($from, "Last 3 activities for your child:\n\n- Boarded Bus at Junction Amboses Rd (Today, 8:15 AM)\n- Arrived at Kiota School (Today, 8:45 AM)\n- Departed for Home (Today, 3:10 PM)");
                break;

            case '3':
                $this->sendTextMessage($from, "Good news, {$userName}! There are no new critical alerts right now.");
                break;

            case '4':
                $this->sendTextMessage($from, "Here is the live tracking link for your child's current journey.\nThis link is valid for the next 30 minutes");
                $this->sendTextMessage($from, 'https://www.terrasofthq.com/', true);
                break;

            case '5':
                $this->sendTextMessage($from, 'Option 5. Coming soon!');
                break;

            case '6':
                // Show account details
                $accountInfo = "📋 *Account Information*\n\n";
                $accountInfo .= "Name: {$user['first_name']} {$user['last_name']}\n";
                $accountInfo .= "Email: {$user['email']}\n";
                $accountInfo .= "Phone: {$user['phone']}\n";
                $accountInfo .= "Role: {$user['role']}\n";
                $accountInfo .= "ID: {$user['identification_document']} - {$user['identification_number']}\n";
                $accountInfo .= 'Account Status: '.($user['is_active'] ? 'Active ✅' : 'Inactive ❌');

                $this->sendTextMessage($from, $accountInfo);
                break;

            default:
                $this->sendTextMessage($from, "Sorry {$userName}, I don't understand that. Please select from the menu options.");
                break;
        }
    }

    /**
     * Handle interactive messages (flows)
     */
    private function handleInteractiveMessage($messages, $from)
    {
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

                // Log the data
                Log::info('Flow Response Received', [
                    'from' => $from,
                    'flow_name' => $name,
                    'flow_token' => $flow_token,
                    'guardian' => $guardian,
                    'child' => $child,
                    'school' => $school,
                ]);

                // Register guardian first
                if ($guardian) {
                    $guardianResult = $this->registerGuardian($guardian);

                    if ($guardianResult['success']) {
                        $registeredGuardian = $guardianResult['data'];
                        $guardianId = $registeredGuardian['id'];

                        Log::info('Guardian registered successfully', [
                            'guardian_id' => $guardianId,
                            'name' => "{$registeredGuardian['first_name']} {$registeredGuardian['last_name']}",
                        ]);

                        // Register child/dependant
                        if ($child) {
                            $childResult = $this->registerDependant($child, $guardianId);

                            if ($childResult['success']) {
                                $registeredChild = $childResult['data'];

                                Log::info('Child registered successfully', [
                                    'child_id' => $registeredChild['id'],
                                    'name' => trim("{$registeredChild['first_name']} {$registeredChild['middle_name']} {$registeredChild['last_name']}"),
                                ]);

                                $childName = trim("{$registeredChild['first_name']} {$registeredChild['middle_name']} {$registeredChild['last_name']}");
                                $message = "🎉 Welcome aboard, {$registeredGuardian['first_name']}! 🎉\n\n";
                                $message .= "Your Terra Go account is all set up for {$childName}. You're now ready to receive real-time updates and have peace of mind! 🚌✨";

                                $this->sendTextMessage($from, $message);
                            } else {
                                Log::error('Failed to register child', ['error' => $childResult['error']]);
                                $this->sendTextMessage($from, 'Your account was created, but there was an issue registering your child. Please contact support.');
                            }
                        } else {
                            // Guardian registered but no child data
                            $message = "🎉 Welcome aboard, {$registeredGuardian['first_name']}! 🎉\n\n";
                            $message .= "Your Terra Go account is all set up. You're now ready to receive real-time updates! 🚌✨";

                            $this->sendTextMessage($from, $message);
                        }
                    } else {
                        Log::error('Failed to register guardian', ['error' => $guardianResult['error']]);
                        $this->sendTextMessage($from, 'Sorry, there was an issue creating your account. Please try again or contact support.');
                    }
                }
                break;

            default:
                Log::warning('Unknown interactive type: '.$interactive_type);
                break;
        }
    }

    /**
     * Register a guardian in Terrago system
     *
     * @param  array  $guardianData  Guardian data from flow
     * @return array
     */
    public function registerGuardian($guardianData)
    {
        Log::info('Registering guardian in Terrago');

        try {

            $tokenData = $this->getTerragoAccessToken();

            if (! $tokenData) {
                return [
                    'success' => false,
                    'error' => 'Failed to get access token',
                ];
            }

            $accessToken = $tokenData['access_token'];

            $terragosApiUrl = config('terrago.api_url', 'https://terragostg.terrasofthq.com');

            // Get Parent role ID dynamically
            $parentRoleId = $this->getTerragoRoleIdByName('Parent');

            if (! $parentRoleId) {
                // Fallback to config if dynamic fetch fails
                $parentRoleId = config('terrago.parent_role_id', '582c5dc9-4ef6-4238-98e3-7318c57349fb');
                Log::warning('Using fallback parent role ID from config', ['role_id' => $parentRoleId]);
            }

            // Format the date (DD-MM-YYYY to match API format)
            $dob = $guardianData['dob'] ?? null;
            if ($dob) {
                // If date is in YYYY-MM-DD format, convert to DD-MM-YYYY
                $dob = \Carbon\Carbon::parse($dob)->format('d-m-Y');
            }

            $payload = [
                'first_name' => $guardianData['first_name'] ?? '',
                'middle_name' => $guardianData['middle_name'] ?? '',
                'last_name' => $guardianData['last_name'] ?? '',
                'email' => $guardianData['email'] ?? '',
                'phone' => $guardianData['phone'] ?? '',
                'identification_document' => strtolower($guardianData['identification_document'] ?? 'national_id'),
                'identification_number' => $guardianData['identification_number'] ?? '',
                'dob' => $dob,
                'gender' => strtoupper($guardianData['gender'] ?? 'MALE'),
                'school' => '',
                'location' => '',
                'school_type' => '',
                'role_id' => $parentRoleId,
            ];

            Log::info('Guardian registration payload', $payload);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$accessToken}",
            ])->post("{$terragosApiUrl}/api/users/register", $payload);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Guardian registered successfully', [
                    'guardian_id' => $data['data']['id'] ?? null,
                    'message' => $data['message'] ?? null,
                ]);

                return [
                    'success' => true,
                    'data' => $data['data'],
                    'message' => $data['message'] ?? 'Guardian registered successfully',
                ];
            } else {
                Log::error('Failed to register guardian', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return [
                    'success' => false,
                    'error' => $response->json(),
                ];
            }

        } catch (\Exception $e) {
            Log::error('Exception registering guardian', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Register a dependant (child) in Terrago system
     *
     * @param  array  $childData  Child data from flow
     * @param  string  $parentId  Parent/Guardian ID
     * @return array
     */
    public function registerDependant($childData, $parentId)
    {
        Log::info('Registering dependant in Terrago', ['parent_id' => $parentId]);

        try {
            // Get access token
            $accessToken = cache()->get('terrago_access_token');

            if (! $accessToken) {
                $tokenData = $this->getTerragoAccessToken();

                if (! $tokenData) {
                    return [
                        'success' => false,
                        'error' => 'Failed to get access token',
                    ];
                }

                $accessToken = $tokenData['access_token'];
            }

            $terragosApiUrl = config('terrago.api_url', 'https://terragostg.terrasofthq.com');

            // Format the date (DD-MM-YYYY to match API format)
            $dob = $childData['dob'] ?? null;
            if ($dob) {
                // If date is in YYYY-MM-DD format, convert to DD-MM-YYYY
                $dob = \Carbon\Carbon::parse($dob)->format('d-m-Y');
            }

            $payload = [
                'first_name' => $childData['first_name'] ?? '',
                'middle_name' => $childData['middle_name'] ?? '',
                'last_name' => $childData['last_name'] ?? '',
                'dob' => $dob,
                'gender' => strtoupper($childData['gender'] ?? 'MALE'),
                'parent_id' => $parentId,
            ];

            Log::info('Dependant registration payload', $payload);

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
                'Authorization' => "Bearer {$accessToken}",
            ])->post("{$terragosApiUrl}/api/dependants/add", $payload);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Dependant registered successfully', [
                    'dependant_id' => $data['data']['id'] ?? null,
                    'message' => $data['message'] ?? null,
                ]);

                return [
                    'success' => true,
                    'data' => $data['data'],
                    'message' => $data['message'] ?? 'Dependant registered successfully',
                ];
            } else {
                Log::error('Failed to register dependant', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return [
                    'success' => false,
                    'error' => $response->json(),
                ];
            }

        } catch (\Exception $e) {
            Log::error('Exception registering dependant', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Get access token from Terrago API
     *
     * @return array|null Returns the token data or null on failure
     */
    public function getTerragoAccessToken()
    {
        Log::info('Getting Terrago access token');

        try {
            $terragosApiUrl = config('terrago.api_url', 'https://terragostg.terrasofthq.com');
            $terragosEmail = config('terrago.email');
            $terragosPassword = config('terrago.password');

            if (! $terragosEmail || ! $terragosPassword) {
                Log::error('Terrago credentials not configured');

                return null;
            }

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Content-Type' => 'application/json',
            ])->post("{$terragosApiUrl}/api/auth/access-token", [
                'email' => $terragosEmail,
                'password' => $terragosPassword,
            ]);

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Terrago access token retrieved successfully', [
                    'token_type' => $data['data']['token_type'] ?? null,
                    'expires_in' => $data['data']['expires_in'] ?? null,
                ]);

                // Cache the token for 55 minutes (expires in 60 minutes)
                cache()->put('terrago_access_token', $data['data']['access_token'], now()->addMinutes(55));
                cache()->put('terrago_token_expires_at', now()->addSeconds($data['data']['expires_in'] ?? 3600), now()->addMinutes(55));

                return $data['data'];
            } else {
                Log::error('Failed to get Terrago access token', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return null;
            }

        } catch (\Exception $e) {
            Log::error('Exception getting Terrago access token', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get Terrago user by phone number
     *
     * @param  string  $phoneNumber  The phone number to search for (e.g., 254706347307)
     * @param  bool  $forceRefreshToken  Force getting a new token instead of using cached one
     * @return array Returns array with 'found' boolean and 'data' if user exists
     */
    public function getTerragoUserByPhone($phoneNumber, $forceRefreshToken = false)
    {
        Log::info('Getting Terrago user by phone', ['phone' => $phoneNumber]);

        try {
            // Get access token from cache or fetch new one
            $accessToken = cache()->get('terrago_access_token');

            if (! $accessToken || $forceRefreshToken) {
                Log::info('Access token not in cache or force refresh, getting new token');
                $tokenData = $this->getTerragoAccessToken();

                if (! $tokenData) {
                    Log::error('Failed to get access token');

                    return [
                        'found' => false,
                        'error' => 'Failed to get access token',
                        'data' => null,
                    ];
                }

                $accessToken = $tokenData['access_token'];
            }

            $terragosApiUrl = config('terrago.api_url', 'https://terragostg.terrasofthq.com');

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$accessToken}",
            ])->get("{$terragosApiUrl}/api/users/show-by-phone/{$phoneNumber}");

            if ($response->status() === 200) {
                // User found
                $responseData = $response->json();

                Log::info('Terrago user found', [
                    'phone' => $phoneNumber,
                    'user_id' => $responseData['data']['id'] ?? null,
                    'name' => ($responseData['data']['first_name'] ?? '').' '.($responseData['data']['last_name'] ?? ''),
                ]);

                return [
                    'found' => true,
                    'status' => $responseData['status'],
                    'data' => $responseData['data'],
                    'message' => $responseData['message'] ?? 'User found',
                ];

            } elseif ($response->status() === 404) {
                // User not found
                Log::info('Terrago user not found', ['phone' => $phoneNumber]);

                return [
                    'found' => false,
                    'status' => 404,
                    'data' => null,
                    'message' => 'User not found',
                ];

            } elseif ($response->status() === 401 && ! $forceRefreshToken) {
                // Token expired, try once more with fresh token
                Log::warning('Token expired, retrying with fresh token');

                return $this->getTerragoUserByPhone($phoneNumber, true);

            } else {
                // Other error
                Log::error('Failed to get Terrago user', [
                    'phone' => $phoneNumber,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return [
                    'found' => false,
                    'status' => $response->status(),
                    'error' => 'API error: '.$response->status(),
                    'data' => null,
                ];
            }

        } catch (\Exception $e) {
            Log::error('Exception getting Terrago user', [
                'phone' => $phoneNumber,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return [
                'found' => false,
                'error' => $e->getMessage(),
                'data' => null,
            ];
        }
    }

    /**
     * Get all roles from Terrago API
     *
     * @param  bool  $forceRefresh  Force refresh the cached roles
     * @return array|null Returns roles data or null on failure
     */
    public function getTerragoRoles($forceRefresh = false)
    {
        Log::info('Getting Terrago roles');

        try {
            // Check cache first
            if (! $forceRefresh && cache()->has('terrago_roles')) {
                Log::info('Returning cached roles');

                return cache()->get('terrago_roles');
            }

            // Get access token
            $accessToken = cache()->get('terrago_access_token');

            if (! $accessToken) {
                $tokenData = $this->getTerragoAccessToken();

                if (! $tokenData) {
                    Log::error('Failed to get access token');

                    return null;
                }

                $accessToken = $tokenData['access_token'];
            }

            $terragosApiUrl = config('terrago.api_url', 'https://terragostg.terrasofthq.com');

            $response = Http::withHeaders([
                'Accept' => 'application/json',
                'Authorization' => "Bearer {$accessToken}",
            ])->get("{$terragosApiUrl}/api/roles");

            if ($response->successful()) {
                $data = $response->json();

                Log::info('Terrago roles retrieved successfully', [
                    'total_roles' => count($data['data'] ?? []),
                ]);

                // Cache roles for 24 hours (they don't change often)
                cache()->put('terrago_roles', $data['data'], now()->addHours(24));

                return $data['data'];
            } else {
                Log::error('Failed to get Terrago roles', [
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return null;
            }

        } catch (\Exception $e) {
            Log::error('Exception getting Terrago roles', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return null;
        }
    }

    /**
     * Get role ID by role name
     *
     * @param  string  $roleName  The name of the role (e.g., "Parent", "System Admin")
     * @return string|null Returns the role ID or null if not found
     */
    public function getTerragoRoleIdByName($roleName)
    {
        Log::info('Getting role ID by name', ['role_name' => $roleName]);

        try {
            $roles = $this->getTerragoRoles();

            if (! $roles) {
                Log::error('No roles data available');

                return null;
            }

            foreach ($roles as $role) {
                if (strcasecmp($role['name'], $roleName) === 0) {
                    Log::info('Role found', [
                        'role_name' => $roleName,
                        'role_id' => $role['id'],
                    ]);

                    return $role['id'];
                }
            }

            Log::warning('Role not found', ['role_name' => $roleName]);

            return null;

        } catch (\Exception $e) {
            Log::error('Exception getting role ID by name', [
                'role_name' => $roleName,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Get all roles as a key-value array (name => id)
     *
     * @return array Returns associative array of role names to IDs
     */
    public function getTerragoRoleMapping()
    {
        Log::info('Getting Terrago role mapping');

        try {
            $roles = $this->getTerragoRoles();

            if (! $roles) {
                return [];
            }

            $mapping = [];
            foreach ($roles as $role) {
                $mapping[$role['name']] = $role['id'];
            }

            Log::info('Role mapping created', ['roles' => array_keys($mapping)]);

            return $mapping;

        } catch (\Exception $e) {
            Log::error('Exception creating role mapping', [
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Send a WhatsApp Flow message
     *
     * @param  string  $toNumber  The recipient's phone number
     * @param  string  $flowId  The Flow ID from WhatsApp
     * @param  string  $bodyText  The body text of the message
     * @param  string  $ctaText  The call-to-action button text
     * @param  string  $flowToken  A unique token for this flow instance
     * @param  array  $flowActionPayload  Optional initial data for the flow
     * @param  array|string|null  $header  Optional header (text, image, document, or video)
     * @param  string|null  $footerText  Optional footer text
     * @param  string  $flowAction  The flow action type (navigate or data_exchange)
     * @return array
     */
    public function sendFlowMessage(
        $toNumber,
        $flowId,
        $bodyText,
        $ctaText = 'Continue',
        $flowToken = null,
        $flowActionPayload = [],
        $header = null,
        $footerText = null,
        $flowAction = 'navigate'
    ) {
        Log::info('Sending flow message');

        try {
            $whatsappToken = config('whatsapp.whatsapp_access_token');
            $whatsappPhoneNumberId = config('whatsapp.whatsapp_phone_number_id');
            $whatsappGraphVersion = config('whatsapp.whatsapp_graph_version');

            if (! $whatsappToken || ! $whatsappPhoneNumberId) {
                Log::error('WhatsApp credentials not configured');

                return [
                    'success' => false,
                    'error' => 'WhatsApp credentials not configured',
                ];
            }

            // Generate a unique flow token if not provided
            if (! $flowToken) {
                $flowToken = uniqid('flow_', true);
            }

            // Build the interactive message payload
            $interactive = [
                'type' => 'flow',
                'body' => [
                    'text' => $bodyText,
                ],
                'action' => [
                    'name' => 'flow',
                    'parameters' => [
                        'flow_message_version' => '3',
                        'flow_token' => $flowToken,
                        'flow_id' => $flowId,
                        'flow_cta' => $ctaText,
                        'flow_action' => $flowAction,
                    ],
                ],
            ];

            // Add optional header
            if ($header) {
                if (is_string($header)) {
                    // Simple text header
                    $interactive['header'] = [
                        'type' => 'text',
                        'text' => $header,
                    ];
                } elseif (is_array($header) && isset($header['type'])) {
                    // Complex header (image, document, or video)
                    $interactive['header'] = $header;
                }
            }

            // Add optional footer
            if ($footerText) {
                $interactive['footer'] = [
                    'text' => $footerText,
                ];
            }

            // Add flow action payload if provided
            if (! empty($flowActionPayload)) {
                $interactive['action']['parameters']['flow_action_payload'] = $flowActionPayload;
            }

            $payload = [
                'messaging_product' => 'whatsapp',
                'recipient_type' => 'individual',
                'to' => $toNumber,
                'type' => 'interactive',
                'interactive' => $interactive,
            ];

            Log::info('Flow message payload', ['payload' => $payload]);

            $response = Http::withToken($whatsappToken)
                ->post("https://graph.facebook.com/{$whatsappGraphVersion}/{$whatsappPhoneNumberId}/messages", $payload);

            if ($response->successful()) {
                Log::info('Flow message sent successfully', [
                    'to' => $toNumber,
                    'flow_id' => $flowId,
                    'flow_token' => $flowToken,
                    'message_id' => $response->json()['messages'][0]['id'] ?? null,
                ]);

                return [
                    'success' => true,
                    'data' => $response->json(),
                    'flow_token' => $flowToken,
                ];
            } else {
                Log::error('Failed to send flow message', [
                    'to' => $toNumber,
                    'flow_id' => $flowId,
                    'status' => $response->status(),
                    'response' => $response->json(),
                ]);

                return [
                    'success' => false,
                    'error' => $response->json(),
                ];
            }

        } catch (\Exception $e) {
            Log::channel('stderr')->error('Exception sending flow message', [
                'error' => $e->getMessage(),
                'to' => $toNumber,
                'flow_id' => $flowId ?? null,
            ]);

            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Send a text message via WhatsApp
     *
     * @param  string  $toNumber  The recipient's phone number
     * @param  string  $message  The message text
     * @param  bool  $previewUrl  Enable URL preview
     * @return array
     */
    public function sendTextMessage($toNumber, $message, $previewUrl = false)
    {
        Log::info('Sending text message');
        try {
            $whatsappToken = config('whatsapp.whatsapp_access_token');
            $whatsappPhoneNumberId = config('whatsapp.whatsapp_phone_number_id');
            $whatsappGraphVersion = config('whatsapp.whatsapp_graph_version');

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
     * Data validation endpoint (for debugging)
     */
    public function data_validation(Request $request)
    {
        Log::info('WhatsApp Flow Validation Request', $request->all());

        try {
            // Get encrypted payload
            $encryptedPayload = $request->all();

            Log::info('Encrypted Request', ['payload' => $encryptedPayload]);

            // Decrypt the request
            $decrypted = $this->encryption->decryptRequest($encryptedPayload);
            $flowData = $decrypted['decrypted_data'];
            $aesKey = $decrypted['aes_key'];
            $initialVector = $decrypted['initial_vector'];

            Log::info('Decrypted Flow Data', ['flow_data' => $flowData]);

            // Handle different actions
            $action = $flowData['action'] ?? '';
            $screen = $flowData['screen'] ?? '';
            $data = $flowData['data'] ?? [];

            $response = match ($action) {
                'ping' => $this->handlePing(),
                'INIT' => $this->handleInit($screen, $data, $flowData),
                'data_exchange' => $this->handleDataExchange($screen, $data, $flowData),
                default => ['data' => ['error_message' => 'Unknown action']],
            };

            Log::info('Response Structure', [
                'resp' => $response,
            ]);

            // Encrypt and return response
            $encryptedResponse = $this->encryption->encryptResponse($response, $aesKey, $initialVector);

            return response($encryptedResponse, 200)
                ->header('Content-Type', 'text/plain');

        } catch (\Exception $e) {
            Log::error('WhatsApp Flow Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'request' => $request->all(),
            ]);

            return response()->json(['error' => 'Internal server error'], 500);
        }
    }

    private function handlePing(): array
    {
        return [
            'version' => '3.0',
            'data' => [
                'status' => 'active',
            ],
        ];
    }

    private function handleInit(string $screen, array $data, array $flowData): array
    {
        // Return initial data for the screen
        return [
            'version' => '3.0',
            'screen' => $screen,
            'data' => [
                'schools' => [
                    ['id' => 'school_001', 'title' => 'Nairobi Academy', 'description' => 'Premier school'],
                    ['id' => 'school_002', 'title' => 'Braeburn School', 'description' => 'British curriculum'],
                ],
            ],
        ];
    }

    private function handleDataExchange(string $screen, array $data, array $flowData): array
    {
        Log::info('Data Exchange', ['screen' => $screen, 'data' => $data]);

        return match ($screen) {
            'GUARDIAN_DETAILS' => $this->validateGuardianDetails($data, $flowData),
            'CHILD_DETAILS' => $this->validateChildDetails($data, $flowData),
            'SCHOOL_SELECTION' => $this->handleSchoolSelection($data, $flowData),
            default => ['version' => '3.0', 'data' => []],
        };
    }

    private function handleSchoolSelection(array $data, array $flowData): array
    {
        // This is the terminal screen
        // Save all data to database here

        Log::info('Registration Complete', [
            'flow_data' => $flowData,
            'submitted_data' => $data,
        ]);

        // TODO: Save to database
        // $registration = Registration::create([...]);

        // Return success - flow will close
        return [
            'version' => '3.0',
            'data' => [
                'extension_message_response' => [
                    'params' => [
                        'flow_token' => $flowData['flow_token'] ?? '',
                        'registration_status' => 'success',
                    ],
                ],
            ],
        ];
    }

    private function formatErrors($errors): object
    {
        $formattedErrors = new \stdClass;

        foreach ($errors->messages() as $field => $messages) {
            $formattedErrors->{$field} = $messages[0];  // Field name as KEY, message as VALUE
        }

        Log::info('Formatted errors for Flow', ['errors' => $formattedErrors]);

        return $formattedErrors;
    }

    private function validateGuardianDetails(array $data, array $flowData): array
    {
        // Base validation rules
        $rules = [
            'first_name' => 'required|string|max:100',
            'last_name' => 'required|string|max:100',
            'middle_name' => 'nullable|string|max:100',
            'email' => 'required|email:rfc,dns|max:255',
            'phone' => [
                'required',
                'regex:/^\+?[1-9]\d{1,14}$/',
            ],
            'identification_document' => 'required|in:national_id,passport',
            'dob' => [
                'required',
                'date',
                'before:today',
                'before:'.now()->subYears(18)->format('Y-m-d'),
            ],
            'gender' => 'required|in:male,female,other',
        ];

        // Conditional validation for identification_number
        $identificationType = $data['identification_document'] ?? null;

        if ($identificationType === 'national_id') {
            $rules['identification_number'] = [
                'required',
                'numeric',
                'digits:8',
            ];
        } elseif ($identificationType === 'passport') {
            $rules['identification_number'] = [
                'required',
                'string',
                'regex:/^[A-Za-z0-9]{8}$/',
            ];
        } else {
            $rules['identification_number'] = 'required|string|min:8|max:8';
        }

        $messages = [
            'email.email' => 'Please enter a valid email address',
            'phone.regex' => 'Please enter a valid international phone number (e.g., +254712345678)',
            'identification_number.numeric' => 'National ID must contain only numbers',
            'identification_number.digits' => 'National ID must be exactly 8 digits',
            'identification_number.regex' => 'Passport must be exactly 8 alphanumeric characters',
            'identification_number.min' => 'Identification number must be 8 characters',
            'identification_number.max' => 'Identification number must be 8 characters',
            'dob.before' => 'Guardian must be at least 18 years old',
            'first_name.required' => 'First name is required',
            'last_name.required' => 'Last name is required',
            'identification_document.required' => 'Please select an identification document type',
        ];

        $validator = Validator::make($data, $rules, $messages);

        if ($validator->fails()) {
            Log::info('Guardian validation failed', ['errors' => $validator->errors()->toArray()]);

            // Convert errors to object format
            $errorMessages = [];
            foreach ($validator->errors()->messages() as $field => $fieldMessages) {
                $errorMessages[$field] = $fieldMessages[0];
            }

            return [
                'version' => '3.0',
                'screen' => 'GUARDIAN_DETAILS',
                'data' => [
                    'error_messages' => (object) $errorMessages,
                    'schools' => $this->getSchoolsData(),
                ],
            ];
        }

        // Validation passed - move to next screen
        Log::info('Guardian validation passed, moving to CHILD_DETAILS');

        return [
            'version' => '3.0',
            'screen' => 'CHILD_DETAILS',
            'data' => [
                'guardian_first_name' => $data['first_name'],
                'guardian_middle_name' => $data['middle_name'] ?? '',
                'guardian_last_name' => $data['last_name'],
                'guardian_email' => $data['email'],
                'guardian_phone' => $data['phone'],
                'guardian_identification_document' => $data['identification_document'],
                'guardian_identification_number' => $data['identification_number'],
                'guardian_dob' => $data['dob'],
                'guardian_gender' => $data['gender'],
                'schools' => $this->getSchoolsData(),
            ],
        ];
    }

    private function validateChildDetails(array $data, array $flowData): array
    {
        $validator = Validator::make($data, [
            'child_first_name' => 'required|string|max:100',
            'child_last_name' => 'required|string|max:100',
            'child_middle_name' => 'nullable|string|max:100',
            'child_dob' => 'required|date|before:today',
            'child_gender' => 'required|in:male,female,other',
        ], [
            'child_first_name.required' => 'Child first name is required',
            'child_last_name.required' => 'Child last name is required',
            'child_dob.required' => 'Child date of birth is required',
            'child_dob.before' => 'Date of birth must be in the past',
        ]);

        if ($validator->fails()) {
            Log::info('Child validation failed', ['errors' => $validator->errors()->toArray()]);

            // Convert errors to object format
            $errorMessages = [];
            foreach ($validator->errors()->messages() as $field => $fieldMessages) {
                $errorMessages[$field] = $fieldMessages[0];
            }

            return [
                'version' => '3.0',
                'screen' => 'CHILD_DETAILS',
                'data' => [
                    'error_messages' => (object) $errorMessages,
                    // Keep guardian data
                    'guardian_first_name' => $data['guardian_first_name'] ?? '',
                    'guardian_middle_name' => $data['guardian_middle_name'] ?? '',
                    'guardian_last_name' => $data['guardian_last_name'] ?? '',
                    'guardian_email' => $data['guardian_email'] ?? '',
                    'guardian_phone' => $data['guardian_phone'] ?? '',
                    'guardian_identification_document' => $data['guardian_identification_document'] ?? '',
                    'guardian_identification_number' => $data['guardian_identification_number'] ?? '',
                    'guardian_dob' => $data['guardian_dob'] ?? '',
                    'guardian_gender' => $data['guardian_gender'] ?? '',
                    'schools' => $this->getSchoolsData(),
                ],
            ];
        }

        // Validation passed - move to school selection
        Log::info('Child validation passed, moving to SCHOOL_SELECTION');

        // DON'T include error_messages when there are no errors
        return [
            'version' => '3.0',
            'screen' => 'SCHOOL_SELECTION',
            'data' => [
                'guardian_first_name' => $data['guardian_first_name'],
                'guardian_middle_name' => $data['guardian_middle_name'] ?? '',
                'guardian_last_name' => $data['guardian_last_name'],
                'guardian_email' => $data['guardian_email'],
                'guardian_phone' => $data['guardian_phone'],
                'guardian_identification_document' => $data['guardian_identification_document'],
                'guardian_identification_number' => $data['guardian_identification_number'],
                'guardian_dob' => $data['guardian_dob'],
                'guardian_gender' => $data['guardian_gender'],
                'child_first_name' => $data['child_first_name'],
                'child_middle_name' => $data['child_middle_name'] ?? '',
                'child_last_name' => $data['child_last_name'],
                'child_dob' => $data['child_dob'],
                'child_gender' => $data['child_gender'],
                'schools' => $this->getSchoolsData(),
                // ✅ REMOVED error_messages from success response
            ],
        ];
    }
}
