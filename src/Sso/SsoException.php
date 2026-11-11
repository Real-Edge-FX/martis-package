<?php

declare(strict_types=1);

namespace Martis\Sso;

use Martis\Events\SsoFailed;
use RuntimeException;
use Throwable;

/**
 * A sign-in refusal with a machine-readable reason and, optionally, the
 * translation key of the text the login page shows.
 *
 * SsoController turns every failure of the callback into one log line, one
 * {@see SsoFailed} event and a redirect to the login page. A
 * provider driver or a host closure throws this to choose the `reason` (a short
 * snake_case slug a listener can branch on) and the `messageKey`; any other
 * Throwable is reported as {@see self::REASON_UNKNOWN} with the generic text.
 *
 * The exception message is for the log. It is passed through
 * {@see SsoLogRedactor} first, but keep secrets out of it anyway: the login
 * page never shows it.
 */
class SsoException extends RuntimeException
{
    /** A Throwable that is not an SsoException (an unreachable IdP, a malformed token, a bug). */
    public const REASON_UNKNOWN = 'unknown';

    /** A local row holds the IdP's address and may not be adopted (unverified email, or an address the strategy did not link). */
    public const REASON_ACCOUNT_UNVERIFIED = 'account_unverified';

    /** No local user matches the identity and the provider does not create users. */
    public const REASON_NOT_PROVISIONED = 'not_provisioned';

    /** The external roles map to no local role and the provider denies a role-less sign-in. */
    public const REASON_NO_ROLE_MATCH = 'no_role_match';

    /** `identity_match_attribute = external_id` and the IdP sent no id. */
    public const REASON_MISSING_EXTERNAL_ID = 'missing_external_id';

    /** The local row already stores a different external id than the IdP sent. */
    public const REASON_EXTERNAL_ID_MISMATCH = 'external_id_mismatch';

    /**
     * @param  string  $reason  Short snake_case slug, see the REASON_* constants.
     * @param  string|null  $messageKey  Translation key of the login page text (e.g. `martis::messages.sso_no_role_match`);
     *                                   null or an untranslated key shows the generic `sso_callback_failed` text.
     */
    public function __construct(
        string $message,
        public readonly string $reason,
        public readonly ?string $messageKey = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}
