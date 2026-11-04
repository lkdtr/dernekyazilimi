@extends('layouts.admin')

@php
    use BahriCanli\EYazisma\Enums\Surum;
    use Modules\Correspondence\Models\Letter;
    use Modules\Correspondence\Models\LetterRecipient;
    use Modules\Correspondence\Models\SigningSession;
    use Modules\Correspondence\Support\LetterPackage;

    $user = Auth::user();
    $canManage = $user->hasPermission('correspondence.manage');
    $canApprove = $user->hasPermission('correspondence.approve');
    // What the package waits for (signature, then seal), and whether it needs anything more.
    $step = $package ? LetterPackage::pendingStep($package) : null;
    $complete = $package && LetterPackage::isComplete($package);
    $old = $package?->surum() === Surum::V1;
@endphp

@section('content')
<div class="container-xl">
    <div class="page-header mb-3 d-flex justify-content-between align-items-start">
        <div>
            <div class="text-secondary"><a href="{{ route('admin.correspondence') }}">Giden yazılar</a></div>
            <h2 class="page-title">{{ $letter->subject }}</h2>
            <div class="mt-1">
                <span class="badge bg-{{ Letter::STATUS_COLORS[$letter->status] ?? 'secondary' }}-lt">{{ $letter->statusLabel() }}</span>
                @if ($letter->document_no)<span class="ms-2">Sayı: <strong>{{ $letter->document_no }}</strong></span><span class="ms-2 text-secondary">{{ $letter->document_date->format('d.m.Y') }}</span>@endif
            </div>
        </div>
        <div class="btn-list">
            <a href="{{ route('admin.correspondence.pdf', $letter) }}" target="_blank" class="btn btn-outline-primary"><i class="ti ti-file-type-pdf me-1"></i>Yazı ve PDF ekleri</a>
            <a href="{{ route('admin.correspondence.pdf', [$letter, 'original' => 1]) }}" target="_blank" class="btn btn-outline-secondary">Asıl PDF</a>
            @if ($letter->isEditable() && $canManage)
                <a href="{{ route('admin.correspondence.edit', $letter) }}" class="btn btn-outline-secondary"><i class="ti ti-edit me-1"></i>Düzenle</a>
                @unless ($canApprove)
                    <form method="POST" action="{{ route('admin.correspondence.submit', $letter) }}">@csrf<button type="submit" class="btn btn-primary">Onaya gönder</button></form>
                @endunless
            @endif
            @if ($canApprove && in_array($letter->status, [Letter::DRAFT, Letter::PENDING], true))
                @if ($letter->status === Letter::PENDING)
                    <form method="POST" action="{{ route('admin.correspondence.return', $letter) }}">@csrf<button type="submit" class="btn btn-outline-secondary">Taslağa geri al</button></form>
                @endif
                <form method="POST" action="{{ route('admin.correspondence.approve', $letter) }}" onsubmit="return confirm('{{ $letter->isPdf() ? 'Yazı onaylanacak' : 'Yazı onaylanıp sayı verilecek' }}; sonrasında içeriği değiştirilemez. Devam edilsin mi?')">@csrf<button type="submit" class="btn btn-success"><i class="ti ti-check me-1"></i>{{ $letter->isPdf() ? 'Onayla' : 'Onayla ve sayı ver' }}</button></form>
            @endif
        </div>
    </div>

    @include('admin::partials.status')

    @if ($letter->status === Letter::CANCELLED)
        <div class="alert alert-danger" role="alert">Bu yazı {{ $letter->cancelled_at->format('d.m.Y') }} tarihinde iptal edildi: {{ $letter->cancel_reason }}</div>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Yazı</h3></div>
                <div class="card-body">
                    @if ($letter->references)
                        <p class="mb-2"><strong>İlgi:</strong> @foreach ($letter->references as $index => $reference)<span class="d-block">{{ chr(ord('a') + $index) }}) {{ $reference }}</span>@endforeach</p>
                    @endif
                    @if ($letter->isPdf())
                        <p class="mb-0"><i class="ti ti-file-type-pdf me-1"></i>Hazır PDF: <a href="{{ route('admin.correspondence.pdf', $letter) }}" target="_blank">{{ $letter->pdf_name }}</a> <span class="text-secondary small">Yazı portalın dışında hazırlandı; doğrulama bilgileri onaydan sonra PDF’e eklenir.</span></p>
                    @else
                        <div class="markdown">{!! $letter->body !!}</div>
                    @endif
                </div>
                <div class="card-footer text-secondary small">
                    Belge doğrulama kodu: <code>{{ $letter->document_id }}</code>
                    @if ($letter->file_code)<span class="ms-3">Dosya planı: {{ $letter->file_code }} {{ $letter->file_name }}</span>@endif
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Ekler</h3></div>
                <div class="list-group list-group-flush">
                    @forelse ($letter->attachments as $attachment)
                        <div class="list-group-item d-flex justify-content-between align-items-center">
                            <div>
                                {{ $loop->iteration }}.
                                @if ($attachment->hasFile())
                                    <a href="{{ route('admin.correspondence.attachments.show', [$letter, $attachment]) }}">{{ $attachment->name }}</a>
                                    <span class="text-secondary small ms-1">{{ $attachment->original_name }}, {{ number_format($attachment->size / 1024, 0, ',', '.') }} KB</span>
                                @else
                                    {{ $attachment->name }} <span class="badge bg-secondary-lt ms-1">fiziksel</span>
                                @endif
                            </div>
                            @if ($letter->isEditable() && $canManage)
                                <form method="POST" action="{{ route('admin.correspondence.attachments.destroy', [$letter, $attachment]) }}">@csrf @method('DELETE')<button type="submit" class="btn btn-sm btn-outline-danger" title="Eki kaldır"><i class="ti ti-trash"></i></button></form>
                            @endif
                        </div>
                    @empty
                        <div class="list-group-item text-secondary">Ek yok.</div>
                    @endforelse
                </div>
                @if ($letter->isEditable() && $canManage)
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.correspondence.attachments.store', $letter) }}" enctype="multipart/form-data" class="row g-2 align-items-end">
                            @csrf
                            <div class="col-md-5"><label class="form-label required">Ekin adı</label><input name="name" class="form-control @error('name') is-invalid @enderror" value="{{ old('name') }}" maxlength="255" required></div>
                            <div class="col-md-5"><label class="form-label">Dosya</label><input type="file" name="file" class="form-control @error('file') is-invalid @enderror">@error('file')<div class="invalid-feedback">{{ $message }}</div>@enderror</div>
                            <div class="col-md-2"><button type="submit" class="btn btn-primary w-100">Ekle</button></div>
                            <div class="col-12 form-hint">Dosya seçilmezse ek fiziksel (kitap, CD gibi) sayılır.</div>
                        </form>
                    </div>
                @endif
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">Dağıtım</h3></div>
                <div class="list-group list-group-flush">
                    @foreach ($letter->recipients as $recipient)
                        <div class="list-group-item">
                            <div class="d-flex justify-content-between"><strong>{{ $recipient->name }}</strong><span class="badge bg-{{ $recipient->delivery === LetterRecipient::ACTION ? 'blue' : 'secondary' }}-lt">{{ LetterRecipient::DELIVERIES[$recipient->delivery] }}</span></div>
                            <div class="text-secondary small">{{ LetterRecipient::KINDS[$recipient->kind] }}@if ($recipient->identifier) · {{ LetterRecipient::IDENTIFIERS[$recipient->kind] }}: {{ $recipient->identifier }}@endif</div>
                            @if ($recipient->address)<div class="text-secondary small">{{ $recipient->address }}</div>@endif
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="card mb-3">
                <div class="card-header"><h3 class="card-title">İmzacılar</h3></div>
                <div class="list-group list-group-flush">
                    @foreach ($letter->signers ?? [] as $signer)
                        <div class="list-group-item">{{ $signer['first_name'] }} {{ $signer['last_name'] }}@if ($signer['title'] ?? null)<div class="text-secondary small">{{ $signer['title'] }}</div>@endif</div>
                    @endforeach
                </div>
                <div class="card-footer text-secondary small">
                    Yazan: {{ $letter->creator?->name ?? '—' }}, {{ $letter->created_at->format('d.m.Y') }}
                    @if ($letter->approved_at)<br>Onaylayan: {{ $letter->approver?->name ?? '—' }}, {{ $letter->approved_at->format('d.m.Y H:i') }}@endif
                </div>
            </div>

            @if ($letter->isNumbered() || $package)
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">e-Yazışma paketi</h3>@if ($package)<div class="card-actions"><span class="badge bg-secondary-lt">{{ $old ? '2.0 öncesi' : '2.x' }}</span></div>@endif</div>
                    <div class="card-body">
                        @if ($canManage)
                            {{-- The browser extension replaces this text with its own status; if it stays, the extension is missing or not allowed on this portal. --}}
                            <div id="eyazisma-extension-status" role="status" class="alert alert-warning small">
                                Tarayıcı eklentisi algılanmadı. İmza uygulamasını eklenti başlatır: <a href="https://addons.mozilla.org/tr/firefox/addon/eyazisma-imza/" target="_blank" rel="noopener noreferrer">Firefox</a> veya <a href="https://chromewebstore.google.com/detail/gdfahhbabofbcgphlaphkegkbahlgjhl" target="_blank" rel="noopener noreferrer">Chrome</a> için e-Yazışma İmza eklentisini ve <a href="https://github.com/bahricanli/eyazisma-imza/releases" target="_blank" rel="noopener noreferrer">imza uygulamasını</a> kurun. Eklenti kuruluysa simgesine tıklayıp bu portala izin verin ve sayfayı yenileyin.
                            </div>
                        @endif
                        @if (! $package)
                            <p class="text-secondary small">Önce QR kod ve doğrulama bilgileri eklenmiş PDF imzalanır. EYP, yüklenen imzalı PDF ve eklerden oluşturulur; paket imzası sonraki adımdır.</p>
                            @unless ($letter->hasSignedPdf())
                                <div class="mb-3">
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('admin.correspondence.pdf.signing-link', $letter) }}" id="pdf-signing-form" class="mb-3" rel="noopener" data-addresses='@json([SigningSession::APPLICATION_SECURE, SigningSession::APPLICATION])'>
                                            @csrf
                                            <input type="hidden" name="launch_application" value="0">
                                            <input type="hidden" name="application_address" value="{{ SigningSession::APPLICATION }}">
                                            <label class="form-label required">PDF nasıl imzalanacak?</label>
                                            <label class="form-check"><input class="form-check-input" type="radio" name="signing_method" value="external" required><span class="form-check-label">Dışarıda imzala ve yükle</span></label>
                                            <label class="form-check"><input class="form-check-input" type="radio" name="signing_method" value="application" required><span class="form-check-label">İmza uygulamasıyla imzala</span></label>
                                            @error('signing_method')<div class="text-danger">{{ $message }}</div>@enderror
                                            <button class="btn btn-primary w-100">İmzalama sürecini başlat</button>
                                            <p class="form-hint mt-2" id="pdf-signing-status" aria-live="polite">İmza uygulaması seçildiğinde bu bilgisayardaki imza ekranı yeni sekmede açılır. İşlem bitince bu sayfayı yenileyin.</p>
                                        </form>
                                    @endif
                                    @if (session('pdf-signing-link'))
                                        <div id="signing-app" data-addresses='@json([SigningSession::APPLICATION_SECURE, SigningSession::APPLICATION])' hidden></div>
                                        <a href="{{ SigningSession::applicationUrl(session('pdf-signing-link')) }}" data-signing-link="{{ session('pdf-signing-link') }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary w-100 mb-2">İmza uygulamasında PDF’i imzala</a>
                                        <input class="form-control form-control-sm" value="{{ session('pdf-signing-link') }}" readonly onclick="this.select()">
                                        <div class="form-hint">Bağlantı {{ SigningSession::LIFETIME }} dakika geçerli ve tek kullanımlıktır. İşlem bitince sayfayı yenileyin.</div>
                                    @endif
                                    <details @if (session('pdf-signing-external') || $errors->has('signed_pdf')) open @endif>
                                    <summary class="mb-2">Dışarıda imzala ve yükle</summary>
                                    <p class="text-secondary small">İmza uygulamasında artımlı kaydetme kullanın.</p>
                                    <a href="{{ route('admin.correspondence.pdf', [$letter, 'original' => 1]) }}" target="_blank" class="btn btn-outline-primary w-100">1. İmzalanacak PDF’i indir</a>
                                    @if ($canManage)
                                        <form method="POST" action="{{ route('admin.correspondence.signed-pdf', $letter) }}" enctype="multipart/form-data" class="mt-3">
                                            @csrf
                                            <label class="form-label" for="signed_pdf">2. Dışarıda imzalanan PDF’i yükle</label>
                                            <input id="signed_pdf" type="file" name="signed_pdf" accept="application/pdf" required class="form-control @error('signed_pdf') is-invalid @enderror">
                                            @error('signed_pdf')<div class="invalid-feedback">{{ $message }}</div>@enderror
                                            <div class="form-hint">İmzanın içerik bütünlüğü kontrol edilir. Sertifika güveni ve iptal durumu imza uygulamasında doğrulanmalıdır.</div>
                                            <button class="btn btn-primary w-100 mt-2">İmzalı PDF’i kaydet</button>
                                        </form>
                                    @endif
                                    </details>
                                </div>
                            @else
                                <p class="text-success">İmzalı PDF yüklendi; EYP oluşturulabilir.</p>
                            @endunless
                            @if ($canManage && $letter->hasSignedPdf())
                                <form method="POST" action="{{ route('admin.correspondence.package.store', $letter) }}">
                                    @csrf
                                    @foreach ([LetterPackage::OLD => ['2.0 öncesi: yalnız e-imza', 'İmzayla tamamlanır; kurumlar arasında hâlâ kullanılır.'], LetterPackage::CURRENT => ['2.x: e-imza ve e-mühür', 'Kurumun e-mührü de gerekir.']] as $value => [$label, $hint])
                                        <label class="form-check">
                                            <input type="radio" class="form-check-input" name="generation" value="{{ $value }}" @checked($generation === (string) $value)>
                                            <span class="form-check-label">{{ $label }}</span>
                                            <span class="form-check-description">{{ $hint }}</span>
                                        </label>
                                    @endforeach
                                    <button type="submit" class="btn btn-primary w-100 mt-2">Paketi oluştur</button>
                                </form>
                            @endif
                        @else
                            <div id="signing-app" class="small text-secondary mb-3" data-addresses='@json([SigningSession::APPLICATION_SECURE, SigningSession::APPLICATION])' hidden></div>

                            @if (session('signing-link'))
                                <div class="alert alert-info" role="alert">
                                    <div class="mb-1"><strong>İmza bağlantısı</strong> ({{ SigningSession::LIFETIME }} dakika geçerli, tek kullanımlık)</div>
                                    <a href="{{ SigningSession::applicationUrl(session('signing-link')) }}" data-signing-link="{{ session('signing-link') }}" target="_blank" rel="noopener noreferrer" class="btn btn-primary w-100 mb-2"><i class="ti ti-external-link me-1"></i>İmza uygulamasında aç</a>
                                    <input class="form-control form-control-sm font-monospace" value="{{ session('signing-link') }}" readonly onclick="this.select()">
                                    <div class="small mt-1">İmza uygulaması bu bilgisayarda açık olmalıdır. Açılmazsa bağlantıyı kopyalayıp uygulamaya yapıştırın. Bağlantı yeniden gösterilmez.</div>
                                </div>
                            @endif

                            <ol class="mb-3 ps-3">
                                <li class="mb-3">
                                    <strong>Elektronik imza</strong>
                                    @if ($step === SigningSession::SIGNATURE)
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('admin.correspondence.package.signing-link', $letter) }}" class="my-2">@csrf<button type="submit" class="btn btn-primary w-100"><i class="ti ti-writing-sign me-1"></i>İmza uygulamasıyla imzala</button></form>
                                        @endif
                                        <details class="small">
                                            <summary class="text-secondary">Elle: özeti indir, imzala, yükle</summary>
                                            <div class="text-secondary my-2">Paket özetini imzacının e-imzasıyla, içeriği kendi içinde taşıyan (tümleşik) CAdES olarak imzalayın ve imza dosyasını yükleyin.</div>
                                            <a href="{{ route('admin.correspondence.package.digest', $letter) }}" class="btn btn-sm btn-outline-primary mb-2"><i class="ti ti-download me-1"></i>PaketOzeti.xml</a>
                                            @if ($canManage)
                                                <form method="POST" action="{{ route('admin.correspondence.package.sign', $letter) }}" enctype="multipart/form-data" class="d-flex gap-2">@csrf<input type="file" name="file" class="form-control form-control-sm" required><button type="submit" class="btn btn-sm btn-primary">Yükle</button></form>
                                            @endif
                                        </details>
                                    @else
                                        <span class="badge bg-green-lt ms-1">eklendi</span>
                                    @endif
                                </li>
                                <li>
                                    <strong>Elektronik mühür</strong>
                                    @if ($old)<span class="text-secondary small ms-1">isteğe bağlı</span>@endif
                                    @if ($step === SigningSession::SEAL)
                                        @if ($canManage)
                                            <form method="POST" action="{{ route('admin.correspondence.package.signing-link', $letter) }}" class="my-2">@csrf<button type="submit" class="btn {{ $old ? 'btn-outline-primary' : 'btn-primary' }} w-100"><i class="ti ti-rubber-stamp me-1"></i>İmza uygulamasıyla mühürle</button></form>
                                        @endif
                                        <details class="small">
                                            <summary class="text-secondary">Elle: özeti indir, mühürle, yükle</summary>
                                            <div class="text-secondary my-2">Nihai özeti kurumun e-mührüyle tümleşik CAdES olarak imzalayın ve mühür dosyasını yükleyin.</div>
                                            <a href="{{ route('admin.correspondence.package.final-digest', $letter) }}" class="btn btn-sm btn-outline-primary mb-2"><i class="ti ti-download me-1"></i>NihaiOzet.xml</a>
                                            @if ($canManage)
                                                <form method="POST" action="{{ route('admin.correspondence.package.seal', $letter) }}" enctype="multipart/form-data" class="d-flex gap-2">@csrf<input type="file" name="file" class="form-control form-control-sm" required><button type="submit" class="btn btn-sm btn-primary">Yükle</button></form>
                                            @endif
                                        </details>
                                    @elseif ($step === null)
                                        <span class="badge bg-green-lt ms-1">eklendi</span>
                                    @else
                                        <span class="text-secondary small ms-1">imzadan sonra</span>
                                    @endif
                                </li>
                            </ol>

                            @if ($complete)
                                @foreach ($report->hatalar() as $finding)
                                    <div class="alert alert-danger py-2 small" role="alert">{{ $finding }}</div>
                                @endforeach
                                @foreach ($report->uyarilar() as $finding)
                                    <div class="alert alert-warning py-2 small" role="alert">{{ $finding }}</div>
                                @endforeach
                                @if ($report->gecerli())
                                    <div class="alert alert-success py-2 small" role="alert">Paket tamamlandı: yapısı ve özet değerleri geçerli. İmzanın kriptografik doğrulaması burada yapılmaz.</div>
                                @endif
                            @endif

                            <div class="btn-list">
                                <a href="{{ route('admin.correspondence.package', $letter) }}" class="btn btn-outline-primary"><i class="ti ti-download me-1"></i>{{ $complete ? '.eyp indir' : 'Tamamlanmamış paketi indir' }}</a>
                                @if ($canManage && $step !== null)
                                    <form method="POST" action="{{ route('admin.correspondence.package.destroy', $letter) }}" onsubmit="return confirm('Paket silinsin mi? Eklenen imza da silinir.')">@csrf @method('DELETE')<button type="submit" class="btn btn-outline-danger">Paketi sil</button></form>
                                @endif
                            </div>
                        @endif
                    </div>
                </div>
            @endif

            @if ($letter->isEditable() && $canManage)
                <form method="POST" action="{{ route('admin.correspondence.destroy', $letter) }}" onsubmit="return confirm('Taslak silinsin mi?')">@csrf @method('DELETE')<button type="submit" class="btn btn-outline-danger w-100 mb-3">Taslağı sil</button></form>
            @endif

            @if ($letter->isNumbered() && $canApprove)
                <div class="card mb-3">
                    <div class="card-header"><h3 class="card-title">Yazıyı iptal et</h3></div>
                    <div class="card-body">
                        <form method="POST" action="{{ route('admin.correspondence.cancel', $letter) }}" onsubmit="return confirm('Yazı iptal edilsin mi? Sayısı yeniden kullanılmaz.')">
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

<script>
    (function () {
        var form = document.getElementById('pdf-signing-form');
        var box = document.getElementById('signing-app') || document.getElementById('pdf-signing-status');
        if (form) {
            form.addEventListener('submit', function () {
                var choice = form.querySelector('[name="signing_method"]:checked');
                var application = choice && choice.value === 'application';
                form.target = application ? '_blank' : '_self';
                form.elements.launch_application.value = application ? '1' : '0';
                if (application && box) {
                    box.textContent = 'İmza ekranı yeni sekmede açılıyor. İmzalama bitince bu sayfayı yenileyin.';
                    box.hidden = false;
                }
            });
        }
        if (!box || !window.fetch) { return; }
        var addresses = JSON.parse((form || box).dataset.addresses);

        function ask(index) {
            if (index >= addresses.length) {
                box.textContent = 'İmza uygulaması şu an açık değil. Tarayıcı eklentisi kuruluysa imza düğmesine bastığınızda uygulamayı kendisi başlatır; eklenti yoksa uygulamayı elle açın ve varsa yerel ağ erişimi iznini verin.';
                box.hidden = false;
                return;
            }
            var controller = window.AbortController ? new AbortController() : null;
            var timer = controller ? setTimeout(function () { controller.abort(); }, 2000) : null;
            fetch(addresses[index] + 'status', controller ? { signal: controller.signal } : {}).then(function (response) {
                if (!response.ok) { throw new Error('status'); }
                return response.json();
            }).then(function (status) {
                if (timer) { clearTimeout(timer); }
                if (status.name !== 'eyazisma-imza') { throw new Error('application'); }
                box.textContent = 'İmza uygulaması hazır (sürüm ' + status.version + '). İmzalama bitince bu sayfayı yenileyin.';
                box.hidden = false;
                if (form) { form.elements.application_address.value = addresses[index]; }
                document.querySelectorAll('[data-signing-link]').forEach(function (button) {
                    button.href = addresses[index] + '?link=' + encodeURIComponent(button.dataset.signingLink);
                });
            }).catch(function () {
                if (timer) { clearTimeout(timer); }
                ask(index + 1);
            });
        }

        ask(0);
    })();
</script>
@endsection
