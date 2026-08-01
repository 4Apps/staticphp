<?php

namespace System\Modules\Core\Exceptions;

use Throwable;
use System\Modules\Core\Interfaces\RequestContentType;
use System\Modules\Core\Models\Config;

/**
 * Global ErrorMessage Exception for throwing and catching custom errors
 */
class ErrorMessage extends \Exception
{
    public ?string $description = null;

    public int $httpStatusCode;
    public ?string $httpStatusMessage;

    private ?string $forceOutputType = null;
    private bool $showStackTrace = false;
    private bool $publicDescription = false;

    public const OUTPUT_TYPE_PLAIN = 'plain';
    public const OUTPUT_TYPE_HTML = 'html';
    public const OUTPUT_TYPE_JSON = 'json';
    public const OUTPUT_TYPE_XML = 'xml';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?string $description = null,
        ?Throwable $previous = null,
        // A thrown ErrorMessage is by definition not a success. Defaulting to 200 meant
        // `throw new ErrorMessage('boom')` rendered an error page under a 200 OK.
        int $httpStatusCode = 500,
        ?string $httpStatusMessage = null,
        ?string $forceOutputType = null,
        bool $showStackTrace = false,
        // The framework's own descriptions name classes, methods and paths - Router's 404
        // carries "Method x of class Y could not be found". Those are notes to a
        // developer, so a description reaches the public only when the thrower says it
        // may. Anyone else gets the standard explanation for the status code.
        bool $publicDescription = false
    ) {
        $this->description = $description;
        $this->httpStatusCode = $httpStatusCode;

        if (!empty($httpStatusMessage)) {
            $this->httpStatusMessage = $httpStatusMessage;
        } else {
            $this->httpStatusMessage = ErrorMessage::httpStatusCodeToMessage($httpStatusCode);
        }
        $this->forceOutputType = $forceOutputType;
        $this->showStackTrace = $showStackTrace;
        $this->publicDescription = $publicDescription;

        parent::__construct($message, $code, $previous);
    }

    private function gatherTrace()
    {
        $previous = $this->getPrevious();
        $stackTrace = empty($previous) ? "\n\nTrace:\n" . $this->getTraceAsString() : '';
        while ($previous) {
            $stackTrace .= "\n\nTrace:\n" . $previous->getTraceAsString();

            $previous = $previous->getPrevious();
        }

        return trim($stackTrace);
    }

    /**
     * The description, if this one is allowed out.
     *
     * Debug mode sees everything. Everybody else sees a description only when the thrower
     * marked it publishable - see $publicDescription.
     *
     * @return ?string
     */
    private function visibleDescription(): ?string
    {
        if ($this->publicDescription === true || Config::get('debug', false) === true) {
            return $this->description;
        }

        return null;
    }

    /**
     * Picks between the two full page renderings and builds it.
     *
     * This is the single place that decides how much a browser is told. Debug on means
     * the developer page - message, source, trace, request. Debug off means the status
     * page, which carries the status code, whatever description the thrower chose to
     * publish, and nothing else.
     *
     * @return string
     */
    private function htmlPage(): string
    {
        if (Config::get('debug', false) === true) {
            return ErrorPage::debug(
                // Router wraps the real failure into an ErrorMessage, so the interesting
                // trace is usually one level down
                $this->getPrevious() ?? $this,
                $this->httpStatusCode,
                ErrorPage::requestId(),
                $this->description
            );
        }

        return ErrorPage::status(
            $this->httpStatusCode,
            $this->httpStatusMessage,
            $this->visibleDescription(),
            // A reference is only worth printing when there is something to correlate it
            // with. 5xx is logged; a crawler hitting a dead url is not.
            ($this->httpStatusCode >= 500 ? ErrorPage::requestId() : null)
        );
    }

    public function outputMessage($outputType = ErrorMessage::OUTPUT_TYPE_HTML, $includeHtmlTemplate = false)
    {
        // Set HTTP status code.
        // http_response_code() is protocol agnostic - a literal "HTTP/1.0 ..." status line
        // pins that version onto what may well be an HTTP/2 response.
        if (headers_sent() == false && $this->httpStatusCode != 200) {
            http_response_code($this->httpStatusCode);
        }

        // Gather stack trace
        $stackTrace = '';
        if (Config::get('debug', false) === true && $this->showStackTrace === true) {
            $stackTrace = $this->gatherTrace();
        }

        // See $publicDescription
        $description = (string) $this->visibleDescription();

        // Force output type
        if (!empty($this->forceOutputType)) {
            $outputType = $this->forceOutputType;
        }

        // Errors are often rendered after output has already begun, and header() warns
        // rather than returning quietly in that case - the status line above is already
        // guarded, so guard the content type the same way
        $canSendHeaders = (headers_sent() === false);

        // Output message
        switch ($outputType) {
            case ErrorMessage::OUTPUT_TYPE_PLAIN:
                $canSendHeaders && header('Content-Type:text/plain; charset=utf-8');
                echo "{$this->code} {$this->message}\n\n{$description}\n\n{$stackTrace}";
                break;


            case ErrorMessage::OUTPUT_TYPE_JSON:
                $canSendHeaders && header('Content-Type:application/json; charset=utf-8');
                echo json_encode([
                    'msg' => [
                        'code' => $this->code,
                        'text' => $this->message,
                        'description' => trim("{$description}\n{$stackTrace}", "\n"),
                    ],
                ]);
                break;

            case ErrorMessage::OUTPUT_TYPE_XML:
                $canSendHeaders && header('Content-Type:application/xml; charset=utf-8');

                // Messages routinely carry request data, so every value is escaped
                $xmlCode = htmlspecialchars((string) $this->code, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xmlMessage = htmlspecialchars((string) $this->message, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xmlDescription = htmlspecialchars($description, ENT_XML1 | ENT_QUOTES, 'UTF-8');
                $xmlTrace = htmlspecialchars((string) $stackTrace, ENT_XML1 | ENT_QUOTES, 'UTF-8');

                echo <<<XML
<Msg xmlns:i="http://www.w3.org/2001/XMLSchema-instance">
    <Code>{$xmlCode}</Code>
    <Text>{$xmlMessage}</Text>
    <Description>{$xmlDescription}</Description>
    <Trace>{$xmlTrace}</Trace>
</Msg>
XML;
                break;

            case ErrorMessage::OUTPUT_TYPE_HTML:
                $canSendHeaders && header('Content-Type:text/html; charset=utf-8');

                if ($includeHtmlTemplate === true) {
                    echo $this->htmlPage();
                } else {
                    // Exception messages regularly contain request data, so escape before
                    // turning newlines into markup
                    $htmlCode = htmlspecialchars((string) $this->code, ENT_QUOTES, 'UTF-8');
                    $htmlMessage = htmlspecialchars((string) $this->message, ENT_QUOTES, 'UTF-8');
                    $htmlDescription = htmlspecialchars($description, ENT_QUOTES, 'UTF-8');
                    $htmlTrace = htmlspecialchars((string) $stackTrace, ENT_QUOTES, 'UTF-8');

                    $htmlDescription = nl2br($htmlDescription);
                    $htmlTrace = nl2br($htmlTrace);

                    echo "{$htmlCode} {$htmlMessage}<br /><br />{$htmlDescription}<br /><br />{$htmlTrace}";
                }
                break;
        }
    }


    // ##################
    // ### Converters ###
    // ##################

    public static function outputTypeFromRequestType(RequestContentType $requestType): string
    {
        switch ($requestType) {
            case RequestContentType::JSON:
                return self::OUTPUT_TYPE_JSON;
            case RequestContentType::XML:
                return self::OUTPUT_TYPE_XML;
            case RequestContentType::TEXT:
                return self::OUTPUT_TYPE_PLAIN;
            case RequestContentType::HTML:
                return self::OUTPUT_TYPE_HTML;
            case RequestContentType::FORM:
            case RequestContentType::MULTIPART:
            case RequestContentType::NONE:
            default:
                return self::OUTPUT_TYPE_HTML;
        }
    }

    public static function httpStatusCodeToMessage(int $httpStatusCode)
    {
        switch ($httpStatusCode) {
                // 2xx Success
            case 200:
                return 'OK';
            case 201:
                return 'Created';
            case 202:
                return 'Accepted';
            case 203:
                return 'Non-Authoritative Information';
            case 204:
                return 'No Content';
            case 205:
                return 'Reset Content';
            case 206:
                return 'Partial Content';

                // 3xx Redirection
            case 300:
                return 'Multiple Choices';
            case 301:
                return 'Moved Permanently';
            case 302:
                return 'Found';
            case 303:
                return 'See Other';
            case 304:
                return 'Not Modified';
            case 307:
                return 'Temporary Redirect';

                // 4xx Client Error
            case 400:
                return 'Bad Request';
            case 401:
                return 'Unauthorized';
            case 402:
                return 'Payment Required';
            case 403:
                return 'Forbidden';
            case 404:
                return 'Not Found';
            case 405:
                return 'Method Not Allowed';
            case 406:
                return 'Not Acceptable';
            case 407:
                return 'Proxy Authentication Required';
            case 408:
                return 'Request Timeout';
            case 409:
                return 'Conflict';
            case 410:
                return 'Gone';
            case 411:
                return 'Length Required';
            case 412:
                return 'Precondition Failed';
            case 413:
                return 'Request Entity Too Large';
            case 414:
                return 'Request-URI Too Long';

                // 5xx Server Error
            case 500:
                return 'Internal Server Error';
            case 501:
                return 'Not Implemented';
            case 502:
                return 'Bad Gateway';
            case 503:
                return 'Service Unavailable';
            case 504:
                return 'Gateway Timeout';
            case 505:
                return 'HTTP Version Not Supported';
            default:
                return 'Unknown Status Code';
        }
    }
}
