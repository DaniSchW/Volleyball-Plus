<?php /** Shared frame of the installer pages (plain PHP: Blade's compiled-view cache may not be writable yet). */ ?>
<!doctype html>
<html lang="<?= e(app()->getLocale()) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title><?= e($title) ?></title>
<style>
  :root { --brand: #1d4ed8; --bg: #f8fafc; --card: #fff; --text: #0f172a; --muted: #475569; --line: #e2e8f0; --ok: #15803d; --bad: #b91c1c; --warn: #a16207; }
  * { box-sizing: border-box; }
  body { margin: 0; font: 16px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; background: var(--bg); color: var(--text); }
  main { max-width: 640px; margin: 0 auto; padding: 24px 16px 48px; }
  h1 { font-size: 1.5rem; margin: 0 0 8px; }
  h2 { font-size: 1.05rem; margin: 0 0 12px; }
  p { margin: 0 0 12px; color: var(--muted); }
  section { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 16px; margin-top: 16px; }
  label { display: block; font-weight: 600; font-size: .9rem; margin: 12px 0 4px; }
  input, select { width: 100%; padding: 10px 12px; font: inherit; border: 1px solid #cbd5e1; border-radius: 8px; background: #fff; }
  .row { display: grid; grid-template-columns: 2fr 1fr; gap: 12px; }
  .radio { display: flex; gap: 8px; align-items: center; font-weight: 400; }
  .radio input { width: auto; }
  .help { font-size: .85rem; color: var(--muted); margin-top: 4px; }
  ul.checks { list-style: none; padding: 0; margin: 0; }
  ul.checks li { display: flex; justify-content: space-between; gap: 12px; padding: 6px 0; border-bottom: 1px solid var(--line); font-size: .9rem; }
  .ok { color: var(--ok); } .bad { color: var(--bad); } .warn { color: var(--warn); }
  .errors { border-color: #fecaca; background: #fef2f2; }
  .errors ul { margin: 0; padding-left: 20px; color: var(--bad); }
  button, .button { display: inline-block; margin-top: 20px; width: 100%; padding: 12px; font: inherit; font-weight: 600; color: #fff; background: var(--brand); border: 0; border-radius: 8px; text-align: center; text-decoration: none; cursor: pointer; }
  button:disabled { opacity: .5; cursor: not-allowed; }
  pre, code { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .85rem; }
  pre { white-space: pre-wrap; word-break: break-all; background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 8px; }
  code.block { display: block; padding: 10px; background: #f1f5f9; border-radius: 8px; word-break: break-all; }
</style>
</head>
<body>
<main>
<?= $content ?>
</main>
</body>
</html>
