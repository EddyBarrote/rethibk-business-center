<?php

namespace App\Models;

use App\Concerns\BelongsToTenant;
use App\Enums\ActorType;
use App\Enums\AuditResult;
use Database\Factories\AuditLogFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * Append-only audit trail (sections 3.2.4 and 5.4). Rows can be written and
 * read, never changed or removed.
 *
 * @property int $id
 * @property int $tenant_id
 * @property ActorType $actor_type
 * @property int|null $actor_id
 * @property string $action
 * @property array<string, mixed>|null $payload
 * @property AuditResult $result
 * @property string|null $ip
 * @property Carbon $created_at
 */
class AuditLog extends Model
{
    /** @use HasFactory<AuditLogFactory> */
    use BelongsToTenant, HasFactory;

    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('audit_logs is append-only.'));
        static::deleting(fn () => throw new LogicException('audit_logs is append-only.'));
    }

    /**
     * Record an action. The actor is a user, an agent, or null for the system.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function record(
        ?Model $actor,
        string $action,
        array $payload = [],
        AuditResult|string $result = AuditResult::Ok,
        ?Model $subject = null,
    ): self {
        $request = app()->runningInConsole() ? null : request();

        return static::query()->create([
            'actor_type' => self::actorTypeFor($actor),
            'actor_id' => $actor?->getKey(),
            'action' => $action,
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'payload' => $payload,
            'result' => $result instanceof AuditResult ? $result : AuditResult::from($result),
            'ip' => $request?->ip(),
        ]);
    }

    private static function actorTypeFor(?Model $actor): ActorType
    {
        return match (true) {
            $actor === null => ActorType::System,
            $actor instanceof User => ActorType::User,
            default => ActorType::Agent,
        };
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    protected function casts(): array
    {
        return [
            'actor_type' => ActorType::class,
            'result' => AuditResult::class,
            'payload' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
