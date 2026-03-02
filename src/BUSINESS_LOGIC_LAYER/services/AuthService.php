<?php
// 01-SOURCE-CODE/services/AuthService.php
namespace SwapSystem\Services;

use SwapSystem\Models\User;
use SwapSystem\Utils\PinHelper;
use SwapSystem\Utils\AuditLogger;
use SwapSystem\Utils\Logger;
use SwapSystem\Config\Communication\CommunicationFactory;

class AuthService
{
    private PinHelper $pinHelper;
    private CommunicationFactory $commFactory;
    private AuditLogger $auditLogger;

    public function __construct(PinHelper $pinHelper, CommunicationFactory $commFactory, AuditLogger $auditLogger)
    {
        $this->pinHelper = $pinHelper;
        $this->commFactory = $commFactory;
        $this->auditLogger = $auditLogger;
    }

    /**
     * Authenticate credentials (email/username + password)
     * Returns User on success or throws Exception.
     */
    public function authenticate(array $credentials): User
    {
        $identifier = $credentials['email'] ?? $credentials['username'] ?? null;
        $password = $credentials['password'] ?? null;

        if (!$identifier || !$password) {
            throw new \Exception('Missing credentials');
        }

        $user = User::findByIdentifier($identifier);
        if (!$user) {
            Logger::warning("Auth failed for identifier {$identifier}");
            throw new \Exception('Invalid credentials');
        }

        if (!password_verify($password, $user->getPasswordHash())) {
            $this->auditLogger->log($user->getId(), 'AUTH_FAILED_PASSWORD', ['identifier' => $identifier]);
            throw new \Exception('Invalid credentials');
        }

        return $user;
    }

    /**
     * Issue MFA challenge via preferred channel.
     */
    public function issueMfaChallenge(User $user): bool
    {
        $code = $this->pinHelper->generateMfaPin($user->getId());
        $channel = $user->getPreferredMfaChannel() ?? 'sms';
        $provider = $this->commFactory->getProvider($channel);

        // send - provider abstracts details
        $provider->send($user->getPhone(), "Your MFA code: {$code}");
        $this->auditLogger->log($user->getId(), 'MFA_ISSUED', ['channel' => $channel]);

        return true;
    }

    /**
     * Verify MFA code.
     */
    public function verifyMfa(string $identifier, string $code): User
    {
        $user = User::findByIdentifier($identifier);
        if (!$user) {
            throw new \Exception('Invalid identifier');
        }

        if (!$this->pinHelper->verifyMfaPin($user->getId(), $code)) {
            $this->auditLogger->log($user->getId(), 'MFA_FAILED', []);
            throw new \Exception('Invalid MFA code');
        }

        $this->auditLogger->log($user->getId(), 'MFA_PASSED', []);
        return $user;
    }

    /**
     * Complete login - generate token and update last login.
     */
    public function completeLogin($user): string
    {
        $userId = $user instanceof User ? $user->getId() : (int)$user;
        User::updateLastLogin($userId);
        // placeholder token generation
        return base64_encode("session:{$userId}:" . time());
    }

    public function invalidateSession(string $token): void
    {
        // TODO: implement session store invalidation
        $this->auditLogger->log(null, 'SESSION_INVALIDATED', ['token' => $token]);
    }
}
