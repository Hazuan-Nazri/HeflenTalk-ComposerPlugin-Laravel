<?php

namespace HelfenTalk\Connect\Tests;

use HelfenTalk\Connect\Support\Capabilities;
use PHPUnit\Framework\TestCase;

class CapabilitiesTest extends TestCase
{
    private array $matrix = [
        'admin' => ['all' => ['view', 'create', 'edit', 'delete']],
        'manager' => ['workers' => ['view', 'edit'], 'all' => ['view']],
        'employee' => ['all' => ['view']],
    ];

    public function test_all_wildcard_applies_to_every_table(): void
    {
        $this->assertSame(['view', 'create', 'edit', 'delete'], Capabilities::resolve($this->matrix, 'admin', 'workers'));
        $this->assertSame(['view', 'create', 'edit', 'delete'], Capabilities::resolve($this->matrix, 'admin', 'epasses'));
    }

    public function test_table_specific_overrides_all(): void
    {
        $this->assertSame(['view', 'edit'], Capabilities::resolve($this->matrix, 'manager', 'workers'));
        // Other tables fall back to the 'all' entry.
        $this->assertSame(['view'], Capabilities::resolve($this->matrix, 'manager', 'epasses'));
    }

    public function test_unknown_role_or_table_has_no_capabilities(): void
    {
        $this->assertSame([], Capabilities::resolve($this->matrix, 'intern', 'workers'));
        $this->assertSame([], Capabilities::resolve([], 'admin', 'workers'));
        $this->assertSame([], Capabilities::resolve($this->matrix, null, 'workers'));
    }

    public function test_any_write_capability_implies_view(): void
    {
        $matrix = ['editor' => ['workers' => ['edit']]];

        $this->assertSame(['view', 'edit'], Capabilities::resolve($matrix, 'editor', 'workers'));
    }

    public function test_allows_checks_a_single_capability(): void
    {
        $this->assertTrue(Capabilities::allows($this->matrix, 'admin', 'workers', 'delete'));
        $this->assertFalse(Capabilities::allows($this->matrix, 'manager', 'workers', 'delete'));
        $this->assertTrue(Capabilities::allows($this->matrix, 'employee', 'workers', 'view'));
        $this->assertFalse(Capabilities::allows($this->matrix, 'employee', 'workers', 'edit'));
    }

    public function test_capability_for_operation(): void
    {
        $this->assertSame('create', Capabilities::capabilityForOperation('create'));
        $this->assertSame('edit', Capabilities::capabilityForOperation('update'));
        $this->assertSame('delete', Capabilities::capabilityForOperation('delete'));
        $this->assertSame('view', Capabilities::capabilityForOperation('query'));
        $this->assertSame('view', Capabilities::capabilityForOperation('count'));
    }
}
