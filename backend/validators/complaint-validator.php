<?php
declare(strict_types=1);

function validateComplaintInput(array $input): array
{
    $data=[]; $errors=[];
    $requiredText=['complainant_name','complainant_contact','complainant_address','complainant_purok','respondent_name','respondent_contact','respondent_address','respondent_purok','complaint_type','narrative','relief_sought','incident_purok','incident_location'];
    foreach($requiredText as $field){$value=trim((string)($input[$field]??''));$data[$field]=$value;if($value==='')$errors[$field]='This field is required.';}
    foreach(['complainant_profile_id','respondent_profile_id'] as $field){$value=filter_var($input[$field]??null,FILTER_VALIDATE_INT);$data[$field]=$value?:null;}
    $types=['Neighborhood Dispute','Financial & Debt Issues','Slander & Defamation','Physical Altercation','Noise Disturbance','Others'];
    if($data['complaint_type']!==''&&!in_array($data['complaint_type'],$types,true))$errors['complaint_type']='Invalid complaint type.';
    $reliefs=['Conciliation Hearing','Warning','Financial Settlement','Written Agreement','Other Resolution'];
    if($data['relief_sought']!==''&&!in_array($data['relief_sought'],$reliefs,true))$errors['relief_sought']='Invalid desired resolution.';
    $rawDate=trim((string)($input['incident_at']??''));$data['incident_at']=$rawDate;
    if($rawDate===''){$errors['incident_at']='This field is required.';}else{$date=DateTime::createFromFormat('Y-m-d\\TH:i',$rawDate);if(!$date||$date->format('Y-m-d\\TH:i')!==$rawDate||$date>new DateTime())$errors['incident_at']='Enter a valid incident date and time that is not in the future.';else$data['incident_at']=$date->format('Y-m-d H:i:s');}
    $data['incident_latitude']=filter_var($input['incident_latitude']??null,FILTER_VALIDATE_FLOAT,FILTER_NULL_ON_FAILURE);
    $data['incident_longitude']=filter_var($input['incident_longitude']??null,FILTER_VALIDATE_FLOAT,FILTER_NULL_ON_FAILURE);
    if($data['incident_latitude']===null||$data['incident_longitude']===null)$errors['incident_location']='Pin the incident location on the map.';
    foreach(['complainant_contact','respondent_contact'] as $field)if($data[$field]!==''&&!preg_match('/^[0-9+() -]{7,30}$/',$data[$field]))$errors[$field]='Enter a valid contact number.';
    return [$data,$errors];
}
