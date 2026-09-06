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

    /**
     * One column per table that the current schema is known to have.
     *
     * A table that exists but is behind is worse than one that is missing: jobs
     * keep succeeding, every insert fails inside the rescue boundary, and the
     * dashboard just goes quiet. This turns that into a sentence.
     *
     * @var array<string, string>
     */
    protected const CANARIES = [
        'runs_table' => 'fingerprint',
        'buckets_table' => 'sample_rate',
        'actions_table' => 'action',
        'issues_table' => 'fingerprint',
        'workers_table' => 'last_seen',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        [$missingTables, $staleTables] = $this->inspect();

        if ($missingTables !== []) {
            return new JsonResponse([
                'message' => 'Yardmaster has not been migrated yet. Run: php artisan migrate',
                'missing_tables' => $missingTables,
            ], 503);
        }

        if ($staleTables !== []) {
            return new JsonResponse([
                'message' => 'Yardmaster\'s schema is out of date. Run: php artisan migrate',
                'stale_tables' => $staleTables,
            ], 503);
        }

        return $next($request);
    }

    /**
     * @return array{0: array<int, string>, 1: array<int, string>}
     */
    protected function inspect(): array
    {
        $name = $this->config->get('yardmaster.storage.connection');

        try {
            $schema = $this->db->connection(is_string($name) ? $name : null)->getSchemaBuilder();
        } catch (Throwable) {
            // An unreachable database is a different problem with its own
            // error; do not mask it with a migration hint.
            return [[], []];
        }

        $missing = [];
        $stale = [];

        foreach (self::CANARIES as $key => $column) {
            $table = $this->config->get("yardmaster.storage.{$key}");

            if (! is_string($table)) {
                continue;
            }

            if (! $schema->hasTable($table)) {
                $missing[] = $table;
            } elseif (! $schema->hasColumn($table, $column)) {
                $stale[] = $table;
            }
        }

        return [$missing, $stale];
    }
}
