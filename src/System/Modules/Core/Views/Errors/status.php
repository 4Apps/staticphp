<?php

/**
 * Public status page. Shown to everyone who is not a developer, so it says what happened
 * and nothing else - no exception message, no path, no trace.
 *
 * Rendered by ErrorPage::status(). Self contained on purpose: one file, inline css, no
 * request to anything.
 *
 * Available: $code, $title, $description, $reference, $tone, $home_url, $datetime, $esc.
 *
 * @var int $code
 * @var string $title
 * @var ?string $description
 * @var ?string $reference
 * @var string $tone
 * @var ?string $home_url
 * @var string $datetime
 * @var callable $esc
 */

?>
<!DOCTYPE html>
<html lang="en" data-tone="<?= $esc($tone) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $esc($code) ?> <?= $esc($title) ?></title>
<style>
:root {
    --bg: #f4f5f7;
    --bg-tint: rgba(15, 23, 42, 0.04);
    --fg: #10131a;
    --muted: #5c6472;
    --rule: rgba(15, 23, 42, 0.1);
    --accent: #64748b;
    --font: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
}

html[data-tone="server"] { --accent: #dc2f39; }
html[data-tone="client"] { --accent: #c2760c; }
html[data-tone="redirect"] { --accent: #2563eb; }

@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0b0d12;
        --bg-tint: rgba(255, 255, 255, 0.04);
        --fg: #e9ecf2;
        --muted: #98a1b2;
        --rule: rgba(255, 255, 255, 0.12);
        --accent: #94a3b8;
    }

    html[data-tone="server"] { --accent: #f2686f; }
    html[data-tone="client"] { --accent: #eaa640; }
    html[data-tone="redirect"] { --accent: #6f9bff; }
}

* { box-sizing: border-box; }

html, body { height: 100%; }

body {
    margin: 0;
    padding: 2rem 1.5rem;
    display: flex;
    align-items: center;
    justify-content: center;
    font-family: var(--font);
    color: var(--fg);
    background-color: var(--bg);
    background-image:
        radial-gradient(75% 55% at 50% -10%, color-mix(in srgb, var(--accent) 14%, transparent), transparent 70%),
        radial-gradient(50% 40% at 100% 100%, var(--bg-tint), transparent 70%);
    -webkit-font-smoothing: antialiased;
    text-rendering: optimizeLegibility;
}

body::before {
    content: "";
    position: fixed;
    inset: 0 0 auto 0;
    height: 3px;
    background: linear-gradient(90deg, transparent, var(--accent), transparent);
    opacity: 0.75;
}

main {
    position: relative;
    width: 100%;
    max-width: 33rem;
    text-align: center;
    z-index: 1;
}

.code {
    margin: 0;
    font-size: clamp(4.5rem, 14vw, 7rem);
    font-weight: 700;
    letter-spacing: -0.045em;
    line-height: 0.95;
    color: var(--accent);
}

@supports (background-clip: text) {
    .code {
        background-image: linear-gradient(
            170deg,
            var(--accent),
            color-mix(in srgb, var(--accent) 62%, transparent)
        );
        background-clip: text;
        -webkit-background-clip: text;
        color: transparent;
    }
}

h1 {
    margin: 0.9rem 0 0;
    font-size: clamp(1.5rem, 4vw, 2rem);
    font-weight: 600;
    letter-spacing: -0.02em;
    line-height: 1.15;
}

.lede {
    margin: 1rem auto 0;
    max-width: 28rem;
    font-size: 1.0625rem;
    line-height: 1.65;
    color: var(--muted);
}

.home {
    display: inline-block;
    margin-top: 2rem;
    padding: 0.7rem 1.4rem;
    border: 1px solid var(--rule);
    border-radius: 10px;
    font-size: 0.9rem;
    font-weight: 550;
    text-decoration: none;
    color: var(--fg);
    transition: border-color 0.15s ease, background-color 0.15s ease;
}

.home:hover,
.home:focus-visible {
    border-color: var(--accent);
    background: color-mix(in srgb, var(--accent) 9%, transparent);
}

footer {
    margin-top: 2.75rem;
    padding-top: 1.1rem;
    border-top: 1px solid var(--rule);
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem 1.25rem;
    justify-content: center;
    font-size: 0.78rem;
    color: var(--muted);
}

footer code {
    font-family: var(--mono);
    font-size: 0.78rem;
    color: var(--fg);
    user-select: all;
}
</style>
</head>
<body>
<main>
    <p class="code"><?= $esc($code) ?></p>

    <h1><?= $esc($title) ?></h1>

    <?php if (empty($description) === false) { ?>
    <p class="lede"><?= nl2br($esc($description)) ?></p>
    <?php } ?>

    <?php if (empty($home_url) === false) { ?>
    <a class="home" href="<?= $esc($home_url) ?>">Back to the homepage</a>
    <?php } ?>

    <footer>
        <?php if (empty($reference) === false) { ?>
        <span>Reference <code><?= $esc($reference) ?></code></span>
        <?php } ?>
        <span><?= $esc($datetime) ?></span>
    </footer>
</main>
</body>
</html>
