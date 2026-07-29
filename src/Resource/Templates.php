<?php

declare(strict_types=1);

namespace Pulsenote\Resource;

use Pulsenote\Internal\Operation;
use Pulsenote\Model\RenderedTemplate;
use Pulsenote\Model\Template;

/**
 * Stored email templates, one row per (slug, locale) pair.
 *
 * Reached as `$pulsenote->templates`.
 */
final class Templates extends Resource
{
    /**
     * List templates.
     *
     * @param string|null $locale Restrict to one locale (e.g. `pl`).
     *
     * @return list<Template>
     */
    #[Operation('listTemplates', 'GET', '/api/v1/templates')]
    public function list(?string $locale = null): array
    {
        return array_map(
            static fn (array $row): Template => Template::fromArray($row),
            $this->transport->requestList('GET', '/api/v1/templates', query: ['locale' => $locale]),
        );
    }

    /**
     * Create a template.
     *
     * @param string                   $name     Human-readable template name.
     * @param string                   $slug     URL-safe identifier, unique per tenant + locale.
     * @param string                   $body     Template body (HTML). Supports variable interpolation.
     * @param string|null              $subject  Subject line. Supports template variables.
     * @param string|null              $locale   Locale of this variant. Defaults to `en`.
     * @param array<string,mixed>|null $metadata Arbitrary template metadata.
     */
    #[Operation('createTemplate', 'POST', '/api/v1/templates')]
    public function create(
        string $name,
        string $slug,
        string $body,
        ?string $subject = null,
        ?string $locale = null,
        ?array $metadata = null,
    ): Template {
        return Template::fromArray($this->transport->requestObject(
            'POST',
            '/api/v1/templates',
            body: [
                'name' => $name,
                'slug' => $slug,
                'body' => $body,
                'subject' => $subject,
                'locale' => $locale,
                'metadata' => $metadata,
            ],
        ));
    }

    /**
     * Fetch one template by ID.
     *
     * @throws \Pulsenote\Exception\NotFoundException No such template for this tenant.
     */
    #[Operation('getTemplate', 'GET', '/api/v1/templates/{id}')]
    public function get(string $id): Template
    {
        return Template::fromArray($this->transport->requestObject(
            'GET',
            $this->path('/api/v1/templates/{id}', ['id' => $id]),
        ));
    }

    /**
     * Replace a template. This is a PUT — `name`, `slug`, and `body` are required
     * even when only one of them changes.
     *
     * @param array<string,mixed>|null $metadata Arbitrary template metadata.
     */
    #[Operation('updateTemplate', 'PUT', '/api/v1/templates/{id}')]
    public function update(
        string $id,
        string $name,
        string $slug,
        string $body,
        ?string $subject = null,
        ?string $locale = null,
        ?array $metadata = null,
    ): Template {
        return Template::fromArray($this->transport->requestObject(
            'PUT',
            $this->path('/api/v1/templates/{id}', ['id' => $id]),
            body: [
                'name' => $name,
                'slug' => $slug,
                'body' => $body,
                'subject' => $subject,
                'locale' => $locale,
                'metadata' => $metadata,
            ],
        ));
    }

    /**
     * Delete a template.
     */
    #[Operation('deleteTemplate', 'DELETE', '/api/v1/templates/{id}')]
    public function delete(string $id): void
    {
        $this->transport->requestVoid('DELETE', $this->path('/api/v1/templates/{id}', ['id' => $id]));
    }

    /**
     * Preview a template with sample data. Nothing is sent.
     *
     * @param array<string,mixed>|null $data Sample data to interpolate into the template.
     */
    #[Operation('renderTemplate', 'POST', '/api/v1/templates/{id}/render')]
    public function render(string $id, ?array $data = null): RenderedTemplate
    {
        return RenderedTemplate::fromArray($this->transport->requestObject(
            'POST',
            $this->path('/api/v1/templates/{id}/render', ['id' => $id]),
            body: ['data' => $data],
        ));
    }

    /**
     * List every locale variant stored under one slug.
     *
     * @return list<Template>
     */
    #[Operation('listTemplateLocales', 'GET', '/api/v1/templates/slug/{slug}/locales')]
    public function listLocales(string $slug): array
    {
        return array_map(
            static fn (array $row): Template => Template::fromArray($row),
            $this->transport->requestList(
                'GET',
                $this->path('/api/v1/templates/slug/{slug}/locales', ['slug' => $slug]),
            ),
        );
    }
}
