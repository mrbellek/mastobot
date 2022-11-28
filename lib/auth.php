<?php
declare(strict_types=1);

namespace Mastobot\Lib;

/**
 * Auth class - verify current user is the correct user
 */
class Auth extends Base
{
    public function verifyUser(string $username): bool
    {
        $this->logger->output('Fetching identity..');

        if (!$username) {
            $this->logger->write(2, 'No username');
            $this->logger->output('- No username provided!');

            return false;
        }

        $currentUser = $this->mastodon->getUser();
        if (is_array($currentUser) && !empty($currentUser['username'])) {
			if ($currentUser['username'] === $username) {
				$this->logger->output('- Allowed: @%s, continuing.', $currentUser['username']);
			} else {
				$this->logger->write(2, sprintf(
                    'Authenticated username was unexpected: %s (expected: %s)',
                    $currentUser['username'],
                    $username
                ));
				$this->logger->output(sprintf(
                    '- Not allowed: @%s (expected: %s), halting.',
                    $currentUser['username'],
                    $username
                ));

				return false;
			}
		} else {
			$this->logger->write(2, 'API call failed: getUser');
			$this->logger->output('- API call failed, halting.');

			return false;
        }

        return true;
    }
}