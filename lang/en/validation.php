<?php

return [
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
        'current_password' => 'mevcut şifre',
        'password' => 'şifre',
        'password_confirmation' => 'şifre tekrarı',
    ],
];
