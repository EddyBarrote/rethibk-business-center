<?php

use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

// Tenant channels from section 10.1 of docs/SPEC.md. Run and approval channels
// arrive with the agent_runs and approvals tables in E02.

Broadcast::channel('tenant.{tenantId}.agents', fn (User $user, int $tenantId) => $user->tenant_id === $tenantId);

Broadcast::channel('tenant.{tenantId}.user.{userId}', fn (User $user, int $tenantId, int $userId) => $user->tenant_id === $tenantId && $user->id === $userId);
