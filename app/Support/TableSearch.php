<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class TableSearch
{
    /**
     * Search visible columns before pagination, including case-sensitive legacy columns.
     * Column and relation names must come from server-side definitions, never request input.
     *
     * @param  list<string>  $columns
     * @param  array<string, list<string>>  $relations
     */
    public static function apply(Builder $query, string $search, array $columns, array $relations = []): Builder
    {
        $search = trim($search);
        if ($search === '') {
            return $query;
        }
        $pattern = '%'.mb_strtolower($search).'%';

        return $query->where(function (Builder $group) use ($columns, $relations, $pattern): void {
            foreach ($columns as $column) {
                $wrapped = $group->getQuery()->getGrammar()->wrap($group->qualifyColumn($column));
                $group->orWhereRaw('LOWER('.$wrapped.') LIKE ?', [$pattern]);
            }
            foreach ($relations as $relation => $fields) {
                $group->orWhereHas($relation, function (Builder $related) use ($fields, $pattern): void {
                    $related->where(function (Builder $fieldsQuery) use ($fields, $pattern): void {
                        foreach ($fields as $field) {
                            $wrapped = $fieldsQuery->getQuery()->getGrammar()->wrap($fieldsQuery->qualifyColumn($field));
                            $fieldsQuery->orWhereRaw('LOWER('.$wrapped.') LIKE ?', [$pattern]);
                        }
                    });
                });
            }
        });
    }
}
