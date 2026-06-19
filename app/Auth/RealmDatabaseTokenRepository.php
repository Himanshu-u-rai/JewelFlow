<?php

namespace App\Auth;

use App\Support\Realm;
use Illuminate\Auth\Passwords\DatabaseTokenRepository;
use Illuminate\Contracts\Auth\CanResetPassword as CanResetPasswordContract;
use Illuminate\Support\Carbon;

/**
 * Password-reset token repository keyed by (email, realm) instead of email only.
 *
 * Laravel's stock DatabaseTokenRepository keys every token row by the user's
 * email. Once Dhiran exists, the same email can belong to an 'erp' account AND a
 * 'dhiran' account, so an email-only key is ambiguous — one realm's reset could
 * overwrite or match the other's token. This subclass adds the user's realm to
 * every where/payload, so each realm has its own independent token row for the
 * same email. Mirrors the table change in
 * …_add_realm_to_password_reset_tokens (PK (email, realm)).
 *
 * The realm comes from the user model (App\Models\User has a `realm` attribute),
 * defaulting to 'erp' for any non-User CanResetPassword implementation.
 */
class RealmDatabaseTokenRepository extends DatabaseTokenRepository
{
    private function realmOf(CanResetPasswordContract $user): string
    {
        $realm = $user->realm ?? Realm::ERP;

        return Realm::isValid($realm) ? $realm : Realm::ERP;
    }

    public function create(CanResetPasswordContract $user)
    {
        $email = $user->getEmailForPasswordReset();

        $this->deleteExisting($user);

        $token = $this->createNewToken();

        $this->getTable()->insert($this->getRealmPayload($email, $this->realmOf($user), $token));

        return $token;
    }

    protected function deleteExisting(CanResetPasswordContract $user)
    {
        return $this->getTable()
            ->where('email', $user->getEmailForPasswordReset())
            ->where('realm', $this->realmOf($user))
            ->delete();
    }

    /** Realm-aware payload (the parent getPayload signature can't carry realm). */
    protected function getRealmPayload(string $email, string $realm, #[\SensitiveParameter] string $token): array
    {
        return [
            'email' => $email,
            'realm' => $realm,
            'token' => $this->hasher->make($token),
            'created_at' => new Carbon,
        ];
    }

    public function exists(CanResetPasswordContract $user, #[\SensitiveParameter] $token)
    {
        $record = (array) $this->getTable()
            ->where('email', $user->getEmailForPasswordReset())
            ->where('realm', $this->realmOf($user))
            ->first();

        return $record
            && ! $this->tokenExpired($record['created_at'])
            && $this->hasher->check($token, $record['token']);
    }

    public function recentlyCreatedToken(CanResetPasswordContract $user)
    {
        $record = (array) $this->getTable()
            ->where('email', $user->getEmailForPasswordReset())
            ->where('realm', $this->realmOf($user))
            ->first();

        return $record && $this->tokenRecentlyCreated($record['created_at']);
    }

    public function delete(CanResetPasswordContract $user)
    {
        $this->deleteExisting($user);
    }
}
