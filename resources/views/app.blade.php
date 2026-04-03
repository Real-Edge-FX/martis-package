<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('martis.brand.name', 'Martis') }} Admin</title>
    <script>
        window.MartisConfig = {
            locale: "{{ config('martis.locale', 'en') }}",
            brand: "{{ config('martis.brand.name', 'Martis') }}",
            basePath: "/{{ config('martis.path', 'admin') }}"
        };
    </script>
    @vite(['resources/js/app.tsx'], 'vendor/martis')
</head>
<body>
    <div id="martis-root"></div>
</body>
</html>
