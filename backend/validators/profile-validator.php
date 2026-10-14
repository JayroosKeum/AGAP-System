<?php
declare(strict_types=1);

function validateProfileInput(array $input): array
{
    $allowed = [
        'sex'=>['Male','Female','Not Specified'],
        'civil_status'=>['Single','Married','Widowed','Separated','Not Specified'],
        'residency_status'=>['Permanent Resident','Tenant','Non-Resident'],
        'voter_status'=>['Registered','Not Registered','Not Specified'],
    ];
    $data=[]; $errors=[];
    $data['latitude']=filter_var($input['latitude'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    $data['longitude']=filter_var($input['longitude'] ?? null, FILTER_VALIDATE_FLOAT, FILTER_NULL_ON_FAILURE);
    if ($data['latitude'] !== null && ($data['latitude'] < -90 || $data['latitude'] > 90)) $errors['latitude']='Invalid latitude.';
    if ($data['longitude'] !== null && ($data['longitude'] < -180 || $data['longitude'] > 180)) $errors['longitude']='Invalid longitude.';
    foreach (['first_name','middle_name','last_name','suffix','contact_no','email','address_line','purok_sitio','barangay','city_municipality','province','occupation','notes'] as $field) {
        $value=trim((string)($input[$field] ?? ''));
        $data[$field]=$value === '' ? null : $value;
    }
    foreach (['first_name','last_name','address_line','barangay','city_municipality','province'] as $field) if ($data[$field] === null) $errors[$field]='Required.';
    $data['birth_date']=trim((string)($input['birth_date'] ?? '')) ?: null;
    if ($data['birth_date'] !== null) {
        $date=DateTime::createFromFormat('Y-m-d',$data['birth_date']);
        if (!$date || $date->format('Y-m-d') !== $data['birth_date'] || $date > new DateTime('today')) $errors['birth_date']='Enter a valid past or current date.';
    }
    foreach ($allowed as $field=>$choices) {
        $value=(string)($input[$field] ?? end($choices));
        if (!in_array($value,$choices,true)) $errors[$field]='Invalid value.';
        $data[$field]=$value;
    }
    if ($data['email'] !== null && !filter_var($data['email'],FILTER_VALIDATE_EMAIL)) $errors['email']='Invalid email address.';
    if ($data['contact_no'] !== null && !preg_match('/^[0-9+() -]{7,20}$/',$data['contact_no'])) $errors['contact_no']='Invalid contact number.';
    foreach (['first_name','middle_name','last_name','barangay','city_municipality','province'] as $field) if ($data[$field] !== null && mb_strlen($data[$field]) > 100) $errors[$field]='Maximum 100 characters.';
    return [$data,$errors];
}
