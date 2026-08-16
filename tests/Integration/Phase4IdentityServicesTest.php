<?php

declare(strict_types=1);

namespace App\Tests\Integration;

use App\Module\Audit\Application\Contract\AuditRecord;
use App\Module\Audit\Application\Contract\AuditSink;
use App\Module\Identity\Application\AdministrativeRole;
use App\Module\Identity\Application\CanonicalOrigin;
use App\Module\Identity\Application\LoginThrottle;
use App\Module\Identity\Application\LoginThrottled;
use App\Module\Identity\Application\MfaManagement;
use App\Module\Identity\Application\MfaVerifier;
use App\Module\Identity\Application\PasswordPolicy;
use App\Module\Identity\Application\PasswordResetService;
use App\Module\Identity\Application\ReauthenticationService;
use App\Module\Identity\Application\SensitiveValueHasher;
use App\Module\Identity\Application\SessionRegistry;
use App\Module\Identity\Application\Totp;
use App\Module\Identity\Application\UserAdministration;
use App\Module\Identity\Infrastructure\DatabaseSessionHandler;
use App\Module\Identity\Infrastructure\DoctrineUserProvider;
use App\Module\Identity\Infrastructure\HybridSessionHandler;
use App\Tests\Support\Phase4State;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\DriverManager;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Process\Process;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

#[Group('integration')]
final class Phase4IdentityServicesTest extends KernelTestCase
{
    use Phase4State;

    protected function tearDown(): void
    {
        if (null !== self::$kernel) {
            $this->removePhase4Files();
        }
        parent::tearDown();
    }

    public function testAccountAndSourceThrottlesAreBoundedAndRecover(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $throttle = $this->phase4Service(LoginThrottle::class);

        self::assertFalse($throttle->loginFailed($throttle->reserveLogin('owner@example.test', '2001:db8::81')));
        $this->expectThrottle($throttle, 'owner@example.test', '2001:db8::81');

        for ($attempt = 2; $attempt <= 5; ++$attempt) {
            $connection->executeStatement('UPDATE login_throttle SET not_before = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE locked_until IS NULL');
            self::assertSame(5 === $attempt, $throttle->loginFailed($throttle->reserveLogin('owner@example.test', '2001:db8::81')));
        }
        $this->expectThrottle($throttle, 'owner@example.test', '2001:db8::99');
        self::assertSame(5, $this->phase4Int($connection->fetchOne("SELECT failure_count FROM login_throttle WHERE scope_type = 'account'")));

        $connection->executeStatement("UPDATE login_throttle SET locked_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND), not_before = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE scope_type = 'account'");
        self::assertFalse($throttle->loginFailed($throttle->reserveLogin('owner@example.test', '2001:db8::99')));
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT failure_count FROM login_throttle WHERE scope_type = 'account'")));

        $connection->executeStatement('UPDATE login_throttle SET last_failed_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 16 MINUTE), locked_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND), not_before = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertFalse($throttle->loginFailed($throttle->reserveLogin('owner@example.test', '2001:db8::81')));
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT failure_count FROM login_throttle WHERE scope_type = 'account'")));

        for ($attempt = 1; $attempt <= 20; ++$attempt) {
            self::assertSame(20 === $attempt, $throttle->loginFailed($throttle->reserveLogin('source-user-'.$attempt.'@example.test', '2001:db8::100')));
            $connection->executeStatement('UPDATE login_throttle SET not_before = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND) WHERE locked_until IS NULL');
        }
        $this->expectThrottle($throttle, 'fresh-account@example.test', '2001:db8::100');
        self::assertSame(20, $this->phase4Int($connection->fetchOne(
            "SELECT failure_count FROM login_throttle WHERE scope_type = 'source' AND scope_hash = ?",
            [$throttle->sourceHash('2001:db8::100')],
        )));
        $connection->executeStatement('UPDATE login_throttle SET last_failed_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 16 MINUTE), locked_until = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND), not_before = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertFalse($throttle->loginFailed($throttle->reserveLogin('fresh-account@example.test', '2001:db8::100')));
        self::assertSame(1, $this->phase4Int($connection->fetchOne(
            "SELECT failure_count FROM login_throttle WHERE scope_type = 'source' AND scope_hash = ?",
            [$throttle->sourceHash('2001:db8::100')],
        )));

        $connection->executeStatement('DELETE FROM login_throttle');
        $oldLoginReservation = $throttle->reserveLogin('cleanup-race@example.test', '2001:db8::101');
        $newLoginReservation = $throttle->reserveLogin('cleanup-race@example.test', '2001:db8::101');
        $throttle->loginSucceeded($oldLoginReservation);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT pending_count FROM login_throttle WHERE scope_type = 'account'")));
        $throttle->loginSucceeded($newLoginReservation);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM login_throttle WHERE scope_type = 'account'")));

        $connection->executeStatement('DELETE FROM login_throttle');
        $oldReauthReservations = $throttle->reserveReauthentication('user-id', '2001:db8::102', 'session-id');
        $newReauthReservations = $throttle->reserveReauthentication('user-id', '2001:db8::102', 'session-id');
        $throttle->reauthenticationSucceeded($oldReauthReservations);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT MIN(pending_count) FROM login_throttle WHERE scope_type LIKE 'reauth_%'")));
        $throttle->reauthenticationSucceeded($newReauthReservations);
        self::assertSame(0, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM login_throttle WHERE scope_type LIKE 'reauth_%'")));
    }

    public function testCanonicalOriginRejectsNonOriginComponentsAndNormalizesHost(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $origin = $this->phase4Service(CanonicalOrigin::class);
        $connection->update('organisation_settings', ['canonical_base_url' => 'https://LOCALHOST:443/'], ['singleton_key' => '1']);
        self::assertSame('https://localhost', $origin->value());

        foreach (['https://user@localhost', 'https://localhost?redirect=attacker', 'https://localhost#fragment', 'https://localhost/path'] as $invalid) {
            $connection->update('organisation_settings', ['canonical_base_url' => $invalid], ['singleton_key' => '1']);
            try {
                $origin->value();
                self::fail('A non-origin canonical URL was accepted.');
            } catch (\RuntimeException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testDatabaseSessionsEnforceIdleAbsoluteExpiryAndRevocation(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $user = $this->createPhase4User($connection, 'sessions@example.test', 'phase4-sessions-'.bin2hex(random_bytes(10)));
        $handler = $this->phase4Service(DatabaseSessionHandler::class);
        $registry = $this->phase4Service(SessionRegistry::class);

        self::assertTrue($handler->write('raw-session-one', 'serialized-payload'));
        $registry->bind('raw-session-one', $user->id(), '2001:db8::82', 'Synthetic browser');
        self::assertSame('serialized-payload', $handler->read('raw-session-one'));
        $listed = $registry->listForUser($user->id(), 'raw-session-one');
        self::assertCount(1, $listed);
        self::assertTrue($listed[0]['current']);
        self::assertStringNotContainsString('raw-session-one', json_encode($listed, JSON_THROW_ON_ERROR));
        self::assertStringNotContainsString('raw-session-one', json_encode($connection->fetchAllAssociative('SELECT * FROM user_session'), JSON_THROW_ON_ERROR));

        self::assertTrue($registry->revoke($user->id(), $listed[0]['id_hash']));
        self::assertSame('', $connection->fetchOne('SELECT payload FROM user_session WHERE id_hash = ?', [$listed[0]['id_hash']]));
        $registry->bind('raw-session-one', $user->id(), '2001:db8::82', 'Late response');
        self::assertNotNull($connection->fetchOne('SELECT revoked_at FROM user_session WHERE id_hash = ?', [$listed[0]['id_hash']]));
        self::assertSame('', $handler->read('raw-session-one'));

        $handler->write('raw-session-two', 'payload-two');
        $connection->executeStatement('UPDATE user_session SET idle_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertSame('', $handler->read('raw-session-two'));
        $handler->write('raw-session-two', 'late-idle-payload');
        self::assertSame('', $handler->read('raw-session-two'));
        $handler->write('raw-session-three', 'payload-three');
        $connection->executeStatement('UPDATE user_session SET absolute_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 1 SECOND)');
        self::assertSame('', $handler->read('raw-session-three'));
        $handler->write('raw-session-three', 'late-absolute-payload');
        self::assertSame('', $handler->read('raw-session-three'));

        $handler->destroy('retained-tombstone');
        $connection->executeStatement(
            'UPDATE user_session SET revoked_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 8 DAY), absolute_expires_at = DATE_SUB(UTC_TIMESTAMP(6), INTERVAL 7 DAY) WHERE id_hash = ?',
            [$registry->currentHash('retained-tombstone')],
        );
        self::assertGreaterThanOrEqual(1, $handler->gc(1800));
        self::assertFalse($connection->fetchOne('SELECT 1 FROM user_session WHERE id_hash = ?', [$registry->currentHash('retained-tombstone')]));
    }

    public function testSessionRevocationIsMonotonicAcrossSeparateConnectionInterleaving(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $user = $this->createPhase4User($connection, 'session-race@example.test', 'phase4-session-race-'.bin2hex(random_bytes(10)));
        $hasher = $this->phase4Service(SensitiveValueHasher::class);
        $params = $connection->getParams();
        $host = $params['host'] ?? null;
        $port = $params['port'] ?? null;
        $userName = $params['user'] ?? null;
        $password = $params['password'] ?? null;
        $databaseName = $params['dbname'] ?? null;
        $charset = $params['charset'] ?? null;
        self::assertIsString($host);
        self::assertIsInt($port);
        self::assertIsString($userName);
        self::assertIsString($password);
        self::assertIsString($databaseName);
        self::assertIsString($charset);
        $lateConnection = DriverManager::getConnection([
            'driver' => 'pdo_mysql',
            'host' => $host,
            'port' => $port,
            'user' => $userName,
            'password' => $password,
            'dbname' => $databaseName,
            'charset' => $charset,
        ]);
        $lateHandler = new DatabaseSessionHandler($lateConnection, $hasher);
        $lateRegistry = new SessionRegistry($lateConnection, $hasher);
        $handler = $this->phase4Service(DatabaseSessionHandler::class);
        $registry = $this->phase4Service(SessionRegistry::class);

        $handler->write('interleaved-session', 'authenticated-state');
        $registry->bind('interleaved-session', $user->id(), '2001:db8::120', 'First request');
        self::assertSame('authenticated-state', $lateHandler->read('interleaved-session'));
        $hash = $registry->currentHash('interleaved-session');
        self::assertTrue($registry->revoke($user->id(), $hash));

        self::assertTrue($lateHandler->write('interleaved-session', 'late-authenticated-state'));
        $lateRegistry->bind('interleaved-session', $user->id(), '2001:db8::121', 'Late response');
        self::assertSame('', $handler->read('interleaved-session'));
        self::assertNotNull($connection->fetchOne('SELECT revoked_at FROM user_session WHERE id_hash = ?', [$hash]));

        $handler->destroy('destroyed-session');
        $lateHandler->write('destroyed-session', 'late-after-destroy');
        self::assertSame('', $handler->read('destroyed-session'));
        self::assertNotNull($connection->fetchOne('SELECT revoked_at FROM user_session WHERE id_hash = ?', [$registry->currentHash('destroyed-session')]));
        $lateConnection->close();
    }

    public function testRoleEscalationSessionRevocationAndFinalOwnerInvariant(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $password = 'phase4-users-'.bin2hex(random_bytes(10));
        $owner = $this->createPhase4User($connection, 'owner@example.test', $password);
        $administrator = $this->createPhase4User($connection, 'admin@example.test', $password, AdministrativeRole::Administrator);
        $users = $this->phase4Service(UserAdministration::class);
        $denialBaseline = $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'authorization.denied'"));
        $denialSource = $this->phase4Service(LoginThrottle::class)->sourceHash('2001:db8::denied');

        try {
            $users->create($administrator, 'forbidden-owner@example.test', $password, AdministrativeRole::Owner, $denialSource);
            self::fail('Administrator escalated a user to Owner.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
        self::assertSame($denialBaseline + 1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM audit_event WHERE action = 'authorization.denied'")));
        self::assertSame($denialSource, $connection->fetchOne("SELECT source_hash FROM audit_event WHERE action = 'authorization.denied' ORDER BY occurred_at DESC LIMIT 1"));
        $operatorId = $users->create($administrator, 'operator@example.test', $password, AdministrativeRole::Operator);
        self::assertSame(AdministrativeRole::Operator->value, $connection->fetchOne('SELECT role FROM app_user WHERE id = ?', [$operatorId]));

        $this->phase4Service(SessionRegistry::class)->bind('operator-session', $operatorId, null, null);
        $users->update($administrator, $operatorId, AdministrativeRole::Auditor, false);
        self::assertNotNull($connection->fetchOne('SELECT revoked_at FROM user_session WHERE user_id = ?', [$operatorId]));

        try {
            $users->update($owner, $owner->id(), AdministrativeRole::Administrator, true);
            self::fail('The final active Owner was demoted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('Owner', $exception->getMessage());
        }
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM app_user WHERE role = 'ROLE_OWNER' AND active = 1")));
    }

    #[DataProvider('ownerMutationOperations')]
    public function testConcurrentFinalOwnerMutationsCannotRemoveEveryOwner(string $operation): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $password = 'phase4-concurrent-'.bin2hex(random_bytes(10));
        $firstOwner = $this->createPhase4User($connection, 'owner-one@example.test', $password);
        $secondOwner = $this->createPhase4User($connection, 'owner-two@example.test', $password);
        $projectDirectory = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDirectory);
        $firstSignal = sys_get_temp_dir().'/emailer-phase4-owner-'.bin2hex(random_bytes(8)).'-one';
        $secondSignal = $firstSignal.'-two';
        self::ensureKernelShutdown();

        $firstTarget = 'delete' === $operation ? $secondOwner->id() : $firstOwner->id();
        $secondTarget = 'delete' === $operation ? $firstOwner->id() : $secondOwner->id();
        $first = new Process([PHP_BINARY, 'tests/Support/phase4-owner-mutation-worker.php', $operation, $firstOwner->id(), $firstTarget, $firstSignal, $secondSignal], $projectDirectory);
        $second = new Process([PHP_BINARY, 'tests/Support/phase4-owner-mutation-worker.php', $operation, $secondOwner->id(), $secondTarget, $secondSignal, $firstSignal], $projectDirectory);
        $first->start();
        $second->start();
        $first->wait();
        $second->wait();
        @unlink($firstSignal);
        @unlink($secondSignal);

        self::assertTrue($first->isSuccessful(), $first->getErrorOutput());
        self::assertTrue($second->isSuccessful(), $second->getErrorOutput());
        $outcomes = [$first->getOutput(), $second->getOutput()];
        sort($outcomes);
        self::assertSame(['rejected', 'updated'], $outcomes);

        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        self::assertSame(1, $this->phase4Int($connection->fetchOne("SELECT COUNT(*) FROM app_user WHERE role = 'ROLE_OWNER' AND active = 1")));
    }

    /** @return array<string, array{string}> */
    public static function ownerMutationOperations(): array
    {
        return [
            'demotion' => ['demote'],
            'disable' => ['disable'],
            'deletion' => ['delete'],
        ];
    }

    public function testMfaEnrollmentRecoveryUseAndLifecycleCleanup(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $user = $this->createPhase4User($connection, 'mfa@example.test', 'phase4-mfa-'.bin2hex(random_bytes(10)));
        $management = $this->phase4Service(MfaManagement::class);
        $totp = $this->phase4Service(Totp::class);
        $verifier = $this->phase4Service(MfaVerifier::class);

        $first = $management->start($user);
        $firstSecretIds = $connection->fetchFirstColumn('SELECT id FROM encrypted_secret');
        self::assertCount(1, $firstSecretIds);
        $second = $management->start($user);
        self::assertCount(1, $connection->fetchFirstColumn('SELECT id FROM encrypted_secret'));
        self::assertNotSame($first->seed, $second->seed);
        self::assertSame(11, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM mfa_credential WHERE user_id = ?', [$user->id()])));
        $mfaStorage = json_encode([
            $connection->fetchAllAssociative('SELECT * FROM mfa_credential'),
            $connection->fetchAllAssociative('SELECT * FROM encrypted_secret'),
        ], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($second->seed, $mfaStorage);
        foreach ($second->recoveryCodes as $recoveryCode) {
            self::assertStringNotContainsString($recoveryCode, $mfaStorage);
        }

        $management->confirm($user, $totp->code($second->seed, intdiv(time(), 30) - 1), true, 'current-session');
        self::assertTrue($verifier->required($user->id()));
        $currentTotp = $totp->code($second->seed, intdiv(time(), 30));
        self::assertTrue($verifier->verify($user->id(), $currentTotp));
        self::assertFalse($verifier->verify($user->id(), $currentTotp));
        self::assertTrue($verifier->verify($user->id(), $second->recoveryCodes[0]));
        self::assertFalse($verifier->verify($user->id(), $second->recoveryCodes[0]));
        self::assertSame(9, $management->status($user)['recovery_remaining']);

        $replacementCodes = $management->regenerateRecoveryCodes($user, 'current-session');
        self::assertCount(10, $replacementCodes);
        self::assertFalse($verifier->verify($user->id(), $second->recoveryCodes[1]));
        $management->disable($user, 'current-session');
        self::assertSame(['enabled' => false, 'recovery_remaining' => 0, 'pending' => false], $management->status($user));
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM encrypted_secret')));
        $this->assertPhase4RuntimeFilesExclude([$second->seed, $second->recoveryCodes[0]]);
    }

    public function testConcurrentTotpAndAuthenticationReservationsAreAtomic(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $user = $this->createPhase4User($connection, 'atomic-mfa@example.test', 'phase4-atomic-mfa-'.bin2hex(random_bytes(10)));
        $management = $this->phase4Service(MfaManagement::class);
        $totp = $this->phase4Service(Totp::class);
        $enrollment = $management->start($user);
        $management->confirm($user, $totp->code($enrollment->seed, intdiv(time(), 30) - 1), true, 'not-live');
        $currentCode = $totp->code($enrollment->seed, intdiv(time(), 30) + 1);
        $projectDirectory = self::getContainer()->getParameter('kernel.project_dir');
        self::assertIsString($projectDirectory);
        self::ensureKernelShutdown();

        $totpOutcomes = $this->runSecurityWorkers($projectDirectory, 2, ['totp', $user->id(), $currentCode, 'unused']);
        self::assertSame(['accepted', 'rejected'], $totpOutcomes);

        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $connection->executeStatement('DELETE FROM login_throttle');
        self::ensureKernelShutdown();
        $loginOutcomes = $this->runSecurityWorkers($projectDirectory, 6, ['login', 'parallel@example.test', '2001:db8::122', 'unused']);
        self::assertSame(5, \count(array_filter($loginOutcomes, static fn (string $outcome): bool => 'accepted' === $outcome)));

        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        self::assertSame(5, $this->phase4Int($connection->fetchOne("SELECT pending_count FROM login_throttle WHERE scope_type = 'account'")));
        $connection->executeStatement('DELETE FROM login_throttle');
        self::ensureKernelShutdown();
        $reauthOutcomes = $this->runSecurityWorkers($projectDirectory, 6, ['reauthentication', $user->id(), '2001:db8::123', 'shared-session']);
        self::assertSame(5, \count(array_filter($reauthOutcomes, static fn (string $outcome): bool => 'accepted' === $outcome)));
    }

    public function testReauthenticationConsumesTotpAndRecoveryProofsOnlyOnce(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $password = 'phase4-reauth-mfa-'.bin2hex(random_bytes(10));
        $user = $this->createPhase4User($connection, 'reauth-mfa@example.test', $password);
        $management = $this->phase4Service(MfaManagement::class);
        $totp = $this->phase4Service(Totp::class);
        $enrollment = $management->start($user);
        $management->confirm($user, $totp->code($enrollment->seed, intdiv(time(), 30) - 1), true, 'not-live');
        $service = $this->phase4Service(ReauthenticationService::class);
        $currentCode = $totp->code($enrollment->seed, intdiv(time(), 30));

        self::assertTrue($service->verify($user, $password, $currentCode, 'mfa_management', '2001:db8::124', 'reauth-session'));
        self::assertFalse($service->verify($user, $password, $currentCode, 'mfa_management', '2001:db8::124', 'reauth-session'));
        $connection->executeStatement('DELETE FROM login_throttle');
        self::assertTrue($service->verify($user, $password, $enrollment->recoveryCodes[0], 'mfa_management', '2001:db8::124', 'reauth-session'));
        self::assertFalse($service->verify($user, $password, $enrollment->recoveryCodes[0], 'mfa_management', '2001:db8::124', 'reauth-session'));
    }

    public function testPasswordPolicyDummyIdentityAndMfaDeletionRollback(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $policy = $this->phase4Service(PasswordPolicy::class);
        foreach (['short', 'passwordpassword', str_repeat('x', 15), 'person@example.test-is-not-safe'] as $rejected) {
            try {
                $policy->assertAcceptable($rejected, 'person@example.test');
                self::fail('A weak or context-specific password was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $policy->assertAcceptable('Correct-Horse-Battery-'.bin2hex(random_bytes(8)), 'person@example.test');
        self::addToAssertionCount(1);

        $provider = $this->phase4Service(DoctrineUserProvider::class);
        $dummy = $provider->loadForAuthentication('missing@example.test');
        self::assertSame(DoctrineUserProvider::DUMMY_ID, $dummy->id());
        self::assertStringStartsWith('$argon2id$', $dummy->getPassword());
        $this->createPhase4User($connection, 'disabled@example.test', 'phase4-disabled-'.bin2hex(random_bytes(10)), AdministrativeRole::Auditor, false);
        self::assertSame(DoctrineUserProvider::DUMMY_ID, $provider->loadForAuthentication('disabled@example.test')->id());
        $verifier = $this->phase4Service(MfaVerifier::class);
        self::assertFalse($verifier->verify(DoctrineUserProvider::DUMMY_ID, 'DEADBEEF-DEADBEEF'));

        $this->phase4Service(HybridSessionHandler::class);
        $privateDirectory = self::getContainer()->getParameter('emailer.private_dir');
        self::assertIsString($privateDirectory);
        clearstatcache(true, $privateDirectory.'/sessions');
        self::assertSame(0700, fileperms($privateDirectory.'/sessions') & 0777);

        $actor = $this->createPhase4User($connection, 'cleanup-owner@example.test', 'phase4-cleanup-owner-'.bin2hex(random_bytes(10)));
        $target = $this->createPhase4User($connection, 'cleanup-user@example.test', 'phase4-cleanup-user-'.bin2hex(random_bytes(10)), AdministrativeRole::Administrator);
        $management = $this->phase4Service(MfaManagement::class);
        $management->start($target);
        $registry = $this->phase4Service(SessionRegistry::class);
        $registry->bind('deleted-user-session', $target->id(), null, null);
        $deletedSessionHash = $registry->currentHash('deleted-user-session');
        $secretId = $this->phase4String($connection->fetchOne('SELECT encrypted_secret_id FROM mfa_credential WHERE user_id = ? AND encrypted_secret_id IS NOT NULL', [$target->id()]));
        $users = $this->phase4Service(UserAdministration::class);
        $connection->executeStatement("CREATE TRIGGER phase4_secret_delete_failure BEFORE DELETE ON encrypted_secret FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'synthetic rollback'");
        try {
            try {
                $users->delete($actor, $target->id());
                self::fail('Synthetic secret-deletion failure did not roll back user deletion.');
            } catch (\Throwable) {
                self::addToAssertionCount(1);
            }
        } finally {
            $connection->executeStatement('DROP TRIGGER phase4_secret_delete_failure');
        }
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE id = ?', [$target->id()])));
        self::assertSame(1, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM encrypted_secret WHERE id = ?', [$secretId])));
        self::assertSame(11, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM mfa_credential WHERE user_id = ?', [$target->id()])));

        $users->delete($actor, $target->id());
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM app_user WHERE id = ?', [$target->id()])));
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM encrypted_secret WHERE id = ?', [$secretId])));
        self::assertSame(0, $this->phase4Int($connection->fetchOne('SELECT COUNT(*) FROM mfa_credential WHERE user_id = ?', [$target->id()])));
        self::assertNotNull($connection->fetchOne('SELECT revoked_at FROM user_session WHERE id_hash = ? AND user_id IS NULL', [$deletedSessionHash]));
        $this->phase4Service(DatabaseSessionHandler::class)->write('deleted-user-session', 'late-deleted-user-state');
        self::assertSame('', $this->phase4Service(DatabaseSessionHandler::class)->read('deleted-user-session'));
    }

    public function testPasswordResetIsHashedExpiringSingleUseAndRevokesAuthority(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $oldPassword = 'phase4-reset-old-'.bin2hex(random_bytes(10));
        $newPassword = 'phase4-reset-new-'.bin2hex(random_bytes(10));
        $user = $this->createPhase4User($connection, 'reset@example.test', $oldPassword);
        $registry = $this->phase4Service(SessionRegistry::class);
        $registry->bind('reset-session', $user->id(), null, null);
        $reset = $this->phase4Service(PasswordResetService::class);
        $selector = '019c5be7-7000-7000-8000-000000000001';
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $connection->insert('password_reset_token', [
            'id' => $selector,
            'user_id' => $user->id(),
            'token_hash' => hash('sha256', $token),
            'created_at' => new \DateTimeImmutable('-1 minute')->format('Y-m-d H:i:s.u'),
            'expires_at' => new \DateTimeImmutable('+29 minutes')->format('Y-m-d H:i:s.u'),
            'consumed_at' => null,
        ]);
        self::assertStringNotContainsString($token, json_encode($connection->fetchAllAssociative('SELECT * FROM password_reset_token'), JSON_THROW_ON_ERROR));
        self::assertSame($user->id(), $reset->complete($selector, $token, $newPassword, null));
        self::assertTrue(password_verify($newPassword, $this->phase4String($connection->fetchOne('SELECT password_hash FROM app_user WHERE id = ?', [$user->id()]))));
        self::assertNotNull($connection->fetchOne('SELECT revoked_at FROM user_session WHERE user_id = ?', [$user->id()]));
        $this->expectExceptionFor(static fn () => $reset->complete($selector, $token, $newPassword.'x', null));

        $expiredSelector = '019c5be7-7000-7000-8000-000000000002';
        $connection->insert('password_reset_token', [
            'id' => $expiredSelector,
            'user_id' => $user->id(),
            'token_hash' => hash('sha256', 'expired-token-value-that-is-long-enough'),
            'created_at' => new \DateTimeImmutable('-1 hour')->format('Y-m-d H:i:s.u'),
            'expires_at' => new \DateTimeImmutable('-1 minute')->format('Y-m-d H:i:s.u'),
            'consumed_at' => null,
        ]);
        $this->expectExceptionFor(static fn () => $reset->complete($expiredSelector, 'expired-token-value-that-is-long-enough', $newPassword.'y', null));
    }

    public function testAuditEventsRejectSensitiveDataAndDatabaseMutation(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $sink = $this->phase4Service(AuditSink::class);

        $id = $sink->append(new AuditRecord(null, 'phase4.synthetic', null, 'success', ['method' => 'test']));
        self::assertSame('{"method":"test"}', $connection->fetchOne('SELECT summary_json FROM audit_event WHERE id = ?', [$id]));
        foreach ([['password' => 'secret'], ['method' => 'person@example.test'], ['session' => 'value']] as $summary) {
            try {
                $sink->append(new AuditRecord(null, 'phase4.synthetic', null, 'success', $summary));
                self::fail('Sensitive audit material was accepted.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
        $this->expectDatabaseRejection(static fn () => $connection->update('audit_event', ['result' => 'failure'], ['id' => $id]));
        $this->expectDatabaseRejection(static fn () => $connection->delete('audit_event', ['id' => $id]));
    }

    public function testReauthenticationAssertionIsPurposeBoundAndExpires(): void
    {
        self::bootKernel();
        $connection = $this->phase4Service(Connection::class);
        $this->preparePhase4State($connection);
        $service = $this->phase4Service(ReauthenticationService::class);
        $session = new Session(new MockArraySessionStorage());
        $service->mark($session, 'user_management');
        $service->assertRecent($session, 'user_management');
        self::addToAssertionCount(1);

        $this->expectAccessDenied(static fn () => $service->assertRecent($session, 'mfa_management'));
        $session->set('identity.reauthenticated_at', time() - 301);
        $this->expectAccessDenied(static fn () => $service->assertRecent($session, 'user_management'));
    }

    private function expectThrottle(LoginThrottle $throttle, string $login, string $source): void
    {
        try {
            $throttle->reserveLogin($login, $source);
            self::fail('A throttled authentication request was accepted.');
        } catch (LoginThrottled) {
            self::addToAssertionCount(1);
        }
    }

    /** @param \Closure(): mixed $operation */
    private function expectExceptionFor(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('An invalid operation was accepted.');
        } catch (\RuntimeException) {
            self::addToAssertionCount(1);
        }
    }

    /** @param \Closure(): mixed $operation */
    private function expectDatabaseRejection(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Append-only audit data was mutated.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('append-only', strtolower($exception->getMessage()));
        }
    }

    /** @param \Closure(): mixed $operation */
    private function expectAccessDenied(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('An invalid recent-authentication assertion was accepted.');
        } catch (AccessDeniedException) {
            self::addToAssertionCount(1);
        }
    }

    /**
     * @param array{string, string, string, string} $arguments
     *
     * @return list<string>
     */
    private function runSecurityWorkers(string $projectDirectory, int $count, array $arguments): array
    {
        $prefix = sys_get_temp_dir().'/emailer-phase4-security-'.bin2hex(random_bytes(8));
        $release = $prefix.'-release';
        $processes = [];
        $readyFiles = [];
        for ($index = 0; $index < $count; ++$index) {
            $ready = $prefix.'-ready-'.$index;
            $readyFiles[] = $ready;
            $process = new Process([PHP_BINARY, 'tests/Support/phase4-security-worker.php', ...$arguments, $ready, $release], $projectDirectory);
            $process->start();
            $processes[] = $process;
        }
        $deadline = microtime(true) + 10.0;
        while (\count(array_filter($readyFiles, 'file_exists')) !== $count && microtime(true) < $deadline) {
            usleep(1_000);
        }
        self::assertSame($count, \count(array_filter($readyFiles, 'file_exists')), 'Every worker reached the explicit start barrier.');
        file_put_contents($release, 'release', LOCK_EX);

        $outcomes = [];
        foreach ($processes as $process) {
            $process->wait();
            self::assertTrue($process->isSuccessful(), 'Worker failed: '.$process->getErrorOutput().$process->getOutput());
            $outcomes[] = trim($process->getOutput());
        }
        foreach ([...$readyFiles, $release] as $file) {
            @unlink($file);
        }
        sort($outcomes);

        return $outcomes;
    }
}
