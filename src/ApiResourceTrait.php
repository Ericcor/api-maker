<?php

namespace ApiMaker;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

trait ApiResourceTrait
{
    protected function list(string $class, Request $request)
    {
        // New Model instance
        $model = new $class;

        // Get model's listable
        $listable = $model->getListable();

        // Get model's listable
        $table = $model->getTable();

        // Get columns to select
        $query = $model->select($model->getSelectable());

        // Filters - Set wheres
        foreach ($listable as $key => $alias) {
            list($name, $column) = $this->getNameAndColumn($key, $alias, $table);

            // Where equals to
            if ($request->has($name)) {
                if (is_array($value = $request->get($name)) || count($valueArray = explode(',', $value)) > 1) {
                    $query->whereIn($column, $valueArray ?? $value);
                    continue;
                }

                $query->where($column, $value);
                
            // Where not equals to
            } elseif ($request->has($name . '_not')) {
                if (is_array($value = $request->get($name . '_not')) || count($valueArray = explode(',', $value)) > 1) {
                    $query->whereNotIn($column, $valueArray ?? $value);
                    continue;
                }

                $query->where($column, '<>', $value);
                
            // Where like to
            } elseif ($request->has($name . '_like')) {
                $tablePrefix = env('DB_PREFIX');
                $query->whereRaw($this->getLikeOperator($tablePrefix, $column, $request->get($name . '_like')));
            } elseif ($request->has($name . '_gte') || $request->has($name . '_lte')) {

                // Where greater than or equals to
                if ($request->has($name . '_gte')) {
                    $value = $request->get($name . '_gte');

                    $query->where($column, '>=', $value);
                }

                // Where less than or equals to
                if ($request->has($name . '_lte')) {
                    $value = $request->get($name . '_lte');

                    $query->where($column, '<=', $value);
                }
            // Where between
            } elseif ($request->has($name . '_between')) {
                $value = $request->get($name . '_between');

                $valueArray = !is_array($value) ? explode(',', $value) : $value;

                $query->whereBetween($column, $valueArray);
            // Where null
            } elseif ($request->has($name . '_null')) {
                $value = $request->get($name . '_null');
                if (is_null($value)) {
                    $query->whereNull($column);
                } elseif ($value) {
                    $query->whereNotNull($column);
                }
            // Where not null
            } elseif ($request->has($name . '_not_null')) {
                $query->whereNotNull($column);
            }
        }

        // Filters by relations
        if ($request->has('has')) {
            if ($has = json_decode($request->get('has'), true)) {
                foreach ($has as $name => $values) {
                    $segments = explode('.', $name);
                    $column = array_pop($segments);
                    $relations = $segments;

                    $query->whereHas(implode('.', $relations), function ($query) use ($column, $values) {
                        $query->whereIn("{$query->getModel()->getTable()}.{$column}", $values);
                    });
                }
            }
        }


        // Set order by
        if ($request->has('order_by')) {
            $orderByArray = explode(',', $request->get('order_by'));

            foreach ($orderByArray as $orderByRaw) {
                if (
                   ($orderBy = $this->getOrderBy($orderByRaw, $listable)) !== false
                    &&
                    $this->validateOrderBy($orderBy, $model, $listable)
                ) {
                    $query = $this->applyOrderBy($query, $orderBy);
                }
            }
        }

        // Load relations
        $query->with($this->getEagerLoadedRelations($request, $class));

        // Alter the query before getting the items
        if (method_exists($this, 'alterQuery')) {
            $query = $this->alterQuery($query);
        }

        $perPage = $request->get('per_page') ?? $this->perPage ?? 20;

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

    protected function getLikeOperator($tablePrefix, $column, $value) {
        return "UPPER({$tablePrefix}{$column}) LIKE UPPER('%{$value}%')";
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

    private function getOrderBy($orderBy)
    {
        $array = explode(':', $orderBy);

        return (object) [
            'column' => $array[0],
            'order' => $array[1] ?? 'ASC',
        ];
    }

    private function validateOrderBy($order_by, $model, array $columns = [])
    {
        if (empty($columns) || !in_array(strtoupper($order_by->order), ['ASC', 'DESC'])) {
            return false;
        }

        if (in_array($order_by->column, $columns)) {
            return true;
        } elseif (count($name_array = explode('.', $order_by->column)) == 2) {
            return method_exists($model, $name_array[0]);
        }

        return false;
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

    private function getEagerLoadedRelations(Request $request, $model)
    {
        return collect(explode(',', $request->get('with')))->filter(
            function ($relation) use ($model) {
                $relationArray = explode('.', $relation);
                return method_exists($model, current($relationArray));
            }
        )->toArray();
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
