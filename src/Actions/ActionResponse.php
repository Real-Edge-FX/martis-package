<?php

namespace Martis\Actions;

use Martis\Enums\ActionResponseType;

/**
 * Represents the response returned by an action after execution.
 *
 * Supports message, danger, redirect, visit, openInNewTab, download, emit,
 * modal, openCreate, and openDetail response types.
 */
class ActionResponse implements \JsonSerializable
{
    /**
     * @param  array<string, mixed>|null  $data
     */
    private function __construct(
        private readonly ActionResponseType $type,
        private readonly mixed $data = null,
    ) {}

    /** Success toast notification. */
    public static function message(string $message): self
    {
        return new self(ActionResponseType::Message, ['message' => $message]);
    }

    /** Red error toast notification. */
    public static function danger(string $message): self
    {
        return new self(ActionResponseType::Danger, ['message' => $message]);
    }

    /**
     * External URL redirect. `$url` is an `http(s)` URL or a path: any other
     * scheme (`javascript:`, `data:`, ...) throws, as the SPA would refuse to
     * follow it.
     *
     * @throws \InvalidArgumentException
     */
    public static function redirect(string $url): self
    {
        return new self(ActionResponseType::Redirect, ['url' => self::webUrl($url, 'redirect')]);
    }

    /**
     * SPA navigation to a path below the Martis base path, with $params as its query string (as Nova's visit()).
     *
     * @param  array<string, mixed>  $params
     */
    public static function visit(string $path, array $params = []): self
    {
        return new self(ActionResponseType::Visit, ['path' => $path, 'params' => $params]);
    }

    /**
     * Opens URL in new browser tab. `$url` is an `http(s)` URL or a path (see `redirect()`).
     *
     * @throws \InvalidArgumentException
     */
    public static function openInNewTab(string $url): self
    {
        return new self(ActionResponseType::OpenInNewTab, ['url' => self::webUrl($url, 'openInNewTab')]);
    }

    /**
     * Downloads $url as $filename through a link. `$url` is an `http(s)` URL
     * or a path (see `redirect()`).
     *
     * @throws \InvalidArgumentException
     */
    public static function download(string $filename, string $url): self
    {
        return new self(ActionResponseType::Download, ['filename' => $filename, 'url' => self::webUrl($url, 'download')]);
    }

    /**
     * Emits $eventName with $data on the SPA's Martis event bus (`martisEventBus`), as Nova's emit() uses `Nova.$emit`.
     *
     * @param  array<string, mixed>  $data
     */
    public static function emit(string $eventName, array $data = []): self
    {
        return new self(ActionResponseType::Emit, ['event' => $eventName, 'data' => $data]);
    }

    /**
     * Shows the component registered under $componentName (componentRegistry), with $data as its `data` prop.
     *
     * @param  array<string, mixed>  $data
     */
    public static function modal(string $componentName, array $data = []): self
    {
        return new self(ActionResponseType::Modal, ['component' => $componentName, 'data' => $data]);
    }

    /**
     * Opens the create drawer for the given resource.
     *
     * Example:
     *   return ActionResponse::openCreate('posts');
     */
    public static function openCreate(string $resourceName): self
    {
        return new self(ActionResponseType::OpenCreate, ['resource' => $resourceName]);
    }

    /**
     * Opens the detail drawer for the given resource record.
     *
     * Example:
     *   return ActionResponse::openDetail('posts', $model->id);
     */
    public static function openDetail(string $resourceName, string|int $recordId): self
    {
        return new self(ActionResponseType::OpenDetail, ['resource' => $resourceName, 'recordId' => $recordId]);
    }

    /**
     * Opens the update drawer for the given resource record.
     *
     * Example:
     *   return ActionResponse::openUpdate('posts' , $model->id);
     */
    public static function openUpdate(string $resourceName, string|int $recordId): self
    {
        return new self(ActionResponseType::OpenUpdate, ['resource' => $resourceName, 'recordId' => $recordId]);
    }

    /**
     * `$url` when it is an `http(s)` URL or a relative one, else an exception.
     *
     * The SPA hands the URL to the browser (a redirect, a link to click, a new
     * tab), and a `javascript:` URL there runs script in the panel with the
     * viewer's session, so only web URLs and paths pass, and the SPA refuses
     * anything else too. The browser ignores leading control characters and
     * spaces and drops tabs and newlines inside a URL (`java\tscript:` is
     * `javascript:`), so the scheme is read from the URL without them.
     *
     * @throws \InvalidArgumentException
     */
    private static function webUrl(string $url, string $method): string
    {
        $bare = preg_replace('/[\x00-\x20]+/', '', $url) ?? '';

        if (preg_match('/^([a-z][a-z0-9+.\-]*):/i', $bare, $match) === 1 && ! in_array(strtolower($match[1]), ['http', 'https'], true)) {
            throw new \InvalidArgumentException(
                "ActionResponse::{$method}() takes an http(s) URL or a path, not a \"".strtolower($match[1]).':" URL.'
            );
        }

        return $url;
    }

    /**
     * Type.
     */
    public function type(): ActionResponseType
    {
        return $this->type;
    }

    /** @return array<string, mixed> */
    public function jsonSerialize(): array
    {
        $data = $this->data;

        // emit() and modal() hand their data to the SPA as an object (an event
        // payload, a component's `data` prop); PHP would encode an empty
        // array as a JSON array.
        if (in_array($this->type, [ActionResponseType::Emit, ActionResponseType::Modal], true) && ($data['data'] ?? null) === []) {
            $data['data'] = new \stdClass;
        }

        return [
            'type' => $this->type->value,
            'data' => $data,
        ];
    }
}
