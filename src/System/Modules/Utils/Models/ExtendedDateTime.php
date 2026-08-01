<?php

namespace System\Modules\Utils\Models;

/**
 * Ads some helper clases to the PHP's \DateTime class
 */
class ExtendedDateTime extends \DateTime
{
    public static string | null $fullDateTimeFormat = null;
    public static string | null $dateTimeFormat = null;
    public static string | null $dateFormat = null;
    public static string | null $timeFormat = null;

    /**
     * Locale handed to ICU, overriding whatever setlocale() reports.
     *
     * i18n::init() sets this. Without it the locale comes from setlocale(LC_TIME, 0), which
     * stays "C" unless something set it - and the locale has to be generated in the
     * container for setlocale() to do anything at all, whereas ICU brings its own data.
     */
    public static string | null $defaultLocale = null;

    /**
     * Lazily built IntlDateFormatters, keyed by the accessor that owns them.
     *
     * Building one loads ICU's locale bundle, which costs roughly 700 microseconds for the
     * first and 45 for each subsequent - so eagerly building all four in the constructor
     * cost about 830 microseconds per instance whether or not anything formatted a date.
     * Most instances format at most one way, and many format none at all.
     *
     * @var \IntlDateFormatter[]
     */
    private array $formatters = [];

    private string $locale = '';
    private string $timeZoneString = '';

    // ##############
    // ### Create ###
    // ##############

    public function __construct(
        string $datetime = 'now',
        ?string $timeZoneString = null
    ) {
        if (empty($timeZoneString)) {
            $timeZoneString = date_default_timezone_get();
        }
        $timeZone = new \DateTimeZone($timeZoneString);

        $locale = self::$defaultLocale ?? setlocale(LC_TIME, 0);
        $this->locale = explode('.', (string) $locale)[0];
        $this->timeZoneString = $timeZoneString;

        try {
            parent::__construct($datetime, $timeZone);
        } catch (\Exception $e) {
            // Only this path needs a formatter at construction time
            $timestamp = $this->formatter('dateTime')->parse($datetime);
            parent::__construct("@{$timestamp}", $timeZone);
        }
    }

    /**
     * Get one of the four formatters, building it on first use.
     *
     * @access private
     * @param  string $which One of: fullDateTime, dateTime, date, time
     * @return \IntlDateFormatter
     */
    private function formatter(string $which): \IntlDateFormatter
    {
        if (isset($this->formatters[$which])) {
            return $this->formatters[$which];
        }

        [$dateType, $timeType, $pattern] = match ($which) {
            'fullDateTime' => [\IntlDateFormatter::FULL, \IntlDateFormatter::FULL, self::$fullDateTimeFormat],
            'dateTime' => [\IntlDateFormatter::SHORT, \IntlDateFormatter::SHORT, self::$dateTimeFormat],
            'date' => [\IntlDateFormatter::SHORT, \IntlDateFormatter::NONE, self::$dateFormat],
            'time' => [\IntlDateFormatter::NONE, \IntlDateFormatter::SHORT, self::$timeFormat],
        };

        return $this->formatters[$which] = new \IntlDateFormatter(
            $this->locale,
            $dateType,
            $timeType,
            $this->timeZoneString,
            null,
            $pattern
        );
    }

    public function previousMonth()
    {
        $this->modify('last day of -1 month');
    }

    public function nextMonth()
    {
        $this->modify('first day of +1 month');
    }

    public function startOfTheMonth()
    {
        $this->modify('first day of this month 00:00:00');
    }

    public function endOfTheMonth()
    {
        $this->modify('last day of this month 23:59:59');
    }

    public function startOfTheWeek()
    {
        $this->modify('this week 00:00:00');
    }

    public function endOfTheWeek()
    {
        $this->modify('sunday this week 23:59:59');
    }

    public function startOfTheDay()
    {
        $this->modify('00:00:00');
    }

    public function endOfTheDay()
    {
        $this->modify('23:59:59');
    }

    public static function startOfTheMonthFromTimestamp(int $unixTime)
    {
        $tmp = new ExtendedDateTime("@{$unixTime}");
        $tmp->startOfTheMonth();

        return $tmp->getTimestamp();
    }

    public static function endOfTheMonthFromTimestamp(int $unixTime)
    {
        $tmp = new ExtendedDateTime("@{$unixTime}");
        $tmp->endOfTheMonth();

        return $tmp->getTimestamp();
    }


    // ##############
    // ### Format ###
    // ##############

    public function formatFullDateTime(): string
    {
        return $this->formatter('fullDateTime')->format($this);
    }

    public function formatDateTime(): string
    {
        return $this->formatter('dateTime')->format($this);
    }

    public function formatDate(): string
    {
        return $this->formatter('date')->format($this);
    }

    public function formatTime(): string
    {
        return $this->formatter('time')->format($this);
    }
}
