<?php

namespace ApiMaker;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

trait ApiCrudTrait
{
    public function index(Request $request)
    {
        return response()->json([
            'status' => 'success',
            'data' => $this->list($this->class, $request)
        ]);
    }

    public function store(Request $request)
    {
        try {
            $resource = DB::transaction(function () use ($request) {
                return $this->new($this->class, $request);
            });

            return response()->json([
                'status' => 'success',
                'data' => $resource
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al crear el recurso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $resource = $this->find($this->class, $id);

            return response()->json([
                'status' => 'success',
                'data' => $resource
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Recurso no encontrado'
            ], 404);
        }
    }

    public function update($id, Request $request)
    {
        try {
            $resource = DB::transaction(function () use ($id, $request) {
                $model = $this->class::findOrFail($id);
                return $this->edit($model, $request);
            });

            return response()->json([
                'status' => 'success',
                'data' => $resource
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Recurso no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al actualizar el recurso',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            DB::transaction(function () use ($id) {
                $resource = $this->class::findOrFail($id);
                $resource->delete();
            });

            return response()->json([
                'status' => 'success',
                'message' => 'Recurso eliminado correctamente'
            ]);
        } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Recurso no encontrado'
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'status' => 'error',
                'message' => 'Error al eliminar el recurso',
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
