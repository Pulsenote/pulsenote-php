<?php

declare(strict_types=1);

namespace Pulsenote\Tests;

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
}
