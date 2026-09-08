<?php

declare(strict_types=1);

namespace FachDock\Tests\Mail;

use DomainException;
use FachDock\Mail\MailTemplateRenderer;
use PHPUnit\Framework\TestCase;

final class MailTemplateRendererTest extends TestCase
{
    public function testRendersAndEscapesHtmlValues(): void
    {
        $mail = (new MailTemplateRenderer())->render(
            'Hallo {{name}}',
            '<p>{{name}}</p><a href="{{url}}">Link</a>',
            "Hallo {{name}}\n{{url}}",
            ['name' => '<Admin>', 'url' => 'https://example.test/?a=1&b=2'],
            ['name', 'url'],
        );

        self::assertSame('Hallo <Admin>', $mail->subject);
        self::assertSame('<p>&lt;Admin&gt;</p><a href="https://example.test/?a=1&amp;b=2">Link</a>', $mail->htmlBody);
        self::assertSame("Hallo <Admin>\nhttps://example.test/?a=1&b=2", $mail->textBody);
    }

    public function testRejectsUnknownPlaceholder(): void
    {
        $this->expectException(DomainException::class);

        (new MailTemplateRenderer())->render(
            '{{name}}',
            '{{name}}',
            '{{name}}',
            ['name' => 'A', 'secret' => 'B'],
            ['name'],
        );
    }

    public function testRemovesHeaderLineBreaksFromSubjectValues(): void
    {
        $mail = (new MailTemplateRenderer())->render(
            'Hallo {{name}}',
            '{{name}}',
            '{{name}}',
            ['name' => "A\r\nBcc: hidden@example.test"],
            ['name'],
        );

        self::assertSame('Hallo A  Bcc: hidden@example.test', $mail->subject);
    }
}
