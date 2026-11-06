<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Business\CapabilityMap;

trait CapabilityMapTestDataTrait
{
    /**
     * @return array<array<string, mixed>>
     */
    protected function getCards(): array
    {
        return [
            $this->createCard(
                'cart.md',
                'Shopping cart - add/remove items, quantity changes, validation',
                ['cart', 'basket', 'line items'],
                ['spryker-feature/cart'],
                ['vendor/spryker/cart', 'vendor/spryker/cart-extension', 'vendor/spryker/carts-rest-api'],
                'Cart-level business rules (budget enforcement, availability checks) belong here via `CartPreCheckPluginInterface`.',
            ),
            $this->createCard(
                'purchasing-control.md',
                'Budgets and cost centers for company purchasing',
                ['budget', 'cost center', 'spending limit'],
                ['spryker-feature/purchasing-control'],
                ['vendor/spryker/purchasing-control', 'vendor/spryker/purchasing-control-extension'],
                'Extend budget rules through `BudgetValidatorPluginInterface`.',
            ),
            $this->createCard(
                'sso-authentication.md',
                'Single sign-on for customers',
                ['sso', 'single sign-on'],
                [],
                ['vendor/spryker/oauth-client'],
                'Add an identity provider through `OauthClientPluginInterface`.',
            ),
            $this->createCard(
                'order-processor.md',
                'Order processor - checkout save order steps',
                ['checkout processor'],
                [],
                ['vendor/spryker/checkout'],
                'Plug in through `CheckoutDoSaveOrderInterface`.',
            ),
        ];
    }

    /**
     * @param array<string> $synonyms
     * @param array<string> $features
     * @param array<string> $modules
     *
     * @return array<string, mixed>
     */
    protected function createCard(
        string $file,
        string $capability,
        array $synonyms,
        array $features = [],
        array $modules = [],
        string $extend = '',
        string $status = 'shipped',
    ): array {
        return [
            'file' => $file,
            'capability' => $capability,
            'status' => $status,
            'synonyms' => $synonyms,
            'features' => $features,
            'modules' => $modules,
            'sections' => [
                'What it does' => $capability,
                "Extend, don't duplicate" => $extend,
                'Install' => $features === [] ? '- Feature: none' : sprintf('- Feature: `%s`', $features[0]),
            ],
        ];
    }
}
