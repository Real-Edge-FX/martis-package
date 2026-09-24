<?php

declare(strict_types=1);

namespace Martis\Tests\Fixtures\ConfigCallables;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Http\Request;

/**
 * A `martis.brand.page_title` resolver as a host app writes one. The
 * class name is the invokable form, built through the container (the
 * constructor dependency proves it), and `[PageTitle::class, 'resolve']`
 * the static-method form. `handle()` is not static, so
 * `[PageTitle::class, 'handle']` is not a callable.
 */
final class PageTitle
{
    public function __construct(private readonly Repository $config) {}

    public function __invoke(Request $request): string
    {
        return $this->config->get('martis.brand.name').' · invokable · '.$request->path();
    }

    public static function resolve(Request $request): string
    {
        return 'static · '.$request->path();
    }

    public function handle(Request $request): string
    {
        return 'handle · '.$request->path();
    }
}
