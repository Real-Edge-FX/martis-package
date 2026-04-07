<?php

namespace Tests\Unit;

use Martis\Actions\Action;
use Martis\Actions\ActionFields;
use Martis\Actions\ActionResponse;
use Martis\Actions\DestructiveAction;
use Martis\Enums\ActionExecutionMode;
use Martis\Enums\ModalSize;
use PHPUnit\Framework\TestCase;
use Illuminate\Support\Collection;
use Illuminate\Http\Request;

class ActionTest extends TestCase
{
    public function test_action_has_default_name(): void
    {
        $action = new class extends Action {
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
        $request = new Request();
        $this->assertFalse($action->authorizedToSee($request));
    }

    public function test_can_see_default_true(): void
    {
        $action = TestableAction::make();
        $request = new Request();
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
            'uriKey', 'name', 'destructive', 'showOnIndex', 'showOnDetail',
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
