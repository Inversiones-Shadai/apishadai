<?php

namespace App\Repositories;

use App\Interfaces\RepositoryInterface;
use App\Models\Utils\Filter\FilterModel;
use App\Repositories\Traits\ConditionHandlerTrait;
use App\Repositories\Traits\ErrorHandlerTrait;
use App\Repositories\Traits\QueryBuilderTrait;
use App\Repositories\Traits\RelationHandlerTrait;
use App\Support\TableSearch;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

use function is_array;
use function is_string;

abstract class Repository implements RepositoryInterface
{
    use ConditionHandlerTrait,
        ErrorHandlerTrait,
        QueryBuilderTrait,
        RelationHandlerTrait;

    protected Model $model;

    protected array $defaultRelations = [];

    protected int $perPage = 15;

    protected array $orderBy = ['id', 'DESC'];

    protected bool $useQueryBuilder = false; // Flag para decidir entre DB y Eloquent

    public function __construct(Model $model)
    {
        $this->model = $model;
    }

    /**
     * Obtener todos los registros
     */
    public function getAll(): Collection
    {
        return $this->executeQuery(function () {
            if ($this->useQueryBuilder) {
                [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

                $results = DB::table($this->getTableName())
                    ->orderBy($orderColumn, $orderDirection)
                    ->get();

                return new Collection($results);
            }

            [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

            return $this->buildBaseQuery()
                ->orderBy($orderColumn, $orderDirection)
                ->get();
        }, 'getAll', new Collection);
    }

    /**
     * Paginación de registros
     */
    public function paginate(): LengthAwarePaginator
    {
        return $this->executeQuery(function () {
            if ($this->useQueryBuilder) {
                [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

                return DB::table($this->getTableName())
                    ->orderBy($orderColumn, $orderDirection)
                    ->paginate($this->perPage);
            }

            [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

            return $this->buildBaseQuery()
                ->orderBy($orderColumn, $orderDirection)
                ->paginate($this->perPage);
        }, 'paginate', $this->getEmptyPaginator());
    }

    /** @param list<string> $columns @param array<string, list<string>> $relations */
    public function searchPage(string $search, array $columns, array $relations = []): LengthAwarePaginator
    {
        if (trim($search) === '') {
            return $this->paginate();
        }

        return $this->executeQuery(function () use ($search, $columns, $relations) {
            [$column, $direction] = $this->getNormalizedOrderBy();

            return TableSearch::apply($this->buildBaseQuery(), $search, $columns, $relations)
                ->orderBy($column, $direction)->paginate($this->perPage);
        }, 'searchPage', $this->getEmptyPaginator());
    }

    /**
     * Buscar por ID
     */
    public function findById(int $id): ?Model
    {
        return $this->executeQuery(function () use ($id) {
            if ($this->useQueryBuilder) {
                $result = DB::table($this->getTableName())
                    ->where('id', '=', $id)
                    ->first();

                return $result ? $this->hydrateModel($result) : null;
            }

            return $this->buildBaseQuery()->find($id);
        }, 'findById', null, ['id' => $id]);
    }

    /**
     * Buscar por ID o lanzar excepción
     */
    public function findOrFail(int $id): Model
    {
        $result = $this->findById($id);

        if (! $result) {
            throw new ModelNotFoundException(
                "No query results for model [{$this->getModelClass()}] with ID {$id}"
            );
        }

        return $result;
    }

    /**
     * Crear un nuevo registro
     */
    public function create(array $data): bool
    {
        return $this->executeTransaction(function () use ($data) {
            $sanitized = $this->sanitizeData($data);
            $this->validateRequiredFields($sanitized);

            if ($this->useQueryBuilder) {
                return DB::table($this->getTableName())->insert($sanitized);
            }

            return (bool) $this->model->create($sanitized);
        }, 'create', ['data' => $data]);
    }

    /**
     * Actualizar un registro
     */
    public function update(int $id, array $data): bool
    {
        return $this->executeTransaction(function () use ($id, $data) {
            $filtered = $this->filterEmptyData($data);

            if (empty($filtered)) {
                throw new \InvalidArgumentException('No hay datos para actualizar');
            }

            $sanitized = $this->sanitizeData($filtered);

            if ($this->useQueryBuilder) {
                $updated = DB::table($this->getTableName())
                    ->where('id', '=', $id)
                    ->update($sanitized);

                if ($updated === 0) {
                    throw new \Exception("Registro no encontrado con ID: $id");
                }

                return true;
            }

            $model = $this->model->newQuery()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $model) {
                throw new \Exception("Registro no encontrado con ID: $id");
            }

            return $model->update($sanitized);
        }, 'update', ['id' => $id, 'data' => $data]);
    }

    /**
     * Eliminar un registro
     */
    public function delete(int $id): bool
    {
        return $this->executeTransaction(function () use ($id) {
            if ($this->useQueryBuilder) {
                $deleted = DB::table($this->getTableName())
                    ->where('id', '=', $id)
                    ->delete();

                if ($deleted === 0) {
                    throw new \Exception("Registro no encontrado con ID: $id");
                }

                return true;
            }

            $model = $this->model->newQuery()
                ->whereKey($id)
                ->lockForUpdate()
                ->first();

            if (! $model) {
                throw new \Exception("Registro no encontrado con ID: $id");
            }

            return $model->delete();
        }, 'delete', ['id' => $id]);
    }

    /**
     * Verificar si existe un registro
     */
    public function exists(array $conditions): bool
    {
        return $this->executeQuery(function () use ($conditions) {
            $sanitized = $this->sanitizeConditions($conditions);

            if ($this->useQueryBuilder) {
                $query = DB::table($this->getTableName());
                $this->applyWhereConditions($query, $sanitized);
                Log::debug('Repository::exists() - Query SQL (QueryBuilder):', ['sql' => $query->toSql(), 'bindings' => $query->getBindings()]);

                return $query->exists();
            }

            $query = $this->model->newQuery();
            $this->applyWhereConditions($query, $sanitized);

            return $query->exists();
        }, 'exists', false, ['conditions' => $conditions]);
    }

    /**
     * Listar con condiciones y paginación
     *
     * @param  array  $conditions  Condiciones a aplicar
     * @param  bool  $usePagination  Usar paginación
     * @param  string  $logicalOperator  'AND' o 'OR' para múltiples condiciones (por defecto 'AND')
     */
    public function whereList(array $conditions, bool $usePagination = false, string $logicalOperator = 'AND'): Collection|LengthAwarePaginator
    {

        return $this->executeQuery(function () use ($conditions, $usePagination, $logicalOperator) {
            $sanitized = $this->sanitizeConditions($conditions);

            if ($this->useQueryBuilder) {
                $query = DB::table($this->getTableName());
                $this->applyComplexConditions($query, $sanitized, $logicalOperator);

                if ($usePagination) {
                    [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

                    return $query
                        ->orderBy($orderColumn, $orderDirection)
                        ->paginate($this->perPage);
                } else {
                    [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

                    return $query
                        ->orderBy($orderColumn, $orderDirection)
                        ->get();
                }
            }

            $query = $this->buildBaseQuery();
            $this->applyComplexConditions($query, $sanitized, $logicalOperator);

            if ($usePagination) {
                // Paginate automáticamente lee el parámetro 'page' de la URL
                [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

                return $query
                    ->orderBy($orderColumn, $orderDirection)
                    ->paginate($this->perPage);
            } else {
                [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

                return $query
                    ->orderBy($orderColumn, $orderDirection)
                    ->get();
            }
        }, 'whereList', $this->getEmptyPaginator(), ['conditions' => $conditions]);
    }

    /**
     * Buscar el primer registro con condiciones
     *
     * @param  string  $logicalOperator  'AND' o 'OR' para múltiples condiciones
     */
    public function whereFirst(array $conditions, string $logicalOperator = 'AND'): ?Model
    {
        return $this->executeQuery(function () use ($conditions, $logicalOperator) {
            $sanitized = $this->sanitizeConditions($conditions);

            if ($this->useQueryBuilder) {
                $query = DB::table($this->getTableName());
                $this->applyComplexConditions($query, $sanitized, $logicalOperator);
                [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

                $result = $query
                    ->orderBy($orderColumn, $orderDirection)
                    ->first();

                return $result ? $this->hydrateModel($result) : null;
            }

            $query = $this->buildBaseQuery();
            $this->applyComplexConditions($query, $sanitized, $logicalOperator);
            [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();

            return $query
                ->orderBy($orderColumn, $orderDirection)
                ->first();
        }, 'whereFirst', null, ['conditions' => $conditions]);
    }

    /**
     * Listar con FilterModel - construye condiciones con logicalOperator individual
     */
    public function filterAll(FilterModel $filterModel, bool $usePagination = false): Collection|LengthAwarePaginator
    {
        $conditions = [];
        foreach ($filterModel->getFilterItems() as $item) {
            // Estructura: [key, operator, value, logicalOperator]
            $conditions[] = [$item->getKey(), $item->getOperator(), $item->getValue(), $item->getLogicalOperator()];
        }

        // whereList respeta el logicalOperator individual de cada condición

        return $this->whereList($conditions, $usePagination);
    }

    /**
     * Buscar primer registro con FilterModel - construye condiciones con logicalOperator individual
     */
    public function filterOne(FilterModel $filterModel): ?Model
    {
        $conditions = [];
        foreach ($filterModel->getFilterItems() as $item) {
            // Estructura: [key, operator, value, logicalOperator]
            $conditions[] = [$item->getKey(), $item->getOperator(), $item->getValue(), $item->getLogicalOperator()];
        }

        // whereFirst respeta el logicalOperator individual de cada condición
        return $this->whereFirst($conditions);
    }

    /**
     * Listar con JOINs y condiciones
     */
    public function joinWhereList(
        array $conditions,
        array $tables = [],
        array $selects = [],
        bool $usePagination = false
    ): Collection|LengthAwarePaginator {
        return $this->executeQuery(function () use ($conditions, $tables, $selects, $usePagination) {
            $sanitized = $this->sanitizeConditions($conditions);
            $sanitizedJoins = $this->sanitizeJoins($tables);

            $query = $this->useQueryBuilder
                ? DB::table($this->getTableName())
                : $this->model->newQuery();

            // Aplicar JOINs
            $this->applyJoins($query, $sanitizedJoins);

            // Aplicar condiciones complejas (soporta operadores por condición)
            $this->applyComplexConditions($query, $sanitized);

            // Aplicar selects
            if (! empty($selects)) {
                $query->select($this->sanitizeSelects($selects));
            } else {
                $query->select($this->getTableName().'.*');
            }

            if ($usePagination) {
                [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();
                $orderColumn = $this->qualifyOrderColumnForCurrentTable($orderColumn);

                return $query
                    ->orderBy($orderColumn, $orderDirection)
                    ->paginate($this->perPage);
            } else {
                [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();
                $orderColumn = $this->qualifyOrderColumnForCurrentTable($orderColumn);

                return $query
                    ->orderBy($orderColumn, $orderDirection)
                    ->get();
            }
        }, 'joinWhereList', $this->getEmptyPaginator(), [
            'conditions' => $conditions,
            'tables' => $tables,
            'selects' => $selects,
        ]);
    }

    /**
     * Buscar primer registro con JOINs
     */
    public function joinWhereFirst(
        array $conditions,
        array $tables = [],
        array $selects = []
    ): ?Model {
        return $this->executeQuery(function () use ($conditions, $tables, $selects) {
            $sanitized = $this->sanitizeConditions($conditions);
            $sanitizedJoins = $this->sanitizeJoins($tables);

            $query = $this->useQueryBuilder
                ? DB::table($this->getTableName())
                : $this->model->newQuery();

            $this->applyJoins($query, $sanitizedJoins);
            $this->applyComplexConditions($query, $sanitized);

            if (! empty($selects)) {
                $query->select($this->sanitizeSelects($selects));
            } else {
                $query->select($this->getTableName().'.*');
            }

            [$orderColumn, $orderDirection] = $this->getNormalizedOrderBy();
            $orderColumn = $this->qualifyOrderColumnForCurrentTable($orderColumn);

            $query->orderBy($orderColumn, $orderDirection);

            $result = $query->first();

            if ($this->useQueryBuilder && $result) {
                return $this->hydrateModel($result);
            }

            return $result;
        }, 'joinWhereFirst', null, [
            'conditions' => $conditions,
            'tables' => $tables,
            'selects' => $selects,
        ]);
    }

    /**
     * Filtrar datos vacíos
     */
    protected function filterEmptyData(array $data): array
    {
        return array_filter($data, function ($value) {
            if ($value === null) {
                return false;
            }
            if (is_string($value) && trim($value) === '') {
                return false;
            }
            if (is_array($value) && empty($value)) {
                return false;
            }

            return true;
        });
    }

    /**
     * Construir query base con relaciones
     */
    protected function buildBaseQuery()
    {
        $query = $this->model->newQuery();

        if (! empty($this->defaultRelations)) {
            $query->with($this->defaultRelations);
        }

        return $query;
    }

    /**
     * Obtener nombre de la tabla
     */
    protected function getTableName(): string
    {
        return $this->model->getTable();
    }

    /**
     * Obtener clase del modelo
     */
    protected function getModelClass(): string
    {
        return get_class($this->model);
    }

    /**
     * Hidratar modelo desde objeto stdClass
     */
    protected function hydrateModel(object $data): Model
    {
        $model = $this->model->newInstance();
        $model->setRawAttributes((array) $data, true);

        return $model;
    }

    /**
     * Obtener paginador vacío
     */
    protected function getEmptyPaginator(): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $this->perPage);
    }

    /**
     * Normalizar ordenamiento configurado por repositorios concretos.
     */
    private function getNormalizedOrderBy(): array
    {
        if (array_is_list($this->orderBy) && count($this->orderBy) >= 2) {
            $column = $this->orderBy[0];
            $direction = $this->orderBy[1];
        } else {
            $column = array_key_first($this->orderBy) ?: 'id';
            $direction = $this->orderBy[$column] ?? 'DESC';
        }

        return [
            $this->sanitizeColumnName((string) $column),
            strtoupper((string) $direction) === 'ASC' ? 'ASC' : 'DESC',
        ];
    }

    /**
     * Evitar columnas ambiguas en consultas con JOINs.
     */
    private function qualifyOrderColumnForCurrentTable(string $column): string
    {
        return str_contains($column, '.') ? $column : $this->getTableName().'.'.$column;
    }

    /**
     * Habilitar Query Builder
     */
    public function useQueryBuilder(bool $use = true)
    {
        $this->useQueryBuilder = $use;

        return $this;
    }

    /**
     * Establecer relaciones por defecto
     */
    public function setDefaultRelations(array $relations)
    {
        $this->defaultRelations = $relations;

        return $this;
    }

    /**
     * Establecer paginación
     */
    public function setPerPage(int $perPage)
    {
        $this->perPage = max(1, min($perPage, 100)); // Límite entre 1 y 100

        return $this;
    }

    /**
     * Establecer ordenamiento
     */
    public function setOrderBy(string $column, string $direction = 'DESC')
    {
        $this->orderBy = [
            $this->sanitizeColumnName($column),
            strtoupper($direction) === 'ASC' ? 'ASC' : 'DESC',
        ];

        return $this;
    }
}
