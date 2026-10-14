<?php
declare(strict_types=1);

final class Profile
{
    public function __construct(private PDO $pdo) {}

    public function paginate(string $search, string $status, int $page, int $perPage): array
    {
        $where = ['1=1'];
        $params = [];
        if ($search !== '') {
            $where[] = "(profile_no LIKE :search OR first_name LIKE :search OR middle_name LIKE :search OR last_name LIKE :search OR contact_no LIKE :search OR address_line LIKE :search)";
            $params['search'] = '%' . $search . '%';
        }
        if (in_array($status, ['Active', 'Archived'], true)) {
            $where[] = 'status = :status';
            $params['status'] = $status;
        }
        $clause = implode(' AND ', $where);
        $count = $this->pdo->prepare("SELECT COUNT(*) FROM profiles WHERE {$clause}");
        $count->execute($params);
        $total = (int) $count->fetchColumn();
        $offset = ($page - 1) * $perPage;
        $sql = "SELECT * FROM profiles WHERE {$clause} ORDER BY last_name, first_name, profile_id DESC LIMIT :limit OFFSET :offset";
        $stmt = $this->pdo->prepare($sql);
        foreach ($params as $key => $value) $stmt->bindValue(':' . $key, $value);
        $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        return ['rows' => $stmt->fetchAll(), 'total' => $total];
    }

    public function find(int $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM profiles WHERE profile_id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    public function findDuplicate(array $data, ?int $exceptId = null): ?array
    {
        $sql = "SELECT profile_id, profile_no, first_name, middle_name, last_name, birth_date FROM profiles
                WHERE first_name = :first_name AND last_name = :last_name
                  AND COALESCE(middle_name, '') = COALESCE(:middle_name, '')
                  AND ((birth_date IS NULL AND :birth_date_null IS NULL) OR birth_date = :birth_date_value)";
        $params = ['first_name'=>$data['first_name'],'middle_name'=>$data['middle_name'],'last_name'=>$data['last_name'],'birth_date_null'=>$data['birth_date'],'birth_date_value'=>$data['birth_date']];
        if ($exceptId !== null) { $sql .= ' AND profile_id <> :except_id'; $params['except_id']=$exceptId; }
        $sql .= ' LIMIT 1';
        $stmt=$this->pdo->prepare($sql); $stmt->execute($params);
        return $stmt->fetch() ?: null;
    }

    public function insert(array $data, int $actorId): int
    {
        $sql = 'INSERT INTO profiles (profile_no, first_name, middle_name, last_name, suffix, birth_date, sex, civil_status, contact_no, email, address_line, purok_sitio, barangay, city_municipality, province, residency_status, latitude, longitude, voter_status, occupation, notes, status, created_by, updated_by)
                VALUES (:profile_no, :first_name, :middle_name, :last_name, :suffix, :birth_date, :sex, :civil_status, :contact_no, :email, :address_line, :purok_sitio, :barangay, :city_municipality, :province, :residency_status, :latitude, :longitude, :voter_status, :occupation, :notes, \'Active\', :created_by, :updated_by)';
        $stmt=$this->pdo->prepare($sql);
        $stmt->execute($data + ['created_by'=>$actorId,'updated_by'=>$actorId]);
        return (int)$this->pdo->lastInsertId();
    }

    public function update(int $id, array $data, int $actorId): void
    {
        $sets=[]; foreach(array_keys($data) as $field) $sets[]="{$field} = :{$field}";
        $stmt=$this->pdo->prepare('UPDATE profiles SET '.implode(', ',$sets).', updated_by = :updated_by WHERE profile_id = :profile_id');
        $stmt->execute($data + ['updated_by'=>$actorId,'profile_id'=>$id]);
    }

    public function setStatus(int $id, string $status, int $actorId): void
    {
        $stmt=$this->pdo->prepare('UPDATE profiles SET status = :status, updated_by = :actor WHERE profile_id = :id');
        $stmt->execute(['status'=>$status,'actor'=>$actorId,'id'=>$id]);
    }

    public function history(int $id): array
    {
        $stmt=$this->pdo->prepare("SELECT ph.*, CONCAT(u.first_name, ' ', u.last_name) actor_name FROM profile_history ph INNER JOIN users u ON u.user_id=ph.acted_by WHERE ph.profile_id=:id ORDER BY ph.acted_at DESC, ph.profile_history_id DESC");
        $stmt->execute(['id'=>$id]); return $stmt->fetchAll();
    }

    public function metrics(): array
    {
        $sql = "SELECT COUNT(*) total_profiles, SUM(status='Active' AND residency_status='Permanent Resident') permanent_residents, SUM(status='Active' AND residency_status='Tenant') tenants, COUNT(DISTINCT CASE WHEN status='Active' THEN NULLIF(purok_sitio,'') END) active_puroks FROM profiles";
        return $this->pdo->query($sql)->fetch() ?: [];
    }

    public function puroks(): array
    {
        return $this->pdo->query("SELECT DISTINCT purok_sitio FROM profiles WHERE purok_sitio IS NOT NULL AND purok_sitio <> '' ORDER BY purok_sitio")->fetchAll(PDO::FETCH_COLUMN);
    }

    public function filtered(string $search, string $status, string $residency, string $purok, string $sort): array
    {
        $where=['1=1']; $params=[];
        if($search!==''){ $where[]="(profile_no LIKE :q OR CONCAT_WS(' ',first_name,middle_name,last_name,suffix) LIKE :q OR address_line LIKE :q OR contact_no LIKE :q OR email LIKE :q)"; $params['q']='%'.$search.'%'; }
        if(in_array($status,['Active','Archived'],true)){ $where[]='status=:status'; $params['status']=$status; }
        if(in_array($residency,['Permanent Resident','Tenant','Non-Resident'],true)){ $where[]='residency_status=:residency'; $params['residency']=$residency; }
        if($purok!==''){ $where[]='purok_sitio=:purok'; $params['purok']=$purok; }
        $orders=['newest'=>'created_at DESC','oldest'=>'created_at ASC','name_asc'=>'last_name ASC, first_name ASC','name_desc'=>'last_name DESC, first_name DESC','purok'=>'purok_sitio ASC, last_name ASC'];
        $stmt=$this->pdo->prepare('SELECT * FROM profiles WHERE '.implode(' AND ',$where).' ORDER BY '.($orders[$sort]??$orders['newest']));
        $stmt->execute($params); return $stmt->fetchAll();
    }

    public function addHistory(int $id, string $action, ?array $old, ?array $new, int $actorId): void
    {
        $stmt=$this->pdo->prepare('INSERT INTO profile_history (profile_id, action, old_values, new_values, acted_by) VALUES (:id,:action,:old_values,:new_values,:actor)');
        $stmt->execute(['id'=>$id,'action'=>$action,'old_values'=>$old ? json_encode($old, JSON_UNESCAPED_UNICODE) : null,'new_values'=>$new ? json_encode($new, JSON_UNESCAPED_UNICODE) : null,'actor'=>$actorId]);
    }
}
