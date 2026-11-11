<?php

class ValidationService
{
    public static function required($value): bool
    {
        return is_scalar($value) && trim((string) $value) !== '';
    }

    public static function email($email): bool
    {
        if (!is_string($email)) {
            return false;
        }
        $email = trim($email);
        return $email !== ''
            && mb_strlen($email, 'UTF-8') <= 150
            && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
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

    public static function time($value): bool
    {
        if (!is_string($value)) {
            return false;
        }
        return preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', trim($value)) === 1;
    }

    public static function maxLength($value, int $length): bool
    {
        return is_string($value) && mb_strlen($value, 'UTF-8') <= $length;
    }

    public static function positiveInt($value, int $min = 1, int $max = PHP_INT_MAX): ?int
    {
        $parsed = filter_var($value, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => $min, 'max_range' => $max],
        ]);
        return $parsed === false ? null : (int) $parsed;
    }

    public static function decimal($value, float $min = 0.0, float $max = 999999999.99): ?float
    {
        if ($value === null || $value === '' || is_bool($value) || is_array($value)) {
            return null;
        }
        $normalized = is_string($value) ? str_replace([',', ' '], '', trim($value)) : $value;
        if (!is_numeric($normalized)) {
            return null;
        }
        $floatVal = (float) $normalized;
        if (!is_finite($floatVal) || $floatVal < $min || $floatVal > $max) {
            return null;
        }
        return $floatVal;
    }

    public static function username($value, int $min = 3, int $max = 100): bool
    {
        if (!is_string($value)) {
            return false;
        }
        $value = trim($value);
        $len = strlen($value);
        return $len >= $min
            && $len <= $max
            && preg_match('/^[A-Za-z0-9_.-]+$/', $value) === 1;
    }

    public static function passwordError(string $password, ?string $confirm = null, int $minLength = 12): ?string
    {
        if ($confirm !== null && !hash_equals($password, $confirm)) {
            return 'Password confirmation does not match.';
        }
        if (
            strlen($password) < $minLength
            || !preg_match('/[a-z]/', $password)
            || !preg_match('/[A-Z]/', $password)
            || !preg_match('/\d/', $password)
        ) {
            return "Password must be at least {$minLength} characters with uppercase, lowercase, and a number.";
        }
        return null;
    }

    public static function name(string $value, int $maxLength = 100, bool $required = true): bool
    {
        $value = trim($value);
        if ($value === '') return !$required;
        return self::maxLength($value, $maxLength)
            && preg_match("/^(?=.{1,{$maxLength}}$)[\\p{L}\\p{M}]+(?:\\.?[ '-][\\p{L}\\p{M}]+)*\\.?$/u", $value) === 1;
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

    public static function coordinates($lat, $lng, bool $withinMarikina = false): ?array
    {
        $latFloat = filter_var($lat, FILTER_VALIDATE_FLOAT);
        $lngFloat = filter_var($lng, FILTER_VALIDATE_FLOAT);
        if ($latFloat === false || $lngFloat === false || !is_finite($latFloat) || !is_finite($lngFloat)) {
            return null;
        }
        if ($latFloat < -90.0 || $latFloat > 90.0 || $lngFloat < -180.0 || $lngFloat > 180.0) {
            return null;
        }
        if ($withinMarikina && ($latFloat < 14.610 || $latFloat > 14.690 || $lngFloat < 121.075 || $lngFloat > 121.155)) {
            return null;
        }
        return ['lat' => $latFloat, 'lng' => $lngFloat];
    }

    public static function cleanText($value): string
    {
        return trim(is_scalar($value) ? (string) $value : '');
    }

    /**
     * Validates optional or provided party contact/identity fields (complainant/respondent).
     * Returns an error message string on failure, or null when valid.
     */
    public static function validatePartyPrefix(array $data, string $prefix, bool $allowEntityName = false): ?string
    {
        $label = ucfirst($prefix);
        $first = self::cleanText($data[$prefix . '_first_name'] ?? null);
        $middle = self::cleanText($data[$prefix . '_middle_name'] ?? null);
        $last = self::cleanText($data[$prefix . '_last_name'] ?? null);
        $suffix = self::cleanText($data[$prefix . '_suffix'] ?? null);
        $birthDate = self::cleanText($data[$prefix . '_birthdate'] ?? $data[$prefix . '_birth_date'] ?? null);
        $ageRaw = self::cleanText($data[$prefix . '_age'] ?? null);
        $gender = self::cleanText($data[$prefix . '_gender'] ?? null);
        $civil = self::cleanText($data[$prefix . '_civil_status'] ?? null);
        $occupation = self::cleanText($data[$prefix . '_occupation'] ?? null);
        $contact = self::cleanText($data[$prefix . '_contact_no'] ?? $data[$prefix . '_contact'] ?? null);
        $email = self::cleanText($data[$prefix . '_email'] ?? null);
        $address = self::cleanText($data[$prefix . '_address'] ?? null);
        $purok = self::cleanText($data[$prefix . '_purok'] ?? null);

        $validatePartyPart = static function (string $val, string $fieldLabel) use ($allowEntityName): ?string {
            if ($val === '' || $val === '-') {
                return null;
            }
            if ($allowEntityName) {
                if (!self::maxLength($val, 100) || !self::text($val)) {
                    return "Please enter a valid {$fieldLabel} (up to 100 characters).";
                }
                return null;
            }
            if (!self::name($val, 100, false)) {
                return "Please enter a valid {$fieldLabel} (letters only, up to 100 characters).";
            }
            return null;
        };

        if (($err = $validatePartyPart($first, "{$label} first name")) !== null) return $err;
        if (($err = $validatePartyPart($middle, "{$label} middle name")) !== null) return $err;
        if (($err = $validatePartyPart($last, "{$label} last name")) !== null) return $err;

        if ($suffix !== '' && (!self::maxLength($suffix, 20) || !self::text($suffix))) {
            return "{$label} suffix must be 20 characters or fewer.";
        }
        if ($birthDate !== '' && (!self::date($birthDate) || $birthDate > date('Y-m-d'))) {
            return "Please enter a valid {$label} birth date that is not in the future.";
        }
        if ($ageRaw !== '' && self::positiveInt($ageRaw, 0, 130) === null) {
            return "{$label} age must be a whole number between 0 and 130.";
        }
        if ($gender !== '' && !in_array($gender, ['Male', 'Female', 'Other'], true)) {
            return "Please select a valid {$label} gender.";
        }
        if ($civil !== '' && !in_array($civil, ['Single', 'Married', 'Widowed', 'Separated'], true)) {
            return "Please select a valid {$label} civil status.";
        }
        if ($occupation !== '' && (!self::maxLength($occupation, 100) || !self::text($occupation))) {
            return "{$label} occupation must be 100 characters or fewer.";
        }
        if ($contact !== '' && (mb_strlen($contact, 'UTF-8') > 20 || !self::phone($contact))) {
            return "Please enter a valid Philippine mobile or telephone number for {$label} (up to 20 characters).";
        }
        if ($email !== '' && !self::email($email)) {
            return "Please enter a valid {$label} email address (up to 150 characters).";
        }
        if (!self::maxLength($address, 255) || !self::address($address)) {
            return "Please enter a valid {$label} address (up to 255 characters without control characters).";
        }
        if (!self::maxLength($purok, 100) || !self::address($purok)) {
            return "Please enter a valid {$label} purok (up to 100 characters).";
        }

        $gName = self::cleanText($data[$prefix . '_guardian_name'] ?? null);
        $gRel = self::cleanText($data[$prefix . '_guardian_relationship'] ?? null);
        $gContact = self::cleanText($data[$prefix . '_guardian_contact'] ?? null);
        $gAddr = self::cleanText($data[$prefix . '_guardian_address'] ?? null);
        if ($gName !== '' && (!self::maxLength($gName, 150) || !self::name($gName, 150, false))) {
            return "Please enter a valid legal guardian name for {$label} (up to 150 characters).";
        }
        if ($gRel !== '' && (!self::maxLength($gRel, 100) || !self::text($gRel))) {
            return "{$label} guardian relationship must be 100 characters or fewer.";
        }
        if ($gContact !== '' && (mb_strlen($gContact, 'UTF-8') > 20 || !self::phone($gContact))) {
            return "Please enter a valid Philippine contact number for {$label}'s guardian.";
        }
        if ($gAddr !== '' && (!self::maxLength($gAddr, 255) || !self::address($gAddr))) {
            return "{$label} guardian address must be 255 characters or fewer without control characters.";
        }

        $lat = self::cleanText($data[$prefix . '_latitude'] ?? $data[$prefix . '_home_latitude'] ?? null);
        $lng = self::cleanText($data[$prefix . '_longitude'] ?? $data[$prefix . '_home_longitude'] ?? null);
        if (($lat !== '' || $lng !== '') && self::coordinates($lat, $lng, false) === null) {
            return "Invalid map coordinates provided for {$label}.";
        }

        return null;
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
        if ($address === '') return ['success' => false, 'message' => 'Complete street address is required.'];
        if (!self::maxLength($address, 255) || !self::address($address)) return ['success' => false, 'message' => 'Please provide a valid address of 255 characters or fewer without control characters.'];
        if (mb_strlen($purok) > 100 || !self::address($purok)) return ['success' => false, 'message' => 'Please enter a valid purok (up to 100 characters).'];
        if (!in_array($tenant, ['0', '1', '2'], true)) return ['success' => false, 'message' => 'Please select a valid residency status.'];

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
