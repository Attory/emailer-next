<?php

declare(strict_types=1);

namespace App\Module\Configuration\Presentation\Http;

use App\Module\Identity\Application\Contract\SecurityLevelReader;
use Twig\Extension\AbstractExtension;
use Twig\TwigFunction;

final class SecurityLevelTwigExtension extends AbstractExtension
{
    public function __construct(private readonly SecurityLevelReader $levels)
    {
    }

    public function getFunctions(): array
    {
        return [new TwigFunction('security_requires_recent', $this->levels->requiresRecentAuthentication(...))];
    }
}
