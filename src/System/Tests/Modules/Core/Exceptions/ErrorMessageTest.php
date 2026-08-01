<?php

namespace System\Tests\Modules\Core\Exceptions;

use PHPUnit\Framework\TestCase;
use System\Modules\Core\Exceptions\ErrorMessage;

/**
 * Exception messages routinely carry request data - Router wraps the requested url into
 * one - and controllers may return an ErrorMessage built from user input, so every
 * output format has to escape.
 */
class ErrorMessageTest extends TestCase
{
    private const PAYLOAD = '<img src=x onerror=alert(1)>';

    public function testHtmlOutputEscapesTheMessage()
    {
        $error = new ErrorMessage(message: self::PAYLOAD, httpStatusCode: 200);

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_HTML, false);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<img', $output);
        $this->assertStringContainsString('&lt;img', $output);
    }

    public function testHtmlOutputEscapesTheDescription()
    {
        $error = new ErrorMessage(message: 'msg', description: self::PAYLOAD, httpStatusCode: 200);

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_HTML, false);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<img', $output);
    }

    public function testHtmlOutputStillTurnsNewlinesIntoBreaks()
    {
        $error = new ErrorMessage(message: 'msg', description: "a\nb", httpStatusCode: 200);

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_HTML, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('<br', $output);
    }

    public function testXmlOutputEscapesTheMessage()
    {
        $error = new ErrorMessage(message: self::PAYLOAD, httpStatusCode: 200);

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_XML, false);
        $output = ob_get_clean();

        $this->assertStringNotContainsString('<img', $output);
        $this->assertStringContainsString('&lt;img', $output);
    }

    public function testXmlOutputRemainsWellFormed()
    {
        $error = new ErrorMessage(
            message: 'a & b <tag>',
            description: 'quote " and \' apostrophe',
            httpStatusCode: 200
        );

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_XML, false);
        $output = ob_get_clean();

        $previous = libxml_use_internal_errors(true);
        $document = simplexml_load_string($output);
        libxml_use_internal_errors($previous);

        $this->assertNotFalse($document, 'xml output did not parse');
        $this->assertEquals('a & b <tag>', (string) $document->Text);
    }

    public function testJsonOutputIsEncoded()
    {
        $error = new ErrorMessage(message: self::PAYLOAD, httpStatusCode: 200);

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_JSON, false);
        $output = ob_get_clean();

        $decoded = json_decode($output, true);

        $this->assertIsArray($decoded);
        $this->assertEquals(self::PAYLOAD, $decoded['msg']['text']);
    }

    public function testPlainOutputIsNotMarkup()
    {
        $error = new ErrorMessage(message: self::PAYLOAD, httpStatusCode: 200);

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_PLAIN, false);
        $output = ob_get_clean();

        // text/plain is not parsed as html, so the payload is inert and passes through
        $this->assertStringContainsString(self::PAYLOAD, $output);
    }

    public function testStatusCodeToMessage()
    {
        $this->assertEquals('Not Found', ErrorMessage::httpStatusCodeToMessage(404));
        $this->assertEquals('Internal Server Error', ErrorMessage::httpStatusCodeToMessage(500));
        $this->assertEquals('Unknown Status Code', ErrorMessage::httpStatusCodeToMessage(799));
    }
}
