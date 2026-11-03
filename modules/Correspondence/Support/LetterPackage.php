<?php

namespace Modules\Correspondence\Support;

use App\Support\Organization;
use BahriCanli\EYazisma\Dosya;
use BahriCanli\EYazisma\Enums\DagitimTuru;
use BahriCanli\EYazisma\Enums\PaketAsamasi;
use BahriCanli\EYazisma\Exceptions\EYazismaException;
use BahriCanli\EYazisma\Guid;
use BahriCanli\EYazisma\Model\GercekSahis;
use BahriCanli\EYazisma\Model\IletisimBilgisi;
use BahriCanli\EYazisma\Model\Ilgi;
use BahriCanli\EYazisma\Model\Imza;
use BahriCanli\EYazisma\Model\Kisi;
use BahriCanli\EYazisma\Model\KurumKurulus;
use BahriCanli\EYazisma\Model\NihaiUstveri;
use BahriCanli\EYazisma\Model\Sdp;
use BahriCanli\EYazisma\Model\Tanimlayici;
use BahriCanli\EYazisma\Model\Taraf;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Paket;
use DateTimeImmutable;
use Illuminate\Support\Facades\Storage;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\LetterRecipient;
use Modules\Correspondence\Models\SigningSession;

/**
 * The e-Yazışma package (.eyp) of a numbered letter, kept on the private disk.
 *
 * The package is built from the letter in the 2.x layout or in the one before
 * 2.0, then completed with signatures made elsewhere: the electronic signature
 * over the package digest and the electronic seal over the final digest. The
 * old layout is complete with the signature alone.
 */
class LetterPackage
{
    /** The layout before 2.0: complete with the signature, a seal is optional. */
    public const OLD = '1';

    /** The 2.x layout: signature and seal. */
    public const CURRENT = '2';

    /** Guide version written into packages of the old layout; the one older tools write and recipients accept. */
    private const OLD_VERSION = '1.0';

    public function __construct(
        private Organization $organization,
        private CorrespondenceSettings $settings,
        private LetterPdf $pdf,
    ) {
    }

    public function create(Letter $letter, string $generation = self::CURRENT): Paket
    {
        if (! $letter->isNumbered()) {
            throw new PackageException('Paket yalnız sayı verilmiş yazı için oluşturulabilir.');
        }

        if (! $letter->hasSignedPdf()) {
            throw new PackageException('EYP oluşturmadan önce doğrulama bilgileri eklenmiş PDF’i imzalayıp yükleyin.');
        }

        $old = $generation === self::OLD;
        $letter->loadMissing(['recipients', 'attachments']);

        $builder = Paket::yeni()
            ->belgeId($letter->document_id)
            ->konu($letter->subject)
            ->ozId((string) $letter->id, 'ID')
            ->olusturan($this->creator($letter, $old))
            ->ustYazi(Dosya::icerikten($this->pdf->render($letter), $this->pdf->filename($letter), 'application/pdf'));

        // Date and number are part of the signed metadata in the old layout;
        // the verification address came with 2.0.
        $old
            ? $builder->surum(self::OLD_VERSION)->belge($letter->document_date, $letter->document_no)
            : $builder->dogrulamaAdresi($this->settings->verificationUrl());

        foreach ($letter->recipients as $recipient) {
            $builder->dagitim($this->party($recipient), DagitimTuru::from($recipient->delivery));
        }

        foreach (array_values($letter->references ?? []) as $index => $reference) {
            $builder->ilgi(new Ilgi(Guid::uret(), chr(ord('a') + $index), ad: $reference));
        }

        foreach ($letter->attachments as $attachment) {
            $attachment->hasFile()
                ? $builder->ek(Dosya::icerikten(Storage::disk('local')->get($attachment->path), $attachment->original_name, $attachment->mime), ad: $attachment->name)
                : $builder->fizikselEk($attachment->name);
        }

        if ($letter->file_code) {
            $builder->sdp(new Sdp($letter->file_code, $letter->file_name ?: $letter->file_code));
        }

        try {
            $package = $builder->olustur();
        } catch (EYazismaException $e) {
            throw new PackageException($e->getMessage(), previous: $e);
        }

        $this->store($letter, $package);

        return $package;
    }

    /**
     * What the package waits for: the signature, then the seal. A package of
     * the old layout is complete without the seal, which may still be added.
     */
    public static function pendingStep(Paket $package): ?string
    {
        return match (true) {
            $package->imza() === null => SigningSession::SIGNATURE,
            $package->muhur() === null => SigningSession::SEAL,
            default => null,
        };
    }

    /** Whether nothing more is needed: signed, and sealed too in the 2.x layout. */
    public static function isComplete(Paket $package): bool
    {
        return $package->asama() === PaketAsamasi::Tamamlandi;
    }

    public function open(Letter $letter): ?Paket
    {
        if (! $letter->package_path || ! Storage::disk('local')->exists($letter->package_path)) {
            return null;
        }

        return Paket::icerikten(Storage::disk('local')->get($letter->package_path));
    }

    /**
     * Add the signature made over the package digest.
     */
    public function sign(Letter $letter, string $signature): Paket
    {
        $package = $this->open($letter) ?? throw new PackageException('Yazının paketi yok.');

        try {
            $package->imzaEkle($signature, new NihaiUstveri(
                DateTimeImmutable::createFromInterface($letter->approved_at ?? $letter->document_date),
                $letter->document_no,
                self::certificateSigner($signature) ?? array_map(fn (array $signer) => new Imza(new GercekSahis(
                    new Kisi($signer['first_name'], $signer['last_name']),
                    gorev: $signer['title'] ?? null,
                )), $letter->signers ?? []),
            ));
        } catch (EYazismaException $e) {
            throw new PackageException($e->getMessage(), previous: $e);
        }

        $this->store($letter, $package);

        return $package;
    }

    /**
     * Add the seal made over the final digest; the package is complete.
     */
    public function seal(Letter $letter, string $seal): Paket
    {
        $package = $this->open($letter) ?? throw new PackageException('Yazının paketi yok.');

        try {
            $package->muhurEkle($seal);
        } catch (EYazismaException $e) {
            throw new PackageException($e->getMessage(), previous: $e);
        }

        $this->store($letter, $package);

        return $package;
    }

    public function delete(Letter $letter): void
    {
        if ($letter->package_path) {
            Storage::disk('local')->delete($letter->package_path);
            $letter->forceFill(['package_path' => null])->save();
        }
    }

    private function store(Letter $letter, Paket $package): void
    {
        $path = $letter->directory().'/'.$package->dosyaAdi();
        Storage::disk('local')->put($path, $package->icerik());
        $letter->forceFill(['package_path' => $path])->save();
    }

    /**
     * The organization, by its MERSİS number. Without one, a package of the
     * old layout names the first signer instead, as older tools do.
     */
    private function creator(Letter $letter, bool $old): Taraf
    {
        if (! $this->settings->identifier()) {
            $signer = $letter->signers[0] ?? null;

            if (! $old || $signer === null) {
                throw new PackageException('Paket için kurum ayarlarında MERSİS numarası tanımlanmalıdır.');
            }

            return new GercekSahis(new Kisi($signer['first_name'], $signer['last_name']), gorev: $signer['title'] ?? null);
        }

        $contact = array_filter([
            'telefon' => $this->organization->get('phone'),
            'ePosta' => $this->organization->get('contact_email'),
            'webAdresi' => $this->organization->get('website_url'),
            'adres' => $this->organization->officialAddress(),
        ]);

        return new TuzelSahis(
            new Tanimlayici($this->settings->identifier(), $this->settings->identifierScheme()),
            $this->organization->name(),
            $contact ? new IletisimBilgisi(...$contact) : null,
        );
    }

    private function party(LetterRecipient $recipient): Taraf
    {
        $contact = $recipient->address ? new IletisimBilgisi(adres: $recipient->address) : null;

        if ($recipient->kind === LetterRecipient::PERSON) {
            [$first, $last] = self::splitName($recipient->name);

            return new GercekSahis(new Kisi($first, $last), $recipient->identifier ?: null, iletisimBilgisi: $contact);
        }

        if (! $recipient->identifier) {
            throw new PackageException("Paket için alıcının ".LetterRecipient::IDENTIFIERS[$recipient->kind]." değeri gerekir: {$recipient->name}");
        }

        return $recipient->kind === LetterRecipient::INSTITUTION
            ? new KurumKurulus($recipient->identifier, $recipient->name, $contact)
            : new TuzelSahis(new Tanimlayici($recipient->identifier, 'MERSIS'), $recipient->name, $contact);
    }

    /**
     * Whoever signed the package, as the certificate in the signature names
     * them: the holder may differ from the signers of the letter. Null when
     * the signature carries no certificate of a person.
     *
     * @return ?list<Imza>
     */
    private static function certificateSigner(string $signature): ?array
    {
        $holder = self::certificateHolder($signature);

        if ($holder === null) {
            return null;
        }

        [$first, $last] = self::splitName($holder['name']);

        return [new Imza(new GercekSahis(new Kisi($first, $last), $holder['identity']))];
    }

    /**
     * The person whose certificate a CAdES signature carries: the name and
     * T.C. identity number on it. Null when it carries none.
     *
     * @return ?array{name: string, identity: string}
     */
    public static function certificateHolder(string $signature): ?array
    {
        $pem = "-----BEGIN PKCS7-----\n".chunk_split(base64_encode($signature), 64, "\n")."-----END PKCS7-----\n";

        if (! @openssl_pkcs7_read($pem, $certificates)) {
            return null;
        }

        foreach ($certificates as $certificate) {
            $parsed = openssl_x509_parse($certificate) ?: [];
            $identity = (string) ($parsed['subject']['serialNumber'] ?? '');
            $name = (string) ($parsed['subject']['CN'] ?? '');

            if ($name !== '' && preg_match('/^\d{11}$/', $identity)) {
                return ['name' => $name, 'identity' => $identity];
            }
        }

        return null;
    }

    /**
     * @return array{0: string, 1: string} first names and the last name
     */
    private static function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name));
        $last = count($parts) > 1 ? array_pop($parts) : '';

        return [implode(' ', $parts), $last];
    }
}
