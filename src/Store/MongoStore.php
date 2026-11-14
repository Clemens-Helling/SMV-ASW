<?php

declare(strict_types=1);

namespace Smv\Store;

use MongoDB\BSON\ObjectId;
use MongoDB\BSON\UTCDateTime;
use MongoDB\Client;
use MongoDB\Collection;

/**
 * MongoDB-Implementierung (gleiche Datenbank "SMV" und Collections wie das bisherige Python-Backend:
 * antraege, benutzer, tags). Erfordert ext-mongodb und mongodb/mongodb.
 */
final class MongoStore implements Store
{
    private Client $client;
    private Collection $antraege;
    private Collection $benutzer;
    private Collection $tags;

    public function __construct(string $uri, string $database)
    {
        $this->client = new Client(
            $uri,
            ['serverSelectionTimeoutMS' => 5000, 'connectTimeoutMS' => 10000, 'socketTimeoutMS' => 10000],
            ['typeMap' => ['root' => 'array', 'document' => 'array', 'array' => 'array']],
        );
        $db = $this->client->selectDatabase($database);
        $this->antraege = $db->selectCollection('antraege');
        $this->benutzer = $db->selectCollection('benutzer');
        $this->tags = $db->selectCollection('tags');
    }

    public function findUserByName(string $name): ?array
    {
        return self::fromBson($this->benutzer->findOne(['benutzername' => $name]));
    }

    public function insertUser(array $user): string
    {
        return (string) $this->benutzer->insertOne(self::toBson($user))->getInsertedId();
    }

    public function findUsersWithoutRole(): array
    {
        $out = [];
        foreach ($this->benutzer->find(['rolle' => ['$exists' => false]]) as $doc) {
            $out[] = self::fromBson($doc);
        }
        return $out;
    }

    public function setUserRole(string $id, string $role): void
    {
        $this->benutzer->updateOne(['_id' => new ObjectId($id)], ['$set' => ['rolle' => $role]]);
    }

    public function insertAntrag(array $antrag): string
    {
        return (string) $this->antraege->insertOne(self::toBson($antrag))->getInsertedId();
    }

    public function findAntraege(array $filter, int $skip, int $limit): array
    {
        $query = [];
        if (isset($filter['status'])) {
            $query['status'] = $filter['status'];
        }
        if (isset($filter['phase'])) {
            $query['phase'] = $filter['phase'];
        }
        if (isset($filter['tag'])) {
            $query['tags'] = ['$in' => [$filter['tag']]];
        }
        $out = [];
        foreach ($this->antraege->find($query, ['sort' => ['erstellt_am' => -1], 'skip' => $skip, 'limit' => $limit]) as $doc) {
            $out[] = self::fromBson($doc);
        }
        return $out;
    }

    public function findAntrag(string $id): ?array
    {
        return self::fromBson($this->antraege->findOne(['_id' => new ObjectId($id)]));
    }

    public function updateAntrag(string $id, array $set): void
    {
        $this->antraege->updateOne(['_id' => new ObjectId($id)], ['$set' => self::toBson($set)]);
    }

    public function deleteAntrag(string $id): bool
    {
        return $this->antraege->deleteOne(['_id' => new ObjectId($id)])->getDeletedCount() > 0;
    }

    public function countAntraege(): int
    {
        return $this->antraege->countDocuments([]);
    }

    public function groupCount(string $field): array
    {
        $out = [];
        $cursor = $this->antraege->aggregate([
            ['$group' => ['_id' => '$' . $field, 'count' => ['$sum' => 1]]],
            ['$sort' => ['_id' => 1]],
        ]);
        foreach ($cursor as $doc) {
            $out[(string) $doc['_id']] = (int) $doc['count'];
        }
        return $out;
    }

    public function tagNames(): array
    {
        $out = [];
        foreach ($this->tags->find([], ['sort' => ['name' => 1]]) as $doc) {
            $out[] = (string) $doc['name'];
        }
        return $out;
    }

    public function tagExists(string $name): bool
    {
        return $this->tags->findOne(['name' => $name]) !== null;
    }

    public function insertTag(string $name, \DateTimeImmutable $createdAt): void
    {
        $this->tags->insertOne(self::toBson(['name' => $name, 'erstellt_am' => $createdAt]));
    }

    public function deleteTag(string $name): bool
    {
        return $this->tags->deleteOne(['name' => $name])->getDeletedCount() > 0;
    }

    public function pullTagFromAntraege(string $name, \DateTimeImmutable $now): void
    {
        $this->antraege->updateMany(
            ['tags' => $name],
            ['$pull' => ['tags' => $name], '$set' => ['aktualisiert_am' => new UTCDateTime($now)]],
        );
    }

    public function ping(): bool
    {
        try {
            $this->client->selectDatabase('admin')->command(['ping' => 1]);
            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @param array<string, mixed> $doc
     * @return array<string, mixed>
     */
    private static function toBson(array $doc): array
    {
        foreach ($doc as $key => $value) {
            if ($value instanceof \DateTimeInterface) {
                $doc[$key] = new UTCDateTime($value);
            } elseif (is_array($value)) {
                $doc[$key] = array_values($value); // Listen (z. B. tags) als BSON-Array speichern
            }
        }
        return $doc;
    }

    /**
     * @param array<string, mixed>|null $doc
     * @return array<string, mixed>|null
     */
    private static function fromBson(?array $doc): ?array
    {
        if ($doc === null) {
            return null;
        }
        $out = ['id' => (string) $doc['_id']];
        unset($doc['_id']);
        foreach ($doc as $key => $value) {
            $out[$key] = $value instanceof UTCDateTime
                ? \DateTimeImmutable::createFromMutable($value->toDateTime())->setTimezone(new \DateTimeZone('UTC'))
                : $value;
        }
        return $out;
    }
}
