<?php

return [
    'file' => ':attribute bir dosya olmalıdır.',

    'max' => [
        'array' => ':attribute en fazla :max öğe içermelidir.',
        'file' => ':attribute en fazla :max kilobayt olmalıdır.',
        'numeric' => ':attribute en fazla :max olmalıdır.',
        'string' => ':attribute en fazla :max karakter olmalıdır.',
    ],

    'min' => [
        'array' => ':attribute en az :min öğe içermelidir.',
        'file' => ':attribute en az :min kilobayt olmalıdır.',
        'numeric' => ':attribute en az :min olmalıdır.',
        'string' => ':attribute en az :min karakter olmalıdır.',
    ],

    'mimes' => ':attribute şu dosya türlerinden biri olmalıdır: :values.',

    'password' => [
        'letters' => 'Şifre en az bir harf içermelidir.',
        'mixed' => 'Şifre en az bir büyük harf ve bir küçük harf içermelidir.',
        'numbers' => 'Şifre en az bir rakam içermelidir.',
        'symbols' => 'Şifre en az bir sembol içermelidir.',
        'uncompromised' => 'Bu şifre daha önce sızdırılmış olabilir. Lütfen farklı bir şifre seçin.',
    ],

    'custom' => [
        'password' => [
            'confirmed' => 'Şifre tekrarı eşleşmiyor.',
            'min' => 'Şifre en az :min karakter olmalıdır.',
            'required' => 'Şifre alanı zorunludur.',
        ],
        'current_password' => [
            'current_password' => 'Mevcut şifre doğru değil.',
            'required' => 'Mevcut şifre alanı zorunludur.',
        ],
    ],

    'attributes' => [
        'after_photo' => 'Sonrası fotoğrafı',
        'before_photo' => 'Öncesi fotoğrafı',
        'current_password' => 'mevcut şifre',
        'password' => 'şifre',
        'password_confirmation' => 'şifre tekrarı',
        'warranty_document_photo' => 'Garanti belgesi fotoğrafı',
    ],
];
