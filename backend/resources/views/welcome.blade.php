{{--
    What the API's own address shows in a browser.

    It was Laravel's starter page — another product's name, colours and links
    on the panel's API host. Now the panel's branding (name and brand colour,
    from config/branding.php) and two ways on: the panel itself and the API
    reference. Same rules as the site pages: nothing fetched, nothing about
    the server disclosed.
--}}
@php($brand = \App\Support\BrandColor::value())
@php($panel = (string) config('server.storage.panel_url'))
<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>{{ config('branding.name') }}</title>
<style>
  :root { color-scheme: light dark; --bg: #fbfbfd; --panel: #ffffff; --line: #e6e6ec; --ink: #16161a; --muted: #6b6b76; --brand: {{ $brand }}; }
  @media (prefers-color-scheme: dark) {
    :root { --bg: #0e0e12; --panel: #16161c; --line: #26262f; --ink: #f2f2f5; --muted: #9a9aa6; --brand: color-mix(in srgb, {{ $brand }} 70%, white); }
  }
  * { box-sizing: border-box; }
  body { margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
         background: var(--bg); color: var(--ink); font: 15px/1.6 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; }
  main { width: 100%; max-width: 440px; background: var(--panel); border: 1px solid var(--line); border-radius: 14px; padding: 32px;
         border-top: 4px solid var(--brand); }
  h1 { margin: 0 0 8px; font-size: 22px; }
  p { margin: 0 0 24px; color: var(--muted); }
  a { display: inline-block; margin-right: 12px; padding: 8px 14px; border-radius: 8px; text-decoration: none; font-weight: 600; }
  /* Dark text, not white: white on a light brand colour such as #35B87A is unreadable. */
  a.primary { background: var(--brand); color: #16161a; }
  a.secondary { color: var(--brand); border: 1px solid var(--line); }
</style>
</head>
<body>
<main>
  <h1>{{ config('branding.name') }}</h1>
  <p>{{ __('welcome.lede') }}</p>
  @if ($panel !== '')
    <a class="primary" href="{{ $panel }}">{{ __('welcome.open_panel') }}</a>
  @endif
  <a class="secondary" href="{{ url('/docs/api-reference') }}">{{ __('welcome.api_reference') }}</a>
</main>
</body>
</html>
