<?php

namespace Abhishek\Yardmaster\Http\Controllers;

use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The audit trail, read back.
 */
class ActionController extends Controller
{
    public function __invoke(Request $request, DatabaseManager $db, Config $config): JsonResponse
    {
        $connection = $config->get('yardmaster.storage.connection');
        $table = $config->get('yardmaster.storage.actions_table', 'yard_actions');

        $query = $db->connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'yard_actions');

        foreach (['action', 'connection', 'actor_id'] as $column) {
            if (($value = $request->query($column)) !== null && $value !== '') {
                $query->where($column, $value);
            }
        }

        $perPage = max(1, min(200, (int) $request->query('per_page', 50)));

        return $this->json([
            'data' => $query->orderByDesc('created_at')
                ->forPage(max(1, (int) $request->query('page', 1)), $perPage)
                ->get()
                ->map(static function ($row) {
                    $row = (array) $row;

                    return [
                        'action' => (string) $row['action'],
                        'connection' => (string) $row['connection'],
                        'driver' => (string) $row['driver'],
                        'queue' => $row['queue'] === null ? null : (string) $row['queue'],
                        'target' => $row['target'] === null ? null : (string) $row['target'],
                        'succeeded' => (bool) $row['succeeded'],
                        'affected' => $row['affected'] === null ? null : (int) $row['affected'],
                        'actor_name' => $row['actor_name'] === null ? null : (string) $row['actor_name'],
                        'actor_ip' => $row['actor_ip'] === null ? null : (string) $row['actor_ip'],
                        'created_at' => (float) $row['created_at'],
                    ];
                })
                ->all(),
        ]);
    }
}
