<!DOCTYPE html>
<html lang="zh-CN">
@php
  $manifestPath = public_path('assets/panel/manifest.json');
  $manifest = is_file($manifestPath) ? json_decode(file_get_contents($manifestPath), true) : null;
  $entry = is_array($manifest) ? ($manifest['index.html'] ?? null) : null;
  $bootstrap = [
    'title' => $title ?? 'SimpleXboard',
    'version' => $version ?? null,
    'secure_path' => $secure_path,
    'build' => $manifest['version'] ?? null,
  ];
@endphp
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
  <meta name="color-scheme" content="light" />
  <meta name="robots" content="noindex, nofollow" />
  <title>{{ $title ?? 'SimpleXboard' }}</title>
  @if(is_array($entry) && !empty($entry['file']))
    @foreach($entry['css'] ?? [] as $stylesheet)
      <link rel="stylesheet" href="/assets/panel/{{ $stylesheet }}" integrity="{{ $manifest['integrity'][$stylesheet] ?? '' }}" crossorigin="anonymous" />
    @endforeach
    <script type="module" src="/assets/panel/{{ $entry['file'] }}" integrity="{{ $manifest['integrity'][$entry['file']] ?? '' }}" crossorigin="anonymous"></script>
  @endif
</head>
<body>
  <script type="application/json" id="panel-bootstrap">{!! json_encode($bootstrap, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) !!}</script>
  <div id="app">
    @if(!is_array($entry) || empty($entry['file']))
      <main style="max-width:40rem;margin:12vh auto;padding:2rem;font-family:system-ui;line-height:1.8">
        <h1>管理界面尚未构建</h1>
        <p>请在项目目录执行构建命令，然后刷新此页面。</p>
        <pre>npm --prefix panel ci
npm --prefix panel run build</pre>
      </main>
    @endif
  </div>
  <noscript>管理台需要启用 JavaScript。成员订阅服务不受影响。</noscript>
</body>
</html>
