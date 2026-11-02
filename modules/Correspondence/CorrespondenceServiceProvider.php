<?php

namespace Modules\Correspondence;

use App\Modules\Menu;
use App\Modules\ModuleServiceProvider;
use App\Modules\Slots;
use App\Support\Turnstile;
use Modules\Correspondence\Models\Letter;

/**
 * Outgoing official letters: written, approved and numbered, printed as a
 * PDF and packaged as an e-Yazışma package (.eyp) for signing and sealing.
 */
class CorrespondenceServiceProvider extends ModuleServiceProvider
{
    protected function name(): string
    {
        return 'correspondence';
    }

    protected function bootModule(Menu $menu, Slots $slots): void
    {
        $this->siteApi()->describe('correspondence', fn () => [
            'open' => true,
            // The site shows the widget and sends its answer as "turnstile".
            'turnstile_site_key' => app(Turnstile::class)->enabled() ? app(Turnstile::class)->siteKey() : null,
        ]);

        $this->permissions()->group('correspondence', 'Yazışmalar', 70);
        $this->trash()->register('letter', \Modules\Correspondence\Models\Letter::class);
        $this->permissions()->register('correspondence.view', 'Yazıları görebilsin', 'correspondence', 70);
        $this->permissions()->register('correspondence.manage', 'Yazı yazabilsin, e-Yazışma paketini hazırlayabilsin', 'correspondence', 71);
        $this->permissions()->register('correspondence.approve', 'Yazıyı onaylayıp sayı verebilsin, iptal edebilsin', 'correspondence', 72);
        $this->permissions()->register('correspondence.settings', 'Yazışma ayarlarını düzenleyebilsin', 'correspondence', 73);

        $menu->label('admin', 'correspondence', 'Yazışmalar', 'file-text');
        $menu->add('admin', 'correspondence', 'Giden yazılar', 'admin.correspondence', ['correspondence.view'], 70);
        $menu->add('admin', 'correspondence', 'Yazışma ayarları', 'admin.correspondence.settings', ['correspondence.settings'], 71);

        $this->dashboard()->stat('Onay bekleyen yazı', 'file-text', fn () => Letter::where('status', Letter::PENDING)->count(), 'admin.correspondence', ['correspondence.approve'], 60);
    }
}
