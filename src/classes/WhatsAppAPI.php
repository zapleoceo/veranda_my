<?php

namespace App\Classes;

class WhatsAppAPI
{
    private string $token;
    private string $instanceId;

    public function __construct(string $token = '', string $instanceId = '')
    {
        $this->token = $token;
        $this->instanceId = $instanceId;
    }

    /**
     * Send a text message to a WhatsApp number
     */
    public function sendMessage(string $phone, string $text): bool
    {
        if (empty($this->token) || empty($this->instanceId)) {
            error_log("WhatsAppAPI: Credentials not set. Would send to $phone: $text");
            return false;
        }

        // Placeholder for a WhatsApp API provider (e.g. Green-API, UltraMsg, etc.)
        // Using a generic structure that can be adapted.
        $url = "https://api.ultramsg.com/{$this->instanceId}/messages/chat";
        $payload = [
            'token' => $this->token,
            'to' => ltrim($phone, '+'),
            'body' => $text
        ];

        return $this->request($url, $payload);
    }

    private function request(string $url, array $payload): bool
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($payload));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("WhatsAppAPI Error ($httpCode): " . $response);
            return false;
        }

        $result = json_decode($response, true);
        if (is_array($result)) {
            if (isset($result['error']) && $result['error']) return false;
            if (array_key_exists('sent', $result)) return $result['sent'] === true || $result['sent'] === 1 || $result['sent'] === 'true' || $result['sent'] === '1';
            if (array_key_exists('success', $result)) return $result['success'] === true || $result['success'] === 1 || $result['success'] === 'true' || $result['success'] === '1';
        }
        return true;
    }
}
