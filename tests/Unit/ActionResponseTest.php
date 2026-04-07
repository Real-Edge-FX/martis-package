<?php

namespace Tests\Unit;

use Martis\Actions\ActionResponse;
use PHPUnit\Framework\TestCase;

class ActionResponseTest extends TestCase
{
    public function test_message_response(): void
    {
        $response = ActionResponse::message('Success');
        $data = $response->jsonSerialize();
        $this->assertEquals('message', $data['type']);
        $this->assertEquals('Success', $data['data']['message']);
    }

    public function test_danger_response(): void
    {
        $response = ActionResponse::danger('Failed');
        $data = $response->jsonSerialize();
        $this->assertEquals('danger', $data['type']);
        $this->assertEquals('Failed', $data['data']['message']);
    }

    public function test_redirect_response(): void
    {
        $response = ActionResponse::redirect('/dashboard');
        $data = $response->jsonSerialize();
        $this->assertEquals('redirect', $data['type']);
        $this->assertEquals('/dashboard', $data['data']['url']);
    }

    public function test_visit_response(): void
    {
        $response = ActionResponse::visit('/settings');
        $data = $response->jsonSerialize();
        $this->assertEquals('visit', $data['type']);
        $this->assertEquals('/settings', $data['data']['path']);
    }

    public function test_open_in_new_tab_response(): void
    {
        $response = ActionResponse::openInNewTab('https://example.com');
        $data = $response->jsonSerialize();
        $this->assertEquals('openInNewTab', $data['type']);
        $this->assertEquals('https://example.com', $data['data']['url']);
    }

    public function test_download_response(): void
    {
        $response = ActionResponse::download('report.csv', '/export.csv');
        $data = $response->jsonSerialize();
        $this->assertEquals('download', $data['type']);
        $this->assertEquals('/export.csv', $data['data']['url']);
        $this->assertEquals('report.csv', $data['data']['filename']);
    }

    public function test_emit_response(): void
    {
        $response = ActionResponse::emit('post-published', ['id' => 1]);
        $data = $response->jsonSerialize();
        $this->assertEquals('emit', $data['type']);
        $this->assertEquals('post-published', $data['data']['event']);
        $this->assertEquals(['id' => 1], $data['data']['data']);
    }

    public function test_modal_response(): void
    {
        $response = ActionResponse::modal('ConfirmDialog', ['title' => 'Done']);
        $data = $response->jsonSerialize();
        $this->assertEquals('modal', $data['type']);
        $this->assertEquals('ConfirmDialog', $data['data']['component']);
        $this->assertEquals(['title' => 'Done'], $data['data']['data']);
    }
}
