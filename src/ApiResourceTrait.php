<?php

namespace ApiMaker;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ApiResourceTrait
{
    protected function list(string $class, Request $request)
    {
        $model = new $class;
        $listable = $model->getListable();
        $table = $model->getTable();

        // Seleccionar solo columnas necesarias
        $query = $model->select($model->getSelectable());

        // Aplicar filtros
        foreach ($listable as $key => $alias) {
            list($name, $column) = $this->getNameAndColumn($key, $alias, $table);

            if ($request->filled($name)) {
                $value = $request->input($name);
                is_array($value) ? $query->whereIn($column, $value) : $query->where($column, $value);
            } elseif ($request->filled("{$name}_not")) {
                $query->where($column, '<>', $request->input("{$name}_not"));
            } elseif ($request->filled("{$name}_like")) {
                $query->where($column, 'ILIKE', '%' . $request->input("{$name}_like") . '%');
            } elseif ($request->filled("{$name}_gte")) {
                $query->where($column, '>=', $request->input("{$name}_gte"));
            } elseif ($request->filled("{$name}_lte")) {
                $query->where($column, '<=', $request->input("{$name}_lte"));
            } elseif ($request->filled("{$name}_between")) {
                $values = explode(',', $request->input("{$name}_between"));
                if (count($values) === 2) {
                    $query->whereBetween($column, $values);
                }
            } elseif ($request->filled("{$name}_null")) {
                $query->whereNull($column);
            } elseif ($request->filled("{$name}_not_null")) {
                $query->whereNotNull($column);
            }
        }

        // Aplicar filtros por relaciones solo si se requieren
        if ($request->filled('has')) {
            $hasRelations = json_decode($request->input('has'), true);
            if (is_array($hasRelations)) {
                foreach ($hasRelations as $name => $values) {
                    $relations = explode('.', $name);
                    $column = array_pop($relations);

                    $query->whereHas(array_shift($relations), function ($q) use ($relations, $column, $values) {
                        foreach ($relations as $relation) {
                            $q->whereHas($relation, fn($q) => $q->whereIn("{$q->getModel()->getTable()}.{$column}", $values));
                        }
                    });
                }
            }
        }

        // Filtros con LIKE en relaciones
        if ($request->filled('has_like')) {
            $hasLike = json_decode($request->input('has_like'), true);
            foreach ($hasLike as $name => $values) {
                $relations = explode('.', $name);
                $column = array_pop($relations);
                $mainRelation = array_shift($relations);

                $query->whereHas($mainRelation, function ($q) use ($relations, $column, $values) {
                    foreach ($relations as $relation) {
                        $q = $q->whereHas($relation);
                    }
                    $q->where(fn($q) => array_walk($values, fn($value) => $q->orWhere($column, 'ILIKE', "%{$value}%")));
                });
            }
        }

        // Ordenamiento con validación
        if ($request->filled('order_by')) {
            foreach (explode(',', $request->input('order_by')) as $order) {
                $orderData = $this->getOrderBy($order);
                if ($orderData && $this->validateOrderBy($orderData, $model, $listable)) {
                    $query = $this->applyOrderBy($query, $orderData);
                }
            }
        }

        // Cargar relaciones solo si se solicita
        if ($request->filled('with')) {
            $query->with($this->getEagerLoadedRelations($request, $class));
        }

        // Permitir modificar la consulta antes de ejecutarla
        if (method_exists($this, 'alterQuery')) {
            $query = $this->alterQuery($query);
        }

        // Manejo de paginación optimizado
        $perPage = (int) $request->input('per_page', $this->perPage ?? 20);

        if ($perPage == 0) {
            return $this->noPagination($request, $query);
        } elseif ($request->has('paginate')) {
            switch ($request->get('paginate')) {
                case 'normal':
                    return $this->normalPagination($request, $query, $perPage, $listable);
                    break;

                case 'simple':
                    return $this->simplePagination($request, $query, $perPage);
                    break;

                case 'none':
                    return $this->noPagination($request, $query);
                    break;
                
                default:
                    return $this->normalPagination($request, $query, $perPage, $listable);
                    break;
            }
        }

        return $this->normalPagination($request, $query, $perPage, $listable);
    }

    protected function simplePagination($request, $query, $perPage) {
        $paginated = $query
            ->simplePaginate($perPage)
        ;

        $items = collect($paginated->items())
            ->each(function ($resource) use ($request) {
                $resource->append($this->getAppendableAttributes($request));
            })
        ;

        return $paginated;
    }
    
    protected function noPagination($request, $query) {
        $items = $query
            ->get()
        ;

        $items->each(function ($resource) use ($request) {
                $resource->append($this->getAppendableAttributes($request));
            })
        ;

        return collect(['data' => $items]);
    }

    
    protected function normalPagination($request, $query, $perPage, $listable) {
        
        $query_string = array_merge($listable, ['order_by', 'per_page', 'with']);

        $paginated = $query
            ->paginate($perPage)
            ->appends($request->only($query_string))
        ;

        $items = collect($paginated->items())
            ->each(function ($resource) use ($request) {
                $resource->append($this->getAppendableAttributes($request));
            })
        ;

        return $paginated;
    }

    private function getAppendableAttributes(Request $request)
    {
        if (!$request->has('append')) {
            return [];
        }
        return explode(',', $request->get('append'));
    }

    private function getNameAndColumn($key, $alias, $table)
    {
        $column = !is_numeric($key) ? $key : $alias;

        if ($alias == $column) {
            return [
                // Name
                $alias,
                // Column
                "{$table}.{$column}",
            ];
        }

        return [
            $alias,
            DB::raw($column),
        ];
    }

    protected function getOrderBy($orderBy)
    {
        $array = explode(':', $orderBy);
        return isset($array[0]) ? (object) [
            'column' => $array[0],
            'order' => isset($array[1]) && in_array(strtoupper($array[1]), ['ASC', 'DESC']) ? $array[1] : 'ASC',
        ] : false;
    }

    private function validateOrderBy($orderBy, $model, array $columns = [])
    {
        return in_array($orderBy->column, $columns) && in_array(strtoupper($orderBy->order), ['ASC', 'DESC']);
    }

    private function getEagerLoadedRelations(Request $request, $model)
    {
        return collect(explode(',', $request->get('with', '')))->filter(
            fn($relation) => method_exists($model, $relation)
        )->toArray();
    }

    private function applyOrderBy($query, $order_by)
    {
        if (count($columnaArray = explode('.', $order_by->column)) == 2) {
            $model = $query->getModel();
            $relation = $model->{$columnaArray[0]}();

            if ($relation instanceof \Illuminate\Database\Eloquent\Relations\HasOne) {
	            return $query->join(
	                $table = $relation->getRelated()->getTable(),
	                $relation->getQualifiedForeignKeyName(),
	                $relation->getQualifiedParentKeyName()
	            )->orderBy("{$table}.{$columnaArray[1]}", $order_by->order);
            }

            return $query->join(
                $table = $relation->getRelated()->getTable(),
                $relation->getQualifiedForeignKeyName(),
                $relation->getQualifiedOwnerKeyName()
            )->orderBy("{$table}.{$columnaArray[1]}", $order_by->order);
        }

        return $query->orderBy($order_by->column, $order_by->order);
    }

    protected function find(string $class, $id, $pk = null)
    {
        $model = (new $class);

        if (!is_null($pk)) {
            $model->setKeyName($pk);
        }

        return $model->select($model->getSelectable())
            ->with($this->getEagerLoadedRelations(request(), $class))
            ->findOrFail($id)
            ->append($this->getAppendableAttributes(request()))
        ;
    }

    protected function new(string $class, Request $request)
    {
        $resource = new $class;

        $input = $request->only($resource->getFillable());

        $resource->fill($this->alterInput($input));

        $resource->save();

        return $resource->refresh();
    }

    protected function alterInput(array $input): array
    {
        return $input;
    }

    protected function edit(Model $resource, Request $request)
    {
        $input = $request->only($resource->getFillable());

        $resource->fill($this->alterInput($input));

        $resource->save();

        return $resource->fresh();
    }
}
