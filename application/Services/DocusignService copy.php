<?php

namespace App\Services;

use RuntimeException;
use DocuSign\eSign\Model\Tabs;
use DocuSign\eSign\Model\Signer;
use DocuSign\eSign\Configuration;
use DocuSign\eSign\Model\Document;
use DocuSign\eSign\Model\SignHere;
use DocuSign\eSign\Api\EnvelopesApi;
use DocuSign\eSign\Client\ApiClient;
use DocuSign\eSign\Model\Recipients;
use Config\Docusign as DocusignConfig;
use DocuSign\eSign\Client\ApiException;
use DocuSign\eSign\Model\ReturnUrlRequest;
use DocuSign\eSign\Model\EnvelopeDefinition;
use DocuSign\eSign\Api\TemplatesApi;
use DocuSign\eSign\Model\EnvelopeTemplate;

class DocusignService
{
    protected ApiClient $apiClient;
    protected DocusignConfig $config;

    public function __construct()
    {
        $this->config = new DocusignConfig();

        $cfg = new Configuration();
        // API base host (REST call host)
        $cfg->setHost($this->config->base_path);

        $this->apiClient = new ApiClient($cfg);

        // Ensure OAuth base path is set for JWT flow (explicit)
        // Many SDKs expose getOAuth()->setOAuthBasePath()
        try {
            $this->apiClient->getOAuth()->setOAuthBasePath($this->config->auth_server);
        } catch (\Throwable $e) {
            // Not fatal — some SDK versions behave slightly differently.
            // We will still attempt to call requestJWTUserToken() below.
        }
    }

    /**
     * Create and return a DocuSign JWT access token string.
     *
     * @throws RuntimeException on validation or auth errors
     * @return string access token
     */
    public function createJWTToken(): string
    {
        $integrationKey = $this->config->integration_key;
        $userId         = $this->config->user_id;
        $oauthBasePath  = $this->config->auth_server;
        $keyPath        = $this->config->rsa_private_key_path;
        $scopesArr      = [$this->config->scope, 'impersonation']; // array form

        // 1) Basic validation
        if (empty($integrationKey) || empty($userId) || empty($keyPath)) {
            throw new RuntimeException('DocuSign configuration incomplete (integration_key/user_id/rsa_private_key_path required).');
        }

        if (!file_exists($keyPath)) {
            throw new RuntimeException("DocuSign private key file not found at: {$keyPath}");
        }

        $privateKey = @file_get_contents($keyPath);
        if ($privateKey === false || trim($privateKey) === '') {
            throw new RuntimeException("DocuSign private key file is empty or unreadable: {$keyPath}");
        }

        // 2) Validate using OpenSSL - ensures PEM and ability to use key
        $res = @openssl_pkey_get_private($privateKey);


        // 3) Prepare scopes as string (DocuSign examples sometimes expect string)
        $scopes = implode(' ', $scopesArr); // "signature impersonation"

        // 4) Try to request JWT token. Different SDK versions accept different method signatures.
        try {
            // Preferred path: some SDK versions accept (clientId, userId, privateKey, scopes, expiresIn)
            // We'll attempt a few call signatures safely:
            try {
                // First try: (clientId, userId, privateKey, scopes, expiresIn)
                $maybeToken = $this->apiClient->requestJWTUserToken(
                    $integrationKey,
                    $userId,
                    $privateKey,
                    $scopes,
                    (int) ($this->config->expires_in / 3600) // some SDK expect hours; if not, fallback below
                );
            } catch (\ArgumentCountError $e) {
                // fallback to an alternate call signature including oauthBasePath
                $maybeToken = $this->apiClient->requestJWTUserToken(
                    $integrationKey,
                    $userId,
                    $oauthBasePath,
                    $privateKey,
                    $this->config->expires_in,
                    $scopesArr
                );
            } catch (\TypeError $e) {
                // second fallback: try signature with expires in seconds and scopes array
                $maybeToken = $this->apiClient->requestJWTUserToken(
                    $integrationKey,
                    $userId,
                    $privateKey,
                    $this->config->expires_in,
                    $scopesArr
                );
            }

            // Normal response is an array / object containing access token
            if (is_array($maybeToken) && isset($maybeToken[0])) {
                // SDK often returns object, convert if necessary
                $tokenObj = $maybeToken[0];
                // try common getters
                if (is_object($tokenObj) && method_exists($tokenObj, 'getAccessToken')) {
                    $accessToken = $tokenObj->getAccessToken();
                } elseif (is_array($tokenObj) && isset($tokenObj['access_token'])) {
                    $accessToken = $tokenObj['access_token'];
                } else {
                    // final attempt: cast to string if possible
                    $accessToken = (string) $tokenObj;
                }
            } else {
                throw new RuntimeException('Unexpected token response from DocuSign SDK.');
            }

            if (empty($accessToken)) {
                throw new RuntimeException('DocuSign returned an empty access token.');
            }

            // Set token on ApiClient config for subsequent calls
            $this->apiClient->getConfig()->setAccessToken($accessToken);

            return $accessToken;
        } catch (ApiException $ae) {
            // DocuSign returned an HTTP-level error — include body if available
            $body = $ae->getResponseBody();
            $msg  = 'DocuSign API Exception: ' . $ae->getMessage();
            if ($body) {
                $msg .= ' - Response: ' . (is_string($body) ? $body : json_encode($body));
            }
            throw new RuntimeException($msg);
        } catch (\Throwable $t) {
            // Generic error — keep message but hide overly-sensitive data
            throw new RuntimeException('Failed to obtain DocuSign JWT token: ' . $t->getMessage());
        }
    }

    public function createTemplateAndReturnSenderView(string $accessToken, array $data): array
    {
        // Required fields check
        foreach (['documentBase64', 'fileName', 'returnUrl'] as $field) {
            if (empty($data[$field])) {
                throw new RuntimeException("Missing required field: {$field}");
            }
        }

        // DocuSign API client
        $apiClient = new ApiClient();
        $apiClient->getConfig()->setHost($this->config->base_path);
        $apiClient->getConfig()->setAccessToken($accessToken);

        $templatesApi = new TemplatesApi($apiClient);

        // Create document
        $document = new Document([
            'document_base64' => $data['documentBase64'],
            'name' => $data['fileName'],
            'file_extension' => pathinfo($data['fileName'], PATHINFO_EXTENSION),
            'document_id' => '1',
        ]);

        // Create draft template
        $templateDefinition = new EnvelopeTemplate([
            'name' => 'Attorney Template - ' . date('YmdHis'),
            'email_subject' => 'Please prepare and save this template',
            'documents' => [$document],
            'status' => 'created' // draft
        ]);

        $templateSummary = $templatesApi->createTemplate($this->config->account_id, $templateDefinition);
        $templateId = $templateSummary->getTemplateId();

        // Create embedded sender view URL
        $returnUrlRequest = new ReturnUrlRequest(['return_url' => $data['returnUrl']]);
        $senderView = $templatesApi->createEditView(
            $this->config->account_id,
            $templateId,
            $returnUrlRequest
        );

        return [
            'templateId' => $templateId,
            'senderViewUrl' => $senderView->getUrl()
        ];
    }
    public function createEnvelopeFromTemplate($accessToken, $params)
    {
        $accountId = $this->config->account_id;

        // DocuSign API endpoint
        $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/envelopes";

        // Prepare request body
        $body = [
            'templateId' => $params['templateId'],
            'emailSubject' => 'Please sign this document',
            'templateRoles' => [
                [
                    'roleName' => $params['roleName'], // e.g., "Client"
                    'name'     => 'Default Client',                  // leave blank for now
                    'email'    => 'client@example.com',                   // leave blank for now
                    'clientUserId' => '1234'
                ]
            ],
            'status' => 'sent' // not sent yet
        ];

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        throw new \RuntimeException("DocuSign API Error: " . $response);
    }
    public function createRecipientView1($accessToken, $params)
    {
        $accountId = $this->config->account_id;

        $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/envelopes/{$params['envelopeId']}/views/recipient";

        $body = [
            "returnUrl" => $params['returnUrl'], // where to go after signing
            "authenticationMethod" => "none",
            "email" => $params['email'] ?? "client@example.com",
            "userName" => $params['name'] ?? "Default Client",
            "clientUserId" => "1234" // required for embedded signing
        ];

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: application/json"
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body));

        $response = curl_exec($ch);
        return $response;
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($response, true);
        }

        throw new \RuntimeException("DocuSign API Error: " . $response);
    }
}
