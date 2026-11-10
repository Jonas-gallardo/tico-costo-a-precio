<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title', 'Motor de Precios') · {{ config('app.name', 'TICO') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=instrument-sans:400,500,600,700" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="min-h-screen bg-slate-100 font-sans text-slate-800 antialiased">
        <header class="border-b border-slate-800 bg-slate-900">
            <div class="mx-auto flex max-w-7xl items-center justify-between gap-4 px-4 py-4 sm:px-6 lg:px-8">
                <div class="flex items-center gap-3">
                    <span class="flex h-10 w-10 items-center justify-center rounded-xl bg-emerald-500 text-lg font-bold text-white shadow-sm">
                        T
                    </span>
                    <div>
                        <p class="text-sm font-semibold leading-tight text-white">TICO</p>
                        <p class="text-xs text-slate-400">Administración de precios</p>
                    </div>
                </div>

                <span class="hidden rounded-full border border-slate-700 bg-slate-800 px-3 py-1 text-xs font-medium text-slate-300 sm:inline">
                    Profit · TICO_A
                </span>
            </div>
        </header>

        <main class="mx-auto max-w-7xl px-4 py-8 sm:px-6 lg:px-8">
            @if (session('status'))
                <div class="mb-6 flex items-start gap-3 rounded-xl border border-emerald-200 bg-emerald-50 px-4 py-3 text-sm text-emerald-800 shadow-sm" role="status">
                    <svg class="mt-0.5 h-5 w-5 flex-none text-emerald-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                    </svg>
                    <span>{{ session('status') }}</span>
                </div>
            @endif

            @if (session('error'))
                <div class="mb-6 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800 shadow-sm" role="alert">
                    <svg class="mt-0.5 h-5 w-5 flex-none text-red-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.28 7.22a.75.75 0 00-1.06 1.06L8.94 10l-1.72 1.72a.75.75 0 101.06 1.06L10 11.06l1.72 1.72a.75.75 0 101.06-1.06L11.06 10l1.72-1.72a.75.75 0 00-1.06-1.06L10 8.94 8.28 7.22z" clip-rule="evenodd" />
                    </svg>
                    <span>{{ session('error') }}</span>
                </div>
            @endif

            @yield('content')
        </main>

        <footer class="mx-auto max-w-7xl px-4 pb-8 text-center text-xs text-slate-500 sm:px-6 lg:px-8">
            Motor de actualización de precios · Laravel {{ Illuminate\Foundation\Application::VERSION }}
        </footer>
    </body>
</html>
