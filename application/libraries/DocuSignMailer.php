<?php
defined('BASEPATH') or exit('No direct script access allowed');

class DocuSignMailer
{
    protected $endpoint = "https://us-central1-duepro-2cf60.cloudfunctions.net/emailwidgets/sendemail";
    protected $CI;

    public function __construct()
    {
        $this->CI = &get_instance(); // CI instance for helper loading
        $this->CI->load->helper('url');
    }

    /**
     * Send email with PDF link
     */
    public function sendEmailWithPdf(array $recipients, string $subject, string $message, string $pdfData, string $attachmentName = 'signed_document.pdf')
    {
        // Ensure uploads folder exists
        $uploadDir = FCPATH . 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }

        // Full file path
        $filePath = $uploadDir . $attachmentName;

        // Save PDF file
        if (file_put_contents($filePath, $pdfData) === false) {
            return ['success' => false, 'error' => 'Failed to save PDF file'];
        }

        // Public URL for file
        $pdfUrl = base_url('uploads/' . $attachmentName);
        // return $pdfUrl;

        // Create button
        $button = '<a href="' . $pdfUrl . '" 
           style="display:inline-block;padding:10px 20px;
                  background:#007bff;color:#fff;
                  text-decoration:none;border-radius:5px;">
           View Signed Document
           </a>';

        // Add to message
        $message .= "<br><br>" . $button;
        // print_r($message);

        // Build payload for API
        $payload = [
            "email"       => $recipients[0],
            "subject"     => $subject,
            "message"     => $message,
            "pdfUrl"      => $pdfUrl,
            "pdfFileName" => $attachmentName
        ];


        // Send via CURL
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $this->endpoint,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => 30,
        ]);

        $response = curl_exec($ch);
        $error    = curl_error($ch);
        curl_close($ch);

        if ($error) {
            return ['success' => false, 'error' => $error];
        }

        return ['success' => true, 'response' => $response];
    }

    /**
     * Send email specifically to user
     */
    public function sendUserEmail(string $userEmail, string $pdfData)
    {
        $subject = 'Your Document is Signed!';
        $message = 'Hello, your document has been signed successfully. Please see the attached PDF.';
        return $this->sendEmailWithPdf([$userEmail], $subject, $message, $pdfData, 'user_signed_document.pdf');
    }

    /**
     * Send email specifically to attorney
     */
    public function sendAttorneyEmail(string $attorneyEmail, string $pdfData)
    {
        $subject = 'Client Document Signed';
        $message = 'Hello, the client’s document has been signed. PDF attached for your records.';
        return $this->sendEmailWithPdf([$attorneyEmail], $subject, $message, $pdfData, 'attorney_signed_document.pdf');
    }
}
