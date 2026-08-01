<?php

/**
 * Returns fixed floating number with precision of $precision. Replaces "," to "." and " " to "".
 *
 * @param mixed $input
 * @param int $precision
 * @return float
 */
function fixFloat($input, $precision = -1)
{
    $input = str_replace([',', ' '], ['.', ''], $input);

    return ($precision === -1 ? (float)$input : round((float)$input, $precision));
}


/**
 * Trim characters, can be used with array_walk
 *
 * @param mixed $value
 * @param string $character_mask Characters to trim
 * @return mixed
 */
function trimChars(&$value, $character_mask = " \t\n\r\0\x0B")
{
    /*
        Unicode variant:
            $value = preg_replace('/^['.$character_mask.']*(?U)(.*)['.$character_mask.']*$/u', '\\1', $value);
    */
    $value = trim($value, $character_mask);
}


/**
 * Locale specific number_format
 *
 * Prefers the active i18n locale over localeconv(). localeconv() reads LC_NUMERIC, which
 * nothing in the framework sets and which does nothing at all unless the locale has been
 * generated in the container - so on a stock image this formatted every number the C way
 * while the rest of the page was in Latvian.
 *
 * @param int|float $number
 * @param int $decimals Precision
 * @return string
 */
function localeNumberFormat($number, $decimals = 2)
{
    if (\System\Modules\Utils\Models\i18n::isInitialised() === true) {
        return \System\Modules\Utils\Models\i18n::number($number ?? 0, $decimals);
    }

    $locale = localeconv();
    return number_format($number ?? 0, $decimals, $locale['decimal_point'], $locale['thousands_sep']);
}


/**
 * Returns unique uuid4 value
 *
 * @return string
 */
function uuid4()
{
    $data = openssl_random_pseudo_bytes(16);

    $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version to 0100
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10

    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}


/**
 * Parse query string using $delimiter
 *
 * @param string $str Query string
 * @param string $delimiter Delimiter
 * @return array
 */
function parseQueryString($str, $delimiter = '&')
{
    $op = [];
    $pairs = explode($delimiter, $str);
    foreach ($pairs as $pair) {
        $ex = explode("=", $pair);
        if (count($ex) < 2) {
            continue;
        }
        list($k, $v) = array_map("urldecode", $ex);
        $op[$k] = $v;
    }

    return $op;
}


/**
 * Returns array containing week's start and week's end
 *
 * @param int $week A Week
 * @param int $year of a Year
 * @return array
 */
function weekRange($week, $year = null)
{
    if (empty($year)) {
        $year = date('Y');
    }

    $week -= 1;
    $ts = strtotime("{$year}0104 +{$week} weeks"); // By http://en.wikipedia.org/wiki/ISO_8601#Week_dates
    $start = (date('w', $ts) == 1) ? $ts : strtotime('last monday', $ts);

    return [$start, strtotime('next sunday', $start)];
}


/**
 * Returns array containing month's start and end timestamps
 *
 * @param int $timestamp A timestamp for which date to calculate first and last day
 * @return array
 */
function monthRangeDateTime($timestamp = null)
{
    if (empty($timestamp)) {
        $timestamp = new \DateTime("now", new \DateTimeZone('UTC'));
    }

    if (is_int($timestamp)) {
        $timestamp = new \DateTime("@{$timestamp}");
    }

    $start = clone $timestamp;
    $start->modify('first day of this month');
    $start->setTime(00, 00, 00);

    $end = clone $timestamp;
    $end->modify('last day of this month');
    $end->setTime(23, 59, 59);

    return [$start, $end];
}


/**
 * Returns how many weeks there will be or was in a specific year.
 *
 * @param int $year A year
 */
function getIsoWeeksInYear($year)
{
    $date = new DateTime();
    $date->setISODate($year, 53);
    return ($date->format("W") === "53" ? 53 : 52);
}


/**
 * Returns array containing only values with their keys whose keys are in $keys parameter.
 * Also can return false if $required is specified and any of $keys are missing.
 * Also can fill missing keys with $fill_missing, if its other than false
 *
 * @param array $array Original array
 * @param array $keys Array of keys
 * @param bool $required Whether return false if there are missing keys
 * @param bool|mixed $fill_missing Fill missing keys with this value
 * @return array|bool
 */
function extractArrayByKeys($array, $keys, $required = false, $fill_missing = false)
{
    // Check if input is an array
    if (is_array($array) == false) {
        return false;
    }

    // Build new array
    $new_array = [];
    foreach ($keys as $key) {
        if (isset($array[$key])) {
            $new_array[$key] = $array[$key];
        } elseif ($required === true) {
            return false;
        } elseif ($fill_missing !== false) {
            $new_array[$key] = $fill_missing;
        }
    }

    // Return new array
    return $new_array;
}


/**
 * Returns a boolean value representing whether any of the passed array elements are empty
 *
 * @param array $array
 * @return boolean
 */
function anyEmpty($array)
{
    return (count($array) !== count(array_filter($array)));
}


/**
 * Returns a boolean value representing whether all of the passed array elements are empty
 *
 * @param array $array
 * @return boolean
 */
function allEmpty($array)
{
    return (count(array_filter($array)) === 0);
}


/**
 * Returns string pointing to a (somehow) random temporary filename
 *
 * @param string $prefix
 * @param string $postfix
 * @return string
 */
function tmpFilename($prefix = 'tmp_', $postfix = '')
{
    // uniqid(rand(), true) is predictable, and returning a name without creating the file
    // leaves a window for another process to place a symlink there first. tempnam creates
    // the file atomically with 0600 permissions.
    $filename = tempnam(sys_get_temp_dir(), $prefix);
    if ($filename === false) {
        throw new \RuntimeException('Could not create a temporary file');
    }

    if (empty($postfix)) {
        return $filename;
    }

    // A suffix means a second, still exclusive, create - O_EXCL fails if the name is taken
    $target = $filename . $postfix;
    $handle = @fopen($target, 'x');
    if ($handle === false) {
        unlink($filename);
        throw new \RuntimeException("Could not create a temporary file: {$target}");
    }

    fclose($handle);
    chmod($target, 0600);
    unlink($filename);

    return $target;
}


/**
 * Group $array by $keys.
 * When $keys == ['id', 'name'], Turns [['id' => 1, 'name' => 'Name 1'], ['id' => 2, 'name' => 'Name 2'] into
 * [1 => ['Name 1' => ['id' => 1, 'name' => 'Name 1']], 2 => ['Name 2' => ['id' => 2, 'name' => 'Name 2']]]
 *
 * @param  \Iterator $array
 * @param  mixed    $keys
 * @param  bool     $unique Describes whether last key of input array is unique
 * @return array[]  Returns array grouped by keys
 */
function groupArray($array, $keys = [], $unique = false)
{
    $keys = (array)$keys;
    $result = [];

    foreach ($array as $item) {
        $x = &$result;
        foreach ($keys as $key) {
            $x = &$x[$item[$key]];
        }

        if ($unique === true) {
            $x = $item;
        } else {
            $x[] = $item;
        }
    }

    return $result;
}


/**
 * Returns wheather date has a valid ISO8601 format.
 *
 * @param  string $date string
 * @return bool   Returns true or false
 */
function validISODate($date)
{
    return preg_match('/([0-9]{4})-([0-9]{2})-([0-9]{2})/', $date) == 1;
}


/**
 * Returns wheather datetime has a valid ISO8601 format.
 *
 * @param  string $datetime string
 * @return bool   Returns true or false
 */
function validISODateTime($datetime)
{
    return preg_match(
        '/^'
            . '(\d{4})-(\d{2})-(\d{2})T' // YYYY-MM-DDT ex: 2014-01-01T
            . '(\d{2}):(\d{2}):(\d{2})'  // HH-MM-SS  ex: 17:00:00
            . '(Z|((-|\+)\d{2}:\d{2}))'  // Z or +01:00 or -01:00
            . '$/',
        $datetime
    ) == 1;
}
