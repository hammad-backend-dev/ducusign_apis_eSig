<?php

defined('BASEPATH') or exit('No direct script access allowed');


class DocusignController extends CI_Controller
{
    protected $service;
    protected $docusignConfig;

    public function __construct()
    {
        parent::__construct();
        $this->load->library('session');
        $this->load->config('docusign');
        $this->load->library('DocusignService');
    }


    protected function getAccessToken()
    {
        $authHeader = $this->input->get_request_header('Authorization', TRUE);
        if (!$authHeader || stripos($authHeader, 'Bearer ') !== 0) {
            show_error('Missing or invalid Authorization header', 401);
        }
        return trim(substr($authHeader, 7));
    }

    public function token()
    {
        try {
            $token = $this->service->createJWTToken();
            echo json_encode(['success' => 1, 'message' => 'Token created', 'access_token' => $token]);
        } catch (Exception $e) {
            echo json_encode(['success' => 0, 'message' => 'Error: ' . $e->getMessage(), 'access_token' => null]);
        }
    }

    public function createTemplate()
    {
        try {
            $accessToken = $this->getAccessToken();

            if (empty($_FILES['document']['tmp_name'])) {
                $this->service->notifyDocumentStatus(null, false, 'Document upload failed');
                echo json_encode(['success' => 0, 'message' => 'Document upload failed', 'data' => null]);
                return;
            }

            $documentBase64 = base64_encode(file_get_contents($_FILES['document']['tmp_name']));
            $fileName       = $_FILES['document']['name'];
            $returnUrl      = $this->input->post('returnUrl');

            $result = $this->service->createTemplateAndReturnSenderView($accessToken, [
                'documentBase64' => $documentBase64,
                'fileName'       => $fileName,
                'returnUrl'      => $returnUrl
            ]);

            $this->service->notifyDocumentStatus($result['templateId'], true);

            echo json_encode(['success' => 1, 'message' => 'Template created', 'data' => $result]);
        } catch (Exception $e) {
            $this->service->notifyDocumentStatus(null, false, $e->getMessage());
            echo json_encode(['success' => 0, 'message' => 'Error: ' . $e->getMessage(), 'data' => null]);
        }
    }

    public function createEnvelopeAndRecipientView()
    {
        try {
            $accessToken = $this->getAccessToken();
            $templateId  = $this->input->post('templateId');
            $returnUrl   = $this->input->post('returnUrl');
            $name        = $this->input->post('name') ?? 'Default Client';
            $email       = $this->input->post('email') ?? 'client@example.com';

            if (!$templateId || !$returnUrl) {
                echo json_encode(['success' => 0, 'message' => 'Missing templateId or returnUrl']);
                return;
            }

            $envelope = $this->service->createEnvelopeFromTemplate($accessToken, [
                'templateId' => $templateId,
                'roleName'   => 'Client',
                'name'       => $name,
                'email'      => $email,
                'returnUrl'  => $returnUrl
            ]);

            $envelopeId = $envelope['envelopeId'] ?? null;
            if (!$envelopeId) {
                echo json_encode(['success' => 0, 'message' => 'Envelope creation failed', 'data' => $envelope]);
                return;
            }

            $recipientView = $this->service->createRecipientView($accessToken, [
                'envelopeId' => $envelopeId,
                'returnUrl'  => $returnUrl,
                'name'       => $name,
                'email'      => $email
            ]);

            echo json_encode([
                'success' => 1,
                'message' => 'Envelope and recipient view created',
                'data'    => ['envelope' => $envelope, 'recipientView' => $recipientView]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => 0, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function getSignedDocument($envelopeId)
    {
        try {
            $accessToken = $this->getAccessToken();
            $accountId   = $this->docusignConfig->account_id;
            $url = "https://demo.docusign.net/restapi/v2.1/accounts/{$accountId}/envelopes/{$envelopeId}/documents/1";

            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER => [
                    "Authorization: Bearer {$accessToken}",
                    "Accept: application/pdf"
                ]
            ]);
            $pdfData = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode === 200 && $pdfData) {
                header('Content-Type: application/pdf');
                header('Content-Disposition: attachment; filename="signed_document.pdf"');
                echo $pdfData;
            } else {
                echo json_encode(['success' => 0, 'message' => "Failed to fetch document. HTTP {$httpCode}"]);
            }
        } catch (Exception $e) {
            echo json_encode(['success' => 0, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }
}
