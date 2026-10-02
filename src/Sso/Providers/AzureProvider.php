<?php

declare(strict_types=1);

namespace Martis\Sso\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Martis\Sso\SsoIdentity;
use Martis\Support\ConfigCallable;
use RuntimeException;

/**
 * Microsoft Azure AD provider.
 *
 * Builds on Socialite's `microsoft` driver (the
 * `socialiteproviders/microsoft` package). Three role-source
 * strategies, switchable via `martis.auth.sso.providers.azure.role_source`:
 *
 *   • `app_role_assignments` (default) — calls Microsoft Graph
 *     `/users/{id}/appRoleAssignments` filtered by the configured
 *     `resource_id`. Returns each assignment's `principalDisplayName`,
 *     which matches the Azure AD group / app role display name.
 *
 *   • `groups` — calls `/users/{id}/memberOf` and returns the group
 *     `displayName`s. Coarser-grained but doesn't require app role
 *     definitions in the Azure portal.
 *
 *   • `callable` — defers entirely to the provider config's
 *     `role_source_callable` (see {@see fetchViaCallable()}). Use when
 *     neither built-in endpoint shape fits.
 *
 * Tenant check (v2.4.0). The identity is matched to a local account by
 * email, and a multi-tenant app registration lets any Entra tenant's user
 * sign in with whatever email their tenant says: when a concrete tenant is
 * configured (`tenant`, else `services.{driver}.tenant`: a tenant id or a
 * verified domain, not `common`, `organizations` or `consumers`) an identity
 * whose `tid` claim names another tenant is rejected, and so is one whose
 * tenant cannot be read.
 */
class AzureProvider extends AbstractSsoProvider
{
    /** The shape of a tenant id: a GUID. */
    private const GUID_BODY = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    public function name(): string
    {
        return 'azure';
    }

    public function resolveIdentity(Request $request): SsoIdentity
    {
        $this->ensureSocialiteAvailable();

        $driver = (string) $this->config('driver', 'azure');

        /** @var Provider $client */
        $client = Socialite::driver($driver);

        if (method_exists($client, 'stateless') && (bool) $this->config('stateless', false)) {
            /** @phpstan-ignore-next-line — runtime method on Socialite drivers */
            $client = $client->stateless();
        }

        /** @var User $user */
        $user = $client->user();

        // Socialite's Two\User exposes the OAuth token as a public `token`
        // property.
        $accessToken = null;
        if (property_exists($user, 'token')) {
            /** @phpstan-ignore-next-line */
            $accessToken = (string) $user->token;
        }

        $this->assertConfiguredTenant($user, $accessToken ?? '');

        $email = (string) ($user->getEmail() ?? $user->user['mail'] ?? $user->user['userPrincipalName'] ?? '');
        $name = $user->getName() ?? ($user->user['displayName'] ?? null);
        $externalId = (string) $user->getId();

        $externalRoles = $this->fetchExternalRoles($externalId, $accessToken ?? '');

        return new SsoIdentity(
            provider: $this->name(),
            externalId: $externalId,
            email: $email !== '' ? $email : null,
            name: is_string($name) ? $name : null,
            externalRoles: $externalRoles,
            raw: is_array($user->user ?? null) ? $user->user : [],
            accessToken: $accessToken,
        );
    }

    /**
     * Reject an identity that does not come from the configured tenant.
     * Nothing to check while the tenant is `common`, `organizations`,
     * `consumers` or unset: any tenant may sign in, which is what a
     * multi-tenant app registration asks for.
     *
     * @throws RuntimeException When the identity's tenant is not the configured one, or cannot be read.
     */
    protected function assertConfiguredTenant(User $user, string $accessToken): void
    {
        $tenant = $this->configuredTenant();

        if ($tenant === null) {
            return;
        }

        $expected = $this->tenantIdOf($tenant);
        $actual = $this->identityTenantId($user, $accessToken);

        if ($expected === null) {
            throw new RuntimeException("Azure SSO: the configured tenant [{$tenant}] could not be resolved to a tenant id, so the tenant of the signed-in identity cannot be verified.");
        }

        if ($actual === null) {
            throw new RuntimeException("Azure SSO: the tenant of the signed-in identity could not be read (no `tid` claim), so it cannot be checked against the configured tenant [{$tenant}].");
        }

        if (! hash_equals($expected, $actual)) {
            throw new RuntimeException("Azure SSO: the identity comes from the tenant [{$actual}], not the configured tenant [{$tenant}].");
        }
    }

    /**
     * The tenant the app registration is tied to: the `tenant` of the
     * provider config, else the `tenant` of the Socialite driver's own
     * config (`services.{driver}.tenant`), when it names one tenant. Null for
     * `common`, `organizations`, `consumers` and nothing.
     */
    protected function configuredTenant(): ?string
    {
        $tenant = $this->config('tenant');

        if (! is_string($tenant) || trim($tenant) === '') {
            $tenant = config('services.'.(string) $this->config('driver', 'azure').'.tenant');
        }

        $tenant = is_string($tenant) ? trim($tenant) : '';

        return in_array(strtolower($tenant), ['', 'common', 'organizations', 'consumers'], true) ? null : $tenant;
    }

    /**
     * The tenant id (lowercase GUID) of a configured tenant: itself when it
     * is one, else the one in the issuer of the tenant's OpenID metadata
     * (read once a day), for a tenant configured by domain name. Null when
     * the metadata cannot be read.
     */
    protected function tenantIdOf(string $tenant): ?string
    {
        if (preg_match('~^'.self::GUID_BODY.'$~i', $tenant) === 1) {
            return strtolower($tenant);
        }

        $key = 'martis.sso.azure.tenant-id.'.sha1(strtolower($tenant));
        $known = Cache::get($key);

        if (is_string($known) && $known !== '') {
            return $known;
        }

        $response = Http::acceptJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->get('https://login.microsoftonline.com/'.rawurlencode($tenant).'/v2.0/.well-known/openid-configuration');

        $issuer = $response->successful() ? $response->json('issuer') : null;

        if (! is_string($issuer) || preg_match('~/('.self::GUID_BODY.')/~i', $issuer, $match) !== 1) {
            return null;
        }

        // Only a tenant id is kept: a failed read is asked again, not cached.
        Cache::put($key, strtolower($match[1]), now()->addDay());

        return strtolower($match[1]);
    }

    /**
     * The `tid` of the signed-in identity (lowercase), as the driver reports
     * it (`tid` among the user's raw attributes), else the claim of the
     * access token the provider's own token endpoint returned. That token is
     * read, not trusted for anything but its tenant: it came straight from
     * the token endpoint over TLS in the code exchange.
     */
    protected function identityTenantId(User $user, string $accessToken): ?string
    {
        $raw = is_array($user->user ?? null) ? $user->user : [];

        foreach ([$raw['tid'] ?? null, $this->jwtClaim($accessToken, 'tid')] as $candidate) {
            if (is_string($candidate) && preg_match('~^'.self::GUID_BODY.'$~i', $candidate) === 1) {
                return strtolower($candidate);
            }
        }

        return null;
    }

    private function jwtClaim(string $jwt, string $claim): mixed
    {
        $parts = explode('.', $jwt);

        if (count($parts) !== 3) {
            return null;
        }

        $payload = base64_decode(strtr($parts[1], '-_', '+/'), true);
        $claims = $payload === false ? null : json_decode($payload, true);

        return is_array($claims) ? ($claims[$claim] ?? null) : null;
    }

    /**
     * @return array<int, string>
     */
    protected function fetchExternalRoles(string $externalId, string $accessToken): array
    {
        $source = (string) $this->config('role_source', 'app_role_assignments');

        if ($accessToken === '') {
            return [];
        }

        return match ($source) {
            'app_role_assignments' => $this->fetchAppRoleAssignments($externalId, $accessToken),
            'groups' => $this->fetchGroupMemberships($externalId, $accessToken),
            'callable' => $this->fetchViaCallable($externalId, $accessToken),
            default => [],
        };
    }

    /**
     * Microsoft Graph `appRoleAssignments` filtered by `resourceId`
     * matching the configured app id. Returns the
     * `principalDisplayName` of each assignment — that's the Azure
     * group / app role name visible in the portal.
     *
     * @return array<int, string>
     */
    protected function fetchAppRoleAssignments(string $externalId, string $accessToken): array
    {
        $resourceId = (string) $this->config('resource_id', '');
        if ($resourceId === '') {
            throw new RuntimeException(
                'Azure SSO `role_source = app_role_assignments` requires `resource_id` '.
                'in `config/martis.php → auth.sso.providers.azure`. Set the AZURE_RESOURCE_ID env.'
            );
        }

        $url = sprintf(
            'https://graph.microsoft.com/v1.0/users/%s/appRoleAssignments?$filter=resourceId eq %s',
            urlencode($externalId),
            urlencode($resourceId),
        );

        return $this->graphCall($url, $accessToken, 'principalDisplayName');
    }

    /**
     * Microsoft Graph `memberOf` — returns the user's group
     * memberships. Coarser than appRoleAssignments but doesn't require
     * defining app roles in the Azure portal.
     *
     * @return array<int, string>
     */
    protected function fetchGroupMemberships(string $externalId, string $accessToken): array
    {
        $url = sprintf(
            'https://graph.microsoft.com/v1.0/users/%s/memberOf?$select=displayName',
            urlencode($externalId),
        );

        return $this->graphCall($url, $accessToken, 'displayName');
    }

    /**
     * The `role_source_callable` of the provider config, called with
     * `(string $externalId, string $accessToken)` and returning the
     * external role names. {@see ConfigCallable} resolves it.
     *
     * @return array<int, string>
     */
    protected function fetchViaCallable(string $externalId, string $accessToken): array
    {
        $callable = ConfigCallable::resolve(
            $this->config('role_source_callable'),
            "martis.auth.sso.providers.{$this->name()}.role_source_callable",
        );
        if ($callable === null) {
            return [];
        }

        $result = $callable($externalId, $accessToken);

        return is_array($result) ? array_values(array_map(strval(...), $result)) : [];
    }

    /**
     * @return array<int, string>
     */
    protected function graphCall(string $url, string $accessToken, string $fieldName): array
    {
        $response = Http::acceptJson()
            ->timeout(10)
            ->connectTimeout(5)
            ->withToken($accessToken)
            ->get($url);

        if (! $response->successful()) {
            return [];
        }

        $payload = $response->json();
        if (! is_array($payload) || ! isset($payload['value']) || ! is_array($payload['value'])) {
            return [];
        }

        $names = [];
        foreach ($payload['value'] as $entry) {
            if (is_array($entry) && isset($entry[$fieldName]) && is_string($entry[$fieldName])) {
                $names[] = $entry[$fieldName];
            }
        }

        return array_values(array_unique($names));
    }
}
