<?php

declare(strict_types=1);

namespace App\Tests\Platform;

use PHPUnit\Framework\TestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;
use Twig\Loader\ChainLoader;
use Twig\Loader\FilesystemLoader;
use Twig\TwigFunction;

final class AnalyticsSpacingMaintenanceTest extends TestCase
{
    public function testRenderedPolicyFormAndTableShareTheExistingSectionStack(): void
    {
        $root = \dirname(__DIR__, 2);
        $twig = new Environment(new ChainLoader([
            new ArrayLoader(['admin/layout.html.twig' => '{% block admin_body %}{% endblock %}']),
            new FilesystemLoader($root.'/templates'),
        ]), ['strict_variables' => true]);
        $twig->addFunction(new TwigFunction('is_granted', static fn (string $role): bool => true));
        $twig->addFunction(new TwigFunction('path', static fn (string $name): string => '/not-dispatched'));
        $twig->addFunction(new TwigFunction('csrf_token', static fn (string $name): string => 'synthetic-unused'));
        $html = $twig->render('admin/analytics.html.twig', ['campaigns' => [], 'policy' => [
            'open_tracking' => false, 'click_tracking' => false, 'tracking_retention_days' => 30,
            'rsvp_enabled' => false, 'rsvp_retention_days' => 30, 'rsvp_repeat_policy' => 'first',
        ]]);
        $document = new \DOMDocument();
        @$document->loadHTML($html);
        $xpath = new \DOMXPath($document);
        self::assertSame(1, $xpath->query('//div[@class="section-stack"]/form[@method="post"]')->length);
        self::assertSame(1, $xpath->query('//div[@class="section-stack"]/form/following-sibling::*[1][self::table]')->length);
        self::assertStringContainsString('name="_token" value="synthetic-unused"', $html);
        self::assertStringContainsString('<button>Save policy</button>', $html);
        self::assertStringContainsString('.section-stack>*+*{margin-top:1.5rem}', (string) file_get_contents($root.'/assets/styles/app.css'));
    }
}
