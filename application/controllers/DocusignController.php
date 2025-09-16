<?php
defined('BASEPATH') or exit('No direct script access allowed');

use GuzzleHttp\Client;

class DocusignController extends CI_Controller
{
    protected $docusignService;
    protected $docusignConfig;

    public function __construct()
    {
        parent::__construct();

        $this->load->config('docusign');
        $this->load->library('DocuSignMailer');
        $this->docusignMailer = $this->docusignmailer;
        $this->load->library('AgreementPdfGenerator');
        $this->pdfGenerator = $this->agreementpdfgenerator;
        // $mode = $CI->config->item('docusign_mode');
        $mode = $this->config->item('docusign_mode');

        $this->docusign_url = ($mode === 'production')
            ? $this->config->item('docusign_production_url')
            : $this->config->item('docusign_sandbox_url');



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


    // public function createTemplate()
    // {
    //     try {
    //         $accessToken = $this->getAccessToken();

    //         $documentBase64 = trim($this->input->post('document'));
    //         $coverDocBase64 = trim($this->input->post('cover_document'));

    //         // Check if Base64 is provided
    //         if (empty($documentBase64)) {
    //             echo json_encode([
    //                 'success' => 0,
    //                 'message' => 'Document upload failed',
    //                 'data'    => null
    //             ]);
    //             return;
    //         }

    //         // Merge cover document if it exists
    //         if (!empty($coverDocBase64)) {
    //             $documentBase64 = $this->mergeBase64PDFsAPI($documentBase64, $coverDocBase64);
    //         }

    //         // Decode Base64 to binary for MIME detection
    //         $documentBinary = base64_decode($documentBase64, true);
    //         if ($documentBinary === false) {
    //             echo json_encode([
    //                 'success' => 0,
    //                 'message' => 'Invalid Base64 string',
    //                 'data'    => null
    //             ]);
    //             return;
    //         }

    //         // Detect MIME type
    //         $finfo = new finfo(FILEINFO_MIME_TYPE);
    //         $mimeType = $finfo->buffer($documentBinary);

    //         $extensions = [
    //             'application/pdf' => 'pdf',
    //             'application/msword' => 'doc',
    //             'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    //             'application/zip' => 'zip',
    //             'image/png' => 'png',
    //             'image/jpeg' => 'jpg',
    //         ];

    //         $extension = $extensions[$mimeType] ?? 'bin';
    //         $fileName = 'document_' . time() . '.' . $extension;

    //         $returnUrl = $this->input->post('returnUrl');
    //         $docId     = $this->input->post('attorneyId');

    //         // Send merged document to Docusign
    //         $result = $this->docusignService->createTemplateAndReturnSenderView($accessToken, [
    //             'documentBase64' => $documentBase64,
    //             'fileName'       => $fileName,
    //             'returnUrl'      => $returnUrl
    //         ]);

    //         $this->docusignService->notifyDocumentStatus($docId, true);

    //         echo json_encode([
    //             'success' => 1,
    //             'message' => 'Template created',
    //             'data'    => $result
    //         ]);
    //     } catch (Exception $e) {
    //         $this->docusignService->notifyDocumentStatus(null, false, $e->getMessage());
    //         echo json_encode([
    //             'success' => 0,
    //             'message' => 'Error: ' . $e->getMessage(),
    //             'data'    => null
    //         ]);
    //     }
    // }


    public function createTemplate()
    {
        try {
            $accessToken = $this->getAccessToken();

            $documentBase64 = trim($this->input->post('document'));

            // Check if Base64 is provided
            if (empty($documentBase64)) {
                echo json_encode([
                    'success' => 0,
                    'message' => 'Document upload failed',
                    'data'    => null
                ]);
                return;
            }

            // Decode Base64 to binary for MIME detection
            $documentBinary = base64_decode($documentBase64, true);
            if ($documentBinary === false) {
                echo json_encode([
                    'success' => 0,
                    'message' => 'Invalid Base64 string',
                    'data'    => null
                ]);
                return;
            }

            // Detect MIME type
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mimeType = $finfo->buffer($documentBinary);

            $extensions = [
                'application/pdf'  => 'pdf',
                'application/msword' => 'doc',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
                'application/zip'  => 'zip',
                'image/png'        => 'png',
                'image/jpeg'       => 'jpg',
            ];

            $extension = $extensions[$mimeType] ?? 'bin';
            $fileName  = 'document_' . time() . '.' . $extension;

            $returnUrl = $this->input->post('returnUrl');
            $docId     = $this->input->post('attorneyId');

            // Send document to Docusign
            $result = $this->docusignService->createTemplateAndReturnSenderView($accessToken, [
                'documentBase64' => $documentBase64,
                'fileName'       => $fileName,
                'returnUrl'      => $returnUrl
            ]);

            $this->docusignService->notifyDocumentStatus($docId, true);

            echo json_encode([
                'success' => 1,
                'message' => 'Template created',
                'data'    => $result
            ]);
        } catch (Exception $e) {
            $this->docusignService->notifyDocumentStatus(null, false, $e->getMessage());
            echo json_encode([
                'success' => 0,
                'message' => 'Error: ' . $e->getMessage(),
                'data'    => null
            ]);
        }
    }

    /**
     * Merge two Base64 PDFs using PDF.co
     */
    private function mergeBase64PDFsAPI($base64Doc1, $base64Doc2)
    {
        $client = new \GuzzleHttp\Client();
        $apiKey = 'hammadkhanhk152@gmail.com_TSsmuPD4haZPEJZUjGBx1my4ho88RsdwMghM8C1U6OfIXaDtcRuKaRmd4xyfFfgj';

        // 1️⃣ Upload both Base64 PDFs to get URLs
        $url1 = $this->uploadBase64PDF($base64Doc1, $client, $apiKey);
        $url2 = $this->uploadBase64PDF($base64Doc2, $client, $apiKey);

        // 2️⃣ Merge PDFs using URLs
        $response = $client->post('https://api.pdf.co/v1/pdf/merge', [
            'headers' => [
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'url' => "$url1,$url2",
                'name' => 'merged_document.pdf',
                'async' => false
            ]
        ]);

        $result = json_decode($response->getBody(), true);

        if (!isset($result['url'])) {
            throw new \Exception('PDF merge failed: ' . ($result['message'] ?? 'Unknown error'));
        }

        // Download merged PDF and return Base64
        $mergedPdfBinary = file_get_contents($result['url']);
        return base64_encode($mergedPdfBinary);
    }

    /**
     * Upload a Base64 PDF to PDF.co and get the file URL
     */
    private function uploadBase64PDF($base64Pdf, $client, $apiKey)
    {
        $response = $client->post('https://api.pdf.co/v1/file/upload/base64', [
            'headers' => [
                'x-api-key' => $apiKey,
                'Content-Type' => 'application/json'
            ],
            'json' => [
                'file' => $base64Pdf
            ]
        ]);

        $result = json_decode($response->getBody(), true);

        if (!isset($result['url'])) {
            throw new \Exception('Base64 PDF upload failed: ' . ($result['message'] ?? 'Unknown error'));
        }

        return $result['url'];
    }




    // public function createTemplate()
    // {
    //     try {
    //         $accessToken = $this->getAccessToken();

    //         $documentBase64 = trim($this->input->post('document'));
    //         $coverDocBase64 = trim($this->input->post('cover_document'));

    //         // Check if Base64 is provided
    //         if (empty($documentBase64)) {
    //             // $this->docusignService->notifyDocumentStatus(null, false, 'Document missing');
    //             echo json_encode([
    //                 'success' => 0,
    //                 'message' => 'Document upload failed',
    //                 'data'    => null
    //             ]);
    //             return;
    //         }

    //         // Decode Base64 to binary
    //         $documentBinary = base64_decode($documentBase64, true);
    //         if ($documentBinary === false) {
    //             echo json_encode([
    //                 'success' => 0,
    //                 'message' => 'Invalid Base64 string',
    //                 'data'    => null
    //             ]);
    //             return;
    //         }

    //         // Detect MIME type from binary
    //         $finfo = new finfo(FILEINFO_MIME_TYPE);
    //         $mimeType = $finfo->buffer($documentBinary);

    //         // Map MIME type to file extension
    //         $extensions = [
    //             'application/pdf' => 'pdf',
    //             'application/msword' => 'doc',
    //             'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
    //             'application/zip' => 'zip',
    //             'image/png' => 'png',
    //             'image/jpeg' => 'jpg',
    //         ];

    //         $extension = $extensions[$mimeType] ?? 'bin';
    //         $fileName = 'document_' . time() . '.' . $extension;

    //         $returnUrl      = $this->input->post('returnUrl');
    //         $docId  = $this->input->post('attorneyId');


    //         $result = $this->docusignService->createTemplateAndReturnSenderView($accessToken, [
    //             'documentBase64' => $documentBase64,
    //             'fileName'       => $fileName,
    //             'returnUrl'      => $returnUrl
    //         ]);

    //         $this->docusignService->notifyDocumentStatus($docId, true);

    //         echo json_encode(['success' => 1, 'message' => 'Template created', 'data' => $result]);
    //     } catch (Exception $e) {
    //         $this->docusignService->notifyDocumentStatus(null, false, $e->getMessage());
    //         echo json_encode(['success' => 0, 'message' => 'Error: ' . $e->getMessage(), 'data' => null]);
    //     }
    // }

    // public function createEnvelopeAndRecipientView()
    // {
    //     try {
    //         $accessToken = $this->getAccessToken();
    //         $templateId  = $this->input->post('templateId');

    //         $returnUrl   = $this->input->post('returnUrl');
    //         $name        = $this->input->post('name') ?? 'Default Client';
    //         $email       = $this->input->post('email') ?? 'client@example.com';
    //         $doc_id = $this->input->post('doc_id') ?? '';
    //         $attorney_email = $this->input->post('attorney_email') ?? "softwar.se152@gmail.com";
    //         $otherData = json_decode($this->input->post('otherData'), true);



    //         // pass your frontend data
    //         $pdfPath = $this->pdfGenerator->generate($otherData, "Client Agreement");

    //         // convert to base64 for DocuSign
    //         $documentBase64 = base64_encode(file_get_contents($pdfPath));
    //         print_r($documentBase64);
    //         die;


    //         if (!$templateId || !$returnUrl) {
    //             echo json_encode(['success' => 0, 'message' => 'Missing templateId or returnUrl']);
    //             return;
    //         }

    //         $envelope = $this->docusignService->createEnvelopeFromTemplate($accessToken, [
    //             'templateId' => $templateId,
    //             'roleName'   => 'Client',
    //             'name'       => $name,
    //             'email'      => $email,
    //             'returnUrl'  => $returnUrl
    //         ]);


    //         $envelopeId = $envelope['envelopeId'] ?? null;
    //         if (!$envelopeId) {
    //             echo json_encode(['success' => 0, 'message' => 'Envelope creation failed', 'data' => $envelope]);
    //             return;
    //         }

    //         $returnUrlWithParams = $returnUrl
    //             . '?doc_id=' . urlencode($doc_id)
    //             . '&envelopeId=' . urlencode($envelopeId)
    //             . '&user_email=' . urlencode($email)
    //             . '&attorney_email=' . urlencode($attorney_email);

    //         $recipientView = $this->docusignService->createRecipientView($accessToken, [
    //             'envelopeId' => $envelopeId,
    //             'returnUrl'  => $returnUrlWithParams,
    //             'name'       => $name,
    //             'email'      => $email,
    //         ]);
    //         // $url =  $returnUrl . '?doc_id=' . $doc_id . '&envelopeId=' . $envelopeId;

    //         echo json_encode([
    //             'success' => 1,
    //             // 'id' => $url,
    //             'message' => 'Envelope and recipient view created',
    //             'data'    => ['envelope' => $envelope, 'recipientView' => $recipientView]
    //         ]);
    //     } catch (Exception $e) {
    //         echo json_encode(['success' => 0, 'message' => 'Error: ' . $e->getMessage()]);
    //     }
    // }

    public function createEnvelopeAndRecipientView()
    {
        try {
            $accessToken    = $this->getAccessToken();
            $templateId     = $this->input->post('templateId');
            $returnUrl      = $this->input->post('returnUrl');
            $name           = $this->input->post('name') ?? 'Default Client';
            $email          = $this->input->post('email') ?? 'client@example.com';
            $doc_id         = $this->input->post('doc_id') ?? '';
            $attorney_email = $this->input->post('attorney_email') ?? "softwar.se152@gmail.com";
            // $otherData      = json_decode($this->input->post('otherData'), true);
            $rawOtherData = $this->input->post('otherdata');

            if (is_string($rawOtherData)) {
                $otherData = json_decode($rawOtherData, true);
            } elseif (is_array($rawOtherData)) {
                $otherData = $rawOtherData;
            } else {
                $otherData = null;
            }

            if (!is_array($otherData)) {
                show_error('Invalid otherdata');
                return;
            }



            // 1️⃣ Generate PDF as string
            $pdfString = $this->pdfGenerator->generate($otherData, "Client Agreement", true);
            $agreementBase64 = base64_encode($pdfString);

            // 2️⃣ Get template PDF (base64) from DocuSign
            $templateBase64 = $this->docusignService->getTemplateDocumentBase64($templateId);

            // 3️⃣ Merge both PDFs
            $finalBase64 = $templateBase64
                ? $this->mergeBase64PDFsAPI($templateBase64, $agreementBase64)
                : $agreementBase64;

            // 4️⃣ Create envelope with merged PDF
            // $envelope = $this->docusignService->createEnvelopeFromTemplate($accessToken, [
            //     'name'           => $name,
            //     'email'          => $email,
            //     'roleName'       => 'Client',
            //     'documentBase64' => $finalBase64,
            //     'fileName'       => 'FinalAgreement.pdf'
            // ]);
            // pass templateId and clientUserId (keep clientUserId consistent everywhere)
            $envelope = $this->docusignService->createEnvelopeFromTemplate($accessToken, [
                'templateId'      => $templateId,
                'name'            => $name,
                'email'           => $email,
                'roleName'        => 'Client',
                'documentBase64'  => $finalBase64,
                'fileName'        => 'FinalAgreement.pdf',
                'clientUserId'    => '1234'  // keep same as you use for recipient view
            ]);


            $envelopeId = $envelope['envelopeId'] ?? null;
            if (!$envelopeId) {
                echo json_encode(['success' => 0, 'message' => 'Envelope creation failed', 'data' => $envelope]);
                return;
            }

            // 5️⃣ Recipient signing view
            $returnUrlWithParams = $returnUrl
                . '?doc_id=' . urlencode($doc_id)
                . '&envelopeId=' . urlencode($envelopeId)
                . '&user_email=' . urlencode($email)
                . '&attorney_email=' . urlencode($attorney_email);

            $recipientView = $this->docusignService->createRecipientView($accessToken, [
                'envelopeId' => $envelopeId,
                'returnUrl'  => $returnUrlWithParams,
                'name'       => $name,
                'email'      => $email,
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





    public function signingCallback()
    {
        $docId       = $this->input->get('doc_id');
        $envelopeId  = $this->input->get('envelopeId');
        $userEmail   = $this->input->get('user_email');
        $attorneyEmail = $this->input->get('attorney_email');


        if (!$docId || !$envelopeId) {
            $this->load->view('docusign/error', [
                'message' => 'Missing docId or envelopeId',
                'status'  => 'Unknown'
            ]);
            return;
        }

        try {
            $accessToken = $this->docusignService->createJWTToken();
            $status      = $this->docusignService->getEnvelopeStatus($accessToken, $envelopeId);


            if (!empty($status['status']) && strtolower($status['status']) === 'completed') {
                // Envelope signed
                $this->docusignService->notifyEnvelopeStatus($docId, true);

                // Fetch signed PDF and send email (in-memory)
                try {
                    $pdfData    = $this->docusignService->getSignedDocumentForEmail($accessToken, $envelopeId);


                    $userEmailResult     = $this->docusignMailer->sendUserEmail($userEmail, $pdfData);
                    // print_r($userEmailResult);
                    // die;

                    $attorneyEmailResult = $this->docusignMailer->sendAttorneyEmail($attorneyEmail, $pdfData);
                    // echo "<pre>";
                    // print_r($attorneyEmailResult);
                    // echo "</pre>";
                    // die;
                } catch (Exception $e) {
                    log_message('error', 'Email sending failed: ' . $e->getMessage());
                }

                // Load success view
                $this->load->view('docusign/success', [
                    'message' => 'Thank you, your document is signed!',
                    'status'  => $status['status']
                ]);
            } else {
                // Envelope not signed yet
                $this->docusignService->notifyEnvelopeStatus($docId, false, $status['status'] ?? 'Unknown');

                $this->load->view('docusign/error', [
                    'message' => 'Document not signed yet.',
                    'status'  => $status['status'] ?? 'Unknown'
                ]);
            }
        } catch (Exception $e) {
            // General failure
            log_message('error', 'Signing callback failed: ' . $e->getMessage());
            $this->load->view('docusign/error', [
                'message' => 'An error occurred while processing the envelope.',
                'status'  => 'Error'
            ]);
        }
    }



    // public function signingCallback()
    // {
    //     $this->output->set_content_type('application/json');

    //     $docId      = $this->input->get('doc_id');
    //     $envelopeId = $this->input->get('envelopeId');

    //     if (!$docId || !$envelopeId) {
    //         $this->output->set_output(json_encode([
    //             'success' => 0,
    //             'message' => 'Missing docId or envelopeId'
    //         ]));
    //         return;
    //     }

    //     $accessToken = $this->docusignService->createJWTToken();
    //     $status = $this->docusignService->getEnvelopeStatus($accessToken, $envelopeId);

    //     if (!empty($status['status']) && strtolower($status['status']) === 'completed') {
    //         $pdfData    = $this->docusignService->getSignedDocument($accessToken, $envelopeId);

    //         $res = $this->docusignService->sendCompletionEmail(
    //             ["hammadkhanhk152@gmail.com", "softwar.se152@gmail.com"],
    //             'Document Signed Successfully',
    //             'Hello, your document has been signed and is attached.',
    //             $pdfData
    //         );

    //         $this->output->set_output(json_encode([
    //             'success' => 1,
    //             'message' => 'Document signed and email attempted',
    //             'status'  => $status['status'],
    //             'email_response' => $res
    //         ]));
    //     } else {
    //         $this->output->set_output(json_encode([
    //             'success' => 0,
    //             'message' => 'Document not signed yet',
    //             'status'  => $status['status'] ?? 'Unknown'
    //         ]));
    //     }
    // }



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
            $url = "{$this->docusign_url}/v2.1/accounts/{$accountId}/envelopes/{$envelopeId}/documents/1";

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

    public function testEmail()
    {
        $this->load->library('email');

        $this->email->from('info@duepro.com', 'DuePro System');
        $this->email->to('softwar.se152@gmail.com');
        $this->email->subject('Test GoDaddy SMTP');
        $this->email->message('This is a test email from CodeIgniter using GoDaddy SMTP.');

        if ($this->email->send()) {
            echo 'Email sent successfully!';
        } else {
            echo $this->email->print_debugger();
        }
    }
    public function checkTemplate()
    {
        try {
            $accessToken = $this->getAccessToken();
            $templateId  = $this->input->post_get('templateId');

            if (empty($templateId)) {
                echo json_encode(['success' => 0, 'message' => 'templateId is required']);
                return;
            }

            $result = $this->docusignService->checkTemplateExists($accessToken, $templateId);

            if ($result) {
                echo json_encode([
                    'success' => 1,
                    'message' => 'Template exists',
                    'data'    => $result
                ]);
            } else {
                echo json_encode([
                    'success' => 0,
                    'message' => 'Template not found',
                    'data'    => null
                ]);
            }
        } catch (Exception $e) {
            echo json_encode([
                'success' => 0,
                'message' => 'Error: ' . $e->getMessage(),
                'data'    => null
            ]);
        }
    }
}
