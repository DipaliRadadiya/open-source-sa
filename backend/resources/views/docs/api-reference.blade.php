<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex">
    <title>API Reference</title>
    <style>
        :root {
            --fg: #1f2328; --muted: #656d76; --bg: #ffffff; --border: #d0d7de;
            --code-bg: #f6f8fa; --accent: #0969da; --th-bg: #f6f8fa;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --fg: #e6edf3; --muted: #9198a1; --bg: #0d1117; --border: #30363d;
                --code-bg: #161b22; --accent: #4493f8; --th-bg: #161b22;
            }
        }
        * { box-sizing: border-box; }
        body {
            margin: 0; background: var(--bg); color: var(--fg);
            font: 16px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
        }
        .wrap { max-width: 860px; margin: 0 auto; padding: 2.5rem 1.25rem 5rem; }
        h1, h2, h3, h4 { line-height: 1.25; margin: 1.8em 0 .6em; font-weight: 600; }
        h1 { font-size: 2rem; }
        h2 { font-size: 1.5rem; padding-bottom: .3em; border-bottom: 1px solid var(--border); }
        h3 { font-size: 1.2rem; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }
        p, ul, ol { margin: 0 0 1em; }
        code {
            font-family: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace;
            font-size: .88em; background: var(--code-bg); padding: .2em .4em; border-radius: 6px;
        }
        pre {
            background: var(--code-bg); padding: 1rem; border-radius: 8px; overflow-x: auto;
            border: 1px solid var(--border);
        }
        pre code { background: none; padding: 0; }
        table { border-collapse: collapse; width: 100%; margin: 0 0 1.2em; display: block; overflow-x: auto; }
        th, td { border: 1px solid var(--border); padding: .5em .75em; text-align: left; }
        th { background: var(--th-bg); font-weight: 600; }
        blockquote { margin: 0 0 1em; padding: 0 1em; color: var(--muted); border-left: .25em solid var(--border); }
        hr { border: 0; border-top: 1px solid var(--border); margin: 2em 0; }
        .meta { color: var(--muted); font-size: .85rem; margin-bottom: 2rem; }
    </style>
</head>
<body>
    <div class="wrap">
        <div class="meta">Live API contract — always reflects the latest committed <code>API_REFERENCE.md</code>. Raw: <a href="/docs/api-reference.md">/docs/api-reference.md</a></div>
        <article>{!! $html !!}</article>
    </div>
</body>
</html>
