<?php

namespace System\Modules\Presentation\Models\Tables;

class Utils
{
    public static function ensureArray($value): array
    {
        if (is_array($value)) {
            return $value;
        }

        return [$value];
    }

    public static function valueOrClosure($value, ?\Closure $closure = null, array $args = [])
    {
        if (empty($closure)) {
            return $value;
        }

        array_unshift($args, $value);
        return call_user_func_array($closure, $args);
    }

    public static function expandClosure(mixed $closure, array $args = [])
    {
        if (is_callable($closure)) {
            return call_user_func_array($closure, $args);
        }

        return $closure;
    }

    public static function runClosures(array $arrayOfData, array $args = []): array
    {
        array_walk(
            $arrayOfData,
            function (&$item, $index, $args) {
                $item = self::expandClosure($item, $args);
            },
            $args
        );

        return $arrayOfData;
    }
}
