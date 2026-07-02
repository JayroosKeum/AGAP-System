<?php

class ValidationService
{
    public static function required($value)
    {
        return isset($value)
            && trim($value) !== '';
    }

    public static function email($email)
    {
        return filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        );
    }

    public static function date($date)
    {
        return strtotime($date) !== false;
    }

    public static function maxLength(
        $value,
        $length
    )
    {
        return strlen($value) <= $length;
    }
}