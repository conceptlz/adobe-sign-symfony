<?php

declare(strict_types=1);

namespace Conceptlz\AdobeSign\Symfony\Controller;

use Conceptlz\AdobeSign\Auth\OAuthClient;
use Conceptlz\AdobeSign\Exception\InvalidArgumentException;
use Conceptlz\AdobeSign\Exception\OAuthException;
use Conceptlz\AdobeSign\Exception\TransportException;
use Conceptlz\AdobeSign\Symfony\AdobeSignManager;
use Conceptlz\AdobeSign\Symfony\Event\OAuthConnectedEvent;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\UnauthorizedHttpException;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Connect the signed-in user's Acrobat Sign account (routes adobe_sign_oauth_redirect and
 * adobe_sign_oauth_callback). Protect both with access_control so only signed-in users reach them.
 */
final class OAuthController
{
    private const SESSION_KEY = 'adobe_sign_oauth_state';

    /**
     * @param list<string> $scopes
     */
    public function __construct(
        private readonly AdobeSignManager $manager,
        private readonly EventDispatcherInterface $events,
        private readonly ?TokenStorageInterface $security,
        private readonly array $scopes,
        private readonly string $redirectAfter,
    ) {
    }

    /**
     * GET /adobe-sign/oauth/redirect: store a fresh state in the session and send the user to
     * Adobe with the configured scopes.
     */
    public function redirect(Request $request): RedirectResponse
    {
        $state = OAuthClient::generateState();
        $request->getSession()->set(self::SESSION_KEY, $state);

        return new RedirectResponse($this->manager->oauth()->authorizationUrl($this->scopes, $state));
    }

    /**
     * GET /adobe-sign/oauth/callback: exchange the code, store the token under the user's key,
     * dispatch OAuthConnectedEvent and redirect to oauth.redirect_after. 400 for a bad state or
     * missing code, 403 when Adobe refused, 401 without a signed-in user, 502 if Adobe is unreachable.
     */
    public function callback(Request $request): RedirectResponse
    {
        // remove(): a state is single-use, so a replayed callback URL fails.
        $expected = $request->getSession()->remove(self::SESSION_KEY);
        $expected = is_string($expected) ? $expected : '';

        $user = $this->security?->getToken()?->getUser();
        if (!$user instanceof UserInterface) {
            throw new UnauthorizedHttpException('Session', 'Sign in before connecting Acrobat Sign.');
        }

        /** @var array<string, string> $query */
        $query = $request->query->all();
        try {
            $token = $this->manager->oauth()->handleCallback($query, $expected);
        } catch (OAuthException $e) {
            if (in_array($e->errorCode, ['STATE_MISMATCH', 'MISSING_CODE'], true)) {
                throw new BadRequestHttpException($e->getMessage(), $e);
            }
            throw new AccessDeniedHttpException($e->getMessage(), $e);
        } catch (InvalidArgumentException $e) {
            // e.g. a forged api_access_point outside Adobe's domains: the request itself is bad.
            throw new BadRequestHttpException($e->getMessage(), $e);
        } catch (TransportException $e) {
            throw new HttpException(502, 'Acrobat Sign could not be reached. Please try again.', $e);
        }

        $key = $this->manager->userKey($user);
        $this->manager->tokens()->put($key, $token);
        $this->events->dispatch(new OAuthConnectedEvent($user, $key, $token->apiAccessPoint));

        return new RedirectResponse($this->redirectAfter !== '' ? $this->redirectAfter : '/');
    }
}
