<?php

class ValidationService
{
    public static function required($value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    public static function email($email): bool
    {
        return is_string($email) && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    public static function date($date, string $format = 'Y-m-d'): bool
    {
        if (!is_string($date) || $date === '') return false;
        $parsed = DateTimeImmutable::createFromFormat('!' . $format, $date);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && $parsed->format($format) === $date
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    public static function dateTime($value, string $format): bool
    {
        if (!is_string($value) || $value === '') return false;
        $parsed = DateTimeImmutable::createFromFormat($format, $value);
        $errors = DateTimeImmutable::getLastErrors();
        return $parsed !== false
            && $parsed->format($format) === $value
            && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    public static function maxLength($value, int $length): bool
    {
        return is_string($value) && mb_strlen($value, 'UTF-8') <= $length;
    }

    public static function name(string $value, int $maxLength = 100, bool $required = true): bool
    {
        $value = trim($value);
        if ($value === '') return !$required;
        return self::maxLength($value, $maxLength)
            && preg_match("/^[\\p{L}\\p{M}]+(?:[ '-][\\p{L}\\p{M}]+)*$/u", $value) === 1;
    }

    public static function address(string $value): bool
    {
        return preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $value) !== 1;
    }

    public static function text(string $value, bool $allowLineBreaks = false): bool
    {
        $pattern = $allowLineBreaks
            ? '/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/'
            : '/[\\x00-\\x1F\\x7F]/';
        return preg_match($pattern, $value) !== 1;
    }

    public static function phone(string $value): bool
    {
        $value = trim($value);
        if ($value === '') return true;
        if (preg_match('/^\\+?[0-9 .()\\-]+$/', $value) !== 1) return false;
        $digits = preg_replace('/\\D/', '', $value);
        if (str_starts_with($value, '+')) {
            if (!str_starts_with($digits, '63')) return false;
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '63')) {
            $digits = substr($digits, 2);
        } elseif (str_starts_with($digits, '0')) {
            $digits = substr($digits, 1);
        }

        // Philippine mobile numbers (09xxxxxxxxx or +639xxxxxxxxx).
        if (preg_match('/^9[0-9]{9}$/', $digits) === 1) return true;
        // Philippine landlines: Metro Manila (02 + 8 digits) or provincial
        // area code (0 + 2 digits) with a 7- or 8-digit subscriber number.
        return preg_match('/^(?:2[0-9]{8}|[3-8][0-9]{8,9})$/', $digits) === 1;
    }

    public static function cleanText($value): string
    {
        return trim(is_scalar($value) ? (string) $value : '');
    }

    public static function residentData(array $input): array
    {
        $first = self::cleanText($input['first_name'] ?? null);
        $middle = self::cleanText($input['middle_name'] ?? null);
        $last = self::cleanText($input['last_name'] ?? null);
        $birthDate = self::cleanText($input['birth_date'] ?? null);
        $gender = self::cleanText($input['gender'] ?? null);
        $civilStatus = self::cleanText($input['civil_status'] ?? null);
        $contact = self::cleanText($input['contact_no'] ?? null);
        $email = self::cleanText($input['email'] ?? null);
        $address = self::cleanText($input['address'] ?? null);
        $purok = self::cleanText($input['purok'] ?? null);
        $tenant = self::cleanText($input['is_tenant'] ?? null);

        if (!self::name($first)) return ['success' => false, 'message' => 'Please enter a valid first name (up to 100 characters).'];
        if (!self::name($last)) return ['success' => false, 'message' => 'Please enter a valid last name (up to 100 characters).'];
        if (!self::name($middle, 100, false)) return ['success' => false, 'message' => 'Please enter a valid middle name (up to 100 characters).'];
        if ($birthDate !== '' && (!self::date($birthDate) || $birthDate > date('Y-m-d'))) return ['success' => false, 'message' => 'Please enter a real birth date that is not in the future.'];
        if ($gender !== '' && !in_array($gender, ['Male', 'Female'], true)) return ['success' => false, 'message' => 'Please select a valid gender.'];
        if ($civilStatus !== '' && !in_array($civilStatus, ['Single', 'Married', 'Widowed', 'Separated'], true)) return ['success' => false, 'message' => 'Please select a valid civil status.'];
        if (!self::phone($contact) || mb_strlen($contact) > 20) return ['success' => false, 'message' => 'Please enter a valid Philippine telephone number (up to 20 characters).'];
        if ($email !== '' && (mb_strlen($email) > 150 || !self::email($email))) return ['success' => false, 'message' => 'Please enter a valid email address (up to 150 characters).'];
        if (!self::address($address)) return ['success' => false, 'message' => 'Please remove unsupported control characters from the address.'];
        if (mb_strlen($purok) > 100 || !self::address($purok)) return ['success' => false, 'message' => 'Please enter a valid purok (up to 100 characters).'];
        if (!in_array($tenant, ['0', '1'], true)) return ['success' => false, 'message' => 'Please select a valid tenant status.'];

        return ['success' => true, 'data' => [
            'first_name' => $first,
            'middle_name' => $middle === '' ? null : $middle,
            'last_name' => $last,
            'birth_date' => $birthDate === '' ? null : $birthDate,
            'gender' => $gender === '' ? null : $gender,
            'civil_status' => $civilStatus === '' ? null : $civilStatus,
            'contact_no' => $contact === '' ? null : $contact,
            'email' => $email === '' ? null : $email,
            'address' => $address === '' ? null : $address,
            'purok' => $purok === '' ? null : $purok,
            'is_tenant' => (int) $tenant,
        ]];
    }
}
