<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../services/PangkatDutyGroupService.php';

try {
    $service = new PangkatDutyGroupService();
    $list = $service->listGroups(false);
    echo "PangkatDutyGroupService initialized successfully! Existing groups: " . count($list) . "\n";

    $db = (new Database())->connect();
    $stmt = $db->query("
        SELECT u.user_id, u.first_name, u.last_name, r.role_name 
        FROM users u 
        INNER JOIN roles r ON r.role_id = u.role_id 
        WHERE u.status = 'Active' AND r.role_name = 'Lupon Member'
        LIMIT 5
    ");
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Active Lupon Members available: " . count($members) . "\n";
    foreach ($members as $m) {
        echo " - #{$m['user_id']}: {$m['first_name']} {$m['last_name']}\n";
    }

    if (count($members) >= 3) {
        $testGroup = [
            'group_name' => 'Wednesday Conciliation Panel A',
            'duty_day' => 'Wednesday',
            'start_time' => '08:00',
            'end_time' => '17:00',
            'station' => 'Lupon Court Room',
            'notes' => 'Primary Wednesday conciliation panel for civil and boundary disputes.',
            'is_active' => 1,
            'chairman_id' => $members[0]['user_id'],
            'secretary_id' => $members[1]['user_id'],
            'member_id' => $members[2]['user_id']
        ];
        $saveRes = $service->saveGroup($testGroup, 1);
        echo "Save Group Result: " . json_encode($saveRes) . "\n";

        $groupsNow = $service->listGroups(true);
        echo "Active complete groups count: " . count($groupsNow) . "\n";

        if (!empty($saveRes['duty_group_id'])) {
            $resolve = $service->resolvePanel($saveRes['duty_group_id']);
            echo "Resolve Panel Check: " . ($resolve['success'] ? 'SUCCESS' : 'FAILED: ' . ($resolve['message'] ?? '')) . "\n";
            echo " - Seated Chair: #{$resolve['chairman_id']}, Sec: #{$resolve['secretary_id']}, Member: #{$resolve['member_id']}\n";
        }
    }
} catch (Throwable $e) {
    echo "EXCEPTION: " . $e->getMessage() . "\n" . $e->getTraceAsString() . "\n";
}
