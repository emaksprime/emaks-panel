<?php

namespace Tests\Feature\Settings;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
use Tests\TestCase;

class SettingsLocalizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_layout_uses_turkish_labels(): void
    {
        $layout = file_get_contents(resource_path('js/layouts/settings/layout.tsx')) ?: '';

        $this->assertStringContainsString('Ayarlar', $layout);
        $this->assertStringContainsString('Profil ve hesap ayarlarınızı yönetin', $layout);
        $this->assertStringContainsString('Profil', $layout);
        $this->assertStringContainsString('Güvenlik', $layout);
        $this->assertStringContainsString('Görünüm', $layout);
        $this->assertStringContainsString('aria-label="Ayarlar"', $layout);
        $this->assertStringNotContainsString('Manage your profile and account settings', $layout);
    }

    public function test_security_page_shows_turkish_password_guidance(): void
    {
        $security = file_get_contents(resource_path('js/pages/settings/security.tsx')) ?: '';
        $requirements = file_get_contents(resource_path('js/components/password-requirements.tsx')) ?: '';

        $this->assertStringContainsString('Şifreyi Güncelle', $security);
        $this->assertStringContainsString('Mevcut şifre', $security);
        $this->assertStringContainsString('Yeni şifre', $security);
        $this->assertStringContainsString('Yeni şifre tekrar', $security);
        $this->assertStringContainsString('Şifreyi kaydet', $security);
        $this->assertStringContainsString('İki aşamalı doğrulama', $security);
        $this->assertStringContainsString('<PasswordRequirements', $security);

        $this->assertStringContainsString('Şifre kuralları', $requirements);
        $this->assertStringContainsString('En az 8 karakter', $requirements);
        $this->assertStringContainsString('En az 1 büyük harf', $requirements);
        $this->assertStringContainsString('En az 1 küçük harf', $requirements);
        $this->assertStringContainsString('En az 1 rakam', $requirements);
        $this->assertStringContainsString('En az 1 sembol', $requirements);
        $this->assertStringContainsString('Sistem ayrıca sızdırılmış', $requirements);
        $this->assertStringContainsString('çok yaygın şifreleri kontrol', $requirements);

        $this->assertStringNotContainsString('Update password', $security);
        $this->assertStringNotContainsString('Current password', $security);
        $this->assertStringNotContainsString('New password', $security);
        $this->assertStringNotContainsString('Confirm password', $security);
        $this->assertStringNotContainsString('Save password', $security);
        $this->assertStringNotContainsString('Two-factor authentication', $security);
    }

    public function test_reset_password_page_uses_turkish_copy_and_password_guidance(): void
    {
        $reset = file_get_contents(resource_path('js/pages/auth/reset-password.tsx')) ?: '';

        $this->assertStringContainsString('Şifre Yenile', $reset);
        $this->assertStringContainsString('Yeni şifrenizi belirleyin', $reset);
        $this->assertStringContainsString('E-posta', $reset);
        $this->assertStringContainsString('Yeni şifre', $reset);
        $this->assertStringContainsString('Yeni şifre tekrar', $reset);
        $this->assertStringContainsString('Şifreyi yenile', $reset);
        $this->assertStringContainsString('<PasswordRequirements', $reset);
        $this->assertStringNotContainsString('Reset password', $reset);
        $this->assertStringNotContainsString('Please enter your new password below', $reset);
        $this->assertStringNotContainsString('Confirm password', $reset);
    }

    public function test_profile_and_appearance_pages_use_turkish_copy(): void
    {
        $profile = file_get_contents(resource_path('js/pages/settings/profile.tsx')) ?: '';
        $appearance = file_get_contents(resource_path('js/pages/settings/appearance.tsx')) ?: '';
        $appearanceTabs = file_get_contents(resource_path('js/components/appearance-tabs.tsx')) ?: '';

        $this->assertStringContainsString('Profil Ayarları', $profile);
        $this->assertStringContainsString('Profil Bilgileri', $profile);
        $this->assertStringContainsString('Panel kimlik bilgilerinizi güncelleyin', $profile);
        $this->assertStringContainsString('Ad Soyad', $profile);
        $this->assertStringContainsString('Kullanıcı adı', $profile);
        $this->assertStringContainsString('Kaydet', $profile);
        $this->assertStringNotContainsString('Profile settings', $profile);
        $this->assertStringNotContainsString('Full name', $profile);
        $this->assertStringNotContainsString('Username', $profile);

        $this->assertStringContainsString('Görünüm Ayarları', $appearance);
        $this->assertStringContainsString('Panel görünüm tercihlerinizi güncelleyin', $appearance);
        $this->assertStringContainsString('Açık', $appearanceTabs);
        $this->assertStringContainsString('Koyu', $appearanceTabs);
        $this->assertStringContainsString('Sistem', $appearanceTabs);
        $this->assertStringNotContainsString('Appearance settings', $appearance);
    }

    public function test_password_validation_messages_are_turkish(): void
    {
        $minValidator = Validator::make(
            ['password' => '1234567'],
            ['password' => ['required', Password::min(8)]],
        );

        $this->assertTrue($minValidator->fails());
        $this->assertSame(
            'Şifre en az 8 karakter olmalıdır.',
            $minValidator->errors()->first('password'),
        );

        $mixedCaseValidator = Validator::make(
            ['password' => 'abcdefgh'],
            ['password' => ['required', Password::min(8)->mixedCase()]],
        );

        $this->assertTrue($mixedCaseValidator->fails());
        $this->assertSame(
            'Şifre en az bir büyük harf ve bir küçük harf içermelidir.',
            $mixedCaseValidator->errors()->first('password'),
        );
    }
}
