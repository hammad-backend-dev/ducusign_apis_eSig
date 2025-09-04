<?php
defined('BASEPATH') or exit('No direct script access allowed');

class DocusignController extends CI_Controller
{
    protected $docusignService;
    protected $docusignConfig;

    public function __construct()
    {
        parent::__construct();

        $this->load->config('docusign');
        $this->docusignConfig = [
            'integration_key'       => $this->config->item('integration_key'),
            'user_id'               => $this->config->item('user_id'),
            'account_id'            => $this->config->item('account_id'),
            'rsa_private_key_path'  => $this->config->item('rsa_private_key_path'),
            'expires_in'            => $this->config->item('expires_in'),
            'base_path'             => $this->config->item('base_path'),
            'auth_server'           => $this->config->item('auth_server'),
            'scope'                 => $this->config->item('scope'),
        ];

        // Load service
        require_once APPPATH . 'Services/DocusignService.php';
        $this->docusignService = new DocusignService();
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
            $token = $this->docusignService->createJWTToken();
            echo json_encode(['success' => 1, 'message' => 'Token created', 'access_token' => $token]);
        } catch (Exception $e) {
            echo json_encode(['success' => 0, 'message' => 'Error: ' . $e->getMessage(), 'access_token' => null]);
        }
    }

    public function createTemplate()
    {
        try {
            $accessToken = $this->getAccessToken();

            $documentBase64 = trim($this->input->post('document'));

            // Check if Base64 is provided
            if (empty($documentBase64)) {
                // $this->docusignService->notifyDocumentStatus(null, false, 'Document missing');
                echo json_encode([
                    'success' => 0,
                    'message' => 'Document upload failed',
                    'data'    => null
                ]);
                return;
            }

            // Decode Base64 to binary
            $documentBinary = base64_decode($documentBase64, true);
            if ($documentBinary === false) {
                echo json_encode([
                    'success' => 0,
                    'message' => 'Invalid Base64 string',
                    'data'    => null
                ]);
                return;
            }

            // Detect MIME type from binary
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($documentBinary);

            // Map MIME type to file extension
            $extensions = [
                'application/pdf' => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/zip' => 'zip',
                'image/png' => 'png',
                'image/jpeg' => 'jpg',
            ];

            $extension = $extensions[$mimeType] ?? 'bin';
            $fileName = 'document_' . time() . '.' . $extension;

            $returnUrl      = $this->input->post('returnUrl');
            $docId  = $this->input->post('attorneyId');


            $result = $this->docusignService->createTemplateAndReturnSenderView($accessToken, [
                'documentBase64' => $documentBase64,
                'fileName'       => $fileName,
                'returnUrl'      => $returnUrl
            ]);

            $this->docusignService->notifyDocumentStatus($docId, true);

            echo json_encode(['success' => 1, 'message' => 'Template created', 'data' => $result]);
        } catch (Exception $e) {
            $this->docusignService->notifyDocumentStatus(null, false, $e->getMessage());
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
            $doc_id = $this->input->post('doc_id') ?? '';

            if (!$templateId || !$returnUrl) {
                echo json_encode(['success' => 0, 'message' => 'Missing templateId or returnUrl']);
                return;
            }

            $envelope = $this->docusignService->createEnvelopeFromTemplate($accessToken, [
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


            $recipientView = $this->docusignService->createRecipientView($accessToken, [
                'envelopeId' => $envelopeId,
                // 'returnUrl'  => $returnUrl,
                // 'returnUrl'  => $returnUrl . '?doc_id=' . $doc_id,
                'returnUrl'  => $returnUrl . '?doc_id=' . $doc_id . '&envelopeId=' . $envelopeId,
                'name'       => $name,
                'email'      => $email,
            ]);
            $url =  $returnUrl . '?doc_id=' . $doc_id . '&envelopeId=' . $envelopeId;



            echo json_encode([
                'success' => 1,
                'id' => $url,
                'message' => 'Envelope and recipient view created',
                'data'    => ['envelope' => $envelope, 'recipientView' => $recipientView]
            ]);
        } catch (Exception $e) {
            echo json_encode(['success' => 0, 'message' => 'Error: ' . $e->getMessage()]);
        }
    }

    public function signingCallback()
    {
        $docId      = $this->input->get('doc_id');      // from your app
        $envelopeId = $this->input->get('envelopeId');  // from docusign
        // $accessToken = $this->getAccessToken();

        if (!$docId || !$envelopeId) {
            $this->load->view('docusign/error', ['message' => 'Missing docId or envelopeId']);
            return;
        }
        $accessToken = $this->docusignService->createJWTToken();

        // Check envelope status
        $status = $this->docusignService->getEnvelopeStatus($accessToken, $envelopeId);

        if (!empty($status['status']) && strtolower($status['status']) === 'completed') {
            //  Signed
            $dataa = $this->docusignService->notifyEnvelopeStatus($docId, true);
            echo "<pre>";
            print_r($dataa);
            echo "</pre>";
            die;
            $this->load->view('docusign/success', [
                'message'    => 'Thank you, your document is signed!',
                // 'docId'      => $docId,
                // 'envelopeId' => $envelopeId,
                'status'     => $status['status']
            ]);
        } else {
            //  Not signed
            $this->docusignService->notifyEnvelopeStatus($docId, false, $status['status'] ?? 'Unknown');
            $this->load->view('docusign/error', [
                'message'    => 'Document not signed yet.',
                // 'docId'      => $docId,
                // 'envelopeId' => $envelopeId,
                'status'     => $status['status'] ?? 'Unknown'
            ]);
        }
    }



    public function getSignedDocument()
    {
        // Try JSON first
        $input = json_decode(file_get_contents('php://input'), true);
        $envelopeId = $input['envelopeId'] ?? null;

        // Fallback to POST
        if (empty($envelopeId)) {
            $envelopeId = $this->input->post('envelopeId') ?? null;
        }

        // Fallback to GET
        if (empty($envelopeId)) {
            $envelopeId = $this->input->get('envelopeId') ?? null;
        }
        // echo "<pre>";
        // print_r($_POST);
        // echo "</pre>";
        // die;

        // $envelopeId = $this->input->post('envelopeId');
        if (empty($envelopeId)) {
            echo json_encode(['success' => 0, 'message' => 'envelopeId is required']);
            return;
        }
        try {


            $accessToken = $this->getAccessToken();
            $accountId   = $this->docusignConfig['account_id'];
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
    public function getFreshSenderViewEndpoint()
    {
        try {
            $accessToken = $this->getAccessToken();
            $templateId  = trim($this->input->post_get('templateId')); // works for POST or GET
            $returnUrl   = trim($this->input->post_get('returnUrl'));

            if (empty($templateId) || empty($returnUrl)) {
                $response = [
                    'success' => 0,
                    'message' => 'templateId and returnUrl are required',
                    'senderViewUrl' => null
                ];
            } else {
                // Get fresh sender view URL
                $senderViewUrl = $this->docusignService->getFreshSenderView($accessToken, $templateId, $returnUrl);

                if (empty($senderViewUrl) || $senderViewUrl === 'error') {
                    $response = [
                        'success' => 0,
                        'message' => 'Failed to generate sender view URL',
                        'senderViewUrl' => null
                    ];
                } else {
                    $response = [
                        'success' => 1,
                        'message' => 'Fresh sender view URL generated',
                        'senderViewUrl' => $senderViewUrl
                    ];
                }
            }
        } catch (Exception $e) {
            $response = [
                'success' => 0,
                'message' => 'Error: ' . $e->getMessage(),
                'senderViewUrl' => null
            ];
        }

        // Always output JSON in CI3
        $this->output
            ->set_content_type('application/json')
            ->set_output(json_encode($response));
    }
}
