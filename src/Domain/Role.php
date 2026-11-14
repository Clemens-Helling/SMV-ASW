<?php

declare(strict_types=1);

namespace Smv\Domain;

enum Role: string
{
    use EnumValues;

    case Admin = 'admin';
    case Schuelersprecher = 'schuelersprecher';
    case User = 'user';

    /** Rolle eines Datenbank-Benutzers; alte Benutzer ohne Rollenfeld werden über ist_admin abgeleitet. */
    public static function fromUser(array $user): self
    {
        if (isset($user['rolle']) && is_string($user['rolle'])) {
            $role = self::tryFrom($user['rolle']);
            if ($role !== null) {
                return $role;
            }
        }
        return !empty($user['ist_admin']) ? self::Admin : self::User;
    }
}
