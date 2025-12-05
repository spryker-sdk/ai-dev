<?php

declare(strict_types=1);

namespace SprykerSdk\Zed\AiDev\Business;

use PhpMcp\Server\Attributes\McpPrompt;
use SprykerSdk\Shared\AiDev\AbstractPrompt;

class ExamplePrompt extends AbstractPrompt
{
    #[McpPrompt(name: 'you_are_welcome', description: 'You are welcome!')]
    public function youAreWelcome(string $name, string $role, string $part): array
    {
        $template = <<<END
your name: {{name}}
your role: {{role}}
your part: {{part}}
END;

        $content = $this->replacePlaceholders(
            $template,
            ['name' => $name, 'role' => $role, 'part' => $part]
        );

        return ['role' => 'user', 'content' => $content];
    }
}
