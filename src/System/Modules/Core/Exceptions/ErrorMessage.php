<?php

namespace System\Modules\Core\Exceptions;

use Throwable;
use System\Modules\Core\Interfaces\RequestContentType;
use System\Modules\Core\Models\Load;
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

    public const OUTPUT_TYPE_PLAIN = 'plain';
    public const OUTPUT_TYPE_HTML = 'html';
    public const OUTPUT_TYPE_JSON = 'json';
    public const OUTPUT_TYPE_XML = 'xml';

    public function __construct(
        string $message = '',
        int $code = 0,
        ?string $description = null,
        ?Throwable $previous = null,
        int $httpStatusCode = 200,
        ?string $httpStatusMessage = null,
        ?string $forceOutputType = null,
        bool $showStackTrace = false
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

    private function getClass(): string
    {
        if ($this->httpStatusCode >= 500 && $this->httpStatusCode < 600) {
            return "e{$this->httpStatusCode} e500";
        } elseif ($this->httpStatusCode >= 400 && $this->httpStatusCode < 500) {
            return "e{$this->httpStatusCode} e400";
        } elseif ($this->httpStatusCode >= 300 && $this->httpStatusCode < 400) {
            return "e{$this->httpStatusCode} e400";
        } else {
            return "e{$this->httpStatusCode}";
        }
    }

    public function outputMessage($outputType = ErrorMessage::OUTPUT_TYPE_HTML, $includeHtmlTemplate = false)
    {
        // Set HTTP status code
        if (headers_sent() == false && $this->httpStatusCode != 200) {
            header("HTTP/1.0 {$this->httpStatusCode} {$this->httpStatusMessage}");
        }

        // Gather stack trace
        $stackTrace = '';
        if (Config::get('debug', false) === true && $this->showStackTrace === true) {
            $stackTrace = $this->gatherTrace();
        }

        // Force output type
        if (!empty($this->forceOutputType)) {
            $outputType = $this->forceOutputType;
        }

        // Output message
        switch ($outputType) {
            case ErrorMessage::OUTPUT_TYPE_PLAIN:
                header('Content-Type:text/plain; charset=utf-8');
                echo "{$this->code} {$this->message}\n\n{$this->description}\n\n{$stackTrace}";
                break;


            case ErrorMessage::OUTPUT_TYPE_JSON:
                header('Content-Type:application/json; charset=utf-8');
                echo json_encode([
                    'msg' => [
                        'code' => $this->code,
                        'text' => $this->message,
                        'description' => trim("{$this->description}\n{$stackTrace}", "\n"),
                    ],
                ]);
                break;

            case ErrorMessage::OUTPUT_TYPE_XML:
                header('Content-Type:application/xml; charset=utf-8');
                echo <<<XML
<Msg xmlns:i="http://www.w3.org/2001/XMLSchema-instance">
    <Code>{$this->code}</Code>
    <Text>{$this->message}</Text>
    <Description>{$this->description}</Description>
    <Trace>{$stackTrace}</Trace>
</Msg>
XML;
                break;

            case ErrorMessage::OUTPUT_TYPE_HTML:
                header('Content-Type:text/html; charset=utf-8');

                if ($includeHtmlTemplate === true) {
                    if (!empty($this->description)) {
                        $this->description = str_replace("\n", '<br />', $this->description);
                    }
                    $data = [
                        'http_status_code' => $this->httpStatusCode,
                        'http_status_message' => $this->httpStatusMessage,

                        'code' => $this->code,
                        'message' => $this->message,
                        'description' => $this->description,
                        'stack_trace' => $stackTrace,

                        'error_class' => $this->getClass(),
                    ];
                    Config::$items['view_engine']->setCache(false);
                    Load::view(["Error.html"], $data);
                } else {
                    $stackTrace = str_replace("\n", '<br />', $stackTrace);
                    echo "{$this->code} {$this->message}<br /><br />{$this->description}<br /><br />{$stackTrace}";
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
