<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Martis\Fields\Text;
use Martis\Http\Middleware\MartisAuthenticate;
use Martis\MartisManager;
use Martis\Menu\MenuSection;
use Martis\Resource;
use Martis\ResourceRegistry;
use Martis\Tools\Tool;

/*
 * Tools in the bundled "System" sidebar section (v1.35.0).
 *
 * Resources could opt into the package's System section via
 * `belongsToSystemSection()`; Tools only had `menuSection()`, so a Tool
 * returning the translated "System" label produced a *second* section
 * with the same header. `Tool::withSystemSection()` gives Tools the same
 * opt-in and the navigation builder merges them into the single bundled
 * section: system resources first, then tools, then the Cache admin link.
 */

class ToolSysSecUser extends User
{
    protected $table = 'users';

    protected $guarded = [];
}

class ToolSysSecModel extends Model
{
    protected $table = 'martis_test_tool_system_items';

    protected $fillable = ['name'];
}

class ToolSysSecRolesResource extends Resource
{
    public static function model(): string
    {
        return ToolSysSecModel::class;
    }

    public static function uriKey(): string
    {
        return 'tool-system-roles';
    }

    public static function label(): string
    {
        return 'Roles';
    }

    public static function singularLabel(): string
    {
        return 'Role';
    }

    public function belongsToSystemSection(): bool
    {
        return true;
    }

    public function fields(Request $request): array
    {
        return [Text::make('name')];
    }
}

class ToolSysSecHealthTool extends Tool
{
    public function __construct()
    {
        parent::__construct(name: 'Health', uriKey: 'tool-system-health');
        $this->withIcon('pulse')->withSystemSection();
    }

    public function menuCount(Request $request): ?int
    {
        return 3;
    }
}

beforeEach(function () {
    $this->withoutMiddleware(MartisAuthenticate::class);

    Schema::dropIfExists('martis_test_tool_system_items');
    Schema::create('martis_test_tool_system_items', function ($table) {
        $table->id();
        $table->string('name');
        $table->timestamps();
    });

    if (! Schema::hasTable('users')) {
        Schema::create('users', function ($table) {
            $table->id();
            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->timestamps();
        });
    }

    $this->testUser = ToolSysSecUser::query()->create([
        'name' => 'Sys Admin',
        'email' => 'toolsysadmin@martis.test',
        'password' => bcrypt('secret'),
    ]);

    app(MartisManager::class)->forgetMainMenu();
    app(MartisManager::class)->tools([]);
    app(ResourceRegistry::class)->flush();
});

afterEach(function () {
    app(MartisManager::class)->forgetMainMenu();
    app(MartisManager::class)->tools([]);
    app(ResourceRegistry::class)->flush();
    Schema::dropIfExists('martis_test_tool_system_items');
});

it('Tool::belongsToSystemSection() defaults to false and withSystemSection() toggles it', function () {
    $tool = Tool::make('Settings', 'tool-system-settings');

    expect($tool->belongsToSystemSection())->toBeFalse();
    expect($tool->toArray()['belongsToSystemSection'])->toBeFalse();

    // Fluent: returns the same instance so it chains in the constructor.
    expect($tool->withSystemSection())->toBe($tool);
    expect($tool->belongsToSystemSection())->toBeTrue();
    expect($tool->toArray()['belongsToSystemSection'])->toBeTrue();

    $tool->withSystemSection(false);
    expect($tool->belongsToSystemSection())->toBeFalse();
});
