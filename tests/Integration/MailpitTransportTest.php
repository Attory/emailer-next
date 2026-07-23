<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Infrastructure\Mail\TestSmtpPolicy;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Mailer\Mailer;
use Symfony\Component\Mailer\Transport;
use Symfony\Component\Mime\Email;

#[Group('integration')]
final class MailpitTransportTest extends KernelTestCase
{
    public function testSyntheticMessageIsAcceptedByMailpit(): void
    {
        self::bootKernel();

        $message = (new Email())
            ->from('phase1-sender@example.test')
            ->to('phase1-recipient@example.test')
            ->subject('[TEST] Phase 1 Mailpit transport')
            ->text('Synthetic Phase 1 transport check.');

        $dsn = $_SERVER['MAILER_DSN'] ?? null;
        if (!\is_string($dsn)) {
            throw new \LogicException('The test mail DSN is not configured.');
        }

        TestSmtpPolicy::assertSafe($dsn);

        $mailer = new Mailer(Transport::fromDsn($dsn));
        $mailer->send($message);

        self::addToAssertionCount(1);
    }
}
