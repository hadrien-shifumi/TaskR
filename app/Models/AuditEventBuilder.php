<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Eloquent builder of the append-only audit journal.
 *
 * Mass updates and deletes are executed straight against the query builder,
 * without firing model events: they are refused here so no code path can
 * rewrite or erase history.
 *
 * @extends Builder<AuditEvent>
 */
class AuditEventBuilder extends Builder
{
    public function update(array $values)
    {
        throw new LogicException('Audit events are append-only.');
    }

    public function delete()
    {
        throw new LogicException('Audit events are append-only.');
    }

    public function forceDelete()
    {
        throw new LogicException('Audit events are append-only.');
    }
}
