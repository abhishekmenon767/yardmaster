<?php

namespace Abhishek\Yardmaster\Actions;

use Abhishek\Yardmaster\Events\ActionPerformed;
use Abhishek\Yardmaster\Support\Cast;
use Illuminate\Contracts\Auth\Guard;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * Writes the audit trail.
 *
 * Listens for one event, so nothing that changes queue state can be recorded in
 * one place and forgotten in another. The actor is resolved from the current
 * request where there is one; a console-initiated action honestly records none.
 */
class AuditLog
{
    public function __construct(
        protected DatabaseManager $db,
        protected Config $config,
        protected ?Guard $auth = null,
        protected ?Request $request = null,
    ) {}

    public function record(ActionPerformed $event): void
    {
        $actor = $this->actor();

        $this->table()->insert([
            'action' => $event->action,
            'connection' => $event->connectionName,
            'driver' => $event->driver,
            'queue' => $event->queue,
            'target' => $event->target,
            'succeeded' => $event->succeeded(),
            'affected' => is_int($event->result) ? $event->result : null,
            'actor_id' => $actor['id'],
            'actor_name' => $actor['name'],
            'actor_ip' => $this->request?->ip(),
            'context' => $event->context === [] ? null : json_encode($event->context),
            'created_at' => $event->at ?? microtime(true),
        ]);
    }

    /**
     * @return array{id: string|null, name: string|null}
     */
    protected function actor(): array
    {
        $user = $this->auth?->user();

        if ($user === null) {
            return ['id' => null, 'name' => null];
        }

        $name = null;

        foreach (['name', 'email', 'username'] as $attribute) {
            $value = data_get($user, $attribute);

            if (is_string($value) && $value !== '') {
                $name = $value;

                break;
            }
        }

        return [
            'id' => ($id = $user->getAuthIdentifier()) === null ? null : Cast::string($id),
            'name' => $name,
        ];
    }

    protected function table(): Builder
    {
        $connection = $this->config->get('yardmaster.storage.connection');
        $table = $this->config->get('yardmaster.storage.actions_table', 'yard_actions');

        return $this->db->connection(is_string($connection) ? $connection : null)
            ->table(is_string($table) ? $table : 'yard_actions');
    }
}
