<?php

declare(strict_types=1);

use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Request;
use Illuminate\Session\ArraySessionHandler;
use Illuminate\Session\Store;
use Martis\Sso\Providers\SignedTokenProvider;
use Martis\Sso\SignedToken\PasetoV4Public;

/*
 * Helpers shared by the signed-token SSO specs (provider, issuer, end to end).
 * Required once from each spec.
 */

if (! function_exists('stKeys')) {
    /**
     * A fresh Ed25519 key pair: raw and base64, as config holds them.
     *
     * @return array{secret: string, public: string, secret_b64: string, public_b64: string}
     */
    function stKeys(): array
    {
        $pair = sodium_crypto_sign_keypair();
        $secret = sodium_crypto_sign_secretkey($pair);
        $public = sodium_crypto_sign_publickey($pair);

        return [
            'secret' => $secret,
            'public' => $public,
            'secret_b64' => base64_encode($secret),
            'public_b64' => base64_encode($public),
        ];
    }

    /** A browser session of its own (the session manager hands out one store per driver). */
    function stSession(): Session
    {
        return new Store('martis-test', new ArraySessionHandler(120));
    }

    function stTime(int $timestamp): string
    {
        return gmdate('Y-m-d\TH:i:sP', $timestamp);
    }

    /**
     * Configure a consumer provider block and return it.
     *
     * @param  array<string, mixed>  $overrides  a key set to null is removed
     * @return array<string, mixed>
     */
    function stConsumer(array $publicKeys, string $name = 'panel_a', array $overrides = []): array
    {
        $block = array_merge([
            'enabled' => true,
            'driver' => 'signed-token',
            'authorize_url' => 'https://a.example/martis/sso/issue/panel-b',
            'public_keys' => $publicKeys,
            'issuer' => 'https://a.example',
            'audience' => 'https://b.example',
            'role_strategy' => 'column',
            'auto_create_user' => true,
            'on_no_role_match' => 'deny',
            'sync_user_attributes' => ['name', 'email'],
            'permission_adapter' => 'callable',
            'identity_match_attribute' => 'external_id',
        ], $overrides);

        foreach ($block as $key => $value) {
            if ($value === null) {
                unset($block[$key]);
            }
        }

        config()->set('martis.auth.sso.enabled', true);
        config()->set("martis.auth.sso.providers.{$name}", $block);

        return $block;
    }

    /**
     * The claims of a token a provider built by stConsumer() accepts.
     *
     * @param  array<string, mixed>  $overrides  a key set to null is removed
     * @return array<string, mixed>
     */
    function stClaims(string $state, array $overrides = []): array
    {
        $now = time();
        $claims = array_merge([
            'iss' => 'https://a.example',
            'aud' => 'https://b.example',
            'sub' => '42',
            'iat' => stTime($now),
            'exp' => stTime($now + 60),
            'jti' => bin2hex(random_bytes(16)),
            'state' => $state,
            'email' => 'person@example.com',
            'email_verified' => true,
            'name' => 'Some Person',
            'roles' => ['PMI ADMIN'],
        ], $overrides);

        foreach ($claims as $key => $value) {
            if ($value === null) {
                unset($claims[$key]);
            }
        }

        return $claims;
    }

    /**
     * Sign claims the way the issuer does (footer carries the kid), or with a
     * hand-picked footer.
     *
     * @param  array<string, mixed>  $claims
     */
    function stSign(array $claims, string $secret, ?string $footer = null): string
    {
        $public = sodium_crypto_sign_publickey_from_secretkey($secret);

        return PasetoV4Public::sign(
            json_encode($claims, JSON_THROW_ON_ERROR),
            $secret,
            $footer ?? json_encode(['kid' => PasetoV4Public::keyId($public)], JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Start the flow of a provider: run redirect() and return the browser
     * session it wrote to and the state it sent.
     *
     * `entry` is what redirect() kept in the session (the state with its
     * creation time): stRestoreState() puts it back.
     *
     * @return array{session: Session, state: string, location: string, entry: mixed}
     */
    function stBegin(SignedTokenProvider $provider, ?Session $session = null): array
    {
        $session ??= stSession();
        $request = Request::create('/martis/sso/'.$provider->name().'/redirect');
        $request->setLaravelSession($session);

        $location = $provider->redirect($request)->getTargetUrl();
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        return [
            'session' => $session,
            'state' => (string) $query['state'],
            'location' => $location,
            'entry' => $session->get('martis_sso_state.'.$provider->name()),
        ];
    }

    /**
     * Put the state of a flow back into its browser session, as redirect()
     * kept it (two tabs, a session restore): the one-time store is then the
     * only lock left for the same token.
     *
     * @param  array{session: Session, state: string, location: string, entry: mixed}  $flow
     */
    function stRestoreState(array $flow, string $name = 'panel_a'): void
    {
        $flow['session']->put('martis_sso_state.'.$name, $flow['entry']);
    }

    /** A callback request in the browser session of stBegin(). */
    function stCallback(Session $session, string $token, ?string $state): Request
    {
        $query = ['token' => $token];
        if ($state !== null) {
            $query['state'] = $state;
        }

        $request = Request::create('/martis/sso/panel_a/callback', 'GET', $query);
        $request->setLaravelSession($session);

        return $request;
    }
}
