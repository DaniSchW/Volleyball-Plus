<?php

// German messages for the validation rules in use; anything missing falls
// back to Laravel's English texts.
return [
    'between' => [
        'numeric' => ':attribute muss zwischen :min und :max liegen.',
        'string' => ':attribute muss zwischen :min und :max Zeichen lang sein.',
    ],
    'email' => ':attribute muss eine gültige E-Mail-Adresse sein.',
    'in' => 'Der gewählte Wert für :attribute ist ungültig.',
    'integer' => ':attribute muss eine ganze Zahl sein.',
    'max' => [
        'numeric' => ':attribute darf höchstens :max sein.',
        'string' => ':attribute darf höchstens :max Zeichen lang sein.',
    ],
    'min' => [
        'numeric' => ':attribute muss mindestens :min sein.',
        'string' => ':attribute muss mindestens :min Zeichen lang sein.',
    ],
    'regex' => ':attribute hat ein ungültiges Format.',
    'required' => ':attribute muss ausgefüllt werden.',
    'required_if' => ':attribute muss ausgefüllt werden, wenn :other „:value“ ist.',
    'string' => ':attribute muss ein Text sein.',
    'url' => ':attribute muss eine gültige Adresse sein (beginnend mit https://).',
];
