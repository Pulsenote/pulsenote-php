<?php

declare(strict_types=1);

namespace Pulsenote\Resource;

use Pulsenote\Internal\Operation;
use Pulsenote\Model\Domain;
use Pulsenote\Model\DomainDnsRecords;

/**
 * Sender domains — the domains a tenant is allowed to send `from`.
 *
 * The flow is: {@see add()} → publish the returned DNS records at your registrar →
 * {@see verify()} until the status is `VERIFIED`.
 *
 * Reached as `$pulsenote->domains`.
 */
final class Domains extends Resource
{
    /**
     * List the tenant's sender domains.
     *
     * @return list<Domain>
     */
    #[Operation('listDomains', 'GET', '/api/v1/domains')]
    public function list(): array
    {
        return array_map(
            static fn (array $row): Domain => Domain::fromArray($row),
            $this->transport->requestList('GET', '/api/v1/domains'),
        );
    }

    /**
     * Register a sender domain. The response carries the DNS records to publish.
     *
     * @param string      $domain    Domain to send from, e.g. `mail.example.com`.
     * @param string|null $fromEmail Default from address. Defaults to `noreply@<domain>`.
     * @param string|null $fromName  Default display name for this domain.
     * @param string|null $region    AWS region hosting this domain's SES identity, and
     *                               therefore the jurisdiction its sending is processed in.
     *                               Defaults to the platform region. Currently `eu-west-1`
     *                               is the only supported value; passing an unsupported one
     *                               is rejected by the API rather than silently ignored.
     *
     * @throws \Pulsenote\Exception\ConflictException The domain is already registered.
     */
    #[Operation('addDomain', 'POST', '/api/v1/domains')]
    public function add(
        string $domain,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?string $region = null,
    ): Domain {
        return Domain::fromArray($this->transport->requestObject(
            'POST',
            '/api/v1/domains',
            body: [
                'domain' => $domain,
                'fromEmail' => $fromEmail,
                'fromName' => $fromName,
                'region' => $region,
            ],
        ));
    }

    /**
     * Change the sender identity of a domain you already added.
     *
     * The point of `$fromName` is one account sending under several brands:
     * give each domain its own display name and recipients see the right one,
     * instead of the account name on everything. Pass an empty string to clear
     * it and fall back to the account name.
     *
     * The domain name itself is not editable — that is a different SES identity
     * with different DNS records, so it is an {@see add()} plus a {@see delete()}.
     *
     * Only the arguments you pass are changed; omitted ones are left alone.
     *
     * @param string|null $fromEmail Must be an address on this domain: SES only
     *                               signs mail for the identity it verified.
     * @param string|null $fromName  Display name recipients see. '' clears it.
     * @param bool|null   $isDefault Use this domain when a send omits `from`.
     *                               Promoting one demotes the previous default.
     *
     * @throws \Pulsenote\Exception\NotFoundException   No such domain for this tenant.
     * @throws \Pulsenote\Exception\ValidationException `$fromEmail` is not on this domain.
     */
    #[Operation('updateDomain', 'PATCH', '/api/v1/domains/{id}')]
    public function update(
        string $id,
        ?string $fromEmail = null,
        ?string $fromName = null,
        ?bool $isDefault = null,
    ): Domain {
        return Domain::fromArray($this->transport->requestObject(
            'PATCH',
            '/api/v1/domains/' . rawurlencode($id),
            body: [
                'fromEmail' => $fromEmail,
                'fromName' => $fromName,
                'isDefault' => $isDefault,
            ],
        ));
    }

    /**
     * The DNS records this domain needs, plus what Pulsenote can currently resolve.
     *
     * @throws \Pulsenote\Exception\NotFoundException No such domain for this tenant.
     */
    #[Operation('getDomainDnsRecords', 'GET', '/api/v1/domains/{id}/dns-records')]
    public function dnsRecords(string $id): DomainDnsRecords
    {
        return DomainDnsRecords::fromArray($this->transport->requestObject(
            'GET',
            $this->path('/api/v1/domains/{id}/dns-records', ['id' => $id]),
        ));
    }

    /**
     * The same records as a BIND zone file, ready to import at a registrar.
     */
    #[Operation('getDomainZoneFile', 'GET', '/api/v1/domains/{id}/zone-file')]
    public function zoneFile(string $id): string
    {
        return $this->transport->requestRaw(
            'GET',
            $this->path('/api/v1/domains/{id}/zone-file', ['id' => $id]),
        );
    }

    /**
     * Ask Pulsenote to re-check DNS now. Returns the domain with its refreshed status;
     * verification is not instant, so expect `VERIFYING` before `VERIFIED`.
     *
     * @throws \Pulsenote\Exception\NotFoundException No such domain for this tenant.
     */
    #[Operation('verifyDomain', 'POST', '/api/v1/domains/{id}/verify')]
    public function verify(string $id): Domain
    {
        return Domain::fromArray($this->transport->requestObject(
            'POST',
            $this->path('/api/v1/domains/{id}/verify', ['id' => $id]),
        ));
    }

    /**
     * Remove a sender domain.
     *
     * @throws \Pulsenote\Exception\NotFoundException No such domain for this tenant.
     */
    #[Operation('deleteDomain', 'DELETE', '/api/v1/domains/{id}')]
    public function delete(string $id): void
    {
        $this->transport->requestVoid('DELETE', $this->path('/api/v1/domains/{id}', ['id' => $id]));
    }
}
