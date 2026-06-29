<?php

declare(strict_types=1);

namespace Martis\Sso\Providers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Contracts\Provider;
use Laravel\Socialite\Contracts\User;
use Laravel\Socialite\Facades\Socialite;
use Martis\Sso\SsoIdentity;
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
 *   • `callable` — defers entirely to a host-app closure registered on
 *     the provider config (`role_callable`). Use when neither built-in
 *     endpoint shape fits.
 */
class AzureProvider extends AbstractSsoProvider
{
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

        $email = (string) ($user->getEmail() ?? $user->user['mail'] ?? $user->user['userPrincipalName'] ?? '');
        $name = $user->getName() ?? ($user->user['displayName'] ?? null);
        $externalId = (string) $user->getId();

        // Socialite's Two\User exposes the OAuth token as a public `token`
        // property. (The old `method_exists($user, 'token') ? null : null`
        // here was dead code — both branches were null.)
        $accessToken = null;
        if (property_exists($user, 'token')) {
            /** @phpstan-ignore-next-line */
            $accessToken = (string) $user->token;
        }

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
     * @return array<int, string>
     */
    protected function fetchViaCallable(string $externalId, string $accessToken): array
    {
        $callable = $this->config('role_source_callable');
        if (! is_callable($callable)) {
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
