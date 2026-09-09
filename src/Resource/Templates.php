<?php

declare(strict_types=1);

namespace Pulsenote\Resource;

use Pulsenote\Enum\ImportConflictPolicy;
use Pulsenote\Internal\Operation;
use Pulsenote\Model\ExportedTemplate;
use Pulsenote\Model\RenderedTemplate;
use Pulsenote\Model\Template;
use Pulsenote\Model\TemplateExport;
use Pulsenote\Model\TemplateImportResult;

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
     * Export every template as a portable file.
     *
     * Identity in the result is `slug` + `locale`; no IDs are included, so the
     * file can go straight into {@see self::import()} on another account —
     * moving between organisations, seeding a staging tenant, or keeping a
     * backup that is yours rather than ours.
     *
     * ```php
     * $file = $pulsenote->templates->export();
     * file_put_contents('templates.json', json_encode($file->templates));
     * ```
     */
    #[Operation('exportTemplates', 'GET', '/api/v1/templates/export')]
    public function export(): TemplateExport
    {
        return TemplateExport::fromArray($this->transport->requestObject(
            'GET',
            '/api/v1/templates/export',
        ));
    }

    /**
     * Load templates into this account.
     *
     * A template that is already there (same slug and locale) is **skipped**
     * unless `$onConflict` says otherwise. That default is deliberate:
     * replacing a live template is not something to do by accident, and this
     * method does not throw when it skips — read the result.
     *
     * Your plan's template limit applies to the import as a whole, counting
     * distinct slugs, so locale variants of one template cost no extra quota.
     *
     * @param list<ExportedTemplate|array<string,mixed>> $templates Templates to load.
     * @param ImportConflictPolicy|null                  $onConflict Defaults to skip.
     * @param int|null                                   $version    Format version of the file. Defaults to the current one.
     *
     * @throws \Pulsenote\Exception\AuthenticationException The import would exceed the plan's template limit (403).
     * @throws \Pulsenote\Exception\ValidationException Malformed file, or a version this API cannot read.
     */
    #[Operation('importTemplates', 'POST', '/api/v1/templates/import')]
    public function import(
        array $templates,
        ?ImportConflictPolicy $onConflict = null,
        ?int $version = null,
    ): TemplateImportResult {
        return TemplateImportResult::fromArray($this->transport->requestObject(
            'POST',
            '/api/v1/templates/import',
            body: [
                'version' => $version,
                'templates' => array_map(
                    static fn (ExportedTemplate|array $t): array => $t instanceof ExportedTemplate
                        ? $t->jsonSerialize()
                        : $t,
                    $templates,
                ),
                // Left null when not given, so the API's default applies rather
                // than a default this SDK invented.
                'onConflict' => $onConflict?->value,
            ],
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
