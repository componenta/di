<?php

declare(strict_types=1);

namespace Componenta\DI\Internal\Resolver\Parameter\Request;

use Closure;
use Fiber;

/** Collects result checks while a parameter attribute handler executes. @internal */
final class MappedRequestResultCapture
{
    /** @var array<int,list<MappedRequestPublication|null>> */
    private static array $scopes = [];

    public static function begin(): int
    {
        $execution = self::executionId();
        self::$scopes[$execution][] = null;
        return $execution;
    }

    public static function end(int $execution): ?MappedRequestPublication
    {
        $publication = array_pop(self::$scopes[$execution]);
        if (self::$scopes[$execution] === []) {
            unset(self::$scopes[$execution]);
        }
        return $publication;
    }

    /** @param list<Closure():void> $checks */
    public static function record(object $result, array $checks): void
    {
        $execution = self::executionId();
        if (isset(self::$scopes[$execution])) {
            $scopes = &self::$scopes[$execution];
            $publication = array_pop($scopes) ?? new MappedRequestPublication();
            $scopes[] = $publication;
            $publication->record($result, $checks);
        }
    }

    private static function executionId(): int
    {
        // Avoid retaining a suspended Fiber that the caller can abandon.
        $fiber = Fiber::getCurrent();
        return $fiber === null ? 0 : spl_object_id($fiber);
    }

    private function __construct() {}
}
