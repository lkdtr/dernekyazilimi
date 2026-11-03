<?php

namespace Modules\Correspondence\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\TableSort;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Modules\Correspondence\Models\IncomingAttachment;
use Modules\Correspondence\Models\IncomingDocument;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\LetterAttachment;
use Modules\Correspondence\Models\LetterRecipient;
use Modules\Correspondence\Support\IncomingPackage;
use Modules\Correspondence\Support\IncomingRegistry;
use Modules\Correspondence\Support\PackageException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Documents received by the association: an e-Yazışma package, or a PDF
 * sent by KEP, e-mail or by hand. Each is registered with its own number.
 */
class IncomingController extends Controller
{
    public function index(Request $request): View
    {
        $status = $request->query('status');
        $year = $request->query('year');
        $search = trim((string) $request->query('q'));

        return view('correspondence::admin.incoming.index', [
            'documents' => IncomingDocument::query()
                ->when($status, fn ($query) => $query->where('status', $status))
                ->when($year, fn ($query) => $query->where('number_year', $year))
                ->when($search !== '', fn ($query) => $query->where(fn ($query) => $query->where('subject', 'like', "%{$search}%")->orWhere('registry_no', 'like', "%{$search}%")->orWhere('document_no', 'like', "%{$search}%")->orWhere('sender_name', 'like', "%{$search}%")))
                ->tap(TableSort::by($request, [
                    'number' => fn ($query, $direction) => $query->orderBy('number_year', $direction)->orderBy('number', $direction),
                    'date' => 'received_on',
                    'sender' => 'sender_name',
                    'subject' => 'subject',
                ], fn ($query) => $query->orderByDesc('number_year')->orderByDesc('number')))
                ->paginate(30)->withQueryString(),
            'status' => $status,
            'year' => $year,
            'search' => $search,
        ]);
    }

    public function create(): View
    {
        return view('correspondence::admin.incoming.form', ['letters' => $this->letters()]);
    }

    public function store(Request $request, IncomingRegistry $registry): RedirectResponse
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'extensions:eyp,pdf', 'max:30720'],
            'received_on' => ['required', 'date', 'before_or_equal:today'],
            'channel' => ['required', Rule::in(array_keys(IncomingDocument::CHANNELS))],
            'subject' => ['nullable', 'string', 'max:255'],
            'sender_kind' => ['nullable', Rule::in(array_keys(LetterRecipient::KINDS))],
            'sender_name' => ['nullable', 'string', 'max:255'],
            'sender_identifier' => ['nullable', 'string', 'max:30'],
            'document_no' => ['nullable', 'string', 'max:120'],
            'document_date' => ['nullable', 'date'],
            'letter_id' => ['nullable', 'integer', Rule::exists('correspondence_letters', 'id')->whereNull('deleted_at')],
            'notes' => ['nullable', 'string', 'max:2000'],
            'attachment_files' => ['nullable', 'array', 'max:20'],
            'attachment_files.*' => ['file', 'max:20480', 'mimes:'.LetterAttachment::UPLOAD_MIMES],
        ], [], $this->names());

        $file = $request->file('file');
        $content = $file->get();
        $package = null;

        if (strtolower($file->getClientOriginalExtension()) === IncomingDocument::EYP) {
            try {
                $package = IncomingPackage::read($content);
            } catch (PackageException $exception) {
                throw ValidationException::withMessages(['file' => $exception->getMessage()]);
            }

            $existing = IncomingDocument::where('document_id', $package->documentId)->where('status', IncomingDocument::REGISTERED)->first();
            if ($existing) {
                throw ValidationException::withMessages(['file' => "Bu paket zaten {$existing->registry_no} sayısıyla kayıtlı."]);
            }
        } elseif (! str_starts_with($content, '%PDF-')) {
            throw ValidationException::withMessages(['file' => 'Dosya PDF değil.']);
        }

        // What is written in the form wins; a package fills what is left empty.
        $details = array_map(fn ($value) => is_string($value) ? (trim($value) ?: null) : $value, array_intersect_key($data, array_flip([
            'received_on', 'channel', 'subject', 'sender_kind', 'sender_name', 'sender_identifier', 'document_no', 'document_date', 'letter_id', 'notes',
        ])));
        foreach ($package?->details ?? [] as $key => $value) {
            $details[$key] ??= $value;
        }
        $details['sender_kind'] ??= LetterRecipient::INSTITUTION;

        $missing = array_filter(['subject' => 'Konu', 'sender_name' => 'Gönderen'], fn ($label, $key) => blank($details[$key] ?? null), ARRAY_FILTER_USE_BOTH);
        if ($missing) {
            throw ValidationException::withMessages(array_map(fn ($label) => "{$label} alanı gereklidir.", $missing));
        }

        $document = $registry->register($details, $file, $package, $request->file('attachment_files', []), Auth::user());
        $this->set_log('create',"Gelen evrak kaydedildi ({$document->registry_no})");

        return redirect()->route('admin.correspondence.incoming.show', $document)->with('success-status', "Gelen evrak {$document->registry_no} sayısıyla kaydedildi.");
    }

    public function show(IncomingDocument $incoming): View
    {
        return view('correspondence::admin.incoming.show', ['document' => $incoming->load(['attachments', 'letter', 'creator'])]);
    }

    /** The letter itself, shown in the browser. */
    public function letter(IncomingDocument $incoming): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($incoming->letterPath()), 404);

        return Storage::disk('local')->response($incoming->letterPath(), str_replace(['/', '\\'], '-', $incoming->registry_no).'.pdf', [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /** The file as it was received. */
    public function file(IncomingDocument $incoming): StreamedResponse
    {
        abort_unless(Storage::disk('local')->exists($incoming->file_path), 404);

        return Storage::disk('local')->download($incoming->file_path, $incoming->file_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    public function attachment(IncomingDocument $incoming, IncomingAttachment $attachment): StreamedResponse
    {
        abort_unless($attachment->incoming_id === $incoming->id && Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, ['X-Content-Type-Options' => 'nosniff']);
    }

    /** A registered document is cancelled, not deleted; its number is not given again. */
    public function cancel(Request $request, IncomingDocument $incoming): RedirectResponse
    {
        abort_if($incoming->isCancelled(), 403);

        $data = $request->validate(['cancel_reason' => ['required', 'string', 'max:500']], [], ['cancel_reason' => 'İptal nedeni']);

        $incoming->forceFill(['status' => IncomingDocument::CANCELLED, 'cancelled_at' => now(), 'cancel_reason' => $data['cancel_reason']])->save();

        return back()->with('success-status', 'Gelen evrak kaydı iptal edildi; sayısı yeniden kullanılmaz.');
    }

    /** Numbered outgoing letters an incoming document may answer, the latest first. */
    private function letters()
    {
        return Letter::where('status', Letter::NUMBERED)->orderByDesc('number_year')->orderByDesc('number')->orderByDesc('id')->limit(200)->get(['id', 'document_no', 'subject']);
    }

    /** @return array<string, string> */
    private function names(): array
    {
        return [
            'file' => 'Dosya', 'received_on' => 'Geliş tarihi', 'channel' => 'Geliş yolu', 'subject' => 'Konu',
            'sender_kind' => 'Gönderen türü', 'sender_name' => 'Gönderen', 'sender_identifier' => 'Gönderen kimliği',
            'document_no' => 'Sayı', 'document_date' => 'Tarih', 'letter_id' => 'İlgili giden yazı', 'notes' => 'Not',
            'attachment_files' => 'Ek dosyalar', 'attachment_files.*' => 'Ek dosya',
        ];
    }
}
