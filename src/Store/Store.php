<?php

declare(strict_types=1);

namespace Smv\Store;

/**
 * Datenzugriff. Dokumente sind assoziative Arrays mit String-"id" statt "_id";
 * Zeitstempel sind \DateTimeImmutable (UTC).
 */
interface Store
{
    /** @return array<string, mixed>|null */
    public function findUserByName(string $name): ?array;

    /** @param array<string, mixed> $user @return string neue ID */
    public function insertUser(array $user): string;

    /** @return list<array<string, mixed>> Benutzer ohne Feld "rolle" (Migration alter Daten) */
    public function findUsersWithoutRole(): array;

    public function setUserRole(string $id, string $role): void;

    /** @param array<string, mixed> $antrag @return string neue ID */
    public function insertAntrag(array $antrag): string;

    /**
     * @param array{status?: string, phase?: string, tag?: string} $filter
     * @return list<array<string, mixed>> neueste zuerst
     */
    public function findAntraege(array $filter, int $skip, int $limit): array;

    /** @return array<string, mixed>|null */
    public function findAntrag(string $id): ?array;

    /** @param array<string, mixed> $set */
    public function updateAntrag(string $id, array $set): void;

    public function deleteAntrag(string $id): bool;

    public function countAntraege(): int;

    /** @return array<string, int> Anzahl je Wert des Feldes ("status" | "phase"), nach Wert sortiert */
    public function groupCount(string $field): array;

    /** @return list<string> alphabetisch */
    public function tagNames(): array;

    public function tagExists(string $name): bool;

    public function insertTag(string $name, \DateTimeImmutable $createdAt): void;

    public function deleteTag(string $name): bool;

    public function pullTagFromAntraege(string $name, \DateTimeImmutable $now): void;

    public function ping(): bool;
}
