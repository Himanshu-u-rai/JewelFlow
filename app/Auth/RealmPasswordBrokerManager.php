<?php

namespace App\Auth;

use Illuminate\Auth\Passwords\CacheTokenRepository;
use Illuminate\Auth\Passwords\PasswordBrokerManager;

/**
 * Password broker manager that builds the realm-aware token repository
 * (RealmDatabaseTokenRepository, keyed by email + realm) for the database-backed
 * 'users' broker, so password resets are realm-scoped (Dhiran vs ERP). The cache
 * driver branch is left identical to the framework default.
 *
 * Wired in AppServiceProvider by rebinding the 'auth.password' singleton.
 */
class RealmPasswordBrokerManager extends PasswordBrokerManager
{
    protected function createTokenRepository(array $config)
    {
        // Realm-scoping applies ONLY to the realm-aware 'users' broker. Other
        // brokers (e.g. platform_admins) have no realm column/attribute, so use
        // the framework-default repository for them.
        $usersTable = $this->app['config']['auth.passwords.users.table'] ?? 'password_reset_tokens';
        if (($config['table'] ?? null) !== $usersTable) {
            return parent::createTokenRepository($config);
        }

        $key = $this->app['config']['app.key'];

        if (str_starts_with($key, 'base64:')) {
            $key = base64_decode(substr($key, 7));
        }

        if (isset($config['driver']) && $config['driver'] === 'cache') {
            return new CacheTokenRepository(
                $this->app['cache']->store($config['store'] ?? null),
                $this->app['hash'],
                $key,
                ($config['expire'] ?? 60) * 60,
                $config['throttle'] ?? 0,
            );
        }

        return new RealmDatabaseTokenRepository(
            $this->app['db']->connection($config['connection'] ?? null),
            $this->app['hash'],
            $config['table'],
            $key,
            ($config['expire'] ?? 60) * 60,
            $config['throttle'] ?? 0,
        );
    }
}
