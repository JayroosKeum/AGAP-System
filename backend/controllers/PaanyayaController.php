<?php

declare(strict_types=1);

require_once __DIR__ . '/BaseController.php';
require_once __DIR__ . '/../models/PaanyayaModel.php';

class PaanyayaController extends BaseController
{
    private PaanyayaModel $model;

    public function __construct(?PaanyayaModel $model = null)
    {
        $this->model = $model ?? new PaanyayaModel();
    }

    /**
     * Get Paanyaya workspace and preview details.
     */
    public function show(int $complaintId): array
    {
        if ($complaintId <= 0) {
            return ['success' => false, 'message' => 'Invalid complaint ID.'];
        }

        $data = $this->model->getPaanyayaData($complaintId);
        if (!$data) {
            return ['success' => false, 'message' => 'Complaint record not found.'];
        }

        return ['success' => true, 'data' => $data];
    }

    /**
     * Save streamlined Paanyaya fields.
     * Inputs restricted strictly to:
     * - complainant_name
     * - respondent_name
     * - tanggapan (Barangay Tumana)
     * - petsa
     * - buwan
     * - taon
     * - araw
     * - oras
     * - salaysay
     * - has_e_signature
     */
    public function save(array $input, int $userId): array
    {
        $complaintId = filter_var($input['complaint_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$complaintId) {
            return ['success' => false, 'message' => 'Kailangan ang wastong Complaint ID.'];
        }

        // Validate past date / time guardrail
        $dateStr = trim((string)($input['invitation_date'] ?? ''));
        if (empty($dateStr) && !empty($input['petsa']) && !empty($input['buwan']) && !empty($input['taon'])) {
            $month = $this->model->parseFilipinoMonthToNumber((string)$input['buwan']);
            $dateStr = sprintf('%04d-%02d-%02d', (int)$input['taon'], $month, (int)$input['petsa']);
        }

        $timeStr = trim((string)($input['invitation_time'] ?? ''));
        if (empty($timeStr) && !empty($input['oras'])) {
            $timeStr = date('H:i:s', strtotime((string)$input['oras']));
        }

        if (!empty($dateStr)) {
            $todayStr = date('Y-m-d');
            if ($dateStr < $todayStr) {
                return [
                    'success' => false,
                    'message' => 'Bawal magtakda ng nakaraang petsa. Pakiusap pumili ng petsa simula ngayong araw pataas.'
                ];
            }
            if ($dateStr === $todayStr && !empty($timeStr)) {
                $currentTime = date('H:i:s');
                if ($timeStr < $currentTime) {
                    return [
                        'success' => false,
                        'message' => 'Bawal magtakda ng nakaraang oras para sa araw na ito.'
                    ];
                }
            }
        }

        $streamlinedData = [
            'stage_type' => in_array($input['stage_type'] ?? '', ['Paanyaya 1', 'Paanyaya 2'], true) ? $input['stage_type'] : 'Paanyaya 1',
            'complainant_name' => trim((string)($input['complainant_name'] ?? '')),
            'respondent_name' => trim((string)($input['respondent_name'] ?? '')),
            'tanggapan' => trim((string)($input['tanggapan'] ?? 'Barangay Tumana')),
            'invitation_date' => $dateStr,
            'invitation_time' => $timeStr,
            'petsa' => $input['petsa'] ?? null,
            'buwan' => $input['buwan'] ?? null,
            'taon' => $input['taon'] ?? null,
            'araw' => $input['araw'] ?? null,
            'oras' => $input['oras'] ?? null,
            'salaysay' => trim((string)($input['salaysay'] ?? '')),
            'has_e_signature' => !empty($input['has_e_signature'])
        ];

        try {
            $res = $this->model->savePaanyaya($complaintId, $streamlinedData, $userId);
            return array_merge(['success' => true], $res);
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }

    /**
     * Handle the attendance & progression engine.
     */
    public function progress(array $input, int $userId): array
    {
        $complaintId = filter_var($input['complaint_id'] ?? 0, FILTER_VALIDATE_INT);
        if (!$complaintId) {
            return ['success' => false, 'message' => 'Kailangan ang wastong Complaint ID.'];
        }

        try {
            $res = $this->model->recordAttendanceAndProgression($complaintId, $input, $userId);
            return $res;
        } catch (Throwable $e) {
            return ['success' => false, 'message' => $e->getMessage()];
        }
    }
}
