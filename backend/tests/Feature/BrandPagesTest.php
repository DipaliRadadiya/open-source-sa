<?php

use Illuminate\Support\Facades\View;

/*
 * The brand colour reaches the pages the panel renders itself (2026-10-16):
 * the placeholder/disabled page written into a site, and the API host's home
 * page, which was Laravel's starter page. The colour sits inside a <style>
 * block, so only a plain hex value gets there.
 */
function renderSitePage(): string
{
    return View::make('server.pages.page', [
        'locale' => 'en', 'kind' => 'placeholder', 'title' => 'shop.test', 'heading' => 'h', 'lede' => 'l', 'foot' => 'f',
        'steps' => [], 'dynamic' => null,
    ])->render();
}

it('paints the site page with the brand colour', function () {
    config(['branding.primary_color' => '#123abc']);

    expect(renderSitePage())->toContain('--accent: #123abc;')
        ->not->toContain('#3d5afe');
});

it('refuses anything but a hex colour, so a value cannot add CSS or close the style tag', function (string $value) {
    config(['branding.primary_color' => $value]);

    expect(renderSitePage())->toContain('--accent: #35B87A;')
        ->not->toContain('display:none')->not->toContain('</style><script');
})->with(['red;}body{display:none}', '#12345', '</style><script>x</script>', '']);

it('shows the panel\'s branding at the API address instead of Laravel\'s page', function () {
    config(['branding.name' => 'Reseller One', 'branding.primary_color' => '#35B87A', 'server.storage.panel_url' => 'https://panel.example.com']);

    $this->get('/')->assertOk()
        ->assertSee('Reseller One')
        ->assertSee('--brand: #35B87A;', false)
        ->assertSee('https://panel.example.com', false)
        ->assertSee(__('welcome.api_reference'))
        ->assertDontSee('Laravel');
});
