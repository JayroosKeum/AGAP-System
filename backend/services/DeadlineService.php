<?php

class DeadlineService
{
    public static function mediationEnd()
    {
        return date(
            'Y-m-d',
            strtotime('+15 days')
        );
    }

    public static function conciliationEnd()
    {
        return date(
            'Y-m-d',
            strtotime('+15 days')
        );
    }

    public static function conciliationExtension()
    {
        return date(
            'Y-m-d',
            strtotime('+30 days')
        );
    }
}