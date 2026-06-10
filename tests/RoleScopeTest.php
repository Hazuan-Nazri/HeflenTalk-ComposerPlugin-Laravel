<?php

namespace HelfenTalk\Connect\Tests;

use HelfenTalk\Connect\Support\RoleScope;
use PHPUnit\Framework\TestCase;

class RoleScopeTest extends TestCase
{
    private array $rules = [
        'employee' => ['own_data_only' => true],
        'manager' => ['scope' => 'team'],
        'admin' => ['scope' => 'all'],
    ];

    public function test_resolves_known_roles(): void
    {
        $this->assertSame('own', RoleScope::resolve($this->rules, 'employee'));
        $this->assertSame('team', RoleScope::resolve($this->rules, 'manager'));
        $this->assertSame('all', RoleScope::resolve($this->rules, 'admin'));
    }

    public function test_unknown_or_missing_role_defaults_to_own(): void
    {
        $this->assertSame('own', RoleScope::resolve($this->rules, 'intern'));
        $this->assertSame('own', RoleScope::resolve($this->rules, null));
        $this->assertSame('own', RoleScope::resolve([], 'admin'));
    }
}
