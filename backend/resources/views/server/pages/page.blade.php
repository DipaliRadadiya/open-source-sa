{{--
    The one page the panel serves from somebody else's document root.

    Two callers, two audiences. The placeholder is read by the operator who
    just created a site and wants to know it works; the disabled page is read
    by that site's visitors, who did not choose to be here. Same markup so they
    look like one product, different words.

    Three rules this file exists to hold:

    **Nothing is fetched.** No CDN, no web font, no analytics, no favicon
    request. A server with no outbound network — which a firewalled box behind
    a NAT gateway routinely is — renders a page with a dead stylesheet, and a
    broken layout is a worse answer than the unstyled text this replaces. It
    also means the page cannot report a visitor to anyone.

    **Nothing is disclosed.** No PHP version, no server software, no paths, no
    `phpinfo()`. This is publicly reachable from the second the site
    provisions, and a blank site can sit for months — a version string here is
    a free gift to anything scanning for known-vulnerable builds. The default
    nginx and Apache pages name no version either.

    **Everything is inline.** One file, written through `tee` into a directory
    that may contain nothing else. A stylesheet in a second file would be a
    second write, a second thing to own, and a 404 the first time somebody
    copies the html somewhere.
--}}
<!doctype html>
<html lang="{{ $locale }}">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
{{-- A placeholder that gets indexed outranks the real site that replaces it. --}}
<meta name="robots" content="noindex, nofollow">
<title>{{ $title }}</title>
<style>
  /* System fonts only — see the note above about fetching nothing. */
  :root {
    color-scheme: light dark;
    --bg: #fbfbfd;
    --panel: #ffffff;
    --line: #e6e6ec;
    --ink: #16161a;
    --muted: #6b6b76;
    --accent: #3d5afe;
    --ok-ink: #0f6d43;
    --ok-bg: #e7f6ee;
    --ok-line: #bfe6d2;
  }
  @media (prefers-color-scheme: dark) {
    :root {
      --bg: #0e0e12;
      --panel: #16161c;
      --line: #26262f;
      --ink: #f2f2f5;
      --muted: #9a9aa6;
      --accent: #8c9eff;
      --ok-ink: #7fe0ad;
      --ok-bg: #10281e;
      --ok-line: #1d4634;
    }
  }
  * { box-sizing: border-box; }
  body {
    margin: 0;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: 24px;
    background: var(--bg);
    color: var(--ink);
    font: 15px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto,
          "Helvetica Neue", Arial, "Noto Sans", sans-serif;
    -webkit-font-smoothing: antialiased;
  }
  .card {
    width: 100%;
    max-width: 34rem;
    background: var(--panel);
    border: 1px solid var(--line);
    border-radius: 14px;
    padding: 32px;
    box-shadow: 0 1px 2px rgba(0,0,0,.04), 0 12px 32px -12px rgba(0,0,0,.12);
  }
  .mark {
    width: 38px; height: 38px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 10px;
    background: color-mix(in srgb, var(--accent) 12%, transparent);
    color: var(--accent);
    margin-bottom: 20px;
  }
  /* The disabled page is a state, not an invitation. The accent colour reads
     as something to act on, and the person seeing this page is a visitor who
     cannot act on anything — so it goes neutral rather than blue or red.
     Red would also be wrong: nothing is broken, the owner turned it off. */
  .mark.muted {
    background: color-mix(in srgb, var(--muted) 12%, transparent);
    color: var(--muted);
  }
  h1 {
    margin: 0 0 6px;
    font-size: 1.3rem;
    line-height: 1.3;
    font-weight: 600;
    letter-spacing: -.01em;
    /* A long domain must wrap rather than widen the card off-screen. */
    overflow-wrap: anywhere;
  }
  p { margin: 0; color: var(--muted); }
  .status {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    margin-top: 22px;
    padding: 7px 12px;
    border-radius: 999px;
    font-size: .8125rem;
    background: var(--ok-bg);
    color: var(--ok-ink);
    border: 1px solid var(--ok-line);
  }
  .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }
  .steps { margin: 26px 0 0; padding: 0; list-style: none; border-top: 1px solid var(--line); }
  .steps li { display: flex; gap: 12px; padding: 14px 0 0; }
  .steps li + li { padding-top: 12px; }
  .num {
    flex: none;
    width: 22px; height: 22px;
    display: flex; align-items: center; justify-content: center;
    border-radius: 6px;
    border: 1px solid var(--line);
    font-size: .75rem;
    color: var(--muted);
  }
  .steps strong { display: block; font-weight: 550; color: var(--ink); }
  .steps span { font-size: .875rem; color: var(--muted); }
  .foot { margin-top: 26px; padding-top: 16px; border-top: 1px solid var(--line); font-size: .8125rem; color: var(--muted); }
</style>
</head>
<body>
  <main class="card">
    <div class="mark @if ($kind === 'disabled') muted @endif" aria-hidden="true">
      {{-- Inline, because an <img> would be a request and a missing file. --}}
      @if ($kind === 'disabled')
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <circle cx="12" cy="12" r="9"/><path d="M12 8v5"/><path d="M12 16.5h.01"/>
        </svg>
      @else
        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
          <path d="M3 8.5 12 3l9 5.5v7L12 21l-9-5.5z"/><path d="M12 12 3 8.5"/><path d="m12 12 9-3.5"/><path d="M12 12v9"/>
        </svg>
      @endif
    </div>

    <h1>{{ $heading }}</h1>
    <p>{{ $lede }}</p>

    @if ($dynamic !== null)
      {{-- Rendered by PHP on the site itself, so it is evidence rather than a
           claim: a static file cannot print the time it was asked for. This is
           the question an operator actually has about a new PHP site. --}}
      <span class="status"><span class="dot"></span>{!! $dynamic !!}</span>
    @endif

    @if ($steps !== [])
      <ul class="steps">
        @foreach ($steps as $index => $step)
          <li>
            <span class="num">{{ $index + 1 }}</span>
            <span><strong>{{ $step['title'] }}</strong><span>{{ $step['body'] }}</span></span>
          </li>
        @endforeach
      </ul>
    @endif

    <p class="foot">{{ $foot }}</p>
  </main>
</body>
</html>
