<?php

namespace Iocod\Yardmaster\Http\Middleware;

use Closure;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Answers the "I installed it and it broke" case before it happens.
 *
 * Without this, an application that has not run the migrations gets a raw
 * SQLSTATE dump on its first dashboard load, which reads like a bug in the
 * package rather than a step that was missed. One cheap schema check per API
 * request buys a sentence that tells the operator exactly what to do.
 */
class EnsureTablesExist
{
    public function __construct(
        protected DatabaseManager $db,
        protected Config $config,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        $missing = $this->missing();

        if ($missing !== []) {
            return new JsonResponse([
                'message' => 'Yardmaster has not been migrated yet. Run: php artisan migrate',
                'missing_tables' => $missing,
            ], 503);
        }

        return $next($request);
    }

    /**
     * @return array<int, string>
     */
    protected function missing(): array
    {
        $name = $this->config->get('yardmaster.storage.connection');

        try {
            $schema = $this->db->connection(is_string($name) ? $name : null)->getSchemaBuilder();
        } catch (Throwable) {
            // An unreachable database is a different problem with its own
            // error; do not mask it with a migration hint.
            return [];
        }

        $missing = [];

        foreach (['runs_table', 'buckets_table', 'actions_table'] as $key) {
            $table = $this->config->get("yardmaster.storage.{$key}");
            $table = is_string($table) ? $table : null;

            if ($table !== null && ! $schema->hasTable($table)) {
                $missing[] = $table;
            }
        }

        return $missing;
    }
}
