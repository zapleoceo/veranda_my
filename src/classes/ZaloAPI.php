<?php

namespace App\Classes;

class ZaloAPI
{
    private string $accessToken;
    private string $oaId;

    public function __construct(string $accessToken = '', string $oaId = '')
    {
        $this->accessToken = $accessToken;
        $this->oaId = $oaId;
    }

    /**
     * Send a text message to a user by their Zalo User ID
     * Note: User must have interacted with the OA first.
     */
    public function sendMessage(string $userId, string $text): bool
    {
        if (empty($this->accessToken)) {
            error_log("ZaloAPI: Access token not set. Would send to $userId: $text");
            return false;
        }

        $url = 'https://openapi.zalo.me/v3.0/oa/message/transaction';
        $payload = [
            'recipient' => ['user_id' => $userId],
            'message' => ['text' => $text]
        ];

        return $this->request($url, $payload);
    }

    /**
     * Send a confirmation message via phone number (requires ZNS)
     */
    public function sendZNS(string $phone, string $templateId, array $params): bool
    {
        if (empty($this->accessToken)) {
            error_log("ZaloAPI: ZNS would be sent to $phone with template $templateId");
            return false;
        }

        $url = 'https://business.openapi.zalo.me/message/template';
        $payload = [
            'phone' => ltrim($phone, '+'),
            'template_id' => $templateId,
            'template_data' => $params,
            'tracking_id' => 'res_' . time()
        ];

        return $this->request($url, $payload);
    }

    private function request(string $url, array $payload): bool
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'Content-Type: application/json',
            'access_token: ' . $this->accessToken
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            error_log("ZaloAPI Error ($httpCode): " . $response);
            return false;
        }

        $result = json_decode($response, true);
        return isset($result['error']) && $result['error'] === 0;
    }
}
