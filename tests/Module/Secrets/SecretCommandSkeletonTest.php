<?php

declare(strict_types=1);

namespace App\Tests\Module\Secrets;

use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;

final class SecretCommandSkeletonTest extends KernelTestCase
{
    public function testReplacementAndRotationSkeletonsFailClosedWithoutSecretInputs(): void
    {
        self::bootKernel();
        self::assertNotNull(self::$kernel);
        $application = new Application(self::$kernel);

        foreach (['emailer:secrets:replace', 'emailer:secrets:rotate-master-key'] as $name) {
            $command = $application->find($name);
            self::assertSame([], $command->getDefinition()->getArguments());
            self::assertSame([], $command->getDefinition()->getOptions());
            $tester = new CommandTester($command);
            self::assertSame(1, $tester->execute([]));
            self::assertStringContainsString('unavailable', $tester->getDisplay());
        }
    }
}
