@extends('layouts.app')

@section('title', 'Belge doğrulama')

@section('content')
<div class="{{ $letter ? 'container-xl' : 'container-tight' }} py-4">
    <div class="card card-md">
        @if ($letter)
            @php $cancelled = $letter->status === \Modules\Correspondence\Models\Letter::CANCELLED; @endphp
            <div class="card-status-top bg-{{ $cancelled ? 'danger' : 'success' }}"></div>
            <div class="card-body text-center">
                <div class="mb-3"><i class="ti ti-{{ $cancelled ? 'file-x' : 'file-check' }} text-{{ $cancelled ? 'danger' : 'success' }}" style="font-size: 4rem; line-height: 1;"></i></div>
                <h2 class="h1 mb-2 text-{{ $cancelled ? 'danger' : 'success' }}">{{ $cancelled ? 'İptal edilmiş belge' : 'Belge doğrulandı' }}</h2>
                <div class="text-secondary mb-4">{{ $organization->name() }}</div>
                <dl class="row text-start mb-0">
                    <dt class="col-4">Sayı</dt>
                    <dd class="col-8">{{ $letter->document_no }}</dd>
                    <dt class="col-4">Tarih</dt>
                    <dd class="col-8">{{ $letter->document_date->format('d.m.Y') }}</dd>
                    <dt class="col-4">Konu</dt>
                    <dd class="col-8">{{ $letter->subject }}</dd>
                </dl>
                <div class="mt-4 text-start">
                    <h3>Asıl yazı ve ekleri</h3>
                    <p><a href="{{ route('correspondence.verify.pdf', $letter->document_id) }}" target="_blank" rel="noopener" class="btn btn-primary">Birleşik PDF’yi aç</a>
                    <a href="{{ route('correspondence.verify.pdf', ['code' => $letter->document_id, 'download' => 1]) }}" class="btn btn-outline-primary">Birleşik PDF’yi indir</a>
                    <a href="{{ route('correspondence.verify.pdf', ['code' => $letter->document_id, 'original' => 1]) }}" target="_blank" rel="noopener" class="btn btn-outline-secondary">Asıl PDF’yi aç</a></p>
                    <p class="text-secondary small">Birleşik PDF bir görüntüleme kopyasıdır. Elektronik imzayı doğrulamak için asıl dosyaları kullanın.</p>
                    @if ($letter->attachments->isNotEmpty())
                        <ul>
                            @foreach ($letter->attachments as $attachment)
                                <li>@if ($attachment->hasFile())<a href="{{ route('correspondence.verify.attachment', ['code' => $letter->document_id, 'attachment' => $attachment->id]) }}">{{ $attachment->name }}</a>@else{{ $attachment->name }} (fiziksel ek)@endif</li>
                            @endforeach
                        </ul>
                    @endif
                    <iframe src="{{ route('correspondence.verify.pdf', $letter->document_id) }}" title="Asıl yazı ve PDF ekleri" style="width:100%;height:80vh;border:1px solid #ddd;"></iframe>
                </div>
            </div>
        @else
            @if ($code !== '')<div class="card-status-top bg-danger"></div>@endif
            <div class="card-body">
                <h2 class="h2 text-center mb-3">Belge doğrulama</h2>
                @if ($code !== '')
                    <div class="alert alert-danger" role="alert">Bu doğrulama kodu ve sayıyla bir belge bulunamadı.</div>
                @endif
                <p class="text-secondary">{{ $organization->name() }} tarafından gönderilen yazının altındaki belge doğrulama kodunu ve yazının sayısını yazın.</p>
                <form method="GET" action="{{ route('correspondence.verify') }}">
                    <div class="mb-3"><label class="form-label required" for="kod">Belge doğrulama kodu</label><input id="kod" name="kod" class="form-control" value="{{ $code }}" maxlength="36" placeholder="XXXXXXXX-XXXX-XXXX-XXXX-XXXXXXXXXXXX" required></div>
                    <div class="mb-3"><label class="form-label required" for="sayi">Yazının sayısı</label><input id="sayi" name="sayi" class="form-control" value="{{ $number }}" maxlength="120" placeholder="ör. 06-061-115-2026-23" required><span class="form-hint">Yazının üst kısmındaki "Sayı" satırında yazar.</span></div>
                    <button type="submit" class="btn btn-primary w-100">Doğrula</button>
                </form>
            </div>
        @endif
    </div>
</div>
@endsection
