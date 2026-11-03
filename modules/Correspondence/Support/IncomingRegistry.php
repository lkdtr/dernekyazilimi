<?php

namespace Modules\Correspondence\Support;

use App\Models\User;
use BahriCanli\EYazisma\Dosya;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Correspondence\Models\IncomingDocument;
use Modules\Correspondence\Models\IncomingSequence;

/**
 * Registers a received document: gives it the next incoming number of the
 * year, never reused, and keeps the file as it arrived with the letter and
 * attachments taken out of a package.
 */
class IncomingRegistry
{
    public function __construct(private CorrespondenceSettings $settings)
    {
    }

    /**
     * @param  array<string, mixed>  $details  the fields of the incoming document
     * @param  list<UploadedFile>  $files  attachments sent apart from the letter
     */
    public function register(array $details, UploadedFile $file, ?IncomingPackage $package, array $files, User $user): IncomingDocument
    {
        return DB::transaction(function () use ($details, $file, $package, $files, $user) {
            $year = now()->year;

            IncomingSequence::firstOrCreate(['year' => $year], ['last_number' => 0]);
            $sequence = IncomingSequence::where('year', $year)->lockForUpdate()->first();
            $sequence->increment('last_number');

            $document = new IncomingDocument;
            $document->forceFill($details + [
                'status' => IncomingDocument::REGISTERED,
                'number_year' => $year,
                'number' => $sequence->last_number,
                'registry_no' => Numbering::format($this->settings->incomingFormat(), $year, $sequence->last_number, null, $this->settings->registryNumber()),
                'source' => $package ? IncomingDocument::EYP : IncomingDocument::PDF,
                'document_id' => $package?->documentId,
                'findings' => $package?->findings,
                'signer' => $package?->signer,
                'file_name' => $file->getClientOriginalName(),
                'file_path' => '',
                'created_by' => $user->id,
            ]);
            $directory = $document->directory();
            $document->file_path = $file->storeAs($directory, $package ? $package->documentId.'.eyp' : 'yazi.pdf', 'local');

            if ($package) {
                $document->letter_path = $directory.'/'.$package->letter->paketAdi();
                Storage::disk('local')->put($document->letter_path, $package->letter->icerik);
            }

            $document->save();

            $sort = 0;
            foreach ($package?->attachments ?? [] as $name => $attachment) {
                $this->attach($document, $name, $attachment, ++$sort);
            }
            foreach ($files as $upload) {
                $this->attach($document, $upload->getClientOriginalName(), Dosya::icerikten($upload->get(), $upload->getClientOriginalName(), $upload->getMimeType()), ++$sort);
            }

            return $document;
        });
    }

    private function attach(IncomingDocument $document, string $name, Dosya $file, int $sort): void
    {
        $path = $document->directory().'/ekler/'.$sort.'-'.$file->paketAdi();
        Storage::disk('local')->put($path, $file->icerik);

        $document->attachments()->create([
            'name' => $name,
            'path' => $path,
            'original_name' => $file->ad,
            'mime' => $file->mimeTuru,
            'size' => strlen($file->icerik),
            'sort' => $sort,
        ]);
    }
}
