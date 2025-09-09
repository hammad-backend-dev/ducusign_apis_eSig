<?php
defined('BASEPATH') or exit('No direct script access allowed');

class DocuSignMailer
{
    protected $CI;

    public function __construct()
    {
        $this->CI = &get_instance();
        $this->CI->load->library('email');
    }

    /**
     * Send email with PDF attachment
     *
     * @param array $recipients
     * @param string $subject
     * @param string $message
     * @param string $pdfData
     * @param string $attachmentName
     * @return array
     */
    public function sendEmailWithPdf(array $recipients, string $subject, string $message, string $pdfData, string $attachmentName = 'signed_document.pdf')
    {
        $this->CI->email->clear(true); // Clear previous email

        $this->CI->email->from('info@duepro.com', 'DuePro DocuSign System');
        $this->CI->email->to($recipients);
        $this->CI->email->subject($subject);
        $this->CI->email->message($message);
        $this->CI->email->attach($pdfData, 'attachment', $attachmentName, 'application/pdf');

        if ($this->CI->email->send()) {
            return ['success' => true, 'sent_to' => $recipients];
        }

        return ['success' => false, 'error' => $this->CI->email->print_debugger()];
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
