<?php

namespace App\Services;

use Exception;
use phpseclib3\Crypt\AES;
use phpseclib3\Crypt\RSA;

class WhatsAppFlowEncryption
{
    private $privateKeyBase64;

    private $publicKeyBase64;

    private $passphrase;

    public function __construct()
    {
        $this->privateKeyBase64 = config('whatsapp.private_key');
        $this->publicKeyBase64 = config('whatsapp.public_key');
        $this->passphrase = config('whatsapp.private_key_passphrase');

        if (empty($this->privateKeyBase64)) {
            throw new Exception('WhatsApp private key not configured');
        }
    }

    /**
     * Decrypt incoming request from WhatsApp Flow
     */
    public function decryptRequest(array $encryptedData): array
    {
        try {
            // Decode base64 values from request
            $encryptedAesKey = base64_decode($encryptedData['encrypted_aes_key']);
            $encryptedFlowData = base64_decode($encryptedData['encrypted_flow_data']);
            $initialVector = base64_decode($encryptedData['initial_vector']);

            // Decode private key from base64 stored in env
            $privatePem = base64_decode($this->privateKeyBase64);

            // Load private key with passphrase
            $rsa = RSA::load($privatePem, $this->passphrase)
                ->withPadding(RSA::ENCRYPTION_OAEP)
                ->withHash('sha256')
                ->withMGFHash('sha256');

            // Decrypt AES key using RSA private key
            $aesKey = $rsa->decrypt($encryptedAesKey);

            if (! $aesKey) {
                throw new Exception('Failed to decrypt AES key');
            }

            // Split encrypted data and auth tag (GCM auth tag is last 16 bytes)
            $encryptedBody = substr($encryptedFlowData, 0, -16);
            $authTag = substr($encryptedFlowData, -16);

            // Decrypt flow data using AES-GCM
            $aes = new AES('gcm');
            $aes->setKey($aesKey);
            $aes->setNonce($initialVector);
            $aes->setTag($authTag);

            $decryptedData = $aes->decrypt($encryptedBody);

            if ($decryptedData === false) {
                throw new Exception('Failed to decrypt flow data');
            }

            return [
                'decrypted_data' => json_decode($decryptedData, true),
                'aes_key' => $aesKey,
                'initial_vector' => $initialVector,
            ];
        } catch (Exception $e) {
            \Log::error('WhatsApp Flow Decryption Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Encrypt response to send back to WhatsApp Flow
     */
    public function encryptResponse(array $response, string $aesKey, string $initialVector): string
    {
        try {
            $responseJson = json_encode($response);

            // Flip the Initialization Vector (IV) for the response
            $flippedInitialVector = '';
            for ($i = 0; $i < strlen($initialVector); $i++) {
                $flippedInitialVector .= chr(ord($initialVector[$i]) ^ 0xFF);
            }

            $aes = new AES('gcm');
            $aes->setKey($aesKey);
            $aes->setNonce($flippedInitialVector);

            $encryptedResponse = $aes->encrypt($responseJson);
            $authTag = $aes->getTag();

            $encryptedResponseWithTag = $encryptedResponse.$authTag;

            // Return base64 encoded
            return base64_encode($encryptedResponseWithTag);
        } catch (Exception $e) {
            \Log::error('WhatsApp Flow Encryption Error', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Get the public key for uploading to Meta
     */
    public function getPublicKey(): string
    {
        return base64_decode($this->publicKeyBase64);
    }
}
