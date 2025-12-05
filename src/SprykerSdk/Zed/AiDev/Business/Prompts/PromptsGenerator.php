<?php

/**
 * This file is part of the Spryker Commerce OS.
 * For full license information, please view the LICENSE file that was distributed with this source code.
 */

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

use Generated\Shared\Transfer\AiDevGitHubPromptTransfer;
use SprykerSdk\Zed\AiDev\AiDevConfig;

class PromptsGenerator implements PromptsGeneratorInterface
{
    public function __construct(
        protected GithubPromptsFetcherInterface $githubPromptsFetcher,
        protected AiDevConfig $config
    ) {
    }

    /**
     * @return void
     */
    public function generate(): void
    {
        $aiDevGitHubPromptTransfers = $this->githubPromptsFetcher->getAllPrompts();

        $methods = [];
        foreach ($aiDevGitHubPromptTransfers as $aiDevGitHubPromptTransfer) {
            $methods[] = $this->generateMethodContent($aiDevGitHubPromptTransfer);
        }

        $classContent = $this->generateClassContent(implode("\n", $methods));
        $this->writePromptClass($classContent);
    }

    protected function transformFilenameToClassName(string $filename): array
    {
        $filename = preg_replace('/\.md$/', '', $filename);

        $parts = preg_split('/[-_.]/', $filename);

        return [
            lcfirst(implode('', array_map('ucfirst', $parts))),
            implode('_', array_map('strtolower', $parts)),
        ];
    }

    protected function generateMethodContent(AiDevGitHubPromptTransfer $aiDevGitHubPromptTransfer): string
    {
        [$methodName, $promptName] = $this->transformFilenameToClassName($aiDevGitHubPromptTransfer->getFilename());
        $content = $aiDevGitHubPromptTransfer->getContent();
        $parameters = $this->parseParameters($content);

        $parametersString = implode(', ', array_map(fn (string $parameter) => 'string $' . $parameter, $parameters));
        $parametersReplacement = implode(', ', array_map(
            fn (string $parameter) => '\'' . $parameter . '\' => $' . $parameter,
            $parameters,
        ));

        $methodStub = file_get_contents(__DIR__ . '/../../../../../../data/stubs/PromptMethod.php.stub');

        $description = $this->escapeForPhpString($aiDevGitHubPromptTransfer->getDescription());
        $escapedContent = $this->escapeForHeredoc($content);

        return str_replace(
            ['{{promptName}}', '{{methodName}}', '{{PromptDescription}}', '{{promptParams}}', '{{promptTemplate}}', '{{promptParamsReplacements}}'],
            [$promptName, $methodName, $description, $parametersString, $escapedContent, $parametersReplacement],
            $methodStub,
        );
    }

    protected function generateClassContent(string $methods): string
    {
        $classStub = file_get_contents(__DIR__ . '/../../../../../../data/stubs/PromptClass.php.stub');

        return str_replace('{{content}}', $methods, $classStub);
    }

    /**
     * @return void
     */
    protected function writePromptClass(string $content): void
    {
        $targetDirectory = $this->config->getPromptClassTargetDirectory();

        if (!is_dir($targetDirectory)) {
            mkdir($targetDirectory, 0755, true);
        }

        file_put_contents($targetDirectory . 'GeneratedPrompts.php', $content);
    }

    /**
     * @return array<string>
     */
    protected function parseParameters(string $content): array
    {
        preg_match_all('/\{\{?([a-zA-Z0-9_]+)\}?\}/', $content, $matches);

        return array_unique($matches[1]);
    }

    protected function escapeForPhpString(string $value): string
    {
        return addslashes($value);
    }

    protected function escapeForHeredoc(string $value): string
    {
        return str_replace(['\\', '$'], ['\\\\', '\\$'], $value);
    }
}
