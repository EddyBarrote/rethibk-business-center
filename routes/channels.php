<?php

use App\Models\AgentRun;
use App\Models\Approval;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Tenant channels (section 10.1 of docs/SPEC.md). The broadcasting auth route
// runs on the tenant host, so tenant-scoped queries here are already scoped.

Broadcast::channel('tenant.{tenantId}.agents', fn (User $user, int $tenantId) => $user->tenant_id === $tenantId);

Broadcast::channel('tenant.{tenantId}.run.{runId}', fn (User $user, int $tenantId, int $runId) => $user->tenant_id === $tenantId
    && AgentRun::query()->whereKey($runId)->where('tenant_id', $tenantId)->exists());

Broadcast::channel('tenant.{tenantId}.approvals', fn (User $user, int $tenantId) => $user->tenant_id === $tenantId
    && $user->can('viewAll', Approval::class));

Broadcast::channel('tenant.{tenantId}.user.{userId}', fn (User $user, int $tenantId, int $userId) => $user->tenant_id === $tenantId && $user->id === $userId);
