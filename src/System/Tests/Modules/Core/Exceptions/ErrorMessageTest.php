<?php

namespace System\Tests\Modules\Core\Exceptions;

use PHPUnit\Framework\TestCase;
use System\Modules\Core\Exceptions\ErrorMessage;
use System\Modules\Core\Exceptions\ErrorMessage\BadRequest;
use System\Modules\Core\Exceptions\ErrorMessage\Forbidden;
use System\Modules\Core\Exceptions\ErrorMessage\NotFound;

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
        $error = new ErrorMessage(
            message: 'msg',
            description: "line one\nline two",
            httpStatusCode: 200,
            publicDescription: true
        );

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_HTML, false);
        $output = ob_get_clean();

        $this->assertMatchesRegularExpression('/line one<br\s*\/?>\s*\nline two/', $output);
    }

    /**
     * The framework's own descriptions name classes, methods and paths - Router's 404
     * carries "Method x of class Y could not be found". With debug off those are internal
     * detail, whatever format the client asked for.
     */
    public function testDescriptionsAreWithheldUnlessTheThrowerPublishesThem()
    {
        $description = 'Method "wire" of class "Payments" could not be found';

        foreach (
            [
                ErrorMessage::OUTPUT_TYPE_HTML,
                ErrorMessage::OUTPUT_TYPE_PLAIN,
                ErrorMessage::OUTPUT_TYPE_JSON,
                ErrorMessage::OUTPUT_TYPE_XML,
            ] as $outputType
        ) {
            $error = new ErrorMessage(message: 'Not Found', description: $description, httpStatusCode: 404);

            ob_start();
            $error->outputMessage($outputType, false);
            $output = ob_get_clean();

            $this->assertStringNotContainsString('Payments', $output, $outputType);
        }
    }

    public function testAPublishedDescriptionIsShown()
    {
        $error = new ErrorMessage(
            message: 'Not Found',
            description: 'That invoice has been archived.',
            httpStatusCode: 404,
            publicDescription: true
        );

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_PLAIN, false);
        $output = ob_get_clean();

        $this->assertStringContainsString('That invoice has been archived.', $output);
    }

    public function testTheStatusPageCarriesNeitherTheMessageNorTheDescription()
    {
        $error = new ErrorMessage(
            message: 'Not Found',
            description: 'No controller for path: "admin/secrets"',
            httpStatusCode: 404
        );

        ob_start();
        $error->outputMessage(ErrorMessage::OUTPUT_TYPE_HTML, true);
        $output = ob_get_clean();

        $this->assertStringStartsWith('<!DOCTYPE html>', $output);
        $this->assertStringContainsString('404', $output);
        $this->assertStringNotContainsString('admin/secrets', $output);
        $this->assertStringNotContainsString('ErrorMessage', $output);
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

    /*
    | Status codes
    */

    public function testThrownErrorMessageDefaultsTo500()
    {
        // Defaulting to 200 meant an error page was served under a success status
        $error = new ErrorMessage('something broke');

        $this->assertEquals(500, $error->httpStatusCode);
    }

    public function testNotFoundCarriesA404AndItsMessage()
    {
        $error = new NotFound('Not Found', 'no controller for /x');

        $this->assertEquals(404, $error->httpStatusCode);
        $this->assertEquals('Not Found', $error->getMessage());
        $this->assertEquals('no controller for /x', $error->description);
        $this->assertEquals('Not Found', $error->httpStatusMessage);
    }

    public function testForbiddenCarriesA403()
    {
        $this->assertEquals(403, (new Forbidden())->httpStatusCode);
    }

    public function testBadRequestCarriesA400()
    {
        $this->assertEquals(400, (new BadRequest())->httpStatusCode);
    }

    public function testClientFaultsAreErrorMessagesSoTheyAreNotLogged()
    {
        // Router::init() renders ErrorMessage subclasses without logging or emailing, and
        // sends everything else down the 500-plus-alert path. That split is what keeps a
        // crawler on dead urls from paging someone, so it is worth pinning.
        $this->assertInstanceOf(ErrorMessage::class, new NotFound());
        $this->assertInstanceOf(ErrorMessage::class, new Forbidden());
        $this->assertInstanceOf(ErrorMessage::class, new BadRequest());
    }

    public function testStatusCodeToMessage()
    {
        $this->assertEquals('Not Found', ErrorMessage::httpStatusCodeToMessage(404));
        $this->assertEquals('Internal Server Error', ErrorMessage::httpStatusCodeToMessage(500));
        $this->assertEquals('Unknown Status Code', ErrorMessage::httpStatusCodeToMessage(799));
    }
}
