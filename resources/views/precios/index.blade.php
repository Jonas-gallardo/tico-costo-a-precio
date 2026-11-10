@extends('layouts.app')

@section('title', 'Motor de Actualización de Precios')

@section('content')
    {{-- Encabezado --}}
    <div class="mb-8 flex flex-col gap-4 lg:flex-row lg:items-end lg:justify-between">
        <div>
            <h1 class="text-2xl font-bold tracking-tight text-slate-900 sm:text-3xl">
                Motor de Actualización de Precios - Tico
            </h1>
            <p class="mt-2 max-w-2xl text-sm text-slate-600">
                Detecta los costos de entrada sin precio vigente en <span class="font-semibold text-slate-800">saArtPrecio</span>
                y genera el nuevo precio aplicando el margen configurado sobre el costo.
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-medium text-slate-600 shadow-sm">
                <span class="h-1.5 w-1.5 rounded-full bg-slate-400"></span>
                Tipo de documento: {{ $tipoDoc }}
            </span>
            <span class="inline-flex items-center gap-1.5 rounded-full border border-slate-300 bg-white px-3 py-1 text-xs font-medium text-slate-600 shadow-sm">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500"></span>
                Conexión: {{ $conexion }}
            </span>
        </div>
    </div>

    {{-- Aviso si la escritura está deshabilitada --}}
    @unless ($escrituraHabilitada)
        <div class="mb-6 flex items-start gap-3 rounded-xl border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 shadow-sm" role="alert">
            <svg class="mt-0.5 h-5 w-5 flex-none text-amber-600" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                <path fill-rule="evenodd" d="M8.485 2.495c.673-1.167 2.357-1.167 3.03 0l6.28 10.875c.673 1.167-.17 2.625-1.516 2.625H3.72c-1.347 0-2.189-1.458-1.515-2.625L8.485 2.495zM10 6a.75.75 0 01.75.75v3.5a.75.75 0 01-1.5 0v-3.5A.75.75 0 0110 6zm0 9a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd" />
            </svg>
            <span>
                La escritura está deshabilitada (<code class="rounded bg-amber-100 px-1 py-0.5 text-xs">PROFIT_ESCRITURA_HABILITADA=false</code>).
                El botón permanecerá bloqueado hasta que se active.
            </span>
        </div>
    @endunless

    {{-- Tarjetas de resumen --}}
    <div class="mb-6 grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Registros pendientes por procesar</p>
            <p class="mt-2 text-4xl font-bold tracking-tight {{ $totalPendientes > 0 ? 'text-amber-600' : 'text-emerald-600' }}">
                {{ $totalPendientes }}
            </p>
            <p class="mt-1 text-xs text-slate-500">
                {{ $totalPendientes === 1 ? 'precio por generar' : 'precios por generar' }}
            </p>
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
            <p class="text-sm font-medium text-slate-500">Última ejecución</p>
            @if ($ultimaEjecucion)
                <p class="mt-2 text-lg font-semibold text-slate-900">
                    {{ $ultimaEjecucion['fecha'] }} <span class="text-xs font-normal text-slate-400">UTC</span>
                </p>
                <p class="mt-1 text-xs text-slate-500">
                    {{ $ultimaEjecucion['insertados'] }} insertados ·
                    {{ $ultimaEjecucion['cerrados'] }} vigencias cerradas ·
                    {{ $ultimaEjecucion['duracion_ms'] }} ms
                </p>
            @else
                <p class="mt-2 text-sm font-medium text-slate-700">Aún no hay ejecuciones</p>
                <p class="mt-1 text-xs text-slate-500">El historial aparece después de la primera corrida.</p>
            @endif
        </div>

        <div class="rounded-2xl border border-slate-200 bg-white p-6 shadow-sm sm:col-span-2 lg:col-span-1">
            <p class="text-sm font-medium text-slate-500">Fórmula aplicada</p>
            <p class="mt-2 font-mono text-sm text-slate-800">costo + (costo × margen / 100)</p>
            <p class="mt-1 text-xs text-slate-500">Redondeo comercial a 2 decimales.</p>
        </div>
    </div>

    {{-- Acción principal --}}
    <div class="mb-6 rounded-2xl border border-slate-200 bg-white p-6 shadow-sm">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Procesar pendientes</h2>
                <p class="mt-1 text-sm text-slate-600">
                    El proceso cierra la vigencia anterior e inserta el nuevo precio en una sola transacción.
                </p>
            </div>

            <form id="form-recalcular" action="{{ route('precios.recalcular') }}" method="POST" class="sm:flex-none">
                @csrf
                <button
                    type="submit"
                    id="btn-recalcular"
                    @disabled($totalPendientes === 0 || ! $escrituraHabilitada)
                    class="inline-flex w-full items-center justify-center gap-2 rounded-xl bg-emerald-600 px-6 py-3 text-sm font-semibold text-white shadow-sm transition hover:bg-emerald-700 focus:outline-none focus-visible:ring-2 focus-visible:ring-emerald-500 focus-visible:ring-offset-2 disabled:cursor-not-allowed disabled:bg-slate-300 disabled:text-slate-500 sm:w-auto"
                >
                    <svg id="btn-spinner" class="hidden h-5 w-5 animate-spin text-white" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                        <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                        <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path>
                    </svg>
                    <svg id="btn-icon" class="h-5 w-5 {{ $totalPendientes === 0 || ! $escrituraHabilitada ? 'hidden' : '' }}" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                        <path fill-rule="evenodd" d="M15.312 11.424a5.5 5.5 0 01-9.201 2.466l-.312-.311h2.433a.75.75 0 000-1.5H3.989a.75.75 0 00-.75.75v4.242a.75.75 0 001.5 0v-2.43l.31.31a7 7 0 0011.712-3.138.75.75 0 00-1.449-.39zm1.23-3.723a.75.75 0 00.219-.53V2.929a.75.75 0 00-1.5 0V5.36l-.31-.31A7 7 0 003.239 8.188a.75.75 0 101.448.389A5.5 5.5 0 0113.89 6.11l.311.31h-2.432a.75.75 0 000 1.5h4.243a.75.75 0 00.53-.219z" clip-rule="evenodd" />
                    </svg>
                    <span id="btn-text">
                        @if ($totalPendientes === 0)
                            Sin pendientes
                        @elseif (! $escrituraHabilitada)
                            Escritura deshabilitada
                        @else
                            Recalcular Precios Ahora
                        @endif
                    </span>
                </button>
            </form>
        </div>
    </div>

    {{-- Tabla de pendientes --}}
    <div class="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm">
        <div class="flex items-center justify-between border-b border-slate-200 px-6 py-4">
            <div>
                <h2 class="text-base font-semibold text-slate-900">Costos pendientes</h2>
                <p class="mt-0.5 text-xs text-slate-500">Detección por anti-join contra saArtPrecio (hasta IS NULL).</p>
            </div>
            <span class="rounded-full bg-slate-100 px-3 py-1 text-xs font-semibold text-slate-600">
                {{ $totalPendientes }} {{ $totalPendientes === 1 ? 'registro' : 'registros' }}
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-slate-200 text-left text-sm">
                <thead class="bg-slate-50">
                    <tr>
                        <th scope="col" class="whitespace-nowrap px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Artículo</th>
                        <th scope="col" class="whitespace-nowrap px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Descripción</th>
                        <th scope="col" class="whitespace-nowrap px-6 py-3 text-xs font-semibold uppercase tracking-wide text-slate-500">Almacén</th>
                        <th scope="col" class="whitespace-nowrap px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Costo</th>
                        <th scope="col" class="whitespace-nowrap px-6 py-3 text-center text-xs font-semibold uppercase tracking-wide text-slate-500">Código de Precio</th>
                        <th scope="col" class="whitespace-nowrap px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Margen Mínimo (%)</th>
                        <th scope="col" class="whitespace-nowrap px-6 py-3 text-right text-xs font-semibold uppercase tracking-wide text-slate-500">Precio Final</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-100">
                    @forelse ($pendientes as $fila)
                        <tr class="transition hover:bg-slate-50">
                            <td class="whitespace-nowrap px-6 py-3 font-mono text-xs font-medium text-slate-700">{{ trim((string) $fila->co_art) }}</td>
                            <td class="px-6 py-3 text-slate-700">{{ trim((string) $fila->art_des) }}</td>
                            <td class="whitespace-nowrap px-6 py-3 text-slate-600">{{ trim((string) $fila->cod_almacen) }}</td>
                            <td class="whitespace-nowrap px-6 py-3 text-right font-mono text-slate-700">{{ number_format((float) $fila->costo, 5, '.', ',') }}</td>
                            <td class="whitespace-nowrap px-6 py-3 text-center">
                                <span class="inline-flex items-center rounded-md bg-sky-50 px-2 py-1 text-xs font-semibold text-sky-700 ring-1 ring-inset ring-sky-200">
                                    {{ trim((string) $fila->co_precio) }}
                                </span>
                            </td>
                            <td class="whitespace-nowrap px-6 py-3 text-right text-slate-700">{{ number_format((float) $fila->monto_min, 2, '.', ',') }}</td>
                            <td class="whitespace-nowrap px-6 py-3 text-right font-mono font-semibold text-emerald-700">{{ number_format((float) $fila->precio_calculado, 2, '.', ',') }}</td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="7" class="px-6 py-12 text-center">
                                <svg class="mx-auto h-10 w-10 text-emerald-400" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
                                    <path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.857-9.809a.75.75 0 00-1.214-.882l-3.483 4.79-1.88-1.88a.75.75 0 10-1.06 1.061l2.5 2.5a.75.75 0 001.137-.089l4-5.5z" clip-rule="evenodd" />
                                </svg>
                                <p class="mt-3 text-sm font-semibold text-slate-800">Todo al día</p>
                                <p class="mt-1 text-sm text-slate-500">No hay costos pendientes de convertir en precio.</p>
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <script>
        document.getElementById('form-recalcular')?.addEventListener('submit', function () {
            const boton = document.getElementById('btn-recalcular');
            const texto = document.getElementById('btn-text');
            const spinner = document.getElementById('btn-spinner');
            const icono = document.getElementById('btn-icon');

            if (boton) {
                boton.disabled = true;
                boton.classList.add('cursor-wait', 'opacity-80');
            }

            if (texto) {
                texto.textContent = 'Procesando...';
            }

            if (spinner) {
                spinner.classList.remove('hidden');
            }

            if (icono) {
                icono.classList.add('hidden');
            }
        });
    </script>
@endsection
