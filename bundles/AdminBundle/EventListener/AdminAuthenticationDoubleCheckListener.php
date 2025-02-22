<?php

/**
 * Pimcore
 *
 * This source file is available under two different licenses:
 * - GNU General Public License version 3 (GPLv3)
 * - Pimcore Commercial License (PCL)
 * Full copyright and license information is available in
 * LICENSE.md which is distributed with this source code.
 *
 *  @copyright  Copyright (c) Pimcore GmbH (http://www.pimcore.org)
 *  @license    http://www.pimcore.org/license     GPLv3 and PCL
 */

namespace Pimcore\Bundle\AdminBundle\EventListener;

use Pimcore\Bundle\AdminBundle\Controller\DoubleAuthenticationControllerInterface;
use Pimcore\Bundle\AdminBundle\EventListener\Traits\ControllerTypeTrait;
use Pimcore\Bundle\AdminBundle\Security\User\TokenStorageUserResolver;
use Pimcore\Bundle\CoreBundle\EventListener\Traits\PimcoreContextAwareTrait;
use Pimcore\Http\Request\Resolver\PimcoreContextResolver;
use Pimcore\Http\RequestMatcherFactory;
use Pimcore\Tool\Authentication;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestMatcherInterface;
use Symfony\Component\HttpKernel\Event\ControllerEvent;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Handles double authentication check for pimcore controllers after the firewall did to make sure the admin interface is
 * not accessible on configuration errors. Unauthenticated routes are not double-checked (e.g. login).
 *
 * @internal
 */
class AdminAuthenticationDoubleCheckListener implements EventSubscriberInterface
{
    use ControllerTypeTrait;

    use PimcoreContextAwareTrait;

    /**
     * @var RequestMatcherFactory
     */
    protected $requestMatcherFactory;

    /**
     * @var array
     */
    protected $unauthenticatedRoutes;

    /**
     * @var RequestMatcherInterface[]
     */
    protected $unauthenticatedMatchers;

    /**
     * @var TokenStorageUserResolver
     */
    protected $tokenResolver;

    /**
     * @param RequestMatcherFactory $factory
     * @param TokenStorageUserResolver $tokenResolver
     * @param array $unauthenticatedRoutes
     */
    public function __construct(
        RequestMatcherFactory $factory,
        TokenStorageUserResolver $tokenResolver,
        array $unauthenticatedRoutes
    ) {
        $this->requestMatcherFactory = $factory;
        $this->tokenResolver = $tokenResolver;
        $this->unauthenticatedRoutes = $unauthenticatedRoutes;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            KernelEvents::CONTROLLER => 'onKernelController',
        ];
    }

    public function onKernelController(ControllerEvent $event)
    {
        if (!$event->isMasterRequest()) {
            return;
        }

        $request = $event->getRequest();

        $isDoubleAuthController = $this->isControllerType($event, DoubleAuthenticationControllerInterface::class);
        $isPimcoreAdminContext = $this->matchesPimcoreContext($request, PimcoreContextResolver::CONTEXT_ADMIN);

        if (!$isDoubleAuthController && !$isPimcoreAdminContext) {
            return;
        }

        // double check we have a valid user to make sure there is no invalid security config
        // opening admin interface to the public
        if ($this->requestNeedsAuthentication($request)) {
            if ($isDoubleAuthController) {
                /** @var DoubleAuthenticationControllerInterface $controller */
                $controller = $this->getControllerType($event, DoubleAuthenticationControllerInterface::class);

                if ($controller->needsSessionDoubleAuthenticationCheck()) {
                    $this->checkSessionUser();
                }

                if ($controller->needsStorageDoubleAuthenticationCheck()) {
                    $this->checkTokenStorageUser();
                }
            } else {
                $this->checkSessionUser();
                $this->checkTokenStorageUser();
            }
        }
    }

    /**
     * Check if the current request needs double authentication
     *
     * @param Request $request
     *
     * @return bool
     */
    protected function requestNeedsAuthentication(Request $request)
    {
        foreach ($this->getUnauthenticatedMatchers() as $matcher) {
            if ($matcher->matches($request)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get list of paths which don't need double authentication check
     *
     * @return RequestMatcherInterface[]
     */
    protected function getUnauthenticatedMatchers()
    {
        if (null === $this->unauthenticatedMatchers) {
            $this->unauthenticatedMatchers = $this->requestMatcherFactory->buildRequestMatchers($this->unauthenticatedRoutes);
        }

        return $this->unauthenticatedMatchers;
    }

    /**
     * @throws AccessDeniedHttpException
     *      if there's no current user in the session
     */
    protected function checkSessionUser()
    {
        $user = Authentication::authenticateSession();
        if (null === $user) {
            throw new AccessDeniedHttpException('User is invalid.');
        }
    }

    /**
     * @throws AccessDeniedHttpException
     *      if there's no current user in the token storage
     */
    protected function checkTokenStorageUser()
    {
        $user = $this->tokenResolver->getUser();

        if (null === $user || !Authentication::isValidUser($user)) {
            throw new AccessDeniedHttpException('User is invalid.');
        }
    }
}
