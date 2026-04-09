<?php

namespace Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\DestructiveAction;
use Martis\Enums\ModalSize;
use PHPUnit\Framework\TestCase;

class ActionTest extends TestCase
{
    public function test_action_has_default_name(): void
    {
        $action = new class extends Action
        {
            public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
            {
                return ActionResponse::message('Done');
            }
        };

        $this->assertNotEmpty($action->name());
    }

    public function test_make_creates_instance(): void
    {
        $action = TestableAction::make();
        $this->assertInstanceOf(TestableAction::class, $action);
    }

    public function test_action_uri_key(): void
    {
        $action = TestableAction::make();
        $this->assertEquals('testable-action', $action->uriKey());
    }

    public function test_action_is_not_destructive_by_default(): void
    {
        $action = TestableAction::make();
        $this->assertFalse($action->isDestructive());
    }

    public function test_destructive_action_is_destructive(): void
    {
        $action = TestableDestructiveAction::make();
        $this->assertTrue($action->isDestructive());
    }

    public function test_visibility_defaults(): void
    {
        $action = TestableAction::make();
        $json = $action->jsonSerialize();
        $this->assertTrue($json['showOnIndex']);
        $this->assertTrue($json['showOnDetail']);
        $this->assertFalse($json['showInline']);
    }

    public function test_only_on_index(): void
    {
        $action = TestableAction::make()->onlyOnIndex();
        $json = $action->jsonSerialize();
        $this->assertTrue($json['showOnIndex']);
        $this->assertFalse($json['showOnDetail']);
        $this->assertFalse($json['showInline']);
    }

    public function test_only_on_detail(): void
    {
        $action = TestableAction::make()->onlyOnDetail();
        $json = $action->jsonSerialize();
        $this->assertFalse($json['showOnIndex']);
        $this->assertTrue($json['showOnDetail']);
        $this->assertFalse($json['showInline']);
    }

    public function test_only_inline(): void
    {
        $action = TestableAction::make()->onlyInline();
        $json = $action->jsonSerialize();
        $this->assertFalse($json['showOnIndex']);
        $this->assertFalse($json['showOnDetail']);
        $this->assertTrue($json['showInline']);
    }

    public function test_except_on_index(): void
    {
        $action = TestableAction::make()->exceptOnIndex();
        $json = $action->jsonSerialize();
        $this->assertFalse($json['showOnIndex']);
        $this->assertTrue($json['showOnDetail']);
    }

    public function test_except_on_detail(): void
    {
        $action = TestableAction::make()->exceptOnDetail();
        $json = $action->jsonSerialize();
        $this->assertTrue($json['showOnIndex']);
        $this->assertFalse($json['showOnDetail']);
    }

    public function test_standalone_mode(): void
    {
        $action = TestableAction::make()->standalone();
        $json = $action->jsonSerialize();
        $this->assertEquals('standalone', $json['executionMode']);
        $this->assertTrue($json['standalone']);
    }

    public function test_sole_mode(): void
    {
        $action = TestableAction::make()->sole();
        $json = $action->jsonSerialize();
        $this->assertEquals('sole', $json['executionMode']);
        $this->assertTrue($json['sole']);
    }

    public function test_confirm_text(): void
    {
        $action = TestableAction::make()
            ->confirmText('Are you sure?')
            ->confirmButtonText('Yes')
            ->cancelButtonText('No');
        $json = $action->jsonSerialize();
        $this->assertEquals('Are you sure?', $json['confirmText']);
        $this->assertEquals('Yes', $json['confirmButtonText']);
        $this->assertEquals('No', $json['cancelButtonText']);
        $this->assertTrue($json['withConfirmation']);
    }

    public function test_without_confirmation(): void
    {
        $action = TestableAction::make()->withoutConfirmation();
        $json = $action->jsonSerialize();
        $this->assertFalse($json['withConfirmation']);
    }

    public function test_modal_size(): void
    {
        $action = TestableAction::make()->size(ModalSize::Large);
        $json = $action->jsonSerialize();
        $this->assertEquals('lg', $json['modalSize']);
    }

    public function test_fullscreen(): void
    {
        $action = TestableAction::make()->fullscreen();
        $json = $action->jsonSerialize();
        $this->assertEquals('fullscreen', $json['modalSize']);
    }

    public function test_without_action_events(): void
    {
        $action = TestableAction::make()->withoutActionEvents();
        $json = $action->jsonSerialize();
        $this->assertFalse($json['logEvents']);
    }

    public function test_can_see_callback(): void
    {
        $action = TestableAction::make()->canSee(fn () => false);
        $request = new Request;
        $this->assertFalse($action->authorizedToSee($request));
    }

    public function test_can_see_default_true(): void
    {
        $action = TestableAction::make();
        $request = new Request;
        $this->assertTrue($action->authorizedToSee($request));
    }

    public function test_dry_run_support(): void
    {
        $action = TestableAction::make()->withDryRun();
        $json = $action->jsonSerialize();
        $this->assertTrue($json['supportsDryRun']);
    }

    public function test_json_serialization_keys(): void
    {
        $action = TestableAction::make();
        $json = $action->jsonSerialize();

        $expectedKeys = [
            'uriKey', 'name', 'icon', 'showIcon', 'iconColor', 'group', 'destructive', 'showOnIndex', 'showOnDetail',
            'showInline', 'executionMode', 'standalone', 'sole', 'queued',
            'withConfirmation', 'confirmText', 'confirmButtonText',
            'cancelButtonText', 'modalSize', 'supportsDryRun',
            'customComponent', 'customComponentProps', 'logEvents',
        ];

        foreach ($expectedKeys as $key) {
            $this->assertArrayHasKey($key, $json, "Missing key: {$key}");
        }
    }

    public function test_closure_action_using(): void
    {
        $action = Action::using('My Closure Action', function (ActionFields $fields, Collection $models): ActionResponse|Action|null {
            return ActionResponse::message('Closure ran');
        });
        $this->assertEquals('My Closure Action', $action->name());
        $this->assertNotEmpty($action->uriKey());
    }

    public function test_then_callback(): void
    {
        $called = false;
        $action = TestableAction::make()->then(function () use (&$called) {
            $called = true;
        });
        $this->assertNotNull($action);
    }

    public function test_default_name_from_class(): void
    {
        $action = TestableAction::make();
        $this->assertEquals('Testable Action', $action->name());
    }

    // -------------------------------------------------------------------------
    // Authorization tests
    // -------------------------------------------------------------------------

    public function test_can_run_callback_grants(): void
    {
        $action = TestableAction::make()->canRun(fn () => true);
        $request = new Request;
        $model = new class extends Model {};
        $this->assertTrue($action->authorizedToRun($request, $model));
    }

    public function test_can_run_callback_denies(): void
    {
        $action = TestableAction::make()->canRun(fn () => false);
        $request = new Request;
        $model = new class extends Model {};
        $this->assertFalse($action->authorizedToRun($request, $model));
    }

    public function test_can_run_default_true(): void
    {
        $action = TestableAction::make();
        $request = new Request;
        $model = new class extends Model {};
        $this->assertTrue($action->authorizedToRun($request, $model));
    }

    public function test_can_run_receives_model(): void
    {
        $model = new class extends Model
        {
            public $status = 'draft';
        };
        $action = TestableAction::make()->canRun(fn ($r, $m) => $m->status === 'draft');
        $request = new Request;
        $this->assertTrue($action->authorizedToRun($request, $model));

        $model2 = new class extends Model
        {
            public $status = 'published';
        };
        $this->assertFalse($action->authorizedToRun($request, $model2));
    }

    public function test_can_see_and_can_run_independent(): void
    {
        $action = TestableAction::make()
            ->canSee(fn () => true)
            ->canRun(fn () => false);
        $request = new Request;
        $model = new class extends Model {};
        $this->assertTrue($action->authorizedToSee($request));
        $this->assertFalse($action->authorizedToRun($request, $model));
    }

    // -------------------------------------------------------------------------
    // Icon and group serialization tests
    // -------------------------------------------------------------------------

    public function test_icon_serialization(): void
    {
        $action = TestableAction::make()->icon('rocket-launch');
        $json = $action->jsonSerialize();
        $this->assertEquals('rocket-launch', $json['icon']);
    }

    public function test_group_serialization(): void
    {
        $action = TestableAction::make()->group('Export');
        $json = $action->jsonSerialize();
        $this->assertEquals('Export', $json['group']);
    }

    public function test_icon_and_group_default_null(): void
    {
        $action = TestableAction::make();
        $json = $action->jsonSerialize();
        $this->assertNull($json['icon']);
        $this->assertNull($json['group']);
    }

    public function test_nested_group_serialization(): void
    {
        $action = TestableAction::make()->group('Notifications.Email');
        $json = $action->jsonSerialize();
        $this->assertEquals('Notifications.Email', $json['group']);
    }

    // -------------------------------------------------------------------------
    // withoutIcon and iconColor tests
    // -------------------------------------------------------------------------

    public function test_show_icon_default_true(): void
    {
        $action = TestableAction::make();
        $json = $action->jsonSerialize();
        $this->assertTrue($json['showIcon']);
        $this->assertNull($json['iconColor']);
    }

    public function test_without_icon_serialization(): void
    {
        $action = TestableAction::make()->withoutIcon();
        $json = $action->jsonSerialize();
        $this->assertFalse($json['showIcon']);
    }

    public function test_without_icon_does_not_affect_icon_name(): void
    {
        $action = TestableAction::make()->icon('trash')->withoutIcon();
        $json = $action->jsonSerialize();
        $this->assertEquals('trash', $json['icon']);
        $this->assertFalse($json['showIcon']);
    }

    public function test_icon_color_serialization(): void
    {
        $action = TestableAction::make()->iconColor('#dc2626');
        $json = $action->jsonSerialize();
        $this->assertEquals('#dc2626', $json['iconColor']);
    }

    public function test_icon_color_with_css_variable(): void
    {
        $action = TestableAction::make()->iconColor('var(--martis-danger)');
        $json = $action->jsonSerialize();
        $this->assertEquals('var(--martis-danger)', $json['iconColor']);
    }

    public function test_without_icon_and_icon_color_combined(): void
    {
        $action = TestableAction::make()->icon('trash')->iconColor('#dc2626')->withoutIcon();
        $json = $action->jsonSerialize();
        $this->assertFalse($json['showIcon']);
        $this->assertEquals('#dc2626', $json['iconColor']);
        $this->assertEquals('trash', $json['icon']);
    }

    public function test_is_showing_icon_method(): void
    {
        $action = TestableAction::make();
        $this->assertTrue($action->isShowingIcon());

        $action->withoutIcon();
        $this->assertFalse($action->isShowingIcon());
    }

    public function test_get_icon_color_method(): void
    {
        $action = TestableAction::make();
        $this->assertNull($action->getIconColor());

        $action->iconColor('#ff0000');
        $this->assertEquals('#ff0000', $action->getIconColor());
    }
}

class TestableAction extends Action
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Test done');
    }
}

class TestableDestructiveAction extends DestructiveAction
{
    public function handle(ActionFields $fields, Collection $models): ActionResponse|Action|null
    {
        return ActionResponse::message('Destroyed');
    }
}
