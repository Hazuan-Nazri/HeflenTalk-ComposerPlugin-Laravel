<?php

namespace HelfenTalk\Connect\Tests;

use HelfenTalk\Connect\HelfenTalkConnectServiceProvider;
use HelfenTalk\Connect\Support\ActionRunner;
use HelfenTalk\Connect\Support\SchemaInspector;
use HelfenTalk\Connect\Tests\Fixtures\Worker;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class ActionRunnerTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [HelfenTalkConnectServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testbench');
        $app['config']->set('database.connections.testbench', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
        ]);

        $app['config']->set('helfentalk.connection', null);
        $app['config']->set('helfentalk.allowed_tables', ['workers']);
        $app['config']->set('helfentalk.models', ['workers' => Worker::class]);
        $app['config']->set('helfentalk.capabilities', [
            'admin' => ['all' => ['view', 'create', 'edit', 'delete']],
            'employee' => ['all' => ['view']],
        ]);
        $app['config']->set('helfentalk.writable_columns', ['workers' => ['name', 'status']]);
        $app['config']->set('helfentalk.role_rules', [
            'admin' => ['scope' => 'all'],
            'employee' => ['own_data_only' => true],
        ]);
        $app['config']->set('helfentalk.user_column', 'user_id');
        $app['config']->set('helfentalk.max_write_rows', 5);
        $app['config']->set('helfentalk.audit', ['enabled' => true, 'table' => 'helfentalk_audit_logs']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('workers', function ($table)
        {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('name');
            $table->string('status')->default('active');
            $table->softDeletes();
            $table->timestamps();
        });

        Schema::create('helfentalk_audit_logs', function ($table)
        {
            $table->id();
            $table->string('user_id')->nullable();
            $table->string('role')->nullable();
            $table->string('table_name');
            $table->string('operation');
            $table->json('detail')->nullable();
            $table->unsignedInteger('affected_rows')->default(0);
            $table->boolean('committed')->default(false);
            $table->timestamp('created_at')->nullable();
        });

        Worker::create(['id' => 1, 'user_id' => 1, 'name' => 'Ahmad', 'status' => 'active']);
        Worker::create(['id' => 2, 'user_id' => 2, 'name' => 'Siti', 'status' => 'active']);
        Worker::create(['id' => 3, 'user_id' => 1, 'name' => 'Budi', 'status' => 'active']);
    }

    private function runner(): ActionRunner
    {
        return new ActionRunner(new SchemaInspector(null));
    }

    private array $admin = ['user_id' => 99, 'role' => 'admin'];

    public function test_preview_update_does_not_commit(): void
    {
        $result = $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'update', 'filters' => ['id' => 1], 'values' => ['status' => 'inactive']],
            $this->admin,
            'all',
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['preview']);
        $this->assertSame(1, $result['matched']);
        $this->assertSame('active', Worker::find(1)->status, 'preview must not change data');
    }

    public function test_confirm_update_commits(): void
    {
        $result = $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'update', 'filters' => ['id' => 1], 'values' => ['status' => 'inactive'], 'confirm' => true],
            $this->admin,
            'all',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['affected']);
        $this->assertSame('inactive', Worker::find(1)->status);
    }

    public function test_confirm_delete_soft_deletes_via_model(): void
    {
        $result = $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'delete', 'filters' => ['id' => 1], 'confirm' => true],
            $this->admin,
            'all',
        );

        $this->assertTrue($result['ok']);
        $this->assertNull(Worker::find(1), 'row should be hidden by soft delete');
        $this->assertNotNull(Worker::withTrashed()->find(1)->deleted_at, 'soft-delete column should be set');
    }

    public function test_scope_prevents_touching_other_users_rows(): void
    {
        // employee scope = own (user_id = 2); worker 1 belongs to user 1.
        $context = ['user_id' => 2, 'role' => 'employee'];

        // Give employee edit just to isolate the SCOPE guard (capability passes).
        config(['helfentalk.capabilities.employee' => ['all' => ['view', 'edit']]]);

        $result = $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'update', 'filters' => ['id' => 1], 'values' => ['status' => 'x'], 'confirm' => true],
            $context,
            'own',
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No matching records', $result['error']);
        $this->assertSame('active', Worker::find(1)->status);
    }

    public function test_capability_denied_for_role_without_edit(): void
    {
        $result = $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'update', 'filters' => ['id' => 1], 'values' => ['status' => 'x'], 'confirm' => true],
            ['user_id' => 1, 'role' => 'employee'],
            'own',
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not permitted', $result['error']);
    }

    public function test_non_writable_values_are_rejected(): void
    {
        // 'id' is not in writable_columns -> nothing writable remains.
        $result = $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'update', 'filters' => ['id' => 1], 'values' => ['id' => 500], 'confirm' => true],
            $this->admin,
            'all',
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('No writable values', $result['error']);
        $this->assertNotNull(Worker::find(1));
    }

    public function test_row_cap_blocks_sweeping_updates(): void
    {
        config(['helfentalk.max_write_rows' => 1]);

        // Two workers have status 'active' for user 1 (ids 1 and 3) -> matches 3 total.
        $result = $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'update', 'filters' => ['status' => 'active'], 'values' => ['status' => 'inactive'], 'confirm' => true],
            $this->admin,
            'all',
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('limit', $result['error']);
    }

    public function test_audit_row_written_on_commit(): void
    {
        $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'update', 'filters' => ['id' => 1], 'values' => ['status' => 'inactive'], 'confirm' => true],
            $this->admin,
            'all',
        );

        $row = DB::table('helfentalk_audit_logs')->where('operation', 'update')->where('committed', true)->first();

        $this->assertNotNull($row);
        $this->assertSame('workers', $row->table_name);
        $this->assertSame(1, (int) $row->affected_rows);
    }

    public function test_create_anchors_ownership_under_own_scope(): void
    {
        config(['helfentalk.capabilities.staff' => ['all' => ['view', 'create']]]);

        $result = $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'create', 'values' => ['name' => 'New Hire', 'status' => 'active'], 'confirm' => true],
            ['user_id' => 7, 'role' => 'staff'],
            'own',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(7, (int) $result['created']['user_id'], 'new row should be owned by the actor');
    }

    public function test_count_respects_scope(): void
    {
        $result = $this->runner()->execute(
            ['table' => 'workers', 'operation' => 'count', 'filters' => []],
            ['user_id' => 1, 'role' => 'employee'],
            'own',
        );

        $this->assertTrue($result['ok']);
        $this->assertSame(2, $result['count'], 'employee sees only their own 2 rows');
    }
}

namespace HelfenTalk\Connect\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Worker extends Model
{
    use SoftDeletes;

    protected $table = 'workers';

    public $timestamps = true;

    protected $guarded = [];
}
