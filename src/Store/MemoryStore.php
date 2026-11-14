<?php

declare(strict_types=1);

namespace Smv\Store;

/** In-Memory-Implementierung für Tests (APP_ENV=testing, STORE=memory). */
final class MemoryStore implements Store
{
    /** @var array<string, array<string, mixed>> */
    private array $users = [];
    /** @var array<string, array<string, mixed>> */
    private array $antraege = [];
    /** @var array<string, array<string, mixed>> */
    private array $tags = [];

    private static function newId(): string
    {
        return bin2hex(random_bytes(12));
    }

    public function findUserByName(string $name): ?array
    {
        foreach ($this->users as $u) {
            if ($u['benutzername'] === $name) {
                return $u;
            }
        }
        return null;
    }

    public function insertUser(array $user): string
    {
        $id = self::newId();
        $this->users[$id] = ['id' => $id] + $user;
        return $id;
    }

    public function findUsersWithoutRole(): array
    {
        return array_values(array_filter($this->users, static fn (array $u): bool => !array_key_exists('rolle', $u)));
    }

    public function setUserRole(string $id, string $role): void
    {
        if (isset($this->users[$id])) {
            $this->users[$id]['rolle'] = $role;
        }
    }

    public function insertAntrag(array $antrag): string
    {
        $id = self::newId();
        $this->antraege[$id] = ['id' => $id] + $antrag;
        return $id;
    }

    public function findAntraege(array $filter, int $skip, int $limit): array
    {
        $rows = array_values(array_filter($this->antraege, static function (array $a) use ($filter): bool {
            return (!isset($filter['status']) || ($a['status'] ?? null) === $filter['status'])
                && (!isset($filter['phase']) || ($a['phase'] ?? null) === $filter['phase'])
                && (!isset($filter['tag']) || in_array($filter['tag'], $a['tags'] ?? [], true));
        }));
        usort($rows, static fn (array $a, array $b): int => $b['erstellt_am'] <=> $a['erstellt_am']);
        return array_slice($rows, $skip, $limit);
    }

    public function findAntrag(string $id): ?array
    {
        return $this->antraege[$id] ?? null;
    }

    public function updateAntrag(string $id, array $set): void
    {
        if (isset($this->antraege[$id])) {
            $this->antraege[$id] = $set + $this->antraege[$id];
        }
    }

    public function deleteAntrag(string $id): bool
    {
        $existed = isset($this->antraege[$id]);
        unset($this->antraege[$id]);
        return $existed;
    }

    public function countAntraege(): int
    {
        return count($this->antraege);
    }

    public function groupCount(string $field): array
    {
        $out = [];
        foreach ($this->antraege as $a) {
            $key = (string) ($a[$field] ?? '');
            $out[$key] = ($out[$key] ?? 0) + 1;
        }
        ksort($out, SORT_STRING);
        return $out;
    }

    public function tagNames(): array
    {
        $names = array_map(static fn (array $t): string => (string) $t['name'], array_values($this->tags));
        sort($names, SORT_STRING);
        return $names;
    }

    public function tagExists(string $name): bool
    {
        return $this->tagId($name) !== null;
    }

    public function insertTag(string $name, \DateTimeImmutable $createdAt): void
    {
        $id = self::newId();
        $this->tags[$id] = ['id' => $id, 'name' => $name, 'erstellt_am' => $createdAt];
    }

    public function deleteTag(string $name): bool
    {
        $id = $this->tagId($name);
        if ($id === null) {
            return false;
        }
        unset($this->tags[$id]);
        return true;
    }

    public function pullTagFromAntraege(string $name, \DateTimeImmutable $now): void
    {
        foreach ($this->antraege as $id => $a) {
            if (in_array($name, $a['tags'] ?? [], true)) {
                $this->antraege[$id]['tags'] = array_values(array_filter($a['tags'], static fn (string $t): bool => $t !== $name));
                $this->antraege[$id]['aktualisiert_am'] = $now;
            }
        }
    }

    public function ping(): bool
    {
        return true;
    }

    private function tagId(string $name): ?string
    {
        foreach ($this->tags as $id => $t) {
            if ($t['name'] === $name) {
                return $id;
            }
        }
        return null;
    }
}
