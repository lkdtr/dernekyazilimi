@extends('layouts.admin')

@section('content')
<div class="container-xl">
    <div class="page-header mb-3">
        <h2 class="page-title">Yazışma ayarları</h2>
        <div class="text-secondary mt-1">Antetteki ad, logo, adres ve iletişim bilgileri ile dernek kütük ve MERSİS numaraları <a href="{{ route('admin.settings.organization') }}">kurum ayarlarından</a> gelir.</div>
    </div>

    @include('admin::partials.status')

    <form method="POST" action="{{ route('admin.correspondence.settings.update') }}" class="card">
        @csrf @method('PUT')
        <div class="card-body">
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label required" for="number_format">Sayı biçimi</label>
                    <input id="number_format" name="number_format" class="form-control @error('number_format') is-invalid @enderror" value="{{ old('number_format', $settings->numberFormat()) }}" maxlength="60" required>
                    @error('number_format')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <span class="form-hint"><code>{kutuk}</code> dernek kütük numarası, <code>{yil}</code> yıl, <code>{sira}</code> sıra numarası (<code>{sira:4}</code> dört haneye tamamlanır), <code>{kod}</code> dosya planı kodu. Şu anki biçimle örnek: <strong>{{ $example }}</strong></span>
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label required" for="start_number">Başlangıç numarası</label>
                    <input id="start_number" type="number" min="1" name="start_number" class="form-control @error('start_number') is-invalid @enderror" value="{{ old('start_number', $settings->startNumber()) }}" required>
                    @error('start_number')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <span class="form-hint">Henüz yazı yazılmamış bir yılın ilk sayısı. Sıra her yıl baştan başlar.</span>
                </div>
            </div>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="incoming_format">Gelen evrak sayı biçimi</label>
                    <input id="incoming_format" name="incoming_format" class="form-control @error('incoming_format') is-invalid @enderror" value="{{ old('incoming_format', $settings->incomingFormat()) }}" maxlength="60" placeholder="{{ \Modules\Correspondence\Support\CorrespondenceSettings::DEFAULT_INCOMING_FORMAT }}">
                    @error('incoming_format')<div class="invalid-feedback">{{ $message }}</div>@enderror
                    <span class="form-hint">Gelen evrakın sırası giden yazılardan ayrıdır ve her yıl 1'den başlar. <code>{kutuk}</code>, <code>{yil}</code> ve <code>{sira}</code> kullanılabilir. Örnek: <strong>{{ $incomingExample }}</strong></span>
                </div>
            </div>
            <dl class="row mb-0">
                <dt class="col-sm-3">Dernek kütük numarası</dt>
                <dd class="col-sm-9">{{ $settings->registryNumber() ?? '—' }}</dd>
                <dt class="col-sm-3">MERSİS numarası</dt>
                <dd class="col-sm-9">{{ $settings->identifier() ?? '—' }} <span class="text-secondary small">e-Yazışma paketi oluşturmak için gereklidir.</span></dd>
            </dl>

            <h3 class="card-title mt-4">Belge doğrulama</h3>
            <label class="form-label" for="verification_url">WordPress belge doğrulama sayfası (isteğe bağlı)</label>
            <input id="verification_url" name="verification_url" type="url" class="form-control @error('verification_url') is-invalid @enderror" value="{{ old('verification_url', $settings->verificationUrl() === route('correspondence.verify') ? '' : $settings->verificationUrl()) }}" placeholder="https://www.ornek.org.tr/belge-dogrula/" maxlength="255">
            @error('verification_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
            <span class="form-hint">WordPress sayfasına <code>[dernekyazilimi_verify]</code> ekleyin ve adresini buraya yazın. Boşsa portal kullanılır. Yalnız bundan sonra hazırlanan PDF ve EYP bağlantıları değişir; mevcut imzalı belgeler korunur.</span>

            <h3 class="card-title mt-4">e-Yazışma paketi</h3>
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="package_generation">Önerilen paket türü</label>
                    <select id="package_generation" name="package_generation" class="form-select">
                        <option value="{{ \Modules\Correspondence\Support\LetterPackage::OLD }}" @selected(old('package_generation', $settings->packageGeneration()) === \Modules\Correspondence\Support\LetterPackage::OLD)>2.0 öncesi: yalnız e-imza</option>
                        <option value="{{ \Modules\Correspondence\Support\LetterPackage::CURRENT }}" @selected(old('package_generation', $settings->packageGeneration()) === \Modules\Correspondence\Support\LetterPackage::CURRENT)>2.x: e-imza ve e-mühür</option>
                    </select>
                    <span class="form-hint">Paket oluştururken ilk seçili gelen tür; her pakette değiştirilebilir. 2.0 öncesi paket imzayla tamamlanır ve kurumlar arasında hâlâ kullanılır; 2.x için kurumun e-mührü gerekir.</span>
                </div>
            </div>

            <h3 class="card-title mt-4">Zaman damgası</h3>
            <p class="text-secondary small">İmza uygulaması, imzayı uzun süre doğrulanabilir kılmak için zaman damgası alır. Hesap bilgisi burada saklanır ve yalnız imza sırasında, tek kullanımlık imza bağlantısıyla uygulamaya iletilir. Boş bırakılırsa imza zaman damgasız atılır.</p>
            @php $timestamp = $settings->timestampService(); @endphp
            <div class="row">
                <div class="col-md-6 mb-3">
                    <label class="form-label" for="tsa_url">Zaman damgası adresi</label>
                    <input id="tsa_url" type="url" name="tsa_url" class="form-control @error('tsa_url') is-invalid @enderror" value="{{ old('tsa_url', $timestamp['url'] ?? '') }}" maxlength="255" placeholder="http://...">
                    @error('tsa_url')<div class="invalid-feedback">{{ $message }}</div>@enderror
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="tsa_user">Kullanıcı / müşteri no</label>
                    <input id="tsa_user" name="tsa_user" class="form-control" value="{{ old('tsa_user', $timestamp['user'] ?? '') }}" maxlength="100" autocomplete="off" data-1p-ignore data-lpignore="true" data-bwignore data-form-type="other">
                </div>
                <div class="col-md-3 mb-3">
                    <label class="form-label" for="tsa_password">Parola</label>
                    <x-secret-input id="tsa_password" name="tsa_password" maxlength="200" placeholder="{{ ($timestamp['password'] ?? null) ? 'Kayıtlı; değiştirmek için yazın' : '' }}" />
                </div>
            </div>
        </div>
        <div class="card-footer text-end"><button type="submit" class="btn btn-primary">Kaydet</button></div>
    </form>
</div>
@endsection
