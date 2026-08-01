<?php

/**
 * Developer error page. Only ever rendered when debug is on, because everything that
 * makes it useful - messages, paths, source, request data - is exactly what must never
 * reach the public.
 *
 * Rendered by ErrorPage::debug(). Self contained on purpose: one file, inline css and
 * script, no request to anything. The page that renders a broken application cannot
 * afford to depend on the application.
 *
 * Available: $code, $title, $description, $tone, $exceptions, $groups, $reference,
 * $report, $datetime, $esc.
 *
 * @var int $code
 * @var string $title
 * @var ?string $description
 * @var string $tone
 * @var array<int, array<string, mixed>> $exceptions
 * @var array<string, array<string, string>> $groups
 * @var string $reference
 * @var string $report
 * @var string $datetime
 * @var callable $esc
 */

$first = $exceptions[0] ?? null;
$tabs = array_keys($groups);

?>
<!DOCTYPE html>
<html lang="en" data-tone="<?= $esc($tone) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $esc($code) ?> <?= $esc($title) ?> - <?= $esc($first['class'] ?? 'Error') ?></title>
<style>
:root {
    --bg: #f4f5f7;
    --surface: #ffffff;
    --surface-2: #f8f9fb;
    --fg: #10131a;
    --muted: #5c6472;
    --faint: #8a92a1;
    --rule: rgba(15, 23, 42, 0.11);
    --accent: #dc2f39;
    --accent-soft: rgba(220, 47, 57, 0.09);
    --shadow: 0 1px 2px rgba(15, 23, 42, 0.05), 0 12px 32px -12px rgba(15, 23, 42, 0.16);
    --font: ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
    --mono: ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, "Liberation Mono", monospace;
}

html[data-tone="client"] {
    --accent: #c2760c;
    --accent-soft: rgba(194, 118, 12, 0.11);
}

html[data-tone="redirect"] {
    --accent: #2563eb;
    --accent-soft: rgba(37, 99, 235, 0.1);
}

@media (prefers-color-scheme: dark) {
    :root {
        --bg: #0a0c11;
        --surface: #12151b;
        --surface-2: #171b22;
        --fg: #e9ecf2;
        --muted: #99a2b3;
        --faint: #6f7889;
        --rule: rgba(255, 255, 255, 0.11);
        --accent: #f2686f;
        --accent-soft: rgba(242, 104, 111, 0.13);
        --shadow: 0 1px 2px rgba(0, 0, 0, 0.4), 0 14px 34px -14px rgba(0, 0, 0, 0.7);
    }

    html[data-tone="client"] {
        --accent: #eaa640;
        --accent-soft: rgba(234, 166, 64, 0.13);
    }

    html[data-tone="redirect"] {
        --accent: #6f9bff;
        --accent-soft: rgba(111, 155, 255, 0.14);
    }
}

* { box-sizing: border-box; }

body {
    margin: 0;
    font-family: var(--font);
    font-size: 15px;
    color: var(--fg);
    background-color: var(--bg);
    background-image: radial-gradient(70% 40% at 50% 0%, var(--accent-soft), transparent 70%);
    -webkit-font-smoothing: antialiased;
}

a { color: inherit; }

/* Top bar */

.topbar {
    position: sticky;
    top: 0;
    z-index: 10;
    display: flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.5rem 1rem;
    padding: 0.7rem clamp(1rem, 4vw, 2.5rem);
    border-bottom: 1px solid var(--rule);
    background: color-mix(in srgb, var(--bg) 82%, transparent);
    backdrop-filter: blur(10px);
    font-size: 0.78rem;
    color: var(--muted);
}

.topbar .brand {
    font-weight: 700;
    letter-spacing: -0.02em;
    color: var(--fg);
}

.topbar .status {
    padding: 0.15rem 0.45rem;
    border-radius: 5px;
    font-family: var(--mono);
    font-weight: 600;
    color: var(--accent);
    background: var(--accent-soft);
}

.topbar .reason {
    margin-left: -0.6rem;
    color: var(--fg);
    font-weight: 550;
}

.topbar .url {
    font-family: var(--mono);
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
    max-width: min(46vw, 40rem);
}

.topbar .spacer { flex: 1 1 auto; }

.topbar code {
    font-family: var(--mono);
    color: var(--fg);
    user-select: all;
}

button.copy {
    font: inherit;
    font-size: 0.76rem;
    padding: 0.3rem 0.7rem;
    color: var(--fg);
    background: var(--surface);
    border: 1px solid var(--rule);
    border-radius: 7px;
    cursor: pointer;
}

button.copy:hover { border-color: var(--accent); }

/* Layout */

main {
    max-width: 68rem;
    margin: 0 auto;
    padding: clamp(1.5rem, 4vw, 3rem) clamp(1rem, 4vw, 2.5rem) 5rem;
}

.hero { margin-bottom: 2.25rem; }

.kind {
    display: inline-flex;
    align-items: center;
    gap: 0.5rem;
    margin: 0 0 0.85rem;
    padding: 0.3rem 0.7rem 0.3rem 0.55rem;
    border: 1px solid var(--rule);
    border-radius: 999px;
    font-family: var(--mono);
    font-size: 0.75rem;
    background: var(--accent-soft);
}

.kind .dot {
    width: 0.45rem;
    height: 0.45rem;
    border-radius: 50%;
    background: var(--accent);
    box-shadow: 0 0 0 3px color-mix(in srgb, var(--accent) 22%, transparent);
}

.hero h1 {
    margin: 0;
    font-size: clamp(1.4rem, 3.2vw, 2rem);
    font-weight: 600;
    letter-spacing: -0.02em;
    line-height: 1.28;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.lede {
    margin: 0.85rem 0 0;
    max-width: 46rem;
    font-size: 0.95rem;
    line-height: 1.6;
    color: var(--muted);
}

.where {
    margin: 0.9rem 0 0;
    font-family: var(--mono);
    font-size: 0.82rem;
    color: var(--muted);
}

.where b {
    font-weight: 600;
    color: var(--fg);
}

/* Cards */

.card {
    margin-bottom: 1.5rem;
    border: 1px solid var(--rule);
    border-radius: 12px;
    background: var(--surface);
    box-shadow: var(--shadow);
    overflow: hidden;
}

.card > h2 {
    margin: 0;
    padding: 0.75rem 1.1rem;
    border-bottom: 1px solid var(--rule);
    font-size: 0.75rem;
    font-weight: 600;
    letter-spacing: 0.11em;
    text-transform: uppercase;
    color: var(--muted);
    background: var(--surface-2);
}

.cause {
    padding: 1.1rem;
    border-bottom: 1px solid var(--rule);
}

.cause .name {
    font-family: var(--mono);
    font-size: 0.8rem;
    color: var(--accent);
}

.cause .msg {
    margin: 0.4rem 0 0;
    font-size: 1rem;
    line-height: 1.45;
    /* driver messages carry their own line breaks - the offending sql, usually */
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

/* Source excerpts */

.code {
    font-family: var(--mono);
    font-size: 0.79rem;
    line-height: 1.65;
    overflow-x: auto;
    background: var(--surface-2);
}

.code .row { display: flex; }

.code .ln {
    flex: 0 0 3.75rem;
    padding: 0 0.75rem;
    text-align: right;
    color: var(--faint);
    user-select: none;
    border-right: 1px solid var(--rule);
}

.code .src {
    padding: 0 1rem;
    tab-size: 4;
    white-space: pre;
}

.code .is-error {
    background: var(--accent-soft);
    box-shadow: inset 3px 0 0 var(--accent);
}

.code .is-error .ln {
    color: var(--accent);
    font-weight: 600;
}

/* Frames */

.frames {
    margin: 0;
    padding: 0;
    list-style: none;
}

.frames > li { border-top: 1px solid var(--rule); }

.frames > li:first-child { border-top: 0; }

/* the card header already draws one, a source excerpt above the list does not */
.code + .frames { border-top: 1px solid var(--rule); }

.frame {
    display: flex;
    flex-wrap: wrap;
    align-items: baseline;
    gap: 0.35rem 0.75rem;
    padding: 0.55rem 1.1rem;
    font-family: var(--mono);
    font-size: 0.79rem;
    cursor: pointer;
    list-style: none;
}

.frame::-webkit-details-marker { display: none; }

.frame::before {
    content: "";
    flex: 0 0 auto;
    width: 0.4rem;
    height: 0.4rem;
    margin-top: 0.15rem;
    border-right: 1.5px solid var(--faint);
    border-bottom: 1.5px solid var(--faint);
    transform: rotate(-45deg);
    transition: transform 0.15s ease;
}

details[open] > .frame::before { transform: rotate(45deg); }

li.no-source .frame::before { visibility: hidden; }

details[open] > .frame { background: var(--surface-2); }

.frame:hover { background: var(--surface-2); }

.frame .no {
    flex: 0 0 2.25rem;
    color: var(--faint);
}

.frame .call {
    overflow-wrap: anywhere;
    color: var(--fg);
}

.frame .at {
    margin-left: auto;
    color: var(--muted);
    overflow-wrap: anywhere;
}

.frame .tag {
    padding: 0.05rem 0.35rem;
    border-radius: 4px;
    font-size: 0.66rem;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--faint);
    border: 1px solid var(--rule);
}

li.is-vendor .call,
li.is-vendor .at { color: var(--faint); }

li.no-source .frame { cursor: default; }

li.no-source .frame:hover { background: none; }

/* Context tabs */

.tabs > input { position: absolute; opacity: 0; pointer-events: none; }

.tablist {
    display: flex;
    flex-wrap: wrap;
    gap: 0.25rem;
    padding: 0.5rem;
    border-bottom: 1px solid var(--rule);
    background: var(--surface-2);
}

.tablist label {
    padding: 0.35rem 0.7rem;
    border-radius: 7px;
    font-size: 0.78rem;
    font-weight: 550;
    color: var(--muted);
    cursor: pointer;
    white-space: nowrap;
}

.tablist label:hover { color: var(--fg); }

.tablist label .n {
    margin-left: 0.35rem;
    font-family: var(--mono);
    font-size: 0.7rem;
    color: var(--faint);
}

.panel { display: none; }

.kv {
    display: grid;
    grid-template-columns: minmax(7rem, 14rem) minmax(0, 1fr);
    border-top: 1px solid var(--rule);
}

.panel > .kv:first-child { border-top: 0; }

.kv > .k {
    padding: 0.5rem 1.1rem;
    font-size: 0.78rem;
    font-weight: 550;
    color: var(--muted);
    overflow-wrap: anywhere;
    background: var(--surface-2);
}

.kv > .v {
    padding: 0.5rem 1.1rem;
    font-family: var(--mono);
    font-size: 0.78rem;
    line-height: 1.55;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.empty {
    margin: 0;
    padding: 1.1rem;
    font-size: 0.82rem;
    color: var(--faint);
}

@media (max-width: 34rem) {
    .kv { grid-template-columns: minmax(0, 1fr); }

    .kv > .v { padding-top: 0; }

    .frame .at {
        margin-left: 0;
        flex-basis: 100%;
    }
}

<?php foreach ($tabs as $index => $name) { ?>
#ctx-<?= (int) $index ?>:checked ~ .tablist label[for="ctx-<?= (int) $index ?>"] {
    color: var(--fg);
    background: var(--surface);
    box-shadow: inset 0 0 0 1px var(--rule);
}

#ctx-<?= (int) $index ?>:checked ~ .panels > .panel:nth-child(<?= (int) $index + 1 ?>) { display: block; }
<?php } ?>
</style>
</head>
<body>

<header class="topbar">
    <span class="brand">staticphp</span>
    <span class="status"><?= $esc($code) ?></span>
    <span class="reason"><?= $esc($title) ?></span>
    <?php if (empty($groups['Request']['Url']) === false) { ?>
    <span class="url"><?= $esc($groups['Request']['Method'] ?? '') ?> <?= $esc($groups['Request']['Url']) ?></span>
    <?php } ?>
    <span class="spacer"></span>
    <span>ref <code><?= $esc($reference) ?></code></span>
    <span><?= $esc($datetime) ?></span>
    <button type="button" class="copy" id="copy" hidden>Copy report</button>
</header>

<main>

<?php if ($first !== null) { ?>
    <section class="hero">
        <p class="kind"><span class="dot"></span><?= $esc($first['class']) ?><?php
            echo ($first['code'] !== 0 ? ' ' . $esc($first['code']) : '');
        ?></p>
        <h1><?= $esc($first['message']) ?></h1>
        <?php if (empty($description) === false) { ?>
        <p class="lede"><?= nl2br($esc($description)) ?></p>
        <?php } ?>
        <p class="where">in <b><?= $esc($first['short_file']) ?></b> line <b><?= $esc($first['line']) ?></b></p>
    </section>
<?php } ?>

<?php foreach ($exceptions as $index => $exception) { ?>
    <section class="card">
        <h2><?= ($index === 0 ? 'Thrown here' : 'Caused by') ?></h2>

        <?php if ($index > 0) { ?>
        <div class="cause">
            <div class="name"><?= $esc($exception['class']) ?></div>
            <p class="msg"><?= $esc($exception['message']) ?></p>
            <p class="where">in <b><?= $esc($exception['short_file']) ?></b>
                line <b><?= $esc($exception['line']) ?></b></p>
        </div>
        <?php } ?>

        <?php if (empty($exception['excerpt']) === false) { ?>
        <div class="code">
            <?php foreach ($exception['excerpt'] as $number => $source) { ?>
            <div class="row<?= ($number === $exception['line'] ? ' is-error' : '') ?>"><span
                class="ln"><?= (int) $number ?></span><span class="src"><?= $esc($source) ?></span></div>
            <?php } ?>
        </div>
        <?php } ?>

        <?php if (empty($exception['frames']) === false) { ?>
        <ol class="frames">
            <?php
            foreach ($exception['frames'] as $frame) {
                // A frame with no readable source has nothing to expand into, so it is
                // not made to look expandable
                $expandable = (empty($frame['excerpt']) === false);
                $tag = ($expandable ? 'summary' : 'div');
                $classes = trim(($frame['vendor'] ? 'is-vendor ' : '') . ($expandable ? '' : 'no-source'));
                $at = (
                    $frame['file'] === null
                    ? 'internal function'
                    : $esc($frame['short_file']) . ':' . (int) $frame['line']
                );
                ?>
            <li class="<?= $classes ?>">
                <?php if ($expandable) { ?>
                <details>
                <?php } ?>
                    <<?= $tag ?> class="frame">
                        <span class="no">#<?= (int) $frame['index'] ?></span>
                        <span class="call"><?= $esc($frame['call']) ?></span>
                        <?php if ($frame['vendor']) { ?>
                        <span class="tag">vendor</span>
                        <?php } ?>
                        <span class="at"><?= $at ?></span>
                    </<?= $tag ?>>
                <?php if ($expandable) { ?>
                    <div class="code">
                        <?php foreach ($frame['excerpt'] as $number => $source) { ?>
                        <div class="row<?= ($number === $frame['line'] ? ' is-error' : '') ?>"><span
                            class="ln"><?= (int) $number ?></span><span class="src"><?= $esc($source) ?></span></div>
                        <?php } ?>
                    </div>
                </details>
                <?php } ?>
            </li>
            <?php } ?>
        </ol>
        <?php } ?>
    </section>
<?php } ?>

    <section class="card tabs">
        <?php foreach ($tabs as $index => $name) { ?>
        <input type="radio" name="ctx" id="ctx-<?= (int) $index ?>"<?= ($index === 0 ? ' checked' : '') ?>>
        <?php } ?>

        <div class="tablist">
            <?php foreach ($tabs as $index => $name) { ?>
            <label for="ctx-<?= (int) $index ?>"><?= $esc($name) ?><span
                class="n"><?= count($groups[$name]) ?></span></label>
            <?php } ?>
        </div>

        <div class="panels">
            <?php foreach ($tabs as $name) { ?>
            <div class="panel">
                <?php if (empty($groups[$name])) { ?>
                <p class="empty">Nothing to show.</p>
                <?php } ?>
                <?php foreach ($groups[$name] as $key => $value) { ?>
                <div class="kv"><div class="k"><?= $esc($key) ?></div><div class="v"><?= $esc($value) ?></div></div>
                <?php } ?>
            </div>
            <?php } ?>
        </div>
    </section>

</main>

<pre id="report" hidden><?= $esc($report) ?></pre>

<script>
(function () {
    var button = document.getElementById('copy');
    var report = document.getElementById('report');

    if (!button || !report || !navigator.clipboard) {
        return;
    }

    button.hidden = false;
    button.addEventListener('click', function () {
        navigator.clipboard.writeText(report.textContent).then(function () {
            button.textContent = 'Copied';
            setTimeout(function () {
                button.textContent = 'Copy report';
            }, 1500);
        });
    });
})();
</script>

</body>
</html>
