<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>{{ $title }}</title>
    <style{!! RobinsonRyan\Yikes\Support\CspNonce::attr() !!}>html, body { margin: 0; padding: 0; }</style>
</head>
<body>
    <div id="yikes-app" data-component="{{ $component }}" data-props="{{ json_encode($props) }}"></div>
    {!! RobinsonRyan\Yikes\Support\YikesAssets::injectHtml(request()) !!}
</body>
</html>
