<?php
/**
 * Validation rules for recording a summon delivery attempt.
 * Place in: backend/services/SummonDeliveryValidator.php
 */

declare(strict_types=1);

require_once __DIR__ . '/SummonDeliveryStatus.php';

final class SummonDeliveryValidator
{
    public function validate(array $data): array
    {
        $errors = [];
        $status = trim((string) ($data['delivery_status'] ?? ''));
        $attemptedAt = trim((string) ($data['attempted_at'] ?? ''));
        $address = trim((string) ($data['service_address'] ?? ''));
        $remarks = trim((string) ($data['remarks'] ?? ''));
        $recipientName = trim((string) ($data['recipient_name'] ?? ''));
        $serviceMethod = trim((string) ($data['service_method'] ?? ''));

        if (!SummonDeliveryStatus::isValid($status)) {
            $errors['delivery_status'] = 'Select a valid delivery status.';
        }
        if ($attemptedAt === '' || strtotime($attemptedAt) === false) {
            $errors['attempted_at'] = 'A valid service-attempt date and time is required.';
        }
        if ($address === '') {
            $errors['service_address'] = 'The address used for the service attempt is required.';
        }
        if ($serviceMethod === '') {
            $errors['service_method'] = 'The service method is required.';
        }
        if (SummonDeliveryStatus::isSuccessfulService($status) && $recipientName === '') {
            $errors['recipient_name'] = 'The recipient name is required for a successful service record.';
        }
        if (in_array($status, [
            SummonDeliveryStatus::REFUSED,
            SummonDeliveryStatus::UNSERVED,
            SummonDeliveryStatus::UNLOCATABLE,
            SummonDeliveryStatus::WRONG_ADDRESS,
        ], true) && $remarks === '') {
            $errors['remarks'] = 'Detailed remarks are required for an unsuccessful or disputed service attempt.';
        }

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'normalized' => [
                'delivery_status' => $status,
                'attempted_at' => $attemptedAt,
                'service_address' => $address,
                'remarks' => $remarks,
                'recipient_name' => $recipientName,
                'service_method' => $serviceMethod,
            ],
        ];
    }
}
