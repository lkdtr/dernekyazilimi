<?php

namespace Modules\Correspondence\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Modules\Correspondence\Support\CorrespondenceSettings;
use Modules\Correspondence\Support\LetterPackage;
use Modules\Correspondence\Support\Numbering;

class SettingsController extends Controller
{
    public function edit(CorrespondenceSettings $settings): View
    {
        return view('correspondence::admin.settings', [
            'settings' => $settings,
            'example' => Numbering::format($settings->numberFormat(), now()->year, 41, '010.06', $settings->registryNumber()),
            'incomingExample' => Numbering::format($settings->incomingFormat(), now()->year, 17, null, $settings->registryNumber()),
        ]);
    }

    public function update(Request $request, CorrespondenceSettings $settings): RedirectResponse
    {
        $registry = function ($attribute, $value, $fail) use ($settings) {
            if (str_contains((string) $value, '{kutuk}') && ! $settings->registryNumber()) {
                $fail('Sayı biçiminde {kutuk} kullanmak için önce kurum ayarlarında dernek kütük numarası yazılmalıdır.');
            }
        };
        $data = $request->validate([
            'number_format' => ['required', 'string', 'max:60', 'regex:/\{sira(:\d)?\}/', $registry],
            'incoming_format' => ['nullable', 'string', 'max:60', 'regex:/\{sira(:\d)?\}/', $registry],
            'start_number' => ['required', 'integer', 'between:1,1000000'],
            'package_generation' => ['nullable', Rule::in([LetterPackage::OLD, LetterPackage::CURRENT])],
            'verification_url' => ['nullable', 'url:https', 'max:255', 'not_regex:/[?#]/'],
            'tsa_url' => ['nullable', 'url:http,https', 'max:255'],
            'tsa_user' => ['nullable', 'string', 'max:100'],
            'tsa_password' => ['nullable', 'string', 'max:200'],
        ], ['number_format.regex' => 'Sayı biçiminde {sira} bulunmalıdır.', 'incoming_format.regex' => 'Gelen evrak sayı biçiminde {sira} bulunmalıdır.'], [
            'number_format' => 'Sayı biçimi', 'incoming_format' => 'Gelen evrak sayı biçimi','start_number' => 'Başlangıç numarası', 'verification_url' => 'Belge doğrulama adresi',
            'tsa_url' => 'Zaman damgası adresi', 'tsa_user' => 'Zaman damgası kullanıcısı', 'tsa_password' => 'Zaman damgası parolası',
        ]);

        $settings->save([
            'correspondence_number_format' => $data['number_format'],
            'correspondence_incoming_format' => $data['incoming_format'] ?? null,
            'correspondence_start_number' => (string) $data['start_number'],
            'correspondence_verification_url' => $data['verification_url'] ?? null,
            'correspondence_package_generation' => $data['package_generation'] ?? null,
        ]);
        $settings->saveTimestampService($data['tsa_url'] ?? null, $data['tsa_user'] ?? null, $data['tsa_password'] ?? null);
        $this->set_log('change', 'Yazışma ayarları güncellendi');

        return back()->with('success-status', 'Yazışma ayarları kaydedildi.');
    }
}
