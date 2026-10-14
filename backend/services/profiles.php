<?php
declare(strict_types=1);
require_once __DIR__ . '/../models/Profile.php';
require_once __DIR__ . '/../validators/profile-validator.php';

final class ProfileService
{
    private Profile $profiles;
    public function __construct(private PDO $pdo) { $this->profiles=new Profile($pdo); }
    public function repository(): Profile { return $this->profiles; }

    public function save(array $input, int $actorId, ?int $profileId=null): array
    {
        [$data,$errors]=validateProfileInput($input);
        if ($errors) return ['ok'=>false,'errors'=>$errors,'data'=>$data];
        $duplicate=$this->profiles->findDuplicate($data,$profileId);
        if ($duplicate) return ['ok'=>false,'errors'=>['duplicate'=>'Possible duplicate: '.$duplicate['profile_no'].'.'],'data'=>$data];
        $this->pdo->beginTransaction();
        try {
            if ($profileId === null) {
                $data['profile_no']='PENDING';
                $id=$this->profiles->insert($data,$actorId);
                $profileNo='PRF-'.date('Y').'-'.str_pad((string)$id,6,'0',STR_PAD_LEFT);
                $this->profiles->update($id,['profile_no'=>$profileNo],$actorId);
                $new=$this->profiles->find($id);
                $this->profiles->addHistory($id,'Created',null,$new,$actorId);
            } else {
                $old=$this->profiles->find($profileId);
                if (!$old) throw new RuntimeException('Profile not found.');
                unset($data['profile_no']);
                $this->profiles->update($profileId,$data,$actorId);
                $id=$profileId; $new=$this->profiles->find($id);
                $this->profiles->addHistory($id,'Updated',$old,$new,$actorId);
            }
            $this->pdo->commit(); return ['ok'=>true,'id'=>$id];
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            return ['ok'=>false,'errors'=>['general'=>$e->getMessage()],'data'=>$data];
        }
    }

    public function changeStatus(int $id, string $status, int $actorId): void
    {
        if (!in_array($status,['Archived','Active'],true)) throw new InvalidArgumentException('Invalid status.');
        $this->pdo->beginTransaction();
        try {
            $old=$this->profiles->find($id); if (!$old) throw new RuntimeException('Profile not found.');
            $this->profiles->setStatus($id,$status,$actorId);
            $new=$this->profiles->find($id);
            $this->profiles->addHistory($id,$status==='Archived'?'Archived':'Reactivated',$old,$new,$actorId);
            $this->pdo->commit();
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
}
