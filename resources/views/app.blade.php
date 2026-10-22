@php
    $theme = $page['props']['theme'] ?? null;
    $displayName = $page['props']['school']['display_name'] ?? 'Mading';
@endphp
<!DOCTYPE html>
<html lang="id"
      data-mode="{{ $theme['mode'] ?? 'system' }}"
      data-font-scale="{{ $theme['font_scale'] ?? 100 }}"
      data-reduce-motion="{{ ($theme['reduce_motion'] ?? false) ? '1' : '0' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="robots" content="noindex, nofollow">
    <meta name="app-display-name" content="{{ $displayName }}">
    <meta name="theme-color" content="#0E0B1F">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Baloo+2:wght@500;700;800&family=Fredoka:wght@500;600;700&family=Nunito:wght@400;600;700;800&display=swap" rel="stylesheet">
    @if ($theme)
        <style id="theme-vars">{!! $theme['css'] !!}</style>
    @endif
    @routes
    @vite(['resources/js/app.js'])
    @inertiaHead
</head>
<body class="min-h-dvh antialiased">
    @inertia
</body>
</html>
