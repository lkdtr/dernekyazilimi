@extends('layouts.admin')

@php
    use Modules\Correspondence\Models\IncomingDocument;
    use Modules\Correspondence\Models\LetterRecipient;

    $canRegister = Auth::user()->hasPermission('correspondence.incoming');
@endphp

@section('content')
<div class="container-xl">
    <div class="page-header mb-3 d-flex justify-content-between align-items-start">
        <div>
            <div class="text-secondary"><a href="{{ route('admin.correspondence.incoming') }}">Gelen evrak</a></div>
            <h2 class="page-title">{{ $document->subject }}</h2>
            <div class="mt-1">
                <span class="badge bg-{{ $document->isCancelled() ? 'red' : 'green' }}-lt">{{ $document->statusLabel() }}</span>
                <span class="ms-2">Gelen evrak sayısı: <strong>{{ $document->registry_no }}</strong></span>
                <span class="ms-2 text-secondary">{{ $document->received_on->format('d.m.Y') }}, {{ IncomingDocument::CHANNELS[$document->channel] ?? $document->channel }}</span>
            </div>
        </div>
        <div class="btn-list">
            <a href="{{ route('admin.correspondence.incoming.letter', $document) }}" target="_blank" class="btn btn-outline-primary"><i class="ti ti-file-type-pdf me-1"></i>Yazıyı aç</a>
            @if ($document->isPackage())
                <a href="{{ route('admin.correspondence.incoming.file', $document) }}" class="btn btn-outline-secondary"><i class="ti ti-download me-1"></i>.eyp indir</a>
            @endif
        </div>
    </div>

    @include('admin::partials.status')

    @if ($document->isCancelled())
        <div class="alert alert-danger" role="alert">Bu kayıt {{ $document->cancelled_at->format('d.m.Y') }} tarihinde iptal edildi: {{ $document->cancel_reason }}</div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Evrak</h3></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4">Gönderen</dt>
                        <dd class="col-sm-8">{{ $document->sender_name }}<div class="text-secondary small">{{ LetterRecipient::KINDS[$document->sender_kind] ?? $document->sender_kind }}@if ($document->sender_identifier) · {{ LetterRecipient::IDENTIFIERS[$document->sender_kind] ?? 'Kimlik' }}: {{ $document->sender_identifier }}@endif</div></dd>
                        <dt class="col-sm-4">Gönderenin sayısı</dt>
                        <dd class="col-sm-8">{{ $document->document_no ?? '—' }}@if ($document->document_date) <span class="text-secondary ms-1">{{ $document->document_date->format('d.m.Y') }}</span>@endif</dd>
                        @if ($document->letter)
                            <dt class="col-sm-4">Yanıt verdiği yazı</dt>
                            <dd class="col-sm-8"><a href="{{ route('admin.correspondence.show', $document->letter) }}">{{ $document->letter->document_no }}</a> {{ $document->letter->subject }}</dd>
                        @endif
                        @if ($document->signer)
                            <dt class="col-sm-4">Paketi imzalayan</dt>
                            <dd class="col-sm-8">{{ $document->signer }} <span class="text-secondary small">sertifikaya göre</span></dd>
                        @endif
                        <dt class="col-sm-4">Gelen dosya</dt>
                        <dd class="col-sm-8"><a href="{{ route('admin.correspondence.incoming.file', $document) }}">{{ $document->file_name }}</a></dd>
                        @if ($document->notes)
                            <dt class="col-sm-4">Not</dt>
                            <dd class="col-sm-8">{!! nl2br(e($document->notes)) !!}</dd>
                        @endif
                    </dl>
                </div>
                <div class="card-footer text-secondary small">Kaydeden: {{ $document->creator?->name ?? '—' }}, {{ $document->created_at->format('d.m.Y H:i') }}</div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Ekler</h3></div>
                <div class="list-group list-group-flush">
                    @forelse ($document->attachments as $attachment)
                        <div class="list-group-item">
                            {{ $loop->iteration }}. <a href="{{ route('admin.correspondence.incoming.attachment', [$document, $attachment]) }}">{{ $attachment->name }}</a>
                            <span class="text-secondary small ms-1">{{ number_format($attachment->size / 1024, 0, ',', '.') }} KB</span>
                        </div>
                    @empty
                        <div class="list-group-item text-secondary">Ek yok.</div>
                    @endforelse
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            @if ($document->isPackage())
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">e-Yazışma paketi</h3></div>
                    <div class="card-body">
                        @foreach ($document->findings['errors'] ?? [] as $finding)
                            <div class="alert alert-danger py-2 small" role="alert">{{ $finding }}</div>
                        @endforeach
                        @foreach ($document->findings['warnings'] ?? [] as $finding)
                            <div class="alert alert-warning py-2 small" role="alert">{{ $finding }}</div>
                        @endforeach
                        @if (empty($document->findings['errors']))
                            <div class="alert alert-success py-2 small" role="alert">Paketin yapısı ve özet değerleri geçerli.</div>
                        @endif
                        <p class="text-secondary small mb-0">İmzanın sertifika zinciri ve iptal durumu burada denetlenmez; gerekirse paketi İmzager ile doğrulayın.</p>
                    </div>
                </div>
            @endif

            @if ($canRegister && ! $document->isCancelled())
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Kaydı iptal et</h3></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.correspondence.incoming.cancel', $document) }}" onsubmit="return confirm('Kayıt iptal edilsin mi? Sayısı yeniden kullanılmaz.')">
                            @csrf
                            <textarea name="cancel_reason" rows="2" class="form-control mb-2 @error('cancel_reason') is-invalid @enderror" placeholder="İptal nedeni" maxlength="500" required>{{ old('cancel_reason') }}</textarea>
                            <button type="submit" class="btn btn-outline-danger w-100">İptal et</button>
                        </form>
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
@endsection
