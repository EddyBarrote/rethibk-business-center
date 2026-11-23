<?php

namespace App\Tenancy;

use RuntimeException;

/**
 * A company pack that cannot be loaded as it is (TenantPack): the message
 * says what to fix, in the words of the person editing the pack.
 */
final class TenantPackException extends RuntimeException {}
