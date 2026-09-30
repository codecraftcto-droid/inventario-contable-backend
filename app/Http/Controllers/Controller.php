<?php

namespace App\Http\Controllers;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

abstract class Controller
{
    /**
     * Respuesta paginada estándar: { data: [...], meta: { current_page, per_page, last_page, total } }.
     */
    protected function paginated(LengthAwarePaginator $paginator, ?callable $transform = null): JsonResponse
    {
        $items = collect($paginator->items());

        return response()->json([
            'data' => ($transform ? $items->map($transform) : $items)->values(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'last_page' => $paginator->lastPage(),
                'total' => $paginator->total(),
            ],
        ]);
    }

    /**
     * Orden pedido por el cliente (sort_by / sort_dir), solo sobre columnas permitidas
     * para no exponer ni ordenar por columnas sin índice. Si no se pide, usa el orden por defecto.
     *
     * @param  string[]  $allowed  Columnas ordenables.
     */
    protected function applySort(Builder $query, Request $request, array $allowed, string $defaultColumn, string $defaultDirection = 'asc'): Builder
    {
        $column = $request->string('sort_by')->toString();
        $direction = strtolower($request->string('sort_dir')->toString()) === 'desc' ? 'desc' : 'asc';

        return in_array($column, $allowed, true)
            ? $query->orderBy($column, $direction)
            : $query->orderBy($defaultColumn, $defaultDirection);
    }

    /**
     * Tamaño de página pedido por el cliente, acotado entre 1 y 100.
     */
    protected function perPage(Request $request, int $default = 15): int
    {
        return min(max($request->integer('per_page', $default), 1), 100);
    }
}
