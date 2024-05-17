<?php

namespace System\Modules\Utils\Models;

/**
 * Ads some helper clases to the PHP's \DateTime class
 */
class ExtendedDateTime extends \DateTime
{
    private \IntlDateFormatter $fullDateTimeFormatter;
    private \IntlDateFormatter $dateTimeFormatter;
    private \IntlDateFormatter $dateFormatter;
    private \IntlDateFormatter $timeFormatter;

    // ##############
    // ### Create ###
    // ##############

    public function __construct(string $datetime = 'now', string $timeZoneString = null)
    {
        if (empty($timeZoneString)) {
            $timeZoneString = date_default_timezone_get();
        }
        $timeZone = new \DateTimeZone($timeZoneString);
        $locale = setlocale(LC_TIME, 0);
        $locale = explode('.', $locale)[0];

        parent::__construct($datetime, $timeZone);

        $this->fullDateTimeFormatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::FULL,
            \IntlDateFormatter::FULL,
            $timeZoneString
        );
        $this->dateTimeFormatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::SHORT,
            \IntlDateFormatter::SHORT,
            $timeZoneString
        );
        $this->dateFormatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::SHORT,
            \IntlDateFormatter::NONE,
            $timeZoneString
        );
        $this->timeFormatter = new \IntlDateFormatter(
            $locale,
            \IntlDateFormatter::NONE,
            \IntlDateFormatter::SHORT,
            $timeZoneString
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
        return $this->fullDateTimeFormatter->format($this);
    }

    public function formatDateTime(): string
    {
        return $this->dateTimeFormatter->format($this);
    }

    public function formatDate(): string
    {
        return $this->dateFormatter->format($this);
    }

    public function formatTime(): string
    {
        return $this->timeFormatter->format($this);
    }
}
