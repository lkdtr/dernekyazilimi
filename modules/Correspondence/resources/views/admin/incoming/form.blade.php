@extends('layouts.admin')

@php
    use Modules\Correspondence\Models\IncomingDocument;
    use Modules\Correspondence\Models\LetterRecipient;
@endphp

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <div class="text-secondary"><a href="{{ route('admin.correspondence.incoming') }}">Gelen evrak</a></div>
        <h2 class="page-title">Gelen evrak kaydet</h2>
        <div class="text-secondary mt-1">Kaydedilen evrak yılın sıradaki gelen evrak sayısını alır. e-Yazışma paketinde boş bıraktığınız alanlar paketten doldurulur; PDF'te konu ve gönderen yazılmalıdır.</div>
    </div>

    @include('admin::partials.status')

    @if ($errors->any())
        <div class="alert alert-danger" role="alert"><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <form method="POST" action="{{ route('admin.correspondence.incoming.store') }}" enctype="multipart/form-data">
        @csrf
        <div class="card mb-3">
            <div class="card-header"><h3 class="card-title">Evrak</h3></div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6 mb-3">
                        <label class="form-label required" for="file">Gelen dosya</label>
                        <input id="file" type="file" name="file" accept=".eyp,.pdf,application/pdf" class="form-control @error('file') is-invalid @enderror" required>
                        @error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror
                        <span class="form-hint">e-Yazışma paketi (.eyp) ya da PDF. Paketin yapısı ve özetleri denetlenir; imzacı sertifikadan okunur.</span>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label required" for="received_on">Geliş tarihi</label>
                        <input id="received_on" type="date" name="received_on" class="form-control @error('received_on') is-invalid @enderror" value="{{ old('received_on', today()->toDateString()) }}" max="{{ today()->toDateString() }}" required>
                    </div>
                    <div class="col-md-3 mb-3">
                        <label class="form-label required" for="channel">Geliş yolu</label>
                        <select id="channel" name="channel" class="form-select">@foreach (IncomingDocument::CHANNELS as $key => $label)<option value="{{ $key }}" @selected(old('channel', 'email') === $key)>{{ $label }}</option>@endforeach</select>
                    </div>
                </div>
                <div class="mb-3"><label class="form-label" for="subject">Konu</label><input id="subject" name="subject" class="form-control @error('subject') is-invalid @enderror" value="{{ old('subject') }}" maxlength="255"></div>
                <div class="row">
                    <div class="col-md-3 mb-3"><label class="form-label" for="sender_kind">Gönderen türü</label><select id="sender_kind" name="sender_kind" class="form-select"><option value="">—</option>@foreach (LetterRecipient::KINDS as $key => $label)<option value="{{ $key }}" @selected(old('sender_kind') === $key)>{{ $label }}</option>@endforeach</select></div>
                    <div class="col-md-6 mb-3"><label class="form-label" for="sender_name">Gönderen</label><input id="sender_name" name="sender_name" class="form-control @error('sender_name') is-invalid @enderror" value="{{ old('sender_name') }}" maxlength="255" placeholder="ör. Anadolu Üniversitesi Rektörlüğü"></div>
                    <div class="col-md-3 mb-3"><label class="form-label" for="sender_identifier">DETSİS / MERSİS / T.C. no</label><input id="sender_identifier" name="sender_identifier" class="form-control" value="{{ old('sender_identifier') }}" maxlength="30"></div>
                </div>
                <div class="row">
                    <div class="col-md-6 mb-3"><label class="form-label" for="document_no">Gönderenin sayısı</label><input id="document_no" name="document_no" class="form-control" value="{{ old('document_no') }}" maxlength="120" placeholder="ör. E-63784619-719-1096667"></div>
                    <div class="col-md-3 mb-3"><label class="form-label" for="document_date">Yazının tarihi</label><input id="document_date" type="date" name="document_date" class="form-control" value="{{ old('document_date') }}"></div>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="letter_id">Yanıt verdiği giden yazı (isteğe bağlı)</label>
                    <select id="letter_id" name="letter_id" class="form-select">
                        <option value="">—</option>
                        @foreach ($letters as $letter)<option value="{{ $letter->id }}" @selected((string) old('letter_id') === (string) $letter->id)>{{ $letter->document_no }} · {{ $letter->subject }}</option>@endforeach
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label" for="attachment_files">Ek dosyalar (isteğe bağlı)</label>
                    <input id="attachment_files" type="file" name="attachment_files[]" multiple class="form-control @error('attachment_files.*') is-invalid @enderror">
                    <span class="form-hint">Yazıyla ayrı gelen ekler; en fazla 20 dosya, dosya başına 20 MB. Paketteki ekler kendiliğinden alınır.</span>
                </div>
                <div class="mb-3"><label class="form-label" for="notes">Not</label><textarea id="notes" name="notes" rows="2" class="form-control" maxlength="2000">{{ old('notes') }}</textarea></div>
            </div>
            <div class="card-footer d-flex justify-content-between">
                <a href="{{ route('admin.correspondence.incoming') }}" class="btn btn-link">Vazgeç</a>
                <button type="submit" class="btn btn-primary">Kaydet ve sayı ver</button>
            </div>
        </div>
    </form>
</div>
@endsection
