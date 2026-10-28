<?php

require_once __DIR__ . '/../models/Arbitration.php';
require_once __DIR__ . '/../models/CaseStage.php';
require_once __DIR__ . '/../services/AuditService.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

class ArbitrationController
{
    private $arbitration;
    private $audit;

    public function __construct()
    {
        $this->arbitration = new Arbitration();
        $this->audit = new AuditService();
    }

    public function create($data)
    {
        $caseId = filter_var($data['case_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if (!$caseId) {
            return ['success' => false, 'message' => 'A valid case ID is required.'];
        }

        $awardDate = trim((string) ($data['award_date'] ?? ''));
        if ($awardDate === '') {
            return ['success' => false, 'message' => 'Arbitration award date is required.'];
        }

        $awardDetails = trim((string) ($data['award_details'] ?? $data['award_terms'] ?? ''));
        if ($awardDetails === '') {
            return ['success' => false, 'message' => 'Specific terms and findings of the arbitration award are required.'];
        }

        // Retrieve existing agreement_date from arbitration_records or fallback to input/today
        $existing = $this->arbitration->getByCase($caseId);
        $agreementDate = !empty($existing['agreement_date'])
            ? $existing['agreement_date']
            : (!empty($data['agreement_date']) ? trim((string) $data['agreement_date']) : date('Y-m-d'));

        // Statutory validation under RA 7160 Sec. 413:
        // 1. Cannot be issued earlier than Day 6 (5-day backing out/repudiation window expires)
        // 2. Must be rendered within 15 calendar days from signing date of KP Form 14
        $earliestAllowed = date('Y-m-d', strtotime($agreementDate . ' +6 days'));
        $latestAllowed = date('Y-m-d', strtotime($agreementDate . ' +15 days'));

        if ($awardDate < $earliestAllowed) {
            $formattedEarliest = date('F j, Y', strtotime($earliestAllowed));
            $formattedAgreement = date('F j, Y', strtotime($agreementDate));
            return [
                'success' => false,
                'message' => "Pursuant to RA 7160 Sec. 413, the Arbitration Award (KP Form 15) cannot be rendered earlier than the 6th day after signing the agreement to arbitrate (Day 0: {$formattedAgreement}). The 5-day statutory repudiation window must expire first. Earliest permitted date is {$formattedEarliest}."
            ];
        }

        if ($awardDate > $latestAllowed) {
            $formattedLatest = date('F j, Y', strtotime($latestAllowed));
            $formattedAgreement = date('F j, Y', strtotime($agreementDate));
            return [
                'success' => false,
                'message' => "Pursuant to RA 7160 Sec. 413, the Arbitration Award (KP Form 15) must be rendered within fifteen (15) calendar days from the date the arbitration agreement was signed (Day 0: {$formattedAgreement}). Latest permitted date is {$formattedLatest}."
            ];
        }

        $saveData = [
            'case_id' => $caseId,
            'agreement_date' => $agreementDate,
            'award_date' => $awardDate,
            'award_details' => $awardDetails
        ];

        $result = $this->arbitration->create($saveData);
        if ($result) {
            $userId = (int) ($_SESSION['user_id'] ?? 1);
            $this->audit->log(
                $userId,
                'Rendered Arbitration Award (KP Form 15)',
                'Arbitration',
                $caseId
            );

            // Record stage outcome as Arbitration Award
            try {
                $stageModel = new CaseStage();
                $stageModel->recordOutcome($caseId, 'Arbitration', 'Arbitration Award', $awardDetails, $userId);
            } catch (Throwable $e) {
                error_log('Error updating stage outcome on arbitration award: ' . $e->getMessage());
            }

            return [
                'success' => true,
                'message' => 'Arbitration award successfully rendered and recorded.',
                'case_id' => $caseId,
                'award_date' => $awardDate,
                'agreement_date' => $agreementDate
            ];
        }

        return ['success' => false, 'message' => 'Unable to save arbitration award.'];
    }

    public function getByCase($caseId)
    {
        return $this->arbitration->getByCase($caseId);
    }
}
