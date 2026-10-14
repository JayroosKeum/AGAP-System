<?php
declare(strict_types=1);
function validateProfileInput(array $input): array
{
    $data=[]; $errors=[];
    $textFields=['first_name','middle_name','last_name','contact_no','email','address_line','purok_sitio','barangay','city_municipality','province','occupation','notes'];
    foreach($textFields as $field){$value=trim((string)($input[$field]??''));$data[$field]=$value===''?null:$value;}
    $data['suffix']=null;
    foreach(['first_name','middle_name','last_name','contact_no','email','address_line','purok_sitio','barangay','city_municipality','province'] as $field){if($data[$field]===null)$errors[$field]='This field is required.';}
    $data['birth_date']=trim((string)($input['birth_date']??''))?:null;
    if($data['birth_date']===null){$errors['birth_date']='This field is required.';}else{$date=DateTime::createFromFormat('Y-m-d',$data['birth_date']);if(!$date||$date->format('Y-m-d')!==$data['birth_date']||$date>new DateTime('today'))$errors['birth_date']='Enter a valid birth date.';}
    $sets=['sex'=>['Male','Female','Other'],'civil_status'=>['Single','Married','Widowed','Separated'],'residency_status'=>['Permanent Resident','Temporary Resident','Tenant'],'voter_status'=>['Registered','Not Registered','Not Specified']];
    foreach($sets as $field=>$allowed){$value=trim((string)($input[$field]??''));if($value===''||!in_array($value,$allowed,true))$errors[$field]=$value===''?'This field is required.':'Invalid value.';$data[$field]=$value;}
    if($data['email']!==null&&!filter_var($data['email'],FILTER_VALIDATE_EMAIL))$errors['email']='Enter a valid email address.';
    if($data['contact_no']!==null&&!preg_match('/^[0-9+() -]{7,20}$/',$data['contact_no']))$errors['contact_no']='Enter a valid contact number.';
    $data['latitude']=filter_var($input['latitude']??null,FILTER_VALIDATE_FLOAT,FILTER_NULL_ON_FAILURE);
    $data['longitude']=filter_var($input['longitude']??null,FILTER_VALIDATE_FLOAT,FILTER_NULL_ON_FAILURE);
    if($data['latitude']===null||$data['longitude']===null){$errors['address_line']='Select the location on the map or update the address to position the pin.';}
    return [$data,$errors];
}
