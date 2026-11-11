<?php

declare(strict_types=1);

namespace Martis\Sso;

use Illuminate\Http\RedirectResponse;

/**
 * Why the callback refuses an identity after the user and role steps, without
 * an exception: the user is not provisioned, or the roles map to nothing and
 * the provider denies a role-less sign-in. SsoController records it and answers.
 *
 * @internal
 */
final class SsoDenial
{
    public function __construct(
        /** One of the {@see SsoException} REASON_* constants. */
        public readonly string $reason,

        /** Translation key of the login page text. */
        public readonly string $messageKey,

        /** The response the no-role-match hook chose, when it took over from the login page. */
        public readonly ?RedirectResponse $response = null,
    ) {}
}
