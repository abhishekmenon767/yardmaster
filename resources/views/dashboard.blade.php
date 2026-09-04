<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Yardmaster</title>
    <meta name="yardmaster-base" content="{{ $basePath }}">
    <meta name="yardmaster-csrf" content="{{ csrf_token() }}">
    <link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🚦</text></svg>">
    <link rel="stylesheet" href="{{ $basePath }}/yardmaster.css?v={{ $version }}">
</head>
<body>
    <div id="yardmaster"></div>
    <script src="{{ $basePath }}/yardmaster.js?v={{ $version }}"></script>
</body>
</html>
