<?php

/**
 * Copyright © 2016-present Spryker Systems GmbH. All rights reserved.
 * Use of this software requires acceptance of the Evaluation License Agreement. See LICENSE file.
 */

declare(strict_types=1);

namespace SprykerSdkTest\Zed\AiDev\Communication\AiToolSetup;

use Codeception\Test\Unit;
use SprykerSdk\Zed\AiDev\Communication\AiToolSetup\RuleFrontmatterTransformer;

/**
 * @group AiDev
 * @group Communication
 * @group AiToolSetup
 * @group RuleFrontmatterTransformer
 */
class RuleFrontmatterTransformerTest extends Unit
{
    /**
     * Mirrors AiDevConfig::TOOL_FRONTMATTER_SPEC for GitHub Copilot.
     *
     * @var array<string, mixed>
     */
    protected const array COPILOT_SPEC = [
        'scope_key' => 'applyTo',
        'keep_name' => false,
        'keep_description' => false,
        'trigger_with_scope' => null,
        'trigger_no_scope' => null,
        'fallback_scope' => '**',
    ];

    /**
     * Mirrors AiDevConfig::TOOL_FRONTMATTER_SPEC for Claude Code.
     *
     * @var array<string, mixed>
     */
    protected const array CLAUDE_SPEC = [
        'scope_key' => 'paths',
        'keep_name' => false,
        'keep_description' => false,
        'trigger_with_scope' => null,
        'trigger_no_scope' => null,
        'fallback_scope' => null,
    ];

    protected const string SCOPED_RULE = <<<'MD'
        ---
        name: dependency-provider
        description: Use when writing or modifying a DependencyProvider.
        paths: "src/**/*DependencyProvider.php"
        ---

        **Architecture rule**
        DependencyProviders MUST use late-binding closures.
        MD;

    public function testTransformRewritesPathsToApplyToForCopilot(): void
    {
        // Arrange
        $transformer = new RuleFrontmatterTransformer();

        // Act
        $result = $transformer->transform(static::SCOPED_RULE, static::COPILOT_SPEC);

        // Assert
        $this->assertStringContainsString('applyTo: "src/**/*DependencyProvider.php"', $result);
        $this->assertStringNotContainsString('paths:', $result);
    }

    public function testTransformDropsNameAndDescriptionForCopilot(): void
    {
        // Arrange
        $transformer = new RuleFrontmatterTransformer();

        // Act
        $result = $transformer->transform(static::SCOPED_RULE, static::COPILOT_SPEC);

        // Assert
        $this->assertStringNotContainsString('name:', $result);
        $this->assertStringNotContainsString('description:', $result);
    }

    public function testTransformKeepsTheRuleBody(): void
    {
        // Arrange
        $transformer = new RuleFrontmatterTransformer();

        // Act
        $result = $transformer->transform(static::SCOPED_RULE, static::COPILOT_SPEC);

        // Assert
        $this->assertStringContainsString('**Architecture rule**', $result);
        $this->assertStringContainsString('DependencyProviders MUST use late-binding closures.', $result);
    }

    public function testTransformAppliesTheFallbackScopeWhenTheRuleHasNoPaths(): void
    {
        // Arrange
        $transformer = new RuleFrontmatterTransformer();
        $unscopedRule = "---\nname: owasp\ndescription: Security review rule.\n---\n\nBody.\n";

        // Act
        $result = $transformer->transform($unscopedRule, static::COPILOT_SPEC);

        // Assert
        $this->assertStringContainsString('applyTo: "**"', $result);
    }

    public function testTransformKeepsPathsForClaudeCode(): void
    {
        // Arrange
        $transformer = new RuleFrontmatterTransformer();

        // Act
        $result = $transformer->transform(static::SCOPED_RULE, static::CLAUDE_SPEC);

        // Assert
        $this->assertStringContainsString('paths: "src/**/*DependencyProvider.php"', $result);
        $this->assertStringNotContainsString('applyTo:', $result);
    }

    public function testTransformReturnsContentUnchangedForAnEmptySpec(): void
    {
        // Arrange
        $transformer = new RuleFrontmatterTransformer();

        // Act
        $result = $transformer->transform(static::SCOPED_RULE, []);

        // Assert
        $this->assertSame(static::SCOPED_RULE, $result);
    }

    public function testTransformReturnsContentUnchangedWhenThereIsNoFrontmatter(): void
    {
        // Arrange
        $transformer = new RuleFrontmatterTransformer();
        $withoutFrontmatter = "Just a body, no frontmatter.\n";

        // Act
        $result = $transformer->transform($withoutFrontmatter, static::COPILOT_SPEC);

        // Assert
        $this->assertSame($withoutFrontmatter, $result);
    }
}
