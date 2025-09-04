<?php
defined('BASEPATH') or exit('No direct script access allowed');

class DocusignService
{
    protected $config;

    public function __construct()
    {
        $CI = &get_instance();
        $CI->load->config('docusign');
        $this->config = [
            'integration_key'       => $CI->config->item('integration_key'),
            'user_id'               => $CI->config->item('user_id'),
            'account_id'            => $CI->config->item('account_id'),
            'rsa_private_key_path'  => $CI->config->item('rsa_private_key_path'),
            'expires_in'            => $CI->config->item('expires_in'),
            'base_path'             => $CI->config->item('base_path'),
            'auth_server'           => $CI->config->item('auth_server'),
            'scope'                 => $CI->config->item('scope'),
        ];
    }

    public function createJWTToken()
    {
        $integrationKey = $this->config['integration_key'];
        $userId         = $this->config['user_id'];
        $authServer     = $this->config['auth_server'];
        $keyPath        = $this->config['rsa_private_key_path'];
        $scopes         = [$this->config['scope'], 'impersonation'];

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
            'exp' => $now + $this->config['expires_in'],
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

        $accountId = $this->config['account_id'];
        $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/templates";

        $body = [
            'name' => 'Template - ' . date('YmdHis'),
            'emailSubject' => 'Please prepare template',
            'documents' => [[
                'documentBase64' => $data['documentBase64'],
                'name' => $data['fileName'],
                'fileExtension' => pathinfo($data['fileName'], PATHINFO_EXTENSION),
                'documentId' => '1'
            ]],
            //add extra feidls here if needed

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
        $accountId = $this->config['account_id'];
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
        $accountId = $this->config['account_id'];
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

    // protected function sendCurlRequest($url, $accessToken, $body)
    // {
    //     $ch = curl_init($url);
    //     curl_setopt_array($ch, [
    //         CURLOPT_RETURNTRANSFER => true,
    //         CURLOPT_POST => true,
    //         CURLOPT_HTTPHEADER => [
    //             "Authorization: Bearer {$accessToken}",
    //             "Content-Type: application/json"
    //         ],
    //         CURLOPT_POSTFIELDS => json_encode($body)
    //     ]);
    //     $response = curl_exec($ch);
    //     $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //     curl_close($ch);
    //     if ($httpCode < 200 || $httpCode >= 300) {
    //         throw new RuntimeException("DocuSign API Error [{$httpCode}]: {$response}");
    //     }
    //     return $response;
    // }
    protected function sendCurlRequest($url, $accessToken, $body = null, $method = 'POST')
    {
        $ch = curl_init($url);

        $headers = [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ];

        if (strtoupper($method) === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if (!empty($body)) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));
            }
        } else { // default to GET
            curl_setopt($ch, CURLOPT_HTTPGET, true);
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers
        ]);

        $response  = curl_exec($ch);
        $httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("DocuSign API Error [{$httpCode}]: {$response}");
        }

        return $response;
    }


    public function notifyDocumentStatus($docId, $isSuccess, $errorMessage = null)
    {
        $payload = [
            'collection' => 'LawFirm',
            'docId' => $docId,
            'data' => ['isDocumentEdited' => $isSuccess]
        ];
        if (!$isSuccess && $errorMessage) {
            $payload['data']['errorMessage'] = $errorMessage;
        }

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
    public function notifyEnvelopeStatus($docId, $isSuccess, $errorMessage = null)
    {
        $payload = [
            'collection' => 'QuoteAlert',
            'docId'      => $docId,
            'data'       => ['isEnvelopSign' => $isSuccess]
        ];
        if (!$isSuccess && $errorMessage) {
            $payload['data']['errorMessage'] = $errorMessage;
        }

        $ch = curl_init('https://us-central1-freeme-6e63a.cloudfunctions.net/widgetsforusa/documents/update');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS => json_encode($payload)
        ]);
        $response = curl_exec($ch);
        curl_close($ch);

        return $response;
    }

    public function getEnvelopeStatus($accessToken, $envelopeId)
    {
        $accountId = $this->config['account_id'];
        $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/envelopes/{$envelopeId}";

        $response = $this->sendCurlRequest($url, $accessToken, null, 'GET');
        return json_decode($response, true);
    }




    public function getFreshSenderView($accessToken, $templateId, $returnUrl)
    {
        try {
            $accountId = $this->config['account_id'];

            $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/templates/{$templateId}/views/edit";

            $body = [
                'returnUrl' => $returnUrl
            ];

            $response = $this->sendCurlRequest($url, $accessToken, $body);
            $result   = json_decode($response, true);

            if (!empty($result['url'])) {
                return $result['url'];
            }

            return null; // Return proper null if URL missing
        } catch (Exception $e) {
            return null; // Return null on error
        }
    }
}
