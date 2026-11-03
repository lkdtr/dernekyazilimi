<?php

namespace Modules\Correspondence\Support;

use App\Support\Organization;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;

/**
 * Correspondence settings, kept with the organization settings.
 */
class CorrespondenceSettings
{
    public const DEFAULT_FORMAT = '{yil}/{sira}';

    public const DEFAULT_INCOMING_FORMAT = 'G-{yil}/{sira}';

    public function __construct(private Organization $organization)
    {
    }

    /**
     * Template of the document number: {kutuk} the association's registry
     * number, {yil} year, {sira} sequence number ({sira:4} zero-padded to
     * four digits), {kod} file plan code.
     */
    public function numberFormat(): string
    {
        return $this->organization->get('correspondence_number_format') ?: self::DEFAULT_FORMAT;
    }

    /**
     * Template of the registry number of incoming documents, numbered apart
     * from the outgoing letters: {kutuk}, {yil} and {sira} as above.
     */
    public function incomingFormat(): string
    {
        return $this->organization->get('correspondence_incoming_format') ?: self::DEFAULT_INCOMING_FORMAT;
    }

    /** Public verification page, optionally hosted by the WordPress site. */
    public function verificationUrl(?string $code = null): string
    {
        $url = $this->organization->get('correspondence_verification_url') ?: route('correspondence.verify');

        return $code === null ? $url : $url.(str_contains($url, '?') ? '&' : '?').http_build_query(['kod' => $code]);
    }

    /** First number of a year that has no letters yet. */
    public function startNumber(): int
    {
        return max(1, (int) $this->organization->get('correspondence_start_number', '1'));
    }

    /** The association's registry number ("kütük no", e.g. 06-061-115) from the organization settings, usually the start of its document numbers. */
    public function registryNumber(): ?string
    {
        return $this->organization->get('registry_no');
    }

    /** Identifier of the organization in e-Yazışma packages: its MERSİS number from the organization settings. */
    public function identifier(): ?string
    {
        return $this->organization->get('mersis_no');
    }

    public function identifierScheme(): string
    {
        return $this->organization->get('correspondence_identifier_scheme') ?: 'MERSIS';
    }

    /**
     * Generation of the e-Yazışma packages offered first: "1" for the layout
     * before 2.0, complete with the signature and still widely exchanged, or
     * "2" for the current one, which also needs the organization's seal.
     */
    public function packageGeneration(): string
    {
        return $this->organization->get('correspondence_package_generation') === LetterPackage::CURRENT ? LetterPackage::CURRENT : LetterPackage::OLD;
    }

    /**
     * Time-stamp service the signing application uses to make signatures
     * long-lived; null when none is set.
     *
     * @return array{url: string, user: ?string, password: ?string}|null
     */
    public function timestampService(): ?array
    {
        $url = $this->organization->get('correspondence_tsa_url');

        if (! $url) {
            return null;
        }

        $password = $this->organization->get('correspondence_tsa_password');

        try {
            $password = $password ? Crypt::decryptString($password) : null;
        } catch (DecryptException) {
            $password = null;
        }

        return ['url' => $url, 'user' => $this->organization->get('correspondence_tsa_user'), 'password' => $password];
    }

    /**
     * The password is stored encrypted; null keeps the one already stored.
     */
    public function saveTimestampService(?string $url, ?string $user, ?string $password): void
    {
        $values = ['correspondence_tsa_url' => $url, 'correspondence_tsa_user' => $url ? $user : null];

        if (! $url || ! $user) {
            $values['correspondence_tsa_password'] = null;
        } elseif ($password !== null && $password !== '') {
            $values['correspondence_tsa_password'] = Crypt::encryptString($password);
        }

        $this->organization->save($values);
    }

    public function save(array $values): void
    {
        $this->organization->save($values);
    }
}
