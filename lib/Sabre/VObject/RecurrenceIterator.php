<?php

/**
 * This class is used to determine new for a recurring event, when the next 
 * events occur.
 *
 * This iterator may loop infinitely in the future, therefore it is important 
 * that if you use this class, you set hard limits for the amount of iterations 
 * you want to handle.
 *
 * Note that currently there is not full support for the entire iCalendar 
 * specification, as it's very complex and contains a lot of permutations 
 * that's not yet used very often in software. 
 *
 * For the focus has been on features as they actually appear in Calendaring 
 * software, but this may well get expanded as needed / on demand
 *
 * The following RRULE properties are supported
 *   * UNTIL
 *   * INTERVAL
 *   * FREQ=DAILY
 *   * FREQ=WEEKLY
 *     * BYDAY
 *     * WKST
 *   * FREQ=MONTHLY
 *     * BYMONTHDAY 
 *     * BYDAY
 *     * BYSETPOS
 *   * FREQ=YEARLY
 *     * BYMONTH
 *     * BYDAY
 *
 * Anything beyond this is simply ignored as if it wasn't specified. The effect 
 * is that in some applications the specified recurrence may look incorrect, or 
 * is missing. 
 * 
 * @package Sabre
 * @subpackage VObject
 * @copyright Copyright (C) 2007-2011 Rooftop Solutions. All rights reserved.
 * @author Evert Pot (http://www.rooftopsolutions.nl/) 
 * @license http://code.google.com/p/sabredav/wiki/License Modified BSD License
 */
class Sabre_VObject_RecurrenceIterator implements Iterator {

    /**
     * The initial event
     * 
     * @var DateTime 
     */
    public $startDate;

    /**
     * The 'current' recurrence.
     *
     * This will be increased for every iteration.
     * 
     * @var DateTime 
     */
    public $currentDate;

    /**
     * Frequency is one of: secondly, minutely, hourly, daily, weekly, monthly, 
     * yearly. 
     * 
     * @var string 
     */
    public $frequency;

    /**
     * The last instance of this recurrence, inclusively 
     * 
     * @var DateTime|null 
     */
    public $until;

    /**
     * The number of recurrences, or 'null' if infinitely recurring. 
     * 
     * @var int 
     */
    public $count;

    /**
     * The interval.
     *
     * If for example frequency is set to daily, interval = 2 would mean every 
     * 2 days.
     *
     * @var int
     */
    public $interval = 1;

    /**
     * Which seconds to recur.
     *
     * This is an array of integers (between 0 and 60)
     *
     * @var array 
     */
    public $bySecond; 

    /**
     * Which minutes to recur
     *
     * This is an array of integers (between 0 and 59)
     *
     * @var array 
     */
    public $byMinute;

    /**
     * Which hours to recur
     *
     * This is an array of integers (between 0 and 23)
     *
     * @var array 
     */
    public $byHour;

    /**
     * Which weekdays to recur.
     *
     * This is an array of weekdays
     *
     * This may also be preceeded by a postive or negative integer. If present, 
     * this indicates the nth occurence of a specific day within the monthly or 
     * yearly rrule. For instance, -2TU indicates the second-last tuesday of 
     * the month, or year.
     *
     * @var array
     */
    public $byDay;

    /**
     * Which days of the month to recur
     *
     * This is an array of days of the months (1-31). The value can also be 
     * negative. -5 for instance means the 5th last day of the month.
     * 
     * @var array 
     */
    public $byMonthDay;

    /**
     * Which days of the year to recur.
     *
     * This is an array with days of the year (1 to 366). The values can also 
     * be negative. For instance, -1 will always represent the last day of the 
     * year. (December 31st).
     *
     * @var array 
     */
    public $byYearDay;

    /**
     * Which week numbers to recur.
     *
     * This is an array of integers from 1 to 53. The values can also be 
     * negative. -1 will always refer to the last week of the year. 
     * 
     * @var array 
     */
    public $byWeekNo;

    /**
     * Which months to recur
     *
     * This is an array of integers from 1 to 12.
     *
     * @var array 
     */
    public $byMonth;

    /**
     * Which items in an existing st to recur.
     *
     * These numbers work together with an existing by* rule. It specifies 
     * exactly which items of the existing by-rule to filter.
     *
     * Valid values are 1 to 366 and -1 to -366. As an example, this can be 
     * used to recur the last workday of the month.
     *
     * This would be done by setting frequency to 'monthly', byDay to 
     * 'MO,TU,WE,TH,FR' and bySetPos to -1. 
     * 
     * @var array 
     */
    public $bySetPos;

    /**
     * When a week starts
     *
     * @var string 
     */
    public $weekStart = 'MO';

    /**
     * The current item in the list 
     * 
     * @var int 
     */
    public $counter = 0;

    /**
     * Simple mapping from iCalendar day names to day numbers
     * 
     * @var array
     */
    private $dayMap = array(
        'SU' => 0,
        'MO' => 1,
        'TU' => 2,
        'WE' => 3,
        'TH' => 4,
        'FR' => 5,
        'SA' => 6,
    );

    /**
     * Mappings between the day number and english day name.
     *
     * @var array
     */
    private $dayNames = array(
        0 => 'Sunday',
        1 => 'Monday',
        2 => 'Tuesday',
        3 => 'Wednesday',
        4 => 'Thursday',
        5 => 'Friday',
        6 => 'Saturday',
    ); 

    
    /**
     * Creates the iterator
     *
     * A VEVENT component typically needs to be passed.
     * 
     * @param Sabre_VObject_Component $comp 
     */
    public function __construct(Sabre_VObject_Component $comp) {

        $this->startDate = $comp->DTSTART->getDateTime();
        $this->currentDate = $this->startDate;

        $rrule = (string)$comp->RRULE;

        $parts = explode(';', $rrule);

        foreach($parts as $part) {
            
            list($key, $value) = explode('=', $part, 2);
            
            switch(strtoupper($key)) {

                case 'FREQ' :
                    if (!in_array(
                        strtolower($value),
                        array('secondly','minutely','hourly','daily','weekly','monthly','yearly')
                    )) {
                        throw new InvalidArgumentException('Unknown value for FREQ=' . strtoupper($value));

                    }
                    $this->frequency = strtolower($value);
                    break;

                case 'UNTIL' :
                    $this->until = Sabre_VObject_DateTimeParser::parse($value);
                    break;

                case 'COUNT' :
                    $this->count = (int)$value;
                    break;

                case 'INTERVAL' :
                    $this->interval = (int)$value;
                    break;

                case 'BYSECOND' :
                    $this->bySecond = explode(',', $value);
                    break;

                case 'BYMINUTE' :
                    $this->byMinute = explode(',', $value);
                    break;

                case 'BYHOUR' :
                    $this->byHour = explode(',', $value);
                    break;
                
                case 'BYDAY' :
                    $this->byDay = explode(',', strtoupper($value));
                    break;

                case 'BYMONTHDAY' :
                    $this->byMonthDay = explode(',', $value);
                    break;

                case 'BYYEARDAY' :
                    $this->byYearDay = explode(',', $value);
                    break;

                case 'BYWEEKNO' :
                    $this->byWeekNo = explode(',', $value);
                    break;

                case 'BYMONTH' :
                    $this->byMonth = explode(',', $value);
                    break;

                case 'BYSETPOS' :
                    $this->bySetPos = explode(',', $value);
                    break;

                case 'WKST' :
                    $this->weekStart = strtoupper($value);
                    break;

            }

        }

    } 

    /**
     * Returns the current item in the list
     * 
     * @return DateTime 
     */
    public function current() {

        return clone $this->currentDate;

    }

    /**
     * Returns the current item number
     * 
     * @return int 
     */
    public function key() {

        return $this->counter;

    }

    /**
     * Wether or not there is a 'next item'
     *
     * @return bool 
     */
    public function valid() {

        if (!is_null($this->count)) {
            return $this->counter < $this->count;
        }
        if (!is_null($this->until)) {
            return $this->currentDate <= $this->until;
        }
        return true;

    }

    /**
     * Resets the iterator 
     * 
     * @return void
     */
    public function rewind() {

        $this->currentDate = clone $this->startDate;
        $this->counter = 0;

    }

    /**
     * Goes on to the next iteration 
     * 
     * @return void 
     */
    public function next() {


        switch($this->frequency) {

            case 'daily' :
                $this->currentDate->modify('+' . $this->interval . ' days');
                break;

            case 'weekly' :
                if (!$this->byDay) {
                    $this->currentDate->modify('+' . $this->interval . ' weeks');
                    break;
                }

                $recurrenceDays = array();
                foreach($this->byDay as $byDay) {

                    // The day may be preceeded with a positive (+n) or 
                    // negative (-n) integer. However, this does not make 
                    // sense in 'weekly' so we ignore it here.
                    $recurrenceDays[] = $this->dayMap[substr($byDay,-2)];

                }

                // Current day of the week
                $currentDay = $this->currentDate->format('w');

                // First day of the week:
                $firstDay = $this->dayMap[$this->weekStart];

                // Increasing the 'current day' until we find our next 
                // occurence.
                while(true) {

                    $currentDay++;

                    if ($currentDay>6) {
                        $currentDay = 0;
                    }

                    // We need to roll over to the next week
                    if ($currentDay === $firstDay) {
                        $this->currentDate->modify('+' . $this->interval . ' weeks');
                        $this->currentDate->modify('last ' . $this->dayNames[$this->dayMap[$this->weekStart]]);
                    }

                    // We have a match
                    if (in_array($currentDay ,$recurrenceDays)) {
                        $this->currentDate->modify($this->dayNames[$currentDay]);
                        break; 
                    }

                }
                break;

            case 'monthly' :

                $currentDayOfMonth = $this->currentDate->format('j');
                if (!$this->byMonthDay && !$this->byDay) {

                    // If the current day is higher than the 28th, rollover can 
                    // occur to the next month. We Must skip these invalid 
                    // entries.
                    if ($currentDayOfMonth < 29) {
                        $this->currentDate->modify('+' . $this->interval . ' months');
                    } else {
                        $increase = 0;
                        do {
                            $increase++;
                            $tempDate = clone $this->currentDate;
                            $tempDate->modify('+ ' . ($this->interval*$increase) . ' months');
                        } while ($tempDate->format('j') != $currentDayOfMonth);
                        $this->currentDate = $tempDate;
                    }
                    break;
                }

                while(true) {

                    $occurences = $this->getMonthlyOccurences();

                    foreach($occurences as $k=>$occurence) {

                        // The first occurence thats higher than the current 
                        // day of the month wins.
                        if ($occurence > $currentDayOfMonth) {
                            break 2;
                        }

                    }
                    
                    // If we made it all the way here, it means there were no 
                    // valid occurences, and we need to advance to the next 
                    // month.
                    $this->currentDate->modify('first day of this month');
                    $this->currentDate->modify('+ ' . $this->interval . ' months');

                    // This goes to 0 because we need to start counting at hte 
                    // beginning.
                    $currentDayOfMonth = 0; 

                }

                $this->currentDate->setDate($this->currentDate->format('Y'), $this->currentDate->format('n'), $occurence);
                break;

            case 'yearly' :


        }
        $this->counter++;

    }

    /**
     * Returns all the occurences for a monthly frequency with a 'byDay' 
     * expansion for the current month.
     *
     * The returned list is an array of integers with the day of month (1-31).
     * 
     * @return array 
     */
    protected function getMonthlyOccurences() {

        $startDate = clone $this->currentDate;

        $current = 1;

        $byDayResults = array();

        // Our strategy is to simply go through the byDays, advance the date to 
        // that point and add it to the results.
        if ($this->byDay) foreach($this->byDay as $day) {

            $dayName = $this->dayNames[$this->dayMap[substr($day,-2)]];

            // Dayname will be something like 'wednesday'. Now we need to find 
            // all wednesdays in this month.
            $dayHits = array();

            $checkDate = clone $startDate;
            $checkDate->modify('first day of this month');
            $checkDate->modify($dayName);
            
            do {
                $dayHits[] = $checkDate->format('j');
                $checkDate->modify('next ' . $dayName);
            } while ($checkDate->format('n') === $startDate->format('n'));

            // So now we have 'all wednesdays' for month. It is however 
            // possible that the user only really wanted the 1st, 2nd or last 
            // wednesday.
            if (strlen($day)>2) {
                $offset = (int)substr($day,0,-2);
                
                if ($offset>0) {
                    $byDayResults[] = $dayHits[$offset-1];
                } else {

                    // if it was negative we count from the end of the array
                    $byDayResults[] = $dayHits[count($dayHits) + $offset];
                }
            } else {
                // There was no counter (first, second, last wednesdays), so we 
                // just need to add the all to the list).
                $byDayResults = array_merge($byDayResults, $dayHits);

            }

        }

        $byMonthDayResults = array();
        if ($this->byMonthDay) foreach($this->byMonthDay as $monthDay) {

            // Removing values that are out of range for this month
            if ($monthDay > $startDate->format('t') || 
                $monthDay < 0-$startDate->format('t')) {
                    continue;
            }
            if ($monthDay>0) {
                $byMonthDayResults[] = $monthDay;
            } else {
                // Negative values
                $byMonthDayResults[] = $startDate->format('t') + 1 + $monthDay;
            }
        } 

        // If there was just byDay or just byMonthDay, they just specify our 
        // (almost) final list. If both were provided, then byDay limits the 
        // list.
        if ($this->byMonthDay && $this->byDay) {
            $result = array_intersect($byMonthDayResults, $byDayResults);
        } elseif ($this->byMonthDay) {
            $result = $byMonthDayResults;
        } else {
            $result = $byDayResults;
        }
        $result = array_unique($result);
        sort($result, SORT_NUMERIC);

        // The last thing that needs checking is the BYSETPOS. If it's set, it 
        // means only certain items in the set survive the filter.
        if (!$this->bySetPos) {
            return $result;
        } 

        $filteredResult = array();
        foreach($this->bySetPos as $setPos) {

            if ($setPos<0) {
                $setPos = count($result)-$setPos;
            }
            if (isset($result[$setPos])) {
                $filteredResult[] = $result[$setPos];
            }
        }

        sort($filteredResult, SORT_NUMERIC);
        return $filteredResult;

    } 


}
