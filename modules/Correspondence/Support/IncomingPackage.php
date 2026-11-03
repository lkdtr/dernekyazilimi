<?php

namespace Modules\Correspondence\Support;

use BahriCanli\EYazisma\Dosya;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Model\GercekSahis;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Paket;
use Modules\Correspondence\Models\LetterRecipient;

/**
 * What a received e-Yazışma package says about itself: subject, sender,
 * number and date, the letter and its attachments, the findings of the
 * package check and who signed it. The signature is not checked against
 * its certificate chain here.
 */
final class IncomingPackage
{
    /**
     * @param  array<string, mixed>  $details  subject, sender_kind, sender_name, sender_identifier, document_no, document_date
     * @param  array<string, Dosya>  $attachments  name of the attachment => file
     * @param  array{errors: list<string>, warnings: list<string>}  $findings
     */
    private function __construct(
        public readonly string $documentId,
        public readonly array $details,
        public readonly Dosya $letter,
        public readonly array $attachments,
        public readonly array $findings,
        public readonly ?string $signer,
    ) {
    }

    public static function read(string $content): self
    {
        try {
            $package = Paket::icerikten($content);
            $metadata = $package->ustveri();
            $final = $package->nihaiUstveri();
            $letter = $package->ustYazi();
            $report = $package->dogrula();
        } catch (EYazismaException $exception) {
            throw new PackageException('e-Yazışma paketi okunamadı: '.$exception->getMessage(), previous: $exception);
        }

        $attachments = [];
        foreach ($package->ekDosyalari() as $id => $file) {
            $name = $metadata->ek($id)?->ad ?: $file->ad;
            $attachments[isset($attachments[$name]) ? $name.' ('.$id.')' : $name] = $file;
        }

        $sender = $metadata->olusturan;
        $signature = $package->imza();
        $holder = $signature === null ? null : LetterPackage::certificateHolder($signature);

        return new self(
            strtoupper($metadata->belgeId),
            [
                'subject' => $metadata->konu,
                'sender_kind' => match (true) {
                    $sender instanceof KurumKurulus => LetterRecipient::INSTITUTION,
                    $sender instanceof GercekSahis => LetterRecipient::PERSON,
                    default => LetterRecipient::LEGAL,
                },
                'sender_name' => $sender->gorunenAd(),
                'sender_identifier' => $sender->kimlik(),
                'document_no' => $final?->belgeNo ?: null,
                'document_date' => $final?->tarih->format('Y-m-d'),
            ],
            $letter,
            $attachments,
            [
                'errors' => array_map('strval', $report->hatalar()),
                'warnings' => array_map('strval', $report->uyarilar()),
            ],
            $holder === null ? null : $holder['name'],
        );
    }
}
