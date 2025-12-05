<?php

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business\Prompts;

interface PromptsGeneratorInterface
{
    public function generate(): void;
}
