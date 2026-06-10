<?php

namespace HelfenTalk\Connect\Support;

/**
 * Pure role-to-scope resolution. The plugin is the authoritative enforcer of
 * role rules — it never trusts a scope sent by HeflenTalk, it recomputes from
 * its own configured role_rules.
 */
class RoleScope
{
    /**
     * @param  array<string, array<string, mixed>>  $rules
     */
    public static function resolve(array $rules, ?string $role): string
    {
        $rule = $rules[$role ?? ''] ?? [];

        if (! empty($rule['own_data_only']))
        {
            return 'own';
        }

        if (! empty($rule['scope']) && in_array($rule['scope'], ['own', 'team', 'all'], true))
        {
            return $rule['scope'];
        }

        return 'own';
    }
}
