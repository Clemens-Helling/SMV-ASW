<?php

declare(strict_types=1);

namespace Smv;

use Smv\Domain\Role;
use Smv\Store\Store;

/**
 * Entspricht dem früheren FastAPI-Startup-Event: legt fehlende Standardbenutzer und -Tags an
 * und ergänzt bei alten Benutzern das Rollenfeld. Idempotent; läuft einmal beim Containerstart.
 */
final class Seeder
{
    private const DEFAULT_USERS = [
        ['admin', 'admin123', Role::Admin],
        ['schuelersprecher', 'schueler123', Role::Schuelersprecher],
        ['smv_user', 'user123', Role::User],
    ];

    private const DEFAULT_TAGS = ['Dringend', 'Finanzierung', 'Veranstaltung', 'Regeländerung', 'Sonstiges'];

    /** @param callable(string): void $log */
    public function __construct(private readonly Store $store, private $log = null)
    {
    }

    public function run(): void
    {
        $now = App::now();

        foreach (self::DEFAULT_USERS as [$name, $password, $role]) {
            if ($this->store->findUserByName($name) === null) {
                $this->store->insertUser([
                    'benutzername' => $name,
                    'hashed_password' => App::hashPassword($password),
                    'rolle' => $role->value,
                    'ist_admin' => $role === Role::Admin,
                    'erstellt_am' => $now,
                ]);
                $this->log("Standardbenutzer erstellt: $name");
            }
        }

        foreach ($this->store->findUsersWithoutRole() as $user) {
            $role = !empty($user['ist_admin']) ? Role::Admin : Role::User;
            $this->store->setUserRole($user['id'], $role->value);
            $this->log("Benutzer {$user['benutzername']} migriert zu Rolle: {$role->value}");
        }

        foreach (self::DEFAULT_TAGS as $tag) {
            if (!$this->store->tagExists($tag)) {
                $this->store->insertTag($tag, $now);
            }
        }
    }

    private function log(string $message): void
    {
        if ($this->log !== null) {
            ($this->log)($message);
        }
    }
}
