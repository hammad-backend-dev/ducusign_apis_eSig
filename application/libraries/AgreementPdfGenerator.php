<?php

defined('BASEPATH') or exit('No direct script access allowed');

use Mpdf\Mpdf;
use Mpdf\MpdfException;

class AgreementPdfGenerator
{
    protected Mpdf $mpdf;

    /**
     * AgreementPdfGenerator constructor.
     * Initializes mPDF with A4 size and 1 inch margins.
     *
     * @throws MpdfException
     */
    public function __construct()
    {
        $this->mpdf = new Mpdf([
            'format'        => 'A4',
            'margin_left'   => 25.4, // 1 inch = 25.4 mm
            'margin_right'  => 25.4,
            'margin_top'    => 25.4,
            'margin_bottom' => 25.4,
        ]);

        // Footer
        $this->mpdf->SetFooter('Page {PAGENO} of {nb}');
    }

    /**
     * Generate PDF from data array.
     * Always returns PDF content as string (memory only).
     *
     * @param array  $otherData
     * @param string $title
     * @return string PDF content
     * @throws MpdfException
     */
    public function generate(array $otherData, string $title = "Client Agreement"): string
    {
        $html = $this->buildHtml($otherData, $title);
        $this->mpdf->WriteHTML($html);

        // Return PDF as string in memory
        return $this->mpdf->Output('', 'S'); // 'S' = PDF as string
    }

    /**
     * Build HTML for the PDF.
     *
     * @param array $otherData
     * @param string $title
     * @return string
     */
    protected function buildHtml(array $otherData, string $title): string
    {
        $html = "<h2 style='text-align:center; margin-bottom:20px;'>{$title}</h2>";
        $html .= "<table style='width:100%; border-collapse: collapse; font-size:12pt;'>";
        $html .= "<tr><th style='border:1px solid #000; padding:5px;'>Key</th>";
        $html .= "<th style='border:1px solid #000; padding:5px;'>Value</th></tr>";

        foreach ($otherData as $key => $value) {
            $label = ucwords(preg_replace('/([a-z])([A-Z])/', '$1 $2', $key));
            $html .= "<tr>";
            $html .= "<td style='border:1px solid #000; padding:5px;'>{$label}</td>";
            $html .= "<td style='border:1px solid #000; padding:5px;'>{$value}</td>";
            $html .= "</tr>";
        }

        $html .= "</table>";
        return $html;
    }
}
