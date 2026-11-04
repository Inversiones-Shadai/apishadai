<?php

namespace App\Repositories\Traits;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;

use function count;
use function in_array;
use function is_array;
use function is_string;

trait QueryBuilderTrait
{
    /**
     * Aplicar condiciones WHERE simples
     *
     * @param  string  $logicalOperator  'AND' o 'OR'
     */
    protected function applyWhereConditions($query, array $conditions, string $logicalOperator = 'AND'): void
    {
        $logicalOperator = strtoupper($logicalOperator) === 'OR' ? 'OR' : 'AND';
        $isFirst = true;

        foreach ($conditions as $column => $value) {
            if (is_string($column)) {
                // Array asociativo: ['column' => 'value']
                if ($isFirst || $logicalOperator === 'AND') {
                    $query->where($column, '=', $value);
                } else {
                    $query->orWhere($column, '=', $value);
                }
                $isFirst = false;
            }
        }
    }

    /**
     * Aplicar condiciones complejas (LIKE, IN, etc)
     * Soporta condiciones con logicalOperator individual: [column, operator, value, logicalOperator]
     *
     * @param  string  $logicalOperator  'AND' o 'OR' - fallback si la condición no especifica uno
     */
    protected function applyComplexConditions($query, array $conditions, string $logicalOperator = 'AND'): void
    {
        $logicalOperator = strtoupper($logicalOperator) === 'OR' ? 'OR' : 'AND';

        if ($this->isSingleCondition($conditions)) {
            // ['column', 'operator', 'value'] o ['column', 'operator', 'value', 'logicalOperator']
            [$column, $operator, $value] = array_slice($conditions, 0, 3);
            $conditionLogicalOperator = $conditions[3] ?? $logicalOperator;
            $this->applyCondition($query, $column, $operator, $value, $conditionLogicalOperator);
        } elseif ($this->isMultipleConditions($conditions)) {
            // [['col1', 'op1', 'val1', 'AND'], ['col2', 'op2', 'val2', 'OR']]
            $isFirst = true;
            foreach ($conditions as $condition) {
                if (is_array($condition) && count($condition) >= 2) {
                    $column = $condition[0];
                    $operator = $condition[1] ?? '=';
                    $value = $condition[2] ?? null;
                    // Usar logicalOperator de la condición o el fallback
                    $currentOperator = $condition[3] ?? $logicalOperator;

                    // Primera condición siempre es WHERE (AND implícito)
                    // El logicalOperator solo aplica del segundo en adelante
                    if ($isFirst) {
                        $this->applyCondition($query, $column, $operator, $value, 'AND');
                    } else {
                        $this->applyCondition($query, $column, $operator, $value, $currentOperator);
                    }
                    $isFirst = false;
                }
            }
        } else {
            // Array asociativo
            $this->applyWhereConditions($query, $conditions, $logicalOperator);
        }
    }

    /**
     * Aplicar una condición individual
     *
     * @param  string  $logicalOperator  'AND' u 'OR' - solo se usa si no es la primera condición
     */
    protected function applyCondition($query, string $column, string $operator, $value, string $logicalOperator = 'AND'): void
    {
        $operator = strtoupper(trim($operator));
        $isOrCondition = strtoupper($logicalOperator) === 'OR';

        // Validar operador permitido
        $allowedOperators = ['=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE', 'IN', 'NOT IN', 'BETWEEN', 'IS', 'IS NOT'];

        if (! in_array($operator, $allowedOperators, true)) {
            $operator = '=';
        }

        // Manejar operadores especiales
        switch ($operator) {
            case 'LIKE':
            case 'NOT LIKE':
                $builder = $query instanceof EloquentBuilder ? $query->getQuery() : $query;
                $wrapped = $builder->getGrammar()->wrap($column);
                $method = $isOrCondition ? 'orWhereRaw' : 'whereRaw';
                $query->$method('LOWER('.$wrapped.') '.$operator.' ?', [mb_strtolower((string) $value)]);
                break;

            case 'IN':
            case 'NOT IN':
                if (is_array($value)) {
                    $method = $operator === 'IN' ? ($isOrCondition ? 'orWhereIn' : 'whereIn') : ($isOrCondition ? 'orWhereNotIn' : 'whereNotIn');
                    $query->$method($column, $value);
                }
                break;

            case 'BETWEEN':
                if (is_array($value) && count($value) === 2) {
                    if ($isOrCondition) {
                        $query->orWhereBetween($column, $value);
                    } else {
                        $query->whereBetween($column, $value);
                    }
                }
                break;

            case 'IS':
                if ($isOrCondition) {
                    $query->orWhereNull($column);
                } else {
                    $query->whereNull($column);
                }
                break;

            case 'IS NOT':
                if ($isOrCondition) {
                    $query->orWhereNotNull($column);
                } else {
                    $query->whereNotNull($column);
                }
                break;

            default:
                if ($isOrCondition) {
                    $query->orWhere($column, $operator, $value);
                } else {
                    $query->where($column, $operator, $value);
                }
                break;
        }
    }

    /**
     * Aplicar JOINs a la consulta
     */
    protected function applyJoins($query, array $tables): void
    {
        foreach ($tables as $join) {
            if (! is_array($join)) {
                continue;
            }

            $joinTable = $join['table'] ?? null;
            $first = $join['first'] ?? null;
            $operator = $join['operator'] ?? '=';
            $second = $join['second'] ?? null;
            $type = strtolower($join['type'] ?? 'inner');

            if (! $joinTable || ! $first || ! $second) {
                continue;
            }

            // Validar tipo de JOIN
            if (! in_array($type, ['inner', 'left', 'right', 'cross'], true)) {
                $type = 'inner';
            }

            // Aplicar JOIN según tipo
            switch ($type) {
                case 'left':
                    $query->leftJoin($joinTable, $first, $operator, $second);
                    break;
                case 'right':
                    $query->rightJoin($joinTable, $first, $operator, $second);
                    break;
                case 'cross':
                    $query->crossJoin($joinTable);
                    break;
                default:
                    $query->join($joinTable, $first, $operator, $second);
                    break;
            }
        }
    }

    /**
     * Verifica si es una condición simple
     */
    protected function isSingleCondition(array $conditions): bool
    {
        return count($conditions) === 3
            && isset($conditions[0], $conditions[1], $conditions[2])
            && is_string($conditions[0])
            && ! is_array($conditions[0]);
    }

    /**
     * Verifica si son múltiples condiciones
     */
    protected function isMultipleConditions(array $conditions): bool
    {
        if (empty($conditions) || ! array_is_list($conditions)) {
            return false;
        }

        $firstElement = reset($conditions);

        return is_array($firstElement);
    }

    /**
     * Aplicar paginación con límites seguros
     */
    protected function applySafePagination($query, ?int $perPage = null): LengthAwarePaginator
    {
        $perPage ??= $this->perPage;
        $perPage = max(1, min($perPage, 100)); // Entre 1 y 100

        return $query->paginate($perPage);
    }

    /**
     * Aplicar ordenamiento
     */
    protected function applyOrdering($query, ?array $orderBy = null): void
    {
        $orderBy ??= $this->orderBy;

        if (count($orderBy) >= 2) {
            $column = $this->sanitizeColumnName($orderBy[0]);
            $direction = strtoupper($orderBy[1]) === 'ASC' ? 'ASC' : 'DESC';

            $query->orderBy($column, $direction);
        }
    }
}
