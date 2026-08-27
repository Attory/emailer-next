<?php

declare(strict_types=1);

namespace App\Tests\Module\Secrets;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SecretCommandSkeletonTest extends KernelTestCase
{
    public function testReplacementSkeletonFailsClosedWithoutSecretInputs(): void
    {
        self::bootKernel();
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);

        $command = $application->find('emailer:secrets:replace');
        self::assertSame([], $command->getDefinition()->getArguments());
        self::assertSame([], $command->getDefinition()->getOptions());
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute([]));
        self::assertStringContainsString('unavailable', $tester->getDisplay());
    }

    public function testRotationRequiresInteractiveOwnerReauthentication(): void
    {
        self::bootKernel();
        self::assertNotNull(self::$kernel);
        $command = (new Application(self::$kernel))->find('emailer:secrets:rotate-master-key');
        self::assertSame([], $command->getDefinition()->getArguments());
        self::assertSame([], $command->getDefinition()->getOptions());
        $tester = new CommandTester($command);
        self::assertSame(1, $tester->execute([], ['interactive' => false]));
        self::assertStringContainsString('Interactive Owner re-authentication is required', $tester->getDisplay());
    }
}
