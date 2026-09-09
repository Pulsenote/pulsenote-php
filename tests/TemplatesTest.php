<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

use Pulsenote\Enum\ImportConflictPolicy;
use Pulsenote\Model\ExportedTemplate;
use Pulsenote\Tests\Support\TestCase;

final class TemplatesTest extends TestCase
{
    /**
     * @return array<string,mixed>
     */
    private static function templatePayload(string $locale = 'en'): array
    {
        return [
            'id' => 't-1',
            'name' => 'Welcome',
            'slug' => 'welcome',
            'locale' => $locale,
            'subject' => 'Hi {{name}}',
            'body' => '<p>Hi {{name}}</p>',
            'isActive' => true,
            'createdAt' => '2026-07-01T08:00:00.000Z',
            'updatedAt' => '2026-07-02T08:00:00.000Z',
        ];
    }

    public function testListReturnsTypedTemplates(): void
    {
        $this->http->push(200, [self::templatePayload(), self::templatePayload('pl')]);

        $templates = $this->client()->templates->list(locale: 'pl');

        self::assertSame('locale=pl', $this->http->lastRequest()->getUri()->getQuery());
        self::assertCount(2, $templates);
        self::assertSame('welcome', $templates[0]->slug);
        self::assertSame('pl', $templates[1]->locale);
        self::assertTrue($templates[0]->isActive);
    }

    public function testCreatePostsTheUpsertBody(): void
    {
        $this->http->push(201, self::templatePayload());

        $template = $this->client()->templates->create(
            name: 'Welcome',
            slug: 'welcome',
            body: '<p>Hi {{name}}</p>',
            subject: 'Hi {{name}}',
            metadata: ['owner' => 'growth'],
        );

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/templates', $request->getUri()->getPath());
        self::assertSame([
            'name' => 'Welcome',
            'slug' => 'welcome',
            'body' => '<p>Hi {{name}}</p>',
            'subject' => 'Hi {{name}}',
            'metadata' => ['owner' => 'growth'],
        ], $this->http->lastBody());

        self::assertSame('t-1', $template->id);
        self::assertSame('2026-07-01T08:00:00+00:00', $template->createdAt->format(\DateTimeInterface::ATOM));
    }

    public function testGet(): void
    {
        $this->http->push(200, self::templatePayload());

        $this->client()->templates->get('t-1');

        self::assertSame('/api/v1/templates/t-1', $this->http->lastRequest()->getUri()->getPath());
    }

    public function testUpdateUsesPut(): void
    {
        $this->http->push(200, self::templatePayload());

        $this->client()->templates->update(
            id: 't-1',
            name: 'Welcome',
            slug: 'welcome',
            body: '<p>Updated</p>',
        );

        $request = $this->http->lastRequest();
        self::assertSame('PUT', $request->getMethod());
        self::assertSame('/api/v1/templates/t-1', $request->getUri()->getPath());
        self::assertSame(
            ['name' => 'Welcome', 'slug' => 'welcome', 'body' => '<p>Updated</p>'],
            $this->http->lastBody(),
        );
    }

    public function testDeleteReturnsNothingAndToleratesAnEmptyBody(): void
    {
        $this->http->push(200, null);

        $this->client()->templates->delete('t-1');

        $request = $this->http->lastRequest();
        self::assertSame('DELETE', $request->getMethod());
        self::assertSame('/api/v1/templates/t-1', $request->getUri()->getPath());
    }

    public function testRender(): void
    {
        $this->http->push(200, ['subject' => 'Hi Greg', 'html' => '<p>Hi Greg</p>', 'text' => 'Hi Greg']);

        $rendered = $this->client()->templates->render('t-1', ['name' => 'Greg']);

        $request = $this->http->lastRequest();
        self::assertSame('POST', $request->getMethod());
        self::assertSame('/api/v1/templates/t-1/render', $request->getUri()->getPath());
        self::assertSame(['data' => ['name' => 'Greg']], $this->http->lastBody());

        self::assertSame('<p>Hi Greg</p>', $rendered->html);
        self::assertSame('Hi Greg', $rendered->subject);
    }

    public function testListLocales(): void
    {
        $this->http->push(200, [self::templatePayload('en'), self::templatePayload('pl')]);

        $variants = $this->client()->templates->listLocales('welcome');

        self::assertSame('/api/v1/templates/slug/welcome/locales', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame(['en', 'pl'], array_map(static fn ($t) => $t->locale, $variants));
    }

    public function testExportDropsIdsSoTheFileTravels(): void
    {
        $this->http->push(200, [
            'version' => 1,
            'exportedAt' => '2026-09-09T10:00:00.000Z',
            'templates' => [[
                'slug' => 'welcome',
                'locale' => 'en',
                'name' => 'Welcome',
                'body' => '<p>Hi</p>',
                'subject' => 'Hi',
                'isActive' => true,
            ]],
        ]);

        $file = $this->client()->templates->export();

        self::assertSame('GET', $this->http->lastRequest()->getMethod());
        self::assertSame('/api/v1/templates/export', $this->http->lastRequest()->getUri()->getPath());
        self::assertSame(1, $file->version);
        self::assertCount(1, $file);
        // Identity is slug + locale. There is no id to carry, which is what
        // makes the file usable in a different account.
        self::assertSame('welcome', $file->templates[0]->slug);
        self::assertSame('en', $file->templates[0]->locale);
    }

    public function testImportLeavesTheConflictPolicyToTheApiByDefault(): void
    {
        $this->http->push(200, ['created' => 1, 'updated' => 0, 'skipped' => 0, 'results' => []]);

        $this->client()->templates->import([
            ['slug' => 'welcome', 'locale' => 'en', 'name' => 'Welcome', 'body' => '<p>Hi</p>'],
        ]);

        $body = $this->http->lastBody();
        self::assertSame('POST', $this->http->lastRequest()->getMethod());
        self::assertSame('/api/v1/templates/import', $this->http->lastRequest()->getUri()->getPath());
        // Not sent, so the API's own default (skip) applies. An SDK that filled
        // in 'overwrite' here would replace live templates for someone who
        // never asked.
        self::assertArrayNotHasKey('onConflict', $body);
    }

    public function testImportSendsTheConflictPolicyWhenAsked(): void
    {
        $this->http->push(200, ['created' => 0, 'updated' => 1, 'skipped' => 0, 'results' => []]);

        $result = $this->client()->templates->import(
            [['slug' => 'welcome', 'locale' => 'en', 'name' => 'Welcome', 'body' => '<p>Hi</p>']],
            ImportConflictPolicy::Overwrite,
        );

        self::assertSame('overwrite', $this->http->lastBody()['onConflict']);
        self::assertSame(1, $result->updated);
    }

    public function testImportAcceptsAnExportStraightBack(): void
    {
        $exported = new ExportedTemplate(
            slug: 'welcome',
            locale: 'en',
            name: 'Welcome',
            body: '<p>Hi</p>',
        );
        $this->http->push(200, ['created' => 0, 'updated' => 0, 'skipped' => 1, 'results' => [
            ['slug' => 'welcome', 'locale' => 'en', 'result' => 'skipped'],
        ]]);

        $result = $this->client()->templates->import([$exported]);

        // Round-tripping an export object must not need the caller to unpack it.
        self::assertSame(
            ['slug' => 'welcome', 'locale' => 'en', 'name' => 'Welcome', 'body' => '<p>Hi</p>', 'isActive' => true],
            $this->http->lastBody()['templates'][0],
        );
        // Skipping is the default and is NOT an error — the call does not
        // throw, so the count is the only thing that says nothing changed.
        self::assertSame(1, $result->skipped);
        self::assertCount(1, $result->skipped());
    }
}
