<?php

use Docusign;

defined('BASEPATH') or exit('No direct script access allowed');

class DocusignService
{
    protected $CI;
    protected $docusignConfig;

    public function __construct()
    {
        // Get CI super object
        $this->CI = &get_instance();

        // Load config file
        $this->CI->load->config('docusign');

        // Store config array for easier access
        $this->docusignConfig = $this->CI->config->item('docusign');
    }

    public function createJWTToken()
    {
        $integrationKey = $this->docusignConfig->integration_key;
        $userId         = $this->docusignConfig->user_id;
        $authServer     = $this->docusignConfig->auth_server;
        $keyPath        = $this->docusignConfig->rsa_private_key_path;
        $scopes         = [$this->docusignConfig->scope, 'impersonation'];

        if (!file_exists($keyPath)) {
            throw new RuntimeException("Private key not found: {$keyPath}");
        }

        $privateKey = file_get_contents($keyPath);
        $base64url = function ($data) {
            return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
        };

        $header = $base64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $now = time();
        $payload = $base64url(json_encode([
            'iss' => $integrationKey,
            'sub' => $userId,
            'aud' => $authServer,
            'iat' => $now,
            'exp' => $now + $this->docusignConfig->expires_in,
            'scope' => implode(' ', $scopes)
        ]));

        $data = $header . '.' . $payload;
        if (!openssl_sign($data, $signature, $privateKey, OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Failed to sign JWT.');
        }
        $jwt = $data . '.' . $base64url($signature);

        $url = "https://{$authServer}/oauth/token";
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Content-Type: application/x-www-form-urlencoded"],
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion' => $jwt
            ])
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("JWT request failed: {$response}");
        }

        $data = json_decode($response, true);
        if (empty($data['access_token'])) {
            throw new RuntimeException("No access token returned: {$response}");
        }

        return $data['access_token'];
    }

    public function createTemplateAndReturnSenderView($accessToken, $data)
    {
        foreach (['documentBase64', 'fileName', 'returnUrl'] as $field) {
            if (empty($data[$field])) throw new RuntimeException("Missing required field: {$field}");
        }

        $accountId = $this->docusignConfig->account_id;
        $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/templates";

        $body = [
            'name' => 'Template - ' . date('YmdHis'),
            'emailSubject' => 'Please prepare template',
            'documents' => [['documentBase64' => $data['documentBase64'], 'name' => $data['fileName'], 'fileExtension' => pathinfo($data['fileName'], PATHINFO_EXTENSION), 'documentId' => '1']],
            'status' => 'created'
        ];

        $response = $this->sendCurlRequest($url, $accessToken, $body);
        $templateResp = json_decode($response, true);
        if (empty($templateResp['templateId'])) throw new RuntimeException("Failed to create template: {$response}");
        $templateId = $templateResp['templateId'];

        $viewUrl = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/templates/{$templateId}/views/edit";
        $viewResp = $this->sendCurlRequest($viewUrl, $accessToken, ['returnUrl' => $data['returnUrl']]);
        $viewData = json_decode($viewResp, true);

        return ['templateId' => $templateId, 'senderViewUrl' => $viewData['url'] ?? ''];
    }

    public function createEnvelopeFromTemplate($accessToken, $params)
    {
        $accountId = $this->docusignConfig->account_id;
        $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/envelopes";

        $body = [
            'templateId' => $params['templateId'],
            'emailSubject' => 'Please sign document',
            'templateRoles' => [[
                'roleName' => $params['roleName'],
                'name' => $params['name'] ?? 'Default Client',
                'email' => $params['email'] ?? 'client@example.com',
                'clientUserId' => '1234'
            ]],
            'status' => 'sent'
        ];

        $response = $this->sendCurlRequest($url, $accessToken, $body);
        return json_decode($response, true);
    }

    public function createRecipientView($accessToken, $params)
    {
        $accountId = $this->docusignConfig->account_id;
        $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/envelopes/{$params['envelopeId']}/views/recipient";

        $body = [
            'returnUrl' => $params['returnUrl'],
            'authenticationMethod' => 'none',
            'email' => $params['email'] ?? 'client@example.com',
            'userName' => $params['name'] ?? 'Default Client',
            'clientUserId' => '1234'
        ];

        $response = $this->sendCurlRequest($url, $accessToken, $body);
        return json_decode($response, true);
    }

    protected function sendCurlRequest($url, $accessToken, $body)
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$accessToken}", "Content-Type: application/json"],
            CURLOPT_POSTFIELDS => json_encode($body)
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode < 200 || $httpCode >= 300) throw new RuntimeException("DocuSign API Error [{$httpCode}]: {$response}");
        return $response;
    }

    public function notifyDocumentStatus($docId, $isSuccess, $errorMessage = null)
    {
        $payload = ['collection' => 'LawFirm', 'docId' => $docId, 'data' => ['isDocumentEdited' => $isSuccess]];
        if (!$isSuccess && $errorMessage) $payload['data']['errorMessage'] = $errorMessage;

        $ch = curl_init('https://us-central1-freeme-6e63a.cloudfunctions.net/widgetsforusa/documents/update');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        curl_exec($ch);
        curl_close($ch);
    }
}
