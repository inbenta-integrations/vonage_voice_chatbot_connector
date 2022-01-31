<?php

/**
 * DON'T MODIFY THIS FILE!!! READ "conf/README.md" BEFORE.
 */

// Escalation configuration
return [
    'chat' => [
        'enabled' => true,
        'phoneNumber' => '', //Escalation phone number
        'workTimeTableActive' => false, // if set to FALSE then transfer number is 24/7, if TRUE then we get the working hours timetable
        'timetable' => [
            'monday'     => ['09:00-18:00'], //It can be this way: ['09:00-18:00', '20:00-23:00']
            'tuesday'    => ['09:00-18:00'],
            'wednesday'  => ['09:00-18:00'],
            'thursday'   => ['09:00-18:00'],
            'friday'     => ['09:00-18:00'],
            'saturday'   => ['09:00-18:00'],
            'sunday'     => [],
            'exceptions' => [
                //'2021-06-19' => [], // not working that day
                //'2021-06-15' => ['9:00-12:00'] // no matter which day of week is, that day agents only works from 9 to 12
            ]
        ],
        'timezoneWorkingHours' => 'America/New_York'
    ],
    'triesBeforeEscalation' => 3,
    'negativeRatingsBeforeEscalation' => 0
];
