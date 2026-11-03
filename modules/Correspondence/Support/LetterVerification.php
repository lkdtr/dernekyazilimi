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
     * regulation places them.
     */
    public function stamp(string $source, Letter $letter): string
    {
        $url = app(CorrespondenceSettings::class)->verificationUrl($letter->document_id);
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
            $footer->loadHtml('<meta charset="utf-8"><style>@page{margin:2mm 8mm}body{font-family:DejaVu Sans;font-size:7pt}img{float:right;width:23mm;height:23mm}a{color:#111;word-wrap:break-word}</style><img src="data:image/svg+xml;base64,'.base64_encode($qr).'"><div style="margin-right:26mm">Bu belge, güvenli elektronik imza ile imzalanmıştır.<br>Belge doğrulama kodu: '.e($letter->document_id).'<br>Doğrulama: <a href="'.e($url).'">'.e($url).'</a><br>Yazı numarası: '.e($letter->document_no).'</div>', 'UTF-8');
            $footer->render();
            $pdf->setSourceFile(StreamReader::createByString($footer->output()));
            $pdf->useTemplate($pdf->importPage(1, PageBoundaries::CROP_BOX, true, true), 0, $height - 28, $width, 28);
        }

        return $pdf->Output('S');
    }
}
