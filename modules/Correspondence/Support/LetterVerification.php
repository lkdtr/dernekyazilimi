<?php

namespace Modules\Correspondence\Support;

use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Dompdf\Dompdf;
use Modules\Correspondence\Models\Letter;
use setasign\Fpdi\Fpdi;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\PdfReader\PageBoundaries;

class LetterVerification
{
    /**
     * Reserve a footer on every page before signing, without covering the
     * source. Its first two lines are the signature notice and the
     * verification code, as article 23 of the official correspondence
     * regulation places them; the notice names the article of law 5070 that
     * gives the signature the force of a handwritten one. The address is printed without the code, as
     * institutions print it; the link and the QR code carry it.
     */
    public function stamp(string $source, Letter $letter): string
    {
        $settings = app(CorrespondenceSettings::class);
        $url = $settings->verificationUrl($letter->document_id);
        $address = $settings->verificationUrl();
        $qr = (new Writer(new ImageRenderer(new RendererStyle(120, 4), new SvgImageBackEnd())))->writeString($url);
        $pdf = new Fpdi;
        $pdf->SetAutoPageBreak(false);
        $count = $pdf->setSourceFile(StreamReader::createByString($source));
        $pages = [];
        for ($page = 1; $page <= $count; $page++) {
            $template = $pdf->importPage($page, PageBoundaries::CROP_BOX, true, true);
            $pages[] = [$template, $pdf->getTemplateSize($template)];
        }
        foreach ($pages as [$template, $size]) {
            $width = $size['width'];
            $height = $size['height'];
            $pdf->AddPage($size['orientation'], [$width, $height]);
            $scale = min(1, ($height - 30) / $height);
            $pdf->useTemplate($template, ($width - $width * $scale) / 2, 0, $width * $scale);
            $footer = new Dompdf;
            $footer->setPaper([0, 0, $width * 72 / 25.4, 28 * 72 / 25.4]);
            $footer->loadHtml('<meta charset="utf-8"><style>@page{margin:2mm 8mm}body{font-family:DejaVu Sans;font-size:7pt}img{float:right;width:23mm;height:23mm}a{color:#111;word-wrap:break-word}</style><img src="data:image/svg+xml;base64,'.base64_encode($qr).'"><div style="margin-right:26mm;padding-top:3.5mm"><div style="border-top:0.6pt solid #111;padding-top:1.2mm;margin-bottom:1.2mm;font-size:7.5pt;font-weight:bold">Bu belge, 5070 sayılı Elektronik İmza Kanunu\'nun 5. maddesi gereğince güvenli elektronik imza ile imzalanmıştır.</div><table style="width:100%;border-collapse:collapse;font-size:7pt"><tr><td style="padding:0">Belge Doğrulama Kodu: '.e($letter->document_id).'</td><td style="padding:0;text-align:right">Belge Doğrulama Adresi: <a href="'.e($url).'">'.e($address).'</a></td></tr></table>Yazı numarası: '.e($letter->document_no).'</div>', 'UTF-8');
            $footer->render();
            $pdf->setSourceFile(StreamReader::createByString($footer->output()));
            $pdf->useTemplate($pdf->importPage(1, PageBoundaries::CROP_BOX, true, true), 0, $height - 28, $width, 28);
        }

        return $pdf->Output('S');
    }
}
