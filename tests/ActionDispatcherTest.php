<?php

namespace HelfenTalk\Connect\Tests;

use HelfenTalk\Connect\HelfenTalkConnectServiceProvider;
use HelfenTalk\Connect\Support\ActionDispatcher;
use HelfenTalk\Connect\Support\SchemaInspector;
use HelfenTalk\Connect\Tests\Fixtures\AccUser;
use HelfenTalk\Connect\Tests\Fixtures\AccWorker;
use HelfenTalk\Connect\Tests\Fixtures\AccWorkerController;
use Illuminate\Support\Facades\Schema;
use Orchestra\Testbench\TestCase;

class ActionDispatcherTest extends TestCase
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
        $app['config']->set('helfentalk.auth', ['model' => AccUser::class, 'key' => 'id', 'guard' => null]);
        $app['config']->set('helfentalk.audit', ['enabled' => true, 'table' => 'helfentalk_audit_logs']);
        $app['config']->set('helfentalk.actions', [
            'change_worker_status' => [
                'label' => 'Change a worker’s status',
                'controller' => [AccWorkerController::class, 'update'],
                'inputs' => [
                    'worker' => 'The worker ID to update',
                    'status' => 'New status: active or inactive',
                ],
                'confirm' => true,
                'roles' => ['admin'],
                'bindings' => ['worker' => AccWorker::class],
            ],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function ($table)
        {
            $table->id();
            $table->string('name')->nullable();
        });

        Schema::create('workers', function ($table)
        {
            $table->id();
            $table->string('name');
            $table->string('status')->default('active');
            $table->unsignedBigInteger('updated_by')->nullable();
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

        AccUser::create(['id' => 9, 'name' => 'Boss']);
        AccWorker::create(['id' => 1, 'name' => 'Ahmad', 'status' => 'active']);
    }

    private function dispatcher(): ActionDispatcher
    {
        return new ActionDispatcher(new SchemaInspector(null));
    }

    private array $admin = ['user_id' => 9, 'role' => 'admin'];

    public function test_preview_does_not_run_the_controller(): void
    {
        $result = $this->dispatcher()->dispatch(
            ['name' => 'change_worker_status', 'values' => ['worker' => 1, 'status' => 'inactive'], 'confirm' => false],
            $this->admin,
        );

        $this->assertTrue($result['ok']);
        $this->assertTrue($result['preview']);
        $this->assertSame(['worker' => 1, 'status' => 'inactive'], $result['inputs']);
        $this->assertSame('active', AccWorker::find(1)->status, 'preview must not change data');
    }

    public function test_confirm_runs_the_clients_controller(): void
    {
        $result = $this->dispatcher()->dispatch(
            ['name' => 'change_worker_status', 'values' => ['worker' => 1, 'status' => 'inactive'], 'confirm' => true],
            $this->admin,
        );

        $this->assertTrue($result['ok']);
        $this->assertSame('inactive', AccWorker::find(1)->status);
        // Controller stamped the acting user via auth()->id() — proves the user was logged in.
        $this->assertSame(9, (int) AccWorker::find(1)->updated_by);
    }

    public function test_controller_validation_is_surfaced(): void
    {
        $result = $this->dispatcher()->dispatch(
            ['name' => 'change_worker_status', 'values' => ['worker' => 1, 'status' => 'banana'], 'confirm' => true],
            $this->admin,
        );

        $this->assertFalse($result['ok']);
        $this->assertArrayHasKey('status', $result['errors']);
        $this->assertSame('active', AccWorker::find(1)->status, 'rejected write must not change data');
    }

    public function test_role_not_on_allow_list_is_denied(): void
    {
        $result = $this->dispatcher()->dispatch(
            ['name' => 'change_worker_status', 'values' => ['worker' => 1, 'status' => 'inactive'], 'confirm' => true],
            ['user_id' => 9, 'role' => 'employee'],
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not permitted', $result['error']);
        $this->assertSame('active', AccWorker::find(1)->status);
    }

    public function test_undeclared_action_is_refused(): void
    {
        $result = $this->dispatcher()->dispatch(
            ['name' => 'wipe_database', 'values' => [], 'confirm' => true],
            $this->admin,
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('not available', $result['error']);
        $this->assertFalse(ActionDispatcher::isDeclared('wipe_database'));
        $this->assertTrue(ActionDispatcher::isDeclared('change_worker_status'));
    }
}

namespace HelfenTalk\Connect\Tests\Fixtures;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Http\Request;

class AccUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class AccWorker extends Model
{
    protected $table = 'workers';

    public $timestamps = true;

    protected $guarded = [];
}

class AccWorkerController
{
    public function update(Request $request, AccWorker $worker): array
    {
        $data = $request->validate(['status' => 'required|in:active,inactive']);

        $worker->update(['status' => $data['status'], 'updated_by' => auth()->id()]);

        return ['id' => $worker->id, 'status' => $worker->status, 'by' => auth()->id()];
    }
}
