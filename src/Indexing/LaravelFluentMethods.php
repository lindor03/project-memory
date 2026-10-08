<?php

namespace ProjectMemory\Indexing;

/** Query-builder methods that Eloquent forwards when no local method exists. */
class LaravelFluentMethods
{
    /** @var list<string> */
    public const QUERY = [
        'where', 'orWhere', 'whereIn', 'orWhereIn', 'whereNotIn', 'whereNull', 'orWhereNull',
        'whereNotNull', 'whereBetween', 'whereDate', 'whereColumn', 'whereHas', 'orWhereHas',
        'whereDoesntHave', 'with', 'without', 'withCount', 'orderBy', 'orderByDesc', 'latest',
        'oldest', 'groupBy', 'having', 'limit', 'take', 'skip', 'offset', 'forPage', 'distinct',
        'select', 'addSelect', 'join', 'leftJoin', 'rightJoin', 'crossJoin', 'lockForUpdate',
        'sharedLock', 'reorder', 'inRandomOrder',
    ];

    /** @var list<string> */
    public const COLLECTION = ['get', 'pluck', 'cursor', 'lazy'];

    /** @var list<string> */
    public const MODEL = [
        'create', 'forceCreate', 'forceFill', 'fill', 'save', 'update', 'delete', 'destroy',
        'query', 'find', 'findOrFail', 'first', 'firstOrFail', 'firstOrCreate', 'firstOrNew',
        'updateOrCreate', 'all', 'increment', 'decrement', 'refresh', 'replicate', 'push',
        'load', 'loadMissing', 'relationLoaded', 'notify', 'notifyNow', 'getAttribute',
        'setAttribute', 'newQuery', 'withoutEvents',
    ];

    /** @var list<string> */
    public const TERMINAL = [
        'first', 'find', 'findOrFail', 'firstOrFail', 'firstOrNew', 'firstOrCreate', 'sole',
        'value', 'count', 'exists', 'doesntExist', 'update', 'delete', 'insert', 'upsert',
    ];

    public static function isQuery(string $method): bool
    {
        return in_array($method, self::QUERY, true);
    }

    public static function isFluent(string $method): bool
    {
        return self::isQuery($method) || in_array($method, [...self::COLLECTION, ...self::TERMINAL, ...self::MODEL], true);
    }

    /**
     * Return the statically identifiable chain type, or null when the call is terminal
     * or the receiver is not a known builder/relation/model.
     */
    public static function chainType(string $method, ?string $receiver): ?string
    {
        if ($receiver === null || ! self::isQuery($method) && ! in_array($method, self::COLLECTION, true)) {
            return null;
        }

        $relation = str_ends_with($receiver, '\\Relation');
        $builder = str_ends_with($receiver, '\\Builder') || $receiver === 'Illuminate\\Database\\Eloquent\\Model';
        if (! $relation && ! $builder) {
            return null;
        }

        if (in_array($method, self::COLLECTION, true)) {
            return 'Illuminate\\Support\\Collection';
        }

        return $relation
            ? 'Illuminate\\Database\\Eloquent\\Relations\\Relation'
            : 'Illuminate\\Database\\Eloquent\\Builder';
    }
}
