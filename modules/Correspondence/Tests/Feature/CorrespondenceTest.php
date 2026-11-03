<?php

namespace Modules\Correspondence\Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\Organization;
use BahriCanli\EYazisma\Enums\PaketAsamasi;
use BahriCanli\EYazisma\Enums\Surum;
use BahriCanli\EYazisma\Model\TuzelSahis;
use BahriCanli\EYazisma\Paket;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Modules\Correspondence\Models\IncomingDocument;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\LetterSequence;
use Modules\Correspondence\Models\SigningSession;
use Modules\Correspondence\Support\Numbering;
use Tests\TestCase;

class CorrespondenceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function owner(): User
    {
        return User::factory()->create(['role' => 1]);
    }

    private function userWith(array $permissions): User
    {
        $role = Role::create(['key' => 'role-'.uniqid(), 'name' => 'Rol']);
        $role->syncPermissions(array_merge(['admin.access'], $permissions));
        $user = User::factory()->create();
        $user->roles()->attach($role);

        return $user;
    }

    private function form(array $overrides = []): array
    {
        return array_replace([
            'subject' => 'Şenlik daveti',
            'body' => '<p>Şenliğimize bekleriz.</p><script>alert(1)</script>',
            'references' => "12.03.2026 tarihli ve 2026/12 sayılı yazımız.\n\nİkinci ilgi.",
            'file_code' => '010.06',
            'file_name' => 'Davetler',
            'recipients' => [
                ['kind' => 'institution', 'name' => 'Adalet Bakanlığı', 'identifier' => '24301050', 'address' => 'Ankara', 'delivery' => 'GRG'],
                ['kind' => 'person', 'name' => 'Ali Rıza Kaya', 'identifier' => '', 'address' => '', 'delivery' => 'BLG'],
                ['kind' => 'institution', 'name' => '', 'identifier' => '', 'address' => '', 'delivery' => 'GRG'],
            ],
            'signers' => [
                ['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'title' => 'Yönetim Kurulu Başkanı'],
                ['first_name' => '', 'last_name' => '', 'title' => ''],
            ],
        ], $overrides);
    }

    private function letter(User $user, array $overrides = []): Letter
    {
        $this->actingAs($user)->post('/admin/correspondence', $this->form($overrides))->assertSessionHasNoErrors();

        return Letter::latest('id')->first();
    }

    private function numbered(User $user, array $overrides = []): Letter
    {
        $letter = $this->letter($user, $overrides);
        $this->actingAs($user)->post("/admin/correspondence/{$letter->id}/approve")->assertSessionHasNoErrors();

        return $letter->fresh();
    }

    private function signPdf(Letter $letter, User $owner): string
    {
        $link = $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/pdf/signing-link", ['signing_method' => 'application'])->assertSessionHasNoErrors()->getSession()->get('pdf-signing-link');
        $session = $this->getJson($link)->assertOk()->assertJsonPath('step', 'pdf')->json();
        $this->postJson($link, ['signature' => base64_encode('invalid CMS')])->assertUnprocessable();
        $this->postJson($link, ['signature' => base64_encode($this->cmsSignature(base64_decode($session['content'])))])->assertOk();
        $this->getJson($link)->assertNotFound();
        return Storage::disk('local')->get($letter->signedPdfPath());
    }

    private function cmsSignature(string $content, array $subject = ['commonName' => 'Test signer']): string
    {
        $input = tmpfile();
        $output = tmpfile();
        try {
            fwrite($input, $content);
            $key = openssl_pkey_new(['private_key_bits' => 2048]);
            $csr = openssl_csr_new($subject, $key);
            $certificate = openssl_csr_sign($csr, null, $key, 1);
            $this->assertTrue(openssl_cms_sign(stream_get_meta_data($input)['uri'], stream_get_meta_data($output)['uri'], $certificate, $key, [], OPENSSL_CMS_BINARY, OPENSSL_ENCODING_DER));
            rewind($output);
            return stream_get_contents($output);
        } finally {
            fclose($input);
            fclose($output);
        }
    }

    /** A standard incremental PDF signature revision, as an external signer writes it. */
    private function externalSigningDocument(string $source): string
    {
        preg_match_all('/\/Size (\d+)/', $source, $sizes);
        preg_match_all('/startxref\s+(\d+)/', $source, $xrefs);
        $first = (int) end($sizes[1]);
        $previous = (int) end($xrefs[1]);
        $catalog = $first + 2;
        $objects = [
            '<< /Type /Sig /Filter /Adobe.PPKLite /SubFilter /adbe.pkcs7.detached /ByteRange [0 0000000000 0000000000 0000000000] /Contents <'.str_repeat('0', \Modules\Correspondence\Support\PdfSigningDocument::CAPACITY * 2).'> >>',
            '<< /Type /Annot /Subtype /Widget /FT /Sig /T (ExternalSignature) /Rect [0 0 0 0] /V '.$first.' 0 R >>',
            '<< /Type /Catalog /Pages 1 0 R /AcroForm << /SigFlags 3 /Fields ['.($first + 1).' 0 R] >> >>',
        ];
        $pdf = $source."\n";
        $offsets = [];
        foreach ($objects as $index => $object) {
            $offsets[] = strlen($pdf);
            $pdf .= ($first + $index)." 0 obj\n".$object."\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n{$first} 3\n";
        foreach ($offsets as $offset) {
            $pdf .= sprintf("%010d 00000 n \n", $offset);
        }
        $pdf .= 'trailer << /Size '.($first + 3).' /Root '.$catalog.' 0 R /Prev '.$previous." >>\nstartxref\n{$xref}\n%%EOF\n";
        $start = strpos($pdf, '/Contents <', strlen($source)) + strlen('/Contents ');
        $end = $start + \Modules\Correspondence\Support\PdfSigningDocument::CAPACITY * 2 + 2;

        return str_replace('/ByteRange [0 0000000000 0000000000 0000000000]', sprintf('/ByteRange [0 %010d %010d %010d]', $start, $end, strlen($pdf) - $end), $pdf);
    }

    public function test_a_draft_is_written_and_edited(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->get('/admin/correspondence/create')->assertOk()->assertSee('Alıcı ekle');
        $letter = $this->letter($owner);

        $this->assertSame(Letter::DRAFT, $letter->status);
        $this->assertNull($letter->document_no);
        $this->assertMatchesRegularExpression('/^[0-9A-F]{8}(-[0-9A-F]{4}){3}-[0-9A-F]{12}$/', $letter->document_id);
        $this->assertStringNotContainsString('<script', $letter->body);
        $this->assertSame(['12.03.2026 tarihli ve 2026/12 sayılı yazımız.', 'İkinci ilgi.'], $letter->references);
        $this->assertSame([['first_name' => 'Ayşe', 'last_name' => 'Yılmaz', 'title' => 'Yönetim Kurulu Başkanı']], $letter->signers);
        $this->assertSame(['Adalet Bakanlığı', 'Ali Rıza Kaya'], $letter->recipients->pluck('name')->all());
        $this->assertSame($owner->id, $letter->created_by);

        $this->actingAs($owner)->get('/admin/correspondence')->assertOk()->assertSee('Şenlik daveti')->assertSee('Taslak');
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertOk()->assertSee('Adalet Bakanlığı')->assertSee('Onayla ve sayı ver');
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/edit")->assertOk()->assertSee('Ali Rıza Kaya');

        $this->actingAs($owner)->put("/admin/correspondence/{$letter->id}", $this->form(['subject' => 'Yeni konu', 'recipients' => [['kind' => 'legal', 'name' => 'Örnek A.Ş.', 'delivery' => 'GRG']]]))->assertSessionHasNoErrors();
        $this->assertSame('Yeni konu', $letter->fresh()->subject);
        $this->assertSame(['Örnek A.Ş.'], $letter->fresh()->recipients->pluck('name')->all());

        $this->actingAs($owner)->post('/admin/correspondence', $this->form(['recipients' => [], 'signers' => []]))->assertSessionHasErrors(['recipients', 'signers']);
    }

    public function test_approval_gives_the_next_number_of_the_year_and_fixes_the_content(): void
    {
        $owner = $this->owner();
        app(Organization::class)->save(['correspondence_number_format' => 'E-{kod}-{sira:4}/{yil}', 'correspondence_start_number' => '41']);

        $first = $this->numbered($owner);
        $second = $this->numbered($owner, ['file_code' => '']);
        $year = now()->year;

        $this->assertSame(Letter::NUMBERED, $first->status);
        $this->assertSame("E-010.06-0041/{$year}", $first->document_no);
        $this->assertSame("E--0042/{$year}", $second->document_no);
        $this->assertSame([41, 42], [$first->number, $second->number]);
        $this->assertTrue($first->document_date->isToday());
        $this->assertSame($owner->id, $first->approved_by);
        $this->assertSame(42, LetterSequence::find($year)->last_number);

        $this->actingAs($owner)->put("/admin/correspondence/{$first->id}", $this->form(['subject' => 'Değişti']))->assertForbidden();
        $this->actingAs($owner)->get("/admin/correspondence/{$first->id}/edit")->assertForbidden();
        $this->actingAs($owner)->delete("/admin/correspondence/{$first->id}")->assertForbidden();
        $this->actingAs($owner)->post("/admin/correspondence/{$first->id}/approve")->assertForbidden();
        $this->assertSame('Şenlik daveti', $first->fresh()->subject);

        // A cancelled letter keeps its number; the next letter gets a new one.
        $this->actingAs($owner)->post("/admin/correspondence/{$first->id}/cancel", [])->assertSessionHasErrors('cancel_reason');
        $this->actingAs($owner)->post("/admin/correspondence/{$first->id}/cancel", ['cancel_reason' => 'Yanlış alıcı'])->assertSessionHasNoErrors();
        $this->assertSame(Letter::CANCELLED, $first->fresh()->status);
        $this->assertSame(43, $this->numbered($owner)->number);
    }

    public function test_number_formats(): void
    {
        $this->assertSame('2026/7', Numbering::format('{yil}/{sira}', 2026, 7));
        $this->assertSame('2026-00007', Numbering::format('{yil}-{sira:5}', 2026, 7));
        $this->assertSame('E-804.01-12', Numbering::format('E-{kod}-{sira}', 2026, 12, '804.01'));
        $this->assertSame('06-061-115-2026-22', Numbering::format('{kutuk}-{yil}-{sira}', 2026, 22, null, '06-061-115'));
    }

    public function test_the_number_can_start_with_the_registry_number_of_the_association(): void
    {
        $owner = $this->owner();
        $settings = ['number_format' => '{kutuk}-{yil}-{sira}', 'start_number' => 22];

        $this->actingAs($owner)->put('/admin/correspondence/settings', $settings)->assertSessionHasErrors('number_format');
        $this->actingAs($owner)->put('/admin/settings/organization', ['name' => 'Örnek Derneği', 'registry_no' => '06-061-115'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put('/admin/correspondence/settings', $settings)->assertSessionHasNoErrors();
        $this->actingAs($owner)->get('/admin/correspondence/settings')->assertOk()->assertSee('06-061-115-'.now()->year.'-41');

        $this->assertSame('06-061-115-'.now()->year.'-22', $this->numbered($owner)->document_no);
        $this->assertSame('06-061-115-'.now()->year.'-23', $this->numbered($owner)->document_no);
    }

    public function test_writing_and_approving_are_separate_permissions(): void
    {
        $writer = $this->userWith(['correspondence.view', 'correspondence.manage']);
        $approver = $this->userWith(['correspondence.view', 'correspondence.approve']);
        $reader = $this->userWith(['correspondence.view']);

        $letter = $this->letter($writer);

        $this->actingAs($reader)->get("/admin/correspondence/{$letter->id}")->assertOk()->assertDontSee('Onayla ve sayı ver');
        $this->actingAs($reader)->get('/admin/correspondence/create')->assertForbidden();
        $this->actingAs($reader)->get('/admin/correspondence/settings')->assertForbidden();
        $this->actingAs($writer)->post("/admin/correspondence/{$letter->id}/approve")->assertForbidden();
        $this->actingAs($approver)->post('/admin/correspondence', $this->form())->assertForbidden();

        $this->actingAs($writer)->get("/admin/correspondence/{$letter->id}")->assertSee('Onaya gönder');
        $this->actingAs($writer)->post("/admin/correspondence/{$letter->id}/submit")->assertSessionHasNoErrors();
        $this->assertSame(Letter::PENDING, $letter->fresh()->status);
        $this->actingAs($writer)->put("/admin/correspondence/{$letter->id}", $this->form())->assertForbidden();

        $this->actingAs($approver)->post("/admin/correspondence/{$letter->id}/return");
        $this->assertSame(Letter::DRAFT, $letter->fresh()->status);

        $this->actingAs($writer)->post("/admin/correspondence/{$letter->id}/submit");
        $this->actingAs($approver)->post("/admin/correspondence/{$letter->id}/approve");
        $this->assertSame(Letter::NUMBERED, $letter->fresh()->status);
        $this->assertSame($approver->id, $letter->fresh()->approved_by);

        $this->get("/admin/correspondence/{$letter->id}")->assertOk();
        auth()->logout();
        $this->get('/admin/correspondence')->assertRedirect();
    }

    public function test_attachments_are_kept_privately_and_only_on_drafts(): void
    {
        $owner = $this->owner();
        $letter = $this->letter($owner);

        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/attachments", ['name' => 'Rapor', 'file' => UploadedFile::fake()->createWithContent('Çalışma Raporu.pdf', '%PDF-1.4 rapor')])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/attachments", ['name' => 'İki adet CD'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/attachments", ['name' => 'Betik', 'file' => UploadedFile::fake()->createWithContent('a.php', '<?php')])->assertSessionHasErrors('file');

        [$file, $physical] = $letter->attachments()->get()->all();

        $this->assertTrue($file->hasFile());
        $this->assertFalse($physical->hasFile());
        Storage::disk('local')->assertExists($file->path);
        $this->assertStringStartsWith("correspondence/{$letter->id}/ekler/", $file->path);

        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/attachments/{$file->id}")->assertOk()->assertStreamedContent('%PDF-1.4 rapor');
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/attachments/{$physical->id}")->assertNotFound();

        $this->actingAs($owner)->delete("/admin/correspondence/{$letter->id}/attachments/{$physical->id}")->assertSessionHasNoErrors();
        $this->assertSame(1, $letter->attachments()->count());

        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/approve");
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/attachments", ['name' => 'Geç kalan ek'])->assertForbidden();
        $this->actingAs($owner)->delete("/admin/correspondence/{$letter->id}/attachments/{$file->id}")->assertForbidden();

        $draft = $this->letter($owner);
        $this->actingAs($owner)->post("/admin/correspondence/{$draft->id}/attachments", ['name' => 'Ek', 'file' => UploadedFile::fake()->createWithContent('ek.pdf', '%PDF-1.4')]);
        $path = $draft->attachments()->first()->path;
        $this->actingAs($owner)->delete("/admin/correspondence/{$draft->id}")->assertRedirect('/admin/correspondence');
        // Nothing is deleted for good: the draft and its files wait in "Silinenler".
        Storage::disk('local')->assertExists($path);
        $this->assertNull(Letter::find($draft->id));
        $this->actingAs($owner)->get('/admin/trash')->assertOk()->assertSee($draft->subject);
        $this->actingAs($owner)->patch("/admin/trash/letter/{$draft->id}")->assertSessionHas('success-status');
        $this->assertSame(1, Letter::find($draft->id)->attachments()->count());
    }

    public function test_the_letter_is_printed_as_a_pdf(): void
    {
        $owner = $this->owner();
        $letter = $this->letter($owner);

        $draft = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertStringStartsWith('%PDF-', $draft->getContent());

        $html = view('correspondence::pdf.letter', ['letter' => $letter->load(['recipients', 'attachments']), 'logo' => null])->render();
        $this->assertStringContainsString('TASLAK', $html);
        $this->assertStringContainsString('DAĞITIM YERLERİNE', $html);
        $this->assertStringContainsString('Yönetim Kurulu Başkanı', $html);

        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/approve");
        $html = view('correspondence::pdf.letter', ['letter' => $letter->fresh()->load(['recipients', 'attachments']), 'logo' => null])->render();
        $this->assertStringNotContainsString('TASLAK', $html);
        $this->assertStringContainsString($letter->fresh()->document_no, $html);
        $final = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/pdf")->assertOk()->getContent();
        $this->assertNotSame($draft->getContent(), $final);
        $this->assertStringContainsString(route('correspondence.verify', ['kod' => $letter->document_id]), $final);

        // The letterhead carries the registered address, not the public one.
        $this->actingAs($owner)->put('/admin/settings/organization', ['name' => 'Örnek Derneği', 'address' => 'PK 50 Yenişehir', 'official_address' => 'Deneme Sok. No: 1 Çankaya', 'kep_address' => 'ornek@hs01.kep.tr'])->assertSessionHasNoErrors();
        $html = view('correspondence::pdf.letter', ['letter' => $letter->fresh()->load(['recipients', 'attachments']), 'logo' => null])->render();
        $this->assertStringContainsString('Deneme Sok. No: 1 Çankaya', $html);
        $this->assertStringNotContainsString('PK 50 Yenişehir', $html);
        $this->assertStringContainsString('KEP: ornek@hs01.kep.tr', $html);

        // The letterhead shows the logo or, without one, the name: never both.
        $this->assertStringContainsString('<td class="name">Örnek Derneği</td>', $html);
        $html = view('correspondence::pdf.letter', ['letter' => $letter->fresh()->load(['recipients', 'attachments']), 'logo' => 'data:image/png;base64,AAAA'])->render();
        $this->assertStringContainsString('<td class="name"><img src="data:image/png;base64,AAAA"', $html);
        $this->assertStringNotContainsString('<td class="name">Örnek Derneği', $html);
    }

    public function test_starting_pdf_signing_opens_the_local_application_and_rejects_other_addresses(): void
    {
        $owner = $this->owner();
        $letter = $this->numbered($owner);
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertOk()->assertSee('id="pdf-signing-form"', false);
        $this->post("/admin/correspondence/{$letter->id}/pdf/signing-link", [
            'signing_method' => 'application', 'launch_application' => 1,
            'application_address' => 'https://evil.example/',
        ])->assertSessionHasErrors('application_address');
        $this->assertSame(0, SigningSession::where('letter_id', $letter->id)->count());
        $response = $this->post("/admin/correspondence/{$letter->id}/pdf/signing-link", [
            'signing_method' => 'application', 'launch_application' => 1,
            'application_address' => SigningSession::APPLICATION_SECURE,
        ])->assertSessionHas('pdf-signing-link');
        $link = $response->getSession()->get('pdf-signing-link');
        $response->assertRedirect(SigningSession::APPLICATION_SECURE.'?link='.rawurlencode($link));
        $this->getJson($link)->assertOk()->assertJsonPath('step', SigningSession::PDF);
        $this->post("/admin/correspondence/{$letter->id}/pdf/signing-link", [
            'signing_method' => 'external', 'launch_application' => 1,
        ])->assertSessionHas('pdf-signing-external')->assertRedirect();
    }

    public function test_pdf_signing_is_required_and_unsigned_or_unrelated_uploads_are_rejected(): void
    {
        $owner = $this->owner();
        $letter = $this->numbered($owner);
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package")->assertSessionHas('danger-status');
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/pdf/signing-link")->assertSessionHasErrors('signing_method');
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/pdf/signing-link", ['signing_method' => 'external'])->assertSessionHas('pdf-signing-external');
        $prepared = $this->get("/admin/correspondence/{$letter->id}/pdf")->assertOk()->getContent();
        $this->post("/admin/correspondence/{$letter->id}/signed-pdf", ['signed_pdf' => UploadedFile::fake()->createWithContent('unsigned.pdf', $prepared)])->assertSessionHasErrors('signed_pdf');
        $this->assertFalse($letter->hasSignedPdf());
        $viewer = $this->userWith(['correspondence.view']);
        $this->actingAs($viewer)->post("/admin/correspondence/{$letter->id}/pdf/signing-link", ['signing_method' => 'application'])->assertForbidden();
        $this->actingAs($viewer)->post("/admin/correspondence/{$letter->id}/signed-pdf", ['signed_pdf' => UploadedFile::fake()->createWithContent('unsigned.pdf', $prepared)])->assertForbidden();
        $signed = $this->signPdf($letter, $owner);
        $this->assertNotSame($prepared, $signed);
        $this->assertSame($signed, $this->get("/admin/correspondence/{$letter->id}/pdf")->getContent());
        $this->post("/admin/correspondence/{$letter->id}/pdf/signing-link", ['signing_method' => 'application'])->assertForbidden();
        $this->post("/admin/correspondence/{$letter->id}/signed-pdf", ['signed_pdf' => UploadedFile::fake()->createWithContent('signed.pdf', $signed)])->assertForbidden();
    }

    public function test_an_externally_signed_pdf_is_checked_and_packaged_without_changes(): void
    {
        $owner = $this->owner();
        $letter = $this->numbered($owner);
        $source = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/pdf")->assertOk()->getContent();
        $prepared = $this->externalSigningDocument($source);
        $signed = \Modules\Correspondence\Support\PdfSigningDocument::complete($prepared, $this->cmsSignature(\Modules\Correspondence\Support\PdfSigningDocument::bytes($prepared)));
        $tampered = str_replace('ExternalSignature', 'ModifiedSignature', $signed);
        $this->post("/admin/correspondence/{$letter->id}/signed-pdf", ['signed_pdf' => UploadedFile::fake()->createWithContent('tampered.pdf', $tampered)])->assertSessionHasErrors('signed_pdf');
        $other = $this->numbered($owner);
        $this->post("/admin/correspondence/{$other->id}/signed-pdf", ['signed_pdf' => UploadedFile::fake()->createWithContent('unrelated.pdf', $signed)])->assertSessionHasErrors('signed_pdf');
        // Appending bytes after the signed revision invalidates full-document coverage.
        $this->post("/admin/correspondence/{$letter->id}/signed-pdf", ['signed_pdf' => UploadedFile::fake()->createWithContent('tampered.pdf', $signed."\nchanged")])->assertSessionHasErrors('signed_pdf');
        $this->assertFalse($letter->hasSignedPdf());
        $this->post("/admin/correspondence/{$letter->id}/signed-pdf", ['signed_pdf' => UploadedFile::fake()->createWithContent('signed.pdf', $signed)])->assertSessionHasNoErrors()->assertSessionHas('success-status');
        $this->assertSame($signed, $this->get("/admin/correspondence/{$letter->id}/pdf")->getContent());
        $this->post("/admin/correspondence/{$letter->id}/package", ['generation' => '1'])->assertSessionHas('success-status');
        $package = Paket::icerikten(Storage::disk('local')->get($letter->fresh()->package_path));
        $this->assertSame($signed, $package->ustYazi()->icerik);
    }

    public function test_the_package_is_built_signed_and_sealed(): void
    {
        $owner = $this->owner();
        $draft = $this->letter($owner);

        $this->actingAs($owner)->post("/admin/correspondence/{$draft->id}/package", ['generation' => '2'])->assertSessionHas('danger-status');
        $this->assertNull($draft->fresh()->package_path);

        $this->actingAs($owner)->post("/admin/correspondence/{$draft->id}/attachments", ['name' => 'Rapor', 'file' => UploadedFile::fake()->createWithContent('Çalışma Raporu.pdf', '%PDF-1.4 rapor')]);
        $this->actingAs($owner)->post("/admin/correspondence/{$draft->id}/attachments", ['name' => 'İki adet CD']);
        $this->actingAs($owner)->post("/admin/correspondence/{$draft->id}/approve");
        $letter = $draft->fresh();
        $this->signPdf($letter, $owner);

        // The organization's identifier is needed first.
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package", ['generation' => '2'])->assertSessionHas('danger-status');
        $this->actingAs($owner)->put('/admin/correspondence/settings', ['number_format' => '{yil}', 'start_number' => 1])->assertSessionHasErrors('number_format');
        $this->actingAs($owner)->put('/admin/correspondence/settings', ['number_format' => '{yil}/{sira}', 'start_number' => 1])->assertSessionHasNoErrors();
        $this->actingAs($owner)->put('/admin/settings/organization', ['name' => 'Örnek Derneği', 'mersis_no' => '0123456789012345'])->assertSessionHasNoErrors();
        $this->actingAs($owner)->get('/admin/correspondence/settings')->assertOk()->assertSee('0123456789012345');

        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package", ['generation' => '2'])->assertSessionHas('success-status');
        $letter->refresh();
        $this->assertSame("correspondence/{$letter->id}/{$letter->document_id}.eyp", $letter->package_path);
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package", ['generation' => '2'])->assertForbidden();

        $package = Paket::icerikten(Storage::disk('local')->get($letter->package_path));
        $metadata = $package->ustveri();

        $this->assertSame(PaketAsamasi::ImzaBekliyor, $package->asama());
        $this->assertSame($letter->document_id, $package->belgeId());
        $this->assertSame('Şenlik daveti', $metadata->konu);
        $this->assertInstanceOf(TuzelSahis::class, $metadata->olusturan);
        $this->assertSame('0123456789012345', $metadata->olusturan->kimlik());
        $this->assertSame(['Adalet Bakanlığı', 'Ali Rıza Kaya'], array_map(fn ($dagitim) => $dagitim->taraf->gorunenAd(), $metadata->dagitimlar));
        $this->assertSame(['GRG', 'BLG'], array_map(fn ($dagitim) => $dagitim->dagitimTuru->value, $metadata->dagitimlar));
        $this->assertSame(['a', 'b'], array_map(fn ($ilgi) => $ilgi->etiket, $metadata->ilgiler));
        $this->assertSame(['DED', 'FZK'], array_map(fn ($ek) => $ek->tur->value, $metadata->ekler));
        $this->assertSame('010.06', $metadata->sdpBilgisi->anaSdp->kod);
        $this->assertStringEndsWith('/belge-dogrula', $metadata->dogrulamaAdresi);
        $this->assertStringStartsWith('%PDF-', $package->ustYazi()->icerik);
        $this->assertSame('%PDF-1.4 rapor', array_values($package->ekDosyalari())[0]->icerik);

        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertOk()->assertSee('PaketOzeti.xml')->assertDontSee('NihaiOzet.xml');
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/package/final-digest")->assertNotFound();
        $digest = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/package/digest")->assertOk()->assertDownload('PaketOzeti.xml')->getContent();
        $this->assertSame($package->paketOzeti(), $digest);

        // The seal cannot come before the signature.
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/seal", ['file' => UploadedFile::fake()->createWithContent('muhur.imz', "\x30\x82muhur")])->assertSessionHas('danger-status');

        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signature", ['file' => UploadedFile::fake()->createWithContent('imza.imz', "\x30\x82".$digest)])->assertSessionHas('success-status');
        $finalDigest = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/package/final-digest")->assertOk()->getContent();
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertSee('NihaiOzet.xml');

        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/seal", ['file' => UploadedFile::fake()->createWithContent('muhur.imz', "\x30\x82".$finalDigest)])->assertSessionHas('success-status');

        $content = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/package")->assertOk()->assertDownload($letter->document_id.'.eyp')->getContent();
        $package = Paket::icerikten($content);

        $this->assertSame(PaketAsamasi::Tamamlandi, $package->asama());
        $this->assertSame([], array_map('strval', $package->dogrula()->bulgular));
        $this->assertSame($letter->document_no, $package->nihaiUstveri()->belgeNo);
        $this->assertSame('Ayşe Yılmaz', $package->nihaiUstveri()->imzalar[0]->imzalayan->gorunenAd());
        $this->assertSame('Yönetim Kurulu Başkanı', $package->nihaiUstveri()->imzalar[0]->imzalayan->gorev);

        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertSee('Paket tamamlandı: yapısı ve özet değerleri geçerli')->assertDontSee('Paketi sil');
        $this->actingAs($owner)->delete("/admin/correspondence/{$letter->id}/package")->assertForbidden();
        Storage::disk('local')->assertExists($letter->package_path);
    }

    public function test_the_signer_of_the_package_comes_from_the_certificate(): void
    {
        $owner = $this->owner();
        $letter = $this->numbered($owner);
        $this->signPdf($letter, $owner);
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package")->assertSessionHas('success-status');
        $digest = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/package/digest")->assertOk()->getContent();

        $signature = $this->cmsSignature($digest, ['commonName' => 'BAHRİ MERİÇ CANLI', 'serialNumber' => '15757142316']);
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signature", ['file' => UploadedFile::fake()->createWithContent('imza.imz', $signature)])->assertSessionHas('success-status');

        $signers = Paket::icerikten(Storage::disk('local')->get($letter->refresh()->package_path))->nihaiUstveri()->imzalar;
        $this->assertCount(1, $signers);
        $this->assertSame('BAHRİ MERİÇ', $signers[0]->imzalayan->kisi->ilkAdi);
        $this->assertSame('CANLI', $signers[0]->imzalayan->kisi->soyadi);
        $this->assertSame('15757142316', $signers[0]->imzalayan->tckn);
    }

    public function test_a_package_of_the_layout_before_2_0_is_complete_with_the_signature(): void
    {
        $owner = $this->owner();
        $letter = $this->numbered($owner);
        $this->signPdf($letter, $owner);

        // The old layout is offered first and needs no MERSİS number: the first signer is named as the creator.
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertOk()->assertSee('2.0 öncesi: yalnız e-imza')->assertSee('2.x: e-imza ve e-mühür');
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package", ['generation' => 'x'])->assertSessionHasErrors('generation');
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package")->assertSessionHas('success-status');
        $letter->refresh();

        $package = Paket::icerikten(Storage::disk('local')->get($letter->package_path));
        $this->assertSame(Surum::V1, $package->surum());
        $this->assertSame('1.0', $package->ozellikler()->surum);
        $this->assertSame('Ayşe Yılmaz', $package->ustveri()->olusturan->gorunenAd());
        $this->assertSame($letter->document_no, $package->nihaiUstveri()->belgeNo);
        $this->assertSame($letter->document_date->toDateString(), $package->nihaiUstveri()->tarih->format('Y-m-d'));
        $this->assertSame(['Adalet Bakanlığı', 'Ali Rıza Kaya'], array_map(fn ($hedef) => $hedef->gorunenAd(), $package->hedefler()));

        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertSee('2.0 öncesi')->assertSee('İmza uygulamasıyla imzala')->assertDontSee('İmza uygulamasıyla mühürle');

        $link = $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signing-link")->getSession()->get('signing-link');
        auth()->logout();
        $session = $this->getJson($link)->assertOk()->assertJsonPath('step', 'signature')->assertJsonPath('profile', 'BES')->json();
        $this->postJson($link, ['signature' => base64_encode("\x30\x82".base64_decode($session['content']))])->assertOk()->assertJsonPath('complete', true);

        $package = Paket::icerikten(Storage::disk('local')->get($letter->package_path));
        $this->assertSame(PaketAsamasi::Tamamlandi, $package->asama());
        $this->assertSame([], array_map('strval', $package->dogrula()->bulgular));
        $this->assertNull($package->muhur());

        // Complete without a seal; one may still be added.
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertSee('Paket tamamlandı')->assertSee('isteğe bağlı')->assertSee('İmza uygulamasıyla mühürle')->assertSee('.eyp indir');

        $link = $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signing-link")->getSession()->get('signing-link');
        auth()->logout();
        $session = $this->getJson($link)->assertOk()->assertJsonPath('step', 'seal')->assertJsonPath('profile', 'A')->assertJsonPath('filename', 'NihaiOzet.xml')->json();
        $this->postJson($link, ['signature' => base64_encode("\x30\x82".base64_decode($session['content']))])->assertOk();

        $package = Paket::icerikten(Storage::disk('local')->get($letter->package_path));
        $this->assertNotNull($package->muhur());
        $this->assertTrue($package->dogrula()->gecerli());
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signing-link")->assertForbidden();
        $this->actingAs($owner)->delete("/admin/correspondence/{$letter->id}/package")->assertForbidden();

        // With the time-stamp service set, the signature of the old layout is asked time-stamped.
        app(Organization::class)->save(['name' => 'Örnek Derneği', 'correspondence_tsa_url' => 'http://zd.example.org', 'correspondence_package_generation' => '2', 'mersis_no' => '0123456789012345']);
        $other = $this->numbered($owner);
        $this->signPdf($other, $owner);
        $this->actingAs($owner)->get("/admin/correspondence/{$other->id}")->assertSee('value="2" checked', false);
        $this->actingAs($owner)->post("/admin/correspondence/{$other->id}/package", ['generation' => '1']);
        $link = $this->actingAs($owner)->post("/admin/correspondence/{$other->id}/package/signing-link")->getSession()->get('signing-link');
        $this->getJson($link)->assertJsonPath('profile', 'T');
        $this->assertSame('Örnek Derneği', Paket::icerikten(Storage::disk('local')->get($other->fresh()->package_path))->ustveri()->olusturan->gorunenAd());
    }

    public function test_a_finished_letter_is_uploaded_as_a_pdf_with_its_own_number(): void
    {
        $owner = $this->owner();
        $document = new \Dompdf\Dompdf;
        $document->loadHtml('<p>Uploaded letter</p>');
        $document->render();
        $pdf = $document->output();
        $form = fn (array $overrides = []) => array_replace($this->form(['body' => null]), [
            'source' => 'pdf',
            'pdf' => UploadedFile::fake()->createWithContent('06-061-115-2026-22 - Anadolu Üniversitesi (imzalı).pdf', $pdf),
            'document_no' => '06-061-115-2026-22',
            'document_date' => '2026-10-03',
        ], $overrides);

        $this->actingAs($owner)->get('/admin/correspondence')->assertSee('Hazır PDF ile yazı');
        $this->actingAs($owner)->get('/admin/correspondence/create?source=pdf')->assertOk()->assertSee('PDF dosyası')->assertSee('name="document_no"', false)->assertDontSee('name="body"', false);
        $this->actingAs($owner)->get('/admin/correspondence/create')->assertOk()->assertDontSee('name="document_no"', false);

        $this->actingAs($owner)->post('/admin/correspondence', $form(['pdf' => null, 'document_no' => '', 'document_date' => '']))->assertSessionHasErrors(['pdf', 'document_no', 'document_date']);
        $this->actingAs($owner)->post('/admin/correspondence', $form(['pdf' => UploadedFile::fake()->createWithContent('yazi.docx', 'x')]))->assertSessionHasErrors('pdf');
        $this->actingAs($owner)->post('/admin/correspondence', $form())->assertSessionHasNoErrors();

        $letter = Letter::latest('id')->first();
        $this->assertTrue($letter->isPdf());
        $this->assertSame(Letter::DRAFT, $letter->status);
        $this->assertSame('06-061-115-2026-22', $letter->document_no);
        $this->assertSame('2026-10-03', $letter->document_date->toDateString());
        $this->assertNull($letter->body);
        $this->assertStringStartsWith("correspondence/{$letter->id}/", $letter->pdf_path);
        $this->assertSame($pdf, Storage::disk('local')->get($letter->pdf_path));

        // The same number cannot be recorded twice.
        $this->actingAs($owner)->post('/admin/correspondence', $form())->assertSessionHasErrors('document_no');

        // The PDF is served as it was uploaded; the details can change while it is a draft.
        $this->assertSame($pdf, $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/pdf")->assertOk()->getContent());
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertOk()->assertSee('Hazır PDF')->assertSee('06-061-115-2026-22');
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/edit")->assertOk()->assertSee('Yüklü:');
        $this->actingAs($owner)->put("/admin/correspondence/{$letter->id}", $form(['pdf' => null, 'subject' => 'Kış Kampı']))->assertSessionHasNoErrors();
        $this->assertSame('Kış Kampı', $letter->fresh()->subject);
        $this->assertSame($pdf, Storage::disk('local')->get($letter->fresh()->pdf_path));

        // Approval keeps the number on the letter and leaves the sequence alone.
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/approve")->assertSessionHasNoErrors();
        $letter->refresh();
        $this->assertSame(Letter::NUMBERED, $letter->status);
        $this->assertSame('06-061-115-2026-22', $letter->document_no);
        $this->assertNull($letter->number);
        $this->assertNull(LetterSequence::find(now()->year));
        $this->actingAs($owner)->put("/admin/correspondence/{$letter->id}", $form())->assertForbidden();

        // Verification is added before signing. The package preserves the signed bytes.
        $prepared = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/pdf")->assertOk()->getContent();
        $this->assertNotSame($pdf, $prepared);
        $signed = $this->signPdf($letter, $owner);
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package")->assertSessionHas('success-status');
        $package = Paket::icerikten(Storage::disk('local')->get($letter->fresh()->package_path));
        $this->assertSame($signed, $package->ustYazi()->icerik);
        $this->assertSame('06-061-115-2026-22', $package->nihaiUstveri()->belgeNo);
        $this->assertSame('2026-10-03', $package->nihaiUstveri()->tarih->format('Y-m-d'));

        auth()->logout();
        $this->get('/belge-dogrula?kod='.$letter->document_id)->assertOk()->assertSee('06-061-115-2026-22');
    }

    public function test_an_unfinished_package_can_be_discarded_and_needs_recipient_identifiers(): void
    {
        $owner = $this->owner();
        app(Organization::class)->save(['mersis_no' => '0123456789012345']);

        $letter = $this->numbered($owner, ['recipients' => [['kind' => 'institution', 'name' => 'Numarasız Kurum', 'delivery' => 'GRG']]]);
        $this->signPdf($letter, $owner);
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package", ['generation' => '2'])->assertSessionHas('danger-status', 'Paket için alıcının DETSİS no değeri gerekir: Numarasız Kurum');

        $letter = $this->numbered($owner);
        $this->signPdf($letter, $owner);
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package", ['generation' => '2'])->assertSessionHas('success-status');
        $path = $letter->fresh()->package_path;

        $this->actingAs($this->userWith(['correspondence.view']))->delete("/admin/correspondence/{$letter->id}/package")->assertForbidden();
        $this->actingAs($owner)->delete("/admin/correspondence/{$letter->id}/package")->assertSessionHas('success-status');
        Storage::disk('local')->assertMissing($path);
        $this->assertNull($letter->fresh()->package_path);
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/package")->assertNotFound();
    }

    public function test_the_signing_application_signs_and_seals_through_single_use_links(): void
    {
        $owner = $this->owner();
        app(Organization::class)->save(['mersis_no' => '0123456789012345']);
        $letter = $this->numbered($owner);
        $this->signPdf($letter, $owner);

        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signing-link")->assertForbidden();
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package", ['generation' => '2']);
        $this->actingAs($this->userWith(['correspondence.view']))->post("/admin/correspondence/{$letter->id}/package/signing-link")->assertForbidden();

        $this->actingAs($owner)->put('/admin/correspondence/settings', ['number_format' => '{yil}/{sira}', 'start_number' => 1, 'tsa_url' => 'http://zd.example.org', 'tsa_user' => '1234', 'tsa_password' => 'gizli'])->assertSessionHasNoErrors();
        $this->assertNotSame('gizli', app(Organization::class)->get('correspondence_tsa_password'));
        $this->actingAs($owner)->get('/admin/correspondence/settings')->assertOk()->assertSee('http://zd.example.org')->assertDontSee('gizli');
        // An empty password keeps the stored one.
        $this->actingAs($owner)->put('/admin/correspondence/settings', ['number_format' => '{yil}/{sira}', 'start_number' => 1, 'tsa_url' => 'http://zd.example.org', 'tsa_user' => '1234'])->assertSessionHasNoErrors();

        $first = $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signing-link")->assertSessionHas('signing-link')->getSession()->get('signing-link');
        $link = $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signing-link")->getSession()->get('signing-link');
        $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}")->assertOk()->assertSee('İmza uygulamasıyla imzala');
        $this->actingAs($owner)->withSession(['signing-link' => $link])->get("/admin/correspondence/{$letter->id}")
            ->assertSee('http://127.0.0.1:51515/?link='.rawurlencode($link), false)->assertSee('İmza uygulamasında aç')
            ->assertSee('https:\/\/127.0.0.1:51516\/', false);
        auth()->logout();

        // A newer link replaces the older one.
        $this->getJson($first)->assertNotFound()->assertJsonPath('message', 'İmza bağlantısı geçersiz ya da süresi dolmuş.');
        $this->getJson(preg_replace('/.$/', 'x', $link))->assertNotFound();

        $session = $this->getJson($link)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJsonPath('step', 'signature')->assertJsonPath('profile', 'XL')->assertJsonPath('filename', 'PaketOzeti.xml')
            ->assertJsonPath('document_no', $letter->document_no)->assertJsonPath('subject', 'Şenlik daveti')
            ->assertJsonPath('timestamp', ['url' => 'http://zd.example.org', 'user' => '1234', 'password' => 'gizli'])->json();
        $digest = base64_decode($session['content']);
        $this->assertStringContainsString('<PaketOzeti', $digest);

        $this->postJson($link, ['signature' => '***'])->assertStatus(422);
        $this->postJson($link, [])->assertStatus(422);
        $this->postJson($link, ['signature' => base64_encode("\x30\x82".$digest)])->assertOk()->assertJsonPath('complete', false);

        // The link is spent; the seal needs a new one.
        $this->getJson($link)->assertNotFound();
        $this->postJson($link, ['signature' => base64_encode('x')])->assertNotFound();

        $link = $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signing-link")->getSession()->get('signing-link');
        auth()->logout();

        $session = $this->getJson($link)->assertOk()->assertJsonPath('step', 'seal')->assertJsonPath('profile', 'A')->assertJsonPath('filename', 'NihaiOzet.xml')->json();
        $this->travel(SigningSession::LIFETIME + 1)->minutes();
        $this->getJson($link)->assertNotFound();
        $this->travelBack();
        $this->postJson($link, ['signature' => base64_encode("\x30\x82".base64_decode($session['content']))])->assertOk()->assertJsonPath('complete', true);

        $package = Paket::icerikten(Storage::disk('local')->get($letter->fresh()->package_path));
        $this->assertSame(PaketAsamasi::Tamamlandi, $package->asama());
        $this->assertTrue($package->dogrula()->gecerli());
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signing-link")->assertForbidden();
    }

    public function test_wordpress_verification_api_only_returns_public_numbered_letter_details(): void
    {
        $owner = $this->owner();
        $draft = $this->letter($owner);
        $letter = $this->numbered($owner);
        auth()->logout();
        $this->getJson('/api/site/documents/verify?kod='.$letter->document_id)->assertUnauthorized();
        app(Organization::class)->save(['frame_ancestors' => 'https://www.ornek.org.tr']);
        $key = app(\App\Support\SiteApi::class)->generateKey();
        $this->withHeaders(['X-Api-Key' => $key, 'X-Site-Url' => 'https://bad.example'])->getJson('/api/site/documents/verify?kod='.$letter->document_id)->assertForbidden();
        $this->withHeaders(['X-Site-Url' => 'https://www.ornek.org.tr']);
        $this->getJson('/api/site/config')->assertOk()->assertJsonPath('correspondence.open', true);
        $this->getJson('/api/site/documents/verify?kod='.strtolower($letter->document_id))->assertOk()
            ->assertExactJson(['document_no' => $letter->document_no, 'document_date' => $letter->document_date->format('d.m.Y'), 'subject' => $letter->subject, 'status' => Letter::NUMBERED, 'preview_url' => route('correspondence.verify.pdf', $letter->document_id), 'download_url' => route('correspondence.verify.pdf', ['code' => $letter->document_id, 'download' => 1]), 'original_url' => route('correspondence.verify.pdf', ['code' => $letter->document_id, 'original' => 1]), 'attachments' => []])
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->getJson('/api/site/documents/verify?kod='.$draft->document_id)->assertNotFound();
        $this->getJson('/api/site/documents/verify?'.http_build_query(['kod' => $letter->document_id, 'sayi' => ' '.strtolower($letter->document_no).' ']))->assertOk();
        $this->getJson('/api/site/documents/verify?'.http_build_query(['kod' => $letter->document_id, 'sayi' => '1999/1']))->assertNotFound()->assertJsonPath('message', 'Bu doğrulama kodu ve sayıyla kayıtlı bir belge bulunamadı.');
        $this->getJson('/api/site/documents/verify?kod=not-a-code')->assertUnprocessable();
        $this->getJson('/api/site/documents/verify?kod[]=bad')->assertUnprocessable();
        $letter->forceFill(['status' => Letter::CANCELLED, 'cancel_reason' => 'Private reason'])->save();
        $this->getJson('/api/site/documents/verify?kod='.$letter->document_id)->assertOk()->assertJsonPath('status', Letter::CANCELLED)->assertJsonMissingPath('cancel_reason');
        $letter->delete();
        $this->getJson('/api/site/documents/verify?kod='.$letter->document_id)->assertNotFound();
    }

    public function test_with_turnstile_keys_wordpress_verification_needs_the_human_check(): void
    {
        $letter = $this->numbered($this->owner());
        auth()->logout();
        app(Organization::class)->save(['frame_ancestors' => 'https://www.ornek.org.tr']);
        $key = app(\App\Support\SiteApi::class)->generateKey();
        $this->withHeaders(['X-Api-Key' => $key, 'X-Site-Url' => 'https://www.ornek.org.tr']);
        $url = '/api/site/documents/verify?kod='.$letter->document_id;

        $this->getJson('/api/site/config')->assertJsonPath('correspondence.turnstile_site_key', null);

        config(['services.turnstile.site_key' => 'site-key', 'services.turnstile.secret_key' => 'secret']);
        Http::fake(['challenges.cloudflare.com/*' => fn ($request) => Http::response(['success' => $request['response'] === 'good'])]);

        $this->getJson('/api/site/config')->assertJsonPath('correspondence.turnstile_site_key', 'site-key');
        $this->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('turnstile');
        $this->getJson($url.'&turnstile=bad')->assertUnprocessable()->assertJsonValidationErrors('turnstile');
        $this->getJson($url.'&turnstile[]=good')->assertUnprocessable()->assertJsonValidationErrors('turnstile');
        $this->getJson($url.'&turnstile=good')->assertOk()->assertJsonPath('document_no', $letter->document_no);
    }

    public function test_wordpress_verification_address_is_configurable_for_new_documents(): void
    {
        $owner = $this->owner();
        $url = 'https://www.ornek.org.tr/belge-dogrula/';
        $settings = app(\Modules\Correspondence\Support\CorrespondenceSettings::class);
        $this->assertSame(route('correspondence.verify'), $settings->verificationUrl());
        $form = ['number_format' => '{yil}/{sira}', 'start_number' => 1, 'verification_url' => $url];
        $this->actingAs($owner)->put('/admin/correspondence/settings', $form)->assertSessionHasNoErrors();
        $letter = $this->numbered($owner);
        $this->assertSame($url.'?kod='.$letter->document_id, $settings->verificationUrl($letter->document_id));
        $prepared = $this->get("/admin/correspondence/{$letter->id}/pdf")->getContent();
        $this->assertStringContainsString($url.'?kod='.$letter->document_id, $prepared);
        $this->put('/admin/correspondence/settings', array_replace($form, ['verification_url' => 'javascript:bad']))->assertSessionHasErrors('verification_url');
        $this->put('/admin/correspondence/settings', array_replace($form, ['verification_url' => null]))->assertSessionHasNoErrors();
        $this->assertSame(route('correspondence.verify'), $settings->verificationUrl());
        $this->assertSame($prepared, $this->get("/admin/correspondence/{$letter->id}/pdf")->getContent());
    }

    public function test_initial_upload_keeps_signed_attachments_separate_and_unchanged(): void
    {
        $owner = $this->owner();
        $pdf = new \Dompdf\Dompdf;
        $pdf->loadHtml('<p>Unsigned main letter</p>');
        $pdf->render();
        $main = $pdf->output();
        $template = \Modules\Correspondence\Support\PdfSigningDocument::prepare($main);
        $signedAttachment = \Modules\Correspondence\Support\PdfSigningDocument::complete($template, $this->cmsSignature(\Modules\Correspondence\Support\PdfSigningDocument::bytes($template)));
        $this->actingAs($owner)->get('/admin/correspondence/create?source=pdf')->assertOk()->assertSee('Asıl yazı PDF dosyası')->assertSee('Ek dosyalar');
        $this->post('/admin/correspondence', $this->form([
            'source' => 'pdf', 'body' => null, 'document_no' => '2026/23', 'document_date' => '2026-10-23',
            'pdf' => UploadedFile::fake()->createWithContent('main.pdf', $main),
            'attachment_files' => [UploadedFile::fake()->createWithContent('signed-attachment.pdf', $signedAttachment)],
        ]))->assertSessionHasNoErrors();
        $letter = Letter::latest('id')->firstOrFail();
        $this->assertSame($main, Storage::disk('local')->get($letter->pdf_path));
        $attachment = $letter->attachments()->sole();
        $this->assertSame($signedAttachment, Storage::disk('local')->get($attachment->path));
        $this->post("/admin/correspondence/{$letter->id}/approve")->assertSessionHasNoErrors();
        $this->signPdf($letter->fresh(), $owner);
        $this->post("/admin/correspondence/{$letter->id}/package", ['generation' => '1'])->assertSessionHas('success-status');
        $package = Paket::icerikten(Storage::disk('local')->get($letter->fresh()->package_path));
        $this->assertSame($signedAttachment, array_values($package->ekDosyalari())[0]->icerik);
    }

    public function test_standard_compressed_pdfs_are_supported_without_changing_the_original(): void
    {
        $qpdf = (new \Symfony\Component\Process\ExecutableFinder)->find('qpdf');
        if ($qpdf === null) {
            $this->markTestSkipped('qpdf is required for compressed PDF compatibility.');
        }
        $pdf = new \Dompdf\Dompdf;
        $pdf->loadHtml('<p>Standard compressed PDF</p>');
        $pdf->render();
        $input = tempnam(sys_get_temp_dir(), 'pdf-test-');
        $output = tempnam(sys_get_temp_dir(), 'pdf-test-');
        try {
            file_put_contents($input, $pdf->output());
            (new \Symfony\Component\Process\Process([$qpdf, '--object-streams=generate', $input, $output]))->mustRun();
            $compressed = file_get_contents($output);
            $prepared = app(\Modules\Correspondence\Support\SourcePdf::class)->prepare($compressed);
            $reader = new \setasign\Fpdi\Fpdi;
            $this->assertSame(1, $reader->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($prepared)));
            $owner = $this->owner();
            $this->actingAs($owner)->post('/admin/correspondence', $this->form([
                'source' => 'pdf', 'body' => null, 'document_no' => '2026/23', 'document_date' => '2026-10-23',
                'pdf' => UploadedFile::fake()->createWithContent('standard.pdf', $compressed),
            ]))->assertSessionHasNoErrors();
            $letter = Letter::latest('id')->firstOrFail();
            $this->assertSame($compressed, Storage::disk('local')->get($letter->pdf_path));
            $this->get("/admin/correspondence/{$letter->id}/pdf")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        } finally {
            unlink($input);
            unlink($output);
        }
    }

    public function test_pdf_views_include_attachments_but_preserve_signed_originals_and_access_boundaries(): void
    {
        if ((new \Symfony\Component\Process\ExecutableFinder)->find('qpdf') === null) {
            $this->markTestSkipped('qpdf is required for combined PDF views.');
        }
        $owner = $this->owner();
        $letter = $this->letter($owner);
        $pdf = new \Dompdf\Dompdf;
        $pdf->loadHtml('<p>Attachment page with its own signature</p>');
        $pdf->render();
        $template = \Modules\Correspondence\Support\PdfSigningDocument::prepare($pdf->output());
        $attachmentBytes = \Modules\Correspondence\Support\PdfSigningDocument::complete($template, $this->cmsSignature(\Modules\Correspondence\Support\PdfSigningDocument::bytes($template)));
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/attachments", ['name' => 'Signed attachment', 'file' => UploadedFile::fake()->createWithContent('attachment.pdf', $attachmentBytes)])->assertSessionHasNoErrors();
        $this->post("/admin/correspondence/{$letter->id}/attachments", ['name' => 'Notes', 'file' => UploadedFile::fake()->createWithContent('notes.txt', 'Original notes')])->assertSessionHasNoErrors();
        $attachment = $letter->attachments()->firstOrFail();
        $this->post("/admin/correspondence/{$letter->id}/approve")->assertSessionHasNoErrors();
        $letter->refresh();
        $mainBytes = $this->signPdf($letter, $owner);
        $adminPdf = $this->get("/admin/correspondence/{$letter->id}/pdf")->assertOk()->getContent();
        $reader = new \setasign\Fpdi\Fpdi;
        $this->assertSame(2, $reader->setSourceFile(\setasign\Fpdi\PdfParser\StreamReader::createByString($adminPdf)));
        $this->assertSame($mainBytes, $this->get("/admin/correspondence/{$letter->id}/pdf?original=1")->assertOk()->getContent());
        $draft = $this->letter($owner);
        auth()->logout();
        $publicPdf = route('correspondence.verify.pdf', $letter->document_id);
        $download = route('correspondence.verify.attachment', ['code' => $letter->document_id, 'attachment' => $attachment->id]);
        $this->get('/belge-dogrula?kod='.$letter->document_id)->assertOk()->assertSee('Signed attachment')->assertSee('Birleşik PDF’yi aç')->assertSee('Birleşik PDF’yi indir')->assertSee($publicPdf, false);
        $this->get($publicPdf)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->get($publicPdf.'?download=1')->assertOk()->assertDownload(app(\Modules\Correspondence\Support\LetterPdf::class)->filename($letter));
        $this->assertSame($mainBytes, $this->get($publicPdf.'?original=1')->assertOk()->getContent());
        $this->assertSame($attachmentBytes, $this->get($download)->assertOk()->assertDownload('attachment.pdf')->streamedContent());
        $this->get(route('correspondence.verify.pdf', $draft->document_id))->assertNotFound();
        $this->get(route('correspondence.verify.attachment', ['code' => $draft->document_id, 'attachment' => $attachment->id]))->assertNotFound();
        $this->get(route('correspondence.verify.attachment', ['code' => $letter->document_id, 'attachment' => 999999]))->assertNotFound();
        $this->assertSame($mainBytes, Storage::disk('local')->get($letter->signedPdfPath()));
        $this->assertSame($attachmentBytes, Storage::disk('local')->get($attachment->path));
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package", ['generation' => '1'])->assertSessionHas('success-status');
        $package = Paket::icerikten(Storage::disk('local')->get($letter->fresh()->package_path));
        $this->assertSame($mainBytes, $package->ustYazi()->icerik);
        $this->assertSame($attachmentBytes, array_values($package->ekDosyalari())[0]->icerik);
    }

    public function test_anyone_can_verify_a_letter_by_its_code(): void
    {
        $owner = $this->owner();
        $draft = $this->letter($owner);
        $letter = $this->numbered($owner, ['subject' => 'Doğrulanacak yazı']);
        auth()->logout();

        $this->get('/belge-dogrula')->assertOk()->assertSee('Belge doğrulama kodu')->assertSee('Yazının sayısı');
        $this->get('/belge-dogrula?kod='.strtolower($letter->document_id))->assertOk()->assertSee('Belge doğrulandı')->assertSee($letter->document_no)->assertSee('Doğrulanacak yazı')->assertDontSee('Şenliğimize bekleriz');

        // Typed by hand, the number must be the letter's; the QR link carries the code alone.
        $this->get('/belge-dogrula?'.http_build_query(['kod' => $letter->document_id, 'sayi' => $letter->document_no]))->assertOk()->assertSee('Belge doğrulandı');
        $this->get('/belge-dogrula?'.http_build_query(['kod' => $letter->document_id, 'sayi' => '1999/1']))->assertNotFound()->assertSee('Bu doğrulama kodu ve sayıyla bir belge bulunamadı')->assertDontSee('Doğrulanacak yazı');
        $this->get('/belge-dogrula?kod='.$draft->document_id)->assertNotFound()->assertSee('bulunamadı');
        $this->get('/belge-dogrula?kod=yok')->assertNotFound();

        $letter->forceFill(['status' => Letter::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => 'Neden'])->save();
        $this->get('/belge-dogrula?kod='.$letter->document_id)->assertOk()->assertSee('İptal edilmiş belge');
    }

    public function test_an_incoming_pdf_is_registered_with_a_number_of_its_own(): void
    {
        $owner = $this->owner();
        $outgoing = $this->numbered($owner);
        $year = now()->year;
        $pdf = fn (string $name = 'yazi.pdf') => UploadedFile::fake()->createWithContent($name, '%PDF-1.4 gelen yazı');
        $form = fn (array $overrides = []) => array_replace([
            'file' => $pdf(),
            'received_on' => today()->toDateString(),
            'channel' => 'email',
            'subject' => 'Mustafa Akgül Özgür Yazılım Kış Kampı Düzenlenmesi',
            'sender_kind' => 'institution',
            'sender_name' => 'Anadolu Üniversitesi Rektörlüğü',
            'document_no' => 'E-63784619-719-1096667',
            'document_date' => '2026-10-21',
            'letter_id' => $outgoing->id,
        ], $overrides);

        // A PDF says nothing about itself: subject and sender are written in the form.
        $this->actingAs($owner)->post('/admin/correspondence/incoming', $form(['subject' => '', 'sender_name' => '']))->assertSessionHasErrors(['subject', 'sender_name']);
        $this->actingAs($owner)->post('/admin/correspondence/incoming', $form(['file' => UploadedFile::fake()->createWithContent('yazi.pdf', 'not a pdf')]))->assertSessionHasErrors('file');
        $this->actingAs($owner)->post('/admin/correspondence/incoming', $form(['received_on' => today()->addDay()->toDateString()]))->assertSessionHasErrors('received_on');

        $this->actingAs($owner)->post('/admin/correspondence/incoming', $form())->assertSessionHasNoErrors()->assertSessionHas('success-status');
        $first = IncomingDocument::latest('id')->first();
        $this->assertSame("G-{$year}/1", $first->registry_no);
        $this->assertSame(IncomingDocument::PDF, $first->source);
        $this->assertSame($outgoing->id, $first->letter_id);
        $this->assertSame('%PDF-1.4 gelen yazı', Storage::disk('local')->get($first->letterPath()));

        // The incoming sequence is apart from the outgoing one.
        $this->actingAs($owner)->post('/admin/correspondence/incoming', $form(['attachment_files' => [UploadedFile::fake()->createWithContent('Program.pdf', '%PDF-1.4 program')]]));
        $second = IncomingDocument::latest('id')->first();
        $this->assertSame("G-{$year}/2", $second->registry_no);
        $this->assertSame(['Program.pdf'], $second->attachments->pluck('name')->all());
        $this->assertSame(1, LetterSequence::find($year)->last_number);

        $this->actingAs($owner)->get('/admin/correspondence/incoming?q=Anadolu')->assertOk()->assertSee("G-{$year}/1")->assertSee("G-{$year}/2");
        $this->actingAs($owner)->get("/admin/correspondence/incoming/{$first->id}")->assertOk()->assertSee('E-63784619-719-1096667')->assertSee($outgoing->document_no);
        $this->actingAs($owner)->get("/admin/correspondence/incoming/{$first->id}/letter")->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->actingAs($owner)->get("/admin/correspondence/incoming/{$second->id}/attachments/{$second->attachments->first()->id}")->assertOk()->assertDownload('Program.pdf');
        $this->actingAs($owner)->get("/admin/correspondence/incoming/{$first->id}/attachments/{$second->attachments->first()->id}")->assertNotFound();

        // A cancelled number is not given again.
        $this->actingAs($owner)->post("/admin/correspondence/incoming/{$first->id}/cancel", ['cancel_reason' => 'Yanlış kayıt'])->assertSessionHas('success-status');
        $this->assertTrue($first->fresh()->isCancelled());
        $this->actingAs($owner)->post('/admin/correspondence/incoming', $form());
        $this->assertSame("G-{$year}/3", IncomingDocument::latest('id')->first()->registry_no);

        // The format is a setting.
        $this->actingAs($owner)->put('/admin/correspondence/settings', ['number_format' => '{yil}/{sira}', 'incoming_format' => 'GELEN', 'start_number' => 1])->assertSessionHasErrors('incoming_format');
        $this->actingAs($owner)->put('/admin/correspondence/settings', ['number_format' => '{yil}/{sira}', 'incoming_format' => 'GE-{yil}-{sira:3}', 'start_number' => 1])->assertSessionHasNoErrors();
        $this->actingAs($owner)->post('/admin/correspondence/incoming', $form());
        $this->assertSame("GE-{$year}-004", IncomingDocument::latest('id')->first()->registry_no);
    }

    public function test_registering_incoming_documents_is_a_permission_of_its_own(): void
    {
        $owner = $this->owner();
        $this->actingAs($owner)->post('/admin/correspondence/incoming', [
            'file' => UploadedFile::fake()->createWithContent('yazi.pdf', '%PDF-1.4'), 'received_on' => today()->toDateString(), 'channel' => 'kep', 'subject' => 'Konu', 'sender_name' => 'Gönderen',
        ])->assertSessionHasNoErrors();
        $document = IncomingDocument::latest('id')->first();

        $viewer = $this->userWith(['correspondence.view']);
        $this->actingAs($viewer)->get('/admin/correspondence/incoming')->assertOk()->assertDontSee('Gelen evrak kaydet');
        $this->actingAs($viewer)->get("/admin/correspondence/incoming/{$document->id}")->assertOk()->assertDontSee('Kaydı iptal et');
        $this->actingAs($viewer)->get('/admin/correspondence/incoming/create')->assertForbidden();
        $this->actingAs($viewer)->post("/admin/correspondence/incoming/{$document->id}/cancel", ['cancel_reason' => 'x'])->assertForbidden();

        $clerk = $this->userWith(['correspondence.view', 'correspondence.incoming']);
        $this->actingAs($clerk)->get('/admin/correspondence/incoming/create')->assertOk()->assertSee('Kaydet ve sayı ver');
        $this->actingAs($this->userWith(['correspondence.incoming']))->get("/admin/correspondence/incoming/{$document->id}")->assertForbidden();
    }

    public function test_an_incoming_package_fills_in_its_details_and_attachments(): void
    {
        $owner = $this->owner();
        $draft = $this->letter($owner, ['subject' => 'Kamp işbirliği']);
        $this->actingAs($owner)->post("/admin/correspondence/{$draft->id}/attachments", ['name' => 'Kamp programı', 'file' => UploadedFile::fake()->createWithContent('program.pdf', '%PDF-1.4 program')]);
        $letter = $draft;
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/approve");
        $letter->refresh();
        $this->signPdf($letter, $owner);
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package")->assertSessionHas('success-status');
        $digest = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/package/digest")->getContent();
        $signature = $this->cmsSignature($digest, ['commonName' => 'AYŞE YILMAZ', 'serialNumber' => '12345678901']);
        $this->actingAs($owner)->post("/admin/correspondence/{$letter->id}/package/signature", ['file' => UploadedFile::fake()->createWithContent('imza.imz', $signature)])->assertSessionHas('success-status');
        $package = $this->actingAs($owner)->get("/admin/correspondence/{$letter->id}/package")->getContent();
        $upload = fn () => UploadedFile::fake()->createWithContent('gelen.eyp', $package);

        $this->actingAs($owner)->post('/admin/correspondence/incoming', ['file' => UploadedFile::fake()->createWithContent('bozuk.eyp', 'PK not a package'), 'received_on' => today()->toDateString(), 'channel' => 'kep'])->assertSessionHasErrors('file');

        // Left empty, the details come from the package; what is written wins.
        $this->actingAs($owner)->post('/admin/correspondence/incoming', ['file' => $upload(), 'received_on' => today()->toDateString(), 'channel' => 'kep', 'notes' => 'KEP ile geldi'])->assertSessionHasNoErrors();
        $document = IncomingDocument::latest('id')->first();

        $this->assertSame(IncomingDocument::EYP, $document->source);
        $this->assertSame($letter->document_id, $document->document_id);
        $this->assertSame('Kamp işbirliği', $document->subject);
        $this->assertSame('Ayşe Yılmaz', $document->sender_name);
        $this->assertSame('person', $document->sender_kind);
        $this->assertSame($letter->document_no, $document->document_no);
        $this->assertSame($letter->document_date->toDateString(), $document->document_date->toDateString());
        $this->assertSame('AYŞE YILMAZ', $document->signer);
        $this->assertSame([], $document->findings['errors']);
        $this->assertSame(['Kamp programı'], $document->attachments->pluck('name')->all());
        $this->assertSame('%PDF-1.4 program', Storage::disk('local')->get($document->attachments->first()->path));
        $this->assertStringStartsWith('%PDF-', Storage::disk('local')->get($document->letterPath()));
        $this->assertSame($package, Storage::disk('local')->get($document->file_path));

        $this->actingAs($owner)->get("/admin/correspondence/incoming/{$document->id}")->assertOk()->assertSee('AYŞE YILMAZ')->assertSee('Paketin yapısı ve özet değerleri geçerli')->assertSee('.eyp indir');
        $this->actingAs($owner)->get("/admin/correspondence/incoming/{$document->id}/file")->assertOk()->assertDownload('gelen.eyp');

        // The same package is not registered twice while its record stands.
        $this->actingAs($owner)->post('/admin/correspondence/incoming', ['file' => $upload(), 'received_on' => today()->toDateString(), 'channel' => 'kep'])->assertSessionHasErrors('file');
        $document->forceFill(['status' => IncomingDocument::CANCELLED])->save();
        $this->actingAs($owner)->post('/admin/correspondence/incoming', ['file' => $upload(), 'received_on' => today()->toDateString(), 'channel' => 'kep', 'subject' => 'Elle yazılan konu'])->assertSessionHasNoErrors();
        $this->assertSame('Elle yazılan konu', IncomingDocument::latest('id')->first()->subject);
    }
}
