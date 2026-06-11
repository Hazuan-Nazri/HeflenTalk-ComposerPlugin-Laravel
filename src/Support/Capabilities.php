<?php

namespace HelfenTalk\Connect\Support;

/**
 * Pure per-role × per-table capability resolution. Like RoleScope, the plugin
 * is the authoritative enforcer — it recomputes capabilities from its OWN
 * config and never trusts anything HeflenTalk sends.
 *
 * 'view' is the base read capability; 'create', 'edit' and 'delete' layer on
 * top (granting any write implies 'view'). A table-specific entry overrides the
 * 'all' wildcard entry for that table.
 */
class Capabilities
{
    public const KNOWN = ['view', 'create', 'edit', 'delete'];

    private const WRITES = ['create', 'edit', 'delete'];

    /**
     * @param  array<string, array<string, array<int, string>>>  $matrix
     * @return array<int, string>
     */
    public static function resolve(array $matrix, ?string $role, string $table): array
    {
        $roleCaps = $matrix[$role ?? ''] ?? [];

        $caps = $roleCaps[$table] ?? $roleCaps['all'] ?? [];
        $caps = array_values(array_intersect(self::KNOWN, $caps));

        // Any write capability implies the ability to view.
        if ($caps !== [] && ! in_array('view', $caps, true) && array_intersect(self::WRITES, $caps))
        {
            array_unshift($caps, 'view');
        }

        return $caps;
    }

    /**
     * @param  array<string, array<string, array<int, string>>>  $matrix
     */
    public static function allows(array $matrix, ?string $role, string $table, string $capability): bool
    {
        return in_array($capability, self::resolve($matrix, $role, $table), true);
    }

    /**
     * The capability a given write operation requires.
     */
    public static function capabilityForOperation(string $operation): string
    {
        return match ($operation)
        {
            'create' => 'create',
            'update', 'edit' => 'edit',
            'delete' => 'delete',
            default => 'view',
        };
    }
}
