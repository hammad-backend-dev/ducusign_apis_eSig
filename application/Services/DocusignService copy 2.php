<?php

namespace App\Services;

use RuntimeException;
use DocuSign\eSign\Configuration;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Api\TemplatesApi;
use DocuSign\eSign\Client\ApiClient;
use Config\Docusign as DocusignConfig;
use DocuSign\eSign\Client\ApiException;
use DocuSign\eSign\Model\EnvelopeTemplate;
use DocuSign\eSign\Model\ReturnUrlRequest;

/**
 * Service class for handling DocuSign API operations (JWT authentication, templates, envelopes, recipient views).
 */
class DocusignService
{
    protected ApiClient $apiClient;
    protected DocusignConfig $config;

    /**
     * Initialize the DocuSign API client.
     */
    public function __construct()
    {
        $this->config = new DocusignConfig();

        $cfg = new Configuration();
        $cfg->setHost($this->config->base_path);

        $this->apiClient = new ApiClient($cfg);

        // Set OAuth base path for JWT flow (SDK-dependent)
        try {
            $this->apiClient->getOAuth()->setOAuthBasePath($this->config->auth_server);
        } catch (\Throwable $e) {
            // Non-fatal: some SDK versions handle OAuth differently
        }
    }

    /**
     * Create a DocuSign JWT access token.
     *
     * @throws RuntimeException if config or authentication fails
     * @return string Access token
     */
    public function createJWTToken(): string
    {
        $integrationKey = $this->config->integration_key;
        $userId         = $this->config->user_id;
        $oauthBasePath  = $this->config->auth_server;
        $keyPath        = $this->config->rsa_private_key_path;
        $scopesArr      = [$this->config->scope, 'impersonation'];

        // Validate required fields
        if (!$integrationKey || !$userId || !$keyPath) {
            throw new RuntimeException('DocuSign configuration incomplete.');
        }
        if (!file_exists($keyPath)) {
            throw new RuntimeException("Private key not found: {$keyPath}");
        }

        $privateKey = file_get_contents($keyPath);
        if (!$privateKey) {
            throw new RuntimeException("Private key file is empty or unreadable: {$keyPath}");
        }

        $scopes = implode(' ', $scopesArr);

        try {
            try {
                $tokenResponse = $this->apiClient->requestJWTUserToken(
                    $integrationKey,
                    $userId,
                    $privateKey,
                    $scopes,
                    (int)($this->config->expires_in / 3600)
                );
            } catch (\ArgumentCountError $e) {
                $tokenResponse = $this->apiClient->requestJWTUserToken(
                    $integrationKey,
                    $userId,
                    $oauthBasePath,
                    $privateKey,
                    $this->config->expires_in,
                    $scopesArr
                );
            } catch (\TypeError $e) {
                $tokenResponse = $this->apiClient->requestJWTUserToken(
                    $integrationKey,
                    $userId,
                    $privateKey,
                    $this->config->expires_in,
                    $scopesArr
                );
            }

            $tokenObj = $tokenResponse[0] ?? null;
            $accessToken = is_object($tokenObj) && method_exists($tokenObj, 'getAccessToken')
                ? $tokenObj->getAccessToken()
                : ($tokenObj['access_token'] ?? null);

            if (!$accessToken) {
                throw new RuntimeException('DocuSign returned an empty access token.');
            }

            $this->apiClient->getConfig()->setAccessToken($accessToken);

            return $accessToken;
        } catch (ApiException $ae) {
            $body = $ae->getResponseBody();
            throw new RuntimeException('DocuSign API Exception: ' . $ae->getMessage() . ' - ' . json_encode($body));
        } catch (\Throwable $t) {
            throw new RuntimeException('Failed to obtain JWT token: ' . $t->getMessage());
        }
    }

    /**
     * Create a template in DocuSign and return an embedded sender view URL.
     *
     * @param string $accessToken Valid JWT access token
     * @param array $data [
     *     'documentBase64' => string,
     *     'fileName' => string,
     *     'returnUrl' => string
     * ]
     * @return array ['templateId' => string, 'senderViewUrl' => string]
     */
    public function createTemplateAndReturnSenderView(string $accessToken, array $data): array
    {
        foreach (['documentBase64', 'fileName', 'returnUrl'] as $field) {
            if (empty($data[$field])) {
                throw new RuntimeException("Missing required field: {$field}");
            }
        }

        $apiClient = new ApiClient();
        $apiClient->getConfig()->setHost($this->config->base_path);
        $apiClient->getConfig()->setAccessToken($accessToken);

        $templatesApi = new TemplatesApi($apiClient);

        $document = new Document([
            'document_base64' => $data['documentBase64'],
            'name' => $data['fileName'],
            'file_extension' => pathinfo($data['fileName'], PATHINFO_EXTENSION),
            'document_id' => '1',
        ]);

        $templateDefinition = new EnvelopeTemplate([
            'name' => 'Attorney Template - ' . date('YmdHis'),
            'email_subject' => 'Please prepare and save this template',
            'documents' => [$document],
            'status' => 'created'
        ]);

        $templateSummary = $templatesApi->createTemplate($this->config->account_id, $templateDefinition);
        $templateId = $templateSummary->getTemplateId();

        $returnUrlRequest = new ReturnUrlRequest(['return_url' => $data['returnUrl']]);
        $senderView = $templatesApi->createEditView($this->config->account_id, $templateId, $returnUrlRequest);

        return [
            'templateId' => $templateId,
            'senderViewUrl' => $senderView->getUrl()
        ];
    }

    /**
     * Create an envelope from an existing template.
     *
     * @param string $accessToken JWT token
     * @param array $params ['templateId' => string, 'roleName' => string]
     * @return array API response
     */
    public function createEnvelopeFromTemplate(string $accessToken, array $params): array
    {
        $accountId = $this->config->account_id;
        $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/envelopes";

        $body = [
            'templateId' => $params['templateId'],
            'emailSubject' => 'Please sign this document',
            'templateRoles' => [
                [
                    'roleName' => $params['roleName'],
                    'name' => 'Default Client',
                    'email' => 'client@example.com',
                    'clientUserId' => '1234'
                ]
            ],
            'status' => 'sent'
        ];

        $response = $this->sendCurlRequest($url, $accessToken, $body);
        return json_decode($response, true);
    }

    /**
     * Create an embedded recipient view (signing URL).
     *
     * @param string $accessToken
     * @param array $params ['envelopeId' => string, 'returnUrl' => string, 'email' => string, 'name' => string]
     * @return array
     */
    public function createRecipientView(string $accessToken, array $params): array
    {
        $accountId = $this->config->account_id;
        $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/envelopes/{$params['envelopeId']}/views/recipient";

        $body = [
            "returnUrl" => $params['returnUrl'],
            "authenticationMethod" => "none",
            "email" => $params['email'] ?? "client@example.com",
            "userName" => $params['name'] ?? "Default Client",
            "clientUserId" => "1234"
        ];

        $response = $this->sendCurlRequest($url, $accessToken, $body);
        return json_decode($response, true);
    }

    /**
     * Helper: Send a cURL POST request with Bearer token.
     *
     * @param string $url
     * @param string $accessToken
     * @param array $body
     * @return string
     */
    protected function sendCurlRequest(string $url, string $accessToken, array $body): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$accessToken}",
                "Content-Type: application/json"
            ],
            CURLOPT_POSTFIELDS => json_encode($body),
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode < 200 || $httpCode >= 300) {
            throw new RuntimeException("DocuSign API Error [{$httpCode}]: {$response}");
        }

        return $response;
    }

    function notifyDocumentStatus($docId, $isSuccess, $errorMessage = null)
    {
        $payload = [
            "collection" => "LawFirm",
            "docId" => $docId,
            "data" => [
                "isDocumentEdited" => $isSuccess
            ]
        ];

        // If failed, add error message
        if (!$isSuccess && $errorMessage) {
            $payload["data"]["errorMessage"] = $errorMessage;
        }

        $jsonPayload = json_encode($payload);

        $curl = curl_init();
        curl_setopt_array($curl, array(
            CURLOPT_URL => 'https://us-central1-freeme-6e63a.cloudfunctions.net/widgetsforusa/documents/update',
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_ENCODING => '',
            CURLOPT_MAXREDIRS => 10,
            CURLOPT_TIMEOUT => 0,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
            CURLOPT_CUSTOMREQUEST => 'POST',
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => array(
                'Content-Type: application/json'
            ),
        ));

        //$response = curl_exec($curl); // commenting out local response
        curl_exec($curl);
        curl_close($curl);
    }
}
