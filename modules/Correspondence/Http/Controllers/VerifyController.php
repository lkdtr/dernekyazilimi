<?php

namespace Modules\Correspondence\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Support\Turnstile;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Modules\Correspondence\Models\Letter;
use Modules\Correspondence\Models\LetterAttachment;
use Modules\Correspondence\Support\LetterPdf;
use Modules\Correspondence\Support\LetterPreview;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Public page that confirms a letter by the verification code printed on it.
 * The verification code grants access to the numbered letter and its attachments.
 */
class VerifyController extends Controller
{
    public function show(Request $request): Response
    {
        $code = is_string($request->query('kod')) ? strtoupper(trim($request->query('kod'))) : '';
        $letter = $code === '' ? null : $this->find($code);

        return response()->view('correspondence::verify', [
            'code' => $code,
            'letter' => $letter,
        ], $code !== '' && ! $letter ? 404 : 200)->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function verify(Request $request, Turnstile $turnstile): JsonResponse
    {
        $request->merge(['kod' => is_string($request->query('kod')) ? strtoupper(trim($request->query('kod'))) : $request->query('kod')]);
        $data = $request->validate([
            'kod' => ['required', 'string', 'uuid'],
            Turnstile::FIELD => ['nullable', 'string', 'max:2048'],
        ]);
        if (! $turnstile->verify($data[Turnstile::FIELD] ?? null, $request->ip())) {
            throw ValidationException::withMessages([Turnstile::FIELD => 'Güvenlik doğrulaması tamamlanamadı. Lütfen sayfayı yenileyip yeniden deneyin.']);
        }
        $letter = $this->find($data['kod']);
        $response = $letter ? [
            'document_no' => $letter->document_no,
            'document_date' => $letter->document_date?->format('d.m.Y'),
            'subject' => $letter->subject,
            'status' => $letter->status,
            'preview_url' => route('correspondence.verify.pdf', $letter->document_id),
            'download_url' => route('correspondence.verify.pdf', ['code' => $letter->document_id, 'download' => 1]),
            'original_url' => route('correspondence.verify.pdf', ['code' => $letter->document_id, 'original' => 1]),
            'attachments' => $letter->attachments->map(fn ($attachment) => [
                'name' => $attachment->name,
                'url' => $attachment->hasFile() ? route('correspondence.verify.attachment', ['code' => $letter->document_id, 'attachment' => $attachment->id]) : null,
            ])->values(),
        ] : ['message' => 'Bu kodla kayıtlı bir belge bulunamadı.'];

        return response()->json($response, $letter ? 200 : 404)
            ->header('Cache-Control', 'no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow');
    }

    public function pdf(Request $request, string $code, LetterPdf $pdf, LetterPreview $preview): Response
    {
        $letter = $this->find(strtoupper($code));
        abort_unless($letter, 404);

        return response($request->boolean('original') ? $pdf->render($letter) : $preview->render($letter), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => ($request->boolean('download') ? 'attachment' : 'inline').'; filename="'.$pdf->filename($letter).'"',
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function attachment(string $code, LetterAttachment $attachment): StreamedResponse
    {
        $letter = $this->find(strtoupper($code));
        abort_unless($letter && $attachment->letter_id === $letter->id && $attachment->hasFile() && Storage::disk('local')->exists($attachment->path), 404);

        return Storage::disk('local')->download($attachment->path, $attachment->original_name, [
            'Cache-Control' => 'no-store',
            'X-Robots-Tag' => 'noindex, nofollow',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function find(string $code): ?Letter
    {
        return Letter::with('attachments')->where('document_id', $code)->whereIn('status', [Letter::NUMBERED, Letter::CANCELLED])->first();
    }

}
