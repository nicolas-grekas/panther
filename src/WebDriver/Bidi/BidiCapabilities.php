<?php

/*
 * This file is part of the Panther project.
 *
 * (c) Kévin Dunglas <kevin@dunglas.dev>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

declare(strict_types=1);

namespace Symfony\Component\Panther\WebDriver\Bidi;

use Facebook\WebDriver\Remote\DesiredCapabilities;

/**
 * Keeps the standard "webSocketUrl" capability, which php-webdriver drops when sending W3C capabilities.
 *
 * @see https://github.com/php-webdriver/php-webdriver/pull/1153
 *
 * @internal
 */
final class BidiCapabilities extends DesiredCapabilities
{
    public function toW3cCompatibleArray(): array
    {
        $capabilities = parent::toW3cCompatibleArray();

        if (null !== $webSocketUrl = $this->getCapability('webSocketUrl')) {
            $capabilities['webSocketUrl'] = $webSocketUrl;
        }

        return $capabilities;
    }
}
