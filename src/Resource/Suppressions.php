<?php

declare(strict_types=1);

namespace Pulsenote\Resource;

use Pulsenote\Enum\MessageStream;
use Pulsenote\Internal\Operation;
use Pulsenote\Model\Suppression;

/**
 * Addresses this tenant will not send to.
 *
 * Entries appear automatically when the provider reports a hard bounce or a
 * spam complaint — you do not have to manage those. {@see add()} is for the
 * ones you decide yourself.
 *
 * Suppression is scoped per {@see MessageStream}: blocking someone on
 * `Broadcast` still lets a `Transactional` password reset through.
 *
 * Reached as `$pulsenote->suppressions`.
 */
final class Suppressions extends Resource
{
    /**
     * List suppressed recipients, newest first.
     *
     * The API caps this at 500 entries.
     *
     * @return list<Suppression>
     */
    #[Operation('listSuppressions', 'GET', '/api/v1/suppressions')]
    public function list(): array
    {
        return array_map(
            static fn (array $row): Suppression => Suppression::fromArray($row),
            $this->transport->requestList('GET', '/api/v1/suppressions'),
        );
    }

    /**
     * Suppress an address by hand.
     *
     * Idempotent per address and stream — adding one that is already suppressed
     * refreshes the existing entry rather than failing, so this is safe to
     * retry and safe to run from a loop you are not sure finished.
     *
     * @param string            $email  Address to suppress; normalised server-side.
     * @param MessageStream|null $stream Defaults to `Transactional`.
     */
    #[Operation('addSuppression', 'POST', '/api/v1/suppressions')]
    public function add(string $email, ?MessageStream $stream = null): Suppression
    {
        return Suppression::fromArray($this->transport->requestObject(
            'POST',
            '/api/v1/suppressions',
            body: [
                'email' => $email,
                'stream' => $stream?->value,
            ],
        ));
    }

    /**
     * Remove a suppression, allowing sending to that address again.
     *
     * @throws \Pulsenote\Exception\NotFoundException No suppression with that id
     *   belongs to this tenant. Ids are tenant-scoped, so an id that never
     *   existed and one belonging to somebody else both arrive here.
     */
    #[Operation('deleteSuppression', 'DELETE', '/api/v1/suppressions/{id}')]
    public function remove(string $id): void
    {
        $this->transport->requestVoid(
            'DELETE',
            $this->path('/api/v1/suppressions/{id}', ['id' => $id]),
        );
    }
}
